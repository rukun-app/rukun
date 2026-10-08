<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Tariff;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\CommunityAudit;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Participant;
use Modules\Engagement\Services\EngagementScope;

class EngagementBilling
{
    // This narrow adapter derives every financial field from persisted participation and tariff snapshots.
    // It grants no general billing-management capability to patrol/activity officers.
    public function issue(User $actor, Participant $participant): Invoice
    {
        $event = CommunityEvent::query()->findOrFail($participant->event_id);
        $area = Area::query()->findOrFail($event->area_id);

        return DB::connection('rukun')->transaction(function () use ($actor, $participant, $event, $area): Invoice {
            Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
            $participant->refresh();
            $event->refresh();
            $scope = app(EngagementScope::class);
            abort_unless($scope->manages($actor, $event), 403);
            abort_unless($event->status === 'scheduled' && $participant->status === 'active' && $event->fee_amount > 0, 409);
            if ($event->kind === 'patrol') {
                abort_unless($participant->leave_status === 'approved' && ! $participant->waived && $actor->id !== $participant->user_id, 409);
            }
            $subject = 'engagement:'.$participant->public_id;
            $existing = Invoice::query()->where('subject', $subject)->first();
            if ($existing) {
                return $existing;
            }
            $home = Household::query()->findOrFail($participant->household_id);
            abort_unless($scope->member(User::query()->findOrFail($participant->user_id), $home, $area), 409, __('engagement::messages.member'));
            $homeArea = Area::query()->findOrFail($home->area_id);
            $period = today()->startOfMonth()->toDateString();
            abort_if(AccountingPeriod::query()->whereIn('area_id', array_filter([$homeArea->id, $homeArea->parent_id]))->where('period', '>=', $period)->exists(), 409, __('billing::messages.period_closed'));
            $type = PaymentType::query()->findOrFail($event->payment_type_id);
            $tariff = Tariff::query()->findOrFail($event->tariff_id);
            abort_unless($tariff->payment_type_id === $type->id && $tariff->amount === $event->fee_amount && in_array($type->area_id, [$homeArea->id, $homeArea->parent_id], true) && $type->fund_classification === 'operational' && $type->collection_policy === 'can_accumulate', 409);
            $invoice = Invoice::query()->create(['household_id' => $home->id, 'area_id' => $homeArea->id, 'payment_type_id' => $type->id, 'tariff_id' => $tariff->id, 'period' => $period, 'subject' => $subject, 'amount' => $event->fee_amount, 'due_date' => today()->addDays($event->due_days)->toDateString(), 'settle_by' => today()->addDays($event->due_days)->toDateString(), 'state' => 'issued', 'collection_policy' => $type->collection_policy, 'fund_classification' => $type->fund_classification]);
            CommunityAudit::record('invoice.created', $invoice, ['engagement_participant_id' => $participant->public_id], $actor->id);
            BillingEvents::emit('invoice.created', $invoice, $home->id, [$actor->id]);

            return $invoice;
        }, 3);
    }
}
