/**
 * PDF preview viewer (spec 007 T-03, DOC-05).
 *
 * pdf.js is bundled locally via npm (pdfjs-dist) — no CDN. Features:
 * page navigation, lazy thumbnail strip, zoom 50–400%, find-in-page
 * with highlighted matches, and selectable text (pdf.js TextLayer).
 *
 * The file URL is an HMAC-signed URL (15-min, scoped to the user);
 * every fetch — including pdf.js range requests — re-checks permission
 * server-side, so revoking access kills the viewer immediately.
 */
import * as pdfjsLib from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

const config = window.__previewConfig || {};
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 4.0;

const state = {
  pdf: null,
  pageNum: 1,
  scale: 1,
  fitMode: true,
  textCache: new Map(),
  find: { query: "", matches: [], current: -1 },
  rendering: false,
};

const $ = (id) => document.getElementById(id);

async function init() {
  if (!config.fileUrl) return;
  try {
    state.pdf = await pdfjsLib.getDocument({
      url: config.fileUrl,
      withCredentials: true,
    }).promise;
  } catch (e) {
    showFatal("Could not load the preview. The file may be unavailable — try downloading it instead.");
    return;
  }

  $("pv-page-count").textContent = state.pdf.numPages;
  $("pv-page-input").max = state.pdf.numPages;

  const startPage = parseInt(new URLSearchParams(location.search).get("page") || "1", 10);
  state.pageNum = Math.min(Math.max(startPage || 1, 1), state.pdf.numPages);

  buildThumbnails();
  await renderPage(state.pageNum);
  wireControls();
}

function showFatal(message) {
  const wrap = $("pv-page-wrap");
  wrap.innerHTML = "";
  const p = document.createElement("p");
  p.className = "p-6 text-center text-vl-mut text-[14.5px]";
  p.textContent = message;
  wrap.appendChild(p);
}

/** Scale that fits the page width into the scroll container. */
async function fitScale(pageNum) {
  const page = await state.pdf.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1 });
  const containerWidth = $("pv-scroll").clientWidth - 24; // padding
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, containerWidth / viewport.width));
}

async function renderPage(num) {
  if (state.rendering || !state.pdf) return;
  state.rendering = true;
  try {
    state.pageNum = num;
    const page = await state.pdf.getPage(num);

    if (state.fitMode) {
      state.scale = await fitScale(num);
    }

    const viewport = page.getViewport({ scale: state.scale });
    const outputScale = window.devicePixelRatio || 1;

    const wrap = $("pv-page-wrap");
    wrap.innerHTML = "";
    wrap.style.width = `${Math.floor(viewport.width)}px`;
    wrap.style.height = `${Math.floor(viewport.height)}px`;

    const canvas = document.createElement("canvas");
    canvas.width = Math.floor(viewport.width * outputScale);
    canvas.height = Math.floor(viewport.height * outputScale);
    canvas.style.width = `${Math.floor(viewport.width)}px`;
    canvas.style.height = `${Math.floor(viewport.height)}px`;
    wrap.appendChild(canvas);

    const textLayerDiv = document.createElement("div");
    textLayerDiv.className = "pv-text-layer";
    textLayerDiv.style.width = `${Math.floor(viewport.width)}px`;
    textLayerDiv.style.height = `${Math.floor(viewport.height)}px`;
    wrap.appendChild(textLayerDiv);

    await page.render({
      canvasContext: canvas.getContext("2d"),
      viewport,
      transform: outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : undefined,
    }).promise;

    const textContent = await page.getTextContent();
    state.textCache.set(num, textContent);
    const textLayer = new pdfjsLib.TextLayer({
      textContentSource: textContent,
      container: textLayerDiv,
      viewport,
    });
    await textLayer.render();

    applyFindHighlights();
    updateChrome();
    markActiveThumb();
  } finally {
    state.rendering = false;
  }
}

function updateChrome() {
  $("pv-page-input").value = state.pageNum;
  $("pv-zoom-label").textContent = `${Math.round(state.scale * 100)}%`;
  $("pv-prev").disabled = state.pageNum <= 1;
  $("pv-next").disabled = state.pageNum >= state.pdf.numPages;
}

// ------------------------------------------------------------------
// Thumbnails (lazy)
// ------------------------------------------------------------------

function buildThumbnails() {
  const strip = $("pv-thumb-strip");
  strip.innerHTML = "";
  for (let i = 1; i <= state.pdf.numPages; i++) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pv-thumb block w-full rounded border border-vl-line overflow-hidden bg-white min-h-[44px]";
    btn.dataset.page = String(i);
    btn.setAttribute("aria-label", `Go to page ${i}`);
    const canvas = document.createElement("canvas");
    canvas.dataset.rendered = "false";
    btn.appendChild(canvas);
    const label = document.createElement("span");
    label.className = "block text-[11px] text-vl-mut py-0.5";
    label.textContent = String(i);
    btn.appendChild(label);
    btn.addEventListener("click", () => renderPage(i));
    strip.appendChild(btn);
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        const canvas = entry.target.querySelector("canvas");
        observer.unobserve(entry.target);
        if (canvas.dataset.rendered === "true") continue;
        canvas.dataset.rendered = "true";
        renderThumb(canvas, parseInt(entry.target.dataset.page, 10));
      }
    },
    { root: strip, rootMargin: "200px" }
  );
  strip.querySelectorAll(".pv-thumb").forEach((el) => observer.observe(el));
}

async function renderThumb(canvas, pageNum) {
  try {
    const page = await state.pdf.getPage(pageNum);
    const viewport = page.getViewport({ scale: 0.25 });
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = "100%";
    canvas.style.height = "auto";
    await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
  } catch {
    /* thumbnail failure is cosmetic */
  }
}

function markActiveThumb() {
  document.querySelectorAll(".pv-thumb").forEach((el) => {
    const active = parseInt(el.dataset.page, 10) === state.pageNum;
    el.classList.toggle("ring-2", active);
    el.classList.toggle("ring-vl-ink", active);
    if (active) el.scrollIntoView({ block: "nearest" });
  });
}

// ------------------------------------------------------------------
// Zoom
// ------------------------------------------------------------------

function setZoom(scale, fitMode = false) {
  state.fitMode = fitMode;
  state.scale = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, scale));
  renderPage(state.pageNum);
}

// ------------------------------------------------------------------
// Find in page
// ------------------------------------------------------------------

async function textContentFor(pageNum) {
  if (state.textCache.has(pageNum)) return state.textCache.get(pageNum);
  const page = await state.pdf.getPage(pageNum);
  const tc = await page.getTextContent();
  state.textCache.set(pageNum, tc);
  return tc;
}

async function runFind() {
  const query = $("pv-find").value.trim().toLowerCase();
  const find = state.find;
  find.query = query;
  find.matches = [];
  find.current = -1;

  if (query !== "") {
    for (let p = 1; p <= state.pdf.numPages; p++) {
      const tc = await textContentFor(p);
      tc.items.forEach((item, idx) => {
        const str = String(item.str || "");
        const lower = str.toLowerCase();
        let pos = 0;
        for (;;) {
          const at = lower.indexOf(query, pos);
          if (at < 0) break;
          find.matches.push({ page: p, itemIndex: idx, start: at, end: at + query.length });
          pos = at + query.length;
        }
      });
    }
  }

  const status = $("pv-find-status");
  if (query === "") {
    status.classList.add("hidden");
  } else {
    status.classList.remove("hidden");
    status.textContent = find.matches.length === 0
      ? `No matches for “${$("pv-find").value.trim()}”`
      : `${find.matches.length} match${find.matches.length === 1 ? "" : "es"}`;
  }

  if (find.matches.length > 0) {
    await goToMatch(0);
  } else {
    applyFindHighlights();
  }
}

async function goToMatch(i) {
  const find = state.find;
  if (find.matches.length === 0) return;
  find.current = ((i % find.matches.length) + find.matches.length) % find.matches.length;
  const m = find.matches[find.current];
  if (m.page !== state.pageNum) {
    await renderPage(m.page);
  } else {
    applyFindHighlights();
  }
  const status = $("pv-find-status");
  status.textContent = `Match ${find.current + 1} of ${find.matches.length} — page ${m.page}`;
  const current = document.querySelector(".pv-match-current");
  if (current) current.scrollIntoView({ block: "center" });
}

/** Wrap query occurrences in the current page's text-layer divs. */
function applyFindHighlights() {
  document.querySelectorAll(".pv-match").forEach((el) => {
    el.replaceWith(document.createTextNode(el.textContent));
  });
  const find = state.find;
  if (find.query === "" || find.matches.length === 0) return;

  const layer = document.querySelector(".pv-text-layer");
  if (!layer) return;
  const divs = layer.children;

  find.matches.forEach((m, mi) => {
    if (m.page !== state.pageNum) return;
    const div = divs[m.itemIndex];
    if (!div) return;
    const text = div.textContent || "";
    if (m.end > text.length) return;
    const before = document.createTextNode(text.slice(0, m.start));
    const match = document.createElement("span");
    match.className = "pv-match" + (mi === find.current ? " pv-match-current" : "");
    match.textContent = text.slice(m.start, m.end);
    const after = document.createTextNode(text.slice(m.end));
    div.replaceChildren(before, match, after);
  });
}

// ------------------------------------------------------------------
// Controls
// ------------------------------------------------------------------

function wireControls() {
  $("pv-prev").addEventListener("click", () => {
    if (state.pageNum > 1) renderPage(state.pageNum - 1);
  });
  $("pv-next").addEventListener("click", () => {
    if (state.pageNum < state.pdf.numPages) renderPage(state.pageNum + 1);
  });
  $("pv-page-input").addEventListener("change", (e) => {
    const n = parseInt(e.target.value, 10);
    if (Number.isFinite(n)) renderPage(Math.min(Math.max(n, 1), state.pdf.numPages));
    else e.target.value = state.pageNum;
  });

  $("pv-zoom-in").addEventListener("click", () => setZoom(state.scale * 1.25));
  $("pv-zoom-out").addEventListener("click", () => setZoom(state.scale / 1.25));

  let findTimer = null;
  $("pv-find").addEventListener("input", () => {
    clearTimeout(findTimer);
    findTimer = setTimeout(runFind, 350);
  });
  $("pv-find").addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      goToMatch(state.find.current + 1);
    }
  });
  $("pv-find-next").addEventListener("click", () => goToMatch(state.find.current + 1));

  $("pv-thumbs").addEventListener("click", () => {
    const strip = $("pv-thumb-strip");
    const hidden = strip.classList.toggle("hidden");
    $("pv-thumbs").setAttribute("aria-pressed", String(!hidden));
  });

  document.addEventListener("keydown", (e) => {
    if (e.target.matches("input, textarea")) return;
    if (e.key === "ArrowLeft" && state.pageNum > 1) renderPage(state.pageNum - 1);
    if (e.key === "ArrowRight" && state.pageNum < state.pdf.numPages) renderPage(state.pageNum + 1);
  });
}

const style = document.createElement("style");
style.textContent = `
.pv-text-layer { position: absolute; inset: 0; overflow: hidden; line-height: 1; }
.pv-text-layer > div { position: absolute; white-space: pre; transform-origin: 0% 0%; color: transparent; }
.pv-text-layer ::selection { background: rgba(30, 100, 200, 0.35); }
.pv-match { background: rgba(255, 213, 74, 0.75); color: transparent; border-radius: 2px; }
.pv-match-current { background: rgba(255, 150, 40, 0.9); outline: 2px solid #b45309; }
`;
document.head.appendChild(style);

init();
