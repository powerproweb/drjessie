/* =========================================================
   Per-Article Back to Top (Smooth Scroll)
   - Works with links: [data-backtop]
   - Scrolls to <a name="top"></a> or falls back to page top
   - Respects fixed header offset (--header-offset)
   ========================================================= */

(function () {
  function getHeaderOffset() {
    const cssVar = getComputedStyle(document.documentElement)
      .getPropertyValue("--header-offset")
      .trim();
    const n = parseInt(cssVar, 10);
    return Number.isFinite(n) ? n : 92;
  }

  function findTopTarget() {
    // Prefer your existing anchor: <a name="top"></a>
    const byName = document.querySelector('a[name="top"]');
    if (byName) return byName;

    // Fallback: element with id="top"
    const byId = document.getElementById("top");
    if (byId) return byId;

    // Final fallback: document root
    return document.documentElement;
  }

  document.addEventListener("click", function (e) {
    const link = e.target.closest("[data-backtop]");
    if (!link) return;

    e.preventDefault();

    const target = findTopTarget();
    const offset = getHeaderOffset();

    const y = (target === document.documentElement)
      ? 0
      : target.getBoundingClientRect().top + window.scrollY;

    window.scrollTo({
      top: Math.max(0, y - offset - 10),
      behavior: "smooth",
    });
  });
})();
