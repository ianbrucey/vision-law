// Spec 012 — front-desk chat interactions on the app home (UX preview only).
//
// The home page lives in the spec 002 app shell, not the workspace shell,
// so this file follows the page-script pattern of passkey.js /
// document-upload.js: plain DOM wiring scoped to the panel root, no Alpine
// component registration (Alpine has already started by the time a
// body-loaded script runs, and alpine:init has fired).
//
// Everything is client-side page state: the desk ↔ session handoff, quick
// chips, composer sends, and the attach affordance. NOTHING is sent to or
// stored on the server — chat persistence is build-sequence step 2, and
// the agent behind this UI arrives with it. Message shapes mirror
// workspace.js ({kind, who, html}) as the seed of the UI-event contract.

(() => {
    'use strict';

    const escapeHtml = (text) => {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    const init = () => {
        const root = document.querySelector('[data-ga-frontdesk]');
        if (!root) {
            return;
        }

        const desk = root.querySelector('[data-fd-state="desk"]');
        const session = root.querySelector('[data-fd-state="session"]');
        const form = root.querySelector('[data-fd-form]');
        const input = root.querySelector('[data-fd-input]');
        const file = root.querySelector('[data-fd-file]');

        const activeThread = () =>
            root.querySelector('[data-fd-state]:not([hidden]) [data-fd-thread]') ||
            root.querySelector('[data-fd-thread]');

        const scrollThread = (thread) => {
            if (thread) {
                thread.scrollTop = thread.scrollHeight;
            }
        };

        const appendUser = (text) => {
            const thread = activeThread();
            if (!thread) {
                return;
            }
            const div = document.createElement('div');
            div.className = 'ga-msg ga-user';
            div.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');
            thread.appendChild(div);
            scrollThread(thread);
        };

        const appendSys = (text) => {
            const thread = activeThread();
            if (!thread) {
                return;
            }
            const div = document.createElement('div');
            div.className = 'ga-sysmsg';
            div.textContent = text;
            thread.appendChild(div);
            scrollThread(thread);
        };

        // --- desk ↔ session handoff (in place; no navigation) ---
        const openBtn = root.querySelector('[data-fd-open]');
        if (openBtn && desk && session) {
            openBtn.addEventListener('click', () => {
                desk.hidden = true;
                session.hidden = false;
                scrollThread(session.querySelector('[data-fd-thread]'));
                if (input) {
                    input.focus();
                }
            });
        }

        const backBtn = root.querySelector('[data-fd-back]');
        if (backBtn && desk && session) {
            backBtn.addEventListener('click', () => {
                session.hidden = true;
                desk.hidden = false;
                scrollThread(desk.querySelector('[data-fd-thread]'));
                if (input) {
                    input.focus();
                }
            });
        }

        const dismissBtn = root.querySelector('[data-fd-dismiss]');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                const card = root.querySelector('[data-fd-routecard]');
                if (card) {
                    card.hidden = true;
                }
                appendSys('Route dismissed — staying at the front desk. Nothing was opened or written anywhere.');
            });
        }

        // --- quick chips: tapping one answers as the user ---
        root.querySelectorAll('[data-fd-chip]').forEach((chip) => {
            chip.addEventListener('click', () => {
                const text = chip.getAttribute('data-fd-chip');
                if (text) {
                    appendUser(text);
                }
            });
        });

        // --- composer: auto-grow, same contract as the workspace ---
        const grow = () => {
            if (!input) {
                return;
            }
            // Shrink back to auto first so deletions reduce the height too;
            // the CSS max-height caps growth and the box scrolls past it.
            input.style.height = 'auto';
            input.style.height = input.scrollHeight + 'px';
        };

        if (input) {
            input.addEventListener('input', grow);
            input.addEventListener('keydown', (event) => {
                // Desktop: Enter sends, Shift+Enter inserts a newline. On
                // the phone layout (the 820px breakpoint in workspace.css)
                // Enter stays a newline and the send button sends.
                if (event.key !== 'Enter' || event.shiftKey) {
                    return;
                }
                if (window.matchMedia('(max-width: 820px)').matches) {
                    return;
                }
                event.preventDefault();
                if (form) {
                    form.requestSubmit();
                }
            });
        }

        if (form && input) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const text = input.value.trim();
                if (text === '') {
                    return;
                }
                appendUser(text);
                input.value = '';
                grow();
            });
        }

        if (file) {
            file.addEventListener('change', () => {
                const picked = file.files && file.files[0];
                file.value = '';
                if (!picked) {
                    return;
                }
                appendSys(
                    'Attachment selected: ' +
                        picked.name +
                        ' — preview only; nothing is uploaded or stored in this slice.'
                );
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
