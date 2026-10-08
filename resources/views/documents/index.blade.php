{{--
    documents/index.blade.php — spec 007 T-05 (05-ui.md §01, mockup §01).

    Phone-first document list: search, filter chips, and cards. On desktop
    the cards become a table and the filters gain a sidebar. Tables scroll
    inside their cards on phones — the page itself never scrolls sideways.

    Expects: $matter, $documents (LengthAwarePaginator<Document>),
    $facets (kinds/statuses/folders/uploaders/retention_flagged),
    $folders (nested tree), $savedViews, $filters, $activeViewId,
    $canEdit, $canManage.
--}}
<x-layouts.app title="Documents · {{ $matter->matter_number }} · Vision Law">
    <x-ui.page-header
        title="Documents"
        :subtitle="$documents->total() . ' ' . \Illuminate\Support\Str::plural('document', $documents->total()) . ' · ' . $matter->matter_number"
    >
        <x-slot name="crumbs">
            <a href="{{ route('matters.show', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
        </x-slot>
        <x-slot name="actions">
            @if ($canManage)
                <x-ui.button variant="secondary" :href="route('documents.trash', $matter)">Trash</x-ui.button>
            @endif
            @if ($canEdit && \Illuminate\Support\Facades\Route::has('documents.upload'))
                <x-ui.button variant="primary" :href="route('documents.upload', $matter)">Add documents</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    @php
        // Flat id => indented-name list for selects (folders + bulk move).
        $flatFolders = [];
        $flatten = function ($nodes, $prefix = '') use (&$flatten, &$flatFolders) {
            foreach ($nodes as $node) {
                $flatFolders[(string) $node->getKey()] = $prefix . $node->name;
                $flatten($node->children, $prefix . '— ');
            }
        };
        $flatten($folders);

        $kindOptions = ['' => 'All types'] + array_combine(
            array_keys($facets['kinds']),
            array_map(fn ($k) => ucfirst($k) . ' (' . $facets['kinds'][$k] . ')', array_keys($facets['kinds']))
        );

        $filterQuery = array_filter([
            'q' => $filters['q'] ?? null,
            'kind' => $filters['kind'] ?? null,
            'folder_id' => $filters['folder_id'] ?? null,
            'uploader' => $filters['uploader'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'retention_flagged' => $filters['retention_flagged'] ?? null,
            'min_versions' => $filters['min_versions'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $hasFilters = $filterQuery !== [];
    @endphp

    {{-- Folders --}}
    <x-ui.card class="mb-4">
        <h2 class="font-serif text-[19px] text-vl-ink mb-2">Folders</h2>
        @if ($folders->isEmpty())
            <p class="text-vl-mut text-[14.5px]">No folders yet.</p>
        @else
            <ul>
                @foreach ($folders as $root)
                    @include('documents.partials.folder-node', [
                        'folder' => $root,
                        'matter' => $matter,
                        'depth' => 0,
                        'canEdit' => $canEdit,
                        'allFolders' => $flatFolders,
                    ])
                @endforeach
            </ul>
        @endif
        @if ($canEdit)
            <form method="POST" action="{{ route('folders.store', $matter) }}" class="mt-3 flex flex-col min-[821px]:flex-row gap-2 min-[821px]:items-end">
                @csrf
                <x-ui.field name="name" label="New folder" placeholder="Folder name" class="grow mb-0!" />
                <x-ui.button type="submit" variant="secondary">Create folder</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('documents.index', $matter) }}" role="search" class="mb-4">
        <div class="flex flex-col gap-3 min-[821px]:flex-row min-[821px]:items-end">
            <x-ui.field
                name="q"
                label="Search documents"
                type="search"
                :value="$filters['q'] ?? ''"
                placeholder="Search title, tags, or description…"
                class="grow max-w-none! mb-0!"
            />
            <x-ui.select
                name="kind"
                label="Type"
                :options="$kindOptions"
                :value="$filters['kind'] ?? ''"
                class="mb-0! min-[821px]:w-44"
            />
            <x-ui.select
                name="folder_id"
                label="Folder"
                :options="['' => 'All folders'] + $flatFolders"
                :value="$filters['folder_id'] ?? ''"
                class="mb-0! min-[821px]:w-52"
            />
            <x-ui.button variant="secondary" type="submit">Search</x-ui.button>
        </div>
        <div class="mt-3 flex flex-col gap-3 min-[821px]:flex-row min-[821px]:items-end">
            <x-ui.select
                name="uploader"
                label="Uploader"
                :options="['' => 'Anyone'] + collect($facets['uploaders'])->mapWithKeys(fn ($u) => [$u['id'] => $u['name'] . ' (' . $u['count'] . ')'])->all()"
                :value="$filters['uploader'] ?? ''"
                class="mb-0! min-[821px]:w-52"
            />
            <x-ui.field name="date_from" label="From" type="date" :value="$filters['date_from'] ?? ''" class="mb-0! min-[821px]:w-44" />
            <x-ui.field name="date_to" label="To" type="date" :value="$filters['date_to'] ?? ''" class="mb-0! min-[821px]:w-44" />
            <x-ui.select
                name="retention_flagged"
                label="Retention"
                :options="['' => 'Any', '1' => 'Flagged', '0' => 'Not flagged']"
                :value="$filters['retention_flagged'] ?? ''"
                class="mb-0! min-[821px]:w-40"
            />
            <x-ui.field name="min_versions" label="Min versions" type="number" min="1" :value="$filters['min_versions'] ?? ''" class="mb-0! min-[821px]:w-32" />
            @if ($hasFilters)
                <x-ui.button variant="ghost" :href="route('documents.index', $matter)">Clear</x-ui.button>
            @endif
        </div>
        <div class="mt-3 flex flex-col gap-3 min-[821px]:flex-row min-[821px]:items-end">
            <x-ui.select
                name="view_id"
                label="Saved view"
                :options="['' => 'Choose a saved view…'] + $savedViews->mapWithKeys(fn ($v) => [(string) $v->getKey() => $v->name])->all()"
                :value="$activeViewId ?? ''"
                class="mb-0! min-[821px]:w-52"
            />
            <x-ui.button variant="secondary" type="submit">Apply view</x-ui.button>
        </div>
    </form>

    {{-- Save current filters as a view --}}
    @if ($canEdit || $canManage)
        <form method="POST" action="{{ route('document-views.store', $matter) }}" class="mb-6 flex flex-col min-[821px]:flex-row gap-2 min-[821px]:items-end max-w-[520px]">
            @csrf
            @foreach ($filterQuery as $key => $value)
                <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
            @endforeach
            <x-ui.field name="name" label="Save these filters as" placeholder="View name" class="grow mb-0!" :required="$hasFilters" />
            <x-ui.button type="submit" variant="secondary" :disabled="!$hasFilters">Save view</x-ui.button>
        </form>
    @endif

    {{-- Results --}}
    @if ($documents->total() === 0)
        <x-ui.empty
            title="{{ $hasFilters ? 'No documents match these filters' : 'No documents yet' }}"
            :actionHref="$hasFilters ? route('documents.index', $matter) : null"
            :actionLabel="$hasFilters ? 'Clear filters' : null"
        >
            {{ $hasFilters ? 'Try a different search term or widen the filters.' : 'Upload your first document to begin filing.' }}
        </x-ui.empty>
    @else
        <p class="text-[13px] text-vl-mut mb-2" id="bulk-hint">Select documents to move, tag, or download as a ZIP.</p>

        {{-- Phone cards (mockup §01: cards on phone, table on desktop) --}}
        <div class="md:hidden">
            @foreach ($documents as $doc)
                <x-ui.card class="mb-2.5">
                    <div class="flex gap-2 items-start">
                        <x-ui.checkbox
                            name="document_ids[]"
                            :id="'bulk-select-'.$doc->getKey()"
                            :value="$doc->getKey()"
                            label="Select"
                            form="bulk-actions"
                            class="shrink-0 mb-0!"
                        />
                        <div class="min-w-0 grow">
                            <p class="font-bold text-[16px] text-vl-ink leading-snug break-words">{{ $doc->title }}</p>
                            <p class="text-[13px] text-vl-mut mt-0.5 break-words">
                                v{{ $doc->currentVersion?->version_number ?? '—' }}
                                · {{ $doc->currentVersion?->page_count !== null ? $doc->currentVersion->page_count . ' pages · ' : '' }}{{ $doc->creator?->name ?? 'Unknown' }}
                                · {{ fmtDate($doc->created_at) }}
                            </p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5 items-center">
                                <x-ui.status type="document" :value="$doc->status" />
                                <x-ui.chip>{{ ucfirst($doc->kind) }}</x-ui.chip>
                                @if ($doc->folder)
                                    <x-ui.chip>{{ $doc->folder->name }}</x-ui.chip>
                                @endif
                                @if ($doc->retention_flagged_at)
                                    <x-ui.chip tone="warn">Retention flagged</x-ui.chip>
                                @endif
                            </div>
                            @if (count(\App\Services\DocumentQueryService::tagsOf($doc)) > 0)
                                <p class="text-[12.5px] text-vl-mut mt-1 break-words">
                                    {{ implode(', ', \App\Services\DocumentQueryService::tagsOf($doc)) }}
                                </p>
                            @endif
                            <div class="mt-2.5 flex flex-wrap gap-2">
                                @if (\Illuminate\Support\Facades\Route::has('documents.preview'))
                                    <x-ui.button :href="route('documents.preview', ['matter' => $matter->getKey(), 'document' => $doc->getKey()])">Preview</x-ui.button>
                                @endif
                                @if (\Illuminate\Support\Facades\Route::has('documents.versions.index'))
                                    <x-ui.button :href="route('documents.versions.index', ['matter' => $matter->getKey(), 'document' => $doc->getKey()])">Versions</x-ui.button>
                                @endif
                                @if ($canManage)
                                    <form method="POST" action="{{ route('documents.destroy', ['matter' => $matter->getKey(), 'document' => $doc->getKey()]) }}"
                                          onsubmit="return confirm('Move this document to trash?')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger">Trash</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table (scrolls inside its card on narrow screens) --}}
        <div class="hidden md:block">
            <x-ui.card padded="false" class="mb-2">
                <x-ui.table>
                    <x-slot name="head">
                        <th scope="col"><span class="sr-only">Select</span></th>
                        <th scope="col">Title</th>
                        <th scope="col">Folder</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Versions</th>
                        <th scope="col">Uploader</th>
                        <th scope="col">Updated</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </x-slot>
                    <x-slot name="body">
                        @foreach ($documents as $doc)
                            <tr>
                                <td>
                                    <x-ui.checkbox
                                        name="document_ids[]"
                                        :id="'bulk-select-'.$doc->getKey()"
                                        :value="$doc->getKey()"
                                        label="Select"
                                        form="bulk-actions"
                                        class="mb-0!"
                                    />
                                </td>
                                <td>
                                    <strong class="break-words">{{ $doc->title }}</strong>
                                    @if ($doc->retention_flagged_at)
                                        <span class="block mt-1"><x-ui.chip tone="warn">Retention flagged</x-ui.chip></span>
                                    @endif
                                </td>
                                <td>{{ $doc->folder?->name ?? '—' }}</td>
                                <td>{{ ucfirst($doc->kind) }}</td>
                                <td><x-ui.status type="document" :value="$doc->status" /></td>
                                <td class="whitespace-nowrap">v{{ $doc->currentVersion?->version_number ?? '—' }} ({{ $doc->versions_count }})</td>
                                <td>{{ $doc->creator?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap text-vl-mut">{{ fmtDate($doc->updated_at) }}</td>
                                <td class="whitespace-nowrap">
                                    @if (\Illuminate\Support\Facades\Route::has('documents.preview'))
                                        <x-ui.button size="sm" :href="route('documents.preview', ['matter' => $matter->getKey(), 'document' => $doc->getKey()])">Preview</x-ui.button>
                                    @endif
                                    @if (\Illuminate\Support\Facades\Route::has('documents.versions.index'))
                                        <x-ui.button size="sm" :href="route('documents.versions.index', ['matter' => $matter->getKey(), 'document' => $doc->getKey()])">Versions</x-ui.button>
                                    @endif
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('documents.destroy', ['matter' => $matter->getKey(), 'document' => $doc->getKey()]) }}"
                                              class="inline" onsubmit="return confirm('Move this document to trash?')">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button size="sm" variant="danger" type="submit">Trash</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot>
                </x-ui.table>
            </x-ui.card>
        </div>

        {{-- Bulk actions (checkboxes above join this form via form="bulk-actions") --}}
        <form method="POST" action="{{ route('documents.bulk.move', $matter) }}" id="bulk-actions">
            @csrf
            <x-ui.card class="mt-3">
                <h2 class="font-serif text-[17px] text-vl-ink mb-2">Bulk actions</h2>
                <div class="flex flex-col gap-3 min-[821px]:flex-row min-[821px]:items-end">
                    <x-ui.select
                        name="folder_id"
                        label="Move to folder"
                        :options="$flatFolders"
                        placeholder="Choose folder…"
                        class="mb-0! min-[821px]:w-64"
                    />
                    @if ($canEdit)
                        <x-ui.button type="submit" variant="secondary">Move selected</x-ui.button>
                    @endif
                    <x-ui.button type="submit" variant="secondary"
                        formaction="{{ route('documents.bulk.download', $matter) }}">Download ZIP</x-ui.button>
                </div>
                @if ($canEdit)
                    <div class="mt-3 flex flex-col gap-3 min-[821px]:flex-row min-[821px]:items-end">
                        <x-ui.field name="tags" label="Tags (comma-separated)" placeholder="urgent, exhibit-a" class="grow mb-0!" />
                        <div class="flex gap-2">
                            <x-ui.button type="submit" variant="secondary" name="mode" value="add"
                                formaction="{{ route('documents.bulk.tag', $matter) }}">Add tags</x-ui.button>
                            <x-ui.button type="submit" variant="secondary" name="mode" value="remove"
                                formaction="{{ route('documents.bulk.tag', $matter) }}">Remove tags</x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </form>
        <x-ui.pagination :paginator="$documents" />
    @endif
</x-layouts.app>
