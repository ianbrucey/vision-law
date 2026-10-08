{{--
    documents/editor.blade.php — spec 007 T-04 (DOC-09/10, 007-D03).

    The built-in editor: a typing surface for authored documents.
    contenteditable-based, no CDN libraries, x-ui.* components only.
    Merge fields only in templates; NO drafting intelligence anywhere here.

    Expects: $matter, $document (null when $isNew), $content (HTML string),
    $isNew (bool).
--}}
@php
    $pageTitle = ($isNew ? 'New document' : $document->title).' · Editor · Vision Law';
@endphp
<x-layouts.app :title="$pageTitle">
    <x-ui.page-header
        :title="$isNew ? 'New document' : $document->title"
        :subtitle="$matter->matter_number . ' · ' . $matter->title">
        <x-slot name="crumbs">
            <a href="{{ route('matters.index') }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">Matters</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <a href="{{ route('matters.show', $matter) }}" class="text-vl-mut underline decoration-vl-line underline-offset-2 hover:text-vl-ink">{{ $matter->matter_number }}</a>
            <span class="text-vl-mut" aria-hidden="true"> › </span>
            <span>Editor</span>
        </x-slot>
        <x-slot name="actions">
            @if (! $isNew)
                <x-ui.status type="document" :value="$document->status" />
            @endif
        </x-slot>
    </x-ui.page-header>

    @if ($isNew)
        {{-- Step 1: name the document, then the typing surface opens. --}}
        <x-ui.card>
            <form method="POST" action="{{ route('documents.authored.store', $matter) }}">
                @csrf
                <x-ui.field name="title" label="Title" required help="The document title. Metadata edits (title, description, tags, folder) never create versions." />
                <x-ui.textarea name="description" label="Description" :optional="true" rows="2" />
                <div class="flex flex-wrap gap-2 mt-2">
                    <x-ui.button variant="primary" type="submit" class="min-h-[44px]">Start writing</x-ui.button>
                    <x-ui.button variant="secondary" href="{{ route('matters.show', $matter) }}" class="min-h-[44px]">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @else
        <div id="editor-root"
             data-draft-url="{{ route('documents.draft.update', [$matter, $document]) }}"
             data-publish-url="{{ route('documents.versions.publish', [$matter, $document]) }}"
             data-csrf="{{ csrf_token() }}"
             data-version-count="{{ $document->versions()->count() }}">

            {{-- Toolbar: every button ≥44px; wraps on phone widths. --}}
            <div class="flex flex-wrap gap-1.5 mb-3" role="toolbar" aria-label="Formatting">
                <x-ui.button type="button" data-cmd="formatBlock" data-arg="h1" class="min-h-[44px]" title="Heading 1"><strong>H1</strong></x-ui.button>
                <x-ui.button type="button" data-cmd="formatBlock" data-arg="h2" class="min-h-[44px]" title="Heading 2"><strong>H2</strong></x-ui.button>
                <x-ui.button type="button" data-cmd="formatBlock" data-arg="h3" class="min-h-[44px]" title="Heading 3"><strong>H3</strong></x-ui.button>
                <x-ui.button type="button" data-cmd="bold" class="min-h-[44px]" title="Bold"><strong>B</strong></x-ui.button>
                <x-ui.button type="button" data-cmd="italic" class="min-h-[44px]" title="Italic"><em>I</em></x-ui.button>
                <x-ui.button type="button" data-cmd="insertUnorderedList" class="min-h-[44px]" title="Bulleted list">• List</x-ui.button>
                <x-ui.button type="button" data-cmd="insertOrderedList" class="min-h-[44px]" title="Numbered list">1. List</x-ui.button>
                <x-ui.button type="button" data-cmd="insertTable" class="min-h-[44px]" title="Insert 2×2 table">Table</x-ui.button>
                <x-ui.button type="button" data-cmd="insertPageBreak" class="min-h-[44px]" title="Insert page-break hint">Page break</x-ui.button>
                <x-ui.button type="button" data-cmd="toggleLineNumbers" class="min-h-[44px]" title="Toggle pleading line numbering" aria-pressed="false">Line №</x-ui.button>
            </div>

            {{-- Typing surface. --}}
            <x-ui.card class="!p-0 overflow-hidden">
                <div id="editor-surface"
                     contenteditable="true"
                     role="textbox"
                     aria-label="Document content"
                     aria-multiline="true"
                     class="editor-surface min-h-[60vh] p-4 sm:p-6 text-[16px] leading-relaxed focus:outline-none">{!! $content !!}</div>
            </x-ui.card>

            <p id="draft-status" class="text-[13px] text-vl-mut mt-2" role="status" aria-live="polite">
                @if ($document->draft_updated_at)
                    Unsaved draft from {{ $document->draft_updated_at->diffForHumans() }} — it will resume here until you Save.
                @else
                    Start typing. A draft autosaves every 30 seconds — it never creates a version.
                @endif
            </p>

            {{-- Save: explicit publish → new immutable version (HTML + PDF rendition). --}}
            <form id="publish-form" method="POST" action="{{ route('documents.versions.publish', [$matter, $document]) }}" class="mt-3">
                @csrf
                <input type="hidden" name="content" id="publish-content">
                <x-ui.field name="change_note" label="Change note" :optional="true" help="Optional note stored with the new version." />
                <div class="flex flex-wrap gap-2 mt-2">
                    <x-ui.button variant="primary" type="submit" class="min-h-[44px]">Save — publish new version</x-ui.button>
                    <x-ui.button variant="secondary" href="{{ route('matters.show', $matter) }}" class="min-h-[44px]">Back to matter</x-ui.button>
                </div>
            </form>
        </div>

        <style>
            .editor-surface h1 { font-size: 1.6em; font-weight: 700; margin: .6em 0 .4em; }
            .editor-surface h2 { font-size: 1.35em; font-weight: 700; margin: .6em 0 .4em; }
            .editor-surface h3 { font-size: 1.15em; font-weight: 700; margin: .6em 0 .4em; }
            .editor-surface ul { list-style: disc; padding-left: 1.5em; margin: .5em 0; }
            .editor-surface ol { list-style: decimal; padding-left: 1.5em; margin: .5em 0; }
            .editor-surface table { border-collapse: collapse; width: 100%; margin: .5em 0; }
            .editor-surface td, .editor-surface th { border: 1px solid #9aa; padding: 6px 8px; }
            .editor-surface .page-break { border-top: 2px dashed #9aa; margin: 1em 0; page-break-before: always; }
            .editor-surface .page-break::after { content: "page break"; display: block; font-size: 11px; color: #9aa; text-transform: uppercase; letter-spacing: .1em; }
            /* Pleading line numbering: each block gets a gutter number. */
            .editor-surface.line-numbers { counter-reset: vl-line; padding-left: 3.2em; position: relative; }
            .editor-surface.line-numbers > * { position: relative; }
            .editor-surface.line-numbers > *::before {
                counter-increment: vl-line; content: counter(vl-line);
                position: absolute; left: -2.8em; width: 2.2em; text-align: right;
                color: #9aa; font-size: 12px; user-select: none;
            }
        </style>

        <script>
        (function () {
            const root = document.getElementById('editor-root');
            if (!root) return;
            const surface = document.getElementById('editor-surface');
            const status = document.getElementById('draft-status');
            const csrf = root.dataset.csrf;
            const draftUrl = root.dataset.draftUrl;

            // Toolbar commands (plain execCommand — typing surface only).
            root.querySelectorAll('[data-cmd]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const cmd = btn.dataset.cmd;
                    surface.focus();
                    if (cmd === 'insertTable') {
                        document.execCommand('insertHTML', false,
                            '<table><tbody><tr><td><br></td><td><br></td></tr><tr><td><br></td><td><br></td></tr></tbody></table><p><br></p>');
                    } else if (cmd === 'insertPageBreak') {
                        document.execCommand('insertHTML', false, '<div class="page-break"></div><p><br></p>');
                    } else if (cmd === 'toggleLineNumbers') {
                        const on = surface.classList.toggle('line-numbers');
                        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                    } else if (cmd === 'formatBlock') {
                        document.execCommand('formatBlock', false, btn.dataset.arg);
                    } else {
                        document.execCommand(cmd, false, null);
                    }
                    markDirty();
                });
            });

            let dirty = false;
            let saving = false;
            function markDirty() {
                dirty = true;
                status.textContent = 'Unsaved changes…';
            }
            surface.addEventListener('input', markDirty);

            async function autosave() {
                if (!dirty || saving) return;
                saving = true;
                status.textContent = 'Saving draft…';
                try {
                    const res = await fetch(draftUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ content: surface.innerHTML }),
                    });
                    if (!res.ok) throw new Error('draft failed');
                    const data = await res.json();
                    dirty = false;
                    const when = data.draft_updated_at ? new Date(data.draft_updated_at).toLocaleTimeString() : '';
                    status.textContent = 'Draft saved' + (when ? ' at ' + when : '') + ' — not a version.';
                } catch (e) {
                    status.textContent = 'Draft save failed — your text is still here; try Save.';
                } finally {
                    saving = false;
                }
            }
            setInterval(autosave, 30000);

            // Publish: sync the surface HTML into the form, then submit.
            document.getElementById('publish-form').addEventListener('submit', (e) => {
                document.getElementById('publish-content').value = surface.innerHTML;
            });

            window.addEventListener('beforeunload', (e) => {
                if (dirty) e.preventDefault();
            });
        })();
        </script>
    @endif
</x-layouts.app>
