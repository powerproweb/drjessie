# AGENTS.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

DrJessie.life is the professional website for Jessie Keener, N.D. (Naturopathic Doctor) — a Human Design Specialist focused on holistic naturopathic care, longevity, breathwork, detox, and integrative wellness. The site serves as her public platform for articles, books, courses, downloads, interviews, newsletter signup, and contact.

## Architecture

### Frontend (Static HTML + CSS + JS)

All pages are flat `.html` files at the project root — no build step, no bundler, no framework. CSS lives in `css/`, JS in `js/`.

**Key pages:**
- `index.html` — Homepage
- `books.html` — Books: "Who Will Save Our Doctors" and "The Ascension Handbook"
- `courses.html` — Courses page (longevity, detox, nervous system mastery — some marked "Coming Soon")
- `downloads.html` — Downloadable resources (Coming Soon)
- `contact.html` — Contact page
- `newsletter.html` — Newsletter signup (MailerLite integration)
- `ki_aq_initiative.html` — Article: Understanding the A.Q. Initiative
- `ki_mind_spirit.html` — Article: Keener Intelligence — Mind Spirit
- `ki_body_mastery.html` — Article: Keener Intelligence — Body Mastery
- `ki_culinary_wellness.html` — Article: Culinary Wellness
- `ki_drjk_interviews.html` — Interviews page
- `privacy.html` / `terms.html` — Legal pages
- `404.html` — Custom error page

**CSS:**
- `css/style.css` — Main stylesheet
- `css/custom.css` — Custom overrides and additions

**JS:**
- `js/` — Site scripts

### Assets
- `images/` — Organized into subdirectories: `headers/`, `books/`, `courses/`, `downloads/`, `events/`, `articles/`, `interviews/`, `info-card-img/`, `mailerlite/`, `videos/`
- Images include hero banners (1200×600 and 2400×1200), favicons, social preview images, book covers, and article illustrations

### Third-Party Integrations
- **MailerLite** — Newsletter signup forms and email marketing (account ID: 1914177, loaded via universal JS snippet)

### SEO & Web Standards
- `robots.txt` — Crawl rules
- `sitemap.xml` — XML sitemap for search engines
- `site.webmanifest` — PWA manifest (favicon references)

## Hosting & Deployment
- Apache on shared hosting (BlueHost/cPanel)
- `.htaccess` handles HTTPS + www canonicalization, `.html` extension stripping, security headers, browser caching, gzip compression, MIME types, error pages, and sensitive file blocking
- PHP 8.1 handler configured (for any future dynamic pages)
- No build step — deploy by uploading files directly

## Conventions
- Dark/light colour scheme support (`color-scheme: light dark` in meta, theme-color `#4f65bd`)
- Responsive CSS-only hamburger menu (checkbox toggle pattern — no JS for menu open/close)
- Nav/header/footer markup is duplicated across all HTML files (no includes or templating)
- Hero banner images sit directly under the sticky header
- Article pages use the `ki_` prefix (Keener Intelligence content series)
- Some article pages include inline `<style>` blocks for nav dropdown z-index fixes

## Important Notes
- MailerLite account ID `1914177` is embedded in the universal JS snippet on pages with newsletter signup
- Several sections are marked "Coming Soon" (courses, downloads) — placeholder links exist in the nav
- The `.htaccess` includes a commented-out image hotlink protection block (can be enabled if needed)
