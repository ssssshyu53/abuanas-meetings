-- Abu Anas Meetings Database Schema (SQLite 3.x)

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    workspace_name TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    avatar_color TEXT NOT NULL DEFAULT '#1D63ED',
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    api_key TEXT NOT NULL UNIQUE,
    allowed_domains TEXT NOT NULL DEFAULT '*',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_apikey_user ON api_keys (user_id, is_active);

CREATE TABLE IF NOT EXISTS rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    preset TEXT NOT NULL DEFAULT 'meeting',
    media_mode TEXT NOT NULL DEFAULT 'audio',
    forced_engine TEXT NOT NULL DEFAULT 'auto',
    active_engine TEXT DEFAULT NULL,
    access_mode TEXT NOT NULL DEFAULT 'code',
    passcode TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'live',
    allow_all_mic INTEGER NOT NULL DEFAULT 0,
    whiteboard_enabled INTEGER NOT NULL DEFAULT 0,
    slides_json TEXT NULL,
    host_user_id INTEGER NOT NULL,
    started_at TEXT NULL DEFAULT NULL,
    ended_at TEXT NULL DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rooms_status ON rooms (status, created_at);
CREATE INDEX IF NOT EXISTS idx_rooms_host ON rooms (host_user_id);

CREATE TABLE IF NOT EXISTS room_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    user_key TEXT NOT NULL,
    display_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'listener',
    joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (room_id, user_key)
);
CREATE INDEX IF NOT EXISTS idx_part_seen ON room_participants (room_id, last_seen_at);

CREATE TABLE IF NOT EXISTS room_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    user_key TEXT NOT NULL,
    sender_name TEXT NOT NULL,
    sender_role TEXT NOT NULL DEFAULT 'member',
    body TEXT NOT NULL,
    is_question INTEGER NOT NULL DEFAULT 0,
    is_pinned INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_msg_room ON room_messages (room_id, id);

CREATE TABLE IF NOT EXISTS room_polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    question TEXT NOT NULL,
    options_json TEXT NOT NULL,
    is_closed INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_poll_room ON room_polls (room_id, id);

CREATE TABLE IF NOT EXISTS room_poll_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    user_key TEXT NOT NULL,
    option_index INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (poll_id, user_key)
);

CREATE TABLE IF NOT EXISTS recordings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    media_type TEXT NOT NULL DEFAULT 'audio',
    file_path TEXT NOT NULL DEFAULT '',
    r2_url TEXT NOT NULL DEFAULT '',
    telegram_sent INTEGER NOT NULL DEFAULT 0,
    duration_seconds INTEGER NOT NULL DEFAULT 0,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rec_room ON recordings (room_id, id);

CREATE TABLE IF NOT EXISTS provider_configs (
    provider_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    category TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    priority INTEGER NOT NULL DEFAULT 10,
    monthly_limit REAL NOT NULL DEFAULT 0,
    limit_unit TEXT NOT NULL DEFAULT 'minutes_month',
    used_units REAL NOT NULL DEFAULT 0,
    period_key TEXT NOT NULL DEFAULT '',
    config_json TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
