-- Migration 005: distributed scheduler lock + runtime state (guardian / heartbeat / API)

CREATE TABLE IF NOT EXISTS scheduler_lock (
    id          INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    acquired_at INTEGER NOT NULL,
    holder      TEXT    NOT NULL DEFAULT 'guardian'
);

CREATE TABLE IF NOT EXISTS scheduler_state (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
