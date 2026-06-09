-- Migration 003: Related / duplicate coverage support
-- Adds parent_article_id to brief_articles so a second outlet's
-- coverage of the same story can be stored as a "More:" link
-- rather than a full duplicate summary.

ALTER TABLE brief_articles
    ADD COLUMN parent_article_id INT UNSIGNED NULL DEFAULT NULL AFTER sentiment;

-- ON DELETE SET NULL: if a parent is deleted the child is orphaned
-- (the delete endpoint handles cascade in code).
ALTER TABLE brief_articles
    ADD CONSTRAINT fk_articles_parent
        FOREIGN KEY (parent_article_id) REFERENCES brief_articles(id)
        ON DELETE SET NULL;
