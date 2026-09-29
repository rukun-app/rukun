<?php

namespace Modules\Wifi\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Services\BillingService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;
use Modules\Community\Services\SensitiveIdentifier;
use Modules\Wifi\Models\WifiBill;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiPackage;

class WifiService
{
    public function package(User $actor, string $key, array $data): array
    {
        $type = PaymentType::query()->where('public_id', $data['payment_type_id'])->firstOrFail();
        $area = Area::query()->findOrFail($type->area_id);

        return $this->command($actor, $area, 'package', $key, $data, function () use ($actor, $area, $type, $data): array {
            abort_unless($type->collection_policy === 'must_settle_in_period' && $type->fund_classification === 'pass_through', 422, 'WiFi membutuhkan jenis pembayaran must_settle_in_period dan pass_through.');
            abort_if(Invoice::query()->where('payment_type_id', $type->id)->exists(), 409, 'Gunakan jenis pembayaran khusus WiFi yang belum memiliki invoice.');
            $vendor = Vendor::query()->where('public_id', $data['vendor_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            $package = WifiPackage::query()->create([...$data, 'area_id' => $area->id, 'vendor_id' => $vendor->id, 'payment_type_id' => $type->id]);
            CommunityAudit::record('wifi.package.created', $package, actorId: $actor->id);

            return ['public_id' => $package->public_id];
        });
    }

    public function customer(User $actor, string $key, array $data): array
    {
        $household = Household::query()->where('public_id', $data['household_id'])->firstOrFail();
        $area = Area::query()->findOrFail($household->area_id);

        return $this->command($actor, $area, 'customer', $key, $data, function () use ($actor, $household, $area, $data): array {
            $household = Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();
            abort_unless($household->area_id === $area->id && $household->status === 'active', 409);
            $package = WifiPackage::query()->where('public_id', $data['package_id'])->firstOrFail();
            abort_unless(in_array($package->area_id, [$area->id, $area->parent_id], true), 422);
            $vendor = Vendor::query()->whereKey($package->vendor_id)->lockForUpdate()->firstOrFail();
            abort_unless($vendor->status === 'active', 409);
            // One WiFi subscription per household in each billed month, including a package/vendor switch.
            abort_if(WifiCustomer::query()->where('household_id', $household->id)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', substr($data['starts_on'], 0, 7).'-01'))->exists(), 409, 'Periode langganan WiFi rumah tangga bertumpang tindih.');
            $customer = WifiCustomer::query()->create(['household_id' => $household->id, 'area_id' => $area->id, 'vendor_id' => $package->vendor_id, 'package_id' => $package->id, 'starts_on' => $data['starts_on']]);
            CommunityAudit::record('wifi.customer.created', $customer, actorId: $actor->id);

            return ['public_id' => $customer->public_id];
        });
    }

    public function end(User $actor, string $key, WifiCustomer $customer, array $data): array
    {
        $area = Area::query()->findOrFail($customer->area_id);

        return $this->command($actor, $area, 'customer.end', $key, ['customer_id' => $customer->public_id, ...$data], function () use ($actor, $customer, $data): array {
            $customer->refresh();
            abort_unless(substr($data['ends_on'], 8, 2) === '01' && $data['ends_on'] > $customer->starts_on->toDateString(), 422, 'Pengakhiran berlaku pada hari pertama bulan setelah aktivasi.');
            if ($customer->ends_on) {
                abort_unless($customer->ends_on->toDateString() === $data['ends_on'], 409);

                return ['public_id' => $customer->public_id];
            }
            abort_if(WifiBill::query()->where('customer_id', $customer->id)->where('period', '>=', $data['ends_on'])->exists(), 409, 'Pengakhiran tidak boleh mendahului periode yang sudah ditagihkan.');
            $customer->update(['ends_on' => $data['ends_on']]);
            CommunityAudit::record('wifi.customer.ended', $customer, ['ends_on' => $data['ends_on']], $actor->id);

            return ['public_id' => $customer->public_id];
        });
    }

    public function bill(User $actor, string $key, WifiCustomer $customer, array $data): array
    {
        $area = Area::query()->findOrFail($customer->area_id);

        return $this->command($actor, $area, 'customer.bill', $key, ['customer_id' => $customer->public_id, ...$data], function () use ($actor, $customer, $area, $data): array {
            $customer->refresh();
            $period = $data['period'].'-01';
            $previous = WifiBill::query()->where('customer_id', $customer->id)->where('period', $period)->first();
            if ($previous) {
                return ['public_id' => $previous->public_id, 'invoice_id' => Invoice::query()->findOrFail($previous->invoice_id)->public_id];
            }
            abort_unless($customer->starts_on->format('Y-m') <= $data['period'] && (! $customer->ends_on || $period < $customer->ends_on->toDateString()), 409, 'Langganan tidak aktif pada periode ini.');
            $household = Household::query()->whereKey($customer->household_id)->lockForUpdate()->firstOrFail();
            abort_unless($household->area_id === $area->id && $household->status === 'active', 409, 'Wilayah/status rumah tangga berubah; tutup dan daftarkan langganan yang sesuai.');
            $package = WifiPackage::query()->findOrFail($customer->package_id);
            $vendor = Vendor::query()->whereKey($package->vendor_id)->lockForUpdate()->firstOrFail();
            abort_unless($vendor->status === 'active', 409);
            $type = PaymentType::query()->findOrFail($package->payment_type_id);
            $result = app(BillingService::class)->generate($actor, 'wifi-'.$customer->public_id.'-'.$data['period'], ['area_id' => $area->public_id, 'household_ids' => [$household->public_id], 'payment_type_id' => $type->public_id, 'period' => $data['period'], 'subject' => 'wifi', 'due_date' => $data['period'].'-'.sprintf('%02d', $package->due_day), 'settle_by' => $data['period'].'-'.sprintf('%02d', $package->settle_day), 'state' => 'issued'], managedWifi: true);
            $invoice = Invoice::query()->where('public_id', $result['invoices'][0])->firstOrFail();
            $bill = WifiBill::query()->create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'period' => $period]);
            CommunityAudit::record('wifi.invoice.created', $bill, ['invoice_id' => $invoice->public_id], $actor->id);

            return ['public_id' => $bill->public_id, 'invoice_id' => $invoice->public_id];
        });
    }

    private function command(User $actor, Area $area, string $operation, string $key, array $data, callable $callback): array
    {
        try {
            return DB::connection('rukun')->transaction(function () use ($actor, $area, $operation, $key, $data, $callback): array {
                Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
                DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
                $actor->refresh();
                abort_unless(app(ScopeResolver::class)->allows($actor, 'wifi.manage', $area), 403);
                if ($operation === 'customer.bill') {
                    abort_unless(app(ScopeResolver::class)->allows($actor, 'invoices.manage', $area), 403);
                }
                $identity = ['actor_id' => $actor->id, 'operation' => 'wifi.'.$operation, 'request_key' => $key];
                $fingerprint = SensitiveIdentifier::fingerprint(json_encode($data, JSON_THROW_ON_ERROR));
                $previous = DB::connection('rukun')->table('billing_requests')->where($identity)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, __('api.errors.idempotency_conflict'));

                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                $result = $callback();
                DB::connection('rukun')->table('billing_requests')->insert([...$identity, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['resource' => 'Paket, pelanggan, atau tagihan sudah ada.']);
        }
    }
}
