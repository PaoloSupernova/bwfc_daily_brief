# BWFC Daily Brief — Setup Guide

Complete installation instructions for setting up the BWFC Daily Brief tool on a new Windows machine, including transferring all existing data from the current installation.

---

## Prerequisites

Install the following before starting. All are free.

| Software | Version | Download |
|----------|---------|----------|
| XAMPP | 8.1 or later | [apachefriends.org](https://www.apachefriends.org) |
| Git | Any recent | [git-scm.com](https://git-scm.com) |
| Composer | 2.x | [getcomposer.org/download](https://getcomposer.org/download) |

> **XAMPP install note:** During installation, when asked which components to include, make sure **Apache**, **MySQL**, and **PHP** are all ticked.

---

## Part 1 — Install on the New Machine

### Step 1 — Clone the repository

Open **PowerShell** (or Git Bash) and run:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/PaoloSupernova/bwfc_daily_brief.git bwfc-daily-brief
cd bwfc-daily-brief
```

---

### Step 2 — Install PHP dependencies

Still inside `C:\xampp\htdocs\bwfc-daily-brief`:

```powershell
composer install
```

This installs mPDF (PDF generation), PHPWord (Word export), and all other dependencies into the `vendor/` folder. Takes about 30–60 seconds.

If `composer` is not recognised, use:

```powershell
php C:\xampp\htdocs\bwfc-daily-brief\composer.phar install
```

---

### Step 3 — Configure environment variables

Copy the example file and fill in your values:

```powershell
copy .env.example .env
notepad .env
```

Edit the following values:

```env
# Database — match your XAMPP MySQL setup
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=bwfc_daily_brief
DB_USER=root
DB_PASS=           ← leave blank if your MySQL has no root password (default XAMPP)

# Claude API — get this from console.anthropic.com
ANTHROPIC_API_KEY=sk-ant-...

# Leave these at their defaults unless told otherwise
APP_DEBUG=false
APP_TIMEZONE=Europe/London
ANTHROPIC_MODEL=claude-haiku-4-5-20251001
ANTHROPIC_MAX_TOKENS=1024
AUTH_ENABLED=false
```

Save and close Notepad.

---

### Step 4 — Enable required PHP extensions

Open `C:\xampp\php\php.ini` in Notepad and make sure the following lines are **uncommented** (remove the leading `;` if present):

```ini
extension=curl
extension=gd
extension=mbstring
extension=pdo_mysql
extension=zip
extension=xml
```

Also set these OPcache values so PHP always picks up file changes immediately:

```ini
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

Save `php.ini` and **restart Apache** in the XAMPP Control Panel.

---

### Step 5 — Create the database

1. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
2. Open your browser and go to: `http://localhost/phpmyadmin`
3. Click **New** in the left sidebar.
4. Name the database `bwfc_daily_brief`, set collation to `utf8mb4_unicode_ci`, click **Create**.

---

### Step 6 — Run the database schema and seed

Still in phpMyAdmin, select the `bwfc_daily_brief` database, click the **Import** tab, and import the following files **in this exact order**:

| Order | File | What it does |
|-------|------|-------------|
| 1 | `database/schema.sql` | Creates all base tables |
| 2 | `database/seed.sql` | Inserts default sections, prompt templates, and demo user |
| 3 | `database/migration_002_sections_and_routing.sql` | Adds routing descriptions to sections |
| 4 | `database/migration_003_related_articles.sql` | Adds related coverage support |
| 5 | `database/migration_004_discovery_and_insights.sql` | RSS source registry, queue, weekly insights |
| 6 | `database/migration_005_harden_summary_prompt.sql` | Updates AI summary prompt for accuracy |
| 7 | `database/migration_006_journalists.sql` | Journalist byline tracking (journalists table, per-section flag) |

To import each file: **Import tab → Choose File → select the file → Go**.

> **Skip steps 5, 6 and 7 if you are restoring from a database export** — see Part 2 (Data Transfer) instead.

---

### Step 7 — Copy brand assets

The following files are not in the repository (they contain club-owned assets or were installed separately). Copy them from the old machine:

```
src/fonts-pdf/          ← custom brand fonts for PDF export (Nippo, Satoshi, Built Titling)
public/img/             ← BWFC logo and marque images
```

Connect to the old machine (USB drive, shared network folder, or OneDrive) and copy both folders across to the same paths on the new machine.

---

### Step 8 — Test the installation

Open your browser and go to:

```
http://localhost/bwfc-daily-brief/public/
```

You should see the BWFC Daily Brief dashboard. If you see an error:

- **Database connection failed** → Check MySQL is running and your `.env` DB settings match.
- **Anthropic API key missing** → Check `ANTHROPIC_API_KEY` in `.env` starts with `sk-ant-`.
- **Blank page / 500 error** → Set `APP_DEBUG=true` in `.env` temporarily to see the error, then set it back to `false`.

---

### Step 9 — Set up scheduled jobs (Windows Task Scheduler)

The tool has two automated jobs — RSS feed polling and weekly insights generation. Set these up so they run automatically each morning.

1. Press **Win + S**, search for **Task Scheduler**, open it.
2. Click **Create Basic Task** on the right.
3. Fill in:

| Field | Value |
|-------|-------|
| Name | BWFC Daily Brief Jobs |
| Trigger | Daily |
| Start time | 06:30 AM |
| Action | Start a program |
| Program | `C:\xampp\php\php.exe` |
| Arguments | `C:\xampp\htdocs\bwfc-daily-brief\bin\run_jobs.php` |
| Start in | `C:\xampp\htdocs\bwfc-daily-brief` |

4. Click **Finish**.

The script polls all active RSS feeds and (on Mondays) generates the weekly insights summary. You can also trigger both manually from **Admin → Jobs** in the app.

---

## Part 2 — Transfer Existing Data from the Old Machine

Do this section on the **old machine** first, then bring the files across.

### Step A — Export the database

1. Open `http://localhost/phpmyadmin` on the old machine.
2. Select the `bwfc_daily_brief` database in the left sidebar.
3. Click the **Export** tab.
4. Method: **Quick**, Format: **SQL**.
5. Click **Go** — this downloads a `.sql` file (e.g. `bwfc_daily_brief.sql`).

### Step B — Transfer the export file

Copy the `.sql` file to the new machine via USB, OneDrive, or a network share.

### Step C — Import on the new machine

1. On the new machine, open `http://localhost/phpmyadmin`.
2. Select (or create) the `bwfc_daily_brief` database.
3. Click **Import**, choose the `.sql` file, click **Go**.

> If you import a full database export, **skip Steps 5 and 6** from Part 1 — the schema, seed, and migrations are already included in the export.

### Step D — Transfer your `.env` file

Copy `.env` from `C:\xampp_new\htdocs\bwfc-daily-brief\.env` on the old machine to the same path on the new machine. This brings across your API key and database credentials without having to retype them.

> Remember to update `DB_PASS` if the new machine's MySQL root password differs.

### Step E — Transfer brand assets

Copy these folders from the old machine to the new:

```
src/fonts-pdf/    →    C:\xampp\htdocs\bwfc-daily-brief\src\fonts-pdf\
public/img/       →    C:\xampp\htdocs\bwfc-daily-brief\public\img\
```

---

## Troubleshooting

### "Class not found" errors after a git pull

Run `composer install` again — a pull may have added new library dependencies.

### OPcache serving stale files

PHP caches compiled scripts. If code changes aren't appearing after a pull:

1. Open `C:\xampp\php\php.ini`
2. Confirm `opcache.validate_timestamps=1` and `opcache.revalidate_freq=0`
3. Restart Apache in the XAMPP Control Panel

### Word export fails — "PhpOffice\PhpWord\PhpWord not found"

Run `composer require phpoffice/phpword` in the project directory.

### PDF export fails — "mPDF not found"

Run `composer install` in the project directory.

### Queue "Refresh feeds" fails

Check that MySQL is running and that the `discovery_sources` table exists (migration 004 must have been run).

### Port conflicts (Apache won't start)

If Apache fails to start because port 80 is in use (often by IIS or another web server on a work machine):

1. In XAMPP Control Panel, click **Config** next to Apache → `httpd.conf`
2. Change `Listen 80` to `Listen 8080`
3. Access the app at `http://localhost:8080/bwfc-daily-brief/public/`

---

## Quick Reference

| What | Where |
|------|-------|
| App URL | `http://localhost/bwfc-daily-brief/public/` |
| phpMyAdmin | `http://localhost/phpmyadmin` |
| Environment config | `C:\xampp\htdocs\bwfc-daily-brief\.env` |
| PHP config | `C:\xampp\php\php.ini` |
| Apache logs | `C:\xampp\apache\logs\error.log` |
| PHP error log | `C:\xampp\php\logs\php_error_log` |
| Run jobs manually | `php bin\run_jobs.php` |
| Install/update libs | `composer install` |

---

*BWFC Daily Brief — Internal use only. Bolton Wanderers Football Club Communications Team.*
