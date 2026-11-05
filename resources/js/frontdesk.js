// Spec 012 — front-desk chat interactions on the app home.
//
// The home page lives in the spec 002 app shell, not the workspace shell,
// so this file follows the page-script pattern of passkey.js /
// document-upload.js: plain DOM wiring scoped to the panel root, no Alpine
// component registration (Alpine has already started by the time a
// body-loaded script runs, and alpine:init has fired).
//
// Chat slice (2026-11-05): the desk discovers its mode from
// GET /front-desk/status — an internal signal only; nothing about the
// wiring is ever rendered (Ian's standing rule: product copy only, no
// preview/sample/not-configured commentary). In LIVE mode (Laravel
// flag on AND the agent service reachable) sends go to
// POST /front-desk/messages, which persists the exchange server-side
// and streams the agent's reply back as SSE; an in-flight turn can be
// stopped (AbortController + POST /front-desk/abort). When a turn
// cannot complete — agent unreachable, unconfigured, or errored — the
// assistant turn itself carries one plain product line
// (UNAVAILABLE below); internal error detail never reaches the page,
// and the UI never fakes a reply.

(() => {
    'use strict';

    const escapeHtml = (text) => {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    const csrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

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

        // The one failure line a user ever sees from this panel.
        const UNAVAILABLE = 'Ross isn’t available right now — please try again later.';

        // Live-mode state. mode starts 'sample' (internal status value)
        // and only flips when /front-desk/status confirms live.
        let mode = 'sample';
        let conversationId = null;
        let inFlight = null; // AbortController for the streaming turn

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

        const appendAgent = () => {
            const thread = activeThread();
            if (!thread) {
                return null;
            }
            const div = document.createElement('div');
            div.className = 'ga-msg ga-agent';
            const who = document.createElement('div');
            who.className = 'ga-who';
            who.textContent = 'Agent · Front desk';
            const body = document.createElement('span');
            div.appendChild(who);
            div.appendChild(body);
            thread.appendChild(div);
            scrollThread(thread);
            return body;
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

        // --- mode discovery: internal status only, never rendered ---
        const discoverMode = async () => {
            try {
                const res = await fetch('/front-desk/status', {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) {
                    return;
                }
                const status = await res.json();
                if (status.mode === 'live') {
                    mode = 'live';
                    try {
                        const conv = await fetch('/front-desk/conversation', {
                            headers: { Accept: 'application/json' },
                        });
                        if (conv.ok) {
                            const data = await conv.json();
                            conversationId = data.conversation?.id || null;
                        }
                    } catch {
                        // Conversation id is a resume nicety; sends work without it.
                    }
                }
            } catch {
                // Status unreachable: stay in local-only mode.
            }
        };

        // --- live send: POST the message, consume the SSE stream ---
        const sendLive = async (text) => {
            appendUser(text);
            const body = appendAgent();
            let streamed = '';
            inFlight = new AbortController();
            try {
                const res = await fetch('/front-desk/messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'text/event-stream',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ content: text }),
                    signal: inFlight.signal,
                });
                if (!res.ok || !res.body) {
                    // The server's error detail is internal; the user
                    // gets the one product line.
                    if (body) {
                        body.textContent = UNAVAILABLE;
                    }
                    return;
                }
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                for (;;) {
                    const { done, value } = await reader.read();
                    if (done) {
                        break;
                    }
                    buffer += decoder.decode(value, { stream: true });
                    let idx;
                    while ((idx = buffer.indexOf('\n\n')) >= 0) {
                        const frame = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        const line = frame.split('\n').find((l) => l.startsWith('data:'));
                        if (!line) {
                            continue;
                        }
                        let event;
                        try {
                            event = JSON.parse(line.slice(5).trim());
                        } catch {
                            continue;
                        }
                        if (event.type === 'token' && typeof event.text === 'string') {
                            streamed += event.text;
                            if (body) {
                                body.textContent = streamed;
                                scrollThread(activeThread());
                            }
                        } else if (event.type === 'error') {
                            if (body && streamed === '') {
                                body.textContent = UNAVAILABLE;
                            }
                        } else if (event.type === 'persisted' && event.message?.id) {
                            conversationId = conversationId || null;
                        }
                    }
                }
                if (body && streamed === '' && body.textContent === '') {
                    body.textContent = UNAVAILABLE;
                }
            } catch (err) {
                if (err && err.name === 'AbortError') {
                    appendSys('Stopped.');
                } else if (body) {
                    body.textContent = UNAVAILABLE;
                }
            } finally {
                inFlight = null;
            }
        };

        const stopLive = () => {
            if (inFlight) {
                inFlight.abort();
                fetch('/front-desk/abort', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ conversation_id: conversationId }),
                }).catch(() => {});
            }
        };
        // Exposed for a future stop button.
        root.__fdStop = stopLive;

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
                appendSys('Route dismissed — staying at the front desk.');
            });
        }

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
                input.value = '';
                grow();
                const inSession = Boolean(session && !session.hidden);
                if (mode === 'live' && !inSession) {
                    // Live sends apply to the front-desk (desk) state; the
                    // in-session state keeps local behaviour.
                    sendLive(text);
                } else {
                    appendUser(text);
                }
            });
        }

        if (file) {
            file.addEventListener('change', () => {
                const picked = file.files && file.files[0];
                file.value = '';
                if (!picked) {
                    return;
                }
                appendSys('Attachment selected: ' + picked.name);
            });
        }

        discoverMode();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
