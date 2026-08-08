-- ============================================================
-- Migration 008: Article topic classification
--
-- Adds a controlled "topic" tag to each article — what the story is about
-- (transfer, match, injury, ownership, …), distinct from section (routing)
-- and sentiment (tone). Powers the topic breakdown on the dashboard, weekly
-- insights, and the media report.
-- ============================================================

ALTER TABLE brief_articles ADD COLUMN topic VARCHAR(40) NULL AFTER sentiment;
ALTER TABLE brief_articles ADD INDEX idx_topic (topic);
