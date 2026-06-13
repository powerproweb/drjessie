# Changelog

All notable changes to DrJessie.life will be documented in this file.

## [Unreleased]

### Added
- Initial site with homepage, books, courses, downloads, contact, newsletter pages
- Keener Intelligence article series (A.Q. Initiative, Mind Spirit, Body Mastery, Culinary Wellness)
- Interviews page
- Privacy and Terms pages
- Custom 404 page
- SEO files: robots.txt, sitemap.xml, site.webmanifest
- MailerLite newsletter integration
- Project scaffolding: .gitignore, .gitattributes, AGENTS.md, README.md
- New article image asset for Body Mastery ECS executive section: `images/articles/ah/ecs_system_en4.jpg`

### Changed
- Updated Body Mastery sidebar link text for the ECS executive section to `ECS Human Operating System`
- Updated the Body Mastery ECS executive section hero image to `images/articles/ah/ecs_system_en4.jpg`
- Refined the ECS executive section heading formatting and line break layout in `ki_body_mastery.html`
- Updated `api/_config.php` notification and sender fallback defaults to `jkideal@hotmail.com` for contact workflow continuity.
- Hardened `.gitignore` to exclude local auth artifact files and folders from version control.
- Deployed admin panel credential configuration to production server via `api/_config.local.php` (server-only, not committed), then removed the temporary local credential file after verification.
