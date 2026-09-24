-- Apply once to an existing production database.
ALTER TABLE reports
    ADD COLUMN yes_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER created_at,
    ADD COLUMN no_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER yes_count;

CREATE TABLE report_votes (
    report_id BIGINT UNSIGNED NOT NULL,
    voter_hash CHAR(64) NOT NULL,
    vote ENUM('yes', 'no') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id, voter_hash),
    CONSTRAINT fk_report_vote_report FOREIGN KEY (report_id)
        REFERENCES reports(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    visited_date DATE NOT NULL,
    visit_count INT UNSIGNED NOT NULL DEFAULT 1,
    last_visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip_date (ip_hash, visited_date),
    INDEX idx_visited_date (visited_date)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

