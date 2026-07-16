#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROUTER="$ROOT/app/src/Http/Router.php"
fail=0

assert_absent() {
  local pattern="$1"
  local path="$2"
  if python3 - "$pattern" "$path" <<'PY'
import pathlib, re, sys
pattern, raw_path = sys.argv[1:]
path = pathlib.Path(raw_path)
files = [path] if path.is_file() else [p for p in path.rglob("*") if p.is_file()]
raise SystemExit(0 if any(re.search(pattern, p.read_text(errors="ignore"), re.MULTILINE) for p in files) else 1)
PY
  then
    printf 'FAIL: found %s in %s\n' "$pattern" "$path"
    fail=1
  fi
}

assert_present() {
  local pattern="$1"
  local path="$2"
  if ! python3 - "$pattern" "$path" <<'PY'
import pathlib, re, sys
pattern, raw_path = sys.argv[1:]
text = pathlib.Path(raw_path).read_text(errors="ignore")
raise SystemExit(0 if re.search(pattern, text, re.MULTILINE) else 1)
PY
  then
    printf 'FAIL: missing %s in %s\n' "$pattern" "$path"
    fail=1
  fi
}

assert_absent 'SchedulerService|dispatchScheduler|trySchedulerMaybeTick|/api/scheduler|/api/scheduled-jobs' "$ROOT/app/src"
assert_absent 'dispatchQueryStore|/query-store' "$ROUTER"
assert_absent 'dispatchSystem|/api/system' "$ROUTER"
assert_absent 'scheduler-worker|scheduler-guardian' "$ROOT/app/package.json"
assert_absent 'scheduled_jobs|scheduler_lock|scheduler_state' "$ROOT/app/migrations"

assert_present "DB Tool Box Lite" "$ROOT/app/frontend/index.html"
assert_present "DB Tool Box Lite" "$ROOT/app/config/config.example.php"
assert_present '^1\.0\.0$' "$ROOT/app/VERSION"
assert_present 'dbtoolbox-lite:1\.0\.0' "$ROOT/docker-compose.yml"
assert_present '8789:80|DBTOOLBOX_LITE_PORT.*8789' "$ROOT/docker-compose.yml"

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi

printf 'Lite static surface checks passed.\n'
