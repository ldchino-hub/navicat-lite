PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'viewer',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS connections (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  engine TEXT NOT NULL,
  host TEXT NOT NULL,
  port INTEGER NOT NULL DEFAULT 3306,
  username TEXT NOT NULL,
  password_enc TEXT NOT NULL,
  default_db TEXT,
  ssl_mode TEXT,
  use_vpn INTEGER NOT NULL DEFAULT 0,
  config_group TEXT,
  meta_json TEXT NOT NULL DEFAULT '{}',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS connection_grants (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  connection_id TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'viewer',
  UNIQUE(user_id, connection_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS backups (
  id TEXT PRIMARY KEY,
  connection_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  database_name TEXT NOT NULL,
  file_path TEXT NOT NULL,
  size_bytes INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT,
  status TEXT NOT NULL DEFAULT 'pending',
  options_json TEXT NOT NULL DEFAULT '{}',
  error TEXT,
  started_at TEXT NOT NULL DEFAULT (datetime('now')),
  finished_at TEXT,
  FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_events (
  id TEXT PRIMARY KEY,
  user_id TEXT,
  action TEXT NOT NULL,
  target TEXT,
  payload_json TEXT NOT NULL DEFAULT '{}',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS saved_queries (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  connection_id TEXT NOT NULL,
  title TEXT NOT NULL,
  sql_text TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE CASCADE
);
