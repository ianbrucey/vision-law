// Spec 012 — home dashboard shell interactions (UX slice).
//
// Plain DOM wiring scoped to the dashboard root, following the
// frontdesk.js / passkey.js page-script pattern (no Alpine registration:
// app.js is not loaded by the dashboard layout, and everything here is
// page state only). Responsibilities:
//
// - sidebar collapse (desktop), persisted in localStorage;
// - the chat column's drag-resize, persisted in localStorage;
// - the phone bottom-nav tab state (Chat default) — CSS does the
//   showing/hiding at the 820px breakpoint; without JS every panel
//   simply stacks, so nothing is ever unreachable;
// - the matter-card filter (text + stage) over the server-rendered
//   cards. Filtering never touches the server.
//
// Nothing here is stored or sent anywhere else; chat persistence and
// the agent are later build-sequence steps.

(() => {
    'use strict';

    const SIDE_KEY = 'vl-dash-side';
    const CHAT_KEY = 'vl-dash-chatw';

    const init = () => {
        const root = document.querySelector('[data-ga-dash]');
        if (!root) {
            return;
        }

        // --- sidebar collapse (persisted) ---
        const shell = root.querySelector('[data-ga-dash-shell]');
        const collapseBtn = root.querySelector('[data-dash-collapse]');
        if (shell && collapseBtn) {
            let collapsed = false;
            try {
                collapsed = window.localStorage.getItem(SIDE_KEY) === 'collapsed';
            } catch (e) {
                collapsed = false;
            }
            shell.classList.toggle('ga-dash-collapsed', collapsed);
            collapseBtn.addEventListener('click', () => {
                const on = shell.classList.toggle('ga-dash-collapsed');
                collapseBtn.innerHTML = on
                    ? '⟩'
                    : '⟨ <span class="ga-dash-lbl">Collapse</span>';
                try {
                    window.localStorage.setItem(SIDE_KEY, on ? 'collapsed' : 'open');
                } catch (e) {
                    /* private mode: collapse just doesn't persist */
                }
            });
            if (collapsed) {
                collapseBtn.textContent = '⟩';
            }
        }

        // --- chat column drag-resize (persisted) ---
        const grid = root.querySelector('[data-ga-dash-grid]');
        const drag = root.querySelector('[data-dash-drag]');
        if (grid && drag) {
            const applyWidth = (w) => {
                const clamped = Math.min(580, Math.max(300, w));
                grid.style.setProperty('--ga-dash-chatw', clamped + 'px');
                return clamped;
            };
            try {
                const saved = parseInt(window.localStorage.getItem(CHAT_KEY) || '', 10);
                if (!Number.isNaN(saved)) {
                    applyWidth(saved);
                }
            } catch (e) {
                /* no persistence available */
            }

            let dragging = false;
            drag.addEventListener('pointerdown', (event) => {
                dragging = true;
                drag.classList.add('ga-on');
                drag.setPointerCapture(event.pointerId);
            });
            drag.addEventListener('pointermove', (event) => {
                if (!dragging) {
                    return;
                }
                // The chat is the RIGHT-most column: its width is the
                // distance from the pointer to the grid's right edge.
                const rect = grid.getBoundingClientRect();
                applyWidth(rect.right - event.clientX);
            });
            const endDrag = () => {
                if (!dragging) {
                    return;
                }
                dragging = false;
                drag.classList.remove('ga-on');
                const current = grid.style.getPropertyValue('--ga-dash-chatw');
                if (current) {
                    try {
                        window.localStorage.setItem(CHAT_KEY, String(parseInt(current, 10)));
                    } catch (e) {
                        /* no persistence available */
                    }
                }
            };
            drag.addEventListener('pointerup', endDrag);
            drag.addEventListener('pointercancel', endDrag);
        }

        // --- phone bottom-nav tabs (Chat is the default) ---
        const tabBtns = root.querySelectorAll('[data-dash-tab]');
        if (tabBtns.length > 0) {
            const setTab = (name) => {
                root.setAttribute('data-dash-tab', name);
                tabBtns.forEach((btn) => {
                    const on = btn.getAttribute('data-dash-tab') === name;
                    btn.classList.toggle('ga-on', on);
                    btn.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            };
            tabBtns.forEach((btn) => {
                btn.addEventListener('click', () => {
                    setTab(btn.getAttribute('data-dash-tab'));
                    window.scrollTo(0, 0);
                });
            });
            setTab('chat');
        }

        // --- matter-card filter (text + stage) ---
        const search = root.querySelector('[data-dash-search]');
        const stage = root.querySelector('[data-dash-stage]');
        const cards = root.querySelectorAll('[data-dash-card]');
        const filterEmpty = root.querySelector('[data-dash-filterempty]');
        if ((search || stage) && cards.length > 0) {
            const applyFilter = () => {
                const q = (search ? search.value : '').trim().toLowerCase();
                const st = stage ? stage.value : '';
                let visible = 0;
                cards.forEach((card) => {
                    const text = (card.getAttribute('data-search') || '').toLowerCase();
                    const show =
                        (q === '' || text.includes(q)) &&
                        (st === '' || card.getAttribute('data-stage') === st);
                    card.style.display = show ? '' : 'none';
                    if (show) {
                        visible += 1;
                    }
                });
                if (filterEmpty) {
                    filterEmpty.hidden = visible !== 0;
                }
            };
            if (search) {
                search.addEventListener('input', applyFilter);
            }
            if (stage) {
                stage.addEventListener('change', applyFilter);
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
