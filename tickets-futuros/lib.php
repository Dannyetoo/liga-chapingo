<?php
/* =====================================================================
   Liga Premier Chapingo — Tickets futuros · funciones compartidas

   Un «ticket futuro» es un cargo PROGRAMADO (p. ej. el arbitraje de la
   jornada 7) que todavía no existe en el Estado de cuenta. Sirve para:
     · que el capitán vea lo que viene, si la mesa directiva lo hace visible;
     · que la administración estime los pagos por cobrar.
   No es un movimiento: no toca estado.json ni cambia saldos.

   Lo usan dos lugares:
     · capitanes/api.php  → sólo tf_futuros_visibles() (lectura, equipo de la sesión);
     · tickets-futuros/admin/index.php → todo lo demás (sólo administración).
   Todas las funciones llevan el prefijo tf_ para no chocar con otras.
   ===================================================================== */

declare(strict_types=1);

const TF_TABLA = 'tickets_futuros';

/** Conexión PDO reutilizando el db.php existente (no guarda credenciales propias). */
function tf_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    foreach ([__DIR__ . '/db.php', dirname(__DIR__) . '/disponibilidad/db.php'] as $archivo) {
        if (!is_file($archivo)) continue;
        foreach ($GLOBALS as $v) {                       /* ya cargado por otra página */
            if ($v instanceof PDO) { $pdo = $v; break 2; }
        }
        $pdo = (static function (string $f): ?PDO {
            include $f;
            foreach (get_defined_vars() as $v) {
                if ($v instanceof PDO) return $v;
            }
            foreach (['db', 'pdo', 'obtener_pdo', 'obtenerPDO', 'obtener_conexion', 'conexion', 'conectar', 'get_pdo', 'getPDO', 'getConnection'] as $fn) {
                if (function_exists($fn)) {
                    try { $r = $fn(); if ($r instanceof PDO) return $r; } catch (Throwable $e) { }
                }
            }
            return null;
        })($archivo);
        if ($pdo instanceof PDO) break;
    }
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('No se encontró la conexión a MySQL (db.php).');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function tf_tabla_existe(PDO $pdo): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE '" . TF_TABLA . "'")->fetchColumn();
}

/** CREATE TABLE IF NOT EXISTS (no destructivo). Mismo SQL que sql/tickets-futuros.sql */
function tf_crear_tabla(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TF_TABLA . "` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        equipo_estado_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        equipo_nombre VARCHAR(150) NOT NULL,
        jornada SMALLINT UNSIGNED NULL,
        fecha_estimada DATE NULL,
        concepto VARCHAR(150) NOT NULL,
        importe DECIMAL(10,2) NOT NULL,
        nota VARCHAR(255) NULL,
        visible_capitan TINYINT(1) NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_tf_equipo (equipo_estado_id, visible_capitan, jornada),
        KEY idx_tf_jornada (jornada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Tickets futuros VISIBLES de un equipo (para el portal de capitanes).
 * El id viene de la sesión del portal, nunca del navegador.
 * Sólo los que siguen por cobrar (futuro / por cobrar); cobrados y cancelados no se muestran.
 */
function tf_futuros_visibles(string $equipoEstadoId): array {
    if ($equipoEstadoId === '') return [];
    $pdo = tf_pdo();
    if (!tf_tabla_existe($pdo)) return [];
    /* Con la fase 4 de Finanzas cada ticket tiene rival y estado; sin ella, se lee como antes. */
    $fase4 = (bool)$pdo->query("SHOW COLUMNS FROM `" . TF_TABLA . "` LIKE 'rival_nombre'")->fetchColumn();
    $st = $pdo->prepare('SELECT id, jornada, fecha_estimada, concepto, importe, nota'
                        . ($fase4 ? ', rival_nombre, estado' : '') . '
                           FROM `' . TF_TABLA . '`
                          WHERE equipo_estado_id = ? AND visible_capitan = 1'
                        . ($fase4 ? " AND estado IN ('futuro','por_cobrar')" : '') . '
                          ORDER BY jornada IS NULL, jornada, fecha_estimada IS NULL, fecha_estimada, id');
    $st->execute([$equipoEstadoId]);
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $rival = $fase4 ? trim((string)($f['rival_nombre'] ?? '')) : '';
        $out[] = [
            'id'       => (int)$f['id'],
            'jornada'  => $f['jornada'] === null ? 0 : (int)$f['jornada'],
            'fecha'    => (string)($f['fecha_estimada'] ?? ''),
            'concepto' => (string)$f['concepto'] . ($rival !== '' ? ' vs ' . $rival : ''),
            'importe'  => (float)$f['importe'],
            'nota'     => (string)($f['nota'] ?? ''),
            'rival'    => $rival,
            'estado'   => $fase4 ? (string)$f['estado'] : 'futuro',
        ];
    }
    return $out;
}

/** Carga (una vez) la configuración y las funciones de lectura del portal de capitanes. */
function tf_cargar_capitanes(): bool {
    $dir = dirname(__DIR__) . '/capitanes';
    if (!is_file($dir . '/config.php') || !is_file($dir . '/codigos-lib.php')) return false;
    if (!defined('DATOS_ARBITRAJES')) require_once $dir . '/config.php';
    if (!function_exists('leerEstadoArbitrajes')) require_once $dir . '/codigos-lib.php';
    return defined('DATOS_ARBITRAJES') && function_exists('leerEstadoArbitrajes');
}

function tf_num($x): float { return is_numeric($x) ? (float)$x : 0.0; }
function tf_txt($x): string { return is_scalar($x) ? (string)$x : ''; }

function tf_nombre_de($lista, string $id): string {
    if (!is_array($lista) || $id === '') return '';
    foreach ($lista as $x) {
        if (is_array($x) && (string)($x['id'] ?? '') === $id) return tf_txt($x['nombre'] ?? '');
    }
    return '';
}

/**
 * Equipos del Estado de cuenta con su saldo actual y multas pendientes.
 * Devuelve [equipos(id => datos), ediciones(id => nombre)] o [null, []] si no se puede leer.
 */
function tf_equipos_con_saldo(): array {
    if (!tf_cargar_capitanes()) return [null, []];
    $d = leerEstadoArbitrajes();
    if ($d === null) return [null, []];
    $S = $d['estado'];

    $equipos = [];
    foreach ((is_array($S['equipos'] ?? null) ? $S['equipos'] : []) as $e) {
        if (!is_array($e) || tf_txt($e['id'] ?? '') === '') continue;
        $id = tf_txt($e['id']);
        $equipos[$id] = [
            'id'         => $id,
            'nombre'     => tf_txt($e['nombre'] ?? $id),
            'edicionId'  => tf_txt($e['edicionId'] ?? ''),
            'edicion'    => tf_nombre_de($S['ediciones'] ?? [], tf_txt($e['edicionId'] ?? '')),
            'categoria'  => trim(implode(' · ', array_filter([
                tf_nombre_de($S['disciplinas'] ?? [], tf_txt($e['disciplinaId'] ?? '')),
                tf_nombre_de($S['ramas'] ?? [], tf_txt($e['ramaId'] ?? '')),
                tf_nombre_de($S['categorias'] ?? [], tf_txt($e['categoriaId'] ?? '')),
            ]))),
            'estado'     => tf_txt($e['estado'] ?? ''),
            'saldo'      => 0.0,
            'multas'     => 0.0,
        ];
    }
    foreach ((is_array($S['movs'] ?? null) ? $S['movs'] : []) as $m) {
        $id = is_array($m) ? tf_txt($m['equipoId'] ?? '') : '';
        if (!isset($equipos[$id])) continue;
        $equipos[$id]['saldo'] += tf_num($m['importe'] ?? 0) - tf_num($m['recibido'] ?? 0);
    }
    foreach ((is_array($S['multas'] ?? null) ? $S['multas'] : []) as $mu) {
        $id = is_array($mu) ? tf_txt($mu['equipoId'] ?? '') : '';
        if (!isset($equipos[$id])) continue;
        $r = tf_num($mu['monto'] ?? 0) - tf_num($mu['pagado'] ?? 0);
        if ($r > 0.009) $equipos[$id]['multas'] += $r;
    }
    uasort($equipos, function ($a, $b) { return strcasecmp($a['nombre'], $b['nombre']); });

    $ediciones = [];
    foreach ((is_array($S['ediciones'] ?? null) ? $S['ediciones'] : []) as $x) {
        if (is_array($x) && tf_txt($x['id'] ?? '') !== '') $ediciones[tf_txt($x['id'])] = tf_txt($x['nombre'] ?? $x['id']);
    }
    return [$equipos, $ediciones];
}
