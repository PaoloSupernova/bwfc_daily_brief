# BWFC Daily Brief — Server Deployment Guide

This guide covers deploying the BWFC Daily Brief from the local XAMPP machine to a work server with external access and Microsoft 365 (M365) single sign-on.

**Who this is for:** The communications team and the IT manager setting up the server environment.

---

## Overview

| Local (XAMPP) | Production Server |
|---|---|
| `AUTH_ENABLED=false` | `AUTH_ENABLED=true` |
| Auto-logged in as comms user | Microsoft 365 login required |
| `http://localhost/bwfc-daily-brief/public` | `https://brief.bwfc.co.uk` (your domain) |
| Windows Task Scheduler for jobs | Cron job on server |
| MySQL via phpMyAdmin | MySQL/MariaDB on server |

---

## Part 1 — Azure App Registration (IT Manager)

Before any server work, the IT manager needs to register the application in Azure / Microsoft Entra ID. This gives the app permission to authenticate your staff via their Microsoft 365 accounts.

### Steps

1. Sign in to the **Azure Portal** — [portal.azure.com](https://portal.azure.com) — with a Global Administrator or Application Administrator account.

2. Go to **Microsoft Entra ID** (search for it in the top bar) → **App registrations** → **New registration**.

3. Fill in:
   - **Name:** `BWFC Daily Brief`
   - **Supported account types:** `Accounts in this organizational directory only` (single tenant — your org only)
   - **Redirect URI:** Select `Web`, then enter:
     ```
     https://your-domain.com/auth/callback.php
     ```
     Replace `your-domain.com` with the actual domain (e.g. `brief.bwfc.co.uk`).

4. Click **Register**.

5. On the app overview page, copy these two values — you will need them in the `.env` file:
   - **Application (client) ID** → this is `AZURE_CLIENT_ID`
   - **Directory (tenant) ID** → this is `AZURE_TENANT_ID`

6. Go to **Certificates & secrets** → **New client secret**:
   - Description: `BWFC Daily Brief Production`
   - Expires: 24 months (recommended — set a calendar reminder to rotate before expiry)
   - Click **Add**, then **immediately copy the secret Value** (you cannot see it again after leaving the page)
   - This is `AZURE_CLIENT_SECRET`

7. Go to **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions**, and ensure these are present (they are added by default but confirm):
   - `openid`
   - `profile`
   - `email`
   - `User.Read`
   - Click **Grant admin consent for [your org]** so users are not individually prompted.

8. (Optional but recommended) Go to **Branding & properties** and set:
   - Logo: BWFC crest
   - Home page URL: `https://your-domain.com`
   - This is what users see on the Microsoft login page.

### Summary of values to record

| Variable | Where to find it |
|---|---|
| `AZURE_CLIENT_ID` | App registration → Overview → Application (client) ID |
| `AZURE_CLIENT_SECRET` | Certificates & secrets → the secret Value you copied |
| `AZURE_TENANT_ID` | App registration → Overview → Directory (tenant) ID |

---

## Part 2 — Server Requirements

The server needs:

| Requirement | Minimum |
|---|---|
| PHP | 8.1 or 8.2 |
| MySQL / MariaDB | 8.0 / 10.6 |
| Web server | Apache 2.4, Nginx, or IIS with PHP-CGI |
| HTTPS | Required — Microsoft will not send the callback over HTTP |
| PHP extensions | `curl`, `gd`, `mbstring`, `pdo_mysql`, `zip`, `xml`, `openssl`, `json` |
| Composer | 2.x |

---

## Part 3 — Server Setup

### 3.1 Clone the repository

SSH into the server (or use your IT manager's preferred method):

```bash
cd /var/www   # or wherever your web root is
git clone https://github.com/paolosupernova/bwfc_daily_brief.git bwfc_daily_brief
cd bwfc_daily_brief
```

### 3.2 Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```

If Composer is not installed on the server, the IT manager can install it by following [getcomposer.org/download](https://getcomposer.org/download).

### 3.3 Create the environment file

```bash
cp .env.example .env
nano .env   # or use any text editor
```

Fill in every value. The critical ones for production:

```env
APP_DEBUG=false
APP_TIMEZONE=Europe/London

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=bwfc_daily_brief
DB_USER=bwfc_user
DB_PASS=<strong-password>

ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-haiku-4-5-20251001
ANTHROPIC_TIMEOUT=120
ANTHROPIC_CONNECT_TIMEOUT=30

FETCH_TIMEOUT=15
FETCH_USER_AGENT=Mozilla/5.0 (compatible; BWFCDailyBrief/1.0)

AUTH_ENABLED=true
APP_URL=https://your-domain.com

AZURE_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
AZURE_CLIENT_SECRET=<secret from Azure Portal>
AZURE_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
AZURE_ALLOWED_EMAILS=person1@bwfc.co.uk,person2@bwfc.co.uk
```

**`AZURE_ALLOWED_EMAILS`** — list every comms team member who should have access. Anyone not listed will be blocked even if they have a valid M365 account. Separate with commas, no spaces.

Set restrictive permissions on `.env`:

```bash
chmod 640 .env
chown www-data:www-data .env   # adjust user to match your web server
```

### 3.4 Set up the database

Create the database and a dedicated user:

```sql
CREATE DATABASE bwfc_daily_brief CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bwfc_user'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT ALL PRIVILEGES ON bwfc_daily_brief.* TO 'bwfc_user'@'localhost';
FLUSH PRIVILEGES;
```

See **Part 5** for importing the full database from your local machine.

### 3.5 Web server configuration

The document root **must** point to the `public/` subdirectory, not the project root. This keeps `src/`, `config/`, `.env`, and other sensitive files private.

#### Apache

Create or edit the virtual host:

```apache
<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/bwfc_daily_brief/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/your-domain.crt
    SSLCertificateKeyFile /etc/ssl/private/your-domain.key

    <Directory /var/www/bwfc_daily_brief/public>
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>

    # Redirect HTTP to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    ErrorLog  /var/log/apache2/bwfc_brief_error.log
    CustomLog /var/log/apache2/bwfc_brief_access.log combined
</VirtualHost>
```

Create `/var/www/bwfc_daily_brief/public/.htaccess` if it doesn't exist:

```apache
Options -Indexes
```

Enable the site and restart Apache:

```bash
a2ensite bwfc_daily_brief
a2enmod rewrite ssl
systemctl restart apache2
```

#### Nginx

```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;

    root /var/www/bwfc_daily_brief/public;
    index index.php;

    ssl_certificate     /etc/ssl/certs/your-domain.crt;
    ssl_certificate_key /etc/ssl/private/your-domain.key;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

```bash
nginx -t && systemctl reload nginx
```

#### IIS (Windows Server)

1. Install IIS with the CGI role feature and PHP 8.x via the Web Platform Installer or manually.
2. Set the site's **Physical path** to `C:\inetpub\wwwroot\bwfc_daily_brief\public`.
3. Add a `web.config` in the `public/` folder:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <directoryBrowse enabled="false" />
    <defaultDocument>
      <files>
        <add value="index.php" />
      </files>
    </defaultDocument>
    <rewrite>
      <rules>
        <rule name="Force HTTPS" stopProcessing="true">
          <match url="(.*)" />
          <conditions>
            <add input="{HTTPS}" pattern="^OFF$" />
          </conditions>
          <action type="Redirect" url="https://{HTTP_HOST}/{R:1}" redirectType="Permanent" />
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>
```

4. Bind an SSL certificate to the site in IIS Manager.

### 3.6 File permissions

```bash
# Web server must be able to write to the temp directory PHP uses
# (PhpWord and mPDF write temp files)
# Usually fine by default, but if you hit permission errors:
chmod -R 755 /var/www/bwfc_daily_brief
chmod -R 775 /var/www/bwfc_daily_brief/public
chown -R www-data:www-data /var/www/bwfc_daily_brief
```

### 3.7 SSL certificate

If you don't have an SSL certificate, the IT manager can obtain one from:
- Your organisation's existing wildcard certificate, if available
- Let's Encrypt (free) via Certbot: `certbot --apache -d your-domain.com`

Microsoft **requires** HTTPS for the OAuth2 callback — the login flow will fail without it.

---

## Part 4 — Scheduled Jobs (Cron)

On Linux, replace the Windows Task Scheduler entry with a cron job.

```bash
crontab -e
```

Add:

```cron
# BWFC Daily Brief — run jobs every day at 06:30
30 6 * * * /usr/bin/php /var/www/bwfc_daily_brief/bin/run_jobs.php >> /var/log/bwfc_brief_cron.log 2>&1
```

The script skips discovery if sources are within their polling window and only generates insights on Mondays unless forced.

On **Windows Server (IIS)**, use Task Scheduler as documented in SETUP.md, pointing to the server's PHP executable and the `bin/run_jobs.php` script.

---

## Part 5 — Transferring the Database from Local to Server

This moves all your existing briefs, articles, sources, and settings across.

### 5.1 Export from local XAMPP

On your Windows machine:

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Select the `bwfc_daily_brief` database from the left panel.
3. Click **Export** → **Custom** method.
4. Under **Output**, tick **Save output to a file** and set format to **SQL**.
5. Under **Data creation options** → ensure **Complete inserts** is ticked.
6. Click **Export**. Save the file as `bwfc_daily_brief_export.sql`.

Or via command line on Windows:

```cmd
C:\xampp_new\mysql\bin\mysqldump -u root -p bwfc_daily_brief > bwfc_daily_brief_export.sql
```

### 5.2 Transfer the file to the server

Use SCP, SFTP, or your IT manager's preferred file transfer method:

```bash
scp bwfc_daily_brief_export.sql user@your-server:/tmp/
```

### 5.3 Import on the server

```bash
mysql -u bwfc_user -p bwfc_daily_brief < /tmp/bwfc_daily_brief_export.sql
```

Or via phpMyAdmin on the server:
1. Select the `bwfc_daily_brief` database.
2. Click **Import** → **Choose file** → select the `.sql` file → **Go**.

### 5.4 Transfer brand assets (if not in git)

If the `public/img/` folder contains custom images not committed to the repository, copy them:

```bash
scp -r C:\xampp_new\htdocs\bwfc-daily-brief\public\img\* user@your-server:/var/www/bwfc_daily_brief/public/img/
```

---

## Part 6 — First Login Test

1. Open `https://your-domain.com` in a browser.
2. You should be redirected to the Microsoft login page.
3. Sign in with your BWFC Microsoft 365 account.
4. Microsoft redirects back to `https://your-domain.com/auth/callback.php`.
5. You should land on the BWFC Daily Brief dashboard.

**If login fails:** Check the error message on screen. Common causes:

| Error | Fix |
|---|---|
| `OAuth2 state mismatch` | Session not persisting between requests. Check PHP session configuration and that cookies work over HTTPS. |
| `Your account has not been granted access` | Add the email to `AZURE_ALLOWED_EMAILS` in `.env`. |
| `Microsoft login error: AADSTS...` | Check Client ID, Secret, and Tenant ID in `.env`. Check the Redirect URI in Azure Portal matches exactly. |
| `No authorisation code returned` | The Redirect URI in Azure does not match `APP_URL`. They must be identical character for character. |
| Blank page / 500 error | Enable `APP_DEBUG=true` temporarily, check PHP error logs. |

---

## Part 7 — Adding or Removing Users

The app uses an **allowlist** model. Only email addresses listed in `AZURE_ALLOWED_EMAILS` can log in.

### To add a user

1. SSH into the server (or use a file manager).
2. Edit `.env`:
   ```
   AZURE_ALLOWED_EMAILS=existing@bwfc.co.uk,newperson@bwfc.co.uk
   ```
3. Save. No restart needed — the file is read on each request.

### To remove a user

Remove their email from `AZURE_ALLOWED_EMAILS`. Their next login attempt will be rejected. Any existing session will remain valid until they sign out or the session expires (typically 24 hours).

To immediately invalidate a session, restart PHP-FPM (Linux):

```bash
systemctl restart php8.2-fpm
```

### Roles

All users currently have `editor` role by default. If you need to grant `admin` role to someone (for access to the Admin panel), update their record directly in the database:

```sql
UPDATE users SET role = 'admin' WHERE email = 'person@bwfc.co.uk';
```

---

## Part 8 — Keeping the App Updated

When new versions are pushed to the `main` branch:

```bash
cd /var/www/bwfc_daily_brief
git pull origin main
composer install --no-dev --optimize-autoloader
```

If there are new database migration files in `database/migration_*.sql`, run them in order:

```bash
mysql -u bwfc_user -p bwfc_daily_brief < database/migration_006_whatever.sql
```

---

## Quick Reference

| Item | Value |
|---|---|
| App URL | `https://your-domain.com` |
| Document root | `/var/www/bwfc_daily_brief/public` |
| Environment file | `/var/www/bwfc_daily_brief/.env` |
| Azure redirect URI | `https://your-domain.com/auth/callback.php` |
| Cron log | `/var/log/bwfc_brief_cron.log` |
| PHP error log | `/var/log/apache2/bwfc_brief_error.log` |
| Database | `bwfc_daily_brief` |
