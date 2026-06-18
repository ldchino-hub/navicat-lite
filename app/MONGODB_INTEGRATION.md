# MongoDB / Amazon DocumentDB — Integración en DB Tool Box PHP

> **Versión:** 1.2.2 (integrado en `main`)  
> **Base previa:** 1.2.1 (backup `navicat-php-1.0.0-backup-pre-mongodb-20260601-194050`)  
> **Fecha:** 2026-06-01  
> **Entorno validado:** PHP 8.2 · Amazon DocumentDB 5.0 (wire protocol MongoDB) · Linux x86_64

---

## Resumen ejecutivo

Se añadió soporte completo de MongoDB / Amazon DocumentDB a DB Tool Box PHP sin instalar ninguna extensión PHP adicional ni dependencias Composer. Todo el protocolo de red se implementó en PHP puro usando `stream_socket_client` con TLS, el wire protocol OP_MSG de MongoDB y autenticación SCRAM-SHA-1/SHA-256.

**Alcance implementado:**
- Listar bases de datos y colecciones (con conteo de documentos y tamaño)
- Esquema inferido por muestreo de documentos (colecciones son schemaless)
- Data grid paginado con filtros estructurados y raw JSON filter
- Consola de queries con sintaxis shell Mongo (`db.coll.find({})`, `aggregate`, `distinct`, etc.)
- CRUD completo de documentos por `_id`
- Backup / restore nativo en formato NDJSON (con gzip)
- Data transfer Mongo→Mongo con fidelidad total de tipos BSON
- Gestión de usuarios/roles de DocumentDB
- Server info y process list (`currentOp`)
- Importador de conexiones desde `~/.mongo_config.json`
- UI adaptada: formulario de conexión, Monaco en modo JavaScript, árbol sin secciones SQL-only

---

## Prerrequisitos del host donde se re-implemente

```
PHP >= 8.1 (64 bits obligatorio — BSON int64 requiere PHP_INT_SIZE === 8)
Extensiones PHP ya incluidas en instalaciones estándar:
  - openssl       (TLS / stream_socket_client con ssl://)
  - hash          (SCRAM: hash_hmac, hash_pbkdf2)
  - json          (encode/decode de extended JSON)
  - mbstring      (opcional — operaciones de string multibyte)

CA bundle de AWS:  global-bundle.pem  (en $HOME del usuario que corre PHP)
  → Descargar de: https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem

Red:  acceso TCP al puerto 27017 del cluster DocumentDB
      (los endpoints *.docdb.amazonaws.com son internos a la VPC de AWS)
```

---

## Archivos creados (nuevos, no existían antes)

### `src/Mongo/` — capa de protocolo (2 092 líneas totales, PHP puro)

| Archivo | Líneas | Responsabilidad |
|---|---|---|
| `src/Mongo/Bson.php` | 212 | Codec BSON completo: encode y decode de todos los tipos (double, string, document, array, binary, ObjectId, bool, datetime, null, regex, int32/64, decimal128, timestamp) |
| `src/Mongo/MongoClient.php` | 278 | Cliente TCP/TLS: conecta, hace handshake `hello`, autentica, envía OP_MSG (opcode 2013), lee respuestas, drena cursores con `getMore` |
| `src/Mongo/Scram.php` | 146 | Autenticación SCRAM-SHA-1 y SCRAM-SHA-256 (RFC 5802/7677), incluyendo verificación de firma del servidor (mutual auth) |
| `src/Mongo/ObjectId.php` | 57 | Tipo BSON ObjectId (12 bytes, representado como hex de 24 chars) |
| `src/Mongo/UTCDateTime.php` | 29 | Tipo BSON UTC datetime (milisegundos desde epoch) |
| `src/Mongo/Int64.php` | 22 | Wrapper para int64 explícito (distingue int64 de int32 en encode) |
| `src/Mongo/Binary.php` | 28 | Tipo BSON binary (dato + subtipo; UUID se serializa canónicamente) |
| `src/Mongo/Regex.php` | 19 | Tipo BSON regular expression (pattern + flags) |

### `src/Drivers/MongoDriver.php` — driver de negocio (1 225 líneas)

Implementa la misma superficie de métodos que `MySqlDriver` y `PostgresDriver` para que `Router`, `BackupService`, `TransferService` y `SchedulerService` lo usen sin distinción de engine.

**Mapeo conceptual MongoDB vs. SQL:**

| Concepto SQL | Concepto MongoDB |
|---|---|
| database | database |
| table | collection |
| row | document (aplanado con claves dotted `doc.field`) |
| primary key | `_id` (siempre) |
| foreign key | no existe → retorna `[]` |
| view | view de Mongo (existe pero es raro) |
| routine/trigger | no existe → retorna `[]` |
| DDL CREATE TABLE | `{"create": "collName"}` o no-op (schemaless) |

**Métodos públicos implementados:**

```
testConnection()             → ping al admin db
listDatabases()              → listDatabases (excluye admin/local/config)
listTablesLight(db)          → listCollections + collStats (conteo y tamaño)
listViews(db)                → listCollections filtrando type=view (client-side)
listRoutines(db)             → [] (no aplica)
listTriggers(db)             → [] (no aplica)
getTableInfo(db, coll)       → esquema inferido muestreando 200 docs + listIndexes
getPrimaryKeys(db, coll)     → ['_id'] siempre
queryPaginated(db, coll, opts) → find con skip/limit/sort + filtros estructurados
execute(query, db)           → parsea sintaxis shell Mongo o JSON de comando crudo
executeMany(query, db)       → wrappea execute() en formato multi-statement
insertRow(db, coll, data)    → insertOne por _id
updateRow(db, coll, pk, data) → updateOne $set por _id
deleteRow(db, coll, pk)      → deleteOne por _id
getServerInfo()              → buildInfo + serverStatus
getProcessList()             → currentOp
killProcess(id)              → killOp
listEngineUsers()            → usersInfo
createEngineUser(user, pwd)  → createUser con rol readWrite
grantPrivileges(user, db, privs) → grantRolesToUser (mapea ALL→dbOwner, SELECT→read)
revokePrivileges(user, db, privs) → revokeRolesFromUser
dropEngineUser(user)         → dropUser
executeDDL(ddl, db)          → decodifica JSON de comando o pasa a execute(); ignora comentarios //
getForeignKeys(db)           → [] (no aplica)
createDatabase(name)         → crea colección _navicat_init para materializar la db
dropDatabase(name)           → dropDatabase
truncateTable(db, coll)      → delete {} limit 0
dropTable(db, coll)          → drop (ignora error 26 si no existe)
dropView(db, view)           → drop
renameTable(db, old, new)    → renameCollection en admin
explain(query, db, analyze)  → explain con verbosity queryPlanner o executionStats
dumpCollection(db, coll, cb) → stream de documentos en extendedJSON (para backup/transfer)
insertMany(db, coll, docs)   → insert batch (rehidrata $oid/$date/$binary de extendedJSON)
listCollectionNames(db)      → nombres de colecciones (alias de listTablesLight)
```

**Gotchas importantes de Amazon DocumentDB:**

1. **`listCollections` rechaza el filtro `type`** (error 303) — se filtra en el cliente.
2. **Solo SCRAM-SHA-1** en DocumentDB (SHA-256 no está disponible). El driver lo autodetecta.
3. **TLS obligatorio** con el CA bundle de AWS (`global-bundle.pem`).
4. **`pack('d')`** para doubles BSON en PHP x86_64, NO `pack('E')` — comportamiento diferente entre builds de PHP.
5. **`\stdClass`** se usa como marcador de documento vacío `{}` en filtros y cursores — el codec BSON debe manejarlo.

### `scripts/import-mongo-config.php` — importador de conexiones (76 líneas)

Lee `~/.mongo_config.json` (formato `{"hosts": [...]}`) y crea conexiones en la BD SQLite de la app.

```bash
# Uso:
php scripts/import-mongo-config.php

# Variable de entorno opcional:
MONGO_CONFIG_PATH=/ruta/alternativa.json php scripts/import-mongo-config.php
```

**Formato esperado de `~/.mongo_config.json`:**
```json
{
  "hosts": [
    {
      "name": "dev",
      "host": "mi-cluster.c8zmka2vstwe.us-east-1.docdb.amazonaws.com",
      "port": 27017,
      "username": "root",
      "password": "mi-password",
      "auth_db": "admin",
      "ca_file": "global-bundle.pem"
    }
  ]
}
```

Los campos `auth_db` y `ca_file` se guardan en `meta_json` de la conexión (no en columnas propias).

---

## Archivos modificados

### `src/Drivers/DriverFactory.php`

**Cambios:** 3 líneas.

```diff
- public static function getDriver(array $connRow): MySqlDriver|PostgresDriver
+ public static function getDriver(array $connRow): MySqlDriver|PostgresDriver|MongoDriver
  
  return match ($creds['engine']) {
      'postgres' => new PostgresDriver($creds),
+     'mongodb'  => new MongoDriver($creds),
      default    => new MySqlDriver($creds),
  };
```

La clave `'mongodb'` en el campo `engine` de la conexión activa el driver nuevo.

---

### `src/Connections/ConnectionRepository.php`

**Cambios:** 3 bloques, ~45 líneas añadidas.

**1. `credentials()` propaga `meta`:**
```diff
+ 'meta' => json_decode((string)($row['meta_json'] ?? '{}'), true) ?: [],
```
`MongoDriver` lee de `$creds['meta']` las opciones: `authDb`, `caFile`, `tls`, `tlsAllowInvalid`, `authMechanism`.

**2. `create()` persiste opciones Mongo en `meta_json`:**
```diff
- '{}',
+ self::encodeMeta($input),
```
`encodeMeta()` extrae `authDb`, `caFile`, `tls`, `tlsAllowInvalid`, `authMechanism` del input y los guarda en `meta_json`. No requiere nuevas columnas en la BD SQLite.

**3. `update()` fusiona opciones Mongo en `meta_json`:**
Cuando el PUT de conexión incluye alguna de las claves Mongo, se hace `array_merge` sobre el `meta_json` existente.

> **Sin migración de esquema**: todas las opciones extras de MongoDB se almacenan en la columna `meta_json TEXT` ya existente en la tabla `connections`. No es necesario añadir columnas ni ejecutar migraciones.

---

### `src/Http/Router.php`

**Cambios:** ~66 líneas.

**1. `use` statement:**
```php
use Navicat\Drivers\MongoDriver;
```

**2. `driverFromInput()` — "Test Connection" de conexiones nuevas:**
Ahora construye el array `meta` desde el body del request (campos `authDb`, `caFile`, `tls`, etc.) y lo pasa a `MongoDriver`.
```diff
- return ($input['engine'] ?? '') === 'postgres'
-     ? new PostgresDriver($creds)
-     : new MySqlDriver($creds);
+ return match ($input['engine'] ?? '') {
+     'postgres' => new PostgresDriver($creds),
+     'mongodb'  => new MongoDriver($creds),
+     default    => new MySqlDriver($creds),
+ };
```

**3. `generateDesignerDDL()` — Designer de tablas:**
Para MongoDB, el designer genera un comando `{"create": "nombre"}` en vez de SQL DDL, o un comentario no-op si la colección ya existe (schemaless).

**4. `batch-apply` de usuarios (endpoint `/api/engine-users/{id}/batch-apply`):**
Para MongoDB, llama al nuevo `applyMongoBatch()` en vez de `executeDDL(sql)`.

**5. `applyMongoBatch()` — método nuevo:**
Traduce el payload de grants de la UI a llamadas del driver: `createEngineUser`, `grantPrivileges` o `revokePrivileges`.

**6. `buildBatchScript()` — preview de script:**
Para MongoDB retorna un comentario descriptivo (no genera SQL).

---

### `src/Services/BackupService.php`

**Cambios:** ~100 líneas añadidas.

**Dispatch en `backup()`:**
```diff
+ if ($engine === 'mongodb') {
+     $this->exportMongo($conn, $database, $options, $writer, $emit);
+ } elseif ($engine === 'postgres') {
```

**Dispatch en `restore()`:**
```diff
+ if ($engine === 'mongodb') {
+     $this->restoreMongo($conn, $database, $filePath, $emit);
+     return;
+ }
```

**`exportMongo()` — formato NDJSON:**
```
// DB Tool Box native MongoDB backup (NDJSON)
// Database: ecomm_admin_cms_api
// Generated: 2026-06-01T20:00:00+00:00
// @collection: orders
{"_id":{"$oid":"..."},"total":99.5,...}
{"_id":{"$oid":"..."},"total":42.0,...}
// @collection: customers
{"_id":{"$oid":"..."},"name":"John",...}
```

Los documentos se serializan en **extendedJSON** (con marcadores `$oid`, `$date`, `$binary`) para preservar los tipos BSON en el restore.

**`restoreMongo()` — lectura del NDJSON:**
Parsea línea a línea, detecta `// @collection:` para saber a qué colección insertar, acumula batches de 500 y llama `insertMany()`.

---

### `src/Services/TransferService.php`

**Cambios:** ~80 líneas añadidas.

**Problema raíz resuelto:** el path anterior usaba `queryPaginated` (que aplana documentos anidados a strings JSON en celdas) + `insertRow` (documento por documento). Los documentos llegaban corruptos al destino.

**Solución — path nativo Mongo→Mongo:**
```php
if ($srcIsMongo && $dstIsMongo) {
    $copied = self::copyMongo($srcDrv, $sourceDb, $table,
                              $dstDrv, $targetDb, $table, $batchSize, $emit);
} else {
    $copied = self::copyTable(...); // path SQL original
}
```

**`copyMongo()`:** usa `dumpCollection()` (extendedJSON) + `insertMany()` en batches del tamaño configurado. Fidelidad total de tipos BSON.

**Corrección adicional:** `copyTable()` ya no lanza excepción en colecciones/tablas vacías — retorna 0 silenciosamente.

---

### `navicat-ui/src/features/bundle.jsx`

**Cambios:** ~68 líneas (el bundle es fuente recuperada/deobfuscada — se edita directamente).

#### Formulario de conexión

**Puerto por defecto para MongoDB:**
```jsx
port: A === "mysql" ? 3306 : A === "mongodb" ? 27017 : 5432
```

**Estado inicial con campos MongoDB (al editar conexión existente):**
```jsx
authDb: (e?.metaJson || {}).authDb ?? "admin",
caFile: (e?.metaJson || {}).caFile ?? "",
tls:    (e?.metaJson || {}).tls ?? true,
tlsAllowInvalid: (e?.metaJson || {}).tlsAllowInvalid ?? false,
authMechanism:   (e?.metaJson || {}).authMechanism ?? "auto"
```

**Radio button "MongoDB" y campos específicos:**
```jsx
<label><input type="radio" checked={s.engine === "mongodb"}
              onChange={() => h("mongodb")} /> MongoDB</label>

{s.engine === "mongodb" && <>
  <Field label="Auth Database">
    <input value={s.authDb ?? "admin"} ... placeholder="admin" />
  </Field>
  <Field label="TLS / CA File">
    <label><input type="checkbox" checked={!!s.tls} ... /> TLS</label>
    <input value={s.caFile ?? ""} ... placeholder="global-bundle.pem (DocumentDB)"
           disabled={!s.tls} />
  </Field>
</>}
```

#### Árbol lateral de objetos

**Secciones para colecciones MongoDB** (se ocultan FK, checks y triggers):
```jsx
(e.engine === "mongodb"
  ? ["fields", "indexes"]
  : ["fields", "indexes", "foreignKeys", "checks", "triggers"]
).map(C => <_Component17 ... />)
```

**Context menu de campos — adaptado a MongoDB:**

| Acción antes | Acción para MongoDB |
|---|---|
| "SELECT column" | "Query field" → `db.coll.find({}, {"campo": 1, "_id": 0}).limit(100)` |
| "Generate SQL (column)" | "Sample field (aggregate)" → `$group/$sort/$limit` por ese campo |
| "Drop Index Query" | `db.coll.dropIndex("nombre")` |
| "Generate SQL (table)" | "View Schema (inferred)" |

#### Panel de queries (SQL Editor)

**Monaco en modo JavaScript para MongoDB:**
```jsx
defaultLanguage={fe === "mongodb" ? "javascript" : "sql"}
language={fe === "mongodb" ? "javascript" : "sql"}
```

**Tab "SQL" → "Query" + se oculta "Visual":**
```jsx
{fe === "mongodb" ? "Query" : "SQL"}
{fe !== "mongodb" && <button ...> Visual </button>}
```

**Botones adaptados:**
```jsx
{fe === "mongodb" ? "Format" : "Beautify"}
{fe !== "mongodb" && <button>Minify</button>}
{fe !== "mongodb" && <button>Snippets</button>}
```

#### Data grid — filtro avanzado

**Placeholder contextual según engine:**
```jsx
placeholder={m === "mongodb"
  ? '{"field": {"$gt": 0}}'
  : "WHERE clause (e.g. id > 10)"}
```

#### Visual query builder (`FE()`)

**Para MongoDB genera `find()` en vez de SELECT:**
```javascript
// Antes: "SELECT * FROM collection WHERE ..."
// Ahora para mongodb:
db.collection.find({"campo": {"$gt": 0}}, {"campo1": 1}).sort({"campo": 1}).limit(100)
```

---

## Rebuild del frontend

Después de cualquier cambio en `navicat-ui/src/features/bundle.jsx`:

```bash
cd navicat-php-1.0.0
npm run build:frontend
# → escribe a public/assets/index-<hash>.js y actualiza public/index.html
```

Requiere Node.js instalado en la máquina de build (no en producción).

---

## Flujo de datos: cómo funciona una query MongoDB

```
UI (Monaco JS) → POST /api/query/{connId}
                    ↓
            Router.php::dispatchQuery()
                    ↓
            DriverFactory::getDriver($connRow)
              → MongoDriver($creds)
                    ↓
            MongoDriver::execute("db.orders.find({status:'pending'})")
                    ↓
            parseShell() → ['orders', 'find', [{status:'pending'}]]
                    ↓
            MongoClient::runCommand(db, {find:'orders', filter:{...}})
                    ↓
            Bson::encode() → bytes wire protocol
                    ↓
            fwrite($sock, op_msg_frame)  ← TLS socket
                    ↓
            fread → Bson::decode() → PHP array
                    ↓
            documentsToGrid() → {columns, rows} (aplana docs anidados)
                    ↓
            Response::json() → navegador
```

---

## Setup en una copia nueva del sistema

### Paso 1 — Copiar archivos nuevos

```bash
# Desde la instalación original:
cp -r src/Mongo/                 /ruta/nueva/src/Mongo/
cp src/Drivers/MongoDriver.php   /ruta/nueva/src/Drivers/
cp scripts/import-mongo-config.php /ruta/nueva/scripts/
```

### Paso 2 — Aplicar los diffs a archivos existentes

Los cuatro archivos modificados son:

```
src/Drivers/DriverFactory.php          (+3 líneas)
src/Connections/ConnectionRepository.php (+45 líneas)
src/Http/Router.php                    (+66 líneas)
src/Services/BackupService.php         (+100 líneas)
src/Services/TransferService.php       (+80 líneas)
```

Aplica los diffs con `patch`:

```bash
# Genera los patches desde la instalación original:
cd /home/luisjimenez
for f in \
  navicat-php-1.0.0/src/Drivers/DriverFactory.php \
  navicat-php-1.0.0/src/Connections/ConnectionRepository.php \
  navicat-php-1.0.0/src/Http/Router.php \
  navicat-php-1.0.0/src/Services/BackupService.php \
  navicat-php-1.0.0/src/Services/TransferService.php; do
    diff -u "navicat-php-1.0.0-backup-pre-mongodb-20260601-194050/${f#navicat-php-1.0.0/}" "$f"
done > mongodb_backend.patch

# En la copia nueva:
cd /ruta/nueva
patch -p0 < mongodb_backend.patch
```

### Paso 3 — Actualizar la UI (`bundle.jsx`)

```bash
# Copiar el bundle ya modificado:
cp /home/luisjimenez/navicat-ui/src/features/bundle.jsx \
   /ruta/nueva/navicat-ui/src/features/bundle.jsx

# Rebuild:
cd /ruta/nueva/navicat-php-1.0.0
npm run build:frontend
```

### Paso 4 — CA bundle de AWS

```bash
# Descargar si no existe:
curl -o ~/global-bundle.pem \
  https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem

# Verificar:
openssl verify ~/global-bundle.pem
```

### Paso 5 — Importar conexiones DocumentDB

```bash
# Crear / verificar ~/.mongo_config.json con los hosts reales
# Luego:
cd /ruta/nueva/navicat-php-1.0.0
php scripts/import-mongo-config.php
```

### Paso 6 — Reiniciar el servidor

```bash
pm2 restart navicat-php
# o para PHP built-in server:
php -S 0.0.0.0:8080 -t public public/index.php
```

### Paso 7 — Verificar

```bash
php -r '
require "src/bootstrap.php";
$row = Navicat\App::db()->query("SELECT * FROM connections WHERE engine=\"mongodb\" LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
if (!$row) { die("No hay conexiones mongodb\n"); }
$drv = Navicat\Drivers\DriverFactory::getDriver($row);
var_dump($drv->testConnection());
print_r($drv->listDatabases());
'
```

---

## Limitaciones conocidas

| Funcionalidad | Estado | Nota |
|---|---|---|
| Visual Query Builder | No aplica a Mongo | Se oculta el tab para conexiones MongoDB |
| Designer de tablas (columnas tipadas) | No aplica | MongoDB es schemaless; el designer solo puede crear colecciones |
| Foreign keys | No aplica | Mongo no tiene FK → siempre retorna `[]` |
| Routines / Triggers | No aplica | Mongo no tiene → retorna `[]` |
| `EXPLAIN ANALYZE` equivalente | Parcial | Se usa `executionStats` como verbosity |
| Diff de esquemas (DiffService) | Parcial | Compara listas de colecciones; no compara esquemas de documentos |
| Transfer cross-engine (Mongo→MySQL) | Parcial | Usa el path de grid plano; documentos anidados se convierten a JSON strings |
| DocumentDB: `$lookup` entre colecciones | Limitado | DocumentDB no soporta todos los operadores de agregación de Mongo 5+ |
| Conexión `mongodb:prod` | Pendiente | Necesita el host y password reales del cluster prod; actualmente tiene valores placeholder |

---

## Variables de configuración de la conexión

Las conexiones MongoDB guardan sus opciones en la columna `meta_json` de la tabla `connections` (no requieren cambios de esquema). Campos:

| Campo en `meta_json` | Tipo | Default | Descripción |
|---|---|---|---|
| `authDb` | string | `"admin"` | Base de datos de autenticación |
| `caFile` | string | `null` | Ruta al CA bundle TLS. Puede ser ruta absoluta o nombre relativo a `$HOME`. Para DocumentDB: `"global-bundle.pem"` |
| `tls` | bool | `true` | Activar TLS (obligatorio para DocumentDB) |
| `tlsAllowInvalid` | bool | `false` | Ignorar errores de certificado (solo para desarrollo) |
| `authMechanism` | string | `"auto"` | `"auto"` detecta SCRAM-SHA-1 vs SHA-256 según el servidor. Alternativas: `"SCRAM-SHA-1"`, `"SCRAM-SHA-256"` |
