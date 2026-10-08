{{--
    documents/partials/folder-node.blade.php — one folder row in the tree.

    Expects: $folder (DocumentFolder with 'children' relation + document_count
    attribute), $matter, $depth (int), $canEdit (bool),
    $allFolders (flat id => indented-name list for the parent select).
--}}
<li class="py-1">
    <div class="flex items-center gap-2 flex-wrap" style="padding-left: {{ min($depth, 6) * 18 }}px">
        <span class="font-semibold text-vl-ink min-h-[44px] inline-flex items-center" aria-hidden="true">📁</span>
        <a href="{{ route('documents.index', ['matter' => $matter->getKey(), 'folder_id' => $folder->getKey()]) }}"
           class="font-semibold text-vl-ink underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink min-h-[44px] inline-flex items-center">
            {{ $folder->name }}
        </a>
        <x-ui.chip>{{ $folder->getAttribute('document_count') ?? 0 }}</x-ui.chip>
    </div>
    @if ($canEdit)
        <div style="padding-left: {{ min($depth, 6) * 18 }}px">
            <details class="mt-1">
                <summary class="cursor-pointer text-[14px] font-semibold text-vl-ink-2 min-h-[44px] inline-flex items-center">
                    Rename / move
                </summary>
                <form method="POST" action="{{ route('folders.update', ['matter' => $matter->getKey(), 'folder' => $folder->getKey()]) }}"
                      class="mt-2 flex flex-col gap-2 max-w-[420px]">
                    @csrf
                    @method('PATCH')
                    @php
                        $parentOptions = ['' => 'Top level'];
                        foreach ($allFolders as $id => $label) {
                            if ($id !== (string) $folder->getKey()) {
                                $parentOptions[$id] = $label;
                            }
                        }
                    @endphp
                    <x-ui.field name="name" label="Name" :value="$folder->name" required maxlength="120" class="mb-0!" />
                    <x-ui.select name="parent_id" label="Parent folder" :options="$parentOptions"
                                 :value="(string) ($folder->parent_id ?? '')" class="mb-0!" />
                    <div class="flex gap-2">
                        <x-ui.button type="submit" size="sm">Save</x-ui.button>
                    </div>
                </form>
                <form method="POST" action="{{ route('folders.destroy', ['matter' => $matter->getKey(), 'folder' => $folder->getKey()]) }}"
                      class="mt-2" onsubmit="return confirm('Delete this folder? Only empty folders can be deleted.')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" size="sm">Delete folder</x-ui.button>
                </form>
            </details>
        </div>
    @endif
    @if ($folder->children->isNotEmpty())
        <ul class="mt-1">
            @foreach ($folder->children as $child)
                @include('documents.partials.folder-node', [
                    'folder' => $child,
                    'matter' => $matter,
                    'depth' => $depth + 1,
                    'canEdit' => $canEdit,
                    'allFolders' => $allFolders,
                ])
            @endforeach
        </ul>
    @endif
</li>
