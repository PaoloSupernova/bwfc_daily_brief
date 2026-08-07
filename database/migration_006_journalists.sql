-- ============================================================
-- Migration 006: Journalist byline tracking
--
-- Adds journalist attribution to articles so the team can see who
-- writes about the club and the sentiment of their coverage.
--
--   journalists            one row per person (name-only identity)
--   article_journalists    many-to-many: an article can credit several authors
--   brief_articles.byline_raw   the raw detected byline, for reference/re-parse
--   sections.counts_for_journalists   which sections feed the journalist stats
--
-- Idempotent where practical so it is safe to re-run.
-- ============================================================

-- --- journalists ------------------------------------------------
CREATE TABLE IF NOT EXISTS journalists (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    -- normalised key for name-only identity (lowercased, punctuation-stripped)
    name_key VARCHAR(200) NOT NULL,
    last_outlet VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name_key (name_key)
) ENGINE=InnoDB;

-- --- article_journalists (join) ---------------------------------
CREATE TABLE IF NOT EXISTS article_journalists (
    article_id INT UNSIGNED NOT NULL,
    journalist_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id, journalist_id),
    INDEX idx_journalist (journalist_id),
    CONSTRAINT fk_aj_article FOREIGN KEY (article_id) REFERENCES brief_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_aj_journalist FOREIGN KEY (journalist_id) REFERENCES journalists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- brief_articles.byline_raw ----------------------------------
-- Guarded add: ignore the error if the column already exists on re-run.
ALTER TABLE brief_articles ADD COLUMN byline_raw VARCHAR(500) NULL AFTER outlet_name;

-- --- sections.counts_for_journalists ----------------------------
ALTER TABLE sections ADD COLUMN counts_for_journalists TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- Default: count BWFC and EFL coverage toward journalist stats.
UPDATE sections SET counts_for_journalists = 1 WHERE slug IN ('bwfc', 'efl');
