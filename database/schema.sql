-- BWFC Daily Brief - Database Schema
-- MySQL 8.0+
-- Import this first, then seed.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- -----------------------------------------------------
-- Table: sections
-- Daily Brief content categories
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS sections (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: users
-- Stubbed for local XAMPP, activated on M365 SSO deployment
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'editor', 'viewer') NOT NULL DEFAULT 'editor',
    m365_object_id VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_m365 (m365_object_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: briefs
-- One row per daily brief edition
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS briefs (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    brief_date DATE NOT NULL,
    title VARCHAR(255) NULL,
    executive_summary TEXT NULL,
    status ENUM('draft', 'sent') NOT NULL DEFAULT 'draft',
    is_weekend_rollup TINYINT(1) NOT NULL DEFAULT 0,
    header_style ENUM('legacy_png', 'nippo_navy') NOT NULL DEFAULT 'nippo_navy',
    created_by INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    sent_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_brief_date (brief_date),
    INDEX idx_status (status),
    INDEX idx_deleted (deleted_at),
    CONSTRAINT fk_briefs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_briefs_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: brief_articles
-- Each article within a brief
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS brief_articles (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    brief_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    url VARCHAR(2048) NOT NULL,
    outlet_name VARCHAR(255) NOT NULL,
    headline VARCHAR(500) NOT NULL,
    article_content MEDIUMTEXT NULL,
    summary TEXT NOT NULL,
    summary_original TEXT NULL,
    was_edited TINYINT(1) NOT NULL DEFAULT 0,
    was_regenerated_count INT NOT NULL DEFAULT 0,
    was_paywall_fallback TINYINT(1) NOT NULL DEFAULT 0,
    sentiment ENUM('positive', 'neutral', 'negative') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_brief (brief_id, section_id, display_order),
    FULLTEXT idx_search (headline, summary, article_content),
    CONSTRAINT fk_articles_brief FOREIGN KEY (brief_id) REFERENCES briefs(id) ON DELETE CASCADE,
    CONSTRAINT fk_articles_section FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: outlets
-- Domain-to-publication-name mapping
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS outlets (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    is_paywalled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: style_rules
-- Editable banned words, phrases, and instructions
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS style_rules (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    rule_type ENUM('banned_word', 'banned_phrase', 'instruction') NOT NULL,
    rule_value TEXT NOT NULL,
    notes VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (rule_type, is_active)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: prompt_templates
-- Versioned Claude prompts, editable via admin UI
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS prompt_templates (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    template_key VARCHAR(50) NOT NULL,
    template_body TEXT NOT NULL,
    version INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_template_key_version (template_key, version),
    INDEX idx_active (template_key, is_active)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: audit_log
-- Every action recorded
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT UNSIGNED NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: rss_cache
-- bwfc.co.uk RSS feed cache for one-click article adding
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS rss_cache (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    feed_url VARCHAR(500) NOT NULL,
    article_url VARCHAR(2048) NOT NULL UNIQUE,
    headline VARCHAR(500) NOT NULL,
    summary_snippet TEXT NULL,
    published_at DATETIME NULL,
    consumed TINYINT(1) NOT NULL DEFAULT 0,
    consumed_brief_id INT UNSIGNED NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consumed (consumed, published_at),
    CONSTRAINT fk_rss_brief FOREIGN KEY (consumed_brief_id) REFERENCES briefs(id) ON DELETE SET NULL
) ENGINE=InnoDB;
