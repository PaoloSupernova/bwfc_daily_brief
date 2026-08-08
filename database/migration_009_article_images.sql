-- ============================================================
-- Migration 009: Article images
--
-- Stores an optional lead image (og:image) per article, shown as a thumbnail
-- in the brief, review page and PDF. NULL = no image, which renders cleanly as
-- the existing text-only layout.
-- ============================================================

ALTER TABLE brief_articles ADD COLUMN image_url VARCHAR(2048) NULL AFTER url;
