-- ============================================================
-- Migration 007: People (player / staff / exec) mention tracking
--
--   people            one row per person (name-only identity, folded key)
--   article_people    many-to-many: an article can mention several people
--
-- Seeds the current BWFC squad, head coach, and executives as the known list.
-- Manage this list any time in Admin → Squad.
-- ============================================================

CREATE TABLE IF NOT EXISTS people (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    -- accent/hyphen-folded, lowercased identity key
    name_key VARCHAR(200) NOT NULL,
    role ENUM('player','staff','exec','other') NOT NULL DEFAULT 'other',
    -- 1 = on the maintained known list; 0 = added ad hoc / caught automatically
    is_known TINYINT(1) NOT NULL DEFAULT 0,
    -- comma-separated display aliases (e.g. "Chris Forino")
    aliases VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_people_name_key (name_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS article_people (
    article_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,
    confidence ENUM('known','manual','ai') NOT NULL DEFAULT 'known',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id, person_id),
    INDEX idx_ap_person (person_id),
    CONSTRAINT fk_ap_article FOREIGN KEY (article_id) REFERENCES brief_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ap_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Seed the known BWFC people --------------------------------
INSERT INTO people (name, name_key, role, is_known, aliases, active) VALUES
    ('Jack Bonham', 'jack bonham', 'player', 1, NULL, 1),
    ('Nathan Broome', 'nathan broome', 'player', 1, NULL, 1),
    ('David Harrington', 'david harrington', 'player', 1, NULL, 1),
    ('Luke Hutchinson', 'luke hutchinson', 'player', 1, NULL, 1),
    ('Chris Forino-Joseph', 'chris forino joseph', 'player', 1, 'Chris Forino', 1),
    ('Richard Taylor', 'richard taylor', 'player', 1, NULL, 1),
    ('Jordi Osei-Tutu', 'jordi osei tutu', 'player', 1, NULL, 1),
    ('Eoin Toal', 'eoin toal', 'player', 1, NULL, 1),
    ('Akin Famewo', 'akin famewo', 'player', 1, NULL, 1),
    ('Lewis Temple', 'lewis temple', 'player', 1, NULL, 1),
    ('Cyrus Christie', 'cyrus christie', 'player', 1, NULL, 1),
    ('Sam Inwood', 'sam inwood', 'player', 1, NULL, 1),
    ('Gaizka Larrazabal', 'gaizka larrazabal', 'player', 1, NULL, 1),
    ('Ben Davies', 'ben davies', 'player', 1, NULL, 1),
    ('Xavier Simons', 'xavier simons', 'player', 1, NULL, 1),
    ('Josh Sheehan', 'josh sheehan', 'player', 1, NULL, 1),
    ('Joel Randall', 'joel randall', 'player', 1, NULL, 1),
    ('Ethan Erhahon', 'ethan erhahon', 'player', 1, NULL, 1),
    ('Max Conway', 'max conway', 'player', 1, NULL, 1),
    ('Rúben Rodrigues', 'ruben rodrigues', 'player', 1, 'Ruben Rodrigues', 1),
    ('Daeshon Lawrence', 'daeshon lawrence', 'player', 1, NULL, 1),
    ('Luca Stephenson', 'luca stephenson', 'player', 1, NULL, 1),
    ('Toby Ritchie', 'toby ritchie', 'player', 1, NULL, 1),
    ('Sam Dalby', 'sam dalby', 'player', 1, NULL, 1),
    ('Thierry Gale', 'thierry gale', 'player', 1, NULL, 1),
    ('Charlie Warren', 'charlie warren', 'player', 1, NULL, 1),
    ('John McAtee', 'john mcatee', 'player', 1, NULL, 1),
    ('Kyliane Dong', 'kyliane dong', 'player', 1, NULL, 1),
    ('Steven Schumacher', 'steven schumacher', 'staff', 1, NULL, 1),
    ('Fergal Harkin', 'fergal harkin', 'exec', 1, NULL, 1),
    ('David Ray', 'david ray', 'exec', 1, NULL, 1),
    ('Sharon Brittan', 'sharon brittan', 'exec', 1, NULL, 1);
