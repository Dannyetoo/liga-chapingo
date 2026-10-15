<?php
/**
 * Liga Chapingo — Canchas (catálogo).
 *
 * La disponibilidad semanal y las excepciones de canchas NO tienen código propio:
 * usan las mismas funciones que los equipos con $entidad = 'cancha'
 * (ver ENTIDADES en funciones.php y lib/disponibilidad.php).
 * Ningún nombre de cancha está escrito en el código: todo sale de la tabla `canchas`.
 */

declare(strict_types=1);

/** ¿Ya se ejecutó sql/fase4-canchas.sql? Evita que el sistema se rompa si aún no. */
function canchas_instaladas(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM canchas LIMIT 1');
        db()->query('SELECT 1 FROM disponibilidad_cancha LIMIT 1');
        db()->query('SELECT 1 FROM excepciones_cancha LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

/** Canchas ordenadas por nombre. $soloActivas = true para selectores de programación. */
function listar_canchas(bool $soloActivas = false): array
{
    if (!canchas_instaladas()) return [];
    $sql = 'SELECT id, nombre, ubicacion, activo FROM canchas'
         . ($soloActivas ? ' WHERE activo = 1' : '')
         . ' ORDER BY activo DESC, nombre';
    return db()->query($sql)->fetchAll();
}

/** Cancha por id en cualquier estado. */
function obtener_cancha(int $id): ?array
{
    if (!canchas_instaladas()) return null;
    return obtener_registro('cancha', $id);
}

/** Valida los datos de una cancha nueva. Devuelve [datos, errores]. */
function validar_cancha(array $in): array
{
    $nombre = trim(preg_replace('/\s+/u', ' ', (string)($in['nombre'] ?? '')));
    $ubic   = trim((string)($in['ubicacion'] ?? ''));
    $err = [];
    if ($nombre === '') {
        $err[] = 'Escribe el nombre de la cancha.';
    } elseif (mb_strlen($nombre) > 100) {
        $err[] = 'El nombre no puede exceder 100 caracteres.';
    } else {
        $st = db()->prepare('SELECT id FROM canchas WHERE LOWER(nombre) = LOWER(?)');
        $st->execute([$nombre]);
        if ($st->fetch()) $err[] = 'Ya existe una cancha llamada «' . $nombre . '».';
    }
    if (mb_strlen($ubic) > 150) {
        $err[] = 'La ubicación no puede exceder 150 caracteres.';
    }
    return [['nombre' => $nombre, 'ubicacion' => $ubic !== '' ? $ubic : null], $err];
}

function crear_cancha(array $d): int
{
    $st = db()->prepare('INSERT INTO canchas (nombre, ubicacion, activo) VALUES (?, ?, 1)');
    $st->execute([$d['nombre'], $d['ubicacion']]);
    return (int)db()->lastInsertId();
}

/** Activa (1) o desactiva (0). Una cancha inactiva no aparece para programar partidos. */
function cambiar_estado_cancha(int $id, bool $activa): bool
{
    $st = db()->prepare('UPDATE canchas SET activo = ? WHERE id = ?');
    $st->execute([$activa ? 1 : 0, $id]);
    return $st->rowCount() === 1;
}
