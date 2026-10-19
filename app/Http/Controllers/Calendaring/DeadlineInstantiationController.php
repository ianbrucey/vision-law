<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use App\Models\DeadlineTemplateVersion;
use App\Models\InstantiationBatch;
use App\Models\Matter;
use App\Models\User;
use App\Services\Calendaring\InstantiationService;
use App\Services\Calendaring\RecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Template instantiation endpoints (009 T-12, 009-D20).
 *
 * Routes are matter-scoped and gated by matter.access:edit (route
 * middleware); the service re-checks authorization and the published
 * status. Preview persists nothing; confirm delegates to
 * InstantiationService (single transaction, idempotent).
 */
class DeadlineInstantiationController extends Controller
{
    public function preview(Request $request): JsonResponse|View
    {
        $actor = $this->actor($request);
        $matter = $this->matter($request);
        $version = $this->resolveVersion($request, $matter);
        $inputs = $this->extractInputs($request);

        $result = InstantiationService::preview($matter, $version, $inputs, $actor);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return view('calendaring.instantiations.preview', [
            'matter' => $matter,
            'version' => $version,
            'inputs' => $result['inputs'],
            'items' => $result['items'],
            'idempotencyKey' => Str::uuid()->toString(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $matter = $this->matter($request);
        $version = $this->resolveVersion($request, $matter);
        $inputs = $this->extractInputs($request);

        $key = $request->input('idempotency_key');
        $idempotencyKey = is_string($key) && trim($key) !== '' ? trim($key) : Str::uuid()->toString();

        $batch = InstantiationService::confirm($matter, $version, $inputs, $idempotencyKey, $actor);

        if ($request->wantsJson()) {
            return response()->json($this->batchResource($batch), $batch->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()
            ->route('matters.calendar', $matter)
            ->with('toast', ['tone' => 'ok', 'message' => 'Template instantiated: '.$batch->items()->count().' items created.']);
    }

    public function recalculatePreview(Request $request, string $matter, string $batch): JsonResponse|View
    {
        $actor = $this->actor($request);
        $matterModel = $this->matter($request);
        $batchModel = $this->resolveBatch($matterModel, $batch);
        $newInputs = $this->extractRecalculationInputs($request, $batchModel);
        $resetOverrides = $request->boolean('reset_overrides');

        $preview = RecalculationService::preview($batchModel, $newInputs, $resetOverrides, $actor);

        if ($request->wantsJson()) {
            return response()->json([
                'batch_id' => $preview['batch_id'],
                'inputs' => $preview['inputs'],
                'entries' => array_map(fn (array $entry) => $this->diffResource($entry), $preview['entries']),
            ]);
        }

        return view('calendaring.instantiations.recalculate-preview', [
            'matter' => $matterModel,
            'batch' => $batchModel,
            'inputs' => $preview['inputs'],
            'entries' => $preview['entries'],
            'resetOverrides' => $resetOverrides,
        ]);
    }

    public function recalculate(Request $request, string $matter, string $batch): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $matterModel = $this->matter($request);
        $batchModel = $this->resolveBatch($matterModel, $batch);
        $newInputs = $this->extractRecalculationInputs($request, $batchModel);
        $resetOverrides = $request->boolean('reset_overrides');

        $result = RecalculationService::apply($batchModel, $newInputs, $actor, $resetOverrides);

        if ($request->wantsJson()) {
            return response()->json([
                'batch' => $this->batchResource($result['batch']),
                'superseded' => array_map(fn (CalendarItem $item) => $this->itemResource($item), $result['superseded']),
                'successors' => array_map(fn (CalendarItem $item) => $this->itemResource($item), $result['successors']),
                'preserved' => array_map(fn (CalendarItem $item) => $this->itemResource($item), $result['preserved']),
            ]);
        }

        return redirect()
            ->route('matters.calendar', $matterModel)
            ->with('toast', ['tone' => 'ok', 'message' => 'Recalculation applied: '.count($result['successors']).' items superseded.']);
    }

    /**
     * Recalculation inputs overlay the batch's stored inputs: the
     * caller supplies only what changed (e.g. a new trigger date).
     *
     * @return array<string, mixed>
     */
    protected function extractRecalculationInputs(Request $request, InstantiationBatch $batch): array
    {
        return array_merge($batch->inputs ?? [], $this->extractInputs($request));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function diffResource(array $entry): array
    {
        return [
            'item_id' => $entry['item_id'],
            'rule_key' => $entry['rule_key'],
            'old_date' => $entry['old_date'],
            'new_date' => $entry['new_date'],
            'changed' => (bool) $entry['changed'],
            'date_overridden' => (bool) $entry['date_overridden'],
            'preserved' => (bool) $entry['preserved'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function batchResource(InstantiationBatch $batch): array
    {
        $items = $batch->items()->orderBy('rule_key')->orderBy('id')->get();

        return [
            'id' => (string) $batch->getKey(),
            'matter_id' => (string) $batch->matter_id,
            'template_version_id' => (string) $batch->template_version_id,
            'idempotency_key' => $batch->idempotency_key,
            'inputs' => $batch->inputs,
            'created_by' => $batch->created_by,
            'item_count' => $items->count(),
            'items' => $items->map(fn (CalendarItem $item) => $this->itemResource($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemResource(CalendarItem $item): array
    {
        return [
            'id' => (string) $item->getKey(),
            'rule_key' => $item->rule_key,
            'title' => $item->title,
            'type' => $item->type,
            'status' => $item->status,
            'due_date' => $item->due_date?->toDateString(),
            'date_overridden' => (bool) $item->date_overridden,
            'supersedes_id' => $item->supersedes_id,
            'calc_trace' => $item->calc_trace,
        ];
    }

    /**
     * Inputs arrive either nested under `inputs` or as flat fields;
     * both forms are folded together (flat fields win).
     *
     * @return array<string, mixed>
     */
    protected function extractInputs(Request $request): array
    {
        $inputs = $request->input('inputs');
        $inputs = is_array($inputs) ? $inputs : [];

        foreach (['trigger_date', 'service_date', 'trigger_key', 'timezone', 'holiday_pack'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $inputs[$field] = $value;
            }
        }

        $triggers = $request->input('triggers');
        if (is_array($triggers)) {
            $inputs['triggers'] = $triggers;
        }

        return $inputs;
    }

    protected function resolveVersion(Request $request, Matter $matter): DeadlineTemplateVersion
    {
        $id = $request->input('template_version_id');
        if (! is_string($id) || ! Str::isUuid($id)) {
            abort(422, 'A template_version_id is required.');
        }

        $version = DeadlineTemplateVersion::query()->whereKey($id)->with('template')->first();
        if ($version === null || $version->template === null
            || (string) $version->template->org_id !== (string) $matter->org_id) {
            abort(404);
        }

        return $version;
    }

    protected function resolveBatch(Matter $matter, string $id): InstantiationBatch
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $batch = InstantiationBatch::query()
            ->where('matter_id', (string) $matter->getKey())
            ->whereKey($id)
            ->first();
        if ($batch === null) {
            abort(404);
        }

        return $batch;
    }

    protected function matter(Request $request): Matter
    {
        $matter = $request->attributes->get('matter');
        if (! $matter instanceof Matter) {
            abort(404);
        }

        return $matter;
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
