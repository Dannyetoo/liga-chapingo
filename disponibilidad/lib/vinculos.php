<?php
/**
 * Liga Chapingo — Vínculo EXPLÍCITO Estado de cuenta (estado.json) ↔ equipos.id (MySQL).
 *
 * El Estado de cuenta identifica al equipo por su id en arbitrajes/datos/estado.json.
 * Disponibilidad usa equipos.id. La relación vive en la tabla equipos_estado_cuenta,
 * la crea una persona de administración y NUNCA se deduce comparando nombres.
 */
declare(strict_types=1);

/** Ruta de /capitanes/ en el servidor (hermana de /disponibilidad/). */
if (!defined('CAPITANES_DIR')) {
    define('CAPITANES_DIR', dirname(__DIR__, 2) . '/capitanes');
}

/** Carga (una vez, sólo lectura) la configuración y las funciones del portal de capitanes. */
function cargar_portal_capitanes(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $cfg = CAPITANES_DIR . '/config.php';
    $lib = CAPITANES_DIR . '/codigos-lib.php';
    if (!is_file($cfg) || !is_file($lib)) {
        error_log('[disponibilidad] no se encontró /capitanes/ en ' . CAPITANES_DIR);
        return $ok = false;
    }
    require_once $cfg;
    require_once $lib;
    return $ok = function_exists('verificaPaseDisponibilidad') && function_exists('leerEstadoArbitrajes');
}

function vinculos_instalados(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM equipos_estado_cuenta LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

/** equipos.id vinculado a un id del Estado de cuenta (o null). */
function equipo_por_id_estado_cuenta(string $idEstado): ?int
{
    if ($idEstado === '' || !vinculos_instalados()) return null;
    $st = db()->prepare('SELECT equipo_id FROM equipos_estado_cuenta WHERE estado_equipo_id = ?');
    $st->execute([$idEstado]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}

/** [estado_equipo_id => fila con equipo_id y nombre del equipo en MySQL] */
function vinculos_por_id_estado(): array
{
    if (!vinculos_instalados()) return [];
    $st = db()->query(
        'SELECT v.id, v.equipo_id, v.estado_equipo_id, v.estado_nombre, v.creado_en, e.nombre AS equipo_nombre, e.activo
           FROM equipos_estado_cuenta v JOIN equipos e ON e.id = v.equipo_id'
    );
    $out = [];
    foreach ($st->fetchAll() as $f) $out[(string)$f['estado_equipo_id']] = $f;
    return $out;
}

/**
 * Equipos del Estado de cuenta con su ruta legible (edición · disciplina · rama · categoría).
 * Devuelve null si no se pudo leer estado.json.
 */
function equipos_estado_cuenta(): ?array
{
    if (!cargar_portal_capitanes()) return null;
    $d = leerEstadoArbitrajes();
    if ($d === null) return null;
    $S = $d['estado'];
    $nombre = function ($lista, $id): string {
        foreach (is_array($lista) ? $lista : [] as $x) {
            if (is_array($x) && (string)($x['id'] ?? '') === (string)$id && $id !== '') return (string)($x['nombre'] ?? '');
        }
        return '';
    };
    $out = [];
    foreach (equiposDelEstado($S) as $e) {
        if (!is_array($e) || !isset($e['id']) || (string)$e['id'] === '') continue;
        $out[] = [
            'id'        => (string)$e['id'],
            'nombre'    => (string)($e['nombre'] ?? ''),
            'encargado' => (string)($e['encargado'] ?? ''),
            'estado'    => (string)($e['estado'] ?? ''),
            'ruta'      => implode(' · ', array_filter([
                $nombre($S['ediciones'] ?? [], (string)($e['edicionId'] ?? '')),
                $nombre($S['disciplinas'] ?? [], (string)($e['disciplinaId'] ?? '')),
                $nombre($S['ramas'] ?? [], (string)($e['ramaId'] ?? '')),
                $nombre($S['categorias'] ?? [], (string)($e['categoriaId'] ?? '')),
            ])),
        ];
    }
    usort($out, fn($a, $b) => [$a['ruta'], $a['nombre']] <=> [$b['ruta'], $b['nombre']]);
    return $out;
}

/** Vincula un id del Estado de cuenta con un equipos.id existente. Falla si ya estaba vinculado. */
function crear_vinculo(string $idEstado, int $equipoId, string $nombreEstado): void
{
    $st = db()->prepare('INSERT INTO equipos_estado_cuenta (equipo_id, estado_equipo_id, estado_nombre) VALUES (?, ?, ?)');
    $st->execute([$equipoId, $idEstado, mb_substr($nombreEstado, 0, 150)]);
}

/** Crea el equipo en MySQL (con el nombre del Estado de cuenta) y lo vincula, en una transacción. */
function crear_equipo_y_vincular(string $idEstado, string $nombre, string $encargado): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO equipos (nombre, capitan_nombre, activo) VALUES (?, ?, 1)');
        $st->execute([mb_substr($nombre, 0, 100), $encargado !== '' ? mb_substr($encargado, 0, 100) : null]);
        $id = (int)$pdo->lastInsertId();
        crear_vinculo($idEstado, $id, $nombre);
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Quita sólo el vínculo (no borra el equipo ni su disponibilidad). */
function quitar_vinculo(string $idEstado): bool
{
    $st = db()->prepare('DELETE FROM equipos_estado_cuenta WHERE estado_equipo_id = ?');
    $st->execute([$idEstado]);
    return $st->rowCount() === 1;
}
