# BWFC Daily Brief

Internal tool for the Bolton Wanderers Communications Team. Paste a link, get a Claude-generated summary that matches our house style, build the daily brief, distribute it.

## Stack

- PHP 8.1+ (XAMPP local development)
- MySQL 8.0+
- Alpine.js for reactivity (single script tag, no build step)
- Anthropic Claude API (Haiku 4.5)

## What it does

1. Comms team member pastes an article URL.
2. App fetches the article content. If the outlet blocks scrapers (Daily Mail, The Sun, Off the Pitch, paywalled Guardian), a paste fallback opens.
3. Claude generates a one-paragraph summary in BWFC house style and suggests a section.
4. User edits the summary if needed, or regenerates for another attempt.
5. Article commits to the brief under the chosen section.
6. Repeat until all articles are in.
7. Claude generates a two-paragraph executive summary covering everything.
8. User copies the HTML block into Outlook, or exports a branded PDF.
9. Brief locks on send. Full audit trail retained.

## Project phases

- **Phase 1 (MVP)**: Core brief creation flow, HTML output, BWFC styling. This build.
- **Phase 2**: PDF export, archive with full-text search, admin UI for style rules and sections, RSS polling of bwfc.co.uk, weekend roll-up toggle.
- **Phase 3**: M365 SSO activation, audit trail UI, sentiment tagging surface, BWFC server deployment.

## Setup (local XAMPP)

1. Clone to `C:\xampp\htdocs\bwfc-daily-brief` (Windows) or `/Applications/XAMPP/htdocs/bwfc-daily-brief` (Mac).
2. Start Apache and MySQL in XAMPP control panel.
3. Open phpMyAdmin, create database `bwfc_daily_brief`.
4. Import `database/schema.sql`, then `database/seed.sql`.
5. Copy `.env.example` to `.env` and add your Anthropic API key.
6. Run `composer install` from the project root.
7. Navigate to `http://localhost/bwfc-daily-brief/public/` in your browser.

## House style

All Claude prompts live in the `prompt_templates` table. They encode British English, banned words (testament, fostering, unwavering, heartfelt, tapestry, navigating, beacon, underscore, merely, pivotal, elevating, serving, embodying, delving, reflecting, cornerstone, significant), forbidden phrases and clichés, punctuation rules (no em dashes), grammar rules (no parallel structures, no correlative conjunctions), and familiar naming conventions (Steven Schumacher, not Head Coach Steven Schumacher).

## Security notes

- `.env` is gitignored. Never commit API keys.
- On local XAMPP, no login is required. SSO stubs sit in `src/Auth.php` for activation on deployment.
- Sent briefs are immutable. The audit log captures every action with timestamps.

## Repo

Target repo: `bwfc-daily-brief` on GitHub. Private, Comms team access only.

## Contact

Paul Holliday, Group Head of Marketing and Communications.
