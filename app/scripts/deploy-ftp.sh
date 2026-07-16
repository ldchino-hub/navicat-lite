#!/usr/bin/env bash
# Deploy DB Tool Box PHP to FTP (db.ldjr.me)
#
# Usage:
#   ./scripts/deploy-ftp.sh [full|frontend|backend|verify]
#   FTP_PASS=... npm run deploy
#
# Credentials: deploy/ftp.env (copy from deploy/ftp.env.example) or env vars.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODE="${1:-full}"

ENV_FILE="${ROOT}/deploy/ftp.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck source=/dev/null
  source "$ENV_FILE"
  set +a
fi

FTP_HOST="${FTP_HOST:-ldjr.me}"
FTP_USER="${FTP_USER:-db@ldjr.me}"
FTP_PASS="${FTP_PASS:-}"
DEPLOY_URL="${DEPLOY_URL:-https://db.ldjr.me}"
DEPLOY_UPLOAD_CONFIG="${DEPLOY_UPLOAD_CONFIG:-0}"
DEPLOY_UPLOAD_CHECK="${DEPLOY_UPLOAD_CHECK:-0}"
DEPLOY_PRUNE_ASSETS="${DEPLOY_PRUNE_ASSETS:-1}"
DEPLOY_SKIP_BUILD="${DEPLOY_SKIP_BUILD:-0}"
DRY_RUN="${DRY_RUN:-0}"

if [[ -z "$FTP_PASS" && "$MODE" != "verify" ]]; then
  echo "Error: set FTP_PASS or create deploy/ftp.env (see deploy/ftp.env.example)" >&2
  exit 1
fi

auth="${FTP_USER}:${FTP_PASS}"
base="ftp://${FTP_HOST}"

log() { printf '==> %s\n' "$*"; }
run() {
  if [[ "$DRY_RUN" == "1" ]]; then
    printf '[dry-run] %s\n' "$*"
  else
    "$@"
  fi
}

upload() {
  local src="$1"
  local dest="$2"
  if [[ ! -f "$src" ]]; then
    echo "Missing file: $src" >&2
    exit 1
  fi
  run curl -sS --ftp-create-dirs -T "$src" "${base}/${dest}" --user "$auth"
  echo "  ↑ ${dest}"
}

upload_tree() {
  local dir="$1"
  local remote_prefix="$2"
  local pattern="${3:-*}"
  while IFS= read -r -d '' f; do
    local rel="${f#"$dir"/}"
    upload "$f" "${remote_prefix}/${rel}"
  done < <(find "$dir" -type f -name "$pattern" -print0)
}

delete_remote() {
  local remote_path="$1"
  run curl -sS --user "$auth" -Q "-DELE ${remote_path}" "${base}/" >/dev/null || true
}

prune_stale_assets() {
  [[ "$DEPLOY_PRUNE_ASSETS" == "1" ]] || return 0
  [[ -d "$ROOT/public/assets" ]] || return 0

  log "Pruning stale assets on FTP..."
  local keep=()
  while IFS= read -r -d '' f; do
    keep+=("$(basename "$f")")
  done < <(find "$ROOT/public/assets" -maxdepth 1 -type f \( -name '*.js' -o -name '*.css' -o -name '*.map' \) -print0)

  local remote_list
  remote_list="$(curl -sS --user "$auth" --list-only "${base}/public/assets/" 2>/dev/null || true)"
  [[ -n "$remote_list" ]] || { echo "  (could not list remote assets — skip prune)"; return 0; }

  local name
  while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    [[ "$name" =~ \.(js|css|map)$ ]] || continue
    local found=0
    local k
    for k in "${keep[@]}"; do
      [[ "$name" == "$k" ]] && found=1 && break
    done
    if [[ "$found" -eq 0 ]]; then
      echo "  ✗ public/assets/${name}"
      delete_remote "public/assets/${name}"
    fi
  done <<< "$remote_list"
}

build_frontend() {
  [[ "$DEPLOY_SKIP_BUILD" == "1" ]] && { log "Skipping frontend build (DEPLOY_SKIP_BUILD=1)"; return 0; }
  log "Building frontend..."
  rm -rf "$ROOT/public/assets"
  run bash -c "cd '$ROOT/frontend' && npm run build --silent"
}

deploy_public_core() {
  upload "$ROOT/public/index.php" "public/index.php"
  upload "$ROOT/public/.htaccess" "public/.htaccess"
}

deploy_frontend() {
  build_frontend
  if [[ ! -f "$ROOT/public/index.html" ]]; then
    echo "Build did not produce public/index.html" >&2
    exit 1
  fi
  log "Uploading frontend (public/)..."
  upload "$ROOT/public/index.html" "public/index.html"
  if [[ -d "$ROOT/public/assets" ]]; then
    while IFS= read -r -d '' f; do
      rel="${f#$ROOT/}"
      upload "$f" "$rel"
    done < <(find "$ROOT/public/assets" -type f -print0)
  fi
  prune_stale_assets
}

deploy_backend() {
  log "Uploading backend PHP..."
  deploy_public_core
  if [[ -f "$ROOT/VERSION" ]]; then
    upload "$ROOT/VERSION" "VERSION"
  fi
  upload_tree "$ROOT/src" "src" '*.php'
  upload_tree "$ROOT/migrations" "migrations" '*'
  upload_tree "$ROOT/scripts" "scripts" '*.php'

  if [[ "$DEPLOY_UPLOAD_CONFIG" == "1" ]]; then
    upload "$ROOT/config/config.php" "config/config.php"
  else
    echo "  ↷ config/config.php (skipped — set DEPLOY_UPLOAD_CONFIG=1 to upload)"
  fi
  upload "$ROOT/config/config.example.php" "config/config.example.php"

  if [[ "$DEPLOY_UPLOAD_CHECK" == "1" && -f "$ROOT/public/check.php" ]]; then
    upload "$ROOT/public/check.php" "public/check.php"
  fi

  log "Root router fallback..."
  upload "$ROOT/deploy/root.htaccess" ".htaccess"
  upload "$ROOT/deploy/root.index.php" "index.php"
}

deploy_full() {
  deploy_frontend
  log "Uploading backend PHP..."
  deploy_public_core
  if [[ -f "$ROOT/VERSION" ]]; then
    upload "$ROOT/VERSION" "VERSION"
  fi
  upload_tree "$ROOT/src" "src" '*.php'
  upload_tree "$ROOT/migrations" "migrations" '*'
  upload_tree "$ROOT/scripts" "scripts" '*.php'

  if [[ "$DEPLOY_UPLOAD_CONFIG" == "1" ]]; then
    upload "$ROOT/config/config.php" "config/config.php"
  else
    echo "  ↷ config/config.php (skipped — set DEPLOY_UPLOAD_CONFIG=1 to upload)"
  fi
  upload "$ROOT/config/config.example.php" "config/config.example.php"

  if [[ "$DEPLOY_UPLOAD_CHECK" == "1" && -f "$ROOT/public/check.php" ]]; then
    upload "$ROOT/public/check.php" "public/check.php"
  fi

  log "Root router fallback..."
  upload "$ROOT/deploy/root.htaccess" ".htaccess"
  upload "$ROOT/deploy/root.index.php" "index.php"
}

verify_deploy() {
  log "Verifying ${DEPLOY_URL}..."
  local health html js_path js_code css_path css_code
  health="$(curl -sS "${DEPLOY_URL}/api/health" 2>/dev/null || echo '{}')"
  echo "  health: ${health}"

  html="$(curl -sS "${DEPLOY_URL}/" 2>/dev/null || true)"
  if [[ "$html" == *"DB Tool Box"* ]]; then
    echo "  homepage: OK (title found)"
  else
    echo "  homepage: FAIL (expected DB Tool Box in HTML)" >&2
    return 1
  fi

  js_path="$(printf '%s' "$html" | sed -n 's/.*src="\.\/assets\/\([^"]*\.js\)".*/\1/p' | head -1)"
  css_path="$(printf '%s' "$html" | sed -n 's/.*href="\.\/assets\/\([^"]*\.css\)".*/\1/p' | head -1)"

  if [[ -n "$js_path" ]]; then
    local js_body js_code
    js_body="$(curl -sS "${DEPLOY_URL}/assets/${js_path}" 2>/dev/null | head -c 40 || true)"
    js_code="$(curl -sS -o /dev/null -w '%{http_code}' "${DEPLOY_URL}/assets/${js_path}" 2>/dev/null || echo '000')"
    echo "  asset js:  ${js_path} → HTTP ${js_code}"
    [[ "$js_code" == "200" ]] || return 1
    if [[ "$js_body" == "<!DOCTYPE"* || "$js_body" == "<html"* ]]; then
      echo "  asset js:  FAIL (got HTML instead of JavaScript — check public/assets/ on server)" >&2
      return 1
    fi
  fi
  if [[ -n "$css_path" ]]; then
    local css_body css_code
    css_body="$(curl -sS "${DEPLOY_URL}/assets/${css_path}" 2>/dev/null | head -c 40 || true)"
    css_code="$(curl -sS -o /dev/null -w '%{http_code}' "${DEPLOY_URL}/assets/${css_path}" 2>/dev/null || echo '000')"
    echo "  asset css: ${css_path} → HTTP ${css_code}"
    [[ "$css_code" == "200" ]] || return 1
    if [[ "$css_body" == "<!DOCTYPE"* || "$css_body" == "<html"* ]]; then
      echo "  asset css: FAIL (got HTML instead of CSS — check public/assets/ on server)" >&2
      return 1
    fi
  fi

  log "Verify OK — ${DEPLOY_URL}"
}

case "$MODE" in
  full)
    deploy_full
    verify_deploy
    ;;
  frontend)
    deploy_frontend
    verify_deploy
    ;;
  backend)
    deploy_backend
    verify_deploy
    ;;
  verify)
    verify_deploy
    ;;
  *)
    echo "Usage: $0 [full|frontend|backend|verify]" >&2
    exit 1
    ;;
esac

log "Done."
