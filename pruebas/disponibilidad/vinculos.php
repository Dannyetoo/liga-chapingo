<?php
/**
 * /disponibilidad/admin/vinculos.php — ENTORNO DE PRUEBAS
 *
 * Vincula cada equipo del Estado de cuenta (arbitrajes/datos/estado.json,
 * sólo lectura) con un equipo de MySQL (equipos.id) mediante la tabla
 * equipos_estado_cuenta. Nunca empareja por nombre.
 *
 * Archivo AUTÓNOMO: sólo necesita ../db.php. Si existen funciones.php y
 * lib/acceso.php con requerir_admin(), también los usa.
 *
 * Operaciones que hace sobre la base (todas no destructivas):
 *   - CREATE TABLE IF NOT EXISTS equipos_estado_cuenta
 *   - INSERT/UPDATE de vínculos
 *   - INSERT en equipos («Crear y vincular»)
 *   - DELETE sólo de la fila del vínculo («Quitar vínculo»); nunca del equipo ni de sus horarios.
 */
declare(strict_types=1);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$VV_BASE = dirname(__DIR__);          // .../disponibilidad
$VV_RAIZ = dirname($VV_BASE);         // .../pruebas
$VV_ESTADO = $VV_RAIZ . '/arbitrajes/datos/estado.json';
const VV_TABLA = 'equipos_estado_cuenta';

/* ---------- 1. Sólo en el entorno de pruebas ---------- */
$vvHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$vvHost = preg_replace('/:\d+$/', '', $vvHost);
if (strpos($vvHost, 'pruebas.') !== 0 && !in_array($vvHost, ['localhost', '127.0.0.1'], true)) {
    http_response_code(403);
    exit('Esta página sólo está disponible en el entorno de pruebas.');
}

/* ---------- 2. Conexión (db.php existente, sin tocarlo) ---------- */
if (!is_file($VV_BASE . '/db.php')) {
    http_response_code(500);
    exit('No se encontró disponibilidad/db.php.');
}
if (is_file($VV_BASE . '/funciones.php')) {
    require_once $VV_BASE . '/funciones.php';
}
if (is_file($VV_BASE . '/lib/acceso.php')) {
    require_once $VV_BASE . '/lib/acceso.php';
}
require_once $VV_BASE . '/db.php';

/* Control de acceso del módulo, si existe (un capitán recibe 403). */
if (function_exists('requerir_admin')) {
    requerir_admin();
}

$vvPdo = null;
foreach ($GLOBALS as $vvVal) {
    if ($vvVal instanceof PDO) { $vvPdo = $vvVal; break; }
}
if (!$vvPdo) {
    foreach (['db', 'pdo', 'obtener_pdo', 'obtenerPDO', 'obtener_conexion', 'conexion', 'conectar', 'get_pdo', 'getPDO', 'getConnection'] as $vvFn) {
        if (function_exists($vvFn)) {
            try {
                $vvR = $vvFn();
                if ($vvR instanceof PDO) { $vvPdo = $vvR; break; }
            } catch (Throwable $e) { /* se ignora */ }
        }
    }
}
if (!$vvPdo) {
    http_response_code(500);
    exit('db.php se cargó, pero no se encontró la conexión PDO.');
}
$vvPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$vvPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---------- 3. Sesión propia para CSRF y mensajes ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $vvRutaCookie = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    session_name('lchvinc');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $vvRutaCookie,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}
if (empty($_SESSION['vv_csrf'])) {
    $_SESSION['vv_csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Utilidades ---------- */
function vv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function vv_flash(string $tipo, string $texto): void { $_SESSION['vv_flash'][] = [$tipo, $texto]; }

function vv_volver(): void {
    header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

function vv_tabla_existe(PDO $pdo): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE '" . VV_TABLA . "'")->fetchColumn();
}

function vv_columnas(PDO $pdo, string $tabla): array {
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . $tabla . '`')->fetchAll() as $c) {
        $cols[$c['Field']] = $c;
    }
    return $cols;
}

/** Lee estado.json y devuelve [equipos, rutasEncontradas, error]. */
function vv_equipos_estado(string $ruta): array {
    if (!is_file($ruta)) return [[], [], 'No se encontró arbitrajes/datos/estado.json.'];
    if (!is_readable($ruta)) return [[], [], 'estado.json existe pero no se puede leer (permisos).'];
    $datos = json_decode((string)file_get_contents($ruta), true);
    if (!is_array($datos)) return [[], [], 'estado.json no es un JSON válido.'];

    $hallazgos = [];
    vv_buscar_listas($datos, '', $hallazgos, 0);

    // Preferir listas cuyo nombre contenga «equipo».
    $conEquipo = array_filter($hallazgos, function ($h) { return stripos($h['ruta'], 'equipo') !== false; });
    if ($conEquipo) $hallazgos = $conEquipo;

    $equipos = [];
    $rutas = [];
    foreach ($hallazgos as $h) {
        $rutas[] = $h['ruta'] . ' (' . count($h['items']) . ')';
        foreach ($h['items'] as $clave => $e) {
            $id = null;
            foreach (['id', 'equipoId', 'equipo_id', 'idEquipo', 'id_equipo', 'clave'] as $k) {
                if (isset($e[$k]) && (is_string($e[$k]) || is_int($e[$k]))) { $id = (string)$e[$k]; break; }
            }
            if ($id === null && is_string($clave)) $id = $clave;
            if ($id === null || $id === '') continue;

            $nombre = '';
            foreach (['nombre', 'name', 'equipo', 'nombre_equipo', 'nombreEquipo'] as $k) {
                if (isset($e[$k]) && is_string($e[$k])) { $nombre = $e[$k]; break; }
            }
            $extra = [];
            foreach (['categoria', 'rama', 'division', 'edicion', 'temporada', 'grupo'] as $k) {
                if (isset($e[$k]) && is_scalar($e[$k]) && $e[$k] !== '') $extra[] = (string)$e[$k];
            }
            $capitan = '';
            foreach (['capitan', 'capitan_nombre', 'capitanNombre'] as $k) {
                if (isset($e[$k]) && is_string($e[$k])) { $capitan = $e[$k]; break; }
            }
            if (!isset($equipos[$id])) {
                $equipos[$id] = [
                    'id' => $id,
                    'nombre' => $nombre !== '' ? $nombre : $id,
                    'extra' => $extra ? implode(' · ', $extra) : trim(str_replace('/', ' › ', $h['ruta']), ' ›'),
                    'capitan' => $capitan,
                ];
            }
        }
    }
    uasort($equipos, function ($a, $b) { return strcasecmp($a['nombre'], $b['nombre']); });
    $err = $equipos ? null : 'No se reconoció ninguna lista de equipos dentro de estado.json. Usa «Vincular manualmente».';
    return [$equipos, $rutas, $err];
}

function vv_es_equipo($e): bool {
    if (!is_array($e)) return false;
    foreach (['nombre', 'name', 'equipo', 'nombre_equipo', 'nombreEquipo'] as $k) {
        if (isset($e[$k]) && is_string($e[$k])) return true;
    }
    return false;
}

function vv_buscar_listas(array $nodo, string $ruta, array &$hallazgos, int $prof): void {
    if ($prof > 6) return;
    $n = count($nodo);
    if ($n > 0) {
        $ok = 0;
        foreach ($nodo as $e) { if (vv_es_equipo($e)) $ok++; }
        $esMapa = array_keys($nodo) !== range(0, $n - 1);
        $tieneId = false;
        foreach ($nodo as $e) {
            if (is_array($e) && (isset($e['id']) || isset($e['equipoId']) || isset($e['equipo_id']) || isset($e['idEquipo']) || isset($e['id_equipo']))) { $tieneId = true; break; }
        }
        if ($ok / $n >= 0.6 && ($tieneId || $esMapa) && $ruta !== '') {
            $hallazgos[] = ['ruta' => $ruta, 'items' => $nodo];
            return;
        }
    }
    foreach ($nodo as $k => $v) {
        if (is_array($v)) vv_buscar_listas($v, $ruta . '/' . $k, $hallazgos, $prof + 1);
    }
}

function vv_crear_tabla(PDO $pdo): string {
    $col = $pdo->query("SHOW COLUMNS FROM equipos LIKE 'id'")->fetch();
    if (!$col) throw new RuntimeException('La tabla equipos no tiene columna id.');
    $tipo = strtolower(trim((string)$col['Type']));
    if (!preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?( zerofill)?$/', $tipo)) {
        throw new RuntimeException('Tipo inesperado de equipos.id: ' . $tipo);
    }
    $tipo = preg_replace('/ zerofill$/', '', $tipo);
    $cuerpo = "CREATE TABLE IF NOT EXISTS `" . VV_TABLA . "` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        equipo_id {$tipo} NOT NULL,
        estado_equipo_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        estado_nombre VARCHAR(150) NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_estado_equipo (estado_equipo_id),
        KEY idx_eec_equipo (equipo_id)%s
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    try {
        $pdo->exec(sprintf($cuerpo, ",\n        CONSTRAINT fk_eec_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id)"));
        return 'Tabla creada (equipo_id ' . $tipo . ', con llave foránea a equipos).';
    } catch (PDOException $e) {
        $pdo->exec(sprintf($cuerpo, ''));
        return 'Tabla creada (equipo_id ' . $tipo . '). La llave foránea no se pudo crear (probablemente equipos no es InnoDB); la página valida la relación por su cuenta.';
    }
}

function vv_insertar_equipo(PDO $pdo, string $nombre, string $capitan): int {
    $cols = vv_columnas($pdo, 'equipos');
    $campos = ['nombre'];
    $valores = [mb_substr($nombre, 0, 150)];
    if (isset($cols['capitan_nombre'])) { $campos[] = 'capitan_nombre'; $valores[] = $capitan; }
    if (isset($cols['activo']))         { $campos[] = 'activo';         $valores[] = 1; }
    $sql = 'INSERT INTO equipos (' . implode(', ', $campos) . ') VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')';
    $pdo->prepare($sql)->execute($valores);
    return (int)$pdo->lastInsertId();
}

/** Vincula dentro de la transacción abierta. Devuelve null o mensaje de error. */
function vv_vincular(PDO $pdo, string $estadoId, string $estadoNombre, int $equipoId): ?string {
    $st = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id = ?');
    $st->execute([$equipoId]);
    if (!$st->fetch()) return 'El equipo de MySQL elegido no existe.';

    $st = $pdo->prepare('SELECT estado_equipo_id, estado_nombre FROM `' . VV_TABLA . '` WHERE equipo_id = ? AND estado_equipo_id <> ? FOR UPDATE');
    $st->execute([$equipoId, $estadoId]);
    if ($otro = $st->fetch()) {
        return 'Ese equipo de MySQL ya está vinculado con «' . ($otro['estado_nombre'] ?: $otro['estado_equipo_id']) . '». Quita ese vínculo primero.';
    }
    $st = $pdo->prepare('SELECT id FROM `' . VV_TABLA . '` WHERE estado_equipo_id = ? FOR UPDATE');
    $st->execute([$estadoId]);
    if ($fila = $st->fetch()) {
        $pdo->prepare('UPDATE `' . VV_TABLA . '` SET equipo_id = ?, estado_nombre = ? WHERE id = ?')
            ->execute([$equipoId, mb_substr($estadoNombre, 0, 150), $fila['id']]);
    } else {
        $pdo->prepare('INSERT INTO `' . VV_TABLA . '` (equipo_id, estado_equipo_id, estado_nombre) VALUES (?, ?, ?)')
            ->execute([$equipoId, $estadoId, mb_substr($estadoNombre, 0, 150)]);
    }
    return null;
}

/* ---------- Datos ---------- */
[$vvEquiposEstado, $vvRutasJson, $vvErrorJson] = vv_equipos_estado($VV_ESTADO);
$vvTabla = vv_tabla_existe($vvPdo);

/* ---------- 4. Acciones (POST + CSRF) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['vv_csrf'], (string)($_POST['csrf'] ?? ''))) {
        vv_flash('error', 'La sesión caducó. Vuelve a intentarlo.');
        vv_volver();
    }
    $accion = (string)($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear_tabla') {
            vv_flash('ok', vv_crear_tabla($vvPdo));
            vv_volver();
        }
        if (!$vvTabla) {
            vv_flash('error', 'Primero crea la tabla de vínculos.');
            vv_volver();
        }

        $estadoId = (string)($_POST['estado_id'] ?? '');
        $manual = $accion === 'manual';
        if (in_array($accion, ['vincular', 'crear_y_vincular', 'manual'], true)) {
            if ($estadoId === '' || strlen($estadoId) > 64 || preg_match('/[\x00-\x1F]/', $estadoId)) {
                vv_flash('error', 'El id del Estado de cuenta está vacío o no es válido.');
                vv_volver();
            }
            if (!$manual && !isset($vvEquiposEstado[$estadoId])) {
                vv_flash('error', 'Ese equipo ya no aparece en el Estado de cuenta.');
                vv_volver();
            }
        }
        $nombreEstado = $manual ? trim((string)($_POST['estado_nombre'] ?? '')) : ($vvEquiposEstado[$estadoId]['nombre'] ?? '');

        if ($accion === 'vincular' || $manual) {
            $equipoId = (int)($_POST['equipo_id'] ?? 0);
            if ($equipoId <= 0) { vv_flash('error', 'Elige un equipo de MySQL.'); vv_volver(); }
            $vvPdo->beginTransaction();
            $err = vv_vincular($vvPdo, $estadoId, $nombreEstado, $equipoId);
            if ($err) { $vvPdo->rollBack(); vv_flash('error', $err); }
            else { $vvPdo->commit(); vv_flash('ok', 'Vinculado: «' . ($nombreEstado ?: $estadoId) . '».'); }
            vv_volver();
        }

        if ($accion === 'crear_y_vincular') {
            $e = $vvEquiposEstado[$estadoId];
            $vvPdo->beginTransaction();
            $nuevo = vv_insertar_equipo($vvPdo, $e['nombre'], $e['capitan']);
            $err = vv_vincular($vvPdo, $estadoId, $e['nombre'], $nuevo);
            if ($err) { $vvPdo->rollBack(); vv_flash('error', $err); }
            else { $vvPdo->commit(); vv_flash('ok', 'Equipo creado en MySQL (id ' . $nuevo . ') y vinculado: «' . $e['nombre'] . '».'); }
            vv_volver();
        }

        if ($accion === 'crear_todos') {
            $ya = $vvPdo->query('SELECT estado_equipo_id FROM `' . VV_TABLA . '`')->fetchAll(PDO::FETCH_COLUMN);
            $ya = array_flip(array_map('strval', $ya));
            $pendientes = array_filter($vvEquiposEstado, function ($e) use ($ya) { return !isset($ya[$e['id']]); });
            if (!$pendientes) { vv_flash('ok', 'No hay equipos pendientes.'); vv_volver(); }
            $vvPdo->beginTransaction();
            foreach ($pendientes as $e) {
                $nuevo = vv_insertar_equipo($vvPdo, $e['nombre'], $e['capitan']);
                $err = vv_vincular($vvPdo, $e['id'], $e['nombre'], $nuevo);
                if ($err) { $vvPdo->rollBack(); vv_flash('error', $err . ' No se guardó ningún cambio.'); vv_volver(); }
            }
            $vvPdo->commit();
            vv_flash('ok', 'Creados y vinculados ' . count($pendientes) . ' equipos.');
            vv_volver();
        }

        if ($accion === 'quitar') {
            $st = $vvPdo->prepare('DELETE FROM `' . VV_TABLA . '` WHERE estado_equipo_id = ?');
            $st->execute([$estadoId]);
            vv_flash('ok', $st->rowCount() ? 'Vínculo quitado. El equipo y sus horarios siguen en MySQL.' : 'Ese vínculo ya no existía.');
            vv_volver();
        }

        vv_flash('error', 'Acción no reconocida.');
        vv_volver();
    } catch (Throwable $e) {
        if ($vvPdo->inTransaction()) $vvPdo->rollBack();
        error_log('[vinculos.php] ' . $e->getMessage());
        $msg = 'No se pudo completar la operación. No se guardó ningún cambio.';
        if ($e instanceof RuntimeException) $msg .= ' ' . $e->getMessage();
        elseif ($e instanceof PDOException && $e->getCode() === '23000') $msg .= ' Hay un dato duplicado (¿ya existe un equipo con ese nombre?).';
        vv_flash('error', $msg);
        vv_volver();
    }
}

/* ---------- 5. Consulta para mostrar ---------- */
$vvEquiposMysql = $vvPdo->query('SELECT id, nombre, activo FROM equipos ORDER BY nombre')->fetchAll();
$vvVinculos = [];
$vvUsados = [];
if ($vvTabla) {
    $sql = 'SELECT v.estado_equipo_id, v.estado_nombre, v.equipo_id, e.nombre, e.activo
              FROM `' . VV_TABLA . '` v LEFT JOIN equipos e ON e.id = v.equipo_id';
    foreach ($vvPdo->query($sql)->fetchAll() as $f) {
        $vvVinculos[(string)$f['estado_equipo_id']] = $f;
        $vvUsados[(int)$f['equipo_id']] = true;
    }
}
$vvPendientes = 0;
foreach ($vvEquiposEstado as $e) { if (!isset($vvVinculos[$e['id']])) $vvPendientes++; }
$vvHuerfanos = array_diff_key($vvVinculos, $vvEquiposEstado);

/* Diagnóstico de archivos (sólo existencia y palabras clave; nunca contenido). */
function vv_archivo(string $ruta, ?string $contiene = null): array {
    if (!is_file($ruta)) return [false, 'no existe'];
    if ($contiene === null) return [true, 'existe'];
    $txt = (string)@file_get_contents($ruta);
    return strpos($txt, $contiene) !== false ? [true, 'existe y contiene «' . $contiene . '»'] : [false, 'existe, pero NO contiene «' . $contiene . '» (versión anterior)'];
}
$vvRefTabla = [];
foreach (array_merge([$VV_BASE . '/entrar.php'], glob($VV_BASE . '/lib/*.php') ?: []) as $f) {
    if (is_file($f) && strpos((string)file_get_contents($f), VV_TABLA) !== false) $vvRefTabla[] = substr($f, strlen($VV_BASE) + 1);
}
$vvDatosDir = $VV_BASE . '/datos';
$vvChecks = [
    ['disponibilidad/entrar.php', vv_archivo($VV_BASE . '/entrar.php')],
    ['disponibilidad/lib/puente-capitanes.php', vv_archivo($VV_BASE . '/lib/puente-capitanes.php')],
    ['disponibilidad/lib/vinculos.php', vv_archivo($VV_BASE . '/lib/vinculos.php')],
    ['disponibilidad/datos/ (registro de pases)', is_dir($vvDatosDir) ? (is_writable($vvDatosDir) ? [true, 'existe y se puede escribir'] : [false, 'existe pero NO se puede escribir']) : [false, 'no existe']],
    ['capitanes/codigos-lib.php', vv_archivo($VV_RAIZ . '/capitanes/codigos-lib.php', 'paseDisponibilidad')],
    ['capitanes/api.php', vv_archivo($VV_RAIZ . '/capitanes/api.php', 'disponibilidad')],
    ['capitanes/index.html', vv_archivo($VV_RAIZ . '/capitanes/index.html', 'Disponibilidad del equipo')],
    ['Código que busca en ' . VV_TABLA, $vvRefTabla ? [true, implode(', ', $vvRefTabla)] : [false, 'ni entrar.php ni lib/*.php usan esta tabla']],
    ['Tabla ' . VV_TABLA, $vvTabla ? [true, count($vvVinculos) . ' vínculos'] : [false, 'no existe (créala abajo)']],
    ['arbitrajes/datos/estado.json', $vvErrorJson ? [false, $vvErrorJson] : [true, count($vvEquiposEstado) . ' equipos en ' . implode('; ', $vvRutasJson)]],
];

$vvFlash = $_SESSION['vv_flash'] ?? [];
unset($_SESSION['vv_flash']);
$vvCsrf = $_SESSION['vv_csrf'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Vínculos de equipos · Disponibilidad</title>
<style>
:root{--verde:#0b5d3b;--verde2:#0f7a4e;--tinta:#16211c;--gris:#667085;--borde:#e3e7e5;--fondo:#f4f6f5;--rojo:#b42318;--ok:#067647;--amarillo:#f2b705}
*{box-sizing:border-box}
body{margin:0;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--tinta);background:var(--fondo)}
.pruebas{position:sticky;top:0;z-index:5;text-align:center;font-weight:800;letter-spacing:.08em;font-size:13px;padding:7px;background:repeating-linear-gradient(135deg,var(--amarillo) 0 18px,#e5ac00 18px 36px)}
main{max-width:1100px;margin:0 auto;padding:18px 16px 60px}
h1{font-size:22px;margin:6px 0 4px;text-transform:uppercase;letter-spacing:.02em}
.sub{color:var(--gris);margin:0 0 16px}
.card{background:#fff;border:1px solid var(--borde);border-radius:12px;padding:16px;margin-bottom:16px}
.card h2{font-size:15px;text-transform:uppercase;letter-spacing:.04em;margin:0 0 10px}
.msg{padding:10px 12px;border-radius:8px;margin-bottom:10px}
.msg.ok{background:#ecfdf3;color:var(--ok);border:1px solid #abefc6}
.msg.error{background:#fef3f2;color:var(--rojo);border:1px solid #fecdca}
.diag{list-style:none;margin:0;padding:0}
.diag li{display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed var(--borde);flex-wrap:wrap}
.diag b{min-width:260px}
.si{color:var(--ok);font-weight:700}.no{color:var(--rojo);font-weight:700}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--borde);vertical-align:middle}
th{font-size:12px;text-transform:uppercase;color:var(--gris)}
code{background:#eef2f0;padding:1px 5px;border-radius:4px;font-size:13px;word-break:break-all}
.chip{display:inline-block;font-size:12px;font-weight:700;border-radius:99px;padding:2px 9px}
.chip.v{background:#ecfdf3;color:var(--ok)}.chip.p{background:#fff6e0;color:#8a5a00}.chip.i{background:#fef3f2;color:var(--rojo)}
form.inline{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0}
select,input[type=text]{font:inherit;padding:7px 8px;border:1px solid #c9d1cd;border-radius:8px;max-width:100%}
button{font:inherit;font-weight:700;border:0;border-radius:8px;padding:8px 12px;cursor:pointer;background:var(--verde);color:#fff}
button:hover{background:var(--verde2)}
button.sec{background:#fff;color:var(--verde);border:1px solid var(--verde)}
button.peligro{background:#fff;color:var(--rojo);border:1px solid #f1b8b2}
.pie{color:var(--gris);font-size:13px}
@media (max-width:760px){
  thead{display:none}
  tr{display:block;border-bottom:1px solid var(--borde);padding:8px 0}
  td{display:block;border:0;padding:4px 0}
  .diag b{min-width:0;width:100%}
}
</style>
</head>
<body>
<div class="pruebas">ENTORNO DE PRUEBAS</div>
<main>
  <h1>Vínculos Estado de cuenta ↔ Disponibilidad</h1>
  <p class="sub">Cada equipo del Estado de cuenta necesita un vínculo explícito con un equipo de MySQL para poder entrar a «Disponibilidad del equipo». Nunca se empareja por nombre.</p>

  <?php foreach ($vvFlash as [$t, $m]): ?>
    <div class="msg <?= vv_h($t) ?>"><?= vv_h($m) ?></div>
  <?php endforeach; ?>

  <section class="card">
    <h2>Diagnóstico de la instalación</h2>
    <ul class="diag">
      <?php foreach ($vvChecks as [$nombre, [$ok, $texto]]): ?>
        <li><b><?= vv_h($nombre) ?></b><span class="<?= $ok ? 'si' : 'no' ?>"><?= $ok ? '✓' : '✗' ?></span><span><?= vv_h($texto) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$vvTabla): ?>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= vv_h($vvCsrf) ?>">
        <input type="hidden" name="accion" value="crear_tabla">
        <button>Crear tabla <?= vv_h(VV_TABLA) ?></button>
        <span class="pie">Sólo CREATE TABLE IF NOT EXISTS. Detecta solo el tipo de <code>equipos.id</code>.</span>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($vvTabla): ?>
  <section class="card">
    <h2>Equipos del Estado de cuenta (<?= count($vvEquiposEstado) ?>) · pendientes: <?= $vvPendientes ?></h2>
    <?php if ($vvPendientes > 0): ?>
      <form method="post" onsubmit="return confirm('Se creará un equipo nuevo en MySQL por cada uno de los <?= (int)$vvPendientes ?> pendientes y se vinculará. ¿Continuar?')" style="margin-bottom:12px">
        <input type="hidden" name="csrf" value="<?= vv_h($vvCsrf) ?>">
        <input type="hidden" name="accion" value="crear_todos">
        <button class="sec">Crear y vincular todos los pendientes</button>
        <span class="pie">Si alguno ya existe en MySQL (p. ej. PSG), vincúlalo primero a mano para no duplicarlo.</span>
      </form>
    <?php endif; ?>

    <?php if ($vvEquiposEstado): ?>
    <table>
      <thead><tr><th>Estado de cuenta</th><th>Id (debe coincidir exacto)</th><th>Estado</th><th>Acción</th></tr></thead>
      <tbody>
      <?php foreach ($vvEquiposEstado as $e): $v = $vvVinculos[$e['id']] ?? null; ?>
        <tr>
          <td><strong><?= vv_h($e['nombre']) ?></strong><br><span class="pie"><?= vv_h($e['extra']) ?></span></td>
          <td><code><?= vv_h($e['id']) ?></code></td>
          <td>
            <?php if (!$v): ?><span class="chip p">Pendiente</span>
            <?php elseif ($v['nombre'] === null): ?><span class="chip i">Equipo MySQL #<?= (int)$v['equipo_id'] ?> no existe</span>
            <?php elseif ((int)$v['activo'] !== 1): ?><span class="chip i">→ <?= vv_h($v['nombre']) ?> (inactivo)</span>
            <?php else: ?><span class="chip v">✓ <?= vv_h($v['nombre']) ?></span><?php endif; ?>
          </td>
          <td>
            <?php if ($v): ?>
              <form method="post" class="inline" onsubmit="return confirm('¿Quitar el vínculo? El equipo y sus horarios NO se borran.')">
                <input type="hidden" name="csrf" value="<?= vv_h($vvCsrf) ?>">
                <input type="hidden" name="accion" value="quitar">
                <input type="hidden" name="estado_id" value="<?= vv_h($e['id']) ?>">
                <button class="peligro">Quitar vínculo</button>
              </form>
            <?php else: ?>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= vv_h($vvCsrf) ?>">
                <input type="hidden" name="estado_id" value="<?= vv_h($e['id']) ?>">
                <select name="equipo_id" aria-label="Equipo de MySQL">
                  <option value="">— equipo existente —</option>
                  <?php foreach ($vvEquiposMysql as $m): if (isset($vvUsados[(int)$m['id']])) continue; ?>
                    <option value="<?= (int)$m['id'] ?>"><?= vv_h($m['nombre']) ?><?= (int)$m['activo'] === 1 ? '' : ' (inactivo)' ?></option>
                  <?php endforeach; ?>
                </select>
                <button name="accion" value="vincular">Vincular</button>
                <button class="sec" name="accion" value="crear_y_vincular" onclick="return confirm('¿Crear «<?= vv_h(addslashes($e['nombre'])) ?>» como equipo nuevo en MySQL y vincularlo?')">Crear y vincular</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Vincular manualmente</h2>
    <p class="pie">Úsalo si tu equipo no aparece arriba. El id debe ser exactamente el del equipo en <code>estado.json</code> (mayúsculas, acentos y espacios incluidos).</p>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= vv_h($vvCsrf) ?>">
      <input type="hidden" name="accion" value="manual">
      <input type="text" name="estado_id" placeholder="id en estado.json" maxlength="64" required>
      <input type="text" name="estado_nombre" placeholder="nombre (referencia)" maxlength="150">
      <select name="equipo_id" required>
        <option value="">— equipo de MySQL —</option>
        <?php foreach ($vvEquiposMysql as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= vv_h($m['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <button>Vincular</button>
    </form>
  </section>

  <?php if ($vvHuerfanos): ?>
  <section class="card">
    <h2>Vínculos cuyo id ya no está en estado.json</h2>
    <table><tbody>
      <?php foreach ($vvHuerfanos as $id => $v): ?>
        <tr>
          <td><code><?= vv_h($id) ?></code> <?= vv_h($v['estado_nombre']) ?></td>
          <td>→ <?= vv_h($v['nombre'] ?? ('#' . $v['equipo_id'])) ?></td>
          <td>
            <form method="post" class="inline" onsubmit="return confirm('¿Quitar este vínculo?')">
              <input type="hidden" name="csrf" value="<?= vv_h($vvCsrf) ?>">
              <input type="hidden" name="accion" value="quitar">
              <input type="hidden" name="estado_id" value="<?= vv_h($id) ?>">
              <button class="peligro">Quitar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <?php endif; ?>
  <?php endif; ?>

  <p class="pie">Después de vincular, vuelve a tu Estado de cuenta y pulsa otra vez «Disponibilidad del equipo». No recargues <code>entrar.php</code>: cada pase sirve una sola vez.</p>
</main>
</body>
</html>
