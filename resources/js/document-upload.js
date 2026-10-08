/**
 * document-upload.js — spec 007 T-10 (05-ui.md §02, mockup §02, 007-D05).
 *
 * One flow for single-shot and chunked uploads:
 * - files ≤ maxSingleBytes: XHR POST to documents.upload with progress.
 * - larger files: init → per-chunk PUT (8 MiB, resume via the session
 *   bitmap) → complete with total SHA-256 verification (007-D05).
 *
 * Stage text follows the pipeline honestly: uploading → uploaded/scan
 * queued. The server-side pipeline (scan → extract/OCR → index) runs
 * async; the row links to the document list for follow-up.
 */
(() => {
    'use strict';

    const cfg = window.__uploadConfig || {};
    const drop = document.getElementById('dz-drop');
    const input = document.getElementById('dz-input');
    const choose = document.getElementById('dz-choose');
    const rows = document.getElementById('dz-rows');
    const folderSel = document.getElementById('dz-folder');
    const tagsInput = document.getElementById('dz-tags');
    if (!drop || !cfg.uploadUrl) return;

    const CHUNK = cfg.chunkBytes || 8 * 1024 * 1024;
    const MAX_SINGLE = cfg.maxSingleBytes || 100 * 1024 * 1024;

    const fill = (tpl, vars) => {
        let u = tpl;
        for (const [k, v] of Object.entries(vars)) u = u.replace(k, encodeURIComponent(v));
        return u;
    };
    const fmtMB = (n) => (n / 1048576).toFixed(n >= 104857600 ? 0 : 1) + ' MB';
    const meta = () => ({
        folder_id: folderSel && folderSel.value ? folderSel.value : null,
        tags: tagsInput && tagsInput.value
            ? tagsInput.value.split(',').map((t) => t.trim()).filter(Boolean).slice(0, 20)
            : [],
    });

    // ------------------------------------------------------------------
    // Row UI
    // ------------------------------------------------------------------
    function makeRow(file) {
        const el = document.createElement('div');
        el.className = 'rounded-vl-control border border-vl-line bg-vl-card px-4 py-3';
        el.innerHTML =
            '<div class="flex items-center justify-between gap-3">' +
            '<div class="fn text-[14px] font-semibold text-vl-ink break-words"></div>' +
            '<div class="pct text-[13px] text-vl-mut whitespace-nowrap"></div>' +
            '</div>' +
            '<div class="prog mt-2 h-2 rounded-full bg-vl-line overflow-hidden" role="progressbar" aria-label="Upload progress">' +
            '<i class="block h-full bg-vl-brass rounded-full transition-all" style="width:0%"></i></div>' +
            '<div class="st-line mt-1.5 text-[13px] text-vl-mut"></div>';
        el.querySelector('.fn').textContent = file.name + ' · ' + fmtMB(file.size);
        rows.prepend(el);
        return {
            el,
            set(pct, stage) {
                el.querySelector('.prog i').style.width = Math.min(100, Math.max(0, pct)) + '%';
                el.querySelector('.pct').textContent = Math.round(pct) + '%';
                if (stage !== undefined) el.querySelector('.st-line').textContent = stage;
            },
            done(stage, docUrl) {
                this.set(100, stage);
                el.querySelector('.prog i').classList.replace('bg-vl-brass', 'bg-vl-ok');
                if (docUrl) {
                    const a = document.createElement('a');
                    a.href = docUrl;
                    a.className = 'underline underline-offset-2 text-vl-ink';
                    a.textContent = 'View in Documents';
                    const line = el.querySelector('.st-line');
                    line.appendChild(document.createTextNode(' — '));
                    line.appendChild(a);
                }
            },
            fail(stage) {
                el.querySelector('.prog i').classList.replace('bg-vl-brass', 'bg-vl-bad');
                el.querySelector('.st-line').textContent = stage;
            },
        };
    }

    const docIndexUrl = cfg.uploadUrl.replace(/\/upload$/, '');

    // ------------------------------------------------------------------
    // Incremental SHA-256 (FIPS 180-4) over file slices — multi-hundred-MB
    // files never sit fully in memory. Any mismatch surfaces as the
    // server's 422 hash_mismatch (session retained for retry).
    // ------------------------------------------------------------------
    const SHA_K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];
    const rotr = (x, n) => (x >>> n) | (x << (32 - n));

    function shaNew() {
        return {
            h: [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19],
            buf: new Uint8Array(0),
            len: 0,
        };
    }
    function shaCompress(st, b, off) {
        const w = new Array(64);
        for (let i = 0; i < 16; i++) {
            w[i] = (b[off + i * 4] << 24) | (b[off + i * 4 + 1] << 16) | (b[off + i * 4 + 2] << 8) | b[off + i * 4 + 3];
        }
        for (let i = 16; i < 64; i++) {
            const s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
            const s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
        }
        let [a, b_, c, d, e, f, g, h] = st.h;
        for (let i = 0; i < 64; i++) {
            const S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
            const ch = (e & f) ^ (~e & g);
            const t1 = (h + S1 + ch + SHA_K[i] + w[i]) | 0;
            const S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
            const maj = (a & b_) ^ (a & c) ^ (b_ & c);
            const t2 = (S0 + maj) | 0;
            h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b_; b_ = a; a = (t1 + t2) | 0;
        }
        for (let i = 0; i < 8; i++) st.h[i] = (st.h[i] + [a, b_, c, d, e, f, g, h][i]) | 0;
    }
    function shaUpdate(st, data) {
        const joined = new Uint8Array(st.buf.length + data.length);
        joined.set(st.buf, 0);
        joined.set(data, st.buf.length);
        let off = 0;
        while (joined.length - off >= 64) { shaCompress(st, joined, off); off += 64; }
        st.buf = joined.slice(off);
        st.len += data.length;
    }
    function shaHex(st) {
        const bitLen = st.len * 8;
        const pad = new Uint8Array(1 + 64);
        pad[0] = 0x80;
        const mod = (st.buf.length + 1) % 64;
        const zeros = mod <= 56 ? 56 - mod : 120 - mod;
        const tail = new Uint8Array(st.buf.length + 1 + zeros + 8);
        tail.set(st.buf, 0);
        tail.set(pad.subarray(0, 1 + zeros), st.buf.length);
        const dv = new DataView(tail.buffer);
        dv.setUint32(tail.length - 8, Math.floor(bitLen / 0x100000000), false);
        dv.setUint32(tail.length - 4, bitLen >>> 0, false);
        for (let off = 0; off < tail.length; off += 64) shaCompress(st, tail, off);
        return st.h.map((x) => (x >>> 0).toString(16).padStart(8, '0')).join('');
    }

    // ------------------------------------------------------------------
    // Transports
    // ------------------------------------------------------------------
    function csrfHeaders(extra) {
        return Object.assign({ 'X-CSRF-TOKEN': cfg.csrf || '', 'Accept': 'application/json' }, extra || {});
    }
    async function readJson(res) {
        try { return await res.json(); } catch (e) { return {}; }
    }
    function errStage(res, body) {
        const code = (body && body.code) || ('http_' + res.status);
        const map = {
            upload_too_large: 'Too large for single upload — retry as chunks.',
            hash_mismatch: 'Checksum mismatch — chunks retained, safe to retry.',
            quarantined: 'Quarantined by the malware scan.',
        };
        return map[code] || ('Upload failed (' + code + ').');
    }

    function uploadSingle(file, row) {
        return new Promise((resolve) => {
            const m = meta();
            const fd = new FormData();
            fd.append('file', file, file.name);
            if (m.folder_id) fd.append('folder_id', m.folder_id);
            fd.append('title', file.name);
            m.tags.forEach((t) => fd.append('tags[]', t));

            const xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.uploadUrl, true);
            xhr.setRequestHeader('X-CSRF-TOKEN', cfg.csrf || '');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) row.set((e.loaded / e.total) * 100, 'Uploading…');
            };
            xhr.onload = () => {
                let body = {};
                try { body = JSON.parse(xhr.responseText || '{}'); } catch (e) { body = {}; }
                if (xhr.status === 201) {
                    row.done('Uploaded — scanning for malware…', docIndexUrl);
                } else if (xhr.status === 200 && body.notice === 'identical_bytes_already_stored') {
                    row.done('Identical bytes already stored — linked the existing document.', docIndexUrl);
                } else {
                    row.fail(errStage({ status: xhr.status }, body));
                }
                resolve();
            };
            xhr.onerror = () => { row.fail('Network error — retry.'); resolve(); };
            row.set(0, 'Uploading…');
            xhr.send(fd);
        });
    }

    async function uploadChunked(file, row) {
        const m = meta();
        try {
            row.set(0, 'Hashing file (SHA-256)…');
            const st = shaNew();
            const total = Math.ceil(file.size / CHUNK);
            const slices = [];
            for (let n = 0; n < total; n++) {
                const slice = file.slice(n * CHUNK, Math.min(file.size, (n + 1) * CHUNK));
                const bytes = new Uint8Array(await slice.arrayBuffer());
                shaUpdate(st, bytes);
                slices.push(bytes);
            }
            const sha = shaHex(st);

            row.set(1, 'Starting resumable session…');
            let res = await fetch(cfg.initUrl, {
                method: 'POST',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ filename: file.name, size: file.size, sha256: sha, mime_hint: file.type || null }),
            });
            let body = await readJson(res);
            if (res.status !== 201) { row.fail(errStage(res, body)); return; }
            const session = body.data;
            const sessionId = session.id;
            const received = new Set(session.received_chunks || []);

            for (let n = 0; n < total; n++) {
                if (received.has(n)) {
                    row.set(((n + 1) / total) * 100, 'Uploading chunk ' + (n + 1) + ' of ' + total + ' (already received — skipped)');
                    continue;
                }
                row.set((n / total) * 100, 'Uploading chunk ' + (n + 1) + ' of ' + total + ' — resumes automatically if interrupted');
                res = await fetch(fill(cfg.chunkUrlTemplate, { SESSION: sessionId, N: String(n) }), {
                    method: 'PUT',
                    headers: csrfHeaders({ 'Content-Type': 'application/octet-stream' }),
                    body: slices[n],
                });
                body = await readJson(res);
                if (res.status === 409) { row.fail('Chunk conflict — retry the upload.'); return; }
                if (res.status !== 200) { row.fail(errStage(res, body)); return; }
            }

            row.set(99, 'Verifying checksum…');
            res = await fetch(fill(cfg.completeUrlTemplate, { SESSION: sessionId }), {
                method: 'POST',
                headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({
                    title: file.name,
                    folder_id: m.folder_id,
                    tags: m.tags,
                }),
            });
            body = await readJson(res);
            if (res.status === 201 || res.status === 200) {
                row.done('Uploaded — scanning for malware…', docIndexUrl);
            } else {
                row.fail(errStage(res, body));
            }
        } catch (e) {
            row.fail('Network error — resume by re-adding the file.');
        }
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------
    async function handleFiles(list) {
        const files = Array.from(list || []);
        for (const file of files) {
            const row = makeRow(file);
            if (file.size <= MAX_SINGLE) {
                uploadSingle(file, row);
            } else {
                uploadChunked(file, row);
            }
        }
    }

    choose.addEventListener('click', (e) => { e.stopPropagation(); input.click(); });
    drop.addEventListener('click', () => input.click());
    drop.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', () => { handleFiles(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => {
        e.preventDefault();
        drop.classList.add('border-vl-brass');
    }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => {
        e.preventDefault();
        drop.classList.remove('border-vl-brass');
    }));
    drop.addEventListener('drop', (e) => handleFiles(e.dataTransfer && e.dataTransfer.files));
})();
