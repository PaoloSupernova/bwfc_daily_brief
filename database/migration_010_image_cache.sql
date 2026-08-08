-- ============================================================
-- Migration 010: Local image cache path
--
-- Stores the locally-cached copy of an article's image (relative web path,
-- e.g. "img/cache/<hash>.jpg"). Rendered outputs embed this cached file so
-- images appear reliably in the browser, Outlook HTML and PDF regardless of
-- the source site's hotlink protection. NULL = not cached (falls back to the
-- remote image_url).
-- ============================================================

ALTER TABLE brief_articles ADD COLUMN image_cached VARCHAR(255) NULL AFTER image_url;
