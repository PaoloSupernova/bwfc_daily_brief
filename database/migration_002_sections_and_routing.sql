-- Migration: Add routing rules, Local Community, Local Business, soft-delete
-- Run this AFTER the original schema.sql and seed.sql
-- Safe to run multiple times (uses IF NOT EXISTS where possible)

-- 1. Add routing_description column to sections for Claude's classification prompt
ALTER TABLE sections
    ADD COLUMN IF NOT EXISTS routing_description TEXT NULL
    AFTER name;

-- 2. Add soft-delete column to sections
ALTER TABLE sections
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL
    AFTER is_active;

-- 3. Update existing sections with routing rules
UPDATE sections SET routing_description = 'Articles primarily about Bolton Wanderers first team, academy, women''s team (when the angle is club-specific), Bolton Wanderers in the Community (BWitC) match or player stories, or Bolton Stadium Hotel football-related coverage.'
    WHERE slug = 'bwfc';

UPDATE sections SET routing_description = 'League One clubs other than Bolton, or the EFL as a governing body. Not general Premier League or Championship coverage.'
    WHERE slug = 'efl';

UPDATE sections SET routing_description = 'Women''s football at any level when the article is about the women''s game broadly, not specifically BWFC Women (those go to BWFC).'
    WHERE slug = 'womens_game';

UPDATE sections SET routing_description = 'Domestic or international football news covering commercial, political, environmental, or technological aspects. Premier League, Championship, European football, international tournaments, football governance.'
    WHERE slug = 'general_football';

UPDATE sections SET routing_description = 'Any non-football sport. Cricket, rugby, boxing, athletics, tennis, Formula 1, other sports news relevant to the business or fanbase.'
    WHERE slug = 'other_sport';

-- 4. Insert Local Community and Local Business sections
INSERT IGNORE INTO sections (slug, name, routing_description, display_order) VALUES
    ('local_community', 'Local Community', 'Bolton-area news that is not football. Bolton Council, community events, charities, schools, local news stories, non-football BWitC community partnership announcements.', 25),
    ('local_business', 'Local Business', 'Bolton-based businesses, BWFC sponsor news, local economy, Bolton Stadium Hotel commercial or venue angle, commercial partnership announcements affecting the club or town.', 45);

-- 5. Adjust display order so new sections sit between existing ones naturally
-- Order: BWFC (10), EFL (20), Local Community (25), Women's Game (30), General Football (40), Local Business (45), Other Sport (50)
UPDATE sections SET display_order = 10 WHERE slug = 'bwfc';
UPDATE sections SET display_order = 20 WHERE slug = 'efl';
UPDATE sections SET display_order = 25 WHERE slug = 'local_community';
UPDATE sections SET display_order = 30 WHERE slug = 'womens_game';
UPDATE sections SET display_order = 40 WHERE slug = 'general_football';
UPDATE sections SET display_order = 45 WHERE slug = 'local_business';
UPDATE sections SET display_order = 50 WHERE slug = 'other_sport';

-- 6. Update the section_suggest prompt template to include the new sections and use dynamic routing
UPDATE prompt_templates
SET template_body = 'Given the article content below, return exactly one label from this list:
BWFC, EFL, LOCAL_COMMUNITY, WOMENS_GAME, GENERAL_FOOTBALL, LOCAL_BUSINESS, OTHER_SPORT

Routing rules:
{{section_rules}}

Return only the single label. No punctuation, no explanation, no formatting.

ARTICLE:

Headline: {{headline}}
Outlet: {{outlet}}
Content preview:
{{content}}'
WHERE template_key = 'section_suggest';
