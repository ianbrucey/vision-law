<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Faceted document list (007 T-05, C-09 list half).
 *
 * Filters (all optional, AND-combined):
 * - q: keyword over title/tags/description — trigram similarity on the
 *   title+tags expression (uses the documents_title_tags_trgm GIN index,
 *   DOC-14), ILIKE fallback on description.
 * - kind: uploaded|authored|generated
 * - folder_id: the folder AND its descendants (filing hierarchy)
 * - uploader: created_by user id
 * - date_from / date_to: created_at range (Y-m-d)
 * - retention_flagged: '1' flagged, '0' not flagged
 * - min_versions: documents with at least N versions
 *
 * 50/page, permission-scoped by the caller (routes carry
 * matter.access:view before any data access — ungranted users never reach
 * here). Facet counts are computed over the matter's live (non-trash)
 * documents, ignoring the active filters.
 */
final class DocumentQueryService
{
    public const PER_PAGE = 50;

    /**
     * Filter keys accepted from the request / saved views.
     *
     * @var list<string>
     */
    public const FILTER_KEYS = [
        'q',
        'kind',
        'folder_id',
        'uploader',
        'date_from',
        'date_to',
        'retention_flagged',
        'min_versions',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Document>
     */
    public static function paginate(Matter $matter, array $filters): LengthAwarePaginator
    {
        return self::baseQuery($matter, $filters)
            ->orderByDesc('documents.updated_at')
            ->orderBy('documents.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Facet counts for the filter UI.
     *
     * @return array{kinds: array<string, int>, statuses: array<string, int>, folders: array<int, array{id: string, name: string, count: int}>, uploaders: array<int, array{id: string, name: string, count: int}>, retention_flagged: int}
     */
    public static function facets(Matter $matter): array
    {
        $live = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', '!=', 'trash');

        $kinds = (clone $live)->selectRaw('kind, count(*) as aggregate')->groupBy('kind')->pluck('aggregate', 'kind');
        $statuses = (clone $live)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        /** @var array<string, int> $folderCounts */
        $folderCounts = (clone $live)
            ->whereNotNull('folder_id')
            ->selectRaw('folder_id, count(*) as aggregate')
            ->groupBy('folder_id')
            ->pluck('aggregate', 'folder_id')
            ->map(fn (mixed $c): int => (int) $c)
            ->all();

        $folders = [];
        foreach (DocumentFolderService::tree($matter) as $root) {
            self::collectFolderFacets($root, $folderCounts, $folders);
        }

        $uploaderCounts = (clone $live)
            ->selectRaw('created_by, count(*) as aggregate')
            ->groupBy('created_by')
            ->pluck('aggregate', 'created_by');

        $uploaders = [];
        if ($uploaderCounts->isNotEmpty()) {
            $names = User::query()
                ->whereIn('id', $uploaderCounts->keys())
                ->pluck('name', 'id');
            foreach ($uploaderCounts as $userId => $count) {
                $uploaders[] = [
                    'id' => (string) $userId,
                    'name' => (string) ($names[$userId] ?? 'Unknown'),
                    'count' => (int) $count,
                ];
            }
            usort($uploaders, fn ($a, $b): int => $b['count'] <=> $a['count']);
        }

        return [
            'kinds' => $kinds->map(fn ($c): int => (int) $c)->all(),
            'statuses' => $statuses->map(fn ($c): int => (int) $c)->all(),
            'folders' => $folders,
            'uploaders' => $uploaders,
            'retention_flagged' => (clone $live)->whereNotNull('retention_flagged_at')->count(),
        ];
    }

    /**
     * The document's tags via the native text[] cast (007 T-02).
     *
     * @return list<string>
     */
    public static function tagsOf(Document $document): array
    {
        /** @var list<string> $tags */
        $tags = $document->tags ?? [];

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Document>
     */
    private static function baseQuery(Matter $matter, array $filters): Builder
    {
        $query = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', '!=', 'trash')
            ->with(['folder:id,name,parent_id', 'currentVersion:id,document_id,version_number,page_count,created_at', 'creator:id,name'])
            ->withCount('versions');

        $kind = $filters['kind'] ?? null;
        if (is_string($kind) && in_array($kind, Document::KINDS, true)) {
            $query->where('kind', $kind);
        }

        $folderId = $filters['folder_id'] ?? null;
        if (is_string($folderId) && Str::isUuid($folderId)) {
            $ids = DocumentFolderService::subtreeIds($matter, $folderId);
            $query->whereIn('folder_id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids);
        }

        $uploader = $filters['uploader'] ?? null;
        if (is_string($uploader) && Str::isUuid($uploader)) {
            $query->where('created_by', $uploader);
        }

        $dateFrom = $filters['date_from'] ?? null;
        if (is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = $filters['date_to'] ?? null;
        if (is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $flagged = $filters['retention_flagged'] ?? null;
        if ($flagged === '1') {
            $query->whereNotNull('retention_flagged_at');
        } elseif ($flagged === '0') {
            $query->whereNull('retention_flagged_at');
        }

        $minVersions = $filters['min_versions'] ?? null;
        if ((is_string($minVersions) || is_int($minVersions)) && (int) $minVersions > 0) {
            // Correlated count subquery — HAVING without GROUP BY is
            // invalid in Postgres.
            $query->has('versions', '>=', (int) $minVersions);
        }

        $q = $filters['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $q = trim($q);
            // 006's MAT-15 pattern: trigram similarity (hits the
            // documents_title_tags_trgm GIN index, DOC-14) OR case-
            // insensitive substring fallback over title, tags, description.
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(function (Builder $w) use ($q, $like): void {
                $w->whereRaw(
                    "(documents.title || ' ' || coalesce(immutable_array_to_string(documents.tags, ' '), '')) % ?",
                    [$q]
                )
                    ->orWhere('documents.title', 'ilike', $like)
                    ->orWhere('documents.description', 'ilike', $like)
                    ->orWhereRaw("immutable_array_to_string(documents.tags, ' ') ILIKE ?", [$like]);
            });
        }

        return $query;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, array{id: string, name: string, count: int}>  $out
     */
    private static function collectFolderFacets(DocumentFolder $folder, array $counts, array &$out): void
    {
        $out[] = [
            'id' => (string) $folder->getKey(),
            'name' => (string) $folder->name,
            'count' => (int) ($counts[$folder->getKey()] ?? 0),
        ];

        foreach ($folder->children as $child) {
            self::collectFolderFacets($child, $counts, $out);
        }
    }
}
