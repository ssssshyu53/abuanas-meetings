-- Abu Anas Meetings Database Schema (MySQL / MariaDB 10.4+)

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    workspace_name VARCHAR(120) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'host', 'member') NOT NULL DEFAULT 'member',
    avatar_color VARCHAR(20) NOT NULL DEFAULT '#1D63ED',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    api_key VARCHAR(80) NOT NULL UNIQUE,
    allowed_domains VARCHAR(500) NOT NULL DEFAULT '*',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apikey_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(16) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    preset ENUM('space', 'classroom', 'meeting', 'consultation') NOT NULL DEFAULT 'meeting',
    media_mode ENUM('audio', 'video') NOT NULL DEFAULT 'audio',
    forced_engine VARCHAR(32) NOT NULL DEFAULT 'auto',
    active_engine VARCHAR(32) DEFAULT NULL,
    access_mode ENUM('public', 'code', 'registered') NOT NULL DEFAULT 'code',
    passcode VARCHAR(64) NOT NULL DEFAULT '',
    status ENUM('scheduled', 'live', 'ended') NOT NULL DEFAULT 'live',
    allow_all_mic TINYINT(1) NOT NULL DEFAULT 0,
    whiteboard_enabled TINYINT(1) NOT NULL DEFAULT 0,
    slides_json MEDIUMTEXT NULL,
    host_user_id INT UNSIGNED NOT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rooms_status (status, created_at),
    INDEX idx_rooms_host (host_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    user_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('host', 'speaker', 'listener') NOT NULL DEFAULT 'listener',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_room_user (room_id, user_key),
    INDEX idx_part_seen (room_id, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    user_key VARCHAR(64) NOT NULL,
    sender_name VARCHAR(120) NOT NULL,
    sender_role ENUM('host', 'member', 'guest') NOT NULL DEFAULT 'member',
    body VARCHAR(500) NOT NULL,
    is_question TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msg_room (room_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_polls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    question VARCHAR(255) NOT NULL,
    options_json TEXT NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_poll_room (room_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_poll_votes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poll_id INT UNSIGNED NOT NULL,
    user_key VARCHAR(64) NOT NULL,
    option_index INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_poll_vote (poll_id, user_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recordings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    media_type ENUM('audio', 'video') NOT NULL DEFAULT 'audio',
    file_path VARCHAR(255) NOT NULL DEFAULT '',
    r2_url VARCHAR(500) NOT NULL DEFAULT '',
    telegram_sent TINYINT(1) NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rec_room (room_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_configs (
    provider_key VARCHAR(32) PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    category ENUM('audio', 'video', 'storage') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 10,
    monthly_limit DOUBLE NOT NULL DEFAULT 0,
    limit_unit ENUM('requests_day', 'gb_month', 'minutes_month') NOT NULL DEFAULT 'minutes_month',
    used_units DOUBLE NOT NULL DEFAULT 0,
    period_key VARCHAR(16) NOT NULL DEFAULT '',
    config_json TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
