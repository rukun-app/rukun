<?php

namespace Modules\Engagement\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Services\BillingReport;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\ScopeResolver;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Incident;
use Modules\Engagement\Models\Participant;
use Modules\Engagement\Models\Team;
use Modules\Engagement\Services\EngagementScope;
use Modules\Engagement\Services\EngagementService;
use Modules\Files\Models\StoredFile;

class EngagementController
{
    public function __construct(private EngagementScope $scope, private EngagementService $service) {}

    public function teams(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'area_id' => 'sometimes|uuid']);
        $query = Team::query()->whereIn('area_id', app(ScopeResolver::class)->constrain(Area::query(), $request->user(), 'patrol.manage')->select('id'));
        if ($request->filled('area_id')) {
            $query->where('area_id', Area::query()->where('public_id', $request->input('area_id'))->value('id'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($team) => $this->teamResource($team)));
    }

    public function createTeam(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => 'required|uuid', 'name' => 'required|string|max:120']);

        $result = $this->service->team($request->user(), $this->key($request), $data);

        return ApiResponse::success($this->teamResource(Team::query()->where('public_id', $result['public_id'])->firstOrFail()), 201);
    }

    public function teamMembers(Request $request, Team $team): JsonResponse
    {
        abort_unless(app(ScopeResolver::class)->allows($request->user(), 'patrol.manage', Area::query()->findOrFail($team->area_id)), 404);
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $page = DB::connection('rukun')->table('engagement_team_members')
            ->where('team_id', $team->id)
            ->orderBy('id')
            ->cursorPaginate($request->integer('per_page', 20));

        $result = $page->toArray();
        $result['data'] = $page->getCollection()->map(fn ($m) => [
            'user_id' => User::query()->findOrFail($m->user_id)->public_id,
            'household_id' => Household::query()->findOrFail($m->household_id)->public_id,
        ])->all();

        return ApiResponse::success($result);
    }

    public function updateTeamMember(Request $request, Team $team): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid', 'household_id' => 'required_unless:remove,true|nullable|uuid', 'remove' => 'sometimes|boolean']);
        $remove = (bool) ($data['remove'] ?? false);

        return ApiResponse::success($this->service->teamMember($request->user(), $team, $this->key($request), $data, $remove));
    }

    public function policy(Request $request, Area $area): JsonResponse
    {
        $scopes = app(ScopeResolver::class);
        abort_unless($area->kind === 'rt' && ($scopes->allows($request->user(), 'patrol.manage', $area) || $scopes->allows($request->user(), 'patrol.policy.manage', $area)), 404);
        $row = DB::connection('rukun')->table('patrol_policies')->where('area_id', $area->id)->first();

        return ApiResponse::success($row ? [
            'area_id' => $area->public_id,
            'payment_type_id' => $row->payment_type_id ? PaymentType::query()->findOrFail($row->payment_type_id)->public_id : null,
            'due_days' => $row->due_days,
            'updated_at' => CarbonImmutable::parse($row->updated_at)->toISOString(),
        ] : ['area_id' => $area->public_id, 'payment_type_id' => null, 'due_days' => 7, 'updated_at' => null]);
    }

    public function setPolicy(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => 'required|uuid', 'payment_type_id' => 'nullable|uuid', 'due_days' => 'required|integer|min:1|max:90']);

        $this->service->policy($request->user(), $this->key($request), $data);

        return $this->policy($request, Area::query()->where('public_id', $data['area_id'])->firstOrFail());
    }

    public function events(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'area_id' => 'sometimes|uuid', 'kind' => 'sometimes|in:patrol,activity', 'status' => 'sometimes|in:scheduled,cancelled']);
        $query = $this->scope->events($request->user());
        if ($request->filled('area_id')) {
            $query->where('area_id', Area::query()->where('public_id', $request->input('area_id'))->value('id'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($event) => $this->eventResource($event)));
    }

    public function showEvent(Request $request, string $id): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();

        return ApiResponse::success($this->eventResource($event));
    }

    public function createEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:patrol,activity',
            'area_id' => 'required|uuid',
            'team_id' => 'required_if:kind,patrol|nullable|uuid|prohibited_unless:kind,patrol',
            'title' => 'required|string|max:200',
            'notes' => 'nullable|string|max:5000',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'due_days' => 'sometimes|integer|min:1|max:90',
            'payment_type_id' => 'nullable|uuid|prohibited_unless:kind,activity',
            'attachments' => 'sometimes|array|max:10',
            'attachments.*' => 'required|uuid|distinct',
        ]);

        $result = $this->service->createEvent($request->user(), $this->key($request), $data);

        return ApiResponse::success($this->eventResource(CommunityEvent::query()->where('public_id', $result['public_id'])->firstOrFail()), 201);
    }

    public function cancelEvent(Request $request, string $id): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'reason' => 'required|string|max:2000',
        ]);
        $data['version'] = (int) $data['version'];
        $this->service->cancel($request->user(), $event, $this->key($request), $data);

        return ApiResponse::success($this->eventResource($event->fresh()));
    }

    public function participants(Request $request, string $id): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'status' => 'sometimes|in:active,withdrawn']);
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();
        $query = $this->scope->participants($request->user(), $event);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($p) => $this->participantResource($p)));
    }

    public function join(Request $request, string $id): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();
        $data = $request->validate(['household_id' => 'required|uuid', 'user_id' => 'sometimes|uuid']);

        $result = $this->service->participate($request->user(), $event, $this->key($request), $data);

        return ApiResponse::success($this->participantResource(Participant::query()->where('public_id', $result['public_id'])->firstOrFail()), 201);
    }

    public function enroll(Request $request, string $id): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();
        abort_unless($this->scope->manages($request->user(), $event), 403);

        return $this->join($request, $id);
    }

    public function participantAction(Request $request, string $eventId, string $participantId): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $eventId)->firstOrFail();
        $participant = $this->scope->participants($request->user(), $event)->where('public_id', $participantId)->firstOrFail();
        $data = $request->validate([
            'action' => 'required|in:attendance,leave,approve_leave,reject_leave,charge,withdraw',
            'version' => 'required|integer|min:1',
            'attendance' => 'required_if:action,attendance|in:present,absent|prohibited_unless:action,attendance',
            'note' => [Rule::requiredIf(fn () => in_array($request->input('action'), ['leave', 'reject_leave'], true) || ($request->input('action') === 'approve_leave' && $request->boolean('waive'))), 'nullable', 'string', 'max:2000'],
            'waive' => 'sometimes|boolean|prohibited_unless:action,approve_leave',
            'user_id' => 'prohibited',
            'amount' => 'prohibited',
            'paid_amount' => 'prohibited',
            'invoice_id' => 'prohibited',
        ]);
        $data['version'] = (int) $data['version'];

        $this->service->action($request->user(), $participant, $this->key($request), $data);

        return ApiResponse::success($this->participantResource($participant->fresh()));
    }

    public function history(Request $request, string $eventId, string $participantId): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $eventId)->firstOrFail();
        $participant = $this->scope->participants($request->user(), $event)->where('public_id', $participantId)->firstOrFail();
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $page = DB::connection('rukun')->table('engagement_history')
            ->where('participant_id', $participant->id)
            ->orderBy('version')
            ->cursorPaginate($request->integer('per_page', 20));

        return ApiResponse::success($page->through(fn ($item) => [
            'public_id' => $item->public_id,
            'actor_id' => User::query()->find($item->actor_id)?->public_id,
            'action' => $item->action,
            'version' => $item->version,
            'created_at' => CarbonImmutable::parse($item->created_at)->toISOString(),
        ]));
    }

    public function incidents(Request $request, string $id): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();
        $query = Incident::query()->where('event_id', $event->id);
        if (! $this->scope->manages($request->user(), $event)) {
            $query->where('reported_by', $request->user()->id);
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($inc) => $this->incidentResource($inc)));
    }

    public function createIncident(Request $request, string $id): JsonResponse
    {
        $event = $this->scope->events($request->user())->where('public_id', $id)->firstOrFail();
        $data = $request->validate([
            'description' => 'required|string|max:5000',
            'attachments' => 'sometimes|array|max:10',
            'attachments.*' => 'required|uuid|distinct',
        ]);

        $result = $this->service->incident($request->user(), $event, $this->key($request), $data);

        return ApiResponse::success($this->incidentResource(Incident::query()->where('public_id', $result['public_id'])->firstOrFail()), 201);
    }

    private function teamResource(Team $team): array
    {
        return [
            'public_id' => $team->public_id,
            'area_id' => Area::query()->findOrFail($team->area_id)->public_id,
            'name' => $team->name,
            'created_at' => $team->created_at->toISOString(),
        ];
    }

    private function eventResource(CommunityEvent $event): array
    {
        return [
            'public_id' => $event->public_id,
            'kind' => $event->kind,
            'area_id' => Area::query()->findOrFail($event->area_id)->public_id,
            'team_id' => $event->team_id ? Team::query()->findOrFail($event->team_id)->public_id : null,
            'title' => $event->title,
            'notes' => $event->notes,
            'starts_at' => $event->starts_at->toISOString(),
            'ends_at' => $event->ends_at->toISOString(),
            'status' => $event->status,
            'fee_amount' => $event->fee_amount,
            'due_days' => $event->due_days,
            'version' => $event->version,
            'documents' => $this->eventDocuments($event->id),
            'created_at' => $event->created_at->toISOString(),
        ];
    }

    private function participantResource(Participant $participant): array
    {
        $invoice = $participant->invoice_id ? Invoice::query()->findOrFail($participant->invoice_id) : null;

        return [
            'event_id' => CommunityEvent::query()->findOrFail($participant->event_id)->public_id,
            'leave_recorded_by' => User::query()->find($participant->leave_recorded_by)?->public_id,
            'leave_reviewed_by' => User::query()->find($participant->leave_reviewed_by)?->public_id,
            'leave_review_note' => $participant->leave_review_note,
            'attendance_by' => User::query()->find($participant->attendance_by)?->public_id,
            'billing' => $invoice ? ['amount' => $invoice->amount, ...app(BillingReport::class)->invoice($invoice)] : null,
            'public_id' => $participant->public_id,
            'user_id' => User::query()->findOrFail($participant->user_id)->public_id,
            'household_id' => Household::query()->findOrFail($participant->household_id)->public_id,
            'status' => $participant->status,
            'attendance' => $participant->attendance,
            'attendance_note' => $participant->attendance_note,
            'attendance_at' => $participant->attendance_at?->toISOString(),
            'leave_status' => $participant->leave_status,
            'leave_reason' => $participant->leave_reason,
            'waived' => $participant->waived,
            'invoice_id' => $participant->invoice_id ? Invoice::query()->findOrFail($participant->invoice_id)->public_id : null,
            'version' => $participant->version,
        ];
    }

    private function incidentResource(Incident $incident): array
    {
        return [
            'public_id' => $incident->public_id,
            'event_id' => CommunityEvent::query()->findOrFail($incident->event_id)->public_id,
            'reported_by' => User::query()->findOrFail($incident->reported_by)->public_id,
            'description' => $incident->description,
            'documents' => $this->incidentDocuments($incident->id),
            'created_at' => $incident->created_at->toISOString(),
        ];
    }

    private function eventDocuments(int $eventId): array
    {
        return DB::connection('rukun')->table('engagement_documents')
            ->where('event_id', $eventId)->orderBy('id')->get()
            ->map(fn ($doc) => ['file_id' => StoredFile::query()->findOrFail($doc->file_id)->public_id])
            ->all();
    }

    private function incidentDocuments(int $incidentId): array
    {
        return DB::connection('rukun')->table('engagement_documents')
            ->where('incident_id', $incidentId)->orderBy('id')->get()
            ->map(fn ($doc) => ['file_id' => StoredFile::query()->findOrFail($doc->file_id)->public_id])
            ->all();
    }

    private function key(Request $request): string
    {
        return Validator::make(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']]
        )->validate()['key'];
    }
}
