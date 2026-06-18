# Jenkins local — db.ldjr.me

## Tu Jenkins

| Item | Ubicación |
|------|-----------|
| **UI** | http://127.0.0.1:8090/ (puerto **8090** — evita conflicto con Cursor en 8080) |
| **Home (`JENKINS_HOME`)** | `/Users/luisjimenez/.jenkins` |
| **Instalación (Homebrew LTS)** | `/usr/local/opt/jenkins-lts` |
| **WAR** | `/usr/local/opt/jenkins-lts/libexec/jenkins.war` |
| **Servicio** | `brew services start jenkins-lts` |
| **Jobs existentes** | `~/.jenkins/jobs/` (backup-db, migrate-db, …) |

## Setup rápido

```bash
cd /Applications/MAMP/htdocs/filegator/repository/navicat-php
bash deploy/jenkins/setup-local.sh
```

Eso crea `deploy/ftp.env` (si falta), instala el job y reinicia Jenkins.

## Setup manual

```bash
cp deploy/ftp.env.example deploy/ftp.env   # pon FTP_PASS
bash deploy/jenkins/install-job.sh
brew services restart jenkins-lts
```

## Credenciales FTP

**Opción A (recomendada):** archivo `deploy/ftp.env` (gitignored).

**Opción B:** Jenkins → Credentials → Secret text, ID `db-ldjr-ftp-pass`.

Solo necesitas una de las dos.

## Ejecutar deploy

http://127.0.0.1:8090/job/db-toolbox-ldjr/build?delay=0sec

| Parámetro | Uso |
|-----------|-----|
| `DEPLOY_MODE=frontend` | Solo UI (default recomendado) |
| `DEPLOY_MODE=backend` | Solo PHP |
| `DEPLOY_MODE=full` | Todo |
| `DEPLOY_MODE=verify` | Solo prueba https://db.ldjr.me |

Tras cambiar `Jenkinsfile`:

```bash
bash deploy/jenkins/install-job.sh
# luego reload en Jenkins
```
