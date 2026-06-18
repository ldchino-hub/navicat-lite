#!/usr/bin/env bash
# Reset Jenkins local user password (Homebrew LTS on :8090).
set -euo pipefail

JENKINS_HOME="${JENKINS_HOME:-$HOME/.jenkins}"
USER_ID="${1:-luisjimenez}"
NEW_PASS="${2:-ldjr08}"

USER_CONFIG=$(python3 - <<PY
import xml.etree.ElementTree as ET
from pathlib import Path

home = Path("${JENKINS_HOME}")
users = home / "users" / "users.xml"
root = ET.parse(users).getroot()
mapping = root.find("idToDirectoryNameMap")
for entry in mapping.findall("entry"):
    strings = entry.findall("string")
    if len(strings) >= 2 and strings[0].text == "${USER_ID}":
        print(home / "users" / strings[1].text / "config.xml")
        break
PY
)

if [[ -z "$USER_CONFIG" || ! -f "$USER_CONFIG" ]]; then
  echo "Jenkins user not found: $USER_ID" >&2
  exit 1
fi

export PASS="$NEW_PASS"
HASH=$(php -r 'echo "#jbcrypt:" . str_replace("$2y$", "$2a$", password_hash(getenv("PASS"), PASSWORD_BCRYPT, ["cost"=>10]));')

python3 - <<PY
from pathlib import Path
import re
path = Path("${USER_CONFIG}")
text = path.read_text()
text = re.sub(
    r'<passwordHash>#jbcrypt:[^<]+</passwordHash>',
    '<passwordHash>${HASH}</passwordHash>',
    text,
    count=1,
)
path.write_text(text)
print(f"Updated {path}")
PY

echo "Restarting Jenkins..."
brew services restart jenkins-lts >/dev/null
for i in {1..30}; do
  curl -s -o /dev/null http://127.0.0.1:8090/login && break
  sleep 2
done

echo ""
echo "Jenkins user reset:"
echo "  URL:      http://127.0.0.1:8090/login"
echo "  Username: ${USER_ID}"
echo "  Password: ${NEW_PASS}"
