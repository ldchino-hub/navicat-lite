#!/usr/bin/env bash
# Register / update the db-toolbox-ldjr Jenkins job (local Homebrew Jenkins).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
JENKINS_HOME="${JENKINS_HOME:-$HOME/.jenkins}"
JOB_NAME="${JENKINS_JOB_NAME:-db-toolbox-ldjr}"
JOB_DIR="${JENKINS_HOME}/jobs/${JOB_NAME}"
JENKINSFILE="${ROOT}/Jenkinsfile"

if [[ ! -f "$JENKINSFILE" ]]; then
  echo "Missing ${JENKINSFILE}" >&2
  exit 1
fi

mkdir -p "$JOB_DIR"

python3 - "$JOB_DIR/config.xml" "$JENKINSFILE" <<'PY'
import sys
from pathlib import Path

out, jenkinsfile = Path(sys.argv[1]), Path(sys.argv[2]).read_text()

xml = f"""<?xml version='1.1' encoding='UTF-8'?>
<flow-definition plugin="workflow-job">
  <description>Deploy DB Tool Box PHP to db.ldjr.me via FTP</description>
  <keepDependencies>false</keepDependencies>
  <properties>
    <hudson.model.ParametersDefinitionProperty>
      <parameterDefinitions>
        <hudson.model.ChoiceParameterDefinition>
          <name>DEPLOY_MODE</name>
          <description>frontend = UI only | backend = PHP only | full = everything | verify = no upload</description>
          <choices>
            <string>frontend</string>
            <string>backend</string>
            <string>full</string>
            <string>verify</string>
          </choices>
        </hudson.model.ChoiceParameterDefinition>
        <hudson.model.BooleanParameterDefinition>
          <name>UPLOAD_CONFIG</name>
          <description>Upload config/config.php (overwrites server secrets)</description>
          <defaultValue>false</defaultValue>
        </hudson.model.BooleanParameterDefinition>
        <hudson.model.BooleanParameterDefinition>
          <name>UPLOAD_CHECK</name>
          <description>Upload public/check.php diagnostic</description>
          <defaultValue>false</defaultValue>
        </hudson.model.BooleanParameterDefinition>
      </parameterDefinitions>
    </hudson.model.ParametersDefinitionProperty>
  </properties>
  <definition class="org.jenkinsci.plugins.workflow.cps.CpsFlowDefinition" plugin="workflow-cps">
    <script><![CDATA[{jenkinsfile}]]></script>
    <sandbox>true</sandbox>
  </definition>
  <triggers/>
  <disabled>false</disabled>
</flow-definition>
"""
out.write_text(xml)
print(f"Wrote {out}")
PY

echo ""
echo "Job installed: ${JOB_NAME}"
echo "Jenkins UI:  http://127.0.0.1:8090/job/${JOB_NAME}/"
echo ""
echo "Credentials (pick one):"
echo "  A) deploy/ftp.env  — recommended for local Jenkins (gitignored)"
echo "  B) Jenkins Secret text ID: db-ldjr-ftp-pass"
echo ""
echo "Then restart Jenkins:"
echo "  brew services restart jenkins-lts"
echo "Or run: bash deploy/jenkins/setup-local.sh"
