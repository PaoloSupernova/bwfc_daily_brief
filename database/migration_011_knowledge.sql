-- ============================================================
-- Migration 011: Knowledge base
--
-- An editable store of durable club facts, terminology and guidance that is
-- injected into the AI summary prompts for grounding and house consistency.
-- Also holds distilled "style rules" derived from editor edits (category
-- 'style'). Manage in Admin -> Knowledge.
-- ============================================================

CREATE TABLE IF NOT EXISTS knowledge_entries (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    category ENUM('fact','terminology','context','style','avoid','general') NOT NULL DEFAULT 'general',
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active, display_order)
) ENGINE=InnoDB;

-- A few sensible starter entries (edit or remove in Admin -> Knowledge).
INSERT INTO knowledge_entries (category, title, content) VALUES
    ('terminology', 'Club name', 'Refer to the club as "Bolton Wanderers", "Wanderers" or "Bolton" — never "BWFC" in prose. The nickname is "the Trotters".'),
    ('fact', 'Stadium', 'The home ground is the Toughsheet Community Stadium (formerly the University of Bolton Stadium / Reebok Stadium). Capacity ~28,000.'),
    ('context', 'Ownership', 'The club is chaired by Sharon Brittan; Football Ventures is the ownership group. David Ray is CEO and Fergal Harkin is Sporting Director.');
