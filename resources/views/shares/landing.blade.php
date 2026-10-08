{{--
    shares/landing.blade.php — spec 007 T-08 (DOC-25).

    Anonymous landing page for a valid share link (password gate already
    passed, or no password set). Shows the pinned version's title and a
    download button when the link allows it. The link is pinned to one
    version — newer versions never leak through.

    Expects: $share (DocumentShare), $documentTitle (string),
    $versionNumber (int), $fileSize (int|null).
--}}
<x-layouts.public title="Shared document · Vision Law">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <x-ui.card title="Shared document" class="w-full max-w-[440px]">
            <p class="text-[17px] font-semibold text-vl-ink mb-1">{{ $documentTitle }}</p>
            <p class="text-[13px] text-vl-mut mb-6">
                Version {{ $versionNumber }}
                @if ($fileSize)
                    · {{ number_format($fileSize / 1024, 0) }} KB
                @endif
                · link expires {{ $share->expires_at->format('M j, Y') }}
            </p>

            @if ($share->allow_download)
                <form method="POST" action="{{ route('shares.download', ['token' => $token]) }}">
                    @csrf
                    <x-ui.button variant="primary" type="submit" class="w-full">Download</x-ui.button>
                </form>
                <p class="text-[13px] text-vl-mut mt-4">The download is the exact pinned version, with its SHA-256 checksum.</p>
            @else
                <x-ui.banner tone="info" title="Read-only link">
                    Download is disabled for this link. Contact the sender if you need a copy.
                </x-ui.banner>
            @endif
        </x-ui.card>
    </div>
</x-layouts.public>
