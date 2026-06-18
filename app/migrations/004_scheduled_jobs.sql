-- Migration 004: scheduled jobs (queries, backups)

CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    title TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('sql', 'backup')),
    cron_expr TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    last_run_at TEXT,
    next_run_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS scheduled_runs (
    id TEXT PRIMARY KEY,
    job_id TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at TEXT,
    finished_at TEXT,
    log_text TEXT,
    FOREIGN KEY (job_id) REFERENCES scheduled_jobs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_next ON scheduled_jobs(enabled, next_run_at);
