-- ============================================================
-- Migration 012: Story tracker
--
-- Saved "tracked stories" — a named narrative with keywords. The timeline and
-- stats are computed live from the article archive (FULLTEXT), so no re-tagging
-- is needed. Manage on the Stories page.
-- ============================================================

CREATE TABLE IF NOT EXISTS tracked_stories (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    keywords VARCHAR(500) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB;
