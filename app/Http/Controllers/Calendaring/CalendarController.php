<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\CalendarFilterPreference;
use App\Models\CalendarItem;
use App\Models\DeadlineTemplateVersion;
use App\Models\InstantiationBatch;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\Calendaring\AgendaQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        [$mode, $anchor, $filters] = $this->params($request, $actor);

        $result = AgendaQuery::forUser($actor, $mode, $anchor, $filters, $this->viewerTimezone($request));

        return view('calendaring.calendar.index', [
            'result' => $result,
            'mode' => $mode,
            'anchor' => $anchor ?? $result['anchor'],
            'filters' => $filters,
            'counts' => $result['counts'],
            'groups' => $result['groups'],
            'items' => $result['items'],
            'matters' => Matter::query()->accessibleBy($actor)->orderBy('title')->get(),
        ]);
    }

    public function agenda(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        [$mode, $anchor, $filters] = $this->params($request, $actor);

        $result = AgendaQuery::forUser($actor, $mode, $anchor, $filters, $this->viewerTimezone($request));

        return response()->json([
            'mode' => $result['mode'],
            'anchor' => $result['anchor'],
            'start' => $result['start'],
            'end' => $result['end'],
            'viewer_timezone' => $result['viewer_timezone'],
            'counts' => $result['counts'],
            'items' => $result['items']->map(fn (CalendarItem $item) => $this->itemJson($item))->values(),
        ]);
    }

    public function matterCalendar(Request $request): View
    {
        $actor = $this->actor($request);
        $matter = $request->attributes->get('matter');
        if (! $matter instanceof Matter) {
            abort(404);
        }

        $items = AgendaQuery::scopedQuery($actor, ['matter_id' => (string) $matter->getKey()])
            ->with(['assignee'])
            ->orderByRaw('coalesce(calendar_items.due_date, calendar_items.occurrence_date, calendar_items.starts_at::date) asc nulls last')
            ->orderBy('calendar_items.title')
            ->get();

        // Instantiation panel data (009 T-12): published versions in
        // the matter's org + this matter's instantiation batches.
        $publishedVersions = DeadlineTemplateVersion::query()
            ->where('status', 'published')
            ->whereHas('template', fn ($query) => $query->where('org_id', (string) $matter->org_id))
            ->with('template')
            ->orderBy('version')
            ->get();
        $instantiationBatches = InstantiationBatch::query()
            ->where('matter_id', (string) $matter->getKey())
            ->with('templateVersion.template')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->get();

        // Annotate via a list agenda over the same matter for changed state.
        return view('calendaring.calendar.matter', [
            'matter' => $matter,
            'items' => $items,
            'canEdit' => $this->canEdit($actor, $matter),
            'publishedVersions' => $publishedVersions,
            'instantiationBatches' => $instantiationBatches,
        ]);
    }

    public function saveFilters(Request $request): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $filters = $this->sanitizeFilters($request->all());

        CalendarFilterPreference::updateOrCreate(
            ['user_id' => (string) $actor->getKey()],
            ['filters' => $filters]
        );

        if ($request->wantsJson()) {
            return response()->json(['filters' => $filters]);
        }

        return redirect()->route('calendar.index')->with('toast', ['tone' => 'ok', 'message' => 'Calendar filters saved.']);
    }

    /**
     * Explicit query params win; otherwise saved preferences apply (009-D26).
     *
     * @return array{0: string, 1: string|null, 2: array<string, mixed>}
     */
    private function params(Request $request, User $actor): array
    {
        $saved = CalendarFilterPreference::query()->where('user_id', $actor->getKey())->first();
        $savedFilters = $saved !== null ? $saved->filters : [];

        $hasExplicit = $request->query('mode') !== null || $request->query('anchor') !== null
            || $request->query('search') !== null || $request->query('q') !== null
            || $request->query('type') !== null || $request->query('status') !== null
            || $request->query('assignee_id') !== null || $request->query('matter_ids') !== null;

        if (! $hasExplicit && $savedFilters !== []) {
            $mode = isset($savedFilters['mode']) && is_string($savedFilters['mode']) ? $savedFilters['mode'] : 'list';
            $anchor = isset($savedFilters['anchor']) && is_string($savedFilters['anchor']) ? $savedFilters['anchor'] : null;

            return [$mode, $anchor, $this->sanitizeFilters($savedFilters)];
        }

        $mode = (string) $request->query('mode', 'list');
        if (! in_array($mode, AgendaQuery::MODES, true)) {
            $mode = 'list';
        }
        $anchor = $request->query('anchor');
        $anchor = is_string($anchor) && $anchor !== '' ? $anchor : null;

        $raw = [
            'search' => $request->query('search', $request->query('q')),
            'type' => $request->query('type'),
            'types' => $request->query('types'),
            'status' => $request->query('status'),
            'statuses' => $request->query('statuses'),
            'assignee_id' => $request->query('assignee_id'),
            'matter_ids' => $request->query('matter_ids'),
        ];

        return [$mode, $anchor, $this->sanitizeFilters($raw)];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $raw): array
    {
        $filters = [];

        $search = $raw['search'] ?? $raw['q'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $filters['search'] = trim($search);
        }

        $types = $raw['types'] ?? (isset($raw['type']) ? [$raw['type']] : null);
        if (is_array($types)) {
            $types = array_values(array_intersect(array_map('strval', $types), CalendarItem::TYPES));
            if ($types !== []) {
                $filters['types'] = $types;
            }
        } elseif (is_string($raw['type'] ?? null) && in_array($raw['type'], CalendarItem::TYPES, true)) {
            $filters['types'] = [$raw['type']];
        }

        $statuses = $raw['statuses'] ?? (isset($raw['status']) ? [$raw['status']] : null);
        if (is_array($statuses)) {
            $statuses = array_values(array_intersect(array_map('strval', $statuses), CalendarItem::STATUSES));
            if ($statuses !== []) {
                $filters['statuses'] = $statuses;
            }
        }

        if (is_string($raw['assignee_id'] ?? null) && Str::isUuid($raw['assignee_id'])) {
            $filters['assignee_id'] = $raw['assignee_id'];
        }

        if (isset($raw['matter_ids'])) {
            $ids = is_array($raw['matter_ids']) ? $raw['matter_ids'] : explode(',', (string) $raw['matter_ids']);
            $ids = array_values(array_filter(array_map('strval', $ids), fn (string $v) => Str::isUuid($v)));
            if ($ids !== []) {
                $filters['matter_ids'] = $ids;
            }
        }

        if (isset($raw['mode']) && is_string($raw['mode'])) {
            $filters['mode'] = $raw['mode'];
        }
        if (isset($raw['anchor']) && is_string($raw['anchor'])) {
            $filters['anchor'] = $raw['anchor'];
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemJson(CalendarItem $item): array
    {
        return [
            'id' => (string) $item->getKey(),
            'matter_id' => (string) $item->matter_id,
            'matter_title' => $item->matter?->title,
            'type' => $item->type,
            'title' => $item->title,
            'status' => $item->status,
            'classification' => $item->classification,
            'due_date' => $item->due_date?->toDateString(),
            'effective_date' => $item->getAttribute('viewer_date') ?? AgendaQuery::effectiveDate($item),
            'assignee_id' => $item->assignee_id,
            'changed' => (bool) $item->getAttribute('changed'),
            'changed_from' => $item->getAttribute('changed_from'),
            'changed_to' => $item->getAttribute('changed_to'),
            'before_status' => $item->getAttribute('changed_from'),
            'after_status' => $item->getAttribute('changed_to'),
        ];
    }

    /**
     * Optional ?tz= viewer-zone override (009-D23, T-15); invalid
     * values are ignored and AgendaQuery falls back to the user's
     * preference zone.
     */
    private function viewerTimezone(Request $request): ?string
    {
        $tz = $request->query('tz');

        return is_string($tz) && $tz !== '' ? $tz : null;
    }

    private function canEdit(User $actor, Matter $matter): bool
    {
        try {
            AccessControl::authorize($actor, 'edit', $matter);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
