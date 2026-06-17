-- Migration 037: เพิ่ม login_attempts table สำหรับ IP-based rate limiting
-- AuthController ใช้ตารางนี้ tracking failed login attempts
-- (จากเดิมที่ใช้ file-based /tmp ซึ่งไม่ persistent)

CREATE TABLE IF NOT EXISTS login_attempts (
    ip VARCHAR(45) PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
