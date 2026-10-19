<?php
/**
 * Liga Chapingo — «BUSCA UN RIVAL» · Actualización 2026-09-29.
 *
 *  · Horarios: mínimo RIVAL_MIN_HORARIOS en total, hasta RIVAL_MAX_POR_DIA por día; se agregan y se
 *    eliminan UNO POR UNO sin borrar los demás (nunca se puede bajar del mínimo).
 *  · Reprogramación con varios horarios (1 a RIVAL_REPROG_MAX): el rival elige uno.
 *  · Peticiones a la administración: solicitar cancelación de un partido y reportar un problema.
 *  · Propuestas de la administración («Generar propuestas»): ambos equipos confirman o rechazan.
 *  · Administración: editar y cancelar partidos confirmados; cancelar una hora de cancha
 *    (avisa «partido pospuesto por disponibilidad de cancha»).
 *  · Notificaciones dentro de la app: se leen de push_cola (ahí se guarda cada aviso, aunque el
 *    equipo no haya activado los avisos en su celular).
 *
 * Tablas nuevas (se crean desde admin/actualizar.php o con sql/rival-actualizacion-2026-09-29.sql):
 *   rival_reprog_opciones, rival_propuestas, rival_peticiones, rival_notif_visto
 * y la columna reservas_cancha.motivo_cancelacion (+ cancelada_en).
 * Si todavía no se instalan, la app sigue funcionando como antes (sin las funciones nuevas).
 */
declare(strict_types=1);

/** Días hacia adelante en los que la ADMINISTRACIÓN puede mover o proponer partidos. */
const RIVAL_DIAS_ADMIN = 60;

/* ------------------------------------------------------------------ */
/* Instalación                                                         */
/* ------------------------------------------------------------------ */

function rival_v8_instalado(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        foreach (['rival_reprog_opciones', 'rival_propuestas', 'rival_peticiones', 'rival_notif_visto', 'rival_jugados'] as $t) {
            db()->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        }
        db()->query('SELECT motivo_cancelacion, cancelada_en FROM reservas_cancha LIMIT 1');
        $ok = true;
    } catch (Throwable $ex) {
        $ok = false;
    }
    return $ok;
}

/** Tipo de columna tal como lo reporta MySQL/MariaDB (o null si no existe). */
function rival_columna(string $tabla, string $col): ?array
{
    $st = db()->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tabla, $col]);
    return $st->fetch() ?: null;
}

/**
 * Si la columna es ENUM y le faltan valores, los agrega (conserva los existentes, NULL y DEFAULT).
 * Nombres de tabla/columna: SÓLO constantes de este archivo (nunca del navegador).
 */
function rival_enum_asegurar(string $tabla, string $col, array $valores): string
{
    $c = rival_columna($tabla, $col);
    if (!$c) return "$tabla.$col no existe (se omite)";
    $tipo = (string)$c['COLUMN_TYPE'];
    if (stripos($tipo, 'enum(') !== 0) return "$tabla.$col es $tipo (no requiere cambio)";
    preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", substr($tipo, 5, -1), $m);
    $act = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
    $faltan = array_values(array_diff($valores, $act));
    if (!$faltan) return "$tabla.$col ya acepta todos los valores";
    $lista = implode(',', array_map(fn($v) => db()->quote($v), array_merge($act, $faltan)));
    $null = $c['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
    $def = $c['COLUMN_DEFAULT'];
    $defSql = '';
    if ($def !== null && strtoupper((string)$def) !== 'NULL') $defSql = ' DEFAULT ' . db()->quote(trim((string)$def, "'"));
    elseif ($c['IS_NULLABLE'] === 'YES') $defSql = ' DEFAULT NULL';
    db()->exec("ALTER TABLE `$tabla` MODIFY `$col` ENUM($lista) $null$defSql");
    return "$tabla.$col: se agregaron " . implode(', ', $faltan);
}

/** Crea lo necesario (no borra ni cambia datos). Devuelve la bitácora de pasos. */
function rival_v8_migrar(): array
{
    $log = [];
    $pdo = db();
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rival_reprog_opciones (
        id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        reprog_id INT UNSIGNED NOT NULL,
        fecha     DATE NOT NULL,
        hora      TIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rro (reprog_id, fecha, hora),
        KEY idx_rro_slot (fecha, hora)
    ) $opts");
    $log[] = 'Tabla rival_reprog_opciones lista';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rival_propuestas (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        grupo_ref       VARCHAR(190) NOT NULL,
        cancha_id       INT UNSIGNED NOT NULL,
        fecha           DATE NOT NULL,
        hora            TIME NOT NULL,
        equipo_a_id     INT NOT NULL,
        equipo_a_nombre VARCHAR(150) NOT NULL,
        equipo_b_id     INT NOT NULL,
        equipo_b_nombre VARCHAR(150) NOT NULL,
        resp_a          VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        resp_b          VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        estado          VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        motivo          VARCHAR(255) NULL,
        creado_por      VARCHAR(80) NULL,
        reserva_id      INT UNSIGNED NULL,
        creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resuelta_en     DATETIME NULL,
        actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_rp_estado (estado, fecha),
        KEY idx_rp_a (equipo_a_id, estado),
        KEY idx_rp_b (equipo_b_id, estado)
    ) $opts");
    $log[] = 'Tabla rival_propuestas lista';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rival_peticiones (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tipo          VARCHAR(20) NOT NULL,
        equipo_id     INT NOT NULL,
        equipo_nombre VARCHAR(150) NOT NULL,
        reserva_id    INT UNSIGNED NULL,
        partido_txt   VARCHAR(255) NULL,
        mensaje       VARCHAR(500) NOT NULL,
        estado        VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        respuesta     VARCHAR(255) NULL,
        atendida_por  VARCHAR(80) NULL,
        creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resuelta_en   DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_pet_estado (estado, creado_en),
        KEY idx_pet_equipo (equipo_id, creado_en)
    ) $opts");
    $log[] = 'Tabla rival_peticiones lista';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rival_notif_visto (
        equipo_id  INT NOT NULL,
        ultimo_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        visto_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (equipo_id)
    ) $opts");
    $log[] = 'Tabla rival_notif_visto lista';
    $pdo->exec("CREATE TABLE IF NOT EXISTS rival_jugados (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        grupo_ref       VARCHAR(190) NULL,
        equipo_a_id     INT NOT NULL,
        equipo_a_nombre VARCHAR(150) NOT NULL,
        equipo_b_id     INT NOT NULL,
        equipo_b_nombre VARCHAR(150) NOT NULL,
        par_llave       VARCHAR(40) NOT NULL,
        fecha           DATE NULL,
        origen          VARCHAR(20) NOT NULL DEFAULT 'manual',
        nota            VARCHAR(255) NULL,
        creado_por      VARCHAR(80) NULL,
        creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_rj_par (par_llave),
        KEY idx_rj_a (equipo_a_id),
        KEY idx_rj_b (equipo_b_id)
    ) $opts");
    $log[] = 'Tabla rival_jugados lista';

    if (!rival_columna('reservas_cancha', 'motivo_cancelacion')) {
        $pdo->exec('ALTER TABLE reservas_cancha ADD COLUMN motivo_cancelacion VARCHAR(255) NULL');
        $log[] = 'reservas_cancha: columna motivo_cancelacion agregada';
    }
    if (!rival_columna('reservas_cancha', 'cancelada_en')) {
        $pdo->exec('ALTER TABLE reservas_cancha ADD COLUMN cancelada_en DATETIME NULL');
        $log[] = 'reservas_cancha: columna cancelada_en agregada';
    }
    // Valores nuevos en columnas ENUM (sólo si esas columnas son ENUM).
    $log[] = rival_enum_asegurar('reservas_cancha', 'estado', ['confirmada', 'cancelada']);
    $log[] = rival_enum_asegurar('rival_opciones', 'estado', ['vigente', 'ocupada', 'confirmada', 'reemplazada', 'liberada']);
    $log[] = rival_enum_asegurar('rival_eventos', 'tipo', ['horario_agregado', 'horario_eliminado', 'propuesta_admin', 'propuesta_resp',
                                                             'partido_editado', 'partido_cancelado', 'cancha_hora_cancelada', 'peticion', 'jugado']);
    if (push_instalado()) {
        $log[] = rival_enum_asegurar('push_cola', 'tipo', ['propuesta', 'partido_cambio', 'peticion', 'cancha']);
    }
    return $log;
}

/** rival_evento() que nunca rompe la operación (p. ej. si rival_eventos.tipo no acepta el tipo). */
function rival_evento_seguro(string $tipo, ?string $grupo, ?int $equipo, ?int $otro, ?string $detalle = null): void
{
    try {
        rival_evento($tipo, $grupo, $equipo, $otro, null, null, $detalle);
    } catch (Throwable $ex) {
        error_log('[rival] evento ' . $tipo . ': ' . $ex->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Horarios uno por uno                                                */
/* ------------------------------------------------------------------ */

/** Horarios de una publicación que siguen contando (no vencidos) por día. */
function rival_por_dia_publicacion(int $pubId): array
{
    $st = db()->prepare("SELECT fecha, COUNT(*) n FROM rival_opciones
                          WHERE publicacion_id = ? AND estado IN ('vigente','ocupada','confirmada') AND fecha >= ? GROUP BY fecha");
    $st->execute([$pubId, rival_ahora()->format('Y-m-d')]);
    $o = [];
    foreach ($st->fetchAll() as $r) $o[(string)$r['fecha']] = (int)$r['n'];
    return $o;
}

/**
 * Agrega uno o más horarios. Sin publicación activa, publica (mínimo RIVAL_MIN_HORARIOS).
 * @return int horarios agregados
 */
function rival_agregar_horarios(array $eq, array $claves): int
{
    $claves = array_values(array_unique(array_filter($claves, fn($c) => is_string($c) && trim($c) !== '')));
    if (!rival_mi_publicacion($eq['equipo_id'])) {
        rival_publicar($eq, $claves);
        return count($claves);
    }
    if (!$claves) throw new RivalError('Elige al menos un horario para agregar.');
    $cancha = (int)rival_cancha_id();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pub = rival_mi_publicacion($eq['equipo_id'], true);
        if (!$pub) throw new RivalError('Tu publicación cambió. Vuelve a intentarlo.');
        $st = $pdo->prepare("SELECT fecha, hora FROM rival_opciones WHERE publicacion_id = ? AND estado IN ('vigente','ocupada','confirmada')");
        $st->execute([$pub['id']]);
        $ya = array_map(fn($r) => $r['fecha'] . ' ' . substr($r['hora'], 0, 5), $st->fetchAll());
        $repetidos = array_intersect($claves, $ya);
        if ($repetidos) throw new RivalError('Ya tienes ' . (count($repetidos) === 1 ? 'ese horario' : 'esos horarios') . ': ' . implode(', ', array_map(fn($c) => rival_slot_texto(substr($c, 0, 10), substr($c, 11)), $repetidos)) . '.');
        [$slots, $err] = rival_validar_slots($claves, $cancha, $ya);
        if ($err) throw new RivalError(implode(' ', $err));
        rival_revisar_por_dia($slots, rival_por_dia_publicacion((int)$pub['id']));
        $buscar = $pdo->prepare("SELECT id FROM rival_opciones WHERE publicacion_id = ? AND cancha_id = ? AND fecha = ? AND hora = ? AND estado IN ('liberada','reemplazada') LIMIT 1");
        $revivir = $pdo->prepare("UPDATE rival_opciones SET estado = 'vigente' WHERE id = ?");
        $ins = $pdo->prepare('INSERT INTO rival_opciones (publicacion_id, cancha_id, fecha, hora) VALUES (?, ?, ?, ?)');
        foreach ($slots as $s) {
            $buscar->execute([$pub['id'], $cancha, $s['fecha'], $s['hora']]);
            $viejo = $buscar->fetchColumn();
            if ($viejo) $revivir->execute([$viejo]);
            else $ins->execute([$pub['id'], $cancha, $s['fecha'], $s['hora']]);
        }
        rival_evento_seguro('horario_agregado', $eq['grupo'], $eq['equipo_id'], null, count($slots) . ' horario(s)');
        $pdo->commit();
        return count($slots);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Elimina UN horario. Nunca deja al equipo con menos de RIVAL_MIN_HORARIOS horarios disponibles.
 * Los horarios que ya son partido no se eliminan (se pide reprogramación o cancelación).
 */
function rival_eliminar_horario(array $eq, int $opcionId): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pub = rival_mi_publicacion($eq['equipo_id'], true);
        if (!$pub) throw new RivalError('No tienes horarios publicados.');
        $st = $pdo->prepare('SELECT * FROM rival_opciones WHERE id = ? AND publicacion_id = ? FOR UPDATE');
        $st->execute([$opcionId, $pub['id']]);
        $op = $st->fetch();
        if (!$op || !in_array($op['estado'], ['vigente', 'ocupada'], true)) {
            throw new RivalError($op && $op['estado'] === 'confirmada'
                ? 'Ese horario ya es un partido confirmado. Desde «Partidos» puedes solicitar reprogramación o cancelación.'
                : 'Ese horario ya no está en tu lista.');
        }
        $ef = rival_estado_opcion($op);
        if ($ef === 'vigente') {
            $st = $pdo->prepare("SELECT * FROM rival_opciones WHERE publicacion_id = ? AND estado = 'vigente' AND id <> ?");
            $st->execute([$pub['id'], $opcionId]);
            $otras = count(array_filter($st->fetchAll(), fn($o) => rival_estado_opcion($o) === 'vigente'));
            if ($otras < RIVAL_MIN_HORARIOS) {
                throw new RivalError('Debes tener siempre al menos ' . RIVAL_MIN_HORARIOS . ' horarios disponibles. Agrega otro horario antes de quitar éste.');
            }
        }
        $pdo->prepare("UPDATE rival_opciones SET estado = 'liberada' WHERE id = ?")->execute([$opcionId]);
        $st = $pdo->prepare("SELECT id, equipo_id FROM rival_solicitudes WHERE opcion_id = ? AND estado = 'pendiente'");
        $st->execute([$opcionId]);
        $txt = rival_slot_texto($op['fecha'], $op['hora']);
        foreach ($st->fetchAll() as $s) {
            $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'El equipo quitó ese horario' WHERE id = ?")
                ->execute([$s['id']]);
            push_encolar((int)$s['equipo_id'], 'rechazado', 'Horario retirado',
                $eq['nombre'] . ' quitó su horario del ' . $txt . '. Tu solicitud se anuló; elige otro horario.', '/disponibilidad/rival.php?s=buscar');
        }
        rival_evento_seguro('horario_eliminado', $eq['grupo'], $eq['equipo_id'], null, $op['fecha'] . ' ' . $op['hora']);
        $pdo->commit();
        return $txt;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* ------------------------------------------------------------------ */
/* Reprogramación con varios horarios                                  */
/* ------------------------------------------------------------------ */

/** @return array<int, array{fecha:string, hora:string, clave:string}> */
function rival_reprog_opciones_de(int $reprogId): array
{
    $st = db()->prepare('SELECT fecha, hora FROM rival_reprog_opciones WHERE reprog_id = ? ORDER BY fecha, hora');
    $st->execute([$reprogId]);
    return array_map(fn($r) => ['fecha' => (string)$r['fecha'], 'hora' => (string)$r['hora'], 'clave' => $r['fecha'] . ' ' . substr((string)$r['hora'], 0, 5)], $st->fetchAll());
}

/**
 * (Dentro de una transacción) un horario dejó de estar disponible: se quita de las propuestas de
 * reprogramación pendientes; las que se quedan sin horarios se anulan.
 */
function rival_reprog_quitar_slot(int $cancha, string $fecha, string $hora, ?int $salvo, string $motivo): void
{
    $pdo = db();
    $hora = strlen($hora) === 5 ? $hora . ':00' : $hora;
    $st = $pdo->prepare("SELECT DISTINCT r.* FROM rival_reprogramaciones r LEFT JOIN rival_reprog_opciones o ON o.reprog_id = r.id
                          WHERE r.estado = 'pendiente' AND r.cancha_id = ? AND r.id <> ?
                            AND ((o.fecha = ? AND o.hora = ?) OR (r.fecha_nueva = ? AND r.hora_nueva = ?))");
    $st->execute([$cancha, $salvo ?? 0, $fecha, $hora, $fecha, $hora]);
    foreach ($st->fetchAll() as $rp) {
        $pdo->prepare('DELETE FROM rival_reprog_opciones WHERE reprog_id = ? AND fecha = ? AND hora = ?')->execute([$rp['id'], $fecha, $hora]);
        $resto = rival_reprog_opciones_de((int)$rp['id']);
        if (!$resto) {
            $pdo->prepare("UPDATE rival_reprogramaciones SET estado = 'anulada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = ? WHERE id = ?")
                ->execute([$motivo, $rp['id']]);
        } elseif ($rp['fecha_nueva'] === $fecha && substr((string)$rp['hora_nueva'], 0, 5) === substr($hora, 0, 5)) {
            $pdo->prepare('UPDATE rival_reprogramaciones SET fecha_nueva = ?, hora_nueva = ? WHERE id = ?')->execute([$resto[0]['fecha'], $resto[0]['hora'], $rp['id']]);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Propuestas de la administración                                     */
/* ------------------------------------------------------------------ */

/** Texto del grupo y categoría («primera», «segunda» u «otra») a partir de grupo_ref. */
function rival_grupo_info(string $grupoRef): array
{
    static $S = false;
    if ($S === false) { $d = fin_leer_estado(); $S = $d ? $d['estado'] : null; }
    [$ed, $di, $ra, $ca, $gl] = array_pad(explode('|', $grupoRef), 5, '');
    $nom = fn(string $l, string $id) => $S ? fin_txt((fin_por_id($S[$l] ?? [])[$id] ?? [])['nombre'] ?? '') : '';
    $catNom = $nom('categorias', $ca);
    $k = rival_clave_nombre($catNom);
    return [
        'texto'     => trim($catNom . ($gl !== '' ? ' · Grupo ' . $gl : '') . ' · ' . $nom('disciplinas', $di) . ' ' . $nom('ramas', $ra)),
        'categoria' => $catNom !== '' ? $catNom : 'Sin categoría',
        'grupo'     => $gl,
        'cat'       => strpos($k, 'primera') !== false ? 'primera' : (strpos($k, 'segunda') !== false ? 'segunda' : 'otra'),
    ];
}

/**
 * Posibles partidos: pares de equipos del MISMO grupo con horarios publicados que coinciden,
 * cancha libre, sin partido próximo entre ellos y que NO se hayan elegido entre sí
 * (sin solicitud pendiente ni propuesta pendiente entre ambos).
 * @return array<int, array{grupo_ref:string, a:array, b:array, slots:string[]}>
 */
function rival_generar_propuestas(): array
{
    $pdo = db();
    $hoy = rival_ahora()->format('Y-m-d');
    $reservados = [];
    $st = $pdo->prepare("SELECT slot_llave FROM reservas_cancha WHERE estado = 'confirmada' AND fecha >= ?");
    $st->execute([$hoy]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $reservados[(string)$k] = true;

    // Horarios ya propuestos por la administración y pares con propuesta pendiente.
    $slotsProp = []; $paresProp = [];
    foreach ($pdo->query("SELECT cancha_id, fecha, hora, equipo_a_id, equipo_b_id FROM rival_propuestas WHERE estado = 'pendiente'")->fetchAll() as $p) {
        $slotsProp[rival_slot_llave((int)$p['cancha_id'], $p['fecha'], $p['hora'])] = true;
        $paresProp[min((int)$p['equipo_a_id'], (int)$p['equipo_b_id']) . '-' . max((int)$p['equipo_a_id'], (int)$p['equipo_b_id'])] = true;
    }
    // Pares que ya se eligieron entre sí (solicitud pendiente) o ya tienen partido próximo.
    $ocupadosPar = $paresProp;
    foreach ($pdo->query("SELECT s.equipo_id a, p.equipo_id b FROM rival_solicitudes s JOIN rival_publicaciones p ON p.id = s.publicacion_id WHERE s.estado = 'pendiente'")->fetchAll() as $x) {
        $ocupadosPar[min((int)$x['a'], (int)$x['b']) . '-' . max((int)$x['a'], (int)$x['b'])] = true;
    }
    // Partidos confirmados (próximos o ya jugados) y partidos registrados como «ya jugados»: no se vuelven a generar.
    foreach ($pdo->query("SELECT equipo_a_id a, equipo_b_id b FROM reservas_cancha WHERE estado = 'confirmada'")->fetchAll() as $x) {
        $ocupadosPar[min((int)$x['a'], (int)$x['b']) . '-' . max((int)$x['a'], (int)$x['b'])] = true;
    }
    foreach ($pdo->query('SELECT par_llave FROM rival_jugados')->fetchAll(PDO::FETCH_COLUMN) as $k) $ocupadosPar[(string)$k] = true;

    $porGrupo = [];
    $pubs = $pdo->query("SELECT * FROM rival_publicaciones WHERE estado = 'activa' ORDER BY grupo_ref, equipo_nombre")->fetchAll();
    $stO = $pdo->prepare("SELECT * FROM rival_opciones WHERE publicacion_id = ? AND estado = 'vigente' ORDER BY fecha, hora");
    foreach ($pubs as $p) {
        $stO->execute([$p['id']]);
        $claves = [];
        foreach ($stO->fetchAll() as $o) {
            if (rival_estado_opcion($o, $reservados) !== 'vigente') continue;
            $ll = rival_slot_llave((int)$o['cancha_id'], $o['fecha'], $o['hora']);
            if (isset($slotsProp[$ll])) continue;
            $claves[$o['fecha'] . ' ' . substr($o['hora'], 0, 5)] = true;
        }
        if ($claves) $porGrupo[(string)$p['grupo_ref']][] = ['id' => (int)$p['equipo_id'], 'nombre' => (string)$p['equipo_nombre'], 'claves' => $claves];
    }
    $out = [];
    foreach ($porGrupo as $g => $eqs) {
        $n = count($eqs);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $eqs[$i]; $b = $eqs[$j];
                if (isset($ocupadosPar[min($a['id'], $b['id']) . '-' . max($a['id'], $b['id'])])) continue;
                $comun = array_keys(array_intersect_key($a['claves'], $b['claves']));
                if (!$comun) continue;
                sort($comun);
                $out[] = ['grupo_ref' => $g, 'a' => ['id' => $a['id'], 'nombre' => $a['nombre']], 'b' => ['id' => $b['id'], 'nombre' => $b['nombre']], 'slots' => $comun];
            }
        }
    }
    usort($out, fn($x, $y) => [$x['grupo_ref'], $x['slots'][0]] <=> [$y['grupo_ref'], $y['slots'][0]]);
    return $out;
}

/** La administración propone un partido. Ambos equipos deben confirmar. */
function rival_admin_proponer(int $a, int $b, string $clave, string $por): array
{
    if ($a === $b || $a <= 0 || $b <= 0) throw new RivalError('Elige dos equipos distintos.');
    $cancha = (int)rival_cancha_id();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM canchas WHERE id = ? FOR UPDATE')->execute([$cancha]);
        // Grupo y nombre: de su publicación o, si no publicó horarios (propuesta manual), de Tesorería.
        $datos = function (int $id): array {
            $p = rival_mi_publicacion($id);
            if ($p) return $p;
            $c = rival_contexto_equipo($id, null);
            if (!$c) throw new RivalError('Uno de los equipos no está vinculado con su categoría (Administración → Vínculos).');
            return ['equipo_nombre' => $c['nombre'], 'grupo_ref' => $c['grupo']];
        };
        $pa = $datos($a); $pb = $datos($b);
        if ((string)$pa['grupo_ref'] !== (string)$pb['grupo_ref']) throw new RivalError($pa['equipo_nombre'] . ' y ' . $pb['equipo_nombre'] . ' no son del mismo grupo.');
        if ($j = rival_ya_jugaron($a, $b)) throw new RivalError($pa['equipo_nombre'] . ' y ' . $pb['equipo_nombre'] . ' ya jugaron' . ($j['fecha'] ? ' (' . rival_dia_texto($j['fecha']) . ')' : '') . '. Si fue un error, quítalo del historial.');
        if (rival_hay_partido_entre($a, $b)) throw new RivalError($pa['equipo_nombre'] . ' y ' . $pb['equipo_nombre'] . ' ya tienen un partido próximo.');
        $st = $pdo->prepare("SELECT COUNT(*) FROM rival_propuestas WHERE estado = 'pendiente' AND ((equipo_a_id = ? AND equipo_b_id = ?) OR (equipo_a_id = ? AND equipo_b_id = ?))");
        $st->execute([$a, $b, $b, $a]);
        if ((int)$st->fetchColumn()) throw new RivalError('Ya hay una propuesta pendiente entre ' . $pa['equipo_nombre'] . ' y ' . $pb['equipo_nombre'] . '.');
        [$slots, $err] = rival_validar_slots([$clave], $cancha, [], RIVAL_DIAS_ADMIN);
        if ($err || !$slots) throw new RivalError($err ? implode(' ', $err) : 'Horario no válido.');
        $s = $slots[0];
        $st = $pdo->prepare("SELECT COUNT(*) FROM rival_propuestas WHERE estado = 'pendiente' AND cancha_id = ? AND fecha = ? AND hora = ?");
        $st->execute([$cancha, $s['fecha'], $s['hora']]);
        if ((int)$st->fetchColumn()) throw new RivalError('Ya hay otra propuesta pendiente en el ' . rival_slot_texto($s['fecha'], $s['hora']) . '.');
        $pdo->prepare('INSERT INTO rival_propuestas (grupo_ref, cancha_id, fecha, hora, equipo_a_id, equipo_a_nombre, equipo_b_id, equipo_b_nombre, creado_por)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$pa['grupo_ref'], $cancha, $s['fecha'], $s['hora'], $a, $pa['equipo_nombre'], $b, $pb['equipo_nombre'], mb_substr($por, 0, 80)]);
        $id = (int)$pdo->lastInsertId();
        $txt = rival_slot_texto($s['fecha'], $s['hora']);
        rival_evento_seguro('propuesta_admin', (string)$pa['grupo_ref'], $a, $b, 'propuesta ' . $id . ' · ' . $s['fecha'] . ' ' . $s['hora']);
        foreach ([[$a, $pb['equipo_nombre']], [$b, $pa['equipo_nombre']]] as [$eqId, $rival]) {
            push_encolar($eqId, 'propuesta', 'La liga te propone un partido',
                'Partido propuesto contra ' . $rival . ' el ' . $txt . ' en DICIFO. Confírmalo o recházalo en «Partidos».', '/disponibilidad/rival.php?s=partidos');
        }
        $pdo->commit();
        return ['id' => $id, 'texto' => $pa['equipo_nombre'] . ' vs ' . $pb['equipo_nombre'] . ' · ' . $txt];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** (Dentro de una transacción) anula propuestas pendientes entre dos equipos. */
function rival_propuestas_anular_entre(int $a, int $b, string $motivo): void
{
    db()->prepare("UPDATE rival_propuestas SET estado = 'anulada', resuelta_en = NOW(), motivo = ?
                    WHERE estado = 'pendiente' AND ((equipo_a_id = ? AND equipo_b_id = ?) OR (equipo_a_id = ? AND equipo_b_id = ?))")
        ->execute([$motivo, $a, $b, $b, $a]);
}

/** (Dentro de una transacción) anula propuestas pendientes en un horario y avisa a los equipos. */
function rival_propuestas_anular_slot(int $cancha, string $fecha, string $hora, string $motivo): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM rival_propuestas WHERE estado = 'pendiente' AND cancha_id = ? AND fecha = ? AND hora = ? FOR UPDATE");
    $st->execute([$cancha, $fecha, strlen($hora) === 5 ? $hora . ':00' : $hora]);
    foreach ($st->fetchAll() as $p) {
        $pdo->prepare("UPDATE rival_propuestas SET estado = 'anulada', resuelta_en = NOW(), motivo = ? WHERE id = ?")->execute([$motivo, $p['id']]);
        $txt = rival_slot_texto($p['fecha'], $p['hora']);
        push_encolar((int)$p['equipo_a_id'], 'propuesta', 'Propuesta de partido anulada', 'La propuesta contra ' . $p['equipo_b_nombre'] . ' del ' . $txt . ' se anuló: ' . mb_strtolower($motivo) . '.', '/disponibilidad/rival.php?s=partidos');
        push_encolar((int)$p['equipo_b_id'], 'propuesta', 'Propuesta de partido anulada', 'La propuesta contra ' . $p['equipo_a_nombre'] . ' del ' . $txt . ' se anuló: ' . mb_strtolower($motivo) . '.', '/disponibilidad/rival.php?s=partidos');
    }
}

/** Propuestas de la administración pendientes para el equipo (con su respuesta y la del rival). */
function rival_propuestas_de_equipo(int $yo): array
{
    $st = db()->prepare("SELECT * FROM rival_propuestas WHERE estado = 'pendiente' AND (equipo_a_id = ? OR equipo_b_id = ?) ORDER BY fecha, hora");
    $st->execute([$yo, $yo]);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $soyA = (int)$p['equipo_a_id'] === $yo;
        $p['rival'] = $soyA ? $p['equipo_b_nombre'] : $p['equipo_a_nombre'];
        $p['mi_resp'] = $soyA ? $p['resp_a'] : $p['resp_b'];
        $p['su_resp'] = $soyA ? $p['resp_b'] : $p['resp_a'];
        $out[] = $p;
    }
    return $out;
}

/**
 * Un equipo responde a una propuesta de la administración. Si ambos aceptan, el partido se confirma
 * en una transacción (cancha bloqueada + índice único del horario).
 * @return string 'rechazada' | 'esperando' | 'confirmada'
 */
function rival_propuesta_responder(array $eq, int $id, bool $acepta): string
{
    return rival_reintentar(fn() => rival_propuesta_responder_una_vez($eq, $id, $acepta));
}

function rival_propuesta_responder_una_vez(array $eq, int $id, bool $acepta): string
{
    $pdo = db();
    $yo = $eq['equipo_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM canchas WHERE id = ? FOR UPDATE')->execute([(int)rival_cancha_id()]);
        $st = $pdo->prepare('SELECT * FROM rival_propuestas WHERE id = ? AND (equipo_a_id = ? OR equipo_b_id = ?) FOR UPDATE');
        $st->execute([$id, $yo, $yo]);
        $p = $st->fetch();
        if (!$p) throw new RivalError('Esa propuesta no es para tu equipo.');
        if ($p['estado'] !== 'pendiente') throw new RivalError('Esa propuesta ya no está pendiente' . ($p['motivo'] ? ': ' . mb_strtolower((string)$p['motivo']) : '') . '.');
        $soyA = (int)$p['equipo_a_id'] === $yo;
        $otro = $soyA ? (int)$p['equipo_b_id'] : (int)$p['equipo_a_id'];
        $otroNom = $soyA ? $p['equipo_b_nombre'] : $p['equipo_a_nombre'];
        $txt = rival_slot_texto($p['fecha'], $p['hora']);
        $colMia = $soyA ? 'resp_a' : 'resp_b';
        $suResp = $soyA ? $p['resp_b'] : $p['resp_a'];

        if (!$acepta) {
            $pdo->prepare("UPDATE rival_propuestas SET $colMia = 'rechazada', estado = 'rechazada', resuelta_en = NOW(), motivo = ? WHERE id = ?")
                ->execute(['Rechazada por ' . $eq['nombre'], $id]);
            rival_evento_seguro('propuesta_resp', (string)$p['grupo_ref'], $yo, $otro, 'propuesta ' . $id . ' rechazada');
            push_encolar($otro, 'propuesta', 'Propuesta de partido rechazada', $eq['nombre'] . ' rechazó el partido propuesto del ' . $txt . '.', '/disponibilidad/rival.php?s=partidos');
            $pdo->commit();
            return 'rechazada';
        }
        if ($suResp !== 'aceptada') {
            $pdo->prepare("UPDATE rival_propuestas SET $colMia = 'aceptada' WHERE id = ?")->execute([$id]);
            rival_evento_seguro('propuesta_resp', (string)$p['grupo_ref'], $yo, $otro, 'propuesta ' . $id . ' aceptada por un equipo');
            push_encolar($otro, 'propuesta', 'Falta tu respuesta', $eq['nombre'] . ' ya aceptó el partido propuesto del ' . $txt . '. Confírmalo o recházalo.', '/disponibilidad/rival.php?s=partidos');
            $pdo->commit();
            return 'esperando';
        }

        // Ambos aceptan → validación final y confirmación.
        $anular = function (string $motivo) use ($pdo, $id, $p, $otro, $txt): void {
            $pdo->prepare("UPDATE rival_propuestas SET estado = 'anulada', resuelta_en = NOW(), motivo = ? WHERE id = ?")->execute([$motivo, $id]);
            push_encolar($otro, 'propuesta', 'Propuesta de partido anulada', 'El partido propuesto del ' . $txt . ' no se pudo confirmar: ' . mb_strtolower($motivo) . '.', '/disponibilidad/rival.php?s=partidos');
            $pdo->commit();
        };
        if (rival_hay_partido_entre($yo, $otro)) { $anular('Ya tienen un partido próximo entre ustedes'); throw new RivalError('Ya tienes un partido próximo contra ' . $otroNom . '. La propuesta se anuló.'); }
        $clave = $p['fecha'] . ' ' . substr($p['hora'], 0, 5);
        [$slots, $err] = rival_validar_slots([$clave], (int)$p['cancha_id'], [], RIVAL_DIAS_ADMIN);
        if ($err || !$slots) { $anular('El horario ya no está disponible'); throw new RivalError('Ese horario ya no está disponible (' . implode(' ', $err) . '). La propuesta se anuló.'); }
        $pubA = rival_mi_publicacion((int)$p['equipo_a_id']);
        $llave = rival_slot_llave((int)$p['cancha_id'], $p['fecha'], $p['hora']);
        try {
            $pdo->prepare("INSERT INTO reservas_cancha (cancha_id, fecha, hora, estado, slot_llave, grupo_ref, equipo_a_id, equipo_b_id,
                                                        equipo_a_nombre, equipo_b_nombre, publicacion_id, solicitud_id)
                           VALUES (?, ?, ?, 'confirmada', ?, ?, ?, ?, ?, ?, ?, NULL)")
                ->execute([$p['cancha_id'], $p['fecha'], $p['hora'], $llave, $p['grupo_ref'], $p['equipo_a_id'], $p['equipo_b_id'],
                           $p['equipo_a_nombre'], $p['equipo_b_nombre'], $pubA ? $pubA['id'] : null]);
        } catch (PDOException $ex) {
            if ((int)($ex->errorInfo[1] ?? 0) !== 1062) throw $ex;
            $anular('El horario se ocupó');
            throw new RivalError('Otro partido acaba de tomar ese horario. La propuesta se anuló.');
        }
        $reserva = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE rival_propuestas SET $colMia = 'aceptada', estado = 'confirmada', reserva_id = ?, resuelta_en = NOW(), motivo = 'Confirmada por ambos equipos' WHERE id = ?")
            ->execute([$reserva, $id]);
        // Los horarios de ambos equipos en ese momento quedan como partido; solicitudes a esos horarios se cancelan.
        $st = $pdo->prepare("SELECT o.id FROM rival_opciones o JOIN rival_publicaciones pu ON pu.id = o.publicacion_id
                              WHERE pu.equipo_id IN (?, ?) AND pu.estado = 'activa' AND o.cancha_id = ? AND o.fecha = ? AND o.hora = ? AND o.estado = 'vigente'");
        $st->execute([$p['equipo_a_id'], $p['equipo_b_id'], $p['cancha_id'], $p['fecha'], $p['hora']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $pdo->prepare("UPDATE rival_opciones SET estado = 'confirmada' WHERE id = ?")->execute([$oid]);
            $pdo->prepare("UPDATE rival_solicitudes SET estado = 'cancelada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'Ese horario se confirmó con otro equipo'
                            WHERE opcion_id = ? AND estado = 'pendiente'")->execute([$oid]);
        }
        $pdo->prepare("UPDATE rival_solicitudes s JOIN rival_publicaciones pu ON pu.id = s.publicacion_id
                          SET s.estado = 'cancelada', s.pendiente_llave = NULL, s.pendiente_par = NULL, s.resuelta_en = NOW(), s.motivo = 'Ya tienen un partido confirmado entre ustedes'
                        WHERE s.estado = 'pendiente' AND ((s.equipo_id = ? AND pu.equipo_id = ?) OR (s.equipo_id = ? AND pu.equipo_id = ?))")
            ->execute([$p['equipo_a_id'], $p['equipo_b_id'], $p['equipo_b_id'], $p['equipo_a_id']]);
        rival_propuestas_anular_entre((int)$p['equipo_a_id'], (int)$p['equipo_b_id'], 'Ya confirmaron un partido entre ustedes');
        rival_ocupar_slot((int)$p['cancha_id'], $p['fecha'], $p['hora'], null);
        rival_evento_seguro('propuesta_resp', (string)$p['grupo_ref'], $yo, $otro, 'propuesta ' . $id . ' confirmada · reserva ' . $reserva);
        push_encolar($otro, 'confirmado', '¡Partido confirmado!', $p['equipo_a_nombre'] . ' vs ' . $p['equipo_b_nombre'] . ', ' . $txt . ' en DICIFO (propuesta de la liga).', '/disponibilidad/rival.php?s=partidos');
        $pdo->commit();
        return 'confirmada';
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function rival_admin_cancelar_propuesta(int $id, string $por): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM rival_propuestas WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    $st = $pdo->prepare("UPDATE rival_propuestas SET estado = 'cancelada', resuelta_en = NOW(), motivo = ? WHERE id = ? AND estado = 'pendiente'");
    $st->execute(['Retirada por la administración', $id]);
    if (!$st->rowCount() || !$p) throw new RivalError('Esa propuesta ya no está pendiente.');
    $txt = rival_slot_texto($p['fecha'], $p['hora']);
    push_encolar((int)$p['equipo_a_id'], 'propuesta', 'Propuesta retirada', 'La liga retiró el partido propuesto contra ' . $p['equipo_b_nombre'] . ' del ' . $txt . '.', '/disponibilidad/rival.php?s=partidos');
    push_encolar((int)$p['equipo_b_id'], 'propuesta', 'Propuesta retirada', 'La liga retiró el partido propuesto contra ' . $p['equipo_a_nombre'] . ' del ' . $txt . '.', '/disponibilidad/rival.php?s=partidos');
    rival_evento_seguro('propuesta_admin', (string)$p['grupo_ref'], (int)$p['equipo_a_id'], (int)$p['equipo_b_id'], 'propuesta ' . $id . ' retirada por ' . $por);
}

/* ------------------------------------------------------------------ */
/* Partidos: administración                                            */
/* ------------------------------------------------------------------ */

/**
 * Cancela (elimina de la agenda) un partido confirmado y avisa a los dos equipos.
 * Si ya hay una transacción abierta, trabaja dentro de ella.
 */
function rival_admin_cancelar_partido(int $reservaId, string $motivo, string $por, string $titulo = 'Partido cancelado', string $tipo = 'partido_cambio'): array
{
    $pdo = db();
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM reservas_cancha WHERE id = ? AND estado = 'confirmada' FOR UPDATE");
        $st->execute([$reservaId]);
        $r = $st->fetch();
        if (!$r) throw new RivalError('Ese partido ya no está confirmado.');
        $motivo = trim($motivo) !== '' ? trim($motivo) : 'Cancelado por la administración';
        $pdo->prepare("UPDATE reservas_cancha SET estado = 'cancelada', slot_llave = ?, motivo_cancelacion = ?, cancelada_en = NOW() WHERE id = ?")
            ->execute(['x' . $reservaId, mb_substr($motivo, 0, 255), $reservaId]);
        // Los horarios de los equipos que se habían vuelto este partido quedan libres (se pueden volver a agregar).
        $pdo->prepare("UPDATE rival_opciones o JOIN rival_publicaciones p ON p.id = o.publicacion_id SET o.estado = 'liberada'
                        WHERE p.equipo_id IN (?, ?) AND o.cancha_id = ? AND o.fecha = ? AND o.hora = ? AND o.estado = 'confirmada'")
            ->execute([$r['equipo_a_id'], $r['equipo_b_id'], $r['cancha_id'], $r['fecha'], $r['hora']]);
        if ($r['solicitud_id'] !== null) {
            $pdo->prepare("UPDATE rival_opciones o JOIN rival_solicitudes s ON s.opcion_id = o.id SET o.estado = 'liberada' WHERE s.id = ? AND o.estado = 'confirmada'")
                ->execute([$r['solicitud_id']]);
        }
        // Partido propuesto por la liga (y quizá movido después): sus horarios originales.
        $pdo->prepare("UPDATE rival_opciones o JOIN rival_publicaciones p ON p.id = o.publicacion_id
                         JOIN rival_propuestas rp ON rp.reserva_id = ? AND rp.cancha_id = o.cancha_id AND rp.fecha = o.fecha AND rp.hora = o.hora
                          SET o.estado = 'liberada'
                        WHERE p.equipo_id IN (?, ?) AND o.estado = 'confirmada'")
            ->execute([$reservaId, $r['equipo_a_id'], $r['equipo_b_id']]);
        $pdo->prepare("UPDATE rival_reprogramaciones SET estado = 'anulada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = 'El partido se canceló' WHERE reserva_id = ? AND estado = 'pendiente'")
            ->execute([$reservaId]);
        $pdo->prepare("UPDATE rival_peticiones SET estado = 'atendida', resuelta_en = NOW(), respuesta = COALESCE(respuesta, 'El partido se canceló'), atendida_por = COALESCE(atendida_por, ?)
                        WHERE reserva_id = ? AND tipo = 'cancelacion' AND estado = 'pendiente'")->execute([mb_substr($por, 0, 80), $reservaId]);
        rival_liberar_slot((int)$r['cancha_id'], $r['fecha'], $r['hora']);
        $txt = rival_slot_texto($r['fecha'], $r['hora']);
        push_encolar((int)$r['equipo_a_id'], $tipo, $titulo, 'Tu partido contra ' . $r['equipo_b_nombre'] . ' del ' . $txt . ': ' . $motivo . '.', '/disponibilidad/rival.php?s=partidos');
        push_encolar((int)$r['equipo_b_id'], $tipo, $titulo, 'Tu partido contra ' . $r['equipo_a_nombre'] . ' del ' . $txt . ': ' . $motivo . '.', '/disponibilidad/rival.php?s=partidos');
        rival_evento_seguro('partido_cancelado', (string)$r['grupo_ref'], (int)$r['equipo_a_id'], (int)$r['equipo_b_id'], 'reserva ' . $reservaId . ' · ' . $por . ' · ' . $motivo);
        if ($propia) $pdo->commit();
        return $r;
    } catch (Throwable $ex) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** Mueve un partido confirmado a otro horario libre de la cancha y avisa a los equipos. */
function rival_admin_editar_partido(int $reservaId, string $clave, string $por): array
{
    return rival_reintentar(function () use ($reservaId, $clave, $por) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM canchas WHERE id = ? FOR UPDATE')->execute([(int)rival_cancha_id()]);
            $st = $pdo->prepare("SELECT * FROM reservas_cancha WHERE id = ? AND estado = 'confirmada' FOR UPDATE");
            $st->execute([$reservaId]);
            $r = $st->fetch();
            if (!$r) throw new RivalError('Ese partido ya no está confirmado.');
            if ($clave === $r['fecha'] . ' ' . substr($r['hora'], 0, 5)) throw new RivalError('Elige un horario distinto al actual.');
            [$slots, $err] = rival_validar_slots([$clave], (int)$r['cancha_id'], [], RIVAL_DIAS_ADMIN);
            if ($err || !$slots) throw new RivalError($err ? implode(' ', $err) : 'Horario no válido.');
            $s = $slots[0];
            try {
                $pdo->prepare('UPDATE reservas_cancha SET fecha = ?, hora = ?, slot_llave = ? WHERE id = ?')
                    ->execute([$s['fecha'], $s['hora'], rival_slot_llave((int)$r['cancha_id'], $s['fecha'], $s['hora']), $reservaId]);
            } catch (PDOException $ex) {
                if ((int)($ex->errorInfo[1] ?? 0) === 1062) throw new RivalError('Ese horario ya está ocupado por otro partido.');
                throw $ex;
            }
            $pdo->prepare("UPDATE rival_reprogramaciones SET estado = 'anulada', pendiente_llave = NULL, resuelta_en = NOW(), respuesta = 'La administración cambió el horario'
                            WHERE reserva_id = ? AND estado = 'pendiente'")->execute([$reservaId]);
            rival_liberar_slot((int)$r['cancha_id'], $r['fecha'], $r['hora']);
            rival_ocupar_slot((int)$r['cancha_id'], $s['fecha'], $s['hora'], null);
            $antes = rival_slot_texto($r['fecha'], $r['hora']);
            $ahora = rival_slot_texto($s['fecha'], $s['hora']);
            push_encolar((int)$r['equipo_a_id'], 'partido_cambio', 'Partido cambiado de horario', 'La liga movió tu partido contra ' . $r['equipo_b_nombre'] . ' del ' . $antes . ' al ' . $ahora . ' en DICIFO.', '/disponibilidad/rival.php?s=partidos');
            push_encolar((int)$r['equipo_b_id'], 'partido_cambio', 'Partido cambiado de horario', 'La liga movió tu partido contra ' . $r['equipo_a_nombre'] . ' del ' . $antes . ' al ' . $ahora . ' en DICIFO.', '/disponibilidad/rival.php?s=partidos');
            rival_evento_seguro('partido_editado', (string)$r['grupo_ref'], (int)$r['equipo_a_id'], (int)$r['equipo_b_id'], 'reserva ' . $reservaId . ' · ' . $por . ' · ' . $r['fecha'] . ' ' . $r['hora'] . ' → ' . $s['fecha'] . ' ' . $s['hora']);
            $pdo->commit();
            return ['partido' => $r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'], 'antes' => $antes, 'ahora' => $ahora];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
    });
}

/**
 * Cancela UNA hora disponible de la cancha (excepción «no disponible» de 1 hora). Si había un partido
 * confirmado, se cancela como «pospuesto por disponibilidad de cancha» y se avisa a los dos equipos;
 * también se avisa a los equipos que tenían ese horario publicado.
 * @return array{partido:?string, avisados:int}
 */
function rival_cancelar_hora_cancha(int $canchaId, string $clave, string $motivo, string $por): array
{
    $sem = obtener_semestre_activo();
    if (!$sem) throw new RivalError('No hay un semestre activo.');
    if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $clave, $m)) throw new RivalError('Elige la hora a cancelar.');
    [$fecha, $hora] = [$m[1], $m[2]];
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $clave, new DateTimeZone(RIVAL_TZ));
    if (!$d || $d->format('Y-m-d H:i') !== $clave) throw new RivalError('Fecha no válida.');
    if ($d <= rival_ahora()) throw new RivalError('Esa hora ya pasó.');
    if (!in_array($hora, rival_horas_de($d, $canchaId), true)) throw new RivalError('Esa hora no está dentro del horario disponible de la cancha.');
    $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
    if (mb_strlen($motivo) > 200) throw new RivalError('El motivo es muy largo (máximo 200 caracteres).');
    $ocup = rival_ocupados($canchaId, $fecha, $fecha);
    if (($ocup[$clave] ?? '') === 'cerrado') throw new RivalError('Esa hora ya estaba cancelada.');
    $finMin = min(rival_min($hora) + RIVAL_DURACION_MIN, 23 * 60 + 59);
    $fin = sprintf('%02d:%02d', intdiv($finMin, 60), $finMin % 60);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM canchas WHERE id = ? FOR UPDATE')->execute([$canchaId]);
        $pdo->prepare("INSERT INTO excepciones_cancha (cancha_id, semestre_id, fecha, tipo, hora_inicio, hora_fin, motivo) VALUES (?, ?, ?, 'no_disponible', ?, ?, ?)")
            ->execute([$canchaId, (int)$sem['id'], $fecha, $hora . ':00', $fin . ':00', mb_substr('Hora cancelada' . ($motivo !== '' ? ': ' . $motivo : '') . ' (' . $por . ')', 0, 255)]);
        $txt = rival_slot_texto($fecha, $hora);
        $razon = 'pospuesto por disponibilidad de cancha' . ($motivo !== '' ? ' (' . $motivo . ')' : '');
        $partido = null;
        $st = $pdo->prepare("SELECT id, equipo_a_nombre, equipo_b_nombre FROM reservas_cancha WHERE cancha_id = ? AND fecha = ? AND hora = ? AND estado = 'confirmada'");
        $st->execute([$canchaId, $fecha, $hora . ':00']);
        if ($r = $st->fetch()) {
            rival_admin_cancelar_partido((int)$r['id'], 'Partido ' . $razon . '. Pueden acordar un nuevo horario', $por, 'Partido pospuesto', 'cancha');
            $partido = $r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'];
        }
        // Equipos con ese horario publicado.
        $st = $pdo->prepare("SELECT o.id, p.equipo_id FROM rival_opciones o JOIN rival_publicaciones p ON p.id = o.publicacion_id
                              WHERE o.cancha_id = ? AND o.fecha = ? AND o.hora = ? AND o.estado IN ('vigente','ocupada') AND p.estado = 'activa'");
        $st->execute([$canchaId, $fecha, $hora . ':00']);
        $avisados = 0;
        foreach ($st->fetchAll() as $o) {
            $pdo->prepare("UPDATE rival_solicitudes SET estado = 'anulada', pendiente_llave = NULL, pendiente_par = NULL, resuelta_en = NOW(), motivo = 'La cancha no está disponible a esa hora'
                            WHERE opcion_id = ? AND estado = 'pendiente'")->execute([$o['id']]);
            push_encolar((int)$o['equipo_id'], 'cancha', 'Horario sin cancha', 'DICIFO no estará disponible el ' . $txt . ($motivo !== '' ? ' (' . $motivo . ')' : '') . '. Cambia ese horario en «Horarios».', '/disponibilidad/rival.php?s=horarios');
            $avisados++;
        }
        rival_reprog_quitar_slot($canchaId, $fecha, $hora, null, 'La cancha no está disponible a esa hora');
        rival_propuestas_anular_slot($canchaId, $fecha, $hora, 'La cancha no está disponible a esa hora');
        rival_evento_seguro('cancha_hora_cancelada', null, null, null, $fecha . ' ' . $hora . ' · ' . $por . ($motivo !== '' ? ' · ' . $motivo : ''));
        $pdo->commit();
        return ['partido' => $partido, 'avisados' => $avisados];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* ------------------------------------------------------------------ */
/* Peticiones a la administración (cancelación · reportar problema)    */
/* ------------------------------------------------------------------ */

const RIVAL_PETICION_TIPOS = ['cancelacion' => 'Solicitud de cancelación', 'problema' => 'Reporte de problema'];

function rival_peticion_crear(array $eq, string $tipo, int $reservaId, string $mensaje): void
{
    if (!isset(RIVAL_PETICION_TIPOS[$tipo])) throw new RivalError('Tipo de solicitud no válido.');
    $mensaje = trim(preg_replace('/\s+/u', ' ', $mensaje) ?? '');
    if (mb_strlen($mensaje) < 5) throw new RivalError($tipo === 'cancelacion' ? 'Escribe el motivo de la cancelación.' : 'Describe el problema.');
    if (mb_strlen($mensaje) > 500) throw new RivalError('El mensaje es muy largo (máximo 500 caracteres).');
    $r = null;
    if ($reservaId > 0) {
        $r = rival_mi_partido($eq['equipo_id'], $reservaId);
        if (!$r) throw new RivalError('Ese partido no es de tu equipo o ya no está confirmado.');
    }
    if ($tipo === 'cancelacion') {
        if (!$r) throw new RivalError('Elige el partido que quieres cancelar.');
        if (rival_ya_empezo($r['fecha'], $r['hora'])) throw new RivalError('Ese partido ya empezó o ya pasó.');
        $st = db()->prepare("SELECT COUNT(*) FROM rival_peticiones WHERE reserva_id = ? AND tipo = 'cancelacion' AND estado = 'pendiente'");
        $st->execute([$reservaId]);
        if ((int)$st->fetchColumn()) throw new RivalError('Ya hay una solicitud de cancelación pendiente para ese partido.');
    }
    $st = db()->prepare("SELECT COUNT(*) FROM rival_peticiones WHERE equipo_id = ? AND creado_en > NOW() - INTERVAL 1 DAY");
    $st->execute([$eq['equipo_id']]);
    if ((int)$st->fetchColumn() >= 10) throw new RivalError('Enviaste muchas solicitudes hoy. Espera la respuesta de la administración.');
    $partidoTxt = $r ? ($r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'] . ' · ' . rival_slot_texto($r['fecha'], $r['hora'])) : null;
    db()->prepare('INSERT INTO rival_peticiones (tipo, equipo_id, equipo_nombre, reserva_id, partido_txt, mensaje) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$tipo, $eq['equipo_id'], mb_substr($eq['nombre'], 0, 150), $r ? $reservaId : null, $partidoTxt, $mensaje]);
    rival_evento_seguro('peticion', $eq['grupo'], $eq['equipo_id'], null, $tipo . ($r ? ' · reserva ' . $reservaId : ''));
    if ($tipo === 'cancelacion' && $r) {
        $otro = (int)$r['equipo_a_id'] === $eq['equipo_id'] ? (int)$r['equipo_b_id'] : (int)$r['equipo_a_id'];
        push_encolar($otro, 'peticion', 'Solicitud de cancelación', $eq['nombre'] . ' pidió a la administración cancelar su partido del ' . rival_slot_texto($r['fecha'], $r['hora']) . '. El partido sigue en pie hasta que la liga responda.', '/disponibilidad/rival.php?s=partidos');
    }
}

function rival_peticiones_de_equipo(int $yo, int $limite = 10): array
{
    $st = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND, creado_en, NOW()) AS hace_s FROM rival_peticiones WHERE equipo_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limite)));
    $st->execute([$yo]);
    return $st->fetchAll();
}

/** La administración resuelve una petición: aprobar (cancela el partido), rechazar o marcar atendida. */
function rival_admin_resolver_peticion(int $id, string $accion, string $respuesta, string $por): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM rival_peticiones WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p || $p['estado'] !== 'pendiente') throw new RivalError('Esa solicitud ya fue atendida.');
    $respuesta = trim(preg_replace('/\s+/u', ' ', $respuesta) ?? '');
    if (mb_strlen($respuesta) > 250) throw new RivalError('La respuesta es muy larga (máximo 250 caracteres).');
    $pdo->beginTransaction();
    try {
        if ($accion === 'aprobar') {
            if ($p['tipo'] !== 'cancelacion' || !$p['reserva_id']) throw new RivalError('Sólo se aprueban solicitudes de cancelación.');
            $pdo->prepare("UPDATE rival_peticiones SET estado = 'aprobada', respuesta = ?, atendida_por = ?, resuelta_en = NOW() WHERE id = ?")
                ->execute([$respuesta !== '' ? $respuesta : 'Cancelación aprobada', mb_substr($por, 0, 80), $id]);
            rival_admin_cancelar_partido((int)$p['reserva_id'], 'cancelado por la administración a solicitud de ' . $p['equipo_nombre'] . ($respuesta !== '' ? ' (' . $respuesta . ')' : ''), $por);
            $res = 'aprobada';
        } elseif ($accion === 'rechazar' || $accion === 'atender') {
            $estado = $accion === 'rechazar' ? 'rechazada' : 'atendida';
            if ($accion === 'rechazar' && $respuesta === '') throw new RivalError('Escribe la razón para rechazar.');
            $pdo->prepare('UPDATE rival_peticiones SET estado = ?, respuesta = ?, atendida_por = ?, resuelta_en = NOW() WHERE id = ?')
                ->execute([$estado, $respuesta !== '' ? $respuesta : null, mb_substr($por, 0, 80), $id]);
            $titulo = $p['tipo'] === 'cancelacion' ? ($estado === 'rechazada' ? 'Cancelación rechazada' : 'Solicitud atendida') : 'Tu reporte fue atendido';
            $cuerpo = ($p['tipo'] === 'cancelacion'
                    ? ($estado === 'rechazada' ? 'La liga no aprobó cancelar el partido (' . $p['partido_txt'] . '). El partido sigue en pie.' : 'La liga atendió tu solicitud sobre ' . $p['partido_txt'] . '.')
                    : 'La administración revisó tu reporte.')
                . ($respuesta !== '' ? ' Respuesta: ' . $respuesta : '');
            push_encolar((int)$p['equipo_id'], 'peticion', $titulo, $cuerpo, '/disponibilidad/rival.php?s=partidos');
            $res = $estado;
        } else {
            throw new RivalError('Acción no válida.');
        }
        if ($res === 'aprobada') {
            push_encolar((int)$p['equipo_id'], 'peticion', 'Cancelación aprobada', 'La liga aprobó cancelar el partido (' . $p['partido_txt'] . ').' . ($respuesta !== '' ? ' ' . $respuesta : ''), '/disponibilidad/rival.php?s=partidos');
        }
        $pdo->commit();
        return $res;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/* ------------------------------------------------------------------ */
/* Notificaciones dentro de la app                                     */
/* ------------------------------------------------------------------ */

function rival_notificaciones(int $yo, int $limite = 40): array
{
    if (!push_instalado()) return [];
    $st = db()->prepare("SELECT id, tipo, titulo, cuerpo, url, creado_en, TIMESTAMPDIFF(SECOND, creado_en, NOW()) AS hace_s FROM push_cola WHERE equipo_id = ? AND tipo <> 'prueba' ORDER BY id DESC LIMIT " . max(1, min(100, $limite)));
    $st->execute([$yo]);
    return $st->fetchAll();
}

function rival_notif_ultimo_visto(int $yo): int
{
    $st = db()->prepare('SELECT ultimo_id FROM rival_notif_visto WHERE equipo_id = ?');
    $st->execute([$yo]);
    return (int)$st->fetchColumn();
}

function rival_notif_no_vistas(int $yo): int
{
    if (!push_instalado()) return 0;
    $st = db()->prepare("SELECT COUNT(*) FROM push_cola WHERE equipo_id = ? AND tipo <> 'prueba' AND id > ?");
    $st->execute([$yo, rival_notif_ultimo_visto($yo)]);
    return (int)$st->fetchColumn();
}

function rival_notif_marcar_vistas(int $yo): void
{
    if (!push_instalado()) return;
    $st = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM push_cola WHERE equipo_id = ?');
    $st->execute([$yo]);
    $max = (int)$st->fetchColumn();
    db()->prepare('INSERT INTO rival_notif_visto (equipo_id, ultimo_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(ultimo_id, VALUES(ultimo_id))')
        ->execute([$yo, $max]);
}

/* ------------------------------------------------------------------ */
/* Lectura para la pantalla del capitán                                */
/* ------------------------------------------------------------------ */

/** Lo nuevo que ve el capitán: propuestas, partidos jugados y cancelados, peticiones y avisos. */
function rival_panel_extra(array $eq): array
{
    $yo = $eq['equipo_id'];
    $ahora = rival_ahora();
    $st = db()->prepare("SELECT * FROM reservas_cancha WHERE estado = 'confirmada' AND fecha <= ? AND (equipo_a_id = ? OR equipo_b_id = ?) ORDER BY fecha DESC, hora DESC LIMIT 40");
    $st->execute([$ahora->format('Y-m-d'), $yo, $yo]);
    $jugados = [];
    foreach ($st->fetchAll() as $r) {
        if (!rival_ya_empezo($r['fecha'], $r['hora'])) continue;
        $r['rival'] = (int)$r['equipo_a_id'] === $yo ? $r['equipo_b_nombre'] : $r['equipo_a_nombre'];
        $jugados[] = $r;
        if (count($jugados) >= 20) break;
    }
    $st = db()->prepare("SELECT * FROM reservas_cancha WHERE estado = 'cancelada' AND cancelada_en >= NOW() - INTERVAL 7 DAY AND (equipo_a_id = ? OR equipo_b_id = ?) ORDER BY cancelada_en DESC LIMIT 5");
    $st->execute([$yo, $yo]);
    $cancelados = [];
    foreach ($st->fetchAll() as $r) { $r['rival'] = (int)$r['equipo_a_id'] === $yo ? $r['equipo_b_nombre'] : $r['equipo_a_nombre']; $cancelados[] = $r; }
    $peticiones = rival_peticiones_de_equipo($yo, 10);
    $petPend = [];
    foreach ($peticiones as $p) if ($p['estado'] === 'pendiente' && $p['reserva_id']) $petPend[(int)$p['reserva_id']][$p['tipo']] = true;
    return [
        'propuestas' => rival_propuestas_de_equipo($yo),
        'jugados'    => $jugados,
        'cancelados' => $cancelados,
        'peticiones' => $peticiones,
        'petPend'    => $petPend,
        'noVistas'   => rival_notif_no_vistas($yo),
        'historial'  => rival_jugados_de_equipo($yo),
        'yaJugo'     => rival_ya_jugo_con($yo),
    ];
}

/** Parte nueva de la huella para la actualización automática. */
function rival_version_extra(array $eq): string
{
    $yo = $eq['equipo_id'];
    $st = db()->prepare("SELECT
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(actualizado_en),'')) FROM rival_propuestas WHERE equipo_a_id = ? OR equipo_b_id = ?),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(resuelta_en),'')) FROM rival_peticiones WHERE equipo_id = ?),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(cancelada_en),'')) FROM reservas_cancha WHERE estado = 'cancelada' AND (equipo_a_id = ? OR equipo_b_id = ?)),
        (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0)) FROM rival_jugados WHERE equipo_a_id = ? OR equipo_b_id = ?)");
    $st->execute([$yo, $yo, $yo, $yo, $yo, $yo, $yo]);
    $v = implode('|', $st->fetch(PDO::FETCH_NUM));
    if (push_instalado()) {
        $st = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM push_cola WHERE equipo_id = ?');
        $st->execute([$yo]);
        $v .= '|' . (int)$st->fetchColumn();
    }
    return $v;
}

/* ------------------------------------------------------------------ */
/* Partidos ya jugados (historial) · propuesta manual                  */
/* ------------------------------------------------------------------ */

function rival_par_llave(int $a, int $b): string
{
    return min($a, $b) . '-' . max($a, $b);
}

/**
 * ¿Ya jugaron entre sí? (registrado como «ya jugado» o partido confirmado que ya empezó).
 * @return array{fecha:?string, origen:string}|null
 */
function rival_ya_jugaron(int $a, int $b): ?array
{
    if (!rival_v8_instalado()) return null;
    $st = db()->prepare('SELECT fecha, origen FROM rival_jugados WHERE par_llave = ? ORDER BY id DESC LIMIT 1');
    $st->execute([rival_par_llave($a, $b)]);
    if ($r = $st->fetch()) return ['fecha' => $r['fecha'] !== null ? (string)$r['fecha'] : null, 'origen' => (string)$r['origen']];
    $st = db()->prepare("SELECT fecha, hora FROM reservas_cancha WHERE estado = 'confirmada' AND fecha <= ?
                          AND ((equipo_a_id = ? AND equipo_b_id = ?) OR (equipo_a_id = ? AND equipo_b_id = ?)) ORDER BY fecha DESC, hora DESC");
    $st->execute([rival_ahora()->format('Y-m-d'), $a, $b, $b, $a]);
    foreach ($st->fetchAll() as $r) {
        if (rival_ya_empezo($r['fecha'], $r['hora'])) return ['fecha' => (string)$r['fecha'], 'origen' => 'dicifo'];
    }
    return null;
}

/** Ids de los rivales con los que el equipo ya jugó. */
function rival_ya_jugo_con(int $yo): array
{
    $o = [];
    if (!rival_v8_instalado()) return $o;
    $st = db()->prepare('SELECT equipo_a_id, equipo_b_id, fecha FROM rival_jugados WHERE equipo_a_id = ? OR equipo_b_id = ?');
    $st->execute([$yo, $yo]);
    foreach ($st->fetchAll() as $r) $o[(int)$r['equipo_a_id'] === $yo ? (int)$r['equipo_b_id'] : (int)$r['equipo_a_id']] = $r['fecha'];
    $st = db()->prepare("SELECT equipo_a_id, equipo_b_id, fecha, hora FROM reservas_cancha WHERE estado = 'confirmada' AND fecha <= ? AND (equipo_a_id = ? OR equipo_b_id = ?)");
    $st->execute([rival_ahora()->format('Y-m-d'), $yo, $yo]);
    foreach ($st->fetchAll() as $r) {
        if (rival_ya_empezo($r['fecha'], $r['hora'])) $o[(int)$r['equipo_a_id'] === $yo ? (int)$r['equipo_b_id'] : (int)$r['equipo_a_id']] = $r['fecha'];
    }
    return $o;
}

/**
 * Equipos activos con su grupo (para elegir en la administración), agrupados.
 * @return array<string, array{texto:string, cat:string, equipos: array<int, array{id:int, nombre:string}>}>
 */
function rival_equipos_por_grupo(): array
{
    $out = [];
    foreach (listar_equipos() as $e) {
        $c = rival_contexto_equipo((int)$e['id'], null);
        if (!$c) continue;
        if (!isset($out[$c['grupo']])) {
            $gi = rival_grupo_info($c['grupo']);
            $out[$c['grupo']] = ['texto' => $gi['texto'], 'cat' => $gi['cat'], 'equipos' => []];
        }
        $out[$c['grupo']]['equipos'][] = ['id' => (int)$e['id'], 'nombre' => $c['nombre'] !== '' ? $c['nombre'] : (string)$e['nombre']];
    }
    uasort($out, fn($x, $y) => [$x['cat'], $x['texto']] <=> [$y['cat'], $y['texto']]);
    return $out;
}

/**
 * Registra un partido YA JUGADO entre dos equipos del mismo grupo: queda en el historial de ambos,
 * «Generar propuestas» ya no los vuelve a juntar y se cierran solicitudes/propuestas pendientes entre ellos.
 */
function rival_admin_registrar_jugado(int $a, int $b, string $fecha, string $nota, string $por, string $origen = 'manual'): string
{
    if ($a === $b || $a <= 0 || $b <= 0) throw new RivalError('Elige dos equipos distintos.');
    $fecha = trim($fecha);
    if ($fecha !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone(RIVAL_TZ));
        if (!$d || $d->format('Y-m-d') !== $fecha) throw new RivalError('La fecha no es válida.');
        if ($d > rival_ahora()) throw new RivalError('La fecha del partido jugado no puede ser futura.');
    }
    $nota = trim(preg_replace('/\s+/u', ' ', $nota) ?? '');
    if (mb_strlen($nota) > 200) throw new RivalError('La nota es muy larga (máximo 200 caracteres).');
    $ca = rival_contexto_equipo($a, null); $cb = rival_contexto_equipo($b, null);
    if (!$ca || !$cb) throw new RivalError('Uno de los equipos no está vinculado con su categoría (Administración → Vínculos).');
    if ($ca['grupo'] !== $cb['grupo']) throw new RivalError($ca['nombre'] . ' y ' . $cb['nombre'] . ' no son del mismo grupo.');
    $st = db()->prepare('SELECT COUNT(*) FROM rival_jugados WHERE par_llave = ?');
    $st->execute([rival_par_llave($a, $b)]);
    if ((int)$st->fetchColumn()) throw new RivalError($ca['nombre'] . ' vs ' . $cb['nombre'] . ' ya estaba registrado como jugado.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO rival_jugados (grupo_ref, equipo_a_id, equipo_a_nombre, equipo_b_id, equipo_b_nombre, par_llave, fecha, origen, nota, creado_por)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ca['grupo'], $a, mb_substr($ca['nombre'], 0, 150), $b, mb_substr($cb['nombre'], 0, 150), rival_par_llave($a, $b),
                       $fecha !== '' ? $fecha : null, $origen, $nota !== '' ? $nota : null, mb_substr($por, 0, 80)]);
        // Solicitudes y propuestas pendientes entre ellos ya no proceden.
        $pdo->prepare("UPDATE rival_solicitudes s JOIN rival_publicaciones p ON p.id = s.publicacion_id
                          SET s.estado = 'cancelada', s.pendiente_llave = NULL, s.pendiente_par = NULL, s.resuelta_en = NOW(), s.motivo = 'Registrado como ya jugado'
                        WHERE s.estado = 'pendiente' AND ((s.equipo_id = ? AND p.equipo_id = ?) OR (s.equipo_id = ? AND p.equipo_id = ?))")
            ->execute([$a, $b, $b, $a]);
        rival_propuestas_anular_entre($a, $b, 'Registrado como ya jugado');
        $txt = $fecha !== '' ? ' (' . rival_dia_texto($fecha) . ')' : '';
        push_encolar($a, 'partido_cambio', 'Partido registrado como jugado', 'La liga registró tu partido contra ' . $cb['nombre'] . $txt . ' como ya jugado. Queda en tu historial.', '/disponibilidad/rival.php?s=partidos');
        push_encolar($b, 'partido_cambio', 'Partido registrado como jugado', 'La liga registró tu partido contra ' . $ca['nombre'] . $txt . ' como ya jugado. Queda en tu historial.', '/disponibilidad/rival.php?s=partidos');
        rival_evento_seguro('jugado', $ca['grupo'], $a, $b, $origen . ' · ' . $por . ($fecha !== '' ? ' · ' . $fecha : ''));
        $pdo->commit();
        return $ca['nombre'] . ' vs ' . $cb['nombre'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** «Solicitudes entre equipos» → clasificar como ya jugado. */
function rival_admin_solicitud_jugada(int $solId, string $fecha, string $por): string
{
    $st = db()->prepare("SELECT s.equipo_id, p.equipo_id AS dueno FROM rival_solicitudes s JOIN rival_publicaciones p ON p.id = s.publicacion_id WHERE s.id = ? AND s.estado = 'pendiente'");
    $st->execute([$solId]);
    $s = $st->fetch();
    if (!$s) throw new RivalError('Esa solicitud ya no está pendiente.');
    return rival_admin_registrar_jugado((int)$s['equipo_id'], (int)$s['dueno'], $fecha, 'Clasificado desde una solicitud entre equipos', $por, 'solicitud');
}

function rival_admin_borrar_jugado(int $id, string $por): void
{
    $st = db()->prepare('SELECT * FROM rival_jugados WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new RivalError('Ese registro ya no existe.');
    db()->prepare('DELETE FROM rival_jugados WHERE id = ?')->execute([$id]);
    rival_evento_seguro('jugado', (string)$r['grupo_ref'], (int)$r['equipo_a_id'], (int)$r['equipo_b_id'], 'registro ' . $id . ' borrado por ' . $por);
}

/** Historial de partidos registrados como jugados de un equipo. */
function rival_jugados_de_equipo(int $yo): array
{
    $st = db()->prepare('SELECT * FROM rival_jugados WHERE equipo_a_id = ? OR equipo_b_id = ? ORDER BY COALESCE(fecha, DATE(creado_en)) DESC, id DESC LIMIT 40');
    $st->execute([$yo, $yo]);
    $o = [];
    foreach ($st->fetchAll() as $r) { $r['rival'] = (int)$r['equipo_a_id'] === $yo ? $r['equipo_b_nombre'] : $r['equipo_a_nombre']; $o[] = $r; }
    return $o;
}

/** Editar un partido registrado como jugado (fecha y nota). */
function rival_admin_editar_jugado(int $id, string $fecha, string $nota, string $por): string
{
    $st = db()->prepare('SELECT * FROM rival_jugados WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new RivalError('Ese registro ya no existe.');
    $fecha = trim($fecha);
    if ($fecha !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone(RIVAL_TZ));
        if (!$d || $d->format('Y-m-d') !== $fecha) throw new RivalError('La fecha no es válida.');
        if ($d > rival_ahora()) throw new RivalError('La fecha de un partido jugado no puede ser futura.');
    }
    $nota = trim(preg_replace('/\s+/u', ' ', $nota) ?? '');
    if (mb_strlen($nota) > 200) throw new RivalError('La nota es muy larga (máximo 200 caracteres).');
    db()->prepare('UPDATE rival_jugados SET fecha = ?, nota = ? WHERE id = ?')->execute([$fecha !== '' ? $fecha : null, $nota !== '' ? $nota : null, $id]);
    rival_evento_seguro('jugado', (string)$r['grupo_ref'], (int)$r['equipo_a_id'], (int)$r['equipo_b_id'], 'registro ' . $id . ' editado por ' . $por);
    return $r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'];
}

/** Partido jugado en DICIFO (reserva ya pasada): corregir fecha y hora. */
function rival_admin_editar_jugado_dicifo(int $reservaId, string $fecha, string $hora, string $por): string
{
    $st = db()->prepare("SELECT * FROM reservas_cancha WHERE id = ? AND estado = 'confirmada'");
    $st->execute([$reservaId]);
    $r = $st->fetch();
    if (!$r || !rival_ya_empezo($r['fecha'], $r['hora'])) throw new RivalError('Ese partido no está en el historial.');
    $clave = trim($fecha) . ' ' . substr(trim($hora), 0, 5);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $clave, new DateTimeZone(RIVAL_TZ));
    if (!$d || $d->format('Y-m-d H:i') !== $clave) throw new RivalError('Fecha u hora no válidas.');
    if ($d > rival_ahora()) throw new RivalError('Un partido ya jugado no puede tener fecha futura. Para programar uno, usa «Proponer un partido».');
    try {
        db()->prepare('UPDATE reservas_cancha SET fecha = ?, hora = ?, slot_llave = ? WHERE id = ?')
            ->execute([$d->format('Y-m-d'), $d->format('H:i:s'), rival_slot_llave((int)$r['cancha_id'], $d->format('Y-m-d'), $d->format('H:i')), $reservaId]);
    } catch (PDOException $ex) {
        if ((int)($ex->errorInfo[1] ?? 0) === 1062) throw new RivalError('Ya hay otro partido registrado en ese horario.');
        throw $ex;
    }
    rival_evento_seguro('jugado', (string)$r['grupo_ref'], (int)$r['equipo_a_id'], (int)$r['equipo_b_id'], 'reserva ' . $reservaId . ' corregida por ' . $por);
    return $r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'];
}

/** Partido jugado en DICIFO: quitarlo del historial (sin avisos: ya pasó). Podrán volver a enfrentarse. */
function rival_admin_borrar_jugado_dicifo(int $reservaId, string $por): string
{
    $st = db()->prepare("SELECT * FROM reservas_cancha WHERE id = ? AND estado = 'confirmada'");
    $st->execute([$reservaId]);
    $r = $st->fetch();
    if (!$r || !rival_ya_empezo($r['fecha'], $r['hora'])) throw new RivalError('Ese partido no está en el historial.');
    db()->prepare("UPDATE reservas_cancha SET estado = 'cancelada', slot_llave = ?, motivo_cancelacion = 'Eliminado del historial por la administración', cancelada_en = NOW() WHERE id = ?")
        ->execute(['x' . $reservaId, $reservaId]);
    rival_evento_seguro('jugado', (string)$r['grupo_ref'], (int)$r['equipo_a_id'], (int)$r['equipo_b_id'], 'reserva ' . $reservaId . ' quitada del historial por ' . $por);
    return $r['equipo_a_nombre'] . ' vs ' . $r['equipo_b_nombre'];
}
