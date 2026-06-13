-- Phase 3: IP-based rate limiting (replaces file-based /tmp)
CREATE TABLE IF NOT EXISTS login_attempts (
    ip VARCHAR(45) PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL
);

-- Phase 4: JWT token revocation
CREATE TABLE IF NOT EXISTS token_blocklist (
    jti VARCHAR(64) PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires (expires_at)
);
