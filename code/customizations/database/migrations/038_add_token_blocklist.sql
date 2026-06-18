-- Migration 038: Add token_blocklist for JWT revocation support
CREATE TABLE IF NOT EXISTS token_blocklist (
    jti VARCHAR(64) PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
