<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Models\Tariff;
use Modules\Billing\Services\BillingReport;
use Modules\Billing\Services\BillingService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Services\PopulationService;
use Modules\Files\Services\FileStorageService;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->setTime(12, 0));
    Cache::store('redis')->getStore()->setPrefix('billing-test:'.Str::uuid().':');
    config()->set('broadcasting.default', 'null');
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->rt = Area::factory()->rt()->create();
    $this->otherRt = Area::factory()->rt()->create();
    $this->household = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->otherHousehold = Household::factory()->create(['area_id' => $this->otherRt->id]);
    $this->treasurer = User::factory()->create();
    $this->reviewer = User::factory()->create();
    foreach ([$this->treasurer, $this->reviewer] as $user) {
        RoleAssignment::factory()->create(['user_id' => $user->id, 'role_id' => Role::findByName('bendahara-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->rt->id]);
    }
    $this->citizen = User::factory()->create();
    $resident = app(PopulationService::class)->createResident($this->admin, ['name' => 'Payer', 'household_id' => $this->household->public_id, 'relationship' => 'head']);
    app(PopulationService::class)->account($this->admin, $resident, 'link-payer-account', ['user_id' => $this->citizen->public_id]);
    $this->billing = app(BillingService::class);
    $created = $this->billing->paymentType($this->treasurer, 'create-iuran-type', ['area_id' => $this->rt->public_id, 'code' => 'IURAN', 'name' => 'Iuran', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational']);
    $this->type = PaymentType::query()->where('public_id', $created['public_id'])->firstOrFail();
    $this->billing->tariff($this->treasurer, 'tariff-base-key', $this->type, ['amount' => 100000, 'starts_at' => '2026-01-01']);
    $this->bank = $this->billing->bankAccount($this->treasurer, 'bank-account-key', ['area_id' => $this->rt->public_id, 'bank_name' => 'Bank Uji', 'account_number' => '0000000000', 'account_holder' => 'RT Uji']);
    $this->proof = app(FileStorageService::class)->store(UploadedFile::fake()->image('proof.png'), $this->citizen);
});

function bill($test, string $period = '2026-09', ?PaymentType $type = null, string $state = 'issued'): Invoice
{
    $result = $test->billing->generate($test->treasurer, 'generate-'.Str::uuid(), ['area_id' => $test->rt->public_id, 'payment_type_id' => ($type ?? $test->type)->public_id, 'household_ids' => [$test->household->public_id], 'period' => $period, 'due_date' => $period.'-30', 'state' => $state]);

    return Invoice::query()->where('public_id', $result['invoices'][0])->firstOrFail();
}
function cashData($test, int $amount = 100000, string $date = '2026-09-29'): array
{
    return ['household_id' => $test->household->public_id, 'amount' => $amount, 'paid_on' => $date];
}
function submissionData($test, int $amount = 100000): array
{
    return ['household_id' => $test->household->public_id, 'amount' => $amount, 'transferred_at' => '2026-09-28', 'destination_account_id' => $test->bank['public_id'], 'proof_file_id' => $test->proof->public_id];
}

it('generates invoices idempotently and preserves tariff snapshots after new effective tariffs', function () {
    $data = ['area_id' => $this->rt->public_id, 'payment_type_id' => $this->type->public_id, 'period' => '2026-09', 'due_date' => '2026-09-30'];
    $first = $this->actingAs($this->treasurer)->postJson('/api/billing/invoices/generate', $data, ['Idempotency-Key' => 'generation-repeat-key'])->assertCreated()->json('data.invoices.0');
    $this->postJson('/api/billing/invoices/generate', $data, ['Idempotency-Key' => 'generation-repeat-key'])->assertCreated()->assertJsonPath('data.invoices.0', $first);
    $this->postJson('/api/billing/invoices/generate', $data, ['Idempotency-Key' => 'generation-new-key'])->assertCreated()->assertJsonPath('data.invoices.0', $first);
    expect(Invoice::query()->count())->toBe(1);
    $tariff = Tariff::query()->first();
    $this->postJson('/api/billing/payment-types/'.$this->type->public_id.'/tariffs', ['amount' => 200000, 'starts_at' => '2026-10-01'], ['Idempotency-Key' => 'overlap-tariff-key'])->assertConflict();
    $this->postJson('/api/billing/tariffs/'.$tariff->public_id.'/end', ['ends_at' => '2026-10-01'], ['Idempotency-Key' => 'end-tariff-key'])->assertOk();
    $this->postJson('/api/billing/payment-types/'.$this->type->public_id.'/tariffs', ['amount' => 200000, 'starts_at' => '2026-10-01'], ['Idempotency-Key' => 'new-tariff-key'])->assertCreated();
    expect(bill($this, '2026-10')->amount)->toBe(200000)->and(Invoice::query()->where('public_id', $first)->value('amount'))->toBe(100000);
    $this->postJson('/api/billing/invoices/generate', [...$data, 'subject' => 'changed'], ['Idempotency-Key' => 'generation-repeat-key'])->assertConflict();
    $this->getJson('/api/billing/invoices/'.$first)->assertOk()->assertJsonPath('data.amount', 100000)->assertJsonPath('data.status', 'issued');
});

it('supports partial cash payments and prevents overpay and duplicate receipts', function () {
    $invoice = bill($this);
    $this->actingAs($this->treasurer);
    $first = $this->postJson('/api/billing/receipts/cash', cashData($this, 40000), ['Idempotency-Key' => 'cash-partial-key'])->assertCreated();
    $this->postJson('/api/billing/receipts/cash', cashData($this, 40000), ['Idempotency-Key' => 'cash-partial-key'])->assertCreated()->assertJsonPath('data.public_id', $first->json('data.public_id'));
    $this->getJson('/api/billing/invoices/'.$invoice->public_id)->assertOk()->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.outstanding_amount', 60000);
    $this->postJson('/api/billing/receipts/cash', cashData($this, 70000), ['Idempotency-Key' => 'cash-overpay-key'])->assertConflict();
    $this->postJson('/api/billing/receipts/cash', cashData($this, 60000), ['Idempotency-Key' => 'cash-complete-key'])->assertCreated();
    $this->getJson('/api/billing/invoices/'.$invoice->public_id)->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.outstanding_amount', 0);
    expect(Receipt::query()->count())->toBe(2)->and((int) LedgerEntry::query()->sum('amount'))->toBe(100000);
    $this->postJson('/api/billing/receipts/cash', [...cashData($this), 'amount' => 0], ['Idempotency-Key' => 'cash-zero-key'])->assertUnprocessable();
});

it('prioritizes current must-settle invoices then oldest arrears and separates funds', function () {
    $arrears = bill($this, '2026-08');
    $type = $this->billing->paymentType($this->treasurer, 'wifi-type-key', ['area_id' => $this->rt->public_id, 'code' => 'WIFI', 'name' => 'WiFi', 'collection_policy' => 'must_settle_in_period', 'fund_classification' => 'pass_through']);
    $wifiType = PaymentType::query()->where('public_id', $type['public_id'])->firstOrFail();
    $this->billing->tariff($this->treasurer, 'wifi-tariff-key', $wifiType, ['amount' => 50000, 'starts_at' => '2026-01-01']);
    $wifi = bill($this, '2026-09', $wifiType);
    $current = bill($this);
    $this->billing->cash($this->treasurer, 'allocation-order-key', cashData($this, 80000));
    $report = app(BillingReport::class);
    expect($report->paid($wifi))->toBe(50000)->and($report->paid($arrears))->toBe(30000)->and($report->paid($current))->toBe(0);
    expect((int) LedgerEntry::query()->where('fund_classification', 'pass_through')->sum('amount'))->toBe(50000)->and((int) LedgerEntry::query()->where('fund_classification', 'operational')->sum('amount'))->toBe(30000);
});

it('validates explicit allocations against amount ownership and outstanding balance', function () {
    $invoice = bill($this);
    $this->actingAs($this->treasurer);
    $this->postJson('/api/billing/receipts/cash', [...cashData($this, 50000), 'allocations' => [['invoice_id' => $invoice->public_id, 'amount' => 40000]]], ['Idempotency-Key' => 'bad-total-key'])->assertUnprocessable();
    $this->postJson('/api/billing/receipts/cash', [...cashData($this, 110000), 'allocations' => [['invoice_id' => $invoice->public_id, 'amount' => 110000]]], ['Idempotency-Key' => 'explicit-overpay-key'])->assertConflict();
    $this->postJson('/api/billing/receipts/cash', [...cashData($this, 50000), 'allocations' => [['invoice_id' => $invoice->public_id, 'amount' => 50000]]], ['Idempotency-Key' => 'explicit-valid-key'])->assertCreated();
    expect((int) DB::connection('rukun')->table('receipt_allocations')->sum('amount'))->toBe(50000);
});

it('keeps submissions pending without affecting invoices until scoped independent approval', function () {
    $invoice = bill($this);
    $id = $this->actingAs($this->citizen)->postJson('/api/billing/submissions', submissionData($this), ['Idempotency-Key' => 'manual-submission-key'])->assertCreated()->json('data.public_id');
    expect(Receipt::query()->count())->toBe(0)->and(app(BillingReport::class)->paid($invoice))->toBe(0);
    $this->actingAs($this->treasurer)->get('/api/files/'.$this->proof->public_id.'/download')->assertOk();
    $other = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $other->id, 'role_id' => Role::findByName('bendahara-rt', 'web')->id, 'area_id' => $this->otherRt->id]);
    $this->actingAs($other)->postJson('/api/billing/submissions/'.$id.'/approve', [], ['Idempotency-Key' => 'wrong-reviewer-key'])->assertForbidden();
    $this->get('/api/files/'.$this->proof->public_id.'/download')->assertNotFound();
    $approved = $this->actingAs($this->treasurer)->postJson('/api/billing/submissions/'.$id.'/approve', ['paid_on' => '2026-09-29'], ['Idempotency-Key' => 'approve-manual-key'])->assertOk();
    $this->postJson('/api/billing/submissions/'.$id.'/approve', [], ['Idempotency-Key' => 'approve-duplicate-key'])->assertOk()->assertJsonPath('data.receipt_id', $approved->json('data.receipt_id'));
    expect(Receipt::query()->count())->toBe(1)->and(app(BillingReport::class)->paid($invoice))->toBe(100000)->and(PaymentSubmission::query()->where('public_id', $id)->value('status'))->toBe('approved');
    $this->actingAs($this->citizen)->deleteJson('/api/files/'.$this->proof->public_id)->assertNotFound();
    $this->patchJson('/api/files/'.$this->proof->public_id, ['display_name' => 'Changed'])->assertNotFound();
    expect(DB::table('user_events')->where('user_id', $this->citizen->id)->where('type', 'payment_submission.approved')->count())->toBe(1);
});

it('preserves rejected submissions and rejects changed amounts and self approval', function () {
    bill($this);
    $id = $this->actingAs($this->citizen)->postJson('/api/billing/submissions', submissionData($this), ['Idempotency-Key' => 'reject-submission-key'])->assertCreated()->json('data.public_id');
    $this->actingAs($this->treasurer)->postJson('/api/billing/submissions/'.$id.'/approve', ['amount' => 90000], ['Idempotency-Key' => 'edit-review-amount'])->assertUnprocessable();
    $this->postJson('/api/billing/submissions/'.$id.'/reject', [], ['Idempotency-Key' => 'missing-reason-key'])->assertUnprocessable();
    $this->postJson('/api/billing/submissions/'.$id.'/reject', ['review_note' => 'Nominal tidak sesuai'], ['Idempotency-Key' => 'reject-reason-key'])->assertOk();
    $this->getJson('/api/billing/submissions/'.$id)->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.amount', 100000)->assertJsonPath('data.review_note', 'Nominal tidak sesuai');
    $this->postJson('/api/billing/submissions/'.$id.'/approve', [], ['Idempotency-Key' => 'approve-rejected-key'])->assertConflict();
    $proof = app(FileStorageService::class)->store(UploadedFile::fake()->image('treasurer.png'), $this->treasurer);
    $id = $this->postJson('/api/billing/submissions', [...submissionData($this), 'proof_file_id' => $proof->public_id], ['Idempotency-Key' => 'self-submission-key'])->assertCreated()->json('data.public_id');
    $this->postJson('/api/billing/submissions/'.$id.'/approve', [], ['Idempotency-Key' => 'self-approval-key'])->assertForbidden();
    expect(Receipt::query()->count())->toBe(0);
});

it('rolls back approval when outstanding changed after submission', function () {
    $invoice = bill($this);
    $result = $this->billing->submit($this->citizen, 'pending-then-cash', submissionData($this));
    $this->billing->cash($this->treasurer, 'cash-before-review', cashData($this));
    $this->actingAs($this->reviewer)->postJson('/api/billing/submissions/'.$result['public_id'].'/approve', [], ['Idempotency-Key' => 'approve-too-late'])->assertConflict();
    expect(PaymentSubmission::query()->first()->status)->toBe('pending')->and(Receipt::query()->count())->toBe(1)->and(LedgerEntry::query()->count())->toBe(1)->and(app(BillingReport::class)->paid($invoice))->toBe(100000);
});

it('reverses receipts with immutable ledger history and restored invoice balances', function () {
    $invoice = bill($this);
    $result = $this->billing->cash($this->treasurer, 'cash-to-reverse', cashData($this));
    $receipt = Receipt::query()->where('public_id', $result['public_id'])->firstOrFail();
    $this->actingAs($this->treasurer)->postJson('/api/billing/receipts/'.$receipt->public_id.'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Salah penerimaan'], ['Idempotency-Key' => 'receipt-reversal-key'])->assertOk();
    expect(app(BillingReport::class)->paid($invoice))->toBe(0)->and(LedgerEntry::query()->count())->toBe(2)->and((int) LedgerEntry::query()->sum('amount'))->toBe(0)->and(Receipt::query()->count())->toBe(1);
    $this->postJson('/api/billing/receipts/'.$receipt->public_id.'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Ulang'], ['Idempotency-Key' => 'second-reversal-key'])->assertConflict();
    expect(fn () => LedgerEntry::query()->first()->update(['amount' => 999]))->toThrow(QueryException::class);
    expect(fn () => $receipt->delete())->toThrow(QueryException::class);
});

it('freezes reports and rejects backdating while current receipts can settle closed-period arrears', function () {
    $invoice = bill($this, '2026-08');
    $closed = $this->billing->close($this->treasurer, 'close-august-key', ['area_id' => $this->rt->public_id, 'period' => '2026-08']);
    expect($closed['report']['invoice_outstanding_at_month_end'])->toBe(100000);
    $this->actingAs($this->treasurer)->postJson('/api/billing/receipts/cash', cashData($this, 50000, '2026-08-31'), ['Idempotency-Key' => 'closed-backdate-key'])->assertConflict();
    $this->postJson('/api/billing/receipts/cash', cashData($this, 50000, '2026-07-31'), ['Idempotency-Key' => 'earlier-backdate-key'])->assertConflict();
    $this->postJson('/api/billing/invoices/'.$invoice->public_id.'/cancel', [], ['Idempotency-Key' => 'cancel-closed-key'])->assertConflict();
    $this->postJson('/api/billing/receipts/cash', cashData($this, 50000), ['Idempotency-Key' => 'current-arrears-key'])->assertCreated();
    $this->getJson('/api/billing/reports/monthly?area_id='.$this->rt->public_id.'&period=2026-08')->assertOk()->assertJsonPath('data.closed', true)->assertJsonPath('data.report.invoice_outstanding_at_month_end', 100000);
    expect(app(BillingReport::class)->paid($invoice))->toBe(50000)->and(AccountingPeriod::query()->first()->report)->toEqual($closed['report']);
});

it('reconciles a synthetic month against manual cash and pass-through totals including expenses and corrections', function () {
    bill($this);
    $this->billing->cash($this->treasurer, 'pilot-income-key', cashData($this));
    $expense = $this->actingAs($this->treasurer)->postJson('/api/billing/expenses', ['area_id' => $this->rt->public_id, 'amount' => 25000, 'description' => 'Kebersihan', 'fund_classification' => 'operational', 'channel' => 'cash'], ['Idempotency-Key' => 'pilot-expense-key'])->assertCreated()->json('data.public_id');
    $this->postJson('/api/billing/expenses/'.$expense.'/approve', [], ['Idempotency-Key' => 'pilot-self-approval'])->assertForbidden();
    $this->postJson('/api/billing/expenses/'.$expense.'/post', ['posted_on' => '2026-09-29'], ['Idempotency-Key' => 'post-unapproved-key'])->assertConflict();
    $this->actingAs($this->reviewer)->postJson('/api/billing/expenses/'.$expense.'/approve', [], ['Idempotency-Key' => 'pilot-expense-approve'])->assertOk();
    $this->actingAs($this->treasurer)->postJson('/api/billing/expenses/'.$expense.'/post', ['posted_on' => '2026-09-29'], ['Idempotency-Key' => 'pilot-expense-post'])->assertOk();
    $this->postJson('/api/billing/ledger', ['area_id' => $this->rt->public_id, 'kind' => 'transfer', 'amount' => 30000, 'posted_on' => '2026-09-29', 'channel' => 'cash', 'destination_channel' => 'bank', 'fund_classification' => 'operational', 'reason' => 'Setor bank'], ['Idempotency-Key' => 'pilot-transfer-key'])->assertCreated();
    $adjustment = $this->postJson('/api/billing/ledger', ['area_id' => $this->rt->public_id, 'kind' => 'adjustment', 'amount' => 5000, 'posted_on' => '2026-09-29', 'channel' => 'cash', 'fund_classification' => 'operational', 'reason' => 'Koreksi uji'], ['Idempotency-Key' => 'pilot-adjustment-key'])->assertCreated()->json('data.public_id');
    $this->postJson('/api/billing/ledger/'.$adjustment.'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Balik koreksi'], ['Idempotency-Key' => 'pilot-adjustment-reverse'])->assertOk();
    $report = $this->getJson('/api/billing/reports/monthly?area_id='.$this->rt->public_id.'&period=2026-09')->assertOk()->json('data.report');
    expect($report['closing_balance'])->toBe(75000)->and($report['funds'][0]['closing'])->toBe(45000)->and($report['funds'][1]['closing'])->toBe(30000)->and($report['funds'][2]['closing'])->toBe(0)->and($report['invoice_paid_at_month_end'])->toBe(100000);
    $this->postJson('/api/billing/periods/close', ['area_id' => $this->rt->public_id, 'period' => '2026-09'], ['Idempotency-Key' => 'pilot-close-key'])->assertOk()->assertJsonPath('data.report.closing_balance', 75000);
});

it('limits households and officers by scope including revoked idempotent replays and RW period closes', function () {
    $invoice = bill($this);
    $this->actingAs($this->citizen)->getJson('/api/billing/invoices')->assertOk()->assertJsonCount(1, 'data.data');
    $this->getJson('/api/billing/bank-accounts')->assertOk()->assertJsonCount(1, 'data.data');
    $this->getJson('/api/billing/ledger')->assertOk()->assertJsonCount(0, 'data.data');
    $this->postJson('/api/billing/receipts/cash', cashData($this), ['Idempotency-Key' => 'citizen-cash-denied'])->assertForbidden();
    $this->postJson('/api/billing/submissions', [...submissionData($this), 'household_id' => $this->otherHousehold->public_id], ['Idempotency-Key' => 'citizen-other-household'])->assertForbidden();
    $this->actingAs($this->treasurer)->getJson('/api/billing/reports/monthly?area_id='.$this->otherRt->public_id.'&period=2026-09')->assertForbidden();
    $this->postJson('/api/billing/receipts/cash', cashData($this, 10000), ['Idempotency-Key' => 'revoked-cash-key'])->assertCreated();
    RoleAssignment::query()->where('user_id', $this->treasurer->id)->update(['status' => 'revoked']);
    $this->postJson('/api/billing/receipts/cash', cashData($this, 10000), ['Idempotency-Key' => 'revoked-cash-key'])->assertForbidden();
    $rw = Area::query()->findOrFail($this->rt->parent_id);
    $this->billing->close($this->admin, 'rw-close-key', ['area_id' => $rw->public_id, 'period' => '2026-09']);
    $this->actingAs($this->reviewer)->postJson('/api/billing/receipts/cash', cashData($this, 10000), ['Idempotency-Key' => 'rw-closed-child-key'])->assertConflict();
});

it('handles invoice draft issue cancellation and deduplicated due-soon notifications', function () {
    $invoice = bill($this, state: 'draft');
    $this->actingAs($this->treasurer)->postJson('/api/billing/receipts/cash', cashData($this), ['Idempotency-Key' => 'draft-cash-key'])->assertConflict();
    $this->postJson('/api/billing/invoices/'.$invoice->public_id.'/issue', [], ['Idempotency-Key' => 'issue-draft-key'])->assertOk();
    Artisan::call('billing:notify-due');
    Artisan::call('billing:notify-due');
    expect(DB::table('user_events')->where('user_id', $this->citizen->id)->where('type', 'invoice.due_soon')->count())->toBe(1);
    $this->postJson('/api/billing/invoices/'.$invoice->public_id.'/cancel', [], ['Idempotency-Key' => 'cancel-unpaid-key'])->assertOk();
    $this->getJson('/api/billing/invoices/'.$invoice->public_id)->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(DB::table('audit_events')->where('event', 'invoice.cancel')->count())->toBe(1);
});

it('reverses both legs of a transfer and refuses a second reversal', function () {
    $result = $this->billing->journal($this->treasurer, 'transfer-pair-key', ['area_id' => $this->rt->public_id, 'kind' => 'transfer', 'amount' => 30000, 'posted_on' => '2026-09-29', 'channel' => 'cash', 'destination_channel' => 'bank', 'fund_classification' => 'operational', 'reason' => 'Setor uji']);
    $entry = LedgerEntry::query()->where('public_id', $result['public_id'])->firstOrFail();
    $this->billing->reverseJournal($this->treasurer, 'reverse-pair-key', $entry, ['posted_on' => '2026-09-29', 'reason' => 'Batal setor']);
    expect(LedgerEntry::query()->count())->toBe(4)->and((int) LedgerEntry::query()->where('channel', 'cash')->sum('amount'))->toBe(0)->and((int) LedgerEntry::query()->where('channel', 'bank')->sum('amount'))->toBe(0);
    $this->actingAs($this->treasurer)->postJson('/api/billing/ledger/'.$entry->public_id.'/reverse', ['posted_on' => '2026-09-29', 'reason' => 'Ulang'], ['Idempotency-Key' => 'second-pair-reversal'])->assertConflict();
});

it('serializes simultaneous receipts so only one can consume the remaining invoice balance', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    $invoice = bill($this);
    $actorId = $this->treasurer->id;
    $data = cashData($this, 80000);
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork receipt test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(BillingService::class)->cash(User::query()->findOrFail($actorId), 'concurrent-cash-'.$number, $data);
                exit(0);
            } catch (HttpException $exception) {
                exit($exception->getStatusCode() === 409 ? 10 : 20);
            } catch (Throwable) {
                exit(30);
            }
        }
        $children[] = $pid;
    }
    $codes = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid,$status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::purge('core');
    DB::purge('rukun');
    sort($codes);
    expect($codes)->toBe([0, 10])->and(Receipt::query()->count())->toBe(1)->and(app(BillingReport::class)->paid($invoice))->toBe(80000)->and((int) LedgerEntry::query()->sum('amount'))->toBe(80000);
});
