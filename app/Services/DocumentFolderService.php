<?php

namespace App\Services;

use App\Exceptions\DocumentFilingException;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Per-matter folder tree (007 T-05, DOC-11).
 *
 * Create/rename/move, delete empty-only, and idempotent seeding of the
 * default folder set per matter type. Every mutation writes its
 * contract audit event in the same transaction (006-D04 pattern).
 * Authorization is the caller's job: routes carry matter.access:<level>;
 * the service asserts matter ownership of every row it touches so a
 * folder can never leak across matters.
 */
final class DocumentFolderService
{
    /**
     * Default folder set per matter type (DOC-11). '*' is the fallback.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_FOLDERS = [
        'litigation' => ['Pleadings', 'Discovery', 'Correspondence'],
        'transactional' => ['Contracts', 'Correspondence', 'Due Diligence'],
        '*' => ['Correspondence', 'Contracts'],
    ];

    /**
     * Idempotent seeding of the default folders for a matter.
     *
     * Called lazily on first document-list/folder access (007-D08): matter
     * creation is 006-owned, so there is no hook there — seeding on read
     * keeps the invariant "every matter has its default folders" without
     * touching MatterService.
     */
    public static function ensureDefaults(Matter $matter): void
    {
        $names = self::DEFAULT_FOLDERS[$matter->matter_type]
            ?? self::DEFAULT_FOLDERS['*'];

        foreach ($names as $name) {
            DocumentFolder::query()->firstOrCreate(
                [
                    'org_id' => $matter->org_id,
                    'matter_id' => $matter->getKey(),
                    'name' => $name,
                    'parent_id' => null,
                ],
                []
            );
        }
    }

    /**
     * The folder tree for a matter: roots with nested children and
     * per-folder live (non-trashed) document counts.
     *
     * @return EloquentCollection<int, DocumentFolder>
     */
    public static function tree(Matter $matter): EloquentCollection
    {
        /** @var EloquentCollection<int, DocumentFolder> $folders */
        $folders = DocumentFolder::query()
            ->where('matter_id', $matter->getKey())
            ->orderBy('name')
            ->get();

        $counts = Document::query()
            ->where('matter_id', $matter->getKey())
            ->where('status', '!=', 'trash')
            ->whereNotNull('folder_id')
            ->selectRaw('folder_id, count(*) as aggregate')
            ->groupBy('folder_id')
            ->pluck('aggregate', 'folder_id');

        $byParent = [];
        foreach ($folders as $folder) {
            $folder->setAttribute('document_count', (int) ($counts[$folder->getKey()] ?? 0));
            $byParent[$folder->parent_id ?? 'root'][] = $folder;
        }

        $nest = function (?string $parentId) use (&$nest, $byParent): EloquentCollection {
            $children = new EloquentCollection($byParent[$parentId ?? 'root'] ?? []);
            foreach ($children as $child) {
                $child->setRelation('children', $nest((string) $child->getKey()));
            }

            return $children;
        };

        return $nest(null);
    }

    /**
     * All folder ids in the subtree rooted at $folderId (inclusive), scoped
     * to the matter. Used by the list's folder facet.
     *
     * @return list<string>
     */
    public static function subtreeIds(Matter $matter, string $folderId): array
    {
        $folders = DocumentFolder::query()
            ->where('matter_id', $matter->getKey())
            ->get(['id', 'parent_id']);

        $children = [];
        foreach ($folders as $folder) {
            $children[$folder->parent_id ?? 'root'][] = (string) $folder->getKey();
        }

        $ids = [];
        $walk = function (string $id) use (&$walk, &$ids, $children): void {
            $ids[] = $id;
            foreach ($children[$id] ?? [] as $childId) {
                $walk($childId);
            }
        };

        // Unknown or foreign folder → empty set (matches nothing, leaks nothing).
        if (! $folders->contains('id', $folderId)) {
            return [];
        }

        $walk($folderId);

        return $ids;
    }

    public static function create(Matter $matter, User $actor, string $name, ?string $parentId = null): DocumentFolder
    {
        $parent = $parentId !== null ? self::resolveInMatter($matter, $parentId) : null;

        return DB::transaction(function () use ($matter, $actor, $name, $parent): DocumentFolder {
            $folder = DocumentFolder::query()->create([
                'org_id' => $matter->org_id,
                'matter_id' => $matter->getKey(),
                'parent_id' => $parent?->getKey(),
                'name' => $name,
            ]);

            AuditLogger::log('folder.created', $actor, [
                'folder_id' => (string) $folder->getKey(),
                'matter_id' => (string) $matter->getKey(),
                'parent_id' => $parent !== null ? (string) $parent->getKey() : null,
            ], $matter);

            return $folder;
        });
    }

    /**
     * Rename and/or re-parent a folder. Re-parenting is cycle-guarded: a
     * folder can never move under itself or one of its descendants.
     *
     * @throws DocumentFilingException 422 folder_cycle
     */
    public static function update(Matter $matter, DocumentFolder $folder, User $actor, ?string $name = null, ?string $parentId = null, bool $detachParent = false): DocumentFolder
    {
        $parent = null;
        if ($detachParent) {
            $parent = null;
        } elseif ($parentId !== null) {
            $parent = self::resolveInMatter($matter, $parentId);

            if ((string) $parent->getKey() === (string) $folder->getKey()
                || in_array((string) $parent->getKey(), self::subtreeIds($matter, (string) $folder->getKey()), true)
            ) {
                throw new DocumentFilingException(422, 'folder_cycle', [
                    'folder_id' => (string) $folder->getKey(),
                ]);
            }
        }

        $renamed = $name !== null && $name !== $folder->name;
        // A move is only real when the parent actually changes; re-setting
        // the same parent is a no-op and writes no audit row.
        $moved = ($detachParent && $folder->parent_id !== null)
            || ($parentId !== null && (string) ($folder->parent_id ?? '') !== $parentId);

        return DB::transaction(function () use ($matter, $folder, $actor, $name, $parent, $renamed, $moved, $detachParent, $parentId): DocumentFolder {
            if ($renamed) {
                $folder->name = $name;
            }
            if ($moved) {
                $folder->parent_id = $detachParent ? null : $parent?->getKey();
            }
            $folder->save();

            if ($renamed) {
                AuditLogger::log('folder.renamed', $actor, [
                    'folder_id' => (string) $folder->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                ], $matter);
            }
            if ($moved) {
                AuditLogger::log('folder.moved', $actor, [
                    'folder_id' => (string) $folder->getKey(),
                    'matter_id' => (string) $matter->getKey(),
                    'parent_id' => $detachParent || $parentId === null ? null : (string) $parent?->getKey(),
                ], $matter);
            }

            return $folder->refresh();
        });
    }

    /**
     * Delete empty-only (DOC-11): a folder holding documents or
     * sub-folders cannot be deleted.
     *
     * @throws DocumentFilingException 422 folder_not_empty
     */
    public static function delete(Matter $matter, DocumentFolder $folder, User $actor): void
    {
        $docCount = Document::query()
            ->where('folder_id', $folder->getKey())
            ->where('status', '!=', 'trash')
            ->count();

        $childCount = DocumentFolder::query()
            ->where('parent_id', $folder->getKey())
            ->count();

        if ($docCount > 0 || $childCount > 0) {
            throw new DocumentFilingException(422, 'folder_not_empty', [
                'folder_id' => (string) $folder->getKey(),
                'document_count' => $docCount,
                'child_count' => $childCount,
            ]);
        }

        DB::transaction(function () use ($matter, $folder, $actor): void {
            $folderId = (string) $folder->getKey();
            $folder->delete();

            AuditLogger::log('folder.deleted', $actor, [
                'folder_id' => $folderId,
                'matter_id' => (string) $matter->getKey(),
            ], $matter);
        });
    }

    /**
     * Resolve a folder id to a row owned by this matter, or 404 (leak-safe:
     * a folder from another matter reads as missing).
     *
     * @throws ModelNotFoundException
     */
    public static function resolveInMatter(Matter $matter, string $raw): DocumentFolder
    {
        return DocumentFolder::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->firstOrFail();
    }
}
