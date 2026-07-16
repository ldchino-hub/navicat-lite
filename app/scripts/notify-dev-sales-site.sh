#!/usr/bin/env bash
# Post update to #dev-sales-site via Slack incoming webhook.
# Usage: SLACK_WEBHOOK_URL=https://hooks.slack.com/... ./scripts/notify-dev-sales-site.sh "message"
set -euo pipefail

MSG="${1:-DB Tool Box update}"
WEBHOOK="${SLACK_WEBHOOK_URL:-${DEV_SALES_SITE_WEBHOOK:-}}"

if [[ -z "$WEBHOOK" ]]; then
  echo "Skip Slack: set SLACK_WEBHOOK_URL or DEV_SALES_SITE_WEBHOOK" >&2
  exit 0
fi

payload=$(python3 -c 'import json,sys; print(json.dumps({"text": sys.argv[1]}))' "$MSG")
curl -sS -X POST -H 'Content-Type: application/json' --data "$payload" "$WEBHOOK"
echo ""
echo "Posted to #dev-sales-site"
