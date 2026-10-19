<?php
/**
 * Liga Chapingo — CHAT ENTRE CAPITANES (30-sep-2026).
 *
 *  · Sólo existe para un partido CONFIRMADO (reservas_cancha.estado = 'confirmada') y sólo lo
 *    ven los dos equipos de ese partido. Se abre al confirmarse y se cierra CHAT_DIAS_DESPUES
 *    días después del partido (queda de sólo lectura).
 *  · Los mensajes se guardan: la liga puede revisarlos si hay un reporte.
 *  · Aviso al celular del otro equipo como máximo uno cada 15 minutos por partido.
 *  · Las tablas se crean solas la primera vez (no hay que instalar nada).
 */
declare(strict_types=1);

const CHAT_MAX_TEXTO      = 500;
const CHAT_DIAS_DESPUES   = 2;
const CHAT_LIMITE_5MIN    = 20;       // mensajes por equipo y partido en 5 minutos

function chat_instalar(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $pdo = db();
    try {
        $pdo->query('SELECT 1 FROM rival_chat LIMIT 1');
        $pdo->query('SELECT 1 FROM rival_chat_visto LIMIT 1');
        return $ok = true;
    } catch (Throwable $ex) { /* se crean abajo */ }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rival_chat (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reserva_id    INT UNSIGNED NOT NULL,
            equipo_id     INT NOT NULL,
            equipo_nombre VARCHAR(150) NOT NULL,
            texto         VARCHAR(500) NOT NULL,
            creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_chat_reserva (reserva_id, id),
            KEY idx_chat_equipo (equipo_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rival_chat_visto (
            reserva_id INT UNSIGNED NOT NULL,
            equipo_id  INT NOT NULL,
            ultimo_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (reserva_id, equipo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (Throwable $ex) {
        error_log('[chat] no se pudieron crear las tablas: ' . $ex->getMessage());
        return $ok = false;
    }
}

/**
 * Partido confirmado de este equipo, con 'rival_id', 'rival' y 'abierto' (se puede escribir).
 * null si no existe, no es suyo o no está confirmado.
 */
function chat_partido(int $equipoId, int $reservaId): ?array
{
    $st = db()->prepare("SELECT id, fecha, hora, estado, equipo_a_id, equipo_b_id, equipo_a_nombre, equipo_b_nombre
                           FROM reservas_cancha WHERE id = ? AND estado = 'confirmada' AND (equipo_a_id = ? OR equipo_b_id = ?)");
    $st->execute([$reservaId, $equipoId, $equipoId]);
    $r = $st->fetch();
    if (!$r) return null;
    $soyA = (int)$r['equipo_a_id'] === $equipoId;
    $r['rival_id'] = $soyA ? (int)$r['equipo_b_id'] : (int)$r['equipo_a_id'];
    $r['rival']    = $soyA ? (string)$r['equipo_b_nombre'] : (string)$r['equipo_a_nombre'];
    $r['yo']       = $soyA ? (string)$r['equipo_a_nombre'] : (string)$r['equipo_b_nombre'];
    $cierre = (new DateTimeImmutable((string)$r['fecha'], new DateTimeZone(RIVAL_TZ)))->modify('+' . (CHAT_DIAS_DESPUES + 1) . ' days');
    $r['abierto'] = rival_ahora() < $cierre;
    return $r;
}

/** Mensajes con id > $desde (máx. 200, en orden). */
function chat_mensajes(int $reservaId, int $desde = 0): array
{
    if (!chat_instalar()) return [];
    $st = db()->prepare('SELECT id, equipo_id, equipo_nombre, texto, creado_en FROM rival_chat WHERE reserva_id = ? AND id > ? ORDER BY id LIMIT 200');
    $st->execute([$reservaId, $desde]);
    return $st->fetchAll();
}

function chat_marcar_visto(int $reservaId, int $equipoId, int $ultimo): void
{
    if ($ultimo <= 0 || !chat_instalar()) return;
    db()->prepare('INSERT INTO rival_chat_visto (reserva_id, equipo_id, ultimo_id) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(ultimo_id, VALUES(ultimo_id))')->execute([$reservaId, $equipoId, $ultimo]);
}

/** [reserva_id => mensajes sin leer del rival] para los partidos de este equipo. */
function chat_no_leidos(int $equipoId, array $reservaIds): array
{
    $reservaIds = array_values(array_filter(array_map('intval', $reservaIds)));
    if (!$reservaIds || !chat_instalar()) return [];
    $in = implode(',', array_fill(0, count($reservaIds), '?'));
    $st = db()->prepare("SELECT c.reserva_id, COUNT(*) n FROM rival_chat c
                          LEFT JOIN rival_chat_visto v ON v.reserva_id = c.reserva_id AND v.equipo_id = ?
                         WHERE c.reserva_id IN ($in) AND c.equipo_id <> ? AND c.id > COALESCE(v.ultimo_id, 0)
                         GROUP BY c.reserva_id");
    $st->execute(array_merge([$equipoId], $reservaIds, [$equipoId]));
    $o = [];
    foreach ($st->fetchAll() as $f) $o[(int)$f['reserva_id']] = (int)$f['n'];
    return $o;
}

/** Envía un mensaje. Lanza RivalError con un texto para el capitán si no se puede. */
function chat_enviar(array $eq, int $reservaId, string $texto): array
{
    if (!chat_instalar()) throw new RivalError('El chat no está disponible por ahora.');
    $p = chat_partido((int)$eq['equipo_id'], $reservaId);
    if (!$p) throw new RivalError('Este partido ya no está confirmado.');
    if (!$p['abierto']) throw new RivalError('El chat de este partido ya se cerró.');
    $texto = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto));
    $texto = (string)preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto));
    if ($texto === '') throw new RivalError('Escribe un mensaje.');
    if (mb_strlen($texto) > CHAT_MAX_TEXTO) throw new RivalError('El mensaje es muy largo (máximo ' . CHAT_MAX_TEXTO . ' caracteres).');
    $st = db()->prepare('SELECT COUNT(*) FROM rival_chat WHERE reserva_id = ? AND equipo_id = ? AND creado_en >= NOW() - INTERVAL 5 MINUTE');
    $st->execute([$reservaId, (int)$eq['equipo_id']]);
    if ((int)$st->fetchColumn() >= CHAT_LIMITE_5MIN) throw new RivalError('Vas muy rápido. Espera un momento.');

    db()->prepare('INSERT INTO rival_chat (reserva_id, equipo_id, equipo_nombre, texto) VALUES (?, ?, ?, ?)')
        ->execute([$reservaId, (int)$eq['equipo_id'], mb_substr((string)$eq['nombre'], 0, 150), $texto]);
    $id = (int)db()->lastInsertId();
    chat_marcar_visto($reservaId, (int)$eq['equipo_id'], $id);
    // Aviso al otro equipo: uno cada 15 minutos por partido como máximo (llave única).
    push_encolar($p['rival_id'], 'chat', 'Mensaje de ' . $eq['nombre'], mb_substr($texto, 0, 120),
                 '/disponibilidad/rival-chat.php?r=' . $reservaId, 'chat:' . $reservaId . ':' . $p['rival_id'] . ':' . intdiv(time(), 900));
    return ['id' => $id];
}
