<?php

namespace Modules\Wifi\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Services\BillingEvents;
use Modules\Community\Models\Area;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;
use Modules\Wifi\Models\GallonBenefit;
use Modules\Wifi\Models\GallonClaim;
use Modules\Wifi\Models\GallonEntry;
use Modules\Wifi\Models\WifiBill;

class GallonService
{
    public function __construct(private SettlementService $settlement, private ScopeResolver $scopes) {}

    public function grant(User $actor, string $key, WifiBill $bill): array
    {
        [$customer, $invoice, $area, $package] = $this->settlement->context($bill);

        return app(WifiTransaction::class)->run($actor, $area, 'benefit.grant', $key, ['bill_id' => $bill->public_id], function () use ($actor, $area): void {
            abort_unless($this->scopes->allows($actor, 'wifi.settle', $area), 403);
        }, function () use ($actor, $bill, $customer, $invoice, $package): array {
            $previous = GallonBenefit::query()->where('bill_id', $bill->id)->first();
            if ($previous) {
                return ['public_id' => $previous->public_id];
            }
            abort_unless($this->settlement->eligible($bill), 409, __('wifi::messages.ineligible'));
            $expiry = $invoice->settle_by->copy()->addDays($package->claim_days);
            abort_if(today()->greaterThan($expiry), 409, __('wifi::messages.expired'));
            $benefit = GallonBenefit::query()->create(['bill_id' => $bill->id, 'quota' => $package->gallon_quota, 'available' => 0, 'expires_on' => $expiry]);
            $this->entry($benefit, null, 'grant', $package->gallon_quota, 0, 0, $actor);
            BillingEvents::emit('wifi.benefit.updated', $benefit, $customer->household_id);

            return ['public_id' => $benefit->public_id];
        });
    }

    public function reserve(User $actor, string $key, GallonBenefit $benefit, array $data): array
    {
        $bill = WifiBill::query()->findOrFail($benefit->bill_id);
        [$customer, , $area] = $this->settlement->context($bill);

        return app(WifiTransaction::class)->run($actor, $area, 'claim.reserve', $key, ['benefit_id' => $benefit->public_id, ...$data], function () use ($actor, $customer): void {
            $this->vendor($actor, $customer->vendor_id);
        }, function () use ($actor, $benefit, $customer, $bill, $data): array {
            $benefit->refresh();
            $this->usable($benefit, $bill);
            $previous = GallonClaim::query()->where('vendor_id', $customer->vendor_id)->where('reference', $data['reference'])->first();
            if ($previous) {
                abort_unless($previous->benefit_id === $benefit->id && $previous->quantity === (int) $data['quantity'], 409);

                return ['public_id' => $previous->public_id];
            }
            abort_unless($data['quantity'] > 0 && $data['quantity'] <= $benefit->available, 409, __('wifi::messages.quota'));
            $claim = GallonClaim::query()->create(['benefit_id' => $benefit->id, 'vendor_id' => $customer->vendor_id, 'reference' => $data['reference'], 'quantity' => $data['quantity'], 'created_by' => $actor->id]);
            $this->entry($benefit, $claim, 'reserve', -$claim->quantity, $claim->quantity, 0, $actor);

            return ['public_id' => $claim->public_id];
        });
    }

    public function claim(User $actor, string $key, GallonClaim $claim, string $action, array $data): array
    {
        $benefit = GallonBenefit::query()->findOrFail($claim->benefit_id);
        $bill = WifiBill::query()->findOrFail($benefit->bill_id);
        [$customer, , $area] = $this->settlement->context($bill);

        return app(WifiTransaction::class)->run($actor, $area, 'claim.'.$action, $key, ['claim_id' => $claim->public_id, ...$data], function () use ($actor, $area, $claim, $customer, $action): void {
            $claim->refresh();
            if ($action === 'confirm') {
                abort_unless(! $actor->must_change_password && in_array($customer->household_id, $this->scopes->memberHouseholdIds($actor), true) && $actor->id !== $claim->created_by, 403);
            } elseif ($action === 'reverse' && $this->scopes->allows($actor, 'wifi.settle', $area)) {
                return;
            } else {
                $this->vendor($actor, $customer->vendor_id);
                abort_if($action === 'reverse' && GallonEntry::query()->where('claim_id', $claim->id)->where('event', 'confirm')->exists(), 403);
            }
        }, function () use ($actor, $benefit, $bill, $claim, $customer, $action, $data): array {
            $benefit->refresh();
            $claim->refresh();
            if ($action === 'reverse') {
                abort_unless(in_array($claim->status, ['reserved', 'delivered', 'confirmed'], true), 409);
                $confirmed = $claim->status === 'confirmed';
                $restore = $benefit->status === 'active' && today()->lessThanOrEqualTo($benefit->expires_on) ? $claim->quantity : 0;
                $this->entry($benefit, $claim, 'reverse', $restore, $confirmed ? 0 : -$claim->quantity, $confirmed ? -$claim->quantity : 0, $actor, $data['reason']);
                $claim->update(['status' => 'reversed']);
            } else {
                $this->usable($benefit, $bill);
                abort_unless($claim->status === ($action === 'deliver' ? 'reserved' : 'delivered'), 409);
                $confirm = $action === 'confirm';
                $this->entry($benefit, $claim, $confirm ? 'confirm' : 'claim', 0, $confirm ? -$claim->quantity : 0, $confirm ? $claim->quantity : 0, $actor);
                $claim->update(['status' => $confirm ? 'confirmed' : 'delivered']);
            }
            BillingEvents::emit('wifi.claim.updated', $claim, $customer->household_id);

            return ['public_id' => $claim->public_id, 'status' => $claim->status];
        });
    }

    public function reverseGrant(User $actor, string $key, GallonBenefit $benefit, array $data): array
    {
        $bill = WifiBill::query()->findOrFail($benefit->bill_id);
        [$customer, , $area] = $this->settlement->context($bill);

        return app(WifiTransaction::class)->run($actor, $area, 'benefit.reverse', $key, ['benefit_id' => $benefit->public_id, ...$data], function () use ($actor, $area): void {
            abort_unless($this->scopes->allows($actor, 'wifi.settle', $area), 403);
        }, function () use ($actor, $benefit, $customer, $data): array {
            $benefit->refresh();
            abort_if($benefit->status === 'reversed' || $benefit->reserved !== 0 || $benefit->confirmed !== 0, 409, __('wifi::messages.reverse_claims'));
            $this->entry($benefit, null, 'reverse', -$benefit->available, 0, 0, $actor, $data['reason']);
            $benefit->update(['status' => 'reversed']);
            BillingEvents::emit('wifi.benefit.updated', $benefit, $customer->household_id);

            return ['public_id' => $benefit->public_id];
        });
    }

    public function expire(GallonBenefit $benefit): void
    {
        $bill = WifiBill::query()->findOrFail($benefit->bill_id);
        [$customer, , $area] = $this->settlement->context($bill);
        DB::connection('rukun')->transaction(function () use ($benefit, $area, $customer): void {
            Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
            $benefit->refresh();
            if ($benefit->status !== 'active' || today()->lessThanOrEqualTo($benefit->expires_on)) {
                return;
            }
            foreach (GallonClaim::query()->where('benefit_id', $benefit->id)->whereIn('status', ['reserved', 'delivered'])->get() as $claim) {
                $this->entry($benefit, $claim, 'expire', 0, -$claim->quantity, 0);
                $claim->update(['status' => 'expired']);
                BillingEvents::emit('wifi.claim.updated', $claim, $customer->household_id);
            }
            $this->entry($benefit, null, 'expire', -$benefit->available, 0, 0);
            $benefit->update(['status' => 'expired']);
            BillingEvents::emit('wifi.benefit.updated', $benefit, $customer->household_id);
        }, 3);
    }

    private function usable(GallonBenefit $benefit, WifiBill $bill): void
    {
        abort_unless($benefit->status === 'active' && today()->lessThanOrEqualTo($benefit->expires_on) && $this->settlement->eligible($bill), 409, __('wifi::messages.unavailable'));
    }

    private function vendor(User $actor, int $vendorId): void
    {
        $vendor = Vendor::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
        abort_unless($vendor->status === 'active' && $this->scopes->allowsVendor($actor, 'wifi.deliver', $vendor), 403);
    }

    private function entry(GallonBenefit $benefit, ?GallonClaim $claim, string $event, int $available, int $reserved, int $confirmed, ?User $actor = null, ?string $reason = null): void
    {
        $entry = GallonEntry::query()->create(['benefit_id' => $benefit->id, 'claim_id' => $claim?->id, 'event' => $event, 'available_delta' => $available, 'reserved_delta' => $reserved, 'confirmed_delta' => $confirmed, 'created_by' => $actor?->id, 'reason' => $reason]);
        $benefit->update(['available' => $benefit->available + $available, 'reserved' => $benefit->reserved + $reserved, 'confirmed' => $benefit->confirmed + $confirmed]);
        CommunityAudit::record('wifi.gallon.'.$event, $entry, ['benefit_id' => $benefit->public_id, 'claim_id' => $claim?->public_id], $actor?->id);
    }
}
