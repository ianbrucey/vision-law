{{--
    home.blade.php — the authenticated app home (spec 010 T-02; C-01, C-03,
    C-04, C-09).

    Dashboard v1, assembled by HomeController from existing data only:
    my matters (006-scoped, most recently updated first, with count),
    recent activity (006 audit read model over the same scope), quick
    actions (page-header actions), and a security status card. When the
    user's organization has no matters at all, the modules are replaced by
    one guided empty state — no placeholder widgets (009's deadlines
    module joins this view at 009 Plan time, 010-D08).

    Expects: $user, $orgName, $matters (Collection<Matter>), $matterCount,
    $activity (Collection<AuditEvent>), $orgHasMatters, $canCreateMatter,
    $isOrgAdmin, $twoFactorConfirmed, $passkeyCount.
--}}
<x-layouts.app title="Home · Vision Law">
    <x-ui.page-header
        title="Home"
        :subtitle="'Welcome back, ' . $user->name . ($orgName ? ' · ' . $orgName : '')"
    >
        <x-slot name="actions">
            @if ($canCreateMatter)
                <x-ui.button variant="primary" href="{{ route('matters.create') }}">New matter</x-ui.button>
            @endif
            @if ($isOrgAdmin)
                <x-ui.button variant="secondary" href="{{ route('admin.invitations.index') }}">Invitations</x-ui.button>
            @endif
        </x-slot>
    </x-ui.page-header>

    @if (! $orgHasMatters)
        <x-ui.empty title="Welcome to Vision Law">
            <p>Vision Law is your firm&rsquo;s home for matters, documents, and deadlines &mdash; every file, change, and decision kept in one secure, audited place. Create your first matter to get started.</p>
            <div class="flex flex-wrap justify-center gap-2.5 mt-4">
                @if ($canCreateMatter)
                    <x-ui.button variant="primary" href="{{ route('matters.create') }}">Create a matter</x-ui.button>
                @endif
                @if ($isOrgAdmin)
                    <x-ui.button variant="secondary" href="{{ route('admin.invitations.index') }}">Invite your team</x-ui.button>
                @endif
            </div>
        </x-ui.empty>
    @else
        @php
            // Home-local activity describer (010-D05 — the 006 timeline's
            // sentence map stays untouched). Unknown events fall through to
            // a humanized event name rather than raw enum text.
            $describe = function (\App\Models\AuditEvent $event): string {
                return match ($event->event) {
                    'matter.created' => 'created the matter',
                    'matter.updated' => 'updated the matter',
                    'matter.comment_added' => 'commented on the matter',
                    'matter.transition' => 'changed the matter state',
                    'document.uploaded' => 'filed a document',
                    default => str_replace('_', ' ', str_replace('matter.', '', (string) $event->event)),
                };
            };
        @endphp

        <div class="grid gap-6 min-[821px]:grid-cols-3">
            <x-ui.card title="My matters" class="min-[821px]:col-span-2">
                <x-slot name="header-actions">
                    <a href="{{ route('matters.index') }}" class="text-[14px] font-semibold text-vl-ink no-underline hover:underline">View all matters</a>
                </x-slot>

                <x-ui.stat :value="$matterCount" label="Matters you can access" />

                <div class="mt-2">
                    @forelse ($matters as $matter)
                        <a href="{{ route('matters.show', $matter) }}" class="flex items-baseline gap-3 py-3 border-t border-vl-line no-underline">
                            <span class="font-semibold text-vl-ink">{{ $matter->title }}</span>
                            <span class="text-[13px] text-vl-mut shrink-0">{{ $matter->matter_number }}</span>
                            <span class="ml-auto text-[13px] text-vl-mut whitespace-nowrap">{{ fmtRelative($matter->updated_at) }}</span>
                        </a>
                    @empty
                        <p class="text-[14.5px] text-vl-mut mt-3">No matters are shared with you yet. When a teammate grants you access to a matter, it will appear here.</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card title="Recent activity" class="min-[821px]:col-span-2 min-[821px]:row-start-2">
                @forelse ($activity as $event)
                    <div class="flex items-baseline gap-3 py-2.5 border-t border-vl-line first:border-t-0 first:pt-0">
                        <p class="text-[14.5px]"><strong class="text-vl-ink">{{ $event->actor?->name ?? 'System' }}</strong> {{ $describe($event) }} &mdash; <span class="text-vl-mut">{{ $event->matter?->title }}</span></p>
                        <span class="ml-auto text-[12.5px] text-vl-mut whitespace-nowrap" title="{{ fmtDT($event->created_at) }}">{{ fmtRelative($event->created_at) }}</span>
                    </div>
                @empty
                    <p class="text-[14.5px] text-vl-mut">No recent activity on your matters yet.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card title="Security status" class="min-[821px]:col-start-3 min-[821px]:row-start-1">
                <div class="space-y-4">
                    <p class="text-[14.5px]">Two-factor authentication <strong class="text-vl-ink">{{ $twoFactorConfirmed ? 'On' : 'Not set up' }}</strong></p>
                    <p class="text-[14.5px]">Passkeys <strong class="text-vl-ink">{{ $passkeyCount }} {{ \Illuminate\Support\Str::plural('passkey', $passkeyCount) }} registered</strong></p>
                </div>
                <x-ui.button variant="secondary" href="{{ route('two-factor.settings') }}" class="mt-5">Security settings</x-ui.button>
            </x-ui.card>
        </div>
    @endif
</x-layouts.app>
