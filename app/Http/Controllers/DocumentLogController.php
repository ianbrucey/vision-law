<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\MatterDocumentLog;
use App\Models\User;
use App\Services\MatterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Received/sent document register endpoints (spec 006 T-03; MAT-04).
 *
 * - POST  /matters/{matter}/document-log       → store (matter.access:edit ≈ :update until T-06)
 * - PATCH /matters/{matter}/document-log/{log} → update (annotations ONLY; matter.access:edit ≈ :update)
 *
 * Append-only by design: there are deliberately NO update (core fields) or
 * delete routes — corrections are new rows. The PATCH route rejects every
 * key except `annotations` with 422, so core fields are immutable through
 * the HTTP surface too.
 */
class DocumentLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'direction' => ['required', 'string', Rule::in(MatterDocumentLog::DIRECTIONS)],
            'counterparty' => ['required', 'string', 'max:255'],
            'logged_at' => ['required', 'date'],
            'method' => ['required', 'string', Rule::in(MatterDocumentLog::METHODS)],
            'notes' => ['nullable', 'string'],
            'annotations' => ['nullable', 'array'],
        ]);

        $log = MatterService::logDocument($matter, $validated, $actor);

        return response()->json($this->resource($log), 201);
    }

    public function update(Request $request, string $matter, string $log): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $logModel = $this->resolveLog($matterModel, $log);

        // Append-only: the ONLY mutable key is `annotations`. Any other key
        // is rejected — a correction to a core field must be a new row.
        $extra = array_diff(array_keys($request->all()), ['annotations']);

        if ($extra !== []) {
            throw ValidationException::withMessages([
                'annotations' => ['Only the annotations field may be updated; core log fields are immutable.'],
            ]);
        }

        /** @var array{annotations?: array<string, mixed>|null} $validated */
        $validated = $request->validate([
            'annotations' => ['nullable', 'array'],
        ]);

        $updated = MatterService::updateDocumentAnnotations(
            $matterModel,
            $logModel,
            $validated['annotations'] ?? null,
            $actor
        );

        return response()->json($this->resource($updated));
    }

    /**
     * @throws ModelNotFoundException
     */
    private function resolveLog(Matter $matter, string $raw): MatterDocumentLog
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        return MatterDocumentLog::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->firstOrFail();
    }

    private function authorizedMatter(Request $request): Matter
    {
        $matter = $request->attributes->get('matter');

        if (! $matter instanceof Matter) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        return $matter;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(MatterDocumentLog $log): array
    {
        return [
            'id' => (string) $log->getKey(),
            'direction' => $log->direction,
            'counterparty' => $log->counterparty,
            'logged_at' => $log->logged_at->toIso8601String(),
            'method' => $log->method,
            'notes' => $log->notes,
            'annotations' => $log->annotations ?? [],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
