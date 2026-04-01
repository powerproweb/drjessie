/*!
 * back-to-top.js
 * Lightweight, dependency-free "Back to Top" link with smooth scrolling + reduced-motion support.
 * Drop this file into your site and include it before </body>.
 */

(function () {
  "use strict";

  // ---- Config ----
  var SHOW_AFTER_PX = 420;          // when to show the link
  var SCROLL_DURATION_MS = 650;     // fallback duration if smooth scroll isn't supported

  // Respect reduced motion
  var prefersReducedMotion = false;
  try {
    prefersReducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  } catch (e) {}

  // Inject minimal CSS (keeps this a single-file install)
  var css = [
    "#backToTop{position:fixed;right:18px;bottom:18px;z-index:9999;",
    "display:inline-flex;align-items:center;gap:8px;",
    "padding:10px 12px;border-radius:999px;",
    "font:600 14px/1.1 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;",
    "text-decoration:none;cursor:pointer;",
    "opacity:0;transform:translateY(8px);pointer-events:none;",
    "transition:opacity .18s ease,transform .18s ease;",
    "backdrop-filter:saturate(180%) blur(10px);",
    "background:rgba(0,0,0,.65);color:#fff;",
    "box-shadow:0 10px 28px rgba(0,0,0,.25);",
    "}",
    "#backToTop:hover{background:rgba(0,0,0,.78)}",
    "#backToTop:focus{outline:2px solid rgba(255,255,255,.85);outline-offset:2px}",
    "#backToTop.is-visible{opacity:1;transform:translateY(0);pointer-events:auto}",
    "@media (prefers-reduced-motion: reduce){#backToTop{transition:none}}",
  ].join("");

  var styleEl = document.createElement("style");
  styleEl.setAttribute("data-back-to-top", "true");
  styleEl.appendChild(document.createTextNode(css));
  document.head.appendChild(styleEl);

  // Create the link
  var link = document.createElement("a");
  link.id = "backToTop";
  link.href = "#top";
  link.setAttribute("aria-label", "Back to top");
  link.innerHTML = '<span aria-hidden="true">↑</span><span>Back to top</span>';

  // Ensure there is a #top target (optional but nice)
  if (!document.getElementById("top")) {
    var topAnchor = document.createElement("div");
    topAnchor.id = "top";
    topAnchor.style.position = "absolute";
    topAnchor.style.top = "0";
    topAnchor.style.left = "0";
    topAnchor.style.width = "1px";
    topAnchor.style.height = "1px";
    topAnchor.style.overflow = "hidden";
    document.body.insertBefore(topAnchor, document.body.firstChild);
  }

  document.body.appendChild(link);

  function setVisible(visible) {
    if (visible) link.classList.add("is-visible");
    else link.classList.remove("is-visible");
  }

  function onScroll() {
    setVisible(window.scrollY > SHOW_AFTER_PX);
  }

  // Smooth scroll with fallback
  function easeInOutCubic(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
  }

  function smoothScrollToTop() {
    if (prefersReducedMotion) {
      window.scrollTo(0, 0);
      return;
    }

    // Native smooth scroll if available
    try {
      window.scrollTo({ top: 0, behavior: "smooth" });
      return;
    } catch (e) {
      // fallback below
    }

    var startY = window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
    var start = performance.now ? performance.now() : Date.now();

    function step(now) {
      var current = now || (performance.now ? performance.now() : Date.now());
      var elapsed = current - start;
      var t = Math.min(1, elapsed / SCROLL_DURATION_MS);
      var eased = easeInOutCubic(t);
      var y = Math.round(startY * (1 - eased));
      window.scrollTo(0, y);
      if (t < 1) requestAnimationFrame(step);
    }

    requestAnimationFrame(step);
  }

  link.addEventListener("click", function (e) {
    e.preventDefault();
    smoothScrollToTop();
  });

  // Passive scroll listener for performance
  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", onScroll, { passive: true });

  // Initial state
  onScroll();
})();
