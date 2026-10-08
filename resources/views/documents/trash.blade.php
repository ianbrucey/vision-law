{{--
    documents/trash.blade.php — spec 007 T-05 (DOC-11).

    Per-matter trash: soft-deleted documents awaiting the 30-day scheduled
    purge. Visible only to :manage users (route-gated). Restore returns
    every version intact; permanent delete is blocked by legal holds (423).

    Expects: $matter, $documents (LengthAwarePaginator<Document>),
    $retentionDays.
--}}
<x-layouts.app title="Trash · {{ $matter->matter_number }} · Vision Law">
    <x-ui.page-header
        title="Trash"
        :subtitle="$documents->total() . ' ' . \Illuminate\Support\Str::plural('document', $documents->total()) . ' · ' . $matter->matter_number . ' · permanently deleted after ' . $retentionDays . ' days'"
    >
        <x-slot name="crumbs">
            <a href="{{ route('documents.index', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">Documents</a>
        </x-slot>
        <x-slot name="actions">
            <x-ui.button variant="secondary" :href="route('documents.index', $matter)">Back to documents</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if ($documents->total() === 0)
        <x-ui.empty title="Trash is empty">
            Deleted documents wait here {{ $retentionDays }} days before permanent deletion.
        </x-ui.empty>
    @else
        <div class="md:hidden">
            @foreach ($documents as $doc)
                <x-ui.card class="mb-2.5">
                    <p class="font-bold text-[16px] text-vl-ink leading-snug break-words">{{ $doc->title }}</p>
                    <p class="text-[13px] text-vl-mut mt-0.5">
                        {{ $doc->versions_count }} {{ \Illuminate\Support\Str::plural('version', $doc->versions_count) }}
                        · trashed {{ fmtDate($doc->deleted_at) }}
                    </p>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('documents.restore', ['matter' => $matter->getKey(), 'document' => $doc->getKey()]) }}">
                            @csrf
                            <x-ui.button type="submit">Restore</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('documents.destroy.permanent', ['matter' => $matter->getKey(), 'document' => $doc->getKey()]) }}"
                              onsubmit="return confirm('Permanently delete this document and all its versions? This cannot be undone.')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger">Delete permanently</x-ui.button>
                        </form>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="hidden md:block">
            <x-ui.card padded="false" class="mb-2">
                <x-ui.table>
                    <x-slot name="head">
                        <th scope="col">Title</th>
                        <th scope="col">Versions</th>
                        <th scope="col">Trashed</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </x-slot>
                    <x-slot name="body">
                        @foreach ($documents as $doc)
                            <tr>
                                <td><strong class="break-words">{{ $doc->title }}</strong></td>
                                <td>{{ $doc->versions_count }}</td>
                                <td class="whitespace-nowrap text-vl-mut">{{ fmtDate($doc->deleted_at) }}</td>
                                <td class="whitespace-nowrap">
                                    <form method="POST" action="{{ route('documents.restore', ['matter' => $matter->getKey(), 'document' => $doc->getKey()]) }}" class="inline">
                                        @csrf
                                        <x-ui.button size="sm" type="submit">Restore</x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('documents.destroy.permanent', ['matter' => $matter->getKey(), 'document' => $doc->getKey()]) }}"
                                          class="inline" onsubmit="return confirm('Permanently delete this document and all its versions? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button size="sm" variant="danger" type="submit">Delete permanently</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </x-slot>
                </x-ui.table>
            </x-ui.card>
        </div>
        <x-ui.pagination :paginator="$documents" />
    @endif
</x-layouts.app>
