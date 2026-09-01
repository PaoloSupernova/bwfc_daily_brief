-- ============================================================
-- Migration 013: Source language for translated articles
--
-- When an article's source is not in English it is translated to English
-- before summarising; this records the original language so the brief can note
-- "Translated from French" etc. NULL = original was English (no note shown).
-- ============================================================

ALTER TABLE brief_articles ADD COLUMN source_language VARCHAR(30) NULL AFTER topic;
