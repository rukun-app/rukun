<?php

namespace Modules\Engagement\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Tariff;
use Modules\Billing\Services\EngagementBilling;
use Modules\Civic\Services\CivicNotices;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;
use Modules\Community\Services\SensitiveIdentifier;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Incident;
use Modules\Engagement\Models\Participant;
use Modules\Engagement\Models\Team;
use Modules\Wifi\Models\WifiPackage;

class EngagementService
{
    public function __construct(private EngagementScope $scope, private ScopeResolver $scopes) {}

    public function team(User $actor, string $key, array $data): array
    {
        $area = Area::query()->where('public_id', $data['area_id'])->where('kind', 'rt')->firstOrFail();

        return $this->command($actor, $area, 'team.create', $key, $data, fn () => $this->scopes->allows($actor, 'patrol.manage', $area), function () use ($actor, $area, $data): array {
            abort_if(Team::query()->where('area_id', $area->id)->where('name', $data['name'])->exists(), 409);
            $team = Team::query()->create(['area_id' => $area->id, 'name' => $data['name']]);
            CommunityAudit::record('patrol.team.created', $team, actorId: $actor->id);

            return ['public_id' => $team->public_id];
        });
    }

    public function teamMember(User $actor, Team $team, string $key, array $data, bool $remove = false): array
    {
        $area = Area::query()->findOrFail($team->area_id);

        return $this->command($actor, $area, 'team.member', $key, ['team' => $team->public_id, 'remove' => $remove, ...$data], fn () => $this->scopes->allows($actor, 'patrol.manage', $area), function () use ($actor, $team, $area, $data, $remove): array {
            $user = User::query()->where('public_id', $data['user_id'])->firstOrFail();
            $query = DB::connection('rukun')->table('engagement_team_members')->where('team_id', $team->id)->where('user_id', $user->id);
            if ($remove) {
                $query->delete();
            } else {
                $home = Household::query()->where('public_id', $data['household_id'])->firstOrFail();
                abort_unless($this->scope->member($user, $home, $area), 422, __('engagement::messages.member'));
                $query->updateOrInsert(['team_id' => $team->id, 'user_id' => $user->id], ['household_id' => $home->id]);
            }
            CommunityAudit::record($remove ? 'patrol.team.member_removed' : 'patrol.team.member_added', $team, ['user_id' => $user->public_id], $actor->id);

            return ['public_id' => $team->public_id];
        });
    }

    public function policy(User $actor, string $key, array $data): array
    {
        $area = Area::query()->where('public_id', $data['area_id'])->where('kind', 'rt')->firstOrFail();

        return $this->command($actor, $area, 'patrol.policy', $key, $data, fn () => $this->scopes->allows($actor, 'patrol.policy.manage', $area), function () use ($actor, $area, $data): array {
            $type = isset($data['payment_type_id']) ? $this->paymentType($data['payment_type_id'], $area) : null;
            DB::connection('rukun')->table('patrol_policies')->updateOrInsert(['area_id' => $area->id], ['payment_type_id' => $type?->id, 'due_days' => $data['due_days'], 'updated_by' => $actor->id, 'updated_at' => now()]);
            CommunityAudit::record('patrol.policy.updated', $area, ['payment_type_id' => $type?->public_id, 'due_days' => $data['due_days']], $actor->id);

            return ['area_id' => $area->public_id];
        });
    }

    public function createEvent(User $actor, string $key, array $data): array
    {
        $area = Area::query()->where('public_id', $data['area_id'])->firstOrFail();
        $permission = $data['kind'] === 'patrol' ? 'patrol.manage' : 'activities.manage';

        return $this->command($actor, $area, 'event.create', $key, $data, fn () => $this->scopes->allows($actor, $permission, $area), function () use ($actor, $area, $data): array {
            $start = CarbonImmutable::parse($data['starts_at'])->setTimezone(config('app.timezone'));
            $end = CarbonImmutable::parse($data['ends_at'])->setTimezone(config('app.timezone'));
            abort_unless($start->isFuture() && $end->greaterThan($start), 422);
            $team = null;
            $type = null;
            $dueDays = $data['due_days'] ?? 7;
            if ($data['kind'] === 'patrol') {
                abort_unless($area->kind === 'rt', 422);
                $team = Team::query()->where('public_id', $data['team_id'])->where('area_id', $area->id)->firstOrFail();
                $policy = DB::connection('rukun')->table('patrol_policies')->where('area_id', $area->id)->first();
                $type = $policy?->payment_type_id ? PaymentType::query()->findOrFail($policy->payment_type_id) : null;
                $dueDays = $policy?->due_days ?? 7;
            } elseif (isset($data['payment_type_id'])) {
                abort_unless($this->scopes->allows($actor, 'activities.fees.manage', $area), 403);
                $type = $this->paymentType($data['payment_type_id'], $area);
            }
            $tariff = $type ? Tariff::query()->where('payment_type_id', $type->id)->where('starts_at', '<=', $start->toDateString())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start->toDateString()))->first() : null;
            abort_if($type && ! $tariff, 422, __('engagement::messages.tariff'));
            $event = CommunityEvent::query()->create(['kind' => $data['kind'], 'area_id' => $area->id, 'team_id' => $team?->id, 'title' => $data['title'], 'notes' => $data['notes'] ?? null, 'starts_at' => $start, 'ends_at' => $end, 'payment_type_id' => $type?->id, 'tariff_id' => $tariff?->id, 'fee_amount' => $tariff?->amount ?? 0, 'due_days' => $dueDays, 'created_by' => $actor->id]);
            $this->attachments($actor, $event, $data['attachments'] ?? []);
            if ($team) {
                $members = DB::connection('rukun')->table('engagement_team_members')->where('team_id', $team->id)->get();
                abort_if($members->isEmpty(), 422, __('engagement::messages.empty_team'));
                foreach ($members as $member) {
                    $user = User::query()->findOrFail($member->user_id);
                    $home = Household::query()->findOrFail($member->household_id);
                    abort_unless($this->scope->member($user, $home, $area), 422, __('engagement::messages.member'));
                    $this->addParticipant($actor, $event, $user, $home);
                }
            }
            CommunityAudit::record('engagement.event.created', $event, actorId: $actor->id);
            $recipients = [];
            foreach (User::query()->where('status', 'active')->lazyById(100) as $user) {
                if ($this->scope->event($user, $event)) {
                    $recipients[] = $user->id;
                }
            }
            CivicNotices::send($event, $recipients);

            return ['public_id' => $event->public_id];
        });
    }

    public function cancel(User $actor, CommunityEvent $event, string $key, array $data): array
    {
        return $this->command($actor, Area::query()->findOrFail($event->area_id), 'event.cancel', $key, ['event' => $event->public_id, ...$data], fn () => $this->scope->manages($actor, $event), function () use ($actor, $event, $data): array {
            $event->refresh();
            abort_unless($event->version === $data['version'] && $event->status === 'scheduled' && $event->starts_at->isFuture(), 409);
            $invoices = Participant::query()->where('event_id', $event->id)->whereNotNull('invoice_id')->pluck('invoice_id');
            abort_if(Invoice::query()->whereIn('id', $invoices)->where('state', '!=', 'cancelled')->exists(), 409, __('engagement::messages.invoice_active'));
            $event->update(['status' => 'cancelled', 'version' => $event->version + 1]);
            CommunityAudit::record('engagement.event.cancelled', $event, ['reason' => $data['reason']], $actor->id);
            CivicNotices::send($event, Participant::query()->where('event_id', $event->id)->pluck('user_id')->all());

            return ['public_id' => $event->public_id];
        });
    }

    public function participate(User $actor, CommunityEvent $event, string $key, array $data): array
    {
        return $this->command($actor, Area::query()->findOrFail($event->area_id), 'participant.create', $key, ['event' => $event->public_id, ...$data], fn () => $this->scope->event($actor, $event), function () use ($actor, $event, $data): array {
            $event->refresh();
            abort_unless($event->status === 'scheduled' && $event->starts_at->isFuture(), 409);
            $user = isset($data['user_id']) ? User::query()->where('public_id', $data['user_id'])->firstOrFail() : $actor;
            abort_unless(($event->kind === 'activity' && $actor->is($user)) || $this->scope->manages($actor, $event), 403);
            $home = Household::query()->where('public_id', $data['household_id'])->firstOrFail();
            abort_unless($this->scope->member($user, $home, Area::query()->findOrFail($event->area_id)), 422, __('engagement::messages.member'));
            abort_if(Participant::query()->where('event_id', $event->id)->where('user_id', $user->id)->exists(), 409);
            $participant = $this->addParticipant($actor, $event, $user, $home);
            CivicNotices::send($participant, [$user->id]);

            return ['public_id' => $participant->public_id];
        });
    }

    private function addParticipant(User $actor, CommunityEvent $event, User $user, Household $home): Participant
    {
        if ($event->kind === 'patrol') {
            $overlap = Participant::query()->where('user_id', $user->id)->where('status', 'active')->whereIn('event_id', CommunityEvent::query()->select('id')->where('kind', 'patrol')->where('status', 'scheduled')->where('starts_at', '<', $event->ends_at)->where('ends_at', '>', $event->starts_at))->exists();
            abort_if($overlap, 409, __('engagement::messages.overlap'));
        }
        $participant = Participant::query()->create(['event_id' => $event->id, 'user_id' => $user->id, 'household_id' => $home->id]);
        $this->history($actor, $participant, 'assigned');

        return $participant;
    }

    public function action(User $actor, Participant $participant, string $key, array $data): array
    {
        $event = CommunityEvent::query()->findOrFail($participant->event_id);
        $area = Area::query()->findOrFail($event->area_id);

        return $this->command($actor, $area, 'participant.action', $key, ['participant' => $participant->public_id, ...$data], fn () => $this->scope->participants($actor, $event)->whereKey($participant->id)->exists(), function () use ($actor, $participant, $event, $area, $data): array {
            $event->refresh();
            $participant->refresh();
            abort_unless($event->status === 'scheduled' && $participant->status === 'active' && $participant->version === $data['version'], 409);
            $manager = $this->scope->manages($actor, $event);
            $home = Household::query()->findOrFail($participant->household_id);
            $user = User::query()->findOrFail($participant->user_id);
            $action = $data['action'];
            if (in_array($action, ['leave', 'reject_leave'], true) || ($action === 'approve_leave' && ($data['waive'] ?? false))) {
                abort_unless(is_string($data['note'] ?? null) && trim($data['note']) !== '', 422);
            }
            if ($action === 'leave') {
                abort_unless($event->kind === 'patrol' && $event->starts_at->isFuture() && $participant->leave_status === 'none' && $participant->attendance === 'pending', 409);
                abort_unless($this->scope->member($user, $home, $area), 409, __('engagement::messages.member'));
                $participant->fill(['leave_status' => 'pending', 'leave_reason' => $data['note'], 'leave_recorded_by' => $actor->id]);
            } elseif (in_array($action, ['approve_leave', 'reject_leave'], true)) {
                abort_unless($manager, 403);
                abort_if($actor->id === $participant->user_id, 403, __('engagement::messages.self_review'));
                abort_unless($event->kind === 'patrol' && $participant->leave_status === 'pending' && $participant->attendance === 'pending' && now()->lessThanOrEqualTo($event->ends_at->addDay()), 409);
                $participant->fill(['leave_status' => $action === 'approve_leave' ? 'approved' : 'rejected', 'leave_reviewed_by' => $actor->id, 'leave_review_note' => $data['note'] ?? null, 'waived' => $action === 'approve_leave' && ($data['waive'] ?? false)]);
                if ($action === 'approve_leave') {
                    $participant->attendance = 'excused';
                    $participant->attendance_by = $actor->id;
                    $participant->attendance_at = now();
                    $participant->save();
                    if ($event->fee_amount > 0 && ! $participant->waived) {
                        $participant->invoice_id = app(EngagementBilling::class)->issue($actor, $participant)->id;
                    }
                }
            } elseif ($action === 'attendance') {
                abort_unless($manager, 403);
                abort_unless(in_array($data['attendance'] ?? null, ['present', 'absent'], true), 422);
                abort_unless(now()->greaterThanOrEqualTo($event->starts_at) && now()->lessThanOrEqualTo($event->ends_at->addDay()) && $participant->attendance === 'pending' && $participant->leave_status !== 'pending', 409);
                $participant->fill(['attendance' => $data['attendance'], 'attendance_note' => $data['note'] ?? null, 'attendance_by' => $actor->id, 'attendance_at' => now()]);
            } elseif ($action === 'charge') {
                abort_unless($manager, 403);
                abort_unless($event->kind === 'activity' && ! $participant->invoice_id && $event->fee_amount > 0, 409);
                $participant->invoice_id = app(EngagementBilling::class)->issue($actor, $participant)->id;
            } elseif ($action === 'withdraw') {
                abort_unless($event->kind === 'activity' || $manager, 403);
                abort_unless($event->starts_at->isFuture() && $participant->attendance === 'pending' && in_array($participant->leave_status, ['none', 'rejected'], true), 409);
                abort_if($participant->invoice_id && Invoice::query()->findOrFail($participant->invoice_id)->state !== 'cancelled', 409, __('engagement::messages.invoice_active'));
                $participant->status = 'withdrawn';
            } else {
                abort(422);
            }
            $participant->version++;
            $participant->save();
            $this->history($actor, $participant, $action);
            $recipients = [$participant->user_id];
            if ($action === 'leave') {
                foreach (User::query()->where('status', 'active')->lazyById(100) as $officer) {
                    if ($this->scope->manages($officer, $event)) {
                        $recipients[] = $officer->id;
                    }
                }
            }
            CivicNotices::send($participant, $recipients);

            return ['public_id' => $participant->public_id];
        });
    }

    public function incident(User $actor, CommunityEvent $event, string $key, array $data): array
    {
        return $this->command($actor, Area::query()->findOrFail($event->area_id), 'incident.create', $key, ['event' => $event->public_id, ...$data], fn () => $this->scope->event($actor, $event), function () use ($actor, $event, $data): array {
            $event->refresh();
            abort_unless($event->status === 'scheduled' && now()->greaterThanOrEqualTo($event->starts_at), 409);
            abort_unless($this->scope->manages($actor, $event) || Participant::query()->where('event_id', $event->id)->where('user_id', $actor->id)->where('status', 'active')->exists(), 403);
            $incident = Incident::query()->create(['event_id' => $event->id, 'reported_by' => $actor->id, 'description' => $data['description']]);
            $this->attachments($actor, $incident, $data['attachments'] ?? []);
            CommunityAudit::record('engagement.incident.created', $incident, actorId: $actor->id);
            $recipients = [$actor->id];
            foreach (User::query()->where('status', 'active')->lazyById(100) as $user) {
                if ($this->scope->manages($user, $event)) {
                    $recipients[] = $user->id;
                }
            }
            CivicNotices::send($incident, $recipients);

            return ['public_id' => $incident->public_id];
        });
    }

    private function history(User $actor, Participant $participant, string $action): void
    {
        DB::connection('rukun')->table('engagement_history')->insert(['public_id' => (string) Str::uuid(), 'participant_id' => $participant->id, 'actor_id' => $actor->id, 'action' => $action, 'version' => $participant->version, 'created_at' => now()]);
        CommunityAudit::record('engagement.participant.'.$action, $participant, ['version' => $participant->version], $actor->id);
    }

    private function paymentType(string $id, Area $area): PaymentType
    {
        $type = PaymentType::query()->where('public_id', $id)->where('area_id', $area->id)->firstOrFail();
        abort_unless($type->fund_classification === 'operational' && $type->collection_policy === 'can_accumulate' && ! WifiPackage::query()->where('payment_type_id', $type->id)->exists(), 422);

        return $type;
    }

    private function attachments(User $actor, Model $subject, array $ids): void
    {
        sort($ids);
        $db = DB::connection('rukun');
        $prefix = config('database.connections.core.prefix');
        foreach ($ids as $id) {
            $file = $db->table($prefix.'files')->where('public_id', $id)->where('owner_id', $actor->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_unless(in_array($file->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true), 422);
            abort_if($db->table($prefix.'attachments')->where('file_id', $file->id)->exists() || $db->table('population_exports')->where('file_id', $file->id)->exists() || $db->table('payment_submissions')->where('proof_file_id', $file->id)->exists() || $db->table('expenses')->where('proof_file_id', $file->id)->exists(), 409, __('civic::messages.file_used'));
            $db->table('engagement_documents')->insert(['file_id' => $file->id, $subject instanceof CommunityEvent ? 'event_id' : 'incident_id' => $subject->id]);
            $db->table($prefix.'attachments')->insert(['file_id' => $file->id, 'attachable_type' => $subject->getMorphClass(), 'attachable_id' => $subject->id, 'collection' => 'attachment', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function command(User $actor, Area $area, string $operation, string $key, array $data, callable $authorize, callable $callback): array
    {
        return DB::connection('rukun')->transaction(function () use ($actor, $area, $operation, $key, $data, $authorize, $callback): array {
            Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            abort_unless($this->scope->active($actor) && $authorize(), 403);
            $identity = ['actor_id' => $actor->id, 'operation' => 'engagement.'.$operation, 'request_key' => $key];
            $fingerprint = SensitiveIdentifier::fingerprint(json_encode($data, JSON_THROW_ON_ERROR));
            $old = DB::connection('rukun')->table('billing_requests')->where($identity)->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, __('api.errors.idempotency_conflict'));

                return json_decode($old->result, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $callback();
            DB::connection('rukun')->table('billing_requests')->insert([...$identity, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }
}
