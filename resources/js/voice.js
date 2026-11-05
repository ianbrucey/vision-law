// Spec 012 — voice mode for both chat composers (dashboard front desk
// and matter workspace), shared by both surfaces.
//
// Ian's design (2026-11-05, mockup chat-voice-mode-2026-11-05.html): tap
// the mic and the composer (field + strip) swaps for a recording bar —
// trash far left (discard, no upload), a live waveform driven by the
// actual microphone level (WebAudio AnalyserNode, never a CSS fake), a
// counting timer, a stop button, and the send arrow at the right edge.
//
// Local-first (Ian's standing rule): the recording lives ONLY in this
// browser (MediaRecorder chunks in memory) until stop/send is pressed.
// Nothing streams anywhere during recording. Stop (or the send arrow —
// a transcript must exist before anything can be sent, so it behaves
// identically) uploads the finished blob ONCE to POST /voice/transcribe
// (Laravel → Mistral) and inserts the returned transcript into the
// field for editing. It NEVER auto-sends. If the upload fails, the blob
// is kept and the bar offers Retry (same blob) / Trash — the audio is
// never silently dropped.

(() => {
    'use strict';

    const csrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const formatTime = (secs) =>
        Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');

    const EXTENSIONS = {
        'audio/webm': 'webm',
        'audio/ogg': 'ogg',
        'audio/mp4': 'm4a',
        'video/mp4': 'mp4',
        'audio/mpeg': 'mp3',
    };

    const attach = (form) => {
        const field = form.querySelector('textarea');
        const strip = form.querySelector('.ga-composer-strip');
        const chips = form.querySelector('.ga-attachchips');
        const mic = form.querySelector('[data-ga-mic]');
        const bar = form.querySelector('[data-ga-recbar]');
        if (!field || !strip || !mic || !bar) {
            return;
        }

        const main = bar.querySelector('[data-ga-rec-main]');
        const busy = bar.querySelector('[data-ga-rec-busy]');
        const errorBox = bar.querySelector('[data-ga-rec-error]');
        const errorMsg = bar.querySelector('[data-ga-rec-errormsg]');
        const wave = bar.querySelector('[data-ga-rec-wave]');
        const timer = bar.querySelector('[data-ga-rec-timer]');

        // Waveform bars are built once; heights are set per animation
        // frame from real frequency data while recording.
        const BAR_COUNT = 26;
        const bars = [];
        for (let i = 0; i < BAR_COUNT; i++) {
            const b = document.createElement('i');
            b.style.height = '5px';
            wave.appendChild(b);
            bars.push(b);
        }

        let state = 'idle'; // idle | recording | transcribing | error
        let stream = null;
        let recorder = null;
        let chunks = [];
        let mimeUsed = '';
        let blob = null;
        let discardOnStop = false;
        let audioCtx = null;
        let analyser = null;
        let rafId = null;
        let timerId = null;
        let startedAt = 0;

        const showOnly = (which) => {
            main.hidden = which !== 'main';
            busy.hidden = which !== 'busy';
            errorBox.hidden = which !== 'error';
        };

        const enterBar = () => {
            field.style.display = 'none';
            strip.style.display = 'none';
            if (chips) {
                chips.style.display = 'none';
            }
            bar.classList.add('on');
            showOnly('main');
        };

        const exitBar = () => {
            bar.classList.remove('on');
            field.style.display = '';
            strip.style.display = '';
            if (chips) {
                chips.style.display = '';
            }
        };

        const stopAudioGraph = () => {
            if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
            if (timerId !== null) {
                clearInterval(timerId);
                timerId = null;
            }
            if (stream) {
                stream.getTracks().forEach((t) => t.stop());
                stream = null;
            }
            if (audioCtx) {
                audioCtx.close().catch(() => {});
                audioCtx = null;
            }
            analyser = null;
        };

        const drawWave = () => {
            if (!analyser) {
                return;
            }
            const data = new Uint8Array(analyser.frequencyBinCount);
            analyser.getByteFrequencyData(data);
            // Skip the lowest bins (rumble) and sample evenly.
            const usable = Math.floor(data.length * 0.72);
            bars.forEach((b, i) => {
                const idx = 2 + Math.floor((i / BAR_COUNT) * usable);
                const v = data[idx] / 255;
                b.style.height = (4 + Math.round(v * 22)) + 'px';
            });
            rafId = requestAnimationFrame(drawWave);
        };

        const pickMime = () => {
            const candidates = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/ogg',
                'audio/mp4',
                'video/mp4',
            ];
            for (const c of candidates) {
                if (window.MediaRecorder && MediaRecorder.isTypeSupported(c)) {
                    return c;
                }
            }
            return '';
        };

        const showError = (message, canRetry) => {
            state = 'error';
            errorMsg.textContent = message;
            const retry = bar.querySelector('[data-ga-rec-retry]');
            if (retry) {
                retry.hidden = !canRetry;
            }
            showOnly('error');
        };

        const insertTranscript = (text) => {
            const trimmed = (text || '').trim();
            const current = field.value;
            if (trimmed !== '') {
                field.value =
                    current === '' || /\s$/.test(current)
                        ? current + trimmed
                        : current + ' ' + trimmed;
            }
            // 'input' drives both integrations: the front desk's plain
            // auto-grow listener and Alpine's x-model in the workspace.
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.focus();
        };

        const upload = async () => {
            state = 'transcribing';
            showOnly('busy');
            const base = (blob.type || mimeUsed).split(';')[0];
            const ext = EXTENSIONS[base] || 'webm';
            const body = new FormData();
            body.append('audio', blob, 'recording.' + ext);
            try {
                const res = await fetch('/voice/transcribe', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken(),
                        Accept: 'application/json',
                    },
                    body,
                });
                if (!res.ok) {
                    throw new Error('transcribe failed: ' + res.status);
                }
                const json = await res.json();
                blob = null;
                state = 'idle';
                exitBar();
                insertTranscript(json.text || '');
            } catch (e) {
                // The blob survives — Retry re-uploads the same audio.
                showError('Couldn’t transcribe that — try again', true);
            }
        };

        const finishRecording = () => {
            if (!recorder || recorder.state === 'inactive') {
                return;
            }
            recorder.stop(); // onstop continues the flow
        };

        const onRecorderStop = () => {
            stopAudioGraph();
            recorder = null;
            if (discardOnStop) {
                chunks = [];
                blob = null;
                state = 'idle';
                exitBar();
                return;
            }
            blob = new Blob(chunks, { type: mimeUsed || undefined });
            chunks = [];
            upload();
        };

        const start = async () => {
            if (state !== 'idle') {
                return;
            }
            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                enterBar();
                showError('Microphone access is needed to record', false);
                return;
            }
            enterBar();
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                showError('Microphone access is needed to record', false);
                return;
            }

            mimeUsed = pickMime();
            chunks = [];
            blob = null;
            discardOnStop = false;
            try {
                recorder = new MediaRecorder(stream, mimeUsed ? { mimeType: mimeUsed } : undefined);
            } catch (e) {
                stopAudioGraph();
                showError('Microphone access is needed to record', false);
                return;
            }
            recorder.ondataavailable = (event) => {
                if (event.data && event.data.size > 0) {
                    chunks.push(event.data);
                }
            };
            recorder.onstop = onRecorderStop;

            // Real level data for the waveform.
            try {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                analyser = audioCtx.createAnalyser();
                analyser.fftSize = 64;
                audioCtx.createMediaStreamSource(stream).connect(analyser);
                drawWave();
            } catch (e) {
                analyser = null; // bars stay at rest height; recording works
            }

            state = 'recording';
            timer.textContent = '0:00';
            startedAt = Date.now();
            timerId = setInterval(() => {
                timer.textContent = formatTime(Math.floor((Date.now() - startedAt) / 1000));
            }, 250);
            recorder.start(250);
        };

        mic.addEventListener('click', start);

        bar.querySelector('[data-ga-rec-trash]').addEventListener('click', () => {
            if (state === 'recording') {
                discardOnStop = true;
                finishRecording();
            } else {
                blob = null;
                state = 'idle';
                exitBar();
            }
        });

        const stopHandler = () => {
            if (state === 'recording') {
                finishRecording();
            }
        };
        bar.querySelector('[data-ga-rec-stop]').addEventListener('click', stopHandler);
        // Send while recording: a transcript must exist before anything
        // can be sent, so the arrow behaves exactly like stop.
        bar.querySelector('[data-ga-rec-send]').addEventListener('click', stopHandler);

        bar.querySelector('[data-ga-rec-retry]').addEventListener('click', () => {
            if (blob) {
                upload();
            }
        });
        bar.querySelector('[data-ga-rec-discard]').addEventListener('click', () => {
            blob = null;
            state = 'idle';
            exitBar();
        });
    };

    document.querySelectorAll('.ga-composer[data-ga-voice]').forEach(attach);
})();
