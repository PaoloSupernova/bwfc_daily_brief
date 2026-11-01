-- ============================================================
-- Migration 014: Queue auto-triage
--
-- Stores the AI's triage of discovery candidates: a priority, a suggested
-- section, and a cluster number grouping duplicate coverage of the same story.
-- Lets the morning queue surface the must-includes and collapse duplicates.
-- ============================================================

ALTER TABLE discovery_candidates
    ADD COLUMN triage_priority ENUM('high','medium','low') NULL AFTER status,
    ADD COLUMN triage_section VARCHAR(50) NULL AFTER triage_priority,
    ADD COLUMN triage_cluster INT NULL AFTER triage_section,
    ADD COLUMN triaged_at TIMESTAMP NULL AFTER triage_cluster;
