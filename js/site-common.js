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

// Ensure every page has a direct Contact link in the top nav.
(function () {
  var navLists = document.querySelectorAll(".main-nav .nav-list");
  if (!navLists.length) return;

  var path = (window.location.pathname || "").toLowerCase();
  var isContactPage = path === "/contact" || path.endsWith("/contact.html");

  navLists.forEach(function (navList) {
    if (!navList) return;

    var existingContact = navList.querySelector(
      'a[href="contact.html"], a[href="/contact.html"], a[href="https://drjessie.life/contact.html"], a[href="https://www.drjessie.life/contact.html"]'
    );
    if (existingContact) {
      if (isContactPage) {
        existingContact.setAttribute("aria-current", "page");
      }
      return;
    }

    var navItem = document.createElement("li");
    navItem.className = "nav-item";

    var navLink = document.createElement("a");
    navLink.className = "nav-link";
    navLink.href = "contact.html";
    navLink.title = "Contact Dr. Jessie";
    navLink.textContent = "Contact";
    if (isContactPage) {
      navLink.setAttribute("aria-current", "page");
    }

    navItem.appendChild(navLink);
    navList.appendChild(navItem);
  });
})();

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

// Mobile nav: keep scrolling confined to menu panel while open.
(function () {
  var nav = document.querySelector(".main-nav");
  if (!nav) return;

  var navToggle = nav.querySelector(".nav-toggle");
  var navList = nav.querySelector(".nav-list");
  if (!navToggle || !navList) return;

  var mobileQuery = window.matchMedia("(max-width: 768px)");

  function setMenuOpenState() {
    var isOpen = mobileQuery.matches && navToggle.checked;
    document.documentElement.classList.toggle("mobile-menu-open", isOpen);
    document.body.classList.toggle("mobile-menu-open", isOpen);
  }

  function closeMenu() {
    if (!navToggle.checked) return;
    navToggle.checked = false;
    setMenuOpenState();
  }

  navToggle.addEventListener("change", setMenuOpenState);

  navList.addEventListener("click", function (e) {
    if (!e.target.closest("a")) return;
    closeMenu();
  });

  document.addEventListener("click", function (e) {
    if (!mobileQuery.matches || !navToggle.checked) return;
    if (nav.contains(e.target)) return;
    closeMenu();
  });

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape" || !navToggle.checked) return;
    closeMenu();
  });

  function handleViewportChange() {
    if (!mobileQuery.matches) {
      navToggle.checked = false;
    }
    setMenuOpenState();
  }

  if (typeof mobileQuery.addEventListener === "function") {
    mobileQuery.addEventListener("change", handleViewportChange);
  } else if (typeof mobileQuery.addListener === "function") {
    mobileQuery.addListener(handleViewportChange);
  }

  window.addEventListener("pageshow", setMenuOpenState);
  setMenuOpenState();
})();

// Courses page sidebar: fixed-on-scroll behavior (desktop) + active section highlighting
(function () {
  var sidebar = document.querySelector(".course-side-menu");
  var layout = document.querySelector(".course-page-layout");
  if (!sidebar || !layout) return;

  var GAP = 24;
  var ACTIVE_CLASS = "course-active";
  var spacer = null;
  var navLinks = Array.prototype.slice.call(
    document.querySelectorAll(".course-side-nav a[href^='#']")
  );
  var sections = [];

  function getHeaderOffset() {
    var raw = getComputedStyle(document.documentElement).getPropertyValue("--header-offset").trim();
    var parsed = parseInt(raw, 10);
    return Number.isFinite(parsed) ? parsed : 92;
  }

  function resetSidebar() {
    sidebar.classList.remove("is-fixed");
    sidebar.style.cssText = "";
    if (spacer) spacer.style.display = "none";
  }

  function setActiveLink(id) {
    if (!id) return;
    navLinks.forEach(function (link) {
      if (link.getAttribute("data-target-id") === id) {
        link.classList.add(ACTIVE_CLASS);
      } else {
        link.classList.remove(ACTIVE_CLASS);
      }
    });
  }

  function updateSidebarPosition() {
    if (!sidebar || !layout) return;

    var headerOffset = getHeaderOffset();
    if (window.innerWidth <= 860) {
      resetSidebar();
      return;
    }

    var layoutRect = layout.getBoundingClientRect();
    var sidebarWidth = sidebar.offsetWidth;
    var triggerY = layoutRect.top + window.scrollY - headerOffset - GAP;
    var maxHeight = Math.max(160, window.innerHeight - headerOffset - (GAP * 2));

    if (!spacer) {
      spacer = document.createElement("div");
      spacer.setAttribute("aria-hidden", "true");
      sidebar.parentNode.insertBefore(spacer, sidebar);
    }

    if (window.scrollY > triggerY) {
      spacer.style.width = sidebarWidth + "px";
      spacer.style.flexShrink = "0";
      spacer.style.display = "block";

      sidebar.classList.add("is-fixed");
      sidebar.style.position = "fixed";
      sidebar.style.top = (headerOffset + GAP) + "px";
      sidebar.style.left = layoutRect.left + "px";
      sidebar.style.width = sidebarWidth + "px";
      sidebar.style.maxHeight = maxHeight + "px";
      sidebar.style.overflow = "visible";
      sidebar.style.zIndex = "100";
    } else {
      resetSidebar();
    }
  }

  window.addEventListener("scroll", updateSidebarPosition, { passive: true });
  window.addEventListener("resize", updateSidebarPosition, { passive: true });
  window.addEventListener("load", updateSidebarPosition);

  navLinks.forEach(function (link) {
    var href = (link.getAttribute("href") || "").trim();
    if (!href || href.charAt(0) !== "#" || href.length === 1) return;

    var id = href.slice(1);
    link.setAttribute("data-target-id", id);

    var section = document.getElementById(id);
    if (section) sections.push(section);

    link.addEventListener("click", function () {
      setActiveLink(id);
    });
  });

  if (sections.length && navLinks.length) {
    if ("IntersectionObserver" in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) setActiveLink(entry.target.id);
        });
      }, { rootMargin: "-15% 0px -65% 0px" });
      sections.forEach(function (section) { io.observe(section); });
    } else {
      function updateActiveByScroll() {
        var headerOffset = getHeaderOffset() + 30;
        var activeId = sections[0].id;

        sections.forEach(function (section) {
          if (window.scrollY >= section.offsetTop - headerOffset) {
            activeId = section.id;
          }
        });

        setActiveLink(activeId);
      }

      window.addEventListener("scroll", updateActiveByScroll, { passive: true });
      window.addEventListener("resize", updateActiveByScroll, { passive: true });
      window.addEventListener("load", updateActiveByScroll);
      updateActiveByScroll();
    }
  }

  if (window.location.hash && window.location.hash.length > 1) {
    setActiveLink(window.location.hash.slice(1));
  } else if (sections.length) {
    setActiveLink(sections[0].id);
  }

  if (document.readyState !== "loading") {
    updateSidebarPosition();
  }
})();

// Courses page image popup: click any course card image to view an enlarged version.
(function () {
  var courseImages = Array.prototype.slice.call(
    document.querySelectorAll(".course-card .course-graphic img, .ki-gc-image-row img, .ki-inline-popup-image")
  );
  if (!courseImages.length) return;

  var lightbox = document.createElement("div");
  lightbox.className = "course-image-lightbox";
  lightbox.setAttribute("aria-hidden", "true");
  lightbox.innerHTML =
    '<div class="course-image-lightbox-backdrop" data-close-lightbox="true"></div>' +
    '<div class="course-image-lightbox-dialog" role="dialog" aria-modal="true" aria-label="Course image preview">' +
      '<button type="button" class="course-image-lightbox-close" aria-label="Close image preview">×</button>' +
      '<img class="course-image-lightbox-image" src="" alt="">' +
      '<p class="course-image-lightbox-caption" aria-live="polite"></p>' +
    "</div>";

  document.body.appendChild(lightbox);

  var preview = lightbox.querySelector(".course-image-lightbox-image");
  var caption = lightbox.querySelector(".course-image-lightbox-caption");
  var closeBtn = lightbox.querySelector(".course-image-lightbox-close");
  var lastFocused = null;
  var isOpen = false;

  function openLightbox(img) {
    if (!img) return;
    lastFocused = document.activeElement;

    preview.src = img.currentSrc || img.src || "";
    preview.alt = img.getAttribute("alt") || "Course image preview";
    caption.textContent = img.getAttribute("alt") || "";

    lightbox.classList.add("is-open");
    lightbox.setAttribute("aria-hidden", "false");
    document.body.classList.add("course-lightbox-open");
    isOpen = true;

    closeBtn.focus();
  }

  function closeLightbox() {
    if (!isOpen) return;

    isOpen = false;
    lightbox.classList.remove("is-open");
    lightbox.setAttribute("aria-hidden", "true");
    document.body.classList.remove("course-lightbox-open");

    preview.src = "";
    preview.alt = "";
    caption.textContent = "";

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  courseImages.forEach(function (img) {
    if (!img) return;
    img.classList.add("is-zoomable");
    img.setAttribute("tabindex", "0");
    img.setAttribute("role", "button");
    img.setAttribute(
      "aria-label",
      ((img.getAttribute("alt") || "Course image") + " — open enlarged preview")
    );

    img.addEventListener("click", function () {
      openLightbox(img);
    });

    img.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openLightbox(img);
      }
    });
  });

  closeBtn.addEventListener("click", closeLightbox);

  lightbox.addEventListener("click", function (e) {
    if (e.target && e.target.getAttribute("data-close-lightbox") === "true") {
      closeLightbox();
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && isOpen) {
      closeLightbox();
    }
  });
})();

// Dynamic year in footer
(function () {
  var el = document.getElementById("year");
  if (el) el.textContent = new Date().getFullYear();
})();

// Global dark/light theme toggle (persisted)
(function () {
  var STORAGE_KEY = "drjessie-theme";
  var THEME_TOGGLE_ENABLED = false; // Set to true to re-enable after light-mode styling is finalized.
  var root = document.documentElement;

  if (!THEME_TOGGLE_ENABLED) {
    root.setAttribute("data-theme", "dark");
    try {
      localStorage.setItem(STORAGE_KEY, "dark");
    } catch (err) {}
    return;
  }

  function getStoredTheme() {
    try {
      var saved = localStorage.getItem(STORAGE_KEY);
      if (saved === "light" || saved === "dark") return saved;
    } catch (err) {}
    return null;
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (err) {}
  }

  function getPreferredTheme() {
    return (window.matchMedia && window.matchMedia("(prefers-color-scheme: light)").matches)
      ? "light"
      : "dark";
  }

  function setToggleUI(theme) {
    var btn = document.querySelector(".theme-toggle");
    if (!btn) return;

    var icon = btn.querySelector(".theme-toggle-icon");
    var text = btn.querySelector(".theme-toggle-text");
    var isLight = theme === "light";

    if (icon) icon.textContent = isLight ? "☀" : "☾";
    if (text) text.textContent = isLight ? "Light" : "Dark";

    btn.setAttribute("aria-pressed", String(isLight));
    btn.setAttribute("title", isLight ? "Switch to dark mode" : "Switch to light mode");
  }

  function applyTheme(theme) {
    root.setAttribute("data-theme", theme);
    setToggleUI(theme);
  }

  function installThemeToggle() {
    var nav = document.querySelector(".main-nav");
    if (!nav || nav.querySelector(".theme-toggle")) return;

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "theme-toggle";
    btn.setAttribute("aria-label", "Toggle light and dark mode");

    var icon = document.createElement("span");
    icon.className = "theme-toggle-icon";
    icon.setAttribute("aria-hidden", "true");

    var text = document.createElement("span");
    text.className = "theme-toggle-text";

    btn.appendChild(icon);
    btn.appendChild(text);
    nav.appendChild(btn);

    btn.addEventListener("click", function () {
      var current = root.getAttribute("data-theme") === "light" ? "light" : "dark";
      var next = current === "light" ? "dark" : "light";
      applyTheme(next);
      saveTheme(next);
    });

    setToggleUI(root.getAttribute("data-theme") || "dark");
  }

  var initialTheme = getStoredTheme() || getPreferredTheme();
  applyTheme(initialTheme);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", installThemeToggle, { once: true });
  } else {
    installThemeToggle();
  }
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
    var forceDisabled = btn.getAttribute('data-force-disabled') === 'true';

    if (placeholder || forceDisabled) {
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
