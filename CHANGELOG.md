# Changelog

All notable changes to this project will be documented in this file.

## [2026.9.4] - 2026-09-08

### 🧠 Google Magika AI File Content-Type Detection & Zero-Trust Defense
- **Embedded Google Magika Model in Docker**:
  - Integrated `magika-cli` (v1.0.2 standalone binary) into `Dockerfile`, packaging Google's deep learning model directly into the container image.
  - Zero network dependency at runtime: 100% offline inference on local CPU taking ~5-15ms per file across `linux/amd64` and `linux/arm64`.
- **Zero-Trust Upload Gateway (`config/detector.php`)**:
  - Added `AssetDetector` helper combining Google Magika AI classification (`output.label`, `output.group`, `score`) with native PHP `fileinfo` fallback.
  - **Eliminated Extension Trust**: Discontinued trusting user-supplied file extensions (`$_FILES['name']`). File routing and format validation are now guided by actual file content.
  - **Dangerous Code & Web Shell Interception**: Proactively blocks executables and scripts (`php`, `shell`, `bash`, `elf`, `pebin`, `wasm`, `python`, etc.) across `api.php`, `config/upload.php`, `api_file.php`, `config/video_logic.php`, `config/audio_logic.php`, and `mcp.php`, even when disguised with image extensions (e.g. `avatar.jpg`).
  - **Empty File Protection**: Intercepts empty files (`output.label === 'empty'` or 0 bytes) early at the gateway.
  - **Defense in Depth**: Retains `getimagesize()`/`imagecreatefromstring()` image decoding and `ffprobe` video/audio integrity checks after Magika validation.
- **Automated Test Suite**:
  - Added `tests/asset_detector_test.mjs` verifying contract methods, dangerous labels blacklist, genuine PNG detection, and disguised WebShell detection. Integrated into `.github/workflows/ci.yml`.

## [2026.9.3] - 2026-09-03

### 🐳 CI/CD & Multi-Arch Docker Publishing
- **Official Docker Hub & GHCR Releases**:
  - Configured automated GitHub Actions workflow (`.github/workflows/docker-publish.yml`) to build and push multi-architecture images (`linux/amd64` and `linux/arm64`) to Docker Hub (`tbdavid2019/888box:latest`) and GitHub Container Registry (`ghcr.io/tbdavid2019/888box:latest`).
  - Native performance on AWS Graviton, Apple Silicon, and AMD64 Linux servers.
- **Zero-Downtime Watchtower Auto-Deployment**:
  - Integrated `containrrr/watchtower` service directly in `docker-compose.yml`.
  - Configured isolated `WATCHTOWER_SCOPE=888box` to prevent collisions on hosts running multiple Watchtower instances.
  - Added `DOCKER_API_VERSION=1.44` compatibility for Ubuntu 24.04 and Docker Engine 26+.
  - Fully closed-loop CI/CD: push code to `main` -> automated tests pass -> multi-arch images pushed -> servers automatically pull and restart within 5 minutes without manual SSH intervention.
- **Configurable Registry Fallback**:
  - Added `${DOCKER_IMAGE:-tbdavid2019/888box:latest}` support to `docker-compose.yml`, allowing seamless override to GHCR (`ghcr.io/tbdavid2019/888box:latest`) via `.env` for environments with network routing restrictions.

### 🔒 Security Audit & Remediation (12 Full-Stack Fixes)
- **SEC-01 (Backdoor Token Removal)**: Removed hardcoded bypass token `'ai_agent'` from `api.php` and `mcp.php`.
- **SEC-02 (SSRF Defense Engine)**: Built centralized SSRF validation (`config/security.php`) blocking private IP ranges (10.x, 172.16-31.x, 192.168.x), loopback (`127.0.0.1`), link-local, IPv6 site-local, and cloud metadata (`169.254.169.254`); enforced safe cURL protocol restrictions.
- **SEC-03 (Arbitrary File Upload & Execution Defense)**: Enforced strict document extension whitelist in `api_file.php` and disabled PHP engine/script execution inside `storage/.htaccess`.
- **SEC-04 (Arbitrary File Deletion / Path Traversal)**: Secured asset deletion across `config/storage.php`, `api_delete_audio.php`, `api_delete_file.php`, and `api_delete_video.php` by strictly resolving paths from database records within the `storage/` root boundary.
- **SEC-05 (SQL LIKE Wildcard Enumeration)**: Added strict hex regex validation `/^[a-fA-F0-9]{6,32}$/` for short and long tokens in `view.php`, eliminating database enumeration risks.
- **SEC-06 (Session Fixation & Cache Hardening)**: Enforced `session_regenerate_id(true)` upon login in `admin/login.php` and applied `Cache-Control: no-store` across all administrative views.
- **SEC-07 (CORS Alignment)**: Aligned Access-Control-Allow-Origin with credentials support in `mcp.php`.
- **SEC-08 (XSS Defense)**: Sanitized dynamic HTML attributes and metadata in `view.php`.
- **SEC-09 - SEC-11 (Command Injection, Installation & Config Protection)**: Sanitized shell arguments in `config/video_helper.php`, hardened `install.sh` `.env` chmod to `600`, and restricted access to sensitive project files in `.htaccess`.
- **Automated Security Test Suite & Skill**: Added `tests/security_audit_test.mjs` (10 test suites covering all remediations) and `.agent/skills/security-audit/SKILL.md`.

### 🎨 UI/UX & Documentation Streamlining
- **Documentation Refactoring**: Transferred granular UI design notes, translation details, and button spacing history from `README.md` into `CHANGELOG.md` to keep `README.md` focused, clean, and developer-friendly.

## [2026.8.19] - 2026-08-19

### ✨ Added
- **Native WebMCP & HTTP JSON-RPC Dual-Mode MCP Server (`mcp.php`, `/mcp`)**:
  - Upgraded `mcp.php` to support both **CLI stdio** (for Claude Desktop, Cursor, subagents) and **HTTP JSON-RPC 2.0** (`GET`, `POST`, `OPTIONS` with full CORS support).
  - Added multi-source authentication supporting `Authorization: Bearer <token>`, `X-API-Key`, parameter `token`, and browser **active session login** (`$_SESSION['loggedin']`).
  - Added new `/mcp` rewrite rule in `.htaccess` mapping directly to `mcp.php` for out-of-the-box compatibility with Cloudflare WebMCP edge bridge and standard agent clients.
  - Expanded and unified tool capabilities:
    - `upload_asset_by_url`: Safely downloads and ingests remote images, videos, audios, or documents with metadata and thumbnail generation.
    - `list_assets`: Paginated browsing of stored assets filtered by media type (`all`, `image`, `video`, `audio`, `file`).
    - `search_assets`: Keyword search across titles, storage paths, and URLs.
    - `get_stats`: Global statistics overview of asset counts and active storage backend.
    - `get_podcast_info`: Dynamic feed discovery for video and audio podcast RSS feeds.
    - `rebuild_podcast_rss`: Administrative action to trigger podcast RSS feed rebuilds.
    - `delete_asset`: Administrative action to safely remove assets and linked storage files.
- **Browser-Side WebMCP Bridge Module (`static/js/webmcp.js`)**:
  - Implemented client-side W3C / Chrome 146+ `document.modelContext` discovery and tool registration.
  - Injected seamlessly across all portal, upload center, and share viewer pages via `config/theme_helper.php`.
  - Automatically forwards tool calls from browser AI agents to the same-origin `/mcp.php` endpoint using active session credentials.
- **AI Agent Discovery & Specification Upgrades**:
  - Added RFC 8288 `Link: </mcp.php>; rel="mcp-server"` header on `index.php`.
  - Updated `skill.php` dynamic LLM instructions with WebMCP, HTTP endpoints, and complete tool documentation.
  - Updated `/.well-known/mcp/server-card.json` schema to version 1.1.0 with comprehensive input schemas for all tools.

## [2026.8.18] - 2026-08-18

### 🐛 Fixed
- **Share-Page View Counts**: A browser-local persistent marker now counts the same asset once per device/browser until that site's storage is cleared, instead of incrementing on every reload.
- **Share-Page CTA Contrast**: The embed-code copy action now forces dark text and icons on its light-blue surface, preventing theme styles from making the label unreadable.
- **Bilingual Share Pages**: Added automatic browser-language detection plus a persistent Traditional Chinese / English switcher in the top-right navigation.
- **Global Bilingual UI**: Added the same automatic Traditional Chinese / English switcher to the portal, all public upload centers, admin login, and admin management pages. The preference is shared across the site and dynamically inserted upload/admin content is translated as well.
- **Bilingual Copy Coverage**: Completed English translations for portal card descriptions, upload-center notices, device statistics, dynamic upload states, and admin metadata labels.
- **Unified Share Header**: Reworked the share-page navigation into the same full-width, fixed top header pattern used by upload centers, with safer title clearance and responsive mobile controls.
- **Unified Non-Home Headers**: Applied the same full-width top header to the four upload centers, admin login, and admin management pages while leaving the homepage layout unchanged. Removed the visible 「門戶」/「Portal」 wording in favor of simple 888 BOX and Home labels.
- **PWA Language Prompt**: The bottom-right 888 BOX installation prompt now follows the site's saved Traditional Chinese / English preference, including its title, description, buttons, and accessible label. The active EN/繁 control also has an explicit high-contrast selected state.
- **Translation Coverage & Header Spacing**: Hardened phrase and attribute translation for mixed homepage/upload text, dynamic upload percentages, and image compression labels. Removed the upload-page top padding that left a blank strip above the shared header, and increased the header brand/context type size.
- **Header Navigation Cleanup**: Replaced the old emoji-based hosting links with a compact shared 图片／影片／音訊／文件 navigation in the top header. Homepage and upload-center labels now use the short category names only; duplicate admin tabs and footer navigation were removed.
- **Admin Login Layout**: Fixed the login page flex layout so the shared header stays at the top and the sign-in card remains centered instead of being pushed to the right edge.
- **Homepage Icon Cleanup**: Replaced the remaining emoji type markers in the universal upload hint with plain localized text so the homepage uses the same icon language throughout.
- **Share Header Contrast & Type Scale**: Changed the upload entry to a dark high-contrast action and consolidated the share page's heading, metadata, navigation, and button type sizes into a clearer scale.
- **Branded OG Image**: Replaced the plain gradient OG asset with a 1200×630 PNG containing the existing 888 BOX brand mark and added explicit OG image dimensions and MIME metadata.
- **Share-Page SEO Metadata**: Added description, canonical, Open Graph, Twitter Card, JSON-LD, and PNG/ICO favicon metadata for richer link previews and search indexing.
- **HTTPS Share URLs**: Share metadata now respects the reverse proxy's forwarded HTTPS scheme instead of emitting HTTP canonical and preview URLs.

## [2026.8.9] - 2026-08-09

### ✨ Added
- **`/llms.txt` Standard Support (`llms.php`)**: Added dynamic `/llms.txt` generator following the [llmstxt.org](https://llmstxt.org/) specification to expose structured Markdown site indices, API gateways (`api.php`), skills (`skill.php`), MCP tools (`mcp.php`), podcast RSS feeds, and sitemaps for LLMs and AI agents.
- **`/llms-full.txt` Rewrite**: Configured `.htaccess` rewrite rules mapping `/llms.txt` -> `llms.php` and `/llms-full.txt` -> `skill.php` for extended LLM context retrieval.
- **AI Agent Discovery Header & HTML Link**: Added RFC 8288 `Link: </llms.txt>; rel="help"; type="text/markdown"` header and `<link rel="help" ...>` HTML tag in `index.php`, as well as updated `.well-known/api-catalog` and `robots.txt`.

## [2026.8.5] - 2026-08-05

### ✨ Added
- **CloudFront Asset Delivery**: Public S3 assets now use the configured `S3_CDN_DOMAIN`, including the dedicated `888box-media` CloudFront distribution. Existing object-key paths are retained so historical uploads continue to resolve.

### 🐛 Fixed
- **Storage Origin Exposure**: S3/OSS/Upyun assets requested through the site proxy are streamed server-side instead of returning a redirect that reveals the upstream storage URL.
- **Protected Asset Access**: Password-protected assets always remain on the same-origin authorization proxy; direct asset URLs, admin previews, API lists, share pages, and podcast feeds now share the same delivery policy.
- **Media Streaming**: The storage proxy supports `GET`, `HEAD`, and single byte-range requests for audio/video playback and seeking.

## [2026.7.31] - 2026-07-31

### ✨ Added
- **Privacy-Safe PWA**: Added web app manifest, install icons, service worker, offline fallback page, and an in-page Android/Chrome installation entry point when the browser exposes an install opportunity.
- **Document Share Previews**: Added safe, readable previews for small `.txt`, `.md`, `.json`, `.csv`, `.log`, `.yaml`, and `.yml` assets. Content is rendered as plain text with a 2 MB preview limit and a download fallback.
- **Chinese Typography**: Added Gen Jyuu Gothic Medium as the project's Chinese fallback font while retaining the existing JetBrains Mono presentation for Latin/monospace content.

### 🐛 Fixed
- **EPUB Reader on Cloud Storage**: Replaced the unavailable EPUB.js CDN address and now deliver EPUB files through the authorized same-origin share route, preventing S3/OSS CORS failures.
- **Share-Link Consistency**: Public upload queues and client-side histories now open and copy tokenized share-page URLs rather than raw storage URLs; image/media previews still use direct asset URLs where required.
- **Share-Page Link UX**: The link panel now defaults to the share-page URL while keeping explicit direct URL, Markdown, HTML, and BBCode options.

## [2026.7.23] - 2026-07-23

### ✨ Added & Improved
- **Modern Lucide Icon System Integration**:
  - Replaced outdated Alibaba Iconfont script and `<use xlink:href>` tags with modern, offline-ready Lucide vector icons (`static/js/lucide.min.js`).
  - Upgraded main Bento grid portal (`index.php`) cards with sleek glassmorphic Lucide icon badges (`image`, `clapperboard`, `folder-archive`, `mic`, `bot`).
  - Upgraded public asset upload centers (`upload_image.php`, `upload_video.php`, `upload_audio.php`, `upload_file.php`) with modern vector icons across headers, dropzone prompts, pagination navigation, copy tabs, and action buttons.
  - Upgraded all admin management interfaces (`admin/index.php`, `admin/video.php`, `admin/audio.php`, `admin/file.php`, `admin/login.php`, `admin/settings.php`) sidebar navigation, modal controls, API/RSS token buttons, and password visibility toggles (`eye` / `eye-off`).
  - Upgraded asset viewer page (`view.php`) password protection gate, metadata indicators, download button, and report action button.
- **Asset Preview UX & Embed Tools (`view.php`)**:
  - **Title Auto-Hiding**: Automatically hide title `<h1>` when an asset has no custom title set, eliminating intrusive "未命名資源" placeholder headers.
  - **Embed Code Panel**: Integrated embed & share code generator supporting Direct URL, Markdown, HTML, and BBCode with one-click copy feedback.
  - **Image Dimensions Metadata**: Added automatic image resolution detection (`Width × Height px`) badge to asset metadata statistics.
- **Permanent Fixed Floating Breadcrumb & Pretty Short URLs**:
  - Upgraded preview page breadcrumb navigation to permanent fixed floating header (`position: fixed`) with glassmorphic blur backdrop, ensuring it stays floating seamlessly as you scroll down the page.
  - Implemented short pretty URLs (`/v/{short_token}`) while preserving 100% backward compatibility for existing 32-char token links.
- **Webtalk Chat Widget Integration**:
  - Embedded Webtalk Chat script (`webtalk-chat.js`) across all public user-facing pages (`index.php`, `view.php`, `upload_*.php`).

## [2026.7.7] - 2026-07-07

### ✨ Added
- **AI Agent Readiness & Discovery**:
  - **robots.txt**: Fully RFC 9309 compliant with Content-Signals (`search=yes, ai-train=no, ai-input=no`) and explicit blocks for major AI crawlers (GPTBot, ClaudeBot, Google-Extended, etc.).
  - **Sitemap**: Added dynamic `sitemap.php` listing canonical public URLs using asset share tokens. Routed through `sitemap.xml` via `.htaccess`.
  - **Link Response Headers**: Emitted RFC 8288 Link headers on homepage (`index.php`) mapping discovery endpoints for API Catalog, Service Document (Skill), Sitemap, MCP Server Card, and Agent Skills index.
  - **WebMCP Integration**: Injected WebMCP client support on homepage (`navigator.modelContext.provideContext`) offering `upload_image`, `list_assets`, `search_assets`, and `get_stats` tools.
  - **API Catalog**: Published RFC 9727 linkset+json catalog at `/.well-known/api-catalog` highlighting API and MCP server endpoints.
  - **MCP Server Card**: Published SEP-1649 card at `/.well-known/mcp/server-card.json` for agent mcp endpoint auto-discovery.
  - **Agent Skills Index**: Published `/.well-known/agent-skills/index.json` outlining all agent capabilities.
- **Image Admin Dashboard Actions**:
  - Added "分享" (Share), "直連" (Direct Link), "編輯" (Edit), and "刪除" (Delete) administrative quick actions to image cards.
  - Added new backend endpoint `api_edit_image.php` to handle title, description, and password edits for images.

### 🔒 Security
- **IDOR Enumeration Vulnerability Fix**:
  - Deprecated sharing via database incremental IDs (`view.php?id=129`) to prevent enumeration attacks. Directly querying by `?id=` now returns a 404.
  - Replaced view URLs with token-based lookups (`view.php?token=3f8a2c...`).
  - Added database migrations to auto-generate `share_token` (32-char hex) and backfill existing image/video/file records.
  - Updated all API and upload helpers to return tokenized share URLs.

## [2026.5.29] - 2026-05-29

### ✨ Added
- **RSS Token Protection Controls**: Added admin-side RSS access controls in `admin/settings.php`, including a public/token-protected mode switch, independent RSS token generation/regeneration, and preview URLs for both video and audio podcast feeds.
- **Token-Aware RSS Gateway**: Added `rss.php` plus new `storage/.htaccess` routing so `/storage/podcast.xml` and `/storage/podcast_audio.xml` now flow through a PHP gateway that can enforce `rss_token` validation without changing the external feed URLs.

### 🐛 Fixed
- **Public RSS Bypass Risk**: Moved RSS rebuild output from directly exposed XML files to internal cache files (`storage/podcast.internal.xml` and `storage/podcast_audio.internal.xml`), preventing legacy direct-file access from bypassing the new RSS token protection mode.
- **Podcast URL Consistency**: Updated admin RSS links, MCP podcast info output, and rendered `skill.php` guidance to return the correct tokenized RSS URLs when RSS protection is enabled.

## [2026.5.28] - 2026-05-28

### 🐛 Fixed
- **Masked Storage Redirect Loop**: Fixed a critical production regression where cloud-backed assets could enter infinite redirects (`ERR_TOO_MANY_REDIRECTS`) because the database `images.url` field was incorrectly storing the public masked `/storage/...` URL instead of the true remote origin URL.
- **Origin/Public URL Separation**: Split runtime handling so uploads now persist the real upstream storage URL for `s3`, `oss`, and `upyun`, while public responses and admin copy links still expose the local masked domain URL.
- **Legacy Bad-Data Compatibility**: Hardened `get_file.php` and PDF inline preview flows to detect and recover from previously stored masked URLs, preventing older production rows from continuing to self-redirect after deployment.

### 📝 Docs
- **Production Maintenance Notes**: Documented the current operational differences across the four production hosts in a local-only maintenance note and excluded that note from git tracking.

## [2026.5.26] - 2026-05-26

### ✨ Added
- **Audio Center ("聲音大廳")**: Full-featured audio hosting support for `mp3`, `wav`, `aac`, `ogg`, `m4a`, `flac` as a first-class asset type, complete with drag-and-drop batches, metadata extraction, dynamic revolving CD visualizer player in `view.php`, dedicated administrative dashboard (`admin/audio.php`), and automated `storage/podcast_audio.xml` iTunes podcast feeds.
- **Pantone Theme Engine**: Added unified themes color presets registry (`config/themes.php` and `config/theme_helper.php`) featuring sleek dark Pantone 2026 Middle East Dart gold/cream/blue colors, instantly toggleable via Admin settings.
- **Cloud Storage Local-Domain Masking**: Implemented secure asset routing proxy (`get_file.php` + `storage/.htaccess` rewrite engine) to hide direct cloud S3/OSS/Upyun bucket endpoints. All copied links and download buttons dynamically mask to use the active production domain (e.g. `https://box.david888.com/storage/...`), seamlessly fetching or redirecting behind the scenes.
- **Dynamic Asset Masking Overrides**: Integrated `getMaskedUrl()` across all index, video, file, and audio administrative dashboards and unified search/list APIs (`api.php`) to ensure historical items and direct clicks strictly copy clean local domain URLs.

### 🐛 Fixed
- **Dynamic Podcast Return Links**: Fixed podcast XML feeds (`podcast.xml` and `podcast_audio.xml`) rendering loopbacks like `127.0.0.1:6767` for the "返回主站點" (Return to Main Site) link by prioritizing the browser request's dynamic `$_SERVER['HTTP_HOST']`.
- **PDF & EPUB Viewers Repair**: Upgraded PDF preview embedding from standard `<iframe>` (blocked by clickjacking safety policies) to `<embed>` tags, and declared explicit block dimensions inside the details viewport to resolve collapsing black screen blocks.
- **Document Upload ("伺服器回應異常") Fix**: Restored proper standalone entry wrappers (`realpath(__FILE__)`) and rearranged helper function locations inside `api_file.php` to prevent fatal undefined function errors.

## [2026.5.13] - 2026-05-13

### ✨ Added
- **Queue Session Upload Stats**: Added per-batch success counters to the image, video, and file upload frontends so operators can immediately see how many items in the current queue run have completed successfully.
- **Device-Local Upload Totals**: Added browser `localStorage` counters for daily and cumulative uploads on each public upload frontend, tracked separately for images, videos, and files on the current device/browser.

### 📝 Docs
- **Frontend Upload Stats Proposal**: Added and completed the OpenSpec change artifacts for `frontend-upload-session-device-stats`, documenting the client-side session and device-local stats behavior.

## [2026.5.12] - 2026-05-13

### 🐛 Fixed
- **Video Upload Queue UI Overflow**: Fixed the legacy video upload page so the `開始依序上傳` action remains reachable even when the queue grows beyond 5 items or the viewport is narrow.
- **Video Password Admin Controls**: Added admin-side edit/remove password controls for uploaded videos, closing a gap where password-protected assets could not be adjusted after upload.
- **Podcast RSS Rebuild Reliability**: Changed video publish flow to rebuild `storage/podcast.xml` from the current database state instead of incrementally appending only, reducing feed drift on long-running deployments.
- **Podcast RSS Base URL Fallback**: Hardened RSS generation so site links no longer degrade to invalid placeholders like `https://` when rebuilds run without a normal browser host context.
- **Podcast RSS Permission Recovery**: Documented and reinforced the expectation that `storage/database.db`, `storage/podcast.xml`, and `storage/podcast.xml.lock` must remain writable by container user `www-data`, which is critical for live RSS updates.
- **Legacy SQLite Asset Flags**: Added runtime/install/migration self-healing for `images.is_video` and `images.is_file`, with backfill logic for older databases that predate the unified asset model.
- **Install Default Upload Cap**: Updated installation/bootstrap defaults so fresh deployments now seed `max_uploads_per_day=100` instead of `50`.

### 📝 Docs
- **Deployment Permission Notes**: Expanded `README.md` to explain Docker ownership expectations for `storage/`, how to diagnose `podcast.xml` permission regressions, and when to use `docker compose up -d --build` during upgrades.

## [2026.5.11] - 2026-05-08

### ✨ Added
- **Per-Frontend Upload History**: Added browser `localStorage` history for `upload_image.php`, `upload_video.php`, and `upload_file.php`, with per-page recent upload UI, copy/open actions, and clear-history controls.
- **Environment Template**: Added `.env.example` covering local, S3, OSS, UpYun, upload limits, and SMTP-related variables.

### 🐛 Fixed
- **Image Upload Auth Regression**: Fixed `api.php` same-origin image uploads failing with `身分驗證無效` by restoring session-aware validation and allowing same-host referers.
- **Upload Size Limit Fallbacks**: Fixed image and file uploads treating missing `max_file_size` config as `0`, which caused false upload rejections and `0MB` messaging on older databases.
- **Core Config Self-Healing**: Added automatic seeding of missing core config rows (`max_file_size`, `max_video_size`, `max_uploads_per_day`, `output_format`, etc.) during runtime bootstrap, install, and migration flows.
- **Centralized Schema Self-Healing**: Moved legacy SQLite column backfills into `config/database.php`, removed scattered runtime `ALTER TABLE` hacks from video entrypoints, and aligned install/migration table definitions with the current production schema.
- **Unified Schema Bootstrap Source**: Introduced `config/schema.php` as the single source of truth for core table creation, image-column backfills, config normalization, and default config seeding across runtime bootstrap, web install, shell install, and MySQL-to-SQLite migration.
- **Legacy Install Script S3 Keys**: Corrected `install.sh` to write `s3_access_key_id` / `s3_access_key_secret` instead of obsolete `s3_key` / `s3_secret`, and aligned prompts with the actual config model.
- **S3 Bootstrap Script**: Updated `setup_s3.sh` to emit `S3_ACL=public-read` and apply a public-read bucket policy so fresh AWS S3 deployments do not return `AccessDenied` for uploaded assets.
- **Frontend Size Messaging**: Fixed sub-1MB upload limit messages so they display `KB` or accurate `MB` values instead of `0MB`.
- **Image Frontend Config Read**: Fixed `static/js/main.js` so the image frontend reads `data-max-file-size` from the correct module script tag.
- **Video/File Upload Validation**: Hardened `video.php` and `api_file.php` size-limit and auth-related behavior to stay aligned with the unified upload gateway.
- **Rendered Skill Format**: Updated `skill.php` to render a standard `SKILL.md`-style document with YAML frontmatter while still injecting the live Base URL and token hints for the current deployment.
- **Public Skill Auth Guidance**: Corrected `skill.php` and `api.php` so public upload actions match the footer-facing product intent: public upload flows can run without a token when login restriction is off, while admin-style actions remain token-protected.

### 📝 Docs
- **README Refresh**: Updated install and S3 sections to document `.env.example`, `setup_s3.sh`, correct S3 variable names, and public-read requirements for AWS S3 buckets.

## [2026.5.10] - 2026-05-08

### ✨ Added
- **AI Agent Skill System (`skill.php`)**: Introduced a dynamic skill documentation endpoint that automatically detects the host domain and protocol (handling reverse proxies). It also injects the user's API token when logged in, making it a zero-configuration "one-click" integration for AI agents.
- **Unified Management Footer**: Standardized the footer across all administrative dashboards (`admin/index.php`, `admin/video.php`, `admin/file.php`) to match the front-end portal, enabling seamless switching between management modules and quick access to AI Skill docs.
- **MCP Tooling Support**: Formalized the system as a programmable asset platform, providing specific guidance for Model Context Protocol (MCP) agents to perform automated uploads, listing, and maintenance tasks.

### 🐛 Fixed
- **Admin Settings Loading Issue**: Resolved a syntax error in `admin/settings.php` caused by a missing array key (`output_format`), which previously caused the settings modal to fail during AJAX loading.
- **Protocol Detection**: Implemented robust protocol detection in `skill.php` using `X-Forwarded-Proto` headers to ensure correct HTTPS URLs are generated in proxied environments (e.g., Cloudflare/Nginx).

## [2026.5.9] - 2026-05-08

### ✨ Added
- **Unified Bento Portal**: Completely redesigned the root `index.php` as a modern, iOS-style Bento Grid portal for unified access to Image, Video, and File centers.
- **Document Hosting Center (`upload_file.php`)**: Added support for general documents including ZIP, PDF, Word, Excel, Visio, and EPUB.
- **EPUB Online Reader**: Integrated `epub.js` into the view portal, allowing users to read electronic books directly in the browser.
- **Unified Security Gatekeeper (`view.php`)**: Implemented a universal asset viewing gateway that handles secure access, analytics, and dynamic rendering for all media types.
- **Granular Password Protection**: Added the ability to set individual access passwords for images, videos, and files during upload.
- **Analytics Engine**: Implemented "Real View Count" tracking for all assets, visible in the administrative dashboards.
- **Reporting System (`api_report.php`)**: Added a user-facing "Report" feature for inappropriate content, integrated with an automated SMTP notification system.
- **SMTP Notification Backend**: Developed a Python-based SMTP mailer (`scripts/report_mail.py`) to handle high-reliability email alerts to administrators.
- **Admin Dashboards Consolidation**:
    - **File Management (`admin/file.php`)**: New dashboard for managing document assets.
    - **Reporting Statistics**: Added "Reported" status badges and hit-counts to Image, Video, and File admin panels.
- **Privacy Controls**: Updated the Podcast engine to automatically exclude password-protected videos from the public RSS feed.
- **Batch Video Metadata**: Added "Global Metadata" inputs to the video upload UI, allowing users to apply a single Title or Password to an entire batch of uploads.

### 💄 Style (UI/UX)
- **Glassmorphic Design**: Adopted a high-end, semi-transparent design language across the portal and view pages.
- **Mobile-First Navigation**: Optimized the Bento Grid for touch-screens with "iOS App" inspired layout and responsiveness.

## [2026.5.8] - 2026-05-08

### ✨ Added
- **Storage Consolidation**: Moved all writable data (SQLite database, local uploads `i/`, RSS feeds, logs) into a dedicated `storage/` directory for unified permission management and better Docker compatibility.
- **Dedicated Video Infrastructure**: Completely separated video upload and management from the original image-centric architecture.
- **Video Upload UI (`upload_video.php`)**: Added a brand new, dedicated user interface specifically for video uploads, featuring a wide-screen drag-and-drop zone and native video preview capabilities.
- **Video Admin Panel (`admin/video.php`)**: Created a dedicated administrative panel to manage, preview, copy links for, and delete uploaded videos.
- **Podcast RSS Generation (`storage/podcast.xml`)**: Implemented automated generation of iTunes-compliant Podcast RSS feeds containing all uploaded videos.
- **Metadata Editing (`api_edit_video.php`)**: Added the ability to edit the `Title` and `Description` of videos directly from the video admin panel, which instantly syncs the changes to the database and rebuilds the Podcast RSS feed.
- **Metadata on Upload**: Added input fields in the video upload UI to allow users to set the Podcast Title and Description at the time of upload.
- **Smart Title Extraction**: The video upload process now automatically extracts and uses the original uploaded filename (e.g., `MyVideo.mp4` -> `MyVideo`) as the default video title if no title is explicitly provided, preserving user-friendly names instead of randomized IDs.
- **Automatic FFmpeg Extraction**: The system now automatically uses FFmpeg (compiled into the Docker image) to extract video duration and resolution, and to generate a thumbnail image at the 1-second mark for the Podcast cover.
- **Configurable Video Limits**: Added a `max_video_size` parameter to the admin settings panel, allowing administrators to configure the maximum allowed video upload size via the UI (defaulting to 500MB).
- **Daily JSON List**: The system now generates a `storage/YYYY-MM-DD/videos.json` file daily to allow external automation bots to easily scrape newly uploaded videos.

### 🐛 Fixed
- **Missing Password Change UI**: Added password update fields to the admin settings panel, allowing administrators to change their password securely from the UI.
- **Database Permission Issue**: Resolved `SQLSTATE[HY000] [14] unable to open database file` error in Docker environments by moving the SQLite database to a writable sub-directory and ensuring correct directory permissions.
- **PHP Upload Limits**: Modified the `Dockerfile` to increase PHP's `upload_max_filesize`, `post_max_size`, and `memory_limit` to 500MB+ to prevent "No file uploaded" (無文件上傳) errors on large video files.
- **Hardcoded Size Constraints**: Removed arbitrary code logic that restricted video uploads to 50MB regardless of server configuration.
- **Zero-Size Database Corruption**: Fixed a critical bug where the local temporary video file was deleted (`unlink`) before its size was captured (`filesize()`), resulting in empty file size database records and NaN upload UI responses.
- **Admin Panel Rendering Crash**: Refactored `admin/video.php` size formatting logic (`floatval()`) to safely tolerate missing or corrupt file size data, ensuring the video grid always renders successfully even if past records are corrupted (fixing the "only one video shows up" bug).
- **Empty S3 Endpoints Validation**: Added robust validation and protocol auto-completion (`https://`) for S3 connection strings in `StorageHelper` to resolve AWS SDK `Invalid URI` initialization errors.
- **Admin Image Filter**: Fixed an issue where video files were causing broken image icons in the original `admin/index.php` by filtering them out of the image queries.
- **Database Schema Migration**: Implemented a robust auto-migration script that triggers when accessing the video admin panel to ensure `title` and `description` columns are added to existing databases, preventing "no such column" SQLite errors.

### 💄 Style (UI/UX)
- **Eradicated Unwanted Backgrounds**: Completely removed the hardcoded anime background image (`bg.webp`) from all views (Upload, Admin, Install) and replaced it with a professional, dark solid-color theme.
- **Terminology Localization**: Audited the entire codebase to replace Simplified Chinese terminologies and comments (e.g., 默认, 视频, 文件) with standard Traditional Chinese (Taiwan) terms (預設, 影片, 檔案).
- **Branding Update**: Changed the main site title and metadata descriptions to "888box" and removed mentions of Alibaba Cloud (OSS) in favor of emphasizing AWS S3 support.
- **Navigation Banners**: Added prominent navigation banners to easily guide users between the image interface and the new dedicated video interface.
