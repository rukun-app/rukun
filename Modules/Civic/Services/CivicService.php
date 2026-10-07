<?php

namespace Modules\Civic\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Civic\Models\Announcement;
use Modules\Civic\Models\CivicCase;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;
use Modules\Community\Services\SensitiveIdentifier;

class CivicService
{
    public function __construct(private CivicScope $scope, private ScopeResolver $scopes) {}

    public function announcement(User $actor, string $key, array $data): Announcement
    {
        $area = Area::query()->where('public_id', $data['area_id'])->firstOrFail();
        $id = $this->command($actor, $area, 'announcement.create', $key, $data,
            fn () => $this->scope->manages($actor, $area, 'announcements.manage'), function () use ($actor, $area, $data): string {
                $announcement = Announcement::query()->create(['area_id' => $area->id, 'author_id' => $actor->id, 'title' => $data['title'], 'body' => $data['body'], 'status' => isset($data['publish_at']) ? 'scheduled' : 'draft', 'publish_at' => isset($data['publish_at']) ? CarbonImmutable::parse($data['publish_at'])->setTimezone(config('app.timezone')) : null]);
                $this->attachments($actor, $announcement, $data['attachments'] ?? []);
                CommunityAudit::record('announcement.created', $announcement, actorId: $actor->id);

                return $announcement->public_id;
            });

        return Announcement::query()->where('public_id', $id)->firstOrFail();
    }

    public function publish(User $actor, Announcement $announcement, string $key, bool $archive = false): Announcement
    {
        $area = Area::query()->findOrFail($announcement->area_id);
        $this->command($actor, $area, $archive ? 'announcement.archive' : 'announcement.publish', $key, ['id' => $announcement->public_id],
            fn () => $this->scope->manages($actor, $area, 'announcements.manage'), function () use ($actor, $announcement, $archive): string {
                $announcement->refresh();
                if (! $archive && $announcement->status === 'published') {
                    return $announcement->public_id;
                }
                abort_unless(in_array($announcement->status, $archive ? ['draft', 'scheduled', 'published'] : ['draft', 'scheduled'], true), 409);
                $announcement->update($archive ? ['status' => 'archived'] : ['status' => 'published', 'published_at' => now()]);
                CommunityAudit::record($archive ? 'announcement.archived' : 'announcement.published', $announcement, actorId: $actor->id);
                if (! $archive) {
                    $recipients = [];
                    foreach (User::query()->where('status', 'active')->lazyById(100) as $user) {
                        if ($this->scope->announcement($user, $announcement)) {
                            $recipients[] = $user->id;
                        }
                    }
                    CivicNotices::send($announcement, $recipients);
                }

                return $announcement->public_id;
            });

        return $announcement->refresh();
    }

    public function read(User $actor, Announcement $announcement, bool $read): void
    {
        abort_unless($this->scope->announcement($actor, $announcement) && $announcement->status === 'published', 404);
        $identity = ['announcement_id' => $announcement->id, 'user_id' => $actor->id];
        if ($read) {
            DB::connection('rukun')->table('civic_reads')->upsert([[...$identity, 'read_at' => now()]], ['announcement_id', 'user_id'], ['read_at']);
        } else {
            DB::connection('rukun')->table('civic_reads')->where($identity)->delete();
        }
    }

    public function createCase(User $actor, string $kind, string $key, array $data): CivicCase
    {
        $home = Household::query()->where('public_id', $data['household_id'])->firstOrFail();
        $area = Area::query()->findOrFail($home->area_id);
        $id = $this->command($actor, $area, $kind.'.create', $key, $data,
            fn () => in_array($home->id, $this->scopes->memberHouseholdIds($actor), true), function () use ($actor, $kind, $home, $area, $data): string {
                $case = CivicCase::query()->create(['kind' => $kind, 'area_id' => $area->id, 'household_id' => $home->id, 'reporter_id' => $actor->id, 'category' => $data['category'], 'description' => $data['description']]);
                $this->attachments($actor, $case, $data['attachments'] ?? []);
                $this->timeline($case, $actor, 'submitted', null, null);
                CommunityAudit::record('civic.'.$kind.'.created', $case, actorId: $actor->id);
                $recipients = [$actor->id];
                foreach (User::query()->where('status', 'active')->lazyById(100) as $user) {
                    if ($this->scope->manages($user, $area, $kind === 'report' ? 'reports.manage' : 'letters.manage')) {
                        $recipients[] = $user->id;
                    }
                }
                CivicNotices::send($case, $recipients);

                return $case->public_id;
            });

        return CivicCase::query()->where('public_id', $id)->firstOrFail();
    }

    public function action(User $actor, CivicCase $case, string $key, array $data): CivicCase
    {
        $area = Area::query()->findOrFail($case->area_id);
        $permission = $case->kind === 'report' ? 'reports.manage' : 'letters.manage';
        $this->command($actor, $area, 'case.action', $key, ['id' => $case->public_id, ...$data], fn () => $this->scope->case($actor, $case), function () use ($actor, $case, $area, $permission, $data): string {
            $case->refresh();
            abort_unless($case->version === $data['version'], 409, __('civic::messages.stale'));
            abort_if(in_array($case->status, ['resolved', 'approved', 'rejected', 'cancelled'], true), 409);
            $manager = $this->scope->manages($actor, $area, $permission);
            $action = $data['action'];
            $old = $case->status;
            if ($action === 'assign') {
                abort_unless($manager, 403);
                $target = isset($data['assigned_to']) ? User::query()->where('public_id', $data['assigned_to'])->firstOrFail() : null;
                abort_if($target && ! $this->scope->manages($target, $area, $permission), 422, __('civic::messages.assignee'));
                $case->assigned_to = $target?->id;
            } elseif ($action === 'cancel') {
                abort_unless($case->reporter_id === $actor->id && $old === 'submitted', 403);
                $case->status = 'cancelled';
            } elseif ($action !== 'comment') {
                abort_unless($manager, 403);
                $allowed = $case->kind === 'report'
                    ? ['submitted' => ['in_progress', 'rejected'], 'in_progress' => ['resolved', 'rejected']]
                    : ['submitted' => ['reviewing', 'rejected'], 'reviewing' => ['approved', 'rejected']];
                abort_unless(in_array($action, $allowed[$old] ?? [], true), 409);
                if ($case->kind === 'letter' && in_array($action, ['approved', 'rejected'], true)) {
                    abort_if($case->reporter_id === $actor->id, 403, __('civic::messages.self_review'));
                }
                if ($action === 'approved') {
                    abort_unless(! empty($data['output_file_id']), 422, __('civic::messages.output_required'));
                    $this->attachments($actor, $case, [$data['output_file_id']], 'output');
                }
                $case->status = $action;
            }
            $case->version++;
            $case->save();
            $this->timeline($case, $actor, $action, $old, $data['note'] ?? null);
            CommunityAudit::record('civic.'.$case->kind.'.'.$action, $case, ['from_status' => $old, 'to_status' => $case->status, 'version' => $case->version], $actor->id);
            $recipients = [$case->reporter_id];
            if ($case->assigned_to) {
                $assigned = User::query()->find($case->assigned_to);
                if ($assigned && $this->scope->case($assigned, $case)) {
                    $recipients[] = $assigned->id;
                }
            }
            CivicNotices::send($case, $recipients);

            return $case->public_id;
        });

        return $case->refresh();
    }

    private function timeline(CivicCase $case, User $actor, string $action, ?string $from, ?string $note): void
    {
        DB::connection('rukun')->table('civic_timeline')->insert(['public_id' => (string) Str::uuid(), 'case_id' => $case->id, 'actor_id' => $actor->id, 'action' => $action, 'from_status' => $from, 'to_status' => $case->status ?? 'submitted', 'assigned_to' => $case->assigned_to, 'note' => $note, 'version' => $case->version ?? 1, 'created_at' => now()]);
    }

    private function attachments(User $actor, Model $subject, array $ids, string $purpose = 'attachment'): void
    {
        sort($ids);
        $db = DB::connection('rukun');
        $prefix = config('database.connections.core.prefix');
        foreach ($ids as $id) {
            $file = $db->table($prefix.'files')->where('public_id', $id)->where('owner_id', $actor->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            abort_unless(in_array($file->mime_type, $purpose === 'output' ? ['application/pdf'] : ['application/pdf', 'image/jpeg', 'image/png'], true), 422);
            abort_if($db->table('civic_documents')->where('file_id', $file->id)->exists() || $db->table($prefix.'attachments')->where('file_id', $file->id)->exists() || $db->table('population_exports')->where('file_id', $file->id)->exists() || $db->table('payment_submissions')->where('proof_file_id', $file->id)->exists() || $db->table('expenses')->where('proof_file_id', $file->id)->exists(), 409, __('civic::messages.file_used'));
            $db->table('civic_documents')->insert(['file_id' => $file->id, $subject instanceof Announcement ? 'announcement_id' : 'case_id' => $subject->id, 'purpose' => $purpose]);
            $db->table($prefix.'attachments')->insert(['file_id' => $file->id, 'attachable_type' => $subject->getMorphClass(), 'attachable_id' => $subject->id, 'collection' => $purpose, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function command(User $actor, Area $area, string $operation, string $key, array $data, callable $authorize, callable $callback): string
    {
        return DB::connection('rukun')->transaction(function () use ($actor, $area, $operation, $key, $data, $authorize, $callback): string {
            Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            abort_unless($this->scope->active($actor) && $authorize(), 403);
            $identity = ['actor_id' => $actor->id, 'operation' => $operation, 'request_key' => $key];
            $fingerprint = SensitiveIdentifier::fingerprint(json_encode($data, JSON_THROW_ON_ERROR));
            $old = DB::connection('rukun')->table('civic_commands')->where($identity)->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, __('api.errors.idempotency_conflict'));

                return $old->result;
            }
            $result = $callback();
            DB::connection('rukun')->table('civic_commands')->insert([...$identity, 'fingerprint' => $fingerprint, 'result' => $result]);

            return $result;
        }, 3);
    }
}
