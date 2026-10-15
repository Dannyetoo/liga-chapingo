<?php
/**
 * Liga Chapingo — «BUSCA UN RIVAL».
 *
 * Flujo: Equipo A publica 3 opciones (fecha + hora + cancha DICIFO, dentro de 14 días) →
 * los equipos de su MISMO GRUPO las ven → Equipo B solicita una → A confirma →
 * el slot queda reservado para toda la liga. Las otras opciones de A SIGUEN publicadas:
 * A puede confirmar hasta 3 partidos (uno por opción, con rivales distintos).
 * Cada equipo puede tener hasta 3 solicitudes pendientes (una por rival).
 * Un partido confirmado se puede reprogramar: cualquiera de los dos equipos propone un
 * horario libre y el otro lo acepta o lo rechaza (sql/rival-fase7.sql).
 *
 * Reglas en la base de datos (ver sql/rival-fase6.sql y rival-fase7.sql): una publicación
 * activa por equipo, una solicitud pendiente por equipo y publicación, una propuesta de
 * reprogramación pendiente por partido y UNA reserva por slot (UNIQUE). Confirmar y aceptar
 * una reprogramación corren en transacción con la cancha bloqueada; si dos operaciones piden
 * el mismo horario a la vez, el índice único deja pasar sólo una.
 *
 * Grupo = edición · disciplina · rama · categoría del equipo en Tesorería (estado.json).
 * Preparado para: notificaciones (rival_eventos), vencimiento (rival_solicitudes.vence_en),
 * cancelaciones y registro en la jornada (reservas_cancha.partido_id).
 */
declare(strict_types=1);

require_once __DIR__ . '/finanzas-fuente.php';
require_once __DIR__ . '/finanzas-calculos.php';
require_once __DIR__ . '/push.php';

const RIVAL_OPCIONES  = 3;          // (compatibilidad) mínimo de horarios; ver RIVAL_MIN_HORARIOS
const RIVAL_MIN_HORARIOS = 3;       // actualización 2026-09-29: cada equipo mantiene SIEMPRE al menos 3 horarios
const RIVAL_MAX_POR_DIA  = 5;       // …y puede elegir hasta 5 horarios por día
const RIVAL_REPROG_MAX   = 5;       // una reprogramación propone de 1 a 5 horarios
const RIVAL_MAX_SOLICITUDES = 3;    // solicitudes pendientes que un equipo puede tener a la vez
const RIVAL_REPROG_HORAS = 24;      // una reprogramación se pide con al menos 24 h de anticipación al partido
const RIVAL_COPAFACIL = 'https://copafacil.com/premierleaguechapingo';
const RIVAL_DIAS      = 14;
const RIVAL_TZ        = 'America/Mexico_City';
const RIVAL_CANCHA    = 'DICIFO';
/** Duración de cada partido: de un bloque de disponibilidad salen partidos cada 60 minutos. */
const RIVAL_DURACION_MIN = 60;
/**
 * Horarios de RESPALDO: sólo se usan si la cancha DICIFO no tiene disponibilidad semanal
 * capturada para el semestre activo (Administración → Canchas → DICIFO).
 */
const RIVAL_HORARIOS = [
    'fin_semana'   => ['10:00', '11:00', '12:00', '13:00', '14:00'],
    'entre_semana' => ['16:00', '17:00', '18:00', '21:30', '22:30'],
];
const RIVAL_DIAS_SEMANA = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
const RIVAL_MESES = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

class RivalError extends RuntimeException {}

/* ------------------------------------------------------------------ */
/* Utilidades                                                          */
/* ------------------------------------------------------------------ */

function rival_ahora(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(RIVAL_TZ));
}

function rival_instalado(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        foreach (['rival_publicaciones', 'rival_opciones', 'rival_solicitudes', 'reservas_cancha', 'rival_eventos', 'rival_reprogramaciones'] as $t) {
            db()->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        }
        db()->query('SELECT pendiente_par FROM rival_solicitudes LIMIT 1');   // actualización fase 7
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

function rival_cancha_id(): ?int
{
    static $id = false;
    if ($id !== false) return $id;
    $st = db()->prepare('SELECT id FROM canchas WHERE UPPER(nombre) = ? ORDER BY id LIMIT 1');
    $st->execute([RIVAL_CANCHA]);
    $v = $st->fetchColumn();
    return $id = $v === false ? null : (int)$v;
}

/** Minutos desde medianoche de «HH:MM[:SS]». */
function rival_min(string $h): int
{
    [$a, $b] = array_map('intval', array_pad(explode(':', substr($h, 0, 5)), 2, '0'));
    return $a * 60 + $b;
}

/** Inicios de partido que caben en un bloque [ini, fin) cada RIVAL_DURACION_MIN minutos. */
function rival_inicios_bloque(string $ini, string $fin): array
{
    $out = [];
    $f = rival_min($fin) ?: 24 * 60;          // «00:00» como fin = medianoche
    for ($m = rival_min($ini); $m + RIVAL_DURACION_MIN <= $f; $m += RIVAL_DURACION_MIN) {
        $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $out;
}

/**
 * Horario de la cancha tal como está capturado en Administración → Canchas:
 * disponibilidad semanal del semestre activo + excepciones de «disponibilidad extra».
 * (Las excepciones «no disponible» se aplican en rival_ocupados()).
 * @return array{fuente:string, semana:array<int,array<int,array{0:string,1:string}>>, extra:array<string,array<int,array{0:string,1:string}>>}
 */
function rival_horario_cancha(int $cancha): array
{
    static $cache = [];
    if (isset($cache[$cancha])) return $cache[$cancha];
    $h = ['fuente' => 'fijo', 'semana' => [], 'extra' => []];
    try {
        $sem = obtener_semestre_activo();
        if ($sem) {
            $st = db()->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM disponibilidad_cancha
                                  WHERE cancha_id = ? AND semestre_id = ? AND activo = 1 ORDER BY dia_semana, hora_inicio');
            $st->execute([$cancha, (int)$sem['id']]);
            foreach ($st->fetchAll() as $r) {
                $h['semana'][(int)$r['dia_semana']][] = [substr($r['hora_inicio'], 0, 5), substr($r['hora_fin'], 0, 5)];
            }
            if ($h['semana']) $h['fuente'] = 'cancha';
            $st = db()->prepare("SELECT fecha, hora_inicio, hora_fin FROM excepciones_cancha
                                  WHERE cancha_id = ? AND semestre_id = ? AND tipo = 'disponibilidad_extra' AND hora_inicio IS NOT NULL AND hora_fin IS NOT NULL");
            $st->execute([$cancha, (int)$sem['id']]);
            foreach ($st->fetchAll() as $r) {
                $h['extra'][(string)$r['fecha']][] = [substr($r['hora_inicio'], 0, 5), substr($r['hora_fin'], 0, 5)];
            }
        }
    } catch (Throwable $ex) {
        // Sin tablas de canchas: se usan los horarios de respaldo.
    }
    return $cache[$cancha] = $h;
}

/** Horas de inicio de partido permitidas en la cancha para una fecha. */
function rival_horas_de(DateTimeInterface $d, ?int $cancha = null): array
{
    $cancha = $cancha ?? (int)rival_cancha_id();
    $h = rival_horario_cancha($cancha);
    $dia = (int)$d->format('N');
    $f = $d->format('Y-m-d');
    if ($h['fuente'] === 'cancha') {
        $horas = [];
        foreach ($h['semana'][$dia] ?? [] as [$a, $b]) $horas = array_merge($horas, rival_inicios_bloque($a, $b));
    } else {
        $horas = $dia >= 6 ? RIVAL_HORARIOS['fin_semana'] : RIVAL_HORARIOS['entre_semana'];
    }
    foreach ($h['extra'][$f] ?? [] as [$a, $b]) $horas = array_merge($horas, rival_inicios_bloque($a, $b));
    $horas = array_values(array_unique($horas));
    sort($horas);
    return $horas;
}

function rival_slot_llave(int $cancha, string $fecha, string $hora): string
{
    return $cancha . ':' . $fecha . ':' . substr($hora, 0, 5);
}

/** «11:00 a.m.» */
function rival_hora_texto(string $hora): string
{
    [$h, $m] = array_map('intval', explode(':', substr($hora, 0, 5)));
    return ($h % 12 ?: 12) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ($h < 12 ? ' a.m.' : ' p.m.');
}

/** «Sáb 3 oct» */
function rival_dia_texto(string $fecha): string
{
    return explode(' · ', rival_slot_texto($fecha, '12:00'))[0];
}

/** «sáb 3 oct · 11:00 a.m.» */
function rival_slot_texto(string $fecha, string $hora): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $fecha . ' ' . substr($hora, 0, 5), new DateTimeZone(RIVAL_TZ));
    if (!$d) return $fecha . ' ' . $hora;
    $dia = mb_substr(RIVAL_DIAS_SEMANA[(int)$d->format('N')], 0, 3);
    $dia = mb_strtoupper(mb_substr($dia, 0, 1)) . mb_substr($dia, 1);
    $h = (int)$d->format('G'); $m = $d->format('i');
    $h12 = $h % 12 ?: 12;
    return $dia . ' ' . (int)$d->format('j') . ' ' . RIVAL_MESES[(int)$d->format('n')] . ' · ' . $h12 . ':' . $m . ($h < 12 ? ' a.m.' : ' p.m.');
}

/**
 * Ejecuta una operación en transacción y la reintenta si MySQL detecta un bloqueo cruzado
 * (deadlock / espera agotada): la segunda vuelta ve el resultado de la otra operación.
 */
function rival_reintentar(callable $fn)
{
    for ($intento = 1; ; $intento++) {
        try {
            return $fn();
        } catch (PDOException $ex) {
            $cod = (int)($ex->errorInfo[1] ?? 0);
            if (!in_array($cod, [1213, 1205], true) || $intento >= 3) throw $ex;
            usleep(random_int(50000, 150000));
        }
    }
}

/** ¿Hay un partido confirmado próximo entre estos dos equipos? */
function rival_hay_partido_entre(int $a, int $b): bool
{
    $st = db()->prepare("SELECT 1 FROM reservas_cancha WHERE estado = 'confirmada' AND fecha >= ?
                           AND ((equipo_a_id = ? AND equipo_b_id = ?) OR (equipo_a_id = ? AND equipo_b_id = ?)) LIMIT 1");
    $st->execute([rival_ahora()->format('Y-m-d'), $a, $b, $b, $a]);
    return (bool)$st->fetchColumn();
}

function rival_evento(string $tipo, ?string $grupo, ?int $equipo, ?int $otro, ?int $pub, ?int $sol, ?string $detalle = null): void
{
    db()->prepare('INSERT INTO rival_eventos (tipo, grupo_ref, equipo_id, otro_equipo_id, publicacion_id, solicitud_id, detalle) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tipo, $grupo, $equipo, $otro, $pub, $sol, $detalle !== null ? mb_substr($detalle, 0, 255) : null]);
}

/* ------------------------------------------------------------------ */
/* Equipo y grupo                                                      */
/* ------------------------------------------------------------------ */

/**
 * Grupos A/B de Primera y Segunda (27-sep-2026). Misma regla que Tesorería (arbitrajes/index.html):
 * se usa equipo.grupo si Tesorería ya lo guardó; si no, en la Tercera Edición se calcula por nombre
 * (lista del Grupo A; el resto de la categoría es Grupo B).
 */
const RIVAL_GRUPOS_A = [
    'primera' => ['blackkaiser', 'raniza', 'lomecan', 'chapitudos', 'corinthians', 'ranfers', 'teamsix', 'barcaguama', 'secierradobleteo', 'cuervos'],
    'segunda' => ['realsam', 'pavosdelinfierno', 'masturbanda', 'verracos', 'armage', 'puercoaran', 'downchester', 'erome'],
];

function rival_clave_nombre(string $x): string
{
    $x = mb_strtolower($x, 'UTF-8');
    if (class_exists('Normalizer')) {
        $x = (string)Normalizer::normalize($x, Normalizer::FORM_D);
        $x = (string)preg_replace('/\p{Mn}+/u', '', $x);
    } else {
        $x = strtr($x, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
    }
    return (string)preg_replace('/[^a-z0-9]/', '', $x);
}

/** 'A', 'B' o '' (sin grupo) para un equipo de Tesorería. */
function rival_grupo_letra(array $S, array $e): string
{
    $nom = fn(string $lista, string $id) => (string)((fin_por_id($S[$lista] ?? [])[$id] ?? [])['nombre'] ?? '');
    $cat = rival_clave_nombre($nom('categorias', fin_txt($e['categoriaId'] ?? '')));
    if ($cat !== 'primera' && $cat !== 'segunda') return '';
    if (array_key_exists('grupo', $e) && $e['grupo'] !== null) {
        $g = strtoupper(trim((string)$e['grupo']));
        return in_array($g, ['A', 'B'], true) ? $g : '';
    }
    if (strpos(rival_clave_nombre($nom('ediciones', fin_txt($e['edicionId'] ?? ''))), 'tercera') === false) return '';
    $k = rival_clave_nombre((string)($e['nombre'] ?? ''));
    foreach (RIVAL_GRUPOS_A[$cat] as $pat) {
        if (strpos($k, $pat) !== false) return 'A';
    }
    return 'B';
}

/** Clave de grupo «edición|disciplina|rama|categoría[|A|B]» de un equipo de Tesorería (por su id). */
function rival_grupo_de_estado(string $estadoId): ?string
{
    $d = fin_leer_estado();
    if (!$d) return null;
    $S = $d['estado'];
    $e = fin_por_id($S['equipos'] ?? [])[$estadoId] ?? null;
    if (!$e) return null;
    $g = rival_grupo_letra($S, $e);
    return implode('|', [fin_txt($e['edicionId'] ?? ''), fin_txt($e['disciplinaId'] ?? ''), fin_txt($e['ramaId'] ?? ''), fin_txt($e['categoriaId'] ?? '')])
        . ($g !== '' ? '|' . $g : '');
}

/**
 * Datos del equipo del capitán para este módulo.
 * @return array{equipo_id:int, estado_id:string, nombre:string, edicion:string, grupo:string, grupo_nombre:string}|null
 */
function rival_contexto_equipo(int $equipoId, ?string $estadoId): ?array
{
    $d = fin_leer_estado();
    if (!$d) return null;
    $S = $d['estado'];
    $eq = fin_por_id($S['equipos'] ?? []);

    // Vínculos de este equipo de disponibilidad con equipos de Tesorería.
    $st = db()->prepare('SELECT estado_equipo_id FROM equipos_estado_cuenta WHERE equipo_id = ?');
    $st->execute([$equipoId]);
    $vinc = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($estadoId !== null && !in_array($estadoId, $vinc, true)) $estadoId = null;   // nunca confiar en un id no vinculado
    if ($estadoId === null) {
        // Sin id (p. ej. simulación): el vínculo de la edición más reciente con movimientos.
        $orden = array_flip(array_column(fin_ediciones($S), 'id'));
        usort($vinc, fn($a, $b) => ($orden[$eq[$a]['edicionId'] ?? ''] ?? 99) <=> ($orden[$eq[$b]['edicionId'] ?? ''] ?? 99));
        $estadoId = $vinc[0] ?? null;
    }
    $e = $estadoId !== null ? ($eq[$estadoId] ?? null) : null;
    if (!$e) return null;
    $ed = fin_txt($e['edicionId'] ?? ''); $di = fin_txt($e['disciplinaId'] ?? '');
    $ra = fin_txt($e['ramaId'] ?? '');    $ca = fin_txt($e['categoriaId'] ?? '');
    $nom = fn(string $lista, string $id) => fin_txt((fin_por_id($S[$lista] ?? [])[$id] ?? [])['nombre'] ?? '');
    $gr = rival_grupo_letra($S, $e);
    return [
        'equipo_id'    => $equipoId,
        'estado_id'    => $estadoId,
        'nombre'       => fin_txt($e['nombre'] ?? ''),
        'edicion'      => $ed,
        'grupo'        => implode('|', [$ed, $di, $ra, $ca]) . ($gr !== '' ? '|' . $gr : ''),
        'grupo_letra'  => $gr,
        'grupo_nombre' => trim($nom('categorias', $ca) . ($gr !== '' ? ' · Grupo ' . $gr : '') . ' · ' . $nom('disciplinas', $di) . ' ' . $nom('ramas', $ra) . ' · ' . $nom('ediciones', $ed)),
    ];
}

/* ------------------------------------------------------------------ */
/* Disponibilidad de la cancha                                         */
/* ------------------------------------------------------------------ */

/** Slots ocupados (reservas confirmadas + excepciones «no disponible» de la cancha) entre dos fechas. */
function rival_ocupados(int $cancha, string $desde, string $hasta): array
{
    $o = [];
    $st = db()->prepare("SELECT fecha, hora FROM reservas_cancha WHERE cancha_id = ? AND estado = 'confirmada' AND fecha BETWEEN ? AND ?");
    $st->execute([$cancha, $desde, $hasta]);
    foreach ($st->fetchAll() as $r) $o[$r['fecha'] . ' ' . substr($r['hora'], 0, 5)] = 'reservado';
    try {
        $st = db()->prepare("SELECT fecha, hora_inicio, hora_fin FROM excepciones_cancha WHERE cancha_id = ? AND tipo = 'no_disponible' AND fecha BETWEEN ? AND ?");
        $st->execute([$cancha, $desde, $hasta]);
        foreach ($st->fetchAll() as $x) {
            $d = new DateTimeImmutable($x['fecha'], new DateTimeZone(RIVAL_TZ));
            $ini = $x['hora_inicio'] ? rival_min($x['hora_inicio']) : 0;
            $fin = $x['hora_fin'] ? (rival_min($x['hora_fin']) ?: 24 * 60) : 24 * 60;
            foreach (rival_horas_de($d, $cancha) as $h) {
                // El partido [h, h+duración) choca con el cierre [ini, fin).
                $m = rival_min($h);
                if ($m < $fin && $m + RIVAL_DURACION_MIN > $ini) $o[$x['fecha'] . ' ' . $h] = $o[$x['fecha'] . ' ' . $h] ?? 'cerrado';
            }
        }
    } catch (Throwable $ex) {
        // Sin tabla de excepciones de cancha: sólo cuentan las reservas.
    }
    return $o;
}

/**
 * Calendario de los próximos 14 días con el estado de cada slot.
 * @return array<int, array{fecha:string, texto:string, slots: array<int, array{hora:string, clave:string, estado:string}>}>
 */
function rival_calendario(int $cancha): array
{
    $ahora = rival_ahora();
    $hoy = $ahora->setTime(0, 0);
    $fin = $hoy->modify('+' . RIVAL_DIAS . ' days');
    $ocup = rival_ocupados($cancha, $hoy->format('Y-m-d'), $fin->format('Y-m-d'));
    $out = [];
    for ($d = $hoy; $d <= $fin; $d = $d->modify('+1 day')) {
        $f = $d->format('Y-m-d');
        $dia = ['fecha' => $f, 'texto' => ucfirst(RIVAL_DIAS_SEMANA[(int)$d->format('N')]) . ' ' . (int)$d->format('j') . ' ' . RIVAL_MESES[(int)$d->format('n')],
                'finde' => (int)$d->format('N') >= 6, 'slots' => []];
        foreach (rival_horas_de($d, $cancha) as $h) {
            $inicio = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$f $h", new DateTimeZone(RIVAL_TZ));
            $estado = $inicio <= $ahora ? 'pasado' : ($ocup["$f $h"] ?? 'libre');
            $dia['slots'][] = ['hora' => $h, 'clave' => "$f $h", 'estado' => $estado];
        }
        $out[] = $dia;
    }
    return $out;
}

/**
 * Valida slots «AAAA-MM-DD HH:MM»: horario DICIFO, futuro, dentro de 14 días, libre, sin repetir.
 * @return array{0: array<int,array{fecha:string,hora:string}>, 1: string[]}
 */
function rival_validar_slots(array $claves, int $cancha, array $excluir = [], int $dias = RIVAL_DIAS): array
{
    $ahora = rival_ahora();
    $limite = $ahora->setTime(0, 0)->modify('+' . $dias . ' days');
    $ocup = rival_ocupados($cancha, $ahora->format('Y-m-d'), $limite->format('Y-m-d'));
    $ok = []; $err = []; $vistos = array_flip($excluir);
    foreach ($claves as $c) {
        $c = is_string($c) ? trim($c) : '';
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $c, $m)) { $err[] = 'Hay un horario con formato no válido.'; continue; }
        [$fecha, $hora] = [$m[1], $m[2]];
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $c, new DateTimeZone(RIVAL_TZ));
        $txt = rival_slot_texto($fecha, $hora);
        if (!$d || $d->format('Y-m-d H:i') !== $c) { $err[] = 'Hay una fecha que no existe.'; continue; }
        if (!in_array($hora, rival_horas_de($d, $cancha), true)) { $err[] = "$txt no está dentro del horario disponible de DICIFO."; continue; }
        if ($d <= $ahora) { $err[] = "$txt ya pasó."; continue; }
        if ($d->setTime(0, 0) > $limite) { $err[] = "$txt queda fuera de los próximos " . $dias . ' días.'; continue; }
        if (isset($ocup[$c])) { $err[] = $ocup[$c] === 'cerrado' ? "$txt: DICIFO no está disponible a esa hora." : "$txt ya está ocupado."; continue; }
        if (isset($vistos[$c])) { $err[] = "$txt está repetido."; continue; }
        $vistos[$c] = true;
        $ok[] = ['fecha' => $fecha, 'hora' => $hora . ':00'];
    }
    return [$ok, $err];
}

/* ------------------------------------------------------------------ */
/* Acciones del capitán (todas en transacción)                         */
/* ------------------------------------------------------------------ */

/** Revisa que ningún día pase de RIVAL_MAX_POR_DIA horarios (contando los que ya tiene el equipo). */
function rival_revisar_por_dia(array $slots, array $yaPorDia = []): void
{
    $n = $yaPorDia;
    foreach ($slots as $s) $n[$s['fecha']] = ($n[$s['fecha']] ?? 0) + 1;
    foreach ($n as $f => $c) {
        if ($c > RIVAL_MAX_POR_DIA) {
            throw new RivalError('Puedes tener hasta ' . RIVAL_MAX_POR_DIA . ' horarios por día: el ' . rival_dia_texto((string)$f) . ' llevarías ' . $c . '.');
        }
    }
}

/** Publica por primera vez: mínimo RIVAL_MIN_HORARIOS horarios, hasta RIVAL_MAX_POR_DIA por día. */
function rival_publicar(array $eq, array $claves): void
{
    $cancha = rival_cancha_id();
    if (!$cancha) throw new RivalError('La cancha DICIFO no está dada de alta. Avisa a la mesa directiva.');
    $claves = array_values(array_unique(array_filter($claves, 'is_string')));
    if (count($claves) < RIVAL_MIN_HORARIOS) throw new RivalError('Elige al menos ' . RIVAL_MIN_HORARIOS . ' horarios diferentes.');
    [$slots, $err] = rival_validar_slots($claves, $cancha);
    if ($err) throw new RivalError(implode(' ', $err));
    if (count($slots) < RIVAL_MIN_HORARIOS) throw new RivalError('Elige al menos ' . RIVAL_MIN_HORARIOS . ' horarios válidos.');
    rival_revisar_por_dia($slots);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO rival_publicaciones (equipo_id, estado_equipo_id, equipo_nombre, edicion_ref, grupo_ref, estado, activa_llave)
                       VALUES (?, ?, ?, ?, ?, \'activa\', ?)')
            ->execute([$eq['equipo_id'], $eq['estado_id'], mb_substr($eq['nombre'], 0, 150), $eq['edicion'], $eq['grupo'], $eq['equipo_id']]);
        $pub = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO rival_opciones (publicacion_id, cancha_id, fecha, hora) VALUES (?, ?, ?, ?)');
        foreach ($slots as $s) $ins->execute([$pub, $cancha, $s['fecha'], $s['hora']]);
        rival_evento('publicada', $eq['grupo'], $eq['equipo_id'], null, $pub, null);
        $pdo->commit();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($ex->errorInfo[1] ?? 0) === 1062) throw new RivalError('Tu equipo ya tiene opciones publicadas.');
        throw $ex;
    }
}

/** Publicación activa del equipo (bloqueada si $paraActualizar). */
function rival_mi_publicacion(int $equipoId, bool $paraActualizar = false): ?array
{
    $st = db()->prepare("SELECT * FROM rival_publicaciones WHERE activa_llave = ?" . ($paraActualizar ? ' FOR UPDATE' : ''));
    $st->execute([$equipoId]);
    return $st->fetch() ?: null;
}

/** Sustituye una opción que ya no sirve (ocupada o vencida) por otra dentro de los próximos 14 días. */
function rival_reemplazar(array $eq, int $opcionId, string $clave): void
{
    $cancha = rival_cancha_id();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pub = rival_mi_publicacion($eq['equipo_id'], true);
        if (!$pub) throw new RivalError('No tienes opciones publicadas.');
        $st = $pdo->prepare('SELECT * FROM rival_opciones WHERE id = ? AND publicacion_id = ? FOR UPDATE');
        $st->execute([$opcionId, $pub['id']]);
        $op = $st->fetch();
        if (!$op) throw new RivalError('Esa opción no es de tu equipo.');
        $ef = rival_estado_opcion($op);
        if (!in_array($ef, ['ocupada', 'vencida', 'fuera'], true)) throw new RivalError('Sólo se reemplazan las opciones ocupadas, vencidas o fuera del horario de la cancha.');
        $vivas = $pdo->prepare("SELECT fecha, hora FROM rival_opciones WHERE publicacion_id = ? AND estado = 'vigente' AND id <> ?");
        $vivas->execute([$pub['id'], $opcionId]);
        $ya = array_map(fn($r) => $r['fecha'] . ' ' . substr($r['hora'], 0, 5), $vivas->fetchAll());
        [$slots, $err] = rival_validar_slots([$clave], (int)$cancha, $ya);
        if ($err || !$slots) throw new RivalError($err ? implode(' ', $err) : 'Elige un horario válido.');
        $pdo->prepare("UPDATE rival_opciones SET estado = 'reemplazada' WHERE id = ?")->execute([$opcionId]);
        $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'La opción fue reemplazada'
                        WHERE opcion_id = ? AND estado = 'pendiente'")->execute([$opcionId]);
        $pdo->prepare('INSERT INTO rival_opciones (publicacion_id, cancha_id, fecha, hora) VALUES (?, ?, ?, ?)')
            ->execute([$pub['id'], $cancha, $slots[0]['fecha'], $slots[0]['hora']]);
        rival_evento('reemplazada', $eq['grupo'], $eq['equipo_id'], null, (int)$pub['id'], null, $slots[0]['fecha'] . ' ' . $slots[0]['hora']);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** Retira la publicación: sus opciones se liberan y sus solicitudes pendientes se cancelan. */
function rival_retirar(array $eq): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pub = rival_mi_publicacion($eq['equipo_id'], true);
        if (!$pub) throw new RivalError('No tienes opciones publicadas.');
        rival_cerrar_publicacion((int)$pub['id'], 'cancelada', 'Retirada por el equipo');
        rival_evento('retirada', $eq['grupo'], $eq['equipo_id'], null, (int)$pub['id'], null);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** (Dentro de una transacción) cierra una publicación y libera todo lo que colgaba de ella. */
function rival_cerrar_publicacion(int $pubId, string $estado, string $motivo): void
{
    $pdo = db();
    $pdo->prepare("UPDATE rival_publicaciones SET estado = ?, activa_llave = NULL, cerrada_en = NOW(), motivo = ? WHERE id = ? AND estado = 'activa'")
        ->execute([$estado, $motivo, $pubId]);
    $pdo->prepare("UPDATE rival_opciones SET estado = 'liberada' WHERE publicacion_id = ? AND estado IN ('vigente','ocupada')")->execute([$pubId]);
    $pdo->prepare("UPDATE rival_solicitudes SET estado = 'cancelada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = ?
                    WHERE publicacion_id = ? AND estado = 'pendiente'")->execute([$motivo, $pubId]);
}

/** Estado real de una opción: vencida si su horario ya pasó, ocupada si alguien confirmó ese slot. */
function rival_estado_opcion(array $op, ?array $reservados = null): string
{
    if ($op['estado'] !== 'vigente') return (string)$op['estado'];
    $ini = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $op['fecha'] . ' ' . substr($op['hora'], 0, 5), new DateTimeZone(RIVAL_TZ));
    if ($ini <= rival_ahora()) return 'vencida';
    // La cancha cambió su horario (disponibilidad semanal o excepción «no disponible»).
    if (!in_array(substr($op['hora'], 0, 5), rival_horas_de($ini, (int)$op['cancha_id']), true)) return 'fuera';
    $cerr = rival_ocupados((int)$op['cancha_id'], $op['fecha'], $op['fecha']);
    if (($cerr[$op['fecha'] . ' ' . substr($op['hora'], 0, 5)] ?? '') === 'cerrado') return 'fuera';
    if ($reservados === null) {
        $st = db()->prepare("SELECT 1 FROM reservas_cancha WHERE slot_llave = ?");
        $st->execute([rival_slot_llave((int)$op['cancha_id'], $op['fecha'], $op['hora'])]);
        if ($st->fetchColumn()) return 'ocupada';
    } elseif (isset($reservados[rival_slot_llave((int)$op['cancha_id'], $op['fecha'], $op['hora'])])) {
        return 'ocupada';
    }
    return 'vigente';
}

/**
 * Equipo B solicita una opción de otro equipo de su grupo.
 * Hasta RIVAL_MAX_SOLICITUDES pendientes a la vez, una por rival, nunca dos al mismo horario.
 */
function rival_solicitar(array $eq, int $opcionId): void
{
    rival_reintentar(function () use ($eq, $opcionId) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Las solicitudes del mismo equipo se procesan una tras otra (para contar bien el límite).
            $pdo->prepare('SELECT id FROM equipos WHERE id = ? FOR UPDATE')->execute([$eq['equipo_id']]);
            $st = $pdo->prepare('SELECT o.*, p.equipo_id AS dueno, p.equipo_nombre AS dueno_nombre, p.grupo_ref, p.estado AS pub_estado FROM rival_opciones o
                                   JOIN rival_publicaciones p ON p.id = o.publicacion_id WHERE o.id = ? FOR UPDATE');
            $st->execute([$opcionId]);
            $op = $st->fetch();
            if (!$op || $op['pub_estado'] !== 'activa') throw new RivalError('Ese horario ya no está disponible.');
            if ((string)$op['grupo_ref'] !== $eq['grupo']) throw new RivalError('Sólo puedes buscar rival dentro de tu grupo.');
            if ((int)$op['dueno'] === $eq['equipo_id']) throw new RivalError('No puedes solicitar tus propias opciones.');
            if (rival_estado_opcion($op) !== 'vigente') throw new RivalError('Ese horario ya no está disponible.');
            if (rival_hay_partido_entre($eq['equipo_id'], (int)$op['dueno'])) {
                throw new RivalError('Ya tienes un partido confirmado contra ' . $op['dueno_nombre'] . '.');
            }
            if (rival_v8_instalado() && rival_ya_jugaron($eq['equipo_id'], (int)$op['dueno'])) {
                throw new RivalError('Ya jugaste contra ' . $op['dueno_nombre'] . ' (está en tu historial). Busca otro rival.');
            }
            $st = $pdo->prepare("SELECT o.cancha_id, o.fecha, o.hora FROM rival_solicitudes s JOIN rival_opciones o ON o.id = s.opcion_id
                                  WHERE s.equipo_id = ? AND s.estado = 'pendiente'");
            $st->execute([$eq['equipo_id']]);
            $pend = $st->fetchAll();
            if (count($pend) >= RIVAL_MAX_SOLICITUDES) {
                throw new RivalError('Ya tienes ' . RIVAL_MAX_SOLICITUDES . ' solicitudes pendientes. Espera respuesta o cancela alguna.');
            }
            $llave = rival_slot_llave((int)$op['cancha_id'], $op['fecha'], $op['hora']);
            foreach ($pend as $p) {
                if (rival_slot_llave((int)$p['cancha_id'], $p['fecha'], $p['hora']) === $llave) {
                    throw new RivalError('Ya pediste ese mismo horario a otro equipo. Elige otro horario.');
                }
            }
            $pdo->prepare('INSERT INTO rival_solicitudes (publicacion_id, opcion_id, equipo_id, estado_equipo_id, equipo_nombre, estado, pendiente_par)
                           VALUES (?, ?, ?, ?, ?, \'pendiente\', ?)')
                ->execute([$op['publicacion_id'], $opcionId, $eq['equipo_id'], $eq['estado_id'], mb_substr($eq['nombre'], 0, 150),
                           $eq['equipo_id'] . ':' . $op['publicacion_id']]);
            $sol = (int)$pdo->lastInsertId();
            rival_evento('solicitada', $eq['grupo'], $eq['equipo_id'], (int)$op['dueno'], (int)$op['publicacion_id'], $sol);
            push_encolar((int)$op['dueno'], 'solicitud', 'Nueva solicitud de partido',
                $eq['nombre'] . ' eligió tu horario del ' . rival_slot_texto($op['fecha'], $op['hora']) . '. Entra a confirmarlo o rechazarlo.', '/disponibilidad/rival.php?s=partidos');
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((int)($ex->errorInfo[1] ?? 0) === 1062) throw new RivalError('Ya le pediste un horario a este equipo. Espera su respuesta o cancela esa solicitud.');
            throw $ex;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
    });
}

/** El solicitante cancela su solicitud pendiente. */
function rival_cancelar_solicitud(array $eq, int $solId): void
{
    $st = db()->prepare("UPDATE rival_solicitudes SET estado = 'cancelada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'Cancelada por el equipo'
                          WHERE id = ? AND equipo_id = ? AND estado = 'pendiente'");
    $st->execute([$solId, $eq['equipo_id']]);
    if (!$st->rowCount()) throw new RivalError('Esa solicitud ya no está pendiente.');
    rival_evento('solicitud_cancelada', $eq['grupo'], $eq['equipo_id'], null, null, $solId);
}

/** El dueño de la publicación rechaza una solicitud. */
function rival_rechazar(array $eq, int $solId): void
{
    $st = db()->prepare("UPDATE rival_solicitudes s JOIN rival_publicaciones p ON p.id = s.publicacion_id
                            SET s.estado = 'rechazada', s.pendiente_llave = NULL, s.pendiente_par = NULL, s.resuelta_en = NOW(), s.motivo = 'Rechazada por el rival'
                          WHERE s.id = ? AND p.equipo_id = ? AND s.estado = 'pendiente'");
    $st->execute([$solId, $eq['equipo_id']]);
    if (!$st->rowCount()) throw new RivalError('Esa solicitud ya no está pendiente.');
    rival_evento('rechazada', $eq['grupo'], $eq['equipo_id'], null, null, $solId);
    $st = db()->prepare('SELECT s.equipo_id, o.fecha, o.hora FROM rival_solicitudes s JOIN rival_opciones o ON o.id = s.opcion_id WHERE s.id = ?');
    $st->execute([$solId]);
    if ($x = $st->fetch()) {
        push_encolar((int)$x['equipo_id'], 'rechazado', 'Solicitud rechazada',
            $eq['nombre'] . ' rechazó tu solicitud del ' . rival_slot_texto($x['fecha'], $x['hora']) . '. Puedes elegir otro horario.', '/disponibilidad/rival.php?s=buscar');
    }
}

/**
 * El dueño (A) CONFIRMA la solicitud de B → partido formalizado.
 * Sus otras opciones siguen publicadas: puede confirmar hasta 3 partidos, uno por opción.
 * Todo en una transacción; la reserva usa un índice único por slot (segunda barrera).
 * @return array{fecha:string, hora:string, rival:string, confirmados:int}
 */
function rival_confirmar(array $eq, int $solId): array
{
    return rival_reintentar(fn() => rival_confirmar_una_vez($eq, $solId));
}

function rival_confirmar_una_vez(array $eq, int $solId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // DICIFO es un recurso único: las confirmaciones se hacen una tras otra (bloqueo de la cancha).
        $pdo->prepare('SELECT id FROM canchas WHERE id = ? FOR UPDATE')->execute([(int)rival_cancha_id()]);
        $pub = rival_mi_publicacion($eq['equipo_id'], true);
        if (!$pub) throw new RivalError('Ya no tienes opciones publicadas.');
        $st = $pdo->prepare("SELECT * FROM rival_solicitudes WHERE id = ? AND publicacion_id = ? FOR UPDATE");
        $st->execute([$solId, $pub['id']]);
        $sol = $st->fetch();
        if (!$sol) throw new RivalError('Esa solicitud ya no existe.');
        if ($sol['estado'] !== 'pendiente') {
            throw new RivalError($sol['estado'] === 'anulada' && $sol['motivo']
                ? 'Esa solicitud se anuló: ' . mb_strtolower((string)$sol['motivo']) . '.'
                : 'Esa solicitud ya no está pendiente.');
        }
        $st = $pdo->prepare('SELECT * FROM rival_opciones WHERE id = ? FOR UPDATE');
        $st->execute([$sol['opcion_id']]);
        $op = $st->fetch();
        $ef = $op ? rival_estado_opcion($op) : 'liberada';
        if ($ef !== 'vigente') {
            $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = ? WHERE id = ?")
                ->execute([$ef === 'vencida' ? 'El horario ya pasó' : ($ef === 'confirmada' ? 'Ese horario ya se confirmó con otro equipo' : ($ef === 'fuera' ? 'DICIFO ya no está disponible a esa hora' : 'El horario se ocupó')), $solId]);
            if ($ef === 'ocupada') $pdo->prepare("UPDATE rival_opciones SET estado = 'ocupada' WHERE id = ?")->execute([$op['id']]);
            $pdo->commit();
            throw new RivalError('Ese horario ya no está disponible (' . ($ef === 'vencida' ? 'ya pasó' : ($ef === 'confirmada' ? 'ya lo confirmaste con otro equipo' : ($ef === 'fuera' ? 'DICIFO ya no está disponible a esa hora' : 'otro partido lo confirmó'))) . '). La solicitud se anuló.');
        }

        // B sigue siendo válido (activo en la liga) y no hay ya un partido próximo entre los dos.
        $b = (int)$sol['equipo_id'];
        $st = $pdo->prepare('SELECT nombre FROM equipos WHERE id = ? AND activo = 1');
        $st->execute([$b]);
        if ($st->fetchColumn() === false) throw new RivalError('El equipo que solicitó ya no está activo.');
        if (rival_hay_partido_entre($eq['equipo_id'], $b)) {
            throw new RivalError('Ya tienes un partido confirmado contra ' . $sol['equipo_nombre'] . '. Rechaza esta solicitud o reprograma el partido existente.');
        }
        // Mismo grupo (categoría + Grupo A/B): si cambió la clasificación, la solicitud ya no procede.
        if (rival_grupo_de_estado((string)$sol['estado_equipo_id']) !== $eq['grupo']) {
            $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'Los equipos ya no están en el mismo grupo' WHERE id = ?")
                ->execute([$solId]);
            $pdo->commit();
            throw new RivalError($sol['equipo_nombre'] . ' no es de tu grupo. La solicitud se anuló.');
        }

        // 1) Reserva global del slot (UNIQUE): si otro la tomó al mismo tiempo, falla aquí.
        $llave = rival_slot_llave((int)$op['cancha_id'], $op['fecha'], $op['hora']);
        try {
            $pdo->prepare("INSERT INTO reservas_cancha (cancha_id, fecha, hora, estado, slot_llave, grupo_ref, equipo_a_id, equipo_b_id,
                                                        equipo_a_nombre, equipo_b_nombre, publicacion_id, solicitud_id)
                           VALUES (?, ?, ?, 'confirmada', ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$op['cancha_id'], $op['fecha'], $op['hora'], $llave, $pub['grupo_ref'], $eq['equipo_id'], $b,
                           mb_substr($eq['nombre'], 0, 150), $sol['equipo_nombre'], $pub['id'], $solId]);
        } catch (PDOException $ex) {
            if ((int)($ex->errorInfo[1] ?? 0) !== 1062) throw $ex;
            $pdo->prepare("UPDATE rival_opciones SET estado = 'ocupada' WHERE id = ?")->execute([$op['id']]);
            $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'El horario se ocupó' WHERE id = ?")
                ->execute([$solId]);
            $pdo->commit();
            throw new RivalError('Otro partido acaba de confirmar ese mismo horario. La solicitud se anuló; reemplaza esa opción.');
        }
        $reserva = (int)$pdo->lastInsertId();

        // 2) La opción elegida y la solicitud quedan confirmadas. Las OTRAS opciones siguen publicadas.
        $pdo->prepare("UPDATE rival_opciones SET estado = 'confirmada' WHERE id = ?")->execute([$op['id']]);
        $pdo->prepare("UPDATE rival_solicitudes SET estado = 'confirmada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW() WHERE id = ?")->execute([$solId]);
        //    Otras solicitudes al MISMO horario de A: ya no se pueden atender.
        $pdo->prepare("UPDATE rival_solicitudes SET estado = 'cancelada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'Ese horario se confirmó con otro equipo'
                        WHERE opcion_id = ? AND estado = 'pendiente'")->execute([$op['id']]);
        //    Solicitudes pendientes entre A y B (en cualquier sentido): ya tienen partido.
        $pdo->prepare("UPDATE rival_solicitudes s JOIN rival_publicaciones p ON p.id = s.publicacion_id
                          SET s.estado = 'cancelada', s.pendiente_llave = NULL, s.pendiente_par = NULL, s.resuelta_en = NOW(), s.motivo = 'Ya tienen un partido confirmado entre ustedes'
                        WHERE s.estado = 'pendiente' AND ((s.equipo_id = ? AND p.equipo_id = ?) OR (s.equipo_id = ? AND p.equipo_id = ?))")
            ->execute([$b, $eq['equipo_id'], $eq['equipo_id'], $b]);

        // 3) El slot deja de estar disponible para TODOS.
        rival_ocupar_slot((int)$op['cancha_id'], $op['fecha'], $op['hora'], null);

        // 4) La publicación sigue activa (actualización 2026-09-29): el equipo conserva sus demás
        //    horarios y agrega más si le quedan menos de RIVAL_MIN_HORARIOS.
        $st = $pdo->prepare("SELECT COUNT(*) FROM rival_opciones WHERE publicacion_id = ? AND estado = 'confirmada'");
        $st->execute([$pub['id']]);
        $nConf = (int)$st->fetchColumn();
        //    Propuestas de la administración entre estos dos equipos: ya tienen partido.
        if (rival_v8_instalado()) rival_propuestas_anular_entre($eq['equipo_id'], $b, 'Ya confirmaron un partido entre ustedes');

        rival_evento('confirmada', $pub['grupo_ref'], $eq['equipo_id'], $b, (int)$pub['id'], $solId, 'reserva ' . $reserva);
        push_encolar($b, 'confirmado', '¡Partido confirmado!',
            $eq['nombre'] . ' confirmó: ' . $eq['nombre'] . ' vs ' . $sol['equipo_nombre'] . ', ' . rival_slot_texto($op['fecha'], $op['hora']) . ' en DICIFO.', '/disponibilidad/rival.php?s=partidos');
        $pdo->commit();
        return ['fecha' => $op['fecha'], 'hora' => $op['hora'], 'rival' => (string)$sol['equipo_nombre'], 'confirmados' => $nConf];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * (Dentro de una transacción) un slot acaba de reservarse: las propuestas de otros equipos en ese
 * horario quedan «ocupadas» (requieren reemplazo), sus solicitudes pendientes se anulan y las
 * propuestas de reprogramación hacia ese horario también.
 */
function rival_ocupar_slot(int $cancha, string $fecha, string $hora, ?int $salvoReprog): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT o.id, p.equipo_id, p.grupo_ref, p.id AS pub FROM rival_opciones o JOIN rival_publicaciones p ON p.id = o.publicacion_id
                          WHERE o.cancha_id = ? AND o.fecha = ? AND o.hora = ? AND o.estado = 'vigente' FOR UPDATE");
    $st->execute([$cancha, $fecha, $hora]);
    foreach ($st->fetchAll() as $x) {
        $pdo->prepare("UPDATE rival_opciones SET estado = 'ocupada' WHERE id = ?")->execute([$x['id']]);
        $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'El horario se ocupó'
                        WHERE opcion_id = ? AND estado = 'pendiente'")->execute([$x['id']]);
        rival_evento('ocupada', $x['grupo_ref'], (int)$x['equipo_id'], null, (int)$x['pub'], null, $fecha . ' ' . $hora);
        push_encolar((int)$x['equipo_id'], 'ocupada', 'Uno de tus horarios se ocupó',
            'El ' . rival_slot_texto($fecha, $hora) . ' lo tomó otro partido. Reemplázalo o agrega otro horario en «Horarios».', '/disponibilidad/rival.php?s=horarios');
    }
    if (rival_v8_instalado()) {
        // Reprogramaciones con varios horarios: sólo se quita ese horario; se anulan las que se quedan sin ninguno.
        rival_reprog_quitar_slot($cancha, $fecha, $hora, $salvoReprog, 'El horario propuesto se ocupó');
        rival_propuestas_anular_slot($cancha, $fecha, $hora, 'El horario se ocupó');
        return;
    }
    $pdo->prepare("UPDATE rival_reprogramaciones SET estado = 'anulada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = 'El horario propuesto se ocupó'
                    WHERE cancha_id = ? AND fecha_nueva = ? AND hora_nueva = ? AND estado = 'pendiente' AND id <> ?")
        ->execute([$cancha, $fecha, $hora, $salvoReprog ?? 0]);
}

/**
 * (Dentro de una transacción) un slot reservado se liberó (reprogramación): las opciones de
 * publicaciones activas que habían quedado «ocupadas» sólo por él vuelven a estar disponibles.
 */
function rival_liberar_slot(int $cancha, string $fecha, string $hora): void
{
    db()->prepare("UPDATE rival_opciones o JOIN rival_publicaciones p ON p.id = o.publicacion_id
                      SET o.estado = 'vigente'
                    WHERE o.cancha_id = ? AND o.fecha = ? AND o.hora = ? AND o.estado = 'ocupada' AND p.estado = 'activa'")
        ->execute([$cancha, $fecha, $hora]);
}

/* ------------------------------------------------------------------ */
/* Reprogramación de partidos confirmados                              */
/* ------------------------------------------------------------------ */

/** Partido confirmado del equipo (A o B), bloqueado si $paraActualizar. */
function rival_mi_partido(int $equipoId, int $reservaId, bool $paraActualizar = false): ?array
{
    $st = db()->prepare("SELECT * FROM reservas_cancha WHERE id = ? AND estado = 'confirmada' AND (equipo_a_id = ? OR equipo_b_id = ?)"
                        . ($paraActualizar ? ' FOR UPDATE' : ''));
    $st->execute([$reservaId, $equipoId, $equipoId]);
    return $st->fetch() ?: null;
}

/** ¿Faltan al menos RIVAL_REPROG_HORAS horas para el partido? */
function rival_reprog_a_tiempo(string $fecha, string $hora): bool
{
    $ini = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $fecha . ' ' . substr($hora, 0, 5), new DateTimeZone(RIVAL_TZ));
    return $ini && $ini >= rival_ahora()->modify('+' . RIVAL_REPROG_HORAS . ' hours');
}

function rival_ya_empezo(string $fecha, string $hora): bool
{
    $ini = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $fecha . ' ' . substr($hora, 0, 5), new DateTimeZone(RIVAL_TZ));
    return !$ini || $ini <= rival_ahora();
}

/**
 * Cualquiera de los dos equipos propone de 1 a RIVAL_REPROG_MAX horarios nuevos para su partido
 * confirmado; el otro equipo elige uno (o rechaza). $claves acepta un string (versión anterior) o una lista.
 */
function rival_reprog_proponer(array $eq, int $reservaId, $claves, string $motivo): void
{
    $claves = array_values(array_unique(array_filter(is_array($claves) ? $claves : [$claves], fn($c) => is_string($c) && trim($c) !== '')));
    if (!$claves) throw new RivalError('Elige al menos un horario nuevo.');
    if (count($claves) > RIVAL_REPROG_MAX) throw new RivalError('Puedes proponer hasta ' . RIVAL_REPROG_MAX . ' horarios.');
    if (count($claves) > 1 && !rival_v8_instalado()) throw new RivalError('Por ahora sólo se puede proponer un horario (falta instalar la actualización).');
    $cancha = (int)rival_cancha_id();
    $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
    if (mb_strlen($motivo) > 200) throw new RivalError('El motivo es muy largo (máximo 200 caracteres).');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = rival_mi_partido($eq['equipo_id'], $reservaId, true);
        if (!$r) throw new RivalError('Ese partido no es de tu equipo o ya no está confirmado.');
        if (rival_ya_empezo($r['fecha'], $r['hora'])) throw new RivalError('Ese partido ya empezó o ya pasó; no se puede reprogramar.');
        if (!rival_reprog_a_tiempo($r['fecha'], $r['hora'])) {
            throw new RivalError('Sólo se puede pedir una reprogramación con al menos ' . RIVAL_REPROG_HORAS . ' horas de anticipación al partido.');
        }
        [$slots, $err] = rival_validar_slots($claves, $cancha);
        if ($err || !$slots) throw new RivalError($err ? implode(' ', $err) : 'Elige un horario válido.');
        usort($slots, fn($x, $y) => strcmp($x['fecha'] . $x['hora'], $y['fecha'] . $y['hora']));
        $s = $slots[0];
        $soyA = (int)$r['equipo_a_id'] === $eq['equipo_id'];
        $otro = $soyA ? (int)$r['equipo_b_id'] : (int)$r['equipo_a_id'];
        $otroNom = $soyA ? $r['equipo_b_nombre'] : $r['equipo_a_nombre'];
        $pdo->prepare("INSERT INTO rival_reprogramaciones (reserva_id, solicita_equipo_id, solicita_nombre, otro_equipo_id, otro_nombre, cancha_id,
                                                           fecha_anterior, hora_anterior, fecha_nueva, hora_nueva, motivo, pendiente_llave)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$reservaId, $eq['equipo_id'], mb_substr($eq['nombre'], 0, 150), $otro, $otroNom, $r['cancha_id'],
                       $r['fecha'], $r['hora'], $s['fecha'], $s['hora'], $motivo !== '' ? $motivo : null, $reservaId]);
        $rpId = (int)$pdo->lastInsertId();
        if (rival_v8_instalado()) {
            $ins = $pdo->prepare('INSERT INTO rival_reprog_opciones (reprog_id, fecha, hora) VALUES (?, ?, ?)');
            foreach ($slots as $x) $ins->execute([$rpId, $x['fecha'], $x['hora']]);
        }
        $txtNuevos = count($slots) === 1 ? 'al ' . rival_slot_texto($s['fecha'], $s['hora'])
            : 'a uno de estos ' . count($slots) . ' horarios: ' . implode(', ', array_map(fn($x) => rival_slot_texto($x['fecha'], $x['hora']), $slots));
        rival_evento('reprog_solicitada', $r['grupo_ref'], $eq['equipo_id'], $otro, $r['publicacion_id'] !== null ? (int)$r['publicacion_id'] : null, null,
                     'reserva ' . $reservaId . ' → ' . $s['fecha'] . ' ' . $s['hora']);
        push_encolar($otro, 'reprog', 'Te proponen reprogramar un partido',
            $eq['nombre'] . ' propone cambiar su partido del ' . rival_slot_texto($r['fecha'], $r['hora']) . ' ' . $txtNuevos . '. Elige uno o rechaza.', '/disponibilidad/rival.php?s=partidos');
        $pdo->commit();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($ex->errorInfo[1] ?? 0) === 1062) throw new RivalError('Ya hay una propuesta de reprogramación pendiente para ese partido.');
        throw $ex;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** Quien propuso la reprogramación la retira. */
function rival_reprog_cancelar(array $eq, int $id): void
{
    $st = db()->prepare("UPDATE rival_reprogramaciones SET estado = 'cancelada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = 'Retirada por quien la propuso'
                          WHERE id = ? AND solicita_equipo_id = ? AND estado = 'pendiente'");
    $st->execute([$id, $eq['equipo_id']]);
    if (!$st->rowCount()) throw new RivalError('Esa propuesta ya no está pendiente.');
    rival_evento('reprog_cancelada', $eq['grupo'], $eq['equipo_id'], null, null, null, 'reprogramación ' . $id);
}

/** El otro equipo rechaza la reprogramación: el partido se queda en su horario original. */
function rival_reprog_rechazar(array $eq, int $id): void
{
    $st = db()->prepare("UPDATE rival_reprogramaciones SET estado = 'rechazada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = 'Rechazada por el rival'
                          WHERE id = ? AND otro_equipo_id = ? AND estado = 'pendiente'");
    $st->execute([$id, $eq['equipo_id']]);
    if (!$st->rowCount()) throw new RivalError('Esa propuesta ya no está pendiente.');
    rival_evento('reprog_rechazada', $eq['grupo'], $eq['equipo_id'], null, null, null, 'reprogramación ' . $id);
    $st = db()->prepare('SELECT solicita_equipo_id, fecha_anterior, hora_anterior FROM rival_reprogramaciones WHERE id = ?');
    $st->execute([$id]);
    if ($x = $st->fetch()) {
        push_encolar((int)$x['solicita_equipo_id'], 'reprog_resp', 'Reprogramación rechazada',
            $eq['nombre'] . ' rechazó el cambio. El partido sigue el ' . rival_slot_texto($x['fecha_anterior'], $x['hora_anterior']) . '.', '/disponibilidad/rival.php?s=partidos');
    }
}

/**
 * El otro equipo ACEPTA: el partido se mueve al nuevo horario en una sola transacción
 * (cancha bloqueada + índice único del slot). El horario anterior queda libre.
 * @return array{fecha:string, hora:string, rival:string}
 */
function rival_reprog_aceptar(array $eq, int $id, string $elegido = ''): array
{
    return rival_reintentar(fn() => rival_reprog_aceptar_una_vez($eq, $id, $elegido));
}

function rival_reprog_aceptar_una_vez(array $eq, int $id, string $elegido = ''): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM canchas WHERE id = ? FOR UPDATE')->execute([(int)rival_cancha_id()]);
        $st = $pdo->prepare("SELECT * FROM rival_reprogramaciones WHERE id = ? AND otro_equipo_id = ? FOR UPDATE");
        $st->execute([$id, $eq['equipo_id']]);
        $rp = $st->fetch();
        if (!$rp) throw new RivalError('Esa propuesta no es para tu equipo.');
        if ($rp['estado'] !== 'pendiente') {
            throw new RivalError($rp['estado'] === 'anulada' && $rp['respuesta'] ? 'Esa propuesta se anuló: ' . mb_strtolower((string)$rp['respuesta']) . '.' : 'Esa propuesta ya no está pendiente.');
        }
        $anular = function (string $por) use ($pdo, $id): void {
            $pdo->prepare("UPDATE rival_reprogramaciones SET estado = 'anulada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = ? WHERE id = ?")->execute([$por, $id]);
            $pdo->commit();
        };
        $r = rival_mi_partido($eq['equipo_id'], (int)$rp['reserva_id'], true);
        if (!$r) { $anular('El partido ya no está confirmado'); throw new RivalError('El partido ya no está confirmado. La propuesta se anuló.'); }
        if ($r['fecha'] !== $rp['fecha_anterior'] || substr($r['hora'], 0, 5) !== substr($rp['hora_anterior'], 0, 5)) {
            $anular('El partido ya cambió de horario'); throw new RivalError('El partido ya cambió de horario. La propuesta se anuló.');
        }
        if (rival_ya_empezo($r['fecha'], $r['hora'])) { $anular('El partido ya empezó'); throw new RivalError('El partido ya empezó; no se puede reprogramar.'); }
        // Varios horarios propuestos: el equipo elige uno de la lista.
        if (rival_v8_instalado()) {
            $ops = rival_reprog_opciones_de((int)$rp['id']);
            if ($ops) {
                $elegido = trim($elegido);
                if ($elegido === '' && count($ops) === 1) $elegido = $ops[0]['clave'];
                $ok = array_values(array_filter($ops, fn($o) => $o['clave'] === $elegido));
                if (!$ok) { $pdo->rollBack(); throw new RivalError('Elige uno de los horarios propuestos.'); }
                $rp['fecha_nueva'] = $ok[0]['fecha'];
                $rp['hora_nueva'] = $ok[0]['hora'];
                $pdo->prepare('UPDATE rival_reprogramaciones SET fecha_nueva = ?, hora_nueva = ? WHERE id = ?')->execute([$rp['fecha_nueva'], $rp['hora_nueva'], $id]);
            }
        }
        $nueva = $rp['fecha_nueva'] . ' ' . substr($rp['hora_nueva'], 0, 5);
        [$slots, $err] = rival_validar_slots([$nueva], (int)$rp['cancha_id']);
        if ($err || !$slots) {
            $anular('El horario propuesto ya no está disponible');
            throw new RivalError('El horario propuesto ya no está disponible (' . implode(' ', $err) . ') La propuesta se anuló; pueden proponer otro.');
        }
        $llave = rival_slot_llave((int)$rp['cancha_id'], $rp['fecha_nueva'], $rp['hora_nueva']);
        try {
            $pdo->prepare('UPDATE reservas_cancha SET fecha = ?, hora = ?, slot_llave = ? WHERE id = ?')
                ->execute([$rp['fecha_nueva'], $rp['hora_nueva'], $llave, $r['id']]);
        } catch (PDOException $ex) {
            if ((int)($ex->errorInfo[1] ?? 0) !== 1062) throw $ex;
            $anular('El horario propuesto se ocupó');
            throw new RivalError('Otro partido acaba de tomar ese horario. La propuesta se anuló; pueden proponer otro.');
        }
        $pdo->prepare("UPDATE rival_reprogramaciones SET estado = 'aceptada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = 'Aceptada' WHERE id = ?")->execute([$id]);
        push_encolar((int)$rp['solicita_equipo_id'], 'reprog_resp', 'Reprogramación aceptada',
            $eq['nombre'] . ' aceptó: el partido ahora es el ' . rival_slot_texto($rp['fecha_nueva'], $rp['hora_nueva']) . ' en DICIFO.', '/disponibilidad/rival.php?s=partidos');
        rival_liberar_slot((int)$r['cancha_id'], $r['fecha'], $r['hora']);
        rival_ocupar_slot((int)$rp['cancha_id'], $rp['fecha_nueva'], $rp['hora_nueva'], $id);
        rival_evento('reprogramada', $r['grupo_ref'], $eq['equipo_id'], (int)$rp['solicita_equipo_id'], $r['publicacion_id'] !== null ? (int)$r['publicacion_id'] : null,
                     $r['solicitud_id'] !== null ? (int)$r['solicitud_id'] : null, $r['fecha'] . ' ' . $r['hora'] . ' → ' . $rp['fecha_nueva'] . ' ' . $rp['hora_nueva']);
        $pdo->commit();
        return ['fecha' => $rp['fecha_nueva'], 'hora' => $rp['hora_nueva'], 'rival' => (string)$rp['solicita_nombre']];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* ------------------------------------------------------------------ */
/* Lectura para la pantalla                                            */
/* ------------------------------------------------------------------ */

/** Todo lo que ve el capitán. */
function rival_panel(array $eq): array
{
    $pdo = db();
    $yo = $eq['equipo_id'];
    $hoy = rival_ahora()->format('Y-m-d');
    $reservados = [];
    $st = $pdo->prepare("SELECT slot_llave FROM reservas_cancha WHERE estado = 'confirmada' AND fecha >= ?");
    $st->execute([$hoy]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $reservados[(string)$k] = true;

    $ops = function (int $pub) use ($pdo, $reservados): array {
        $st = $pdo->prepare("SELECT * FROM rival_opciones WHERE publicacion_id = ? AND estado IN ('vigente','ocupada','confirmada') ORDER BY fecha, hora");
        $st->execute([$pub]);
        $out = [];
        foreach ($st->fetchAll() as $o) { $o['efectivo'] = rival_estado_opcion($o, $reservados); $out[] = $o; }
        return $out;
    };

    // Partidos confirmados próximos (con su propuesta de reprogramación pendiente, si hay)
    $st = $pdo->prepare("SELECT * FROM reservas_cancha WHERE estado = 'confirmada' AND fecha >= ? AND (equipo_a_id = ? OR equipo_b_id = ?) ORDER BY fecha, hora");
    $st->execute([$hoy, $yo, $yo]);
    $confirmados = [];
    $st2 = $pdo->prepare("SELECT * FROM rival_reprogramaciones WHERE pendiente_llave = ?");
    foreach ($st->fetchAll() as $r) {
        $r['rival'] = (int)$r['equipo_a_id'] === $yo ? $r['equipo_b_nombre'] : $r['equipo_a_nombre'];
        $r['rival_id'] = (int)$r['equipo_a_id'] === $yo ? (int)$r['equipo_b_id'] : (int)$r['equipo_a_id'];
        $r['empezo'] = rival_ya_empezo($r['fecha'], $r['hora']);
        $r['reprog_a_tiempo'] = rival_reprog_a_tiempo($r['fecha'], $r['hora']);
        $st2->execute([$r['id']]);
        $r['reprog'] = $st2->fetch() ?: null;
        $confirmados[] = $r;
    }
    $reprogParaMi = array_values(array_filter($confirmados, fn($r) => $r['reprog'] && (int)$r['reprog']['otro_equipo_id'] === $yo));

    // Mi publicación (si la clasificación de grupos cambió, se mueve a mi grupo actual y
    // se anulan las solicitudes de equipos que ya no son de mi grupo).
    $mia = rival_mi_publicacion($yo);
    if ($mia && (string)$mia['grupo_ref'] !== $eq['grupo']) {
        $pdo->prepare('UPDATE rival_publicaciones SET grupo_ref = ? WHERE id = ?')->execute([$eq['grupo'], $mia['id']]);
        $st = $pdo->prepare("SELECT id, estado_equipo_id FROM rival_solicitudes WHERE publicacion_id = ? AND estado = 'pendiente'");
        $st->execute([$mia['id']]);
        foreach ($st->fetchAll() as $x) {
            if (rival_grupo_de_estado((string)$x['estado_equipo_id']) !== $eq['grupo']) {
                $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'Los equipos ya no están en el mismo grupo' WHERE id = ?")
                    ->execute([$x['id']]);
            }
        }
        $mia['grupo_ref'] = $eq['grupo'];
    }
    if ($mia) {
        $mia['opciones'] = $ops((int)$mia['id']);
        // Las opciones confirmadas muestran el partido tal como está hoy (pudo reprogramarse).
        $st = $pdo->prepare("SELECT r.* FROM reservas_cancha r JOIN rival_solicitudes s ON s.id = r.solicitud_id
                              WHERE s.opcion_id = ? AND r.estado = 'confirmada' LIMIT 1");
        // Partidos propuestos por la administración: no tienen solicitud; se ubican por horario y equipo.
        $st3 = $pdo->prepare("SELECT * FROM reservas_cancha WHERE slot_llave = ? AND estado = 'confirmada' AND (equipo_a_id = ? OR equipo_b_id = ?) LIMIT 1");
        foreach ($mia['opciones'] as &$o) {
            if ($o['efectivo'] !== 'confirmada') continue;
            $st->execute([$o['id']]);
            $o['partido'] = $st->fetch() ?: null;
            if (!$o['partido']) {
                $st3->execute([rival_slot_llave((int)$o['cancha_id'], $o['fecha'], $o['hora']), $yo, $yo]);
                $o['partido'] = $st3->fetch() ?: null;
            }
            if (!$o['partido'] && rival_v8_instalado()) {   // propuesta de la liga que después se movió
                $st4 = $pdo->prepare("SELECT r.* FROM rival_propuestas rp JOIN reservas_cancha r ON r.id = rp.reserva_id
                                       WHERE rp.cancha_id = ? AND rp.fecha = ? AND rp.hora = ? AND (rp.equipo_a_id = ? OR rp.equipo_b_id = ?) AND r.estado = 'confirmada' LIMIT 1");
                $st4->execute([$o['cancha_id'], $o['fecha'], $o['hora'], $yo, $yo]);
                $o['partido'] = $st4->fetch() ?: null;
            }
        }
        unset($o);
        $mia['validas'] = count(array_filter($mia['opciones'], fn($o) => in_array($o['efectivo'], ['vigente', 'confirmada'], true)));
        $mia['activas'] = count(array_filter($mia['opciones'], fn($o) => $o['efectivo'] === 'vigente'));
        $mia['confirmadas'] = count(array_filter($mia['opciones'], fn($o) => $o['efectivo'] === 'confirmada'));
        $mia['por_dia'] = [];
        foreach ($mia['opciones'] as $o) {
            if ($o['efectivo'] === 'vencida') continue;
            $mia['por_dia'][$o['fecha']] = ($mia['por_dia'][$o['fecha']] ?? 0) + 1;
        }
        $st = $pdo->prepare("SELECT s.*, o.fecha, o.hora FROM rival_solicitudes s JOIN rival_opciones o ON o.id = s.opcion_id
                              WHERE s.publicacion_id = ? AND s.estado = 'pendiente' ORDER BY o.fecha, o.hora, s.creado_en");
        $st->execute([$mia['id']]);
        $mia['solicitudes'] = $st->fetchAll();
    }

    // Mis solicitudes pendientes (a otros equipos): hasta RIVAL_MAX_SOLICITUDES
    $st = $pdo->prepare("SELECT s.*, o.fecha, o.hora, p.equipo_nombre AS rival, p.equipo_id AS rival_id FROM rival_solicitudes s
                           JOIN rival_opciones o ON o.id = s.opcion_id JOIN rival_publicaciones p ON p.id = s.publicacion_id
                          WHERE s.equipo_id = ? AND s.estado = 'pendiente' ORDER BY o.fecha, o.hora");
    $st->execute([$yo]);
    $misSolicitudes = $st->fetchAll();

    // Avisos recientes: solicitudes resueltas sin partido y reprogramaciones resueltas
    $st = $pdo->prepare("SELECT s.estado, s.motivo, s.resuelta_en, o.fecha, o.hora, p.equipo_nombre AS rival FROM rival_solicitudes s
                           JOIN rival_opciones o ON o.id = s.opcion_id JOIN rival_publicaciones p ON p.id = s.publicacion_id
                          WHERE s.equipo_id = ? AND s.estado IN ('rechazada','anulada','cancelada') AND s.resuelta_en >= NOW() - INTERVAL 3 DAY
                            AND (s.motivo IS NULL OR s.motivo <> 'Cancelada por el equipo')
                          ORDER BY s.resuelta_en DESC LIMIT 3");
    $st->execute([$yo]);
    $avisos = $st->fetchAll();
    $st = $pdo->prepare("SELECT * FROM rival_reprogramaciones
                          WHERE ((solicita_equipo_id = ? AND estado IN ('aceptada','rechazada','anulada')) OR (otro_equipo_id = ? AND estado IN ('cancelada','anulada')))
                            AND resuelta_en >= NOW() - INTERVAL 3 DAY ORDER BY resuelta_en DESC LIMIT 3");
    $st->execute([$yo, $yo]);
    $avisosReprog = $st->fetchAll();

    // Publicaciones hechas antes de la división en grupos A/B: se pasan a su grupo actual.
    if ($eq['grupo_letra'] !== '') {
        $base = substr($eq['grupo'], 0, -2);
        $st = $pdo->prepare("SELECT id, estado_equipo_id FROM rival_publicaciones WHERE grupo_ref = ? AND estado = 'activa'");
        $st->execute([$base]);
        foreach ($st->fetchAll() as $x) {
            $g = rival_grupo_de_estado((string)$x['estado_equipo_id']);
            if ($g !== null && $g !== $base) $pdo->prepare('UPDATE rival_publicaciones SET grupo_ref = ? WHERE id = ?')->execute([$g, $x['id']]);
        }
    }

    // Rivales del grupo con opciones publicadas
    $pedidas = [];
    foreach ($misSolicitudes as $s) $pedidas[(int)$s['publicacion_id']] = $s;
    $conPartido = array_flip(array_column($confirmados, 'rival_id'));
    $st = $pdo->prepare("SELECT * FROM rival_publicaciones WHERE grupo_ref = ? AND estado = 'activa' AND equipo_id <> ? ORDER BY creado_en");
    $st->execute([$eq['grupo'], $yo]);
    $rivales = [];
    foreach ($st->fetchAll() as $p) {
        $p['opciones'] = array_values(array_filter($ops((int)$p['id']), fn($o) => $o['efectivo'] === 'vigente'));
        $p['mi_solicitud'] = $pedidas[(int)$p['id']] ?? null;
        $p['con_partido'] = isset($conPartido[(int)$p['equipo_id']]);
        if ($p['opciones'] || $p['mi_solicitud']) $rivales[] = $p;
    }
    $puedoSolicitar = count($misSolicitudes) < RIVAL_MAX_SOLICITUDES;

    // Estado general del equipo (para la barra de pasos)
    if (($mia && $mia['solicitudes']) || $reprogParaMi) $estado = 'pendiente_confirmacion';
    elseif ($misSolicitudes) $estado = 'solicitud_pendiente';
    elseif ($mia && $mia['activas'] < RIVAL_MIN_HORARIOS) $estado = 'requiere_reemplazo';
    elseif ($mia) $estado = 'publicado';
    elseif ($confirmados) $estado = 'confirmado';
    else $estado = 'disponible';

    return compact('mia', 'misSolicitudes', 'avisos', 'avisosReprog', 'confirmados', 'reprogParaMi', 'rivales', 'puedoSolicitar', 'estado');
}

/** Huella de lo que cambia en la pantalla del equipo (para la actualización en tiempo real). */
function rival_version(array $eq): string
{
    $f = db()->prepare("SELECT
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0), '-', COALESCE(MAX(actualizado_en),'')) FROM rival_publicaciones WHERE grupo_ref = ? OR equipo_id = ?),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0), '-', COALESCE(MAX(resuelta_en),'')) FROM rival_solicitudes),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0), '-', COALESCE(MAX(actualizado_en),'')) FROM reservas_cancha),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0), '-', COALESCE(MAX(actualizado_en),'')) FROM rival_opciones),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0), '-', COALESCE(MAX(resuelta_en),'')) FROM rival_reprogramaciones)");
    $f->execute([$eq['grupo'], $eq['equipo_id']]);
    $extra = rival_v8_instalado() ? rival_version_extra($eq) : '';
    return substr(hash('sha256', implode('|', $f->fetch(PDO::FETCH_NUM)) . '|' . $extra . '|' . rival_ahora()->format('Y-m-d H')), 0, 16);
}

/**
 * Recordatorio a los dos equipos de cada partido confirmado que empieza dentro de las próximas
 * 24 horas. La llave «rec:reserva:fecha hora» evita repetirlo; si el partido se reprograma,
 * la llave cambia y se manda un recordatorio nuevo. Lo llama el Cron.
 */
function rival_encolar_recordatorios(): int
{
    if (!rival_instalado() || !push_instalado()) return 0;
    $ahora = rival_ahora();
    $st = db()->prepare("SELECT * FROM reservas_cancha WHERE estado = 'confirmada' AND fecha BETWEEN ? AND ?");
    $st->execute([$ahora->format('Y-m-d'), $ahora->modify('+2 days')->format('Y-m-d')]);
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        $ini = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $r['fecha'] . ' ' . substr($r['hora'], 0, 5), new DateTimeZone(RIVAL_TZ));
        if (!$ini || $ini <= $ahora || $ini > $ahora->modify('+24 hours')) continue;
        $cuando = $r['fecha'] === $ahora->format('Y-m-d') ? 'hoy' : ($r['fecha'] === $ahora->modify('+1 day')->format('Y-m-d') ? 'mañana' : '');
        $hora = rival_hora_texto($r['hora']);
        foreach ([[(int)$r['equipo_a_id'], $r['equipo_b_nombre']], [(int)$r['equipo_b_id'], $r['equipo_a_nombre']]] as [$eqId, $rival]) {
            $llave = 'rec:' . $r['id'] . ':' . $eqId . ':' . $r['fecha'] . ' ' . substr($r['hora'], 0, 5);
            push_encolar($eqId, 'recordatorio', 'Recordatorio de partido',
                'Tu partido contra ' . $rival . ' es ' . ($cuando !== '' ? $cuando . ' a las ' . $hora : 'el ' . rival_slot_texto($r['fecha'], $r['hora'])) . ' en DICIFO.',
                '/disponibilidad/rival.php?s=partidos', $llave);
            $n++;
        }
    }
    return $n;
}

/* Actualización 2026-09-29: horarios uno por uno, propuestas de la administración,
   cancelaciones, reportes, notificaciones dentro de la app y cierre de horas de cancha. */
require_once __DIR__ . '/rival-extra.php';
