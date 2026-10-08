<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cross-matter document search (spec 007 T-06, DOC-14/15/16/17; C-09
 * search half).
 *
 * GET /search/documents — mirrors 006-D12's /search/matters placement
 * (not matter-nested) because the query spans matters.
 *
 * - `q`: keyword over title/tags/description (trigram + ILIKE fallback,
 *   same pattern as DocumentQueryService).
 * - `content`: full-text over extracted/OCR page text (websearch
 *   tsquery — supports "exact phrase" and -excluded terms), ranked by
 *   ts_rank, with per-page snippets (<mark> hits), per-page match
 *   counts, and a preview_url that opens the preview at the matched
 *   page (?page=, honored by the T-03 preview viewer).
 * - Facets: matter_id, kind, folder_id, uploader, date_from/to,
 *   retention_flagged. 50/page.
 *
 * STRICTLY permission-scoped: the document set is restricted to matters
 * the caller can access BEFORE any text is selected, so an ungranted
 * user gets zero hits and no titles, snippets, or counts ever leak
 * (leak sentinel). Quarantined and trashed documents are never
 * searchable.
 */
class DocumentSearchController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        /** @var array{q?: ?string, content?: ?string, matter_id?: ?string, kind?: ?string, folder_id?: ?string, uploader?: ?string, date_from?: ?string, date_to?: ?string, retention_flagged?: ?string} $validated */
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string', 'max:500'],
            'matter_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', 'string', 'max:20'],
            'folder_id' => ['nullable', 'uuid'],
            'uploader' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'retention_flagged' => ['nullable', 'in:0,1'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $content = trim((string) ($validated['content'] ?? ''));

        if ($q === '' && $content === '') {
            return response()->json([
                'data' => [],
                'meta' => $this->meta(1, 0),
            ]);
        }

        $accessibleMatters = Matter::query()
            ->accessibleBy($actor)
            ->select('matters.id');

        if ($content !== '') {
            $page = $this->contentSearch($actor, $accessibleMatters, $validated, $q, $content);

            return response()->json([
                'data' => $page->getCollection()->map(fn (\stdClass $row): array => $this->pageResource($row, $content))->values()->all(),
                'meta' => $this->meta($page->currentPage(), $page->total(), $page->lastPage()),
            ]);
        }

        $page = $this->keywordSearch($actor, $accessibleMatters, $validated, $q);

        return response()->json([
            'data' => $page->getCollection()->map(fn (\stdClass $row): array => $this->documentResource($row))->values()->all(),
            'meta' => $this->meta($page->currentPage(), $page->total(), $page->lastPage()),
        ]);
    }

    // ------------------------------------------------------------------
    // Full-text (content) search
    // ------------------------------------------------------------------

    /**
     * @param  Builder<Matter>  $accessibleMatters
     * @param  array{q?: ?string, content?: ?string, matter_id?: ?string, kind?: ?string, folder_id?: ?string, uploader?: ?string, date_from?: ?string, date_to?: ?string, retention_flagged?: ?string}  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function contentSearch(User $actor, object $accessibleMatters, array $filters, string $q, string $content): LengthAwarePaginator
    {
        // websearch_to_tsquery gives phrase ("exact phrase") and exclusion
        // (-term) support with zero parser code of our own.
        $tsquery = "websearch_to_tsquery('english', ?)";

        $query = DB::table('document_text_pages as dtp')
            ->join('document_versions as dv', 'dv.id', '=', 'dtp.version_id')
            ->join('documents as d', function ($join): void {
                $join->on('d.id', '=', 'dv.document_id')
                    ->on('d.current_version_id', '=', 'dv.id');
            })
            ->join('matters as m', 'm.id', '=', 'd.matter_id')
            ->where('d.org_id', $actor->org_id)
            ->whereIn('d.matter_id', $accessibleMatters)
            ->whereNull('d.deleted_at')
            ->whereNotIn('d.status', ['quarantined', 'trash'])
            ->whereRaw("dtp.text_tsv @@ {$tsquery}", [$content]);

        $this->applyDocumentFilters($query, $filters);

        if ($q !== '') {
            $this->applyKeywordFilter($query, $q);
        }

        // One ts_headline call renders the display snippet (truncated);
        // the second renders the FULL page so match counts are exact.
        // <mark> tags are the hit markers the preview JS understands.
        $snippetOpts = 'StartSel=<mark>, StopSel=</mark>, MaxWords=40, MinWords=12, ShortWord=3';
        $countOpts = 'StartSel=<mark>, StopSel=</mark>, MaxWords=1000000';

        return $query
            ->select([
                'd.id as document_id',
                'd.title as title',
                'd.matter_id as matter_id',
                'm.matter_number as matter_number',
                'dv.version_number as version_number',
                'dtp.page_number as page_number',
                'dtp.ocr_confidence as ocr_confidence',
            ])
            ->selectRaw("ts_headline('english', dtp.text, {$tsquery}, '{$snippetOpts}') as snippet", [$content])
            ->selectRaw("ts_headline('english', dtp.text, {$tsquery}, '{$countOpts}') as full_marked", [$content])
            ->selectRaw("ts_rank(dtp.text_tsv, {$tsquery}) as rank", [$content])
            ->orderByDesc('rank')
            ->orderBy('d.title')
            ->orderBy('dtp.page_number')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageResource(\stdClass $row, string $content): array
    {
        /** @var array<string, mixed> $r */
        $r = (array) $row;

        $matchCount = substr_count((string) ($r['full_marked'] ?? ''), '<mark>');
        $threshold = (float) config('document.ocr.low_confidence_threshold', 0.70);
        $confidence = $r['ocr_confidence'] !== null ? (float) $r['ocr_confidence'] : null;

        $matterId = (string) $r['matter_id'];
        $documentId = (string) $r['document_id'];
        $page = (int) $r['page_number'];

        return [
            'document_id' => $documentId,
            'title' => (string) $r['title'],
            'matter_id' => $matterId,
            'matter_number' => (string) $r['matter_number'],
            'version_number' => (int) $r['version_number'],
            'page_number' => $page,
            'snippet' => (string) ($r['snippet'] ?? ''),
            'match_count' => $matchCount,
            'rank' => round((float) ($r['rank'] ?? 0.0), 6),
            'ocr_confidence' => $confidence,
            'low_confidence' => $confidence !== null && $confidence < $threshold,
            // Opens the T-03 preview at the matched page (?page= hook);
            // highlight pre-fills the viewer's find box.
            'preview_url' => route('documents.preview', [$matterId, $documentId])
                .'?page='.$page.'&highlight='.urlencode($content),
            'text_url' => route('documents.text', [$matterId, $documentId]),
        ];
    }

    // ------------------------------------------------------------------
    // Keyword (q) search — document level
    // ------------------------------------------------------------------

    /**
     * @param  Builder<Matter>  $accessibleMatters
     * @param  array{q?: ?string, content?: ?string, matter_id?: ?string, kind?: ?string, folder_id?: ?string, uploader?: ?string, date_from?: ?string, date_to?: ?string, retention_flagged?: ?string}  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function keywordSearch(User $actor, object $accessibleMatters, array $filters, string $q): LengthAwarePaginator
    {
        $actor = request()->user();
        $orgId = $actor instanceof User ? $actor->org_id : null;

        $query = DB::table('documents as d')
            ->join('matters as m', 'm.id', '=', 'd.matter_id')
            ->where('d.org_id', $orgId)
            ->whereIn('d.matter_id', $accessibleMatters)
            ->whereNull('d.deleted_at')
            ->whereNotIn('d.status', ['quarantined', 'trash']);

        $this->applyDocumentFilters($query, $filters);
        $this->applyKeywordFilter($query, $q);

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        return $query
            ->select([
                'd.id as document_id',
                'd.title as title',
                'd.matter_id as matter_id',
                'm.matter_number as matter_number',
                'd.kind as kind',
                'd.created_at as created_at',
            ])
            ->selectRaw(
                'GREATEST(similarity(d.title, ?), similarity(coalesce(immutable_array_to_string(d.tags, ?), ?), ?)) as sim',
                [$q, ' ', '', $q]
            )
            ->orderByDesc('sim')
            ->orderByDesc('d.updated_at')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentResource(\stdClass $row): array
    {
        /** @var array<string, mixed> $r */
        $r = (array) $row;

        return [
            'document_id' => (string) $r['document_id'],
            'title' => (string) $r['title'],
            'matter_id' => (string) $r['matter_id'],
            'matter_number' => (string) $r['matter_number'],
            'kind' => (string) $r['kind'],
            'preview_url' => route('documents.preview', [(string) $r['matter_id'], (string) $r['document_id']]),
            'text_url' => route('documents.text', [(string) $r['matter_id'], (string) $r['document_id']]),
        ];
    }

    // ------------------------------------------------------------------
    // Shared filters
    // ------------------------------------------------------------------

    /**
     * @param  \Illuminate\Database\Query\Builder  $query  (documents aliased as d)
     * @param  array{q?: ?string, content?: ?string, matter_id?: ?string, kind?: ?string, folder_id?: ?string, uploader?: ?string, date_from?: ?string, date_to?: ?string, retention_flagged?: ?string}  $filters
     */
    private function applyDocumentFilters(object $query, array $filters): void
    {
        $matterId = $filters['matter_id'] ?? null;
        if (is_string($matterId) && Str::isUuid($matterId)) {
            $query->where('d.matter_id', $matterId);
        }

        $kind = $filters['kind'] ?? null;
        if (is_string($kind) && in_array($kind, ['uploaded', 'authored', 'generated'], true)) {
            $query->where('d.kind', $kind);
        }

        $folderId = $filters['folder_id'] ?? null;
        if (is_string($folderId) && Str::isUuid($folderId)) {
            $query->where('d.folder_id', $folderId);
        }

        $uploader = $filters['uploader'] ?? null;
        if (is_string($uploader) && Str::isUuid($uploader)) {
            $query->where('d.created_by', $uploader);
        }

        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom)) {
            $query->whereDate('d.created_at', '>=', $dateFrom);
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo)) {
            $query->whereDate('d.created_at', '<=', $dateTo);
        }

        $flagged = $filters['retention_flagged'] ?? null;
        if ($flagged === '1') {
            $query->whereNotNull('d.retention_flagged_at');
        } elseif ($flagged === '0') {
            $query->whereNull('d.retention_flagged_at');
        }
    }

    /**
     * Trigram + ILIKE keyword filter — same expression as
     * DocumentQueryService::baseQuery (DOC-14).
     *
     * @param  \Illuminate\Database\Query\Builder  $query  (documents aliased as d)
     */
    private function applyKeywordFilter(object $query, string $q): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        $query->where(function ($w) use ($q, $like): void {
            $w->whereRaw(
                "(d.title || ' ' || coalesce(immutable_array_to_string(d.tags, ' '), '')) % ?",
                [$q]
            )
                ->orWhere('d.title', 'ilike', $like)
                ->orWhere('d.description', 'ilike', $like)
                ->orWhereRaw("immutable_array_to_string(d.tags, ' ') ILIKE ?", [$like]);
        });
    }

    /**
     * @return array{current_page: int, per_page: int, total: int, last_page: int}
     */
    private function meta(int $currentPage, int $total, ?int $lastPage = null): array
    {
        return [
            'current_page' => $currentPage,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage ?? (int) ceil($total / self::PER_PAGE),
        ];
    }
}
