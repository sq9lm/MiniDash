-- 001_initial.sql
-- Base schema for MiniDash v2.0.0

CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT,
    location TEXT,
    os TEXT,
    browser TEXT,
    user_agent TEXT,
    logged_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wan_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rx_bytes INTEGER,
    tx_bytes INTEGER,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS client_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    hostname TEXT,
    ip TEXT,
    network TEXT,
    vlan INTEGER,
    is_wired INTEGER DEFAULT 0,
    rx_bytes INTEGER,
    tx_bytes INTEGER,
    uptime_sec INTEGER,
    seen_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_monitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    vlan TEXT,
    enabled INTEGER DEFAULT 1,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    status TEXT NOT NULL,
    duration INTEGER,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    severity TEXT DEFAULT 'INFO',
    message TEXT,
    details_json TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stalker_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    hostname TEXT,
    ap_mac TEXT,
    ap_name TEXT,
    ssid TEXT,
    channel INTEGER,
    band TEXT,
    rssi INTEGER,
    rx_rate REAL,
    tx_rate REAL,
    connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    disconnected_at DATETIME
);

CREATE TABLE IF NOT EXISTS stalker_roaming (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    hostname TEXT,
    from_ap TEXT,
    to_ap TEXT,
    from_channel INTEGER,
    to_channel INTEGER,
    rssi_before INTEGER,
    rssi_after INTEGER,
    roamed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stalker_watchlist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL UNIQUE,
    label TEXT,
    notify INTEGER DEFAULT 1,
    blocked INTEGER DEFAULT 0,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS threat_ignore (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL UNIQUE,
    label TEXT,
    reason TEXT,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_history_time ON login_history(logged_at);
CREATE INDEX IF NOT EXISTS idx_wan_stats_recorded ON wan_stats(recorded_at);
CREATE INDEX IF NOT EXISTS idx_client_history_mac ON client_history(mac);
CREATE INDEX IF NOT EXISTS idx_client_history_seen ON client_history(seen_at);
CREATE INDEX IF NOT EXISTS idx_device_status_mac ON device_status_history(mac);
CREATE INDEX IF NOT EXISTS idx_stalker_sessions_mac ON stalker_sessions(mac);
CREATE INDEX IF NOT EXISTS idx_stalker_roaming_mac ON stalker_roaming(mac);
CREATE INDEX IF NOT EXISTS idx_stalker_roaming_time ON stalker_roaming(roamed_at);
CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at);
CREATE INDEX IF NOT EXISTS idx_events_severity ON events(severity);
