#!/usr/bin/env bash
# One-shot local Jenkins setup for db-toolbox-ldjr.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="${ROOT}/deploy/ftp.env"
EXAMPLE="${ROOT}/deploy/ftp.env.example"

echo "==> DB Tool Box — Jenkins local setup"
echo "    Project: ${ROOT}"

if [[ ! -f "$ENV_FILE" ]]; then
  cp "$EXAMPLE" "$ENV_FILE"
  echo ""
  echo "Created ${ENV_FILE}"
  echo "Edit it now and set FTP_PASS, then run this script again."
  exit 0
fi

if rg -q 'your-ftp-password' "$ENV_FILE" 2>/dev/null; then
  echo "Error: set a real FTP_PASS in ${ENV_FILE}" >&2
  exit 1
fi

bash "${ROOT}/deploy/jenkins/install-job.sh"

echo "==> Restarting Jenkins (pick up new job)..."
brew services restart jenkins-lts >/dev/null

for i in {1..30}; do
  if curl -s -o /dev/null -w '' http://127.0.0.1:8090/login 2>/dev/null; then
    echo "==> Jenkins is up: http://127.0.0.1:8090/job/db-toolbox-ldjr/"
    exit 0
  fi
  sleep 2
done

echo "Jenkins did not respond on :8090 within 60s. Try: brew services restart jenkins-lts" >&2
exit 1
