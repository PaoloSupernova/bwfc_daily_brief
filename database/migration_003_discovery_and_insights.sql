-- ============================================================
-- BWFC Daily Brief - Migration 003
-- Phase 2.1: Source registry + 2.2: Discovery queue + Weekly insights
-- ============================================================
-- Idempotent: safe to run multiple times. Each ALTER and CREATE checks
-- for existence first.
-- ============================================================

-- ------------------------------------------------------------
-- 1. discovery_sources: feeds we poll for new articles
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS discovery_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    url TEXT NOT NULL,
    source_type ENUM('rss', 'google_news') NOT NULL DEFAULT 'rss',
    is_local TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    poll_frequency_minutes INT NOT NULL DEFAULT 60,
    last_polled_at TIMESTAMP NULL DEFAULT NULL,
    last_success_at TIMESTAMP NULL DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_active (is_active, deleted_at),
    INDEX idx_polling (is_active, last_polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. discovery_polls: history of each poll attempt (audit + diagnostics)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS discovery_polls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    items_found INT NOT NULL DEFAULT 0,
    items_new INT NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    INDEX idx_source_recent (source_id, started_at),
    FOREIGN KEY (source_id) REFERENCES discovery_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. discovery_candidates: articles found by polling, awaiting ingest decision
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS discovery_candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    url TEXT NOT NULL,
    url_hash CHAR(64) NOT NULL,
    headline TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    outlet_name VARCHAR(150) DEFAULT NULL,
    published_at TIMESTAMP NULL DEFAULT NULL,
    discovered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('new', 'ingested', 'rejected', 'duplicate') NOT NULL DEFAULT 'new',
    ingested_article_id INT DEFAULT NULL,
    rejected_reason VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uniq_url_hash (url_hash),
    INDEX idx_status_discovered (status, discovered_at),
    INDEX idx_source (source_id),
    FOREIGN KEY (source_id) REFERENCES discovery_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. weekly_insights: Sunday-night Claude-generated week summaries
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS weekly_insights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    summary_text TEXT NOT NULL,
    metrics_json JSON DEFAULT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by VARCHAR(50) NOT NULL DEFAULT 'scheduled',
    UNIQUE KEY uniq_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. job_runs: scheduled job history (for the admin/jobs page)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(50) NOT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    items_processed INT NOT NULL DEFAULT 0,
    output TEXT DEFAULT NULL,
    INDEX idx_job_recent (job_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Seed default discovery sources
-- ------------------------------------------------------------

INSERT IGNORE INTO discovery_sources (name, url, source_type, is_local, is_active, poll_frequency_minutes) VALUES
    ('bwfc.co.uk', 'https://www.bwfc.co.uk/api/incrowd/getnewlistinformation?count=20&id=51', 'rss', 1, 1, 60),
    ('Bolton News (Sport)', 'https://www.theboltonnews.co.uk/sport/rss/', 'rss', 1, 1, 60),
    ('Bolton News (News)', 'https://www.theboltonnews.co.uk/news/rss/', 'rss', 1, 1, 120),
    ('EFL News', 'https://www.efl.com/-rss/news/', 'rss', 0, 1, 120),
    ('BBC Sport - Football', 'https://feeds.bbci.co.uk/sport/football/rss.xml', 'rss', 0, 1, 180),
    ('Google News - Bolton Wanderers', 'https://news.google.com/rss/search?q=%22Bolton+Wanderers%22&hl=en-GB&gl=GB&ceid=GB:en', 'google_news', 0, 1, 60),
    ('Google News - BWFC Schumacher', 'https://news.google.com/rss/search?q=%22Steven+Schumacher%22+Bolton&hl=en-GB&gl=GB&ceid=GB:en', 'google_news', 0, 1, 120),
    ('Google News - Bolton FC', 'https://news.google.com/rss/search?q=%22Bolton+FC%22+OR+%22BWFC%22&hl=en-GB&gl=GB&ceid=GB:en', 'google_news', 0, 1, 120);

-- ------------------------------------------------------------
-- 7. Add prompt template for weekly insights
-- ------------------------------------------------------------

INSERT IGNORE INTO prompt_templates (slug, version, system_prompt, user_prompt_template, is_active) VALUES (
    'weekly_insights',
    1,
    'You are a senior football communications strategist analysing a week of media coverage about Bolton Wanderers Football Club. Write in British English. Use active voice. Be direct and analytical, not promotional. Avoid em dashes, the words "testament", "fostering", "unwavering", "heartfelt", "tapestry", "navigating", "beacon", "underscore", "merely", "pivotal", "elevating", "serving", "embodying", "delving", "reflecting", "cornerstone", "significant". Avoid corporate buzzwords. Avoid parallelism. Length: 3 paragraphs maximum, around 200 words total.',
    'Produce a weekly insights summary for the week of {{week_start}} to {{week_end}}.

Coverage metrics for the week:
{{metrics}}

A sample of the briefs sent this week:
{{sample}}

Comparison vs prior week:
{{comparison}}

Write three short paragraphs:
1. Volume and pickup: how many articles, how many BWFC stories, what proportion was national pickup, how that compares to the previous week.
2. Themes: what was the dominant story or topic of the week, who was being talked about, what was emerging.
3. Strategic read: one or two sentences of analytical commentary on what the week tells us about coverage trajectory.

Do not include a heading. Do not include the date. Just the three paragraphs, separated by blank lines.',
    1
);
