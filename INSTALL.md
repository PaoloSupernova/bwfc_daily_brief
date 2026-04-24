# BWFC Daily Brief - v1.1 Update Instructions

This update adds:
- Local Community and Local Business sections (permanent, seeded)
- Admin page to manage sections (add, rename, delete, reorder, edit routing rules)
- Article reordering via drag-and-drop or up/down arrows
- Final review screen with inline editing of everything
- Three export formats: Outlook HTML, Plain text (mimics existing email template), PDF
- mPDF integration for branded PDF exports

---

## Step 1: Run the database migration

Open phpMyAdmin and select the `bwfc_daily_brief` database.

1. Click the **Import** tab.
2. Choose file: `database/migration_002_sections_and_routing.sql`
3. Click **Go** at the bottom.

You should see "Import has been successfully finished" with 5 or so queries executed. The migration is idempotent, so if you run it twice, nothing breaks.

Verify by opening the `sections` table. You should see 7 rows, in this order: BWFC, EFL, Local Community, Women's Game, General Football, Local Business, Other Sport.

---

## Step 2: Install mPDF via Composer

Open Command Prompt in the project folder:

```
cd C:\xampp\htdocs\bwfc-daily-brief
```

Replace your existing `composer.json` with the new one, then run:

```
composer update
```

This adds `mpdf/mpdf` alongside `vlucas/phpdotenv`. Takes about 30 seconds (mPDF has a few dependencies).

If you get an error about `ext-gd`, open `C:\xampp\php\php.ini`, find the line `;extension=gd`, remove the semicolon, save the file, restart Apache in XAMPP.

---

## Step 3: Copy the updated files

Overwrite these files in your project folder. The full list by folder:

**Root folder:**
- `composer.json`

**`src/` folder (PHP classes):**
- `BriefRenderer.php` (replace)
- `BriefRepository.php` (replace)
- `Summariser.php` (replace)
- `PdfExporter.php` (NEW)

**`api/` folder:**
- `render.php` (NEW - unified renderer)
- `export_pdf.php` (NEW)
- `reorder_articles.php` (NEW)
- `move_article.php` (NEW)
- `sections_list.php` (NEW)
- `sections_create.php` (NEW)
- `sections_update.php` (NEW)
- `sections_delete.php` (NEW)
- `sections_reorder.php` (NEW)

**`views/` folder:**
- `layout.php` (replace - adds Admin link, SortableJS)
- `brief/_editor.php` (replace - Review button)
- `brief/review.php` (NEW - final review screen)
- `admin/sections.php` (NEW - admin UI)
- `admin/index.php` (NEW - redirect stub)

**`public/` folder:**
- `index.php` (replace - new routes)
- `css/app.css` (replace - new component styles)
- `js/app.js` (replace - three Alpine components)

---

## Step 4: Test

Hard-refresh the browser with **Ctrl + F5**.

1. Click **Admin** in the nav bar. You should see all seven sections listed with their routing rules. Try dragging a section to reorder it.

2. Click **New Brief** and add an article. Claude should now route articles to Local Community (e.g. a Bolton Council story) or Local Business (e.g. a sponsor announcement) when appropriate.

3. After adding 2-3 articles, click **Review & export** at the bottom. You'll land on the review screen where you can edit anything inline, drag articles to reorder, and export in three formats.

4. On the review screen, click **Download PDF**. A branded PDF should save to your Downloads folder.

---

## Troubleshooting

**"Class 'Mpdf\Mpdf' not found"**: Composer didn't install mPDF. Re-run `composer update` in the project root.

**PDF is blank or has encoding errors**: mPDF needs write access to its temp directory. Check that `C:\Windows\Temp\mpdf_bwfc` is writable by Apache.

**Admin link shows 404**: The router update didn't apply. Confirm `public/index.php` is the new version and hard-refresh.

**Dragging articles doesn't save the new order**: Check the browser console (F12). If you see a reference to SortableJS not loading, confirm the CDN script is loading from the Network tab.

**Empty section dropdown on review screen**: Check that migration ran and `sections` table has 7 rows.

---

## Rollback

If anything goes wrong, you can revert the migration:

```sql
ALTER TABLE sections DROP COLUMN IF EXISTS routing_description;
ALTER TABLE sections DROP COLUMN IF EXISTS deleted_at;
DELETE FROM sections WHERE slug IN ('local_community', 'local_business');
```

Then restore the previous versions of the PHP/JS/CSS files from your git history.
