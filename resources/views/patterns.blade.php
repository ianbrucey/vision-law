{{--
    patterns.blade.php — the /_patterns living catalogue (spec 002 T-05,
    docs/UI_Standards.md Governance).

    Renders EVERY x-ui primitive in every variant/state with SYNTHETIC data
    only — the drift detector. Local environment only (the route 404s
    otherwise). Uses the real app shell, so this page also exercises it.
--}}
<x-layouts.app title="UI patterns · Vision Law">
    <x-slot:org>Sterling &amp; Associates</x-slot:org>
    <x-slot:userMenu>G. Granted · Attorney</x-slot:userMenu>
    <x-slot:nav>
        <a href="#buttons">Buttons</a><a href="#forms">Forms</a><a href="#display">Display</a><a href="#feedback">Signals</a><a href="#table">Table</a><a href="#overlay">Overlays</a>
    </x-slot:nav>
    <x-slot:content>
        <x-ui.page-header
            title="UI patterns"
            subtitle="Every x-ui primitive in every variant and state. Synthetic data only — the living drift detector."
        >
            <x-slot:actions>
                <x-ui.button variant="primary" size="sm" href="#table">Jump to tables</x-ui.button>
                <x-ui.button variant="secondary" size="sm" href="#overlay">Jump to overlays</x-ui.button>
            </x-slot:actions>
            <x-slot:subnav>
                <x-ui.sub-nav :items="[
                    ['label' => 'Primitives', 'href' => '#buttons', 'active' => true],
                    ['label' => 'Composites', 'href' => '#table', 'active' => false],
                    ['label' => 'Shell', 'href' => '#shell-notes', 'active' => false],
                ]" />
            </x-slot:subnav>
        </x-ui.page-header>

        {{-- Buttons --}}
        <section id="buttons" aria-label="Buttons" class="mb-8">
            <x-ui.card title="Buttons — one door for actions">
                <div class="flex flex-wrap gap-3 items-center mb-4">
                    <x-ui.button variant="primary">Primary</x-ui.button>
                    <x-ui.button variant="secondary">Secondary</x-ui.button>
                    <x-ui.button variant="danger">Danger</x-ui.button>
                    <x-ui.button variant="ghost">Ghost</x-ui.button>
                </div>
                <div class="flex flex-wrap gap-3 items-center mb-4">
                    <x-ui.button variant="primary" size="sm">Primary sm</x-ui.button>
                    <x-ui.button variant="secondary" size="sm">Secondary sm</x-ui.button>
                    <x-ui.button variant="primary" disabled>Disabled</x-ui.button>
                    <x-ui.button variant="secondary" href="#buttons">Link button</x-ui.button>
                </div>
                <p class="text-[13px] text-vl-mut">One primary button per view. Danger only for irreversible actions, always paired with a plain-language consequence line. Cancellation is a link, never a button.</p>
            </x-ui.card>
        </section>

        {{-- Form controls --}}
        <section id="forms" aria-label="Form controls" class="mb-8">
            <x-ui.card title="Form controls — four doors, one contract">
                <x-ui.field name="matter_title" label="Matter title" value="Sterling v. Apex Construction" help="The caption as it appears on filings." />
                <x-ui.field name="client_ref" label="Client reference" optional />
                <x-ui.field name="docket_no" label="Docket number" value="oops" error="Use the format D-2026-001." />
                <x-ui.select name="demo_status" label="Status" :options="['open' => 'Open', 'on_hold' => 'On hold', 'closed' => 'Closed']" placeholder="Choose a status…" />
                <x-ui.checkbox name="demo_priv" label="Mark as privileged" help="Limits who can see this record." />
                <x-ui.textarea name="demo_notes" label="Notes" value="Synthetic notes for the catalogue." />
            </x-ui.card>
        </section>

        {{-- Cards, KV, stats --}}
        <section id="display" aria-label="Cards and data" class="mb-8">
            <div class="grid gap-4 min-[821px]:grid-cols-3 mb-4">
                <x-ui.card><x-ui.stat value="12" label="Open matters" /></x-ui.card>
                <x-ui.card><x-ui.stat value="3" label="Deadlines this week" /></x-ui.card>
                <x-ui.card><x-ui.stat value="98%" label="Drafts on time" /></x-ui.card>
            </div>
            <x-ui.card title="Matter detail">
                <x-slot:header-actions><x-ui.chip tone="info">Active</x-ui.chip></x-slot:header-actions>
                <x-ui.kv :items="[
                    ['label' => 'Caption', 'value' => 'Sterling v. Apex Construction'],
                    ['label' => 'Court', 'value' => 'Fulton County Superior Court'],
                    ['label' => 'Opened', 'value' => 'Oct 2, 2026'],
                ]" />
            </x-ui.card>
        </section>

        {{-- Banners, chips, statuses --}}
        <section id="feedback" aria-label="Banners, chips and statuses" class="mb-8">
            <x-ui.card title="Banners — signals, never decoration">
                <x-ui.banner tone="info" title="Heads up">Discovery cutoff moved to Nov 12 after the continuance.</x-ui.banner>
                <x-ui.banner tone="success" title="Export complete">The brief exported cleanly with all exhibits attached.</x-ui.banner>
                <x-ui.banner tone="warn" title="Privileged">This matter is marked privileged — do not forward.</x-ui.banner>
                <x-ui.banner tone="danger" title="Destructive context">You are about to delete shared material.</x-ui.banner>
            </x-ui.card>
            <x-ui.card title="Chips & statuses">
                <div class="flex flex-wrap gap-2 mb-4">
                    <x-ui.chip tone="ok">ok</x-ui.chip>
                    <x-ui.chip tone="warn">warn</x-ui.chip>
                    <x-ui.chip tone="bad">bad</x-ui.chip>
                    <x-ui.chip tone="neutral">neutral</x-ui.chip>
                    <x-ui.chip tone="info">info</x-ui.chip>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.status type="matter" value="open" />
                    <x-ui.status type="matter" value="on_hold" />
                    <x-ui.status type="document" value="final" />
                    <x-ui.status type="draft" value="in_review" />
                    <x-ui.status type="draft" value="not_a_real_status" />
                </div>
            </x-ui.card>
        </section>

        {{-- Table & pagination --}}
        <section id="table" aria-label="Table and pagination" class="mb-8">
            <x-ui.table>
                <x-slot:head><th>Document</th><th>Status</th><th>Modified</th><th>By</th></x-slot:head>
                <x-slot:body>
                    @foreach ($documents as $doc)
                        <tr>
                            <td>{{ $doc['title'] }}</td>
                            <td><x-ui.status type="document" :value="$doc['status']" /></td>
                            <td>{{ $doc['modified'] }}</td>
                            <td>{{ $doc['by'] }}</td>
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>
            <x-ui.pagination :paginator="$paginator" />
        </section>

        {{-- Modal, toast, empty --}}
        <section id="overlay" aria-label="Modal, toast and empty states" class="mb-8">
            <x-ui.card title="Modal — the only dialog door">
                <div class="flex flex-wrap gap-3 items-center">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('vl-open-modal', { id: 'patterns-modal' })">Open confirmation modal</x-ui.button>
                    <span class="text-[13px] text-vl-mut">Opens via the <code>vl-open-modal</code> window event · Escape closes · focus trapped.</span>
                </div>
                <x-ui.modal id="patterns-modal" title="Delete draft versions?">
                    Deleting this draft removes all 4 versions permanently. Filed copies are unaffected.
                    <x-slot:footer>
                        <a href="#overlay" class="text-[14px] text-vl-mut no-underline self-center" x-on:click.prevent="$dispatch('vl-close-modal')">Cancel</a>
                        <x-ui.button variant="danger" size="sm">Delete all 4 versions</x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            </x-ui.card>
            <x-ui.card title="Toasts — from session flash">
                @foreach ($toastDemos as $toastDemo)
                    @php(session()->flash('toast', $toastDemo))
                    <x-ui.toast />
                @endforeach
                <p class="text-[13px] text-vl-mut mt-3">Toasts name the consequence, not the action — and never repeat confidential content.</p>
            </x-ui.card>
            <x-ui.card title="Empty states">
                <x-ui.empty title="No documents yet" actionHref="#table" actionLabel="Upload your first document">
                    Uploaded files and generated drafts will appear here.
                </x-ui.empty>
                <x-ui.empty title="No deadlines this week">
                    You're clear. New deadlines appear automatically from matter templates.
                </x-ui.empty>
            </x-ui.card>
        </section>

        {{-- Shell notes --}}
        <section id="shell-notes" aria-label="Shell notes" class="mb-8">
            <x-ui.card title="App shell — the frame">
                <x-ui.kv :items="[
                    ['label' => 'Top bar', 'value' => 'Fixed · wordmark “Vision Law” · org slot · user-menu slot'],
                    ['label' => 'Nav', 'value' => 'Inline on desktop · Alpine drawer on phones (≤820px)'],
                    ['label' => 'Content', 'value' => 'max-width 1120px · 16px gutters mobile / 32px desktop'],
                    ['label' => 'Toast mount', 'value' => 'Top of <main> — the last demo toast above rendered here'],
                    ['label' => 'Skip link', 'value' => 'First in tab order, targets #vl-main'],
                ]" />
            </x-ui.card>
        </section>
    </x-slot:content>
    <x-slot:footer>
        <a href="#buttons" class="text-vl-info no-underline">Back to top</a>
        <span>Vision Law UI foundation · spec 002 · synthetic data only</span>
    </x-slot:footer>
</x-layouts.app>
