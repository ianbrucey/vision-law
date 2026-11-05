// Spec 012 Slice 1 — workspace chat interactions (UX preview only).
//
// Everything here is client-side page state: tab selection, the checkpoint
// decision, quick replies, composer sends, and the attach affordance live
// in Alpine for the length of the page session. NOTHING is sent to or
// stored on the server in this slice — chat persistence is build-sequence
// step 2, and the stub agent behind this UI arrives with it. The message
// shapes pushed here ({kind, who, html}) are the seed of the UI-event
// contract that step will formalize.
//
// Loaded before resources/js/app.js (which starts Alpine) so the
// alpine:init listener is registered in time.

function gaEscapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('gaWorkspace', () => ({
        // 'chat' is the default view on the phone (Layout A); on desktop the
        // chat rail is always visible and the main panel falls back to the
        // Battlefield pane while tab === 'chat'.
        tab: 'chat',
        cpChoice: null,
        draft: '',
        extra: [],
        attachments: [],

        show(tab) {
            this.tab = tab;
        },

        decide(label, effect) {
            if (this.cpChoice !== null) {
                return;
            }
            this.cpChoice = label;
            this.extra.push({
                kind: 'agent',
                who: 'Agent · Assessment protocol',
                html:
                    'Recorded. The battlefield and outline now treat that claim as <b>' +
                    effect +
                    '</b>. Next: computation at pleading mode, then I will show you the full simulation — including the attack I would run against us if I were opposing counsel.',
            });
            this.scrollChats();
        },

        quick(text) {
            this.extra.push({ kind: 'user', who: 'You', html: gaEscapeHtml(text) });
            this.scrollChats();
        },

        growComposer() {
            const el = this.$refs.composer;
            if (! el) {
                return;
            }
            // Shrink back to auto first so deletions reduce the height too;
            // CSS max-height caps the growth and the box scrolls past it.
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        },

        composerKeydown(event) {
            // Desktop: Enter sends, Shift+Enter inserts a newline. On the
            // phone layout (the 820px breakpoint in workspace.css) Enter
            // stays a newline and the send button sends.
            if (event.key !== 'Enter' || event.shiftKey) {
                return;
            }
            if (window.matchMedia('(max-width: 820px)').matches) {
                return;
            }
            event.preventDefault();
            this.send();
        },

        send() {
            const text = this.draft.trim();
            if (text === '') {
                return;
            }
            this.extra.push({ kind: 'user', who: 'You', html: gaEscapeHtml(text).replace(/\n/g, '<br>') });
            this.draft = '';
            this.$nextTick(() => this.growComposer());
            this.tab = 'chat';
            this.scrollChats();
        },

        attachSelected(event) {
            const file = event.target.files && event.target.files[0];
            event.target.value = '';
            if (!file) {
                return;
            }
            // Composer C: the pick also lands as a chip in the composer
            // flow area (above the action strip); the thread record below
            // is unchanged from Slice 1.
            this.attachments.push(file.name);
            this.extra.push({
                kind: 'sys',
                who: null,
                html:
                    'Attachment selected: ' +
                    gaEscapeHtml(file.name) +
                    ' — preview only; nothing is uploaded or stored in this slice.',
            });
            this.tab = 'chat';
            this.scrollChats();
        },

        scrollChats() {
            this.$nextTick(() => {
                document.querySelectorAll('[data-ga-chat-scroll]').forEach((el) => {
                    el.scrollTop = el.scrollHeight;
                });
            });
        },
    }));
});
