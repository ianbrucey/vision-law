{{--
    documents/versions.blade.php — spec 007 T-07 (05-ui.md §04, mockup §4).

    Version history, phone-first: newest first, current version
    distinguished, per-row preview / download / restore /
    diff-against-current. "Upload new version" for uploaded binaries
    (C-07); authored/generated documents version through the editor.

    Expects: $matter, $document,
             $rows (list of ['version' => DocumentVersion,
                             'previewUrl' => ?string, 'downloadUrl' => ?string]),
             $canEdit (bool), $currentVersionId (string).
--}}
<x-layouts.app title="Versions · {{ $document->title }} · Vision Law">
    <x-ui.page-header
        title="Versions"
        :subtitle="$document->title"
    >
        <x-slot name="crumbs">
            <a href="{{ route('documents.index', $matter->getKey()) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
        </x-slot>
    </x-ui.page-header>

    @php
        $noticeMessages = [
            'version_created' => 'New version uploaded.',
            'version_restored' => 'Version restored — a new version was created with the old bytes.',
            'already_current_version' => 'That version is already current — nothing changed.',
            'identical_bytes_already_stored' => 'Identical bytes are already stored — no new version was created.',
            'quarantined' => 'The upload was quarantined by the malware scan and is not available.',
        ];
        $flashNotice = session('version_notice');
    @endphp

    @if (is_string($flashNotice) && $flashNotice !== '')
        <x-ui.banner tone="{{ $flashNotice === 'quarantined' ? 'danger' : 'info' }}" title="Versions">
            {{ $noticeMessages[$flashNotice] ?? $flashNotice }}
        </x-ui.banner>
    @endif

    <x-ui.card class="mb-4">
        <p class="text-vl-mut text-[13.5px] leading-relaxed">
            Every change is a new immutable version. Rollback creates a new version —
            history is never rewritten.
        </p>
    </x-ui.card>

    @if ($canEdit && $document->kind === 'uploaded')
        <x-ui.card class="mb-4">
            <h2 class="font-serif text-[17px] text-vl-ink mb-3">Upload new version</h2>
            <form method="POST" action="{{ route('documents.versions.store', [$matter->getKey(), $document->getKey()]) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <x-ui.field label="File" name="file" type="file" :required="true" />
                <x-ui.textarea label="Change note" name="change_note" rows="2"
                    placeholder="What changed in this version?" />
                <x-ui.button type="submit" variant="primary" class="w-full sm:w-auto min-h-[44px]">Upload as new version</x-ui.button>
            </form>
        </x-ui.card>
    @elseif ($canEdit)
        <x-ui.card class="mb-4">
            <p class="text-vl-mut text-[13.5px]">
                This is an {{ $document->kind }} document — content changes are made in the
                <a href="{{ route('documents.editor.edit', [$matter->getKey(), $document->getKey()]) }}" class="underline decoration-vl-line underline-offset-2">editor</a>,
                which publishes a new version on save.
            </p>
        </x-ui.card>
    @endif

    <div class="space-y-3">
        @forelse ($rows as $row)
            @php
                $version = $row['version'];
                $isCurrent = (string) $version->getKey() === $currentVersionId;
                $size = $version->blob?->size;
                $sizeLabel = $size === null ? null : ($size < 1024 ? $size.' B' : ($size < 1048576 ? number_format($size / 1024, 1).' KB' : number_format($size / 1048576, 1).' MB'));
            @endphp
            <x-ui.card class="{{ $isCurrent ? 'ring-1 ring-vl-ink/30' : '' }}">
                <div class="flex items-start gap-3">
                    <div class="shrink-0 w-12 text-center">
                        <div class="font-serif text-[20px] text-vl-ink leading-none">v{{ $version->version_number }}</div>
                        @if ($isCurrent)
                            <x-ui.chip class="mt-1.5">Current</x-ui.chip>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[14px] text-vl-ink">
                            {{ $version->created_at?->format('M j, Y') }} · {{ $version->creator?->name ?? '—' }}
                        </p>
                        <p class="text-vl-mut text-[13px] mt-0.5 break-words">
                            @if ($version->restoredFrom)
                                Restored from v{{ $version->restoredFrom->version_number }}
                                @if ($version->change_note)
                                    — {{ $version->change_note }}
                                @endif
                            @elseif ($version->change_note)
                                {{ $version->change_note }}
                            @else
                                <span class="italic">No change note</span>
                            @endif
                            @if ($sizeLabel) · {{ $sizeLabel }} @endif
                        </p>
                        <div class="flex flex-wrap gap-2 mt-2.5">
                            @if ($row['previewUrl'])
                                <x-ui.button size="sm" :href="$row['previewUrl']" target="_blank" rel="noopener" class="min-h-[44px]">Preview</x-ui.button>
                            @else
                                <x-ui.button size="sm" disabled title="Preview routes are not available yet" class="min-h-[44px]">Preview</x-ui.button>
                            @endif
                            @if ($row['downloadUrl'])
                                <x-ui.button size="sm" :href="$row['downloadUrl']" class="min-h-[44px]">Download</x-ui.button>
                            @else
                                <x-ui.button size="sm" disabled title="Download routes are not available yet" class="min-h-[44px]">Download</x-ui.button>
                            @endif
                            @if (! $isCurrent)
                                <x-ui.button size="sm"
                                    :href="route('documents.versions.diff', [$matter->getKey(), $document->getKey(), $version->version_number, $rows[0]['version']->version_number])"
                                    class="min-h-[44px]">Diff vs current</x-ui.button>
                            @elseif (count($rows) > 1)
                                <x-ui.button size="sm"
                                    :href="route('documents.versions.diff', [$matter->getKey(), $document->getKey(), $rows[1]['version']->version_number, $version->version_number])"
                                    class="min-h-[44px]">Diff vs v{{ $rows[1]['version']->version_number }}</x-ui.button>
                            @endif
                            @if ($canEdit && ! $isCurrent && $document->kind === 'uploaded')
                                <details class="w-full sm:w-auto">
                                    <summary class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-vl-control border border-vl-line bg-vl-card text-[14px] text-vl-ink cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                        Restore…
                                    </summary>
                                    <form method="POST" action="{{ route('documents.versions.restore', [$matter->getKey(), $document->getKey(), $version->getKey()]) }}" class="mt-2 space-y-2 rounded-vl-control border border-vl-line p-3">
                                        @csrf
                                        <x-ui.textarea label="Reason (required)" name="reason" rows="2"
                                            placeholder="Why is this version being restored?" />
                                        <p class="text-vl-mut text-[12.5px]">Restoring creates a new version with v{{ $version->version_number }}'s exact bytes — history is never rewritten.</p>
                                        <x-ui.button type="submit" variant="primary" size="sm" class="min-h-[44px]">Restore as new version</x-ui.button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.empty title="No versions">This document has no versions yet.</x-ui.empty>
        @endforelse
    </div>

    <p class="text-vl-mut text-[12.5px] mt-4">
        Restoring a version creates a new version with the old bytes and requires a reason —
        the audit trail shows the full chain.
    </p>
</x-layouts.app>
