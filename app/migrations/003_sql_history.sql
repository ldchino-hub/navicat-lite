-- Migration 003: SQL history + saved_queries folder column
-- Safe to re-run (uses IF NOT EXISTS / ignores errors on ADD COLUMN)

CREATE TABLE IF NOT EXISTS sql_history (
  id          TEXT PRIMARY KEY,
  user_id     TEXT,
  connection_id TEXT NOT NULL,
  database    TEXT,
  sql_text    TEXT NOT NULL,
  executed_at TEXT NOT NULL DEFAULT (datetime('now')),
  duration_ms INTEGER,
  affected_rows INTEGER,
  FOREIGN KEY(user_id)       REFERENCES users(id)       ON DELETE SET NULL,
  FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE CASCADE
);

-- folder on saved_queries (skip if present — see Database::isIgnorableMigrationError)
ALTER TABLE saved_queries ADD COLUMN folder TEXT;
