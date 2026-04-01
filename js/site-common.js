/* =========================================================
   DrJessie.life — Shared site-wide scripts
   Consolidates inline JS that was duplicated on every page.
   ========================================================= */

// Header shadow / color shift on scroll
document.addEventListener("scroll", function () {
  var header = document.querySelector("header");
  if (!header) return;
  if (window.scrollY > 10) {
    header.classList.add("is-scrolled");
  } else {
    header.classList.remove("is-scrolled");
  }
});

// Auto-offset main content so the fixed header never overlaps the hero
(function () {
  function setHeaderOffset() {
    var header = document.querySelector("header");
    if (!header) return;
    var h = Math.ceil(header.getBoundingClientRect().height || 0);
    document.documentElement.style.setProperty("--header-offset", h ? (h + "px") : "92px");
  }
  window.addEventListener("load", setHeaderOffset, { once: true });
  window.addEventListener("resize", setHeaderOffset);
  setHeaderOffset();
})();

// Dynamic year in footer
(function () {
  var el = document.getElementById("year");
  if (el) el.textContent = new Date().getFullYear();
})();

// Format buttons: auto-disable when href is missing/placeholder + block clicks when disabled
(function () {
  function isPlaceholderHref(hrefRaw) {
    var href = (hrefRaw || "").trim();
    if (!href) return true;
    var low = href.toLowerCase();
    return (href === "#" || href === "#0" || low.startsWith("javascript"));
  }

  var buttons = document.querySelectorAll('.kdp-links a.buy-btn');
  buttons.forEach(function (btn) {
    var href = btn.getAttribute('href');
    var placeholder = isPlaceholderHref(href);

    if (placeholder) {
      btn.classList.add('is-disabled');
      btn.setAttribute('aria-disabled', 'true');
      btn.setAttribute('tabindex', '-1');
    } else {
      btn.classList.remove('is-disabled');
      btn.removeAttribute('aria-disabled');
      btn.removeAttribute('tabindex');
    }
  });

  // Keep hover/animation alive, but prevent navigation when disabled.
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a.buy-btn');
    if (!a) return;
    var disabled = a.classList.contains('is-disabled') || a.getAttribute('aria-disabled') === 'true';
    if (disabled) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
})();
