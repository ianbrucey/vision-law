{{--
    documents/share.blade.php — spec 007 T-08 (05-ui.md §05, mockup §05).

    Phone-first share dialog: secure-link creation (expiry, optional
    password, download toggle, pinned version), people-with-access
    (internal grants), and active links with instant revocation.

    Expects: $matter, $document, $grants (with user, creator), $links
    (with version), $candidates (org users), $levels, $versions,
    $freshLink (one-time plaintext URL flashed at creation — rendered
    once, never re-rendered).

    Leak rule: tokens and token hashes never reach this view.
--}}
<x-layouts.app title="Share · {{ $document->title }} · Vision Law">
    <x-ui.page-header
        title="Share"
        :subtitle="$document->title . ' · ' . $matter->matter_number"
    >
        <x-slot name="crumbs">
            <a href="{{ route('matters.show', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">{{ $matter->matter_number }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('documents.index', $matter) }}" class="underline decoration-vl-line underline-offset-2 hover:decoration-vl-ink">Documents</a>
        </x-slot>
    </x-ui.page-header>

    @if ($freshLink)
        <x-ui.banner tone="success" title="Link created — copy it now">
            <p class="mb-3">This link is shown <strong>once</strong>. It will not be displayed again.</p>
            <div class="flex flex-col sm:flex-row gap-2">
                <input
                    type="text"
                    readonly
                    value="{{ $freshLink }}"
                    id="fresh-share-link"
                    class="flex-1 min-w-0 border border-vl-line rounded-vl-control px-3.5 py-2.5 text-[15px] bg-vl-card text-vl-text"
                    onclick="this.select()"
                >
                <x-ui.button variant="primary" type="button" id="copy-share-link">Copy link</x-ui.button>
            </div>
        </x-ui.banner>
        <script>
            document.getElementById('copy-share-link')?.addEventListener('click', () => {
                const input = document.getElementById('fresh-share-link');
                input.select();
                navigator.clipboard?.writeText(input.value).catch(() => document.execCommand('copy'));
            });
        </script>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Secure link creation --}}
        <x-ui.card title="Secure link">
            <form method="POST" action="{{ route('documents.links.store', [$matter, $document]) }}">
                @csrf
                <x-ui.select
                    name="expires_in_days"
                    label="Expires"
                    :options="['1' => '24 hours', '7' => '7 days', '30' => '30 days']"
                    value="7"
                    required
                />
                <x-ui.field
                    name="password"
                    label="Password"
                    type="password"
                    optional
                    help="Leave blank for link-only access. Five wrong attempts lock the link for 15 minutes."
                    autocomplete="new-password"
                />
                <x-ui.select
                    name="version_id"
                    label="Pinned version"
                    :options="$versions->pluck('version_number', 'id')->map(fn ($n) => 'Version ' . $n)->toArray()"
                    help="The link always opens this version — newer versions never leak through an old link."
                />
                <x-ui.checkbox
                    name="allow_download"
                    label="Allow download"
                    checked
                    help="When off, the link opens a read-only page with no download button."
                />
                <div class="mt-4">
                    <x-ui.button variant="primary" type="submit" class="w-full sm:w-auto">Create link</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- People with access (internal grants) --}}
        <x-ui.card title="People with access">
            @if ($grants->isEmpty())
                <x-ui.empty title="No document grants">Everyone with access gets it from their matter role.</x-ui.empty>
            @else
                <ul class="divide-y divide-vl-line">
                    @foreach ($grants as $grant)
                        <li class="py-3 flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-vl-ink truncate">{{ $grant->user->name }}</p>
                                <p class="text-[13px] text-vl-mut truncate">{{ $grant->user->email }} · {{ ucfirst($grant->level) }} on this document</p>
                            </div>
                            <form method="POST" action="{{ route('documents.grants.destroy', [$matter, $document, $grant]) }}" onsubmit="return confirm('Remove this grant?');">
                                @csrf
                                @method('DELETE')
                                <x-ui.button variant="danger" size="sm" type="submit">Remove</x-ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h3 class="text-[15px] font-bold text-vl-ink mt-6 mb-3">Add a grant</h3>
            <form method="POST" action="{{ route('documents.grants.store', [$matter, $document]) }}">
                @csrf
                <x-ui.select
                    name="user_id"
                    label="Person"
                    :options="$candidates->pluck('name', 'id')->toArray()"
                    placeholder="Choose a person"
                    required
                    help="Effective permission is the most permissive of their matter role and this grant."
                />
                <x-ui.select
                    name="level"
                    label="Level"
                    :options="array_combine($levels, array_map('ucfirst', $levels))"
                    required
                />
                <x-ui.button variant="secondary" type="submit" class="w-full sm:w-auto">Add grant</x-ui.button>
            </form>
        </x-ui.card>

        {{-- Active links --}}
        <x-ui.card title="Active links" class="lg:col-span-2">
            @if ($links->isEmpty())
                <x-ui.empty title="No links yet">Create a secure link above to share this document outside the matter team.</x-ui.empty>
            @else
                <div class="overflow-x-auto -mx-4 px-4">
                    <table class="w-full text-[14px] min-w-[560px]">
                        <thead>
                            <tr class="text-left text-[12px] uppercase tracking-[0.08em] text-vl-mut">
                                <th class="py-2 pr-4 font-bold">Created</th>
                                <th class="py-2 pr-4 font-bold">Expires</th>
                                <th class="py-2 pr-4 font-bold">Version</th>
                                <th class="py-2 pr-4 font-bold">Protection</th>
                                <th class="py-2 pr-4 font-bold">Download</th>
                                <th class="py-2 font-bold"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-vl-line">
                            @foreach ($links as $link)
                                <tr>
                                    <td class="py-3 pr-4">{{ $link->created_at->format('M j, Y g:i A') }}</td>
                                    <td class="py-3 pr-4">
                                        @if ($link->revoked_at)
                                            <x-ui.chip tone="bad">Revoked</x-ui.chip>
                                        @elseif ($link->expires_at->isPast())
                                            <x-ui.chip tone="warn">Expired</x-ui.chip>
                                        @else
                                            {{ $link->expires_at->format('M j, Y') }}
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">v{{ $link->version?->version_number ?? '—' }}</td>
                                    <td class="py-3 pr-4">{{ $link->password_hash ? 'Password' : 'Link only' }}</td>
                                    <td class="py-3 pr-4">{{ $link->allow_download ? 'Allowed' : 'Off' }}</td>
                                    <td class="py-3 text-right">
                                        @if (! $link->revoked_at)
                                            <form method="POST" action="{{ route('documents.links.destroy', [$matter, $document, $link]) }}" onsubmit="return confirm('Revoke this link immediately?');">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button variant="danger" size="sm" type="submit">Revoke</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
