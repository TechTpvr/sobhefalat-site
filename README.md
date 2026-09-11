# Sobhe Falat V3 — Intelligent News Engine

## What this package contains
- RTL minimalist WordPress theme
- Automatic RSS/Atom ingestion
- 10-minute scheduler
- Persian normalization and URL/title duplicate detection
- AI structured rewriting through OpenAI Responses API
- Page text + multiple image extraction
- Featured image and image attachments
- Automatic publishing or draft mode
- Source manager
- Dashboard, logs and manual run
- Optional Telegram notifications
- Health endpoint: `/wp-json/sobhe-falat/v3/health`

## Install
1. Install WordPress on a PHP/MySQL hosting account.
2. Upload `wordpress-plugin` as a plugin and activate it.
3. Upload `wordpress-theme` as a theme and activate it.
4. In WordPress: Settings / plugin page, enter your OpenAI API key and select an API model available to your account.
5. Review source feeds.
6. For reliable 10-minute execution, configure a real server cron to call WordPress cron, or use a hosting cron job. WP-Cron depends on site traffic.
7. Start in Draft mode, test sources, then enable automatic publishing.

## Important
GitHub stores the code; it is not the PHP runtime.
Feed URLs and page structures can change, so source-specific parsers may require maintenance.
No system can honestly guarantee zero runtime errors on external websites/APIs.
