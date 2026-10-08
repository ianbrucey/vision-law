{{--
    matters/show.blade.php — spec 006 T-05 (05-ui.md §Matter detail, mockup §02–§04).

    Detail with 4 tabs (Overview / Documents / Timeline / Team), transition
    modal, delete modal. The Documents tab is an EXPLICIT Phase-3 placeholder
    (defined empty panel, never a dead link); the received/sent register is
    real (this spec). Tabs are server-rendered via ?tab= (keyboard-navigable,
    no JS required).

    Expects: $matter, $tab, $summary, $canManage, $canEdit, $canComment,
    $legalNextStates, $nextStateOptions (+ per-tab payloads from the
    controller's tab loaders).
--}}
<x-layouts.app :title="$matter->title . ' · Vision Law'">
    <x-ui.page-header :title="$matter->title" :subtitle="$matter->matter_number . ' · ' . $summary['days_in_state'] . ' ' . \Illuminate\Support\Str::plural('day', $summary['days_in_state']) . ' in state'">
        <x-slot name="crumbs">
            <a href="{{ route('matters.index') }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Matters</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>{{ $matter->matter_number }}</span>
        </x-slot>
        <x-slot name="actions">
            <x-ui.status type="matter" :value="$matter->lifecycle_state" />
            @if ($canManage && count($legalNextStates) > 0)
                <x-ui.button variant="secondary" type="button" @click="$dispatch('vl-open-modal', { id: 'transition-modal' })">
                    Transition state
                </x-ui.button>
            @endif
            @if ($canEdit)
                <x-ui.button variant="primary" href="{{ route('matters.edit', $matter) }}">Edit</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button variant="danger" type="button" @click="$dispatch('vl-open-modal', { id: 'delete-modal' })">
                    Delete
                </x-ui.button>
            @endif
        </x-slot>
        <x-slot name="subnav">
            <x-ui.sub-nav :items="[
                ['label' => 'Overview', 'href' => route('matters.show', [$matter->getKey(), 'tab' => 'overview']), 'active' => $tab === 'overview'],
                ['label' => 'Documents', 'href' => route('matters.show', [$matter->getKey(), 'tab' => 'documents']), 'active' => $tab === 'documents'],
                ['label' => 'Timeline', 'href' => route('matters.show', [$matter->getKey(), 'tab' => 'timeline']), 'active' => $tab === 'timeline'],
                ['label' => 'Team', 'href' => route('matters.show', [$matter->getKey(), 'tab' => 'team']), 'active' => $tab === 'team'],
            ]" />
        </x-slot>
    </x-ui.page-header>

    {{-- 05-ui.md §States (validation): transition field errors surface here,
         next to a button that re-opens the modal. --}}
    @if ($errors->transition->any())
        <x-ui.banner tone="danger" title="Transition not saved">
            {{ $errors->transition->first() }}
            <div class="mt-2.5">
                <x-ui.button variant="secondary" size="sm" type="button" @click="$dispatch('vl-open-modal', { id: 'transition-modal' })">
                    Reopen transition
                </x-ui.button>
            </div>
        </x-ui.banner>
    @endif

    @include('matters._' . $tab)

    {{-- Transition modal (mockup §02): legal next states only, note, and the
         consequence line. Guards are enforced server-side; the hints below
         are honest UI, not enforcement. --}}
    @if ($canManage && count($legalNextStates) > 0)
        <x-ui.modal id="transition-modal" title="Transition state">
            <p class="mb-4">
                Current state: <strong>{{ \App\View\Components\Ui\Status::MAP['matter'][$matter->lifecycle_state]['label'] ?? $matter->lifecycle_state }}</strong>.
                Only legal next states are offered — anything else returns 409.
            </p>
            <form method="POST" action="{{ route('matters.transition', $matter) }}" x-data="{ to: '' }">
                @csrf
                <x-ui.select
                    name="to"
                    label="Next state"
                    :options="$nextStateOptions"
                    placeholder="Select next state…"
                    required
                    x-model="to"
                    :error="$errors->transition->first('to')"
                />
                <div x-show="to === 'CLOSED'" x-cloak class="mb-4 rounded-vl-control border border-vl-brass bg-vl-brass-soft px-4 py-3 text-[14px] text-vl-ink">
                    Closing requires a closing note below.
                </div>
                <div x-show="to === 'RETENTION_HOLD'" x-cloak class="mb-4 rounded-vl-control border border-vl-line bg-vl-paper px-4 py-3 text-[14px] text-vl-ink">
                    Retention hold can only be set by an org admin, with a note.
                </div>
                <div x-show="to === 'DISPOSITION'" x-cloak class="mb-4 rounded-vl-control border border-vl-line bg-vl-paper px-4 py-3 text-[14px] text-vl-ink">
                    Disposition is not enabled yet (Phase 5) — this transition will be refused.
                </div>
                <x-ui.textarea
                    name="note"
                    label="Note"
                    optional
                    rows="3"
                    placeholder="Why is this moving? (recorded in the audit trail)"
                    :error="$errors->transition->first('note')"
                />
                <x-slot name="footer">
                    <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                        Cancel
                    </x-ui.button>
                    <x-ui.button variant="primary" type="submit">Transition</x-ui.button>
                </x-slot>
            </form>
        </x-ui.modal>
    @endif

    {{-- Destructive pattern (05-ui.md §States): danger button + consequence
         line, behind the modal. --}}
    @if ($canManage)
        <x-ui.modal id="delete-modal" title="Delete this matter?">
            <p class="mb-4">
                <strong>{{ $matter->matter_number }} · {{ $matter->title }}</strong>
                will be soft-deleted. It disappears from the list but nothing
                is destroyed yet.
            </p>
            <x-ui.banner tone="danger">
                Soft-deleted matters are recoverable by org admins for 30 days.
            </x-ui.banner>
            <x-slot name="footer">
                <x-ui.button variant="ghost" type="button" @click="$dispatch('vl-close-modal')">
                    Cancel
                </x-ui.button>
                <form method="POST" action="{{ route('matters.destroy', $matter) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-ui.button variant="danger" type="submit">Delete matter</x-ui.button>
                </form>
            </x-slot>
        </x-ui.modal>
    @endif
</x-layouts.app>
