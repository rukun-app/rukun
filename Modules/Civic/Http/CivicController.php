<?php

namespace Modules\Civic\Http;

use App\Models\User;
use Carbon\CarbonImmutable;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Civic\Models\Announcement;
use Modules\Civic\Models\CivicCase;
use Modules\Civic\Services\CivicScope;
use Modules\Civic\Services\CivicService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Files\Models\StoredFile;

class CivicController
{
    public function __construct(private CivicScope $scope, private CivicService $service) {}

    public function announcements(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'area_id' => 'sometimes|uuid', 'read' => 'sometimes|boolean']);
        $query = $this->scope->announcements($request->user());
        if ($request->filled('area_id')) {
            $query->whereIn('area_id', Area::query()->select('id')->where('public_id', $request->input('area_id')));
        }
        if ($request->has('read')) {
            $query->whereIn('id', DB::connection('rukun')->table('civic_reads')->select('announcement_id')->where('user_id', $request->user()->id), 'and', ! $request->boolean('read'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($row) => $this->announcementResource($request, $row)));
    }

    public function announcement(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->announcementResource($request, $this->scope->announcements($request->user())->where('public_id', $id)->firstOrFail()));
    }

    public function createAnnouncement(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => 'required|uuid', 'title' => 'required|string|max:200', 'body' => 'required|string|max:10000', 'publish_at' => 'nullable|date', 'attachments' => 'sometimes|array|max:10', 'attachments.*' => 'required|uuid|distinct']);

        return ApiResponse::success($this->announcementResource($request, $this->service->announcement($request->user(), $this->key($request), $data)), 201);
    }

    public function announcementAction(Request $request, string $id, string $action): JsonResponse
    {
        $row = $this->scope->announcements($request->user())->where('public_id', $id)->firstOrFail();
        if (in_array($action, ['read', 'unread'], true)) {
            $this->service->read($request->user(), $row, $action === 'read');
        } else {
            $row = $this->service->publish($request->user(), $row, $this->key($request), $action === 'archive');
        }

        return ApiResponse::success($this->announcementResource($request, $row));
    }

    public function cases(Request $request, string $kind): JsonResponse
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'status' => 'sometimes|in:submitted,in_progress,reviewing,resolved,approved,rejected,cancelled', 'area_id' => 'sometimes|uuid']);
        $query = $this->scope->cases($request->user(), $kind);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('area_id')) {
            $query->whereIn('area_id', Area::query()->select('id')->where('public_id', $request->input('area_id')));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($row) => $this->caseResource($row)));
    }

    public function showCase(Request $request, string $id, string $kind): JsonResponse
    {
        return ApiResponse::success($this->caseResource($this->scope->cases($request->user(), $kind)->where('public_id', $id)->firstOrFail()));
    }

    public function createCase(Request $request, string $kind): JsonResponse
    {
        $data = $request->validate(['household_id' => 'required|uuid', 'category' => 'required|string|max:80', 'description' => 'required|string|max:10000', 'attachments' => 'sometimes|array|max:10', 'attachments.*' => 'required|uuid|distinct']);

        return ApiResponse::success($this->caseResource($this->service->createCase($request->user(), $kind, $this->key($request), $data)), 201);
    }

    public function caseAction(Request $request, string $id, string $kind): JsonResponse
    {
        $row = $this->scope->cases($request->user(), $kind)->where('public_id', $id)->firstOrFail();
        $data = $request->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:assign,comment,cancel,in_progress,resolved,rejected,reviewing,approved', 'note' => 'required_if:action,comment,rejected|nullable|string|max:5000', 'assigned_to' => 'present_if:action,assign|nullable|uuid|prohibited_unless:action,assign', 'output_file_id' => 'required_if:action,approved|uuid|prohibited_unless:action,approved']);
        $data['version'] = (int) $data['version'];

        return ApiResponse::success($this->caseResource($this->service->action($request->user(), $row, $this->key($request), $data)));
    }

    public function timeline(Request $request, string $id, string $kind): JsonResponse
    {
        $row = $this->scope->cases($request->user(), $kind)->where('public_id', $id)->firstOrFail();
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $page = DB::connection('rukun')->table('civic_timeline')->where('case_id', $row->id)->orderBy('version')->cursorPaginate($request->integer('per_page', 20));

        return ApiResponse::success($page->through(fn ($item) => ['public_id' => $item->public_id, 'actor_id' => User::query()->find($item->actor_id)?->public_id, 'action' => $item->action, 'from_status' => $item->from_status, 'to_status' => $item->to_status, 'assigned_to' => User::query()->find($item->assigned_to)?->public_id, 'note' => $item->note, 'version' => $item->version, 'created_at' => CarbonImmutable::parse($item->created_at)->toISOString()]));
    }

    private function announcementResource(Request $request, Announcement $row): array
    {
        $readAt = DB::connection('rukun')->table('civic_reads')->where('announcement_id', $row->id)->where('user_id', $request->user()->id)->value('read_at');

        return ['public_id' => $row->public_id, 'area_id' => Area::query()->findOrFail($row->area_id)->public_id, 'author_id' => User::query()->find($row->author_id)?->public_id, 'title' => $row->title, 'body' => $row->body, 'status' => $row->status, 'publish_at' => $row->publish_at?->toISOString(), 'published_at' => $row->published_at?->toISOString(), 'read_at' => $readAt ? CarbonImmutable::parse($readAt)->toISOString() : null, 'documents' => $this->documents('announcement_id', $row->id), 'created_at' => $row->created_at->toISOString()];
    }

    private function caseResource(CivicCase $row): array
    {
        return ['public_id' => $row->public_id, 'kind' => $row->kind, 'area_id' => Area::query()->findOrFail($row->area_id)->public_id, 'household_id' => Household::query()->findOrFail($row->household_id)->public_id, 'reporter_id' => User::query()->find($row->reporter_id)?->public_id, 'category' => $row->category, 'description' => $row->description, 'status' => $row->status, 'assigned_to' => User::query()->find($row->assigned_to)?->public_id, 'version' => $row->version, 'documents' => $this->documents('case_id', $row->id), 'created_at' => $row->created_at->toISOString(), 'updated_at' => $row->updated_at->toISOString()];
    }

    private function documents(string $column, int $id): array
    {
        return DB::connection('rukun')->table('civic_documents')->where($column, $id)->orderBy('id')->get()->map(fn ($doc) => ['file_id' => StoredFile::query()->findOrFail($doc->file_id)->public_id, 'purpose' => $doc->purpose])->all();
    }

    private function key(Request $request): string
    {
        return Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }
}
