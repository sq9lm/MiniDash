-- 002_remember_tokens.sql
-- Remember Me tokens for persistent login (30 days)
-- Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    selector TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    username TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_selector ON remember_tokens(selector);
CREATE INDEX IF NOT EXISTS idx_remember_tokens_expires ON remember_tokens(expires_at);
