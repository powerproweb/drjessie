/* =========================================================
   Article Product Blocks
   - Renders reusable product CTA blocks from placeholders
   - Centralizes product URLs + default referral codes
   ========================================================= */

(function () {
  "use strict";

  var PRODUCT_REGISTRY = {
    "professional-formulas": {
      vendorName: "Professional Formulas",
      url: "https://professionalformulas.com/patients/",
      ctaLabel: "Open Professional Formulas Portal",
      discountCode: "CRV697T",
      discountLabel: "Use code at checkout",
      logoSrc: "images/cta-logos/professional-formulas.png",
      logoAlt: "Professional Formulas logo",
      logoVariant: "inverse"
    },
    "systemic-formulas": {
      vendorName: "Systemic Formulas",
      url: "https://sfi-portal.com/",
      ctaLabel: "Open Systemic Formulas Portal",
      discountCode: "Txcl-01",
      discountLabel: "Use code at checkout",
      logoSrc: "images/cta-logos/systemic-formulas.png",
      logoAlt: "Systemic Formulas logo"
    },
    "neora": {
      vendorName: "NEORA",
      url: "https://drjessie.neora.com/us/en/neoraconnect",
      ctaLabel: "Open NEORA Link",
      logoSrc: "images/cta-logos/neora-connect.png",
      logoAlt: "NEORA logo",
      logoVariant: "inverse"
    },
    "counter": {
      vendorName: "Counter",
      url: "https://www.counter.com/?aff=JESSIEKEENER",
      ctaLabel: "Open Counter Shop"
    },
    "green-compass": {
      vendorName: "Green Compass",
      url: "https://greencompassglobal.com/?pws=jessiekeener",
      ctaLabel: "Open Green Compass Shop"
    }
  };

  function clean(value) {
    return (value || "").trim();
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (typeof text === "string") node.textContent = text;
    return node;
  }

  function toClassToken(value) {
    return clean(value).toLowerCase().replace(/[^a-z0-9-]/g, "");
  }

  function updateCopyButtonState(button, text) {
    var original = button.getAttribute("data-default-label") || "Copy code";
    button.textContent = text;
    window.setTimeout(function () {
      button.textContent = original;
    }, 1600);
  }

  function copyCode(code, button) {
    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== "function") return;

    navigator.clipboard.writeText(code).then(function () {
      updateCopyButtonState(button, "Copied");
    }).catch(function () {
      updateCopyButtonState(button, "Copy failed");
    });
  }

  function buildProductBlock(placeholder, baseConfig, productKey) {
    var keyClass = toClassToken(productKey || clean(placeholder.getAttribute("data-product-key")));
    var title = clean(placeholder.getAttribute("data-product-title")) || baseConfig.vendorName;
    var description = clean(placeholder.getAttribute("data-product-description"));
    var ctaLabel = clean(placeholder.getAttribute("data-cta-label")) || baseConfig.ctaLabel || ("Open " + baseConfig.vendorName);
    var ctaUrl = clean(placeholder.getAttribute("data-cta-url")) || baseConfig.url;
    var discountCode = clean(placeholder.getAttribute("data-discount-code")) || baseConfig.discountCode || "";
    var discountLabel = clean(placeholder.getAttribute("data-discount-label")) || baseConfig.discountLabel || "Use code";
    var pricingLabel = clean(placeholder.getAttribute("data-pricing-label")) || baseConfig.pricingLabel || "Pricing";
    var pricingText = clean(placeholder.getAttribute("data-pricing-text")) || baseConfig.pricingText || "";
    var pricingItems = Array.isArray(baseConfig.pricingItems) ? baseConfig.pricingItems.slice() : [];
    var pricingItemsAttr = clean(placeholder.getAttribute("data-pricing-items"));
    var logoSrc = clean(placeholder.getAttribute("data-logo-src")) || baseConfig.logoSrc || "";
    var logoAlt = clean(placeholder.getAttribute("data-logo-alt")) || baseConfig.logoAlt || (baseConfig.vendorName + " logo");
    var logoVariant = toClassToken(clean(placeholder.getAttribute("data-logo-variant")) || baseConfig.logoVariant || "");

    if (pricingItemsAttr) {
      pricingItems = pricingItemsAttr.split("|").map(clean).filter(Boolean);
    }

    var wrapper = el("aside", "ki-product-block");
    wrapper.setAttribute("role", "complementary");
    wrapper.setAttribute("aria-label", title);
    if (keyClass) wrapper.className += " ki-product-block--" + keyClass;
    if (logoSrc) {
      var logoWrapClass = "ki-product-logo-wrap";
      if (keyClass) logoWrapClass += " ki-product-logo-wrap--" + keyClass;
      if (logoVariant) logoWrapClass += " ki-product-logo-wrap--" + logoVariant;
      var logoWrap = el("div", logoWrapClass);
      var logoImg = el("img", "ki-product-logo");
      logoImg.setAttribute("src", logoSrc);
      logoImg.setAttribute("alt", logoAlt);
      logoImg.setAttribute("loading", "lazy");
      logoImg.setAttribute("decoding", "async");
      logoWrap.appendChild(logoImg);
      wrapper.appendChild(logoWrap);
    }

    wrapper.appendChild(el("p", "ki-product-title", title));

    if (description) {
      wrapper.appendChild(el("p", "ki-product-description", description));
    }

    if (pricingText || pricingItems.length) {
      var pricingWrap = el("div", "ki-product-pricing");
      if (pricingLabel) {
        pricingWrap.appendChild(el("p", "ki-product-pricing-label", pricingLabel));
      }

      if (pricingText) {
        pricingWrap.appendChild(el("p", "ki-product-pricing-text", pricingText));
      }

      if (pricingItems.length) {
        var pricingList = el("ul", "ki-product-pricing-list");
        pricingItems.forEach(function (item) {
          var text = clean(item);
          if (!text) return;
          pricingList.appendChild(el("li", "ki-product-pricing-item", text));
        });

        if (pricingList.children.length) {
          pricingWrap.appendChild(pricingList);
        }
      }

      wrapper.appendChild(pricingWrap);
    }

    var actions = el("div", "ki-product-actions");

    var link = el("a", "ki-product-link", ctaLabel);
    link.setAttribute("href", ctaUrl);
    link.setAttribute("target", "_blank");
    link.setAttribute("rel", "noopener noreferrer");
    actions.appendChild(link);

    if (discountCode) {
      var codeWrap = el("div", "ki-product-code-wrap");
      var label = el("span", "ki-product-code-label", discountLabel + ":");
      var code = el("code", "ki-product-code", discountCode);

      codeWrap.appendChild(label);
      codeWrap.appendChild(code);

      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
        var copyBtn = el("button", "ki-product-copy-btn", "Copy code");
        copyBtn.type = "button";
        copyBtn.setAttribute("data-default-label", "Copy code");
        copyBtn.addEventListener("click", function () {
          copyCode(discountCode, copyBtn);
        });
        codeWrap.appendChild(copyBtn);
      }

      actions.appendChild(codeWrap);
    }

    wrapper.appendChild(actions);
    return wrapper;
  }

  function renderProductBlocks() {
    var placeholders = document.querySelectorAll(".ki-product-placeholder[data-product-key]");
    if (!placeholders.length) return;

    Array.prototype.forEach.call(placeholders, function (placeholder) {
      var key = clean(placeholder.getAttribute("data-product-key")).toLowerCase();
      var baseConfig = PRODUCT_REGISTRY[key];
      if (!baseConfig || !baseConfig.url) return;

      var block = buildProductBlock(placeholder, baseConfig, key);
      placeholder.replaceWith(block);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", renderProductBlocks);
  } else {
    renderProductBlocks();
  }
})();
