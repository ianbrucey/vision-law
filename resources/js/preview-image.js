/**
 * Image preview viewer (spec 007 T-03, DOC-07).
 *
 * Zoom-to-fit / 100% / step zoom, 90° rotation. Starts on a downscaled
 * variant for progressive loading; zooming to 100% swaps in full
 * resolution on demand.
 */
const img = document.getElementById("img-main");
if (img) {
  const label = document.getElementById("img-zoom-label");
  const state = { zoom: "fit", rotation: 0, fullLoaded: false };

  function apply() {
    img.style.transform = `rotate(${state.rotation}deg)`;
    if (state.zoom === "fit") {
      img.style.width = "";
      img.style.maxWidth = "100%";
      label.textContent = "Fit";
    } else {
      img.style.maxWidth = "none";
      img.style.width = `${state.zoom * 100}%`;
      label.textContent = `${Math.round(state.zoom * 100)}%`;
    }
  }

  function ensureFull() {
    if (state.fullLoaded) return;
    state.fullLoaded = true;
    const full = new Image();
    full.onload = () => {
      img.src = img.dataset.full;
    };
    full.src = img.dataset.full;
  }

  document.getElementById("img-fit").addEventListener("click", () => {
    state.zoom = "fit";
    apply();
  });
  document.getElementById("img-100").addEventListener("click", () => {
    state.zoom = 1;
    ensureFull();
    apply();
  });
  document.getElementById("img-zoom-in").addEventListener("click", () => {
    state.zoom = state.zoom === "fit" ? 1.25 : Math.min(8, state.zoom * 1.25);
    ensureFull();
    apply();
  });
  document.getElementById("img-zoom-out").addEventListener("click", () => {
    state.zoom = state.zoom === "fit" ? 0.75 : Math.max(0.1, state.zoom / 1.25);
    apply();
  });
  document.getElementById("img-rotate").addEventListener("click", () => {
    state.rotation = (state.rotation + 90) % 360;
    apply();
  });

  apply();
}
