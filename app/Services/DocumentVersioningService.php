<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Immutable versioning core (spec 007 T-07, DOC-18/19/20/21, 007-D06).
 *
 * - Every content change is a plain INSERT into document_versions. The
 *   BEFORE UPDATE/DELETE trigger (007-D06) turns any UPDATE/DELETE into a
 *   DB error, so this service never issues either — history is append-only.
 * - version_number is gapless per document: the latest version row is
 *   locked (FOR UPDATE) inside the insert transaction (same pattern as
 *   T-04's AuthoredDocumentService), with the
 *   UNIQUE(document_id, version_number) constraint as the backstop.
 * - Blobs are content-addressed: identical bytes dedupe inside the
 *   DocumentStore (refcount++), so a rollback simply re-inserts the old
 *   bytes and lands on the same blob row.
 * - Rollback creates N+1 with the old bytes + a required reason +
 *   restored_from_version_id lineage. History is never rewritten.
 */
class DocumentVersioningService
{
    /**
     * Line cap for a single diff computation (both sides combined). Past
     * this the diff is reported unavailable ('too_large') rather than
     * burning CPU on a pathological comparison.
     */
    private const MAX_DIFF_LINES = 30000;

    /**
     * MIME types diffed as raw text when no extracted text rows exist.
     *
     * @var list<string>
     */
    private const TEXT_MIMES = ['application/json', 'application/xml'];

    public function __construct(
        private readonly DocumentStore $store,
    ) {}

    /**
     * Create version N+1 for the document with the given bytes.
     *
     * @param  array{change_note?: ?string, processing_status?: string, page_count?: ?int, original_filename?: ?string, restored_from_version_id?: ?string, mime?: ?string}  $meta
     */
    public function createVersion(
        Document $document,
        User $actor,
        string $bytes,
        array $meta = [],
        ?Matter $matter = null,
    ): DocumentVersion {
        return DB::transaction(function () use ($document, $actor, $bytes, $meta, $matter): DocumentVersion {
            // Gapless numbering: lock the latest version row so concurrent
            // creators serialize (T-04 pattern). FOR UPDATE with ORDER BY +
            // LIMIT is legal in Postgres; FOR UPDATE with an aggregate is
            // not.
            $max = DocumentVersion::query()
                ->where('document_id', $document->getKey())
                ->lockForUpdate()
                ->orderByDesc('version_number')
                ->value('version_number');

            /** @var array{mime?: string} $storeMeta */
            $storeMeta = [];
            if (! empty($meta['mime'])) {
                $storeMeta['mime'] = $meta['mime'];
            }

            // Dedupe lives inside the store: identical bytes return the
            // existing blob row with refcount incremented.
            $blob = $this->store->put($bytes, $storeMeta);

            $version = DocumentVersion::create([
                'document_id' => $document->getKey(),
                'version_number' => ((int) $max) + 1,
                'blob_id' => $blob->getKey(),
                'change_note' => $meta['change_note'] ?? null,
                'restored_from_version_id' => $meta['restored_from_version_id'] ?? null,
                'processing_status' => $meta['processing_status'] ?? 'ready',
                'page_count' => $meta['page_count'] ?? null,
                'original_filename' => $meta['original_filename'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            // documents is mutable — only document_versions is
            // trigger-guarded (007-D06).
            $document->update(['current_version_id' => $version->getKey()]);

            AuditLogger::log('document.version.created', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $document->getKey(),
                'version_id' => (string) $version->getKey(),
                'version_number' => $version->version_number,
                'restored_from_version_id' => $meta['restored_from_version_id'] ?? null,
                'size' => strlen($bytes),
            ], $matter ?? $document->matter()->first());

            return $version->fresh() ?? $version;
        });
    }

    /**
     * Rollback: create version N+1 holding the target version's exact
     * bytes. The reason is required (DOC-20) and the lineage is recorded
     * via restored_from_version_id. History is never rewritten.
     *
     * Restoring the already-current version is a no-op
     * ('already_current') — no new row.
     *
     * @return array{status: 'restored'|'already_current', version: DocumentVersion}
     *
     * @throws DocumentUploadException reason_required (422),
     *                                 version_not_in_document (404), quarantined (403),
     *                                 blob_unavailable (422)
     */
    public function restoreVersion(
        Document $document,
        DocumentVersion $target,
        User $actor,
        string $reason,
        ?Matter $matter = null,
    ): array {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DocumentUploadException('reason_required', 422, 'Restoring a version requires a reason.');
        }

        if ((string) $target->document_id !== (string) $document->getKey()) {
            throw new DocumentUploadException('version_not_in_document', 404, 'Version does not belong to this document.');
        }

        $current = $document->currentVersion()->first();

        if ($current !== null && (string) $current->getKey() === (string) $target->getKey()) {
            return ['status' => 'already_current', 'version' => $current];
        }

        $blob = $target->blob()->first();

        if ($blob === null || $blob->quarantined) {
            throw new DocumentUploadException('quarantined', 403, 'A quarantined version cannot be restored.');
        }

        try {
            $bytes = (string) $this->store->get($blob);
        } catch (DocumentStoreException) {
            throw new DocumentUploadException('blob_unavailable', 422, 'The version bytes are unavailable.');
        }

        $restored = $this->createVersion($document, $actor, $bytes, [
            'change_note' => $reason,
            'restored_from_version_id' => (string) $target->getKey(),
            'processing_status' => 'ready',
            'page_count' => $target->page_count,
            'original_filename' => $target->original_filename,
            'mime' => $blob->mime_sniffed,
        ], $matter);

        AuditLogger::log('document.version.restored', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'document_id' => (string) $document->getKey(),
            'version_id' => (string) $restored->getKey(),
            'version_number' => $restored->version_number,
            'restored_from_version_id' => (string) $target->getKey(),
            'restored_from_version_number' => $target->version_number,
            'reason' => $reason,
        ], $matter ?? $document->matter()->first());

        return ['status' => 'restored', 'version' => $restored];
    }

    /**
     * Structured line diff between two versions of the same document.
     *
     * Text sources, in order: document_text_pages rows (T-06
     * extraction/OCR output) → raw blob bytes for text-ish MIME types.
     * Image-only versions without extracted text yield available=false
     * ('no_text'); OCR itself is T-06's lane — this method never builds it.
     *
     * @return array{available: bool, reason: ?string, from: int, to: int, pages: list<array{page: int, hunks: list<list<array{type: string, old: ?int, new: ?int, text: string}>>}>, stats: array{added: int, removed: int}}
     */
    public function diff(DocumentVersion $a, DocumentVersion $b): array
    {
        $result = [
            'available' => false,
            'reason' => null,
            'from' => (int) $a->version_number,
            'to' => (int) $b->version_number,
            'pages' => [],
            'stats' => ['added' => 0, 'removed' => 0],
        ];

        if ((string) $a->document_id !== (string) $b->document_id) {
            $result['reason'] = 'cross_document';

            return $result;
        }

        $textA = $this->versionText($a);
        $textB = $this->versionText($b);

        if ($textA === null || $textB === null) {
            $result['reason'] = $this->unavailableReason($textA === null ? $a : $b);

            return $result;
        }

        $linesA = $this->toLinePages($textA);
        $linesB = $this->toLinePages($textB);

        $total = 0;
        foreach ([$linesA, $linesB] as $pages) {
            foreach ($pages as $lines) {
                $total += count($lines);
            }
        }

        if ($total > self::MAX_DIFF_LINES) {
            $result['reason'] = 'too_large';

            return $result;
        }

        $pageNumbers = array_unique(array_merge(array_keys($linesA), array_keys($linesB)));
        sort($pageNumbers);

        $pages = [];
        $added = 0;
        $removed = 0;

        foreach ($pageNumbers as $pageNumber) {
            $ops = $this->diffLines($linesA[$pageNumber] ?? [], $linesB[$pageNumber] ?? []);

            foreach ($ops as $op) {
                if ($op['type'] === 'add') {
                    $added++;
                } elseif ($op['type'] === 'del') {
                    $removed++;
                }
            }

            $hunks = $this->hunks($ops);

            if ($hunks !== []) {
                $pages[] = ['page' => $pageNumber, 'hunks' => $hunks];
            }
        }

        $result['available'] = true;
        $result['pages'] = $pages;
        $result['stats'] = ['added' => $added, 'removed' => $removed];

        return $result;
    }

    /**
     * Version text, keyed by page number: extracted text rows first
     * (T-06), then raw bytes for text-ish MIME types.
     *
     * @return array<int, string>|null
     */
    private function versionText(DocumentVersion $version): ?array
    {
        $rows = DB::table('document_text_pages')
            ->where('version_id', $version->getKey())
            ->orderBy('page_number')
            ->pluck('text', 'page_number');

        if ($rows->isNotEmpty()) {
            $pages = [];
            foreach ($rows as $pageNumber => $text) {
                $pages[(int) $pageNumber] = (string) $text;
            }

            return $pages;
        }

        $blob = $version->blob()->first();

        if ($blob === null) {
            return null;
        }

        $mime = (string) $blob->mime_sniffed;

        try {
            $bytes = (string) $this->store->get($blob);
        } catch (DocumentStoreException) {
            return null;
        }

        if ($mime === 'text/html') {
            // Authored documents store structured HTML (T-04); diff the
            // readable text, not the markup.
            return [1 => html_entity_decode(strip_tags($bytes), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }

        if (str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_MIMES, true)) {
            return [1 => $bytes];
        }

        return null;
    }

    private function unavailableReason(DocumentVersion $version): string
    {
        $blob = $version->blob()->first();
        $mime = $blob !== null ? (string) $blob->mime_sniffed : '';

        // Image-only (or PDF with no extracted text yet): OCR is T-06's
        // lane — the view renders the clean "diff unavailable" state.
        if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') {
            return 'no_text';
        }

        return 'binary';
    }

    /**
     * @param  array<int, string>  $pages
     * @return array<int, list<string>>
     */
    private function toLinePages(array $pages): array
    {
        $out = [];

        foreach ($pages as $pageNumber => $text) {
            /** @var list<string> $lines */
            $lines = preg_split('/\R/', $text) ?: [];
            $out[(int) $pageNumber] = $lines;
        }

        return $out;
    }

    /**
     * Myers O(ND) line diff. Ops carry 1-based old/new line numbers.
     *
     * @param  list<string>  $a  old lines
     * @param  list<string>  $b  new lines
     * @return list<array{type: 'equal'|'add'|'del', old: ?int, new: ?int, text: string}>
     */
    private function diffLines(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }

        $max = $n + $m;
        $offset = $max;
        $v = array_fill(0, 2 * $max + 1, 0);
        /** @var array<int, array<int, int>> $trace */
        $trace = [];
        $done = false;

        for ($d = 0; $d <= $max && ! $done; $d++) {
            $snapshot = [];
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && $v[$k - 1 + $offset] < $v[$k + 1 + $offset])) {
                    $x = $v[$k + 1 + $offset]; // down: insertion (line added in b)
                } else {
                    $x = $v[$k - 1 + $offset] + 1; // right: deletion (line removed from a)
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k + $offset] = $x;
                $snapshot[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $done = true;

                    break;
                }
            }
            $trace[$d] = $snapshot;
        }

        $ops = [];
        $x = $n;
        $y = $m;

        for ($d = count($trace) - 1; $d >= 1; $d--) {
            $prev = $trace[$d - 1];
            $k = $x - $y;
            $down = $k === -$d || ($k !== $d && ($prev[$k - 1] ?? PHP_INT_MIN) < ($prev[$k + 1] ?? PHP_INT_MIN));
            $prevK = $down ? $k + 1 : $k - 1;
            $prevX = $prev[$prevK] ?? 0;

            // Snake start: the point just after the single edit step.
            $snakeX = $down ? $prevX : $prevX + 1;

            while ($x > $snakeX) {
                $x--;
                $y--;
                $ops[] = ['type' => 'equal', 'old' => $x + 1, 'new' => $y + 1, 'text' => $a[$x]];
            }

            if ($down) {
                $y--;
                $ops[] = ['type' => 'add', 'old' => null, 'new' => $y + 1, 'text' => $b[$y]];
            } else {
                $x--;
                $ops[] = ['type' => 'del', 'old' => $x + 1, 'new' => null, 'text' => $a[$x]];
            }
        }

        // d = 0: the initial snake from (0, 0).
        while ($x > 0 && $y > 0) {
            $x--;
            $y--;
            $ops[] = ['type' => 'equal', 'old' => $x + 1, 'new' => $y + 1, 'text' => $a[$x]];
        }

        return array_reverse($ops);
    }

    /**
     * Group ops into hunks with 3 lines of context. Consecutive del+add
     * runs stay adjacent inside a hunk — the renderer presents those as
     * "changed" blocks.
     *
     * @param  list<array{type: string, old: ?int, new: ?int, text: string}>  $ops
     * @return list<list<array{type: string, old: ?int, new: ?int, text: string}>>
     */
    private function hunks(array $ops, int $context = 3): array
    {
        $count = count($ops);

        if ($count === 0) {
            return [];
        }

        $changeIdx = [];
        foreach ($ops as $i => $op) {
            if ($op['type'] !== 'equal') {
                $changeIdx[] = $i;
            }
        }

        if ($changeIdx === []) {
            return [];
        }

        $windows = [];
        $start = max(0, $changeIdx[0] - $context);
        $end = min($count - 1, $changeIdx[0] + $context);

        foreach (array_slice($changeIdx, 1) as $i) {
            $windowStart = max(0, $i - $context);
            $windowEnd = min($count - 1, $i + $context);

            if ($windowStart <= $end + 1) {
                $end = max($end, $windowEnd);
            } else {
                $windows[] = [$start, $end];
                $start = $windowStart;
                $end = $windowEnd;
            }
        }
        $windows[] = [$start, $end];

        $hunks = [];
        foreach ($windows as [$windowStart, $windowEnd]) {
            $hunks[] = array_slice($ops, $windowStart, $windowEnd - $windowStart + 1);
        }

        return $hunks;
    }
}
