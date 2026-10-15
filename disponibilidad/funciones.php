<?php
/**
 * Liga Chapingo — Módulo de disponibilidad
 * Funciones compartidas: conexión, sesión/CSRF, semestre activo, validaciones y consultas.
 *
 * db.php NO se modifica. Este archivo sólo lo incluye y localiza el objeto PDO
 * que db.php ya crea (acepta $pdo, $conn, $db, $conexion o una función conectar()/getPDO()).
 */

declare(strict_types=1);

/* Sesión PROPIA del módulo: nombre y ruta distintos a /capitanes/ (lchcap) y a /arbitrajes/,
   para que ninguna sesión se mezcle con otra. La cookie sólo viaja a /disponibilidad/. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $modulo = '/' . basename(__DIR__) . '/';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos    = strpos($script, $modulo);
    $ruta   = $pos === false ? '/' : substr($script, 0, $pos + strlen($modulo));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('lchdisp');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $ruta,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
    session_start();
    unset($modulo, $script, $pos, $ruta);
}

// Nunca mostrar errores internos en pantalla.
ini_set('display_errors', '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/acceso.php';
require_once __DIR__ . '/lib/canchas.php';
require_once __DIR__ . '/lib/acciones.php';

const DIAS_SEMANA = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
    5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
];

const MAX_BLOQUES_POR_DIA = 6;

/**
 * Entidades que tienen disponibilidad semanal + excepciones con la MISMA forma de tablas.
 * Los nombres de tabla/columna salen SÓLO de esta lista blanca (nunca del navegador),
 * por eso es seguro interpolarlos en SQL. Agregar una entidad nueva = agregar una fila aquí.
 */
const ENTIDADES = [
    'equipo' => [
        'tabla' => 'equipos', 'disp' => 'disponibilidad_equipo', 'exc' => 'excepciones_disponibilidad',
        'col' => 'equipo_id', 'etiqueta' => 'equipo', 'articulo' => 'el equipo', 'este' => 'este equipo',
        'pagina' => 'index.php', 'param' => 'equipo', 'solo_activos' => true,
    ],
    'cancha' => [
        'tabla' => 'canchas', 'disp' => 'disponibilidad_cancha', 'exc' => 'excepciones_cancha',
        'col' => 'cancha_id', 'etiqueta' => 'cancha', 'articulo' => 'la cancha', 'este' => 'esta cancha',
        'pagina' => 'canchas.php', 'param' => 'cancha', 'solo_activos' => false,
    ],
];

function entidad(string $tipo): array
{
    if (!isset(ENTIDADES[$tipo])) {
        throw new InvalidArgumentException('Entidad no soportada.');
    }
    return ENTIDADES[$tipo] + ['tipo' => $tipo];
}

/* ------------------------------------------------------------------ */
/* Conexión                                                            */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    foreach (['pdo', 'conn', 'db', 'conexion', 'conexionPDO'] as $nombre) {
        if (isset($GLOBALS[$nombre]) && $GLOBALS[$nombre] instanceof PDO) {
            $pdo = $GLOBALS[$nombre];
            break;
        }
    }
    if (!$pdo) {
        foreach (['conectar', 'getPDO', 'getConnection', 'conexion'] as $fn) {
            if (function_exists($fn)) {
                $posible = $fn();
                if ($posible instanceof PDO) { $pdo = $posible; break; }
            }
        }
    }
    if (!$pdo) {
        error_log('[disponibilidad] No se encontró el objeto PDO definido en db.php');
        http_response_code(500);
        exit('Error de configuración del módulo. Contacta al administrador.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

/* ------------------------------------------------------------------ */
/* Salida segura                                                       */
/* ------------------------------------------------------------------ */

function e($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hora_corta(?string $hora): string
{
    return $hora ? substr($hora, 0, 5) : '';
}

function fecha_mx(string $fecha): string
{
    $d = DateTime::createFromFormat('Y-m-d', substr($fecha, 0, 10));
    return $d ? $d->format('d/m/Y') : $fecha;
}

/* ------------------------------------------------------------------ */
/* Mensajes flash (patrón POST → redirect → GET)                       */
/* ------------------------------------------------------------------ */

function flash(string $tipo, $mensajes): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensajes' => (array)$mensajes];
}

function tomar_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Guarda lo que el usuario envió para no perderlo si hubo errores. */
function recordar_formulario(string $clave, array $datos): void
{
    $_SESSION['old'][$clave] = $datos;
}

function tomar_formulario(string $clave): ?array
{
    $d = $_SESSION['old'][$clave] ?? null;
    unset($_SESSION['old'][$clave]);
    return $d;
}

function redirigir_entidad(string $entidad, ?int $id, string $ancla = ''): void
{
    $E = entidad($entidad);
    if ($entidad === 'equipo' && es_capitan()) {
        $id = null;                             // el capitán no usa ?equipo=: su equipo sale de la sesión
    }
    $url = $E['pagina'] . ($id ? '?' . $E['param'] . '=' . $id : '') . ($ancla ? '#' . $ancla : '');
    header('Location: ' . $url, true, 303);
    exit;
}

function redirigir_equipo(?int $equipoId, string $ancla = ''): void
{
    redirigir_entidad('equipo', $equipoId, $ancla);
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valido(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validación común a todos los endpoints de escritura:
 * método POST + token CSRF + equipo existente + semestre activo.
 * Devuelve [equipo, semestre] o redirige con error.
 */
function preparar_escritura(string $entidad = 'equipo'): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Método no permitido.');
    }
    $E = entidad($entidad);
    if ($entidad === 'equipo') {
        // Capitán: el equipo sale de la SESIÓN; un equipo_id distinto en el formulario se rechaza.
        [$id, $errAuth] = equipo_objetivo_escritura();
        if ($errAuth) {
            flash('error', $errAuth);
            redirigir_entidad($entidad, $id);
        }
    } else {
        requerir_admin();                       // canchas y demás recursos: sólo administración
        $id = entero_positivo($_POST[$E['col']] ?? null);
    }

    if (!csrf_valido($_POST['csrf_token'] ?? null)) {
        flash('error', 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.');
        redirigir_entidad($entidad, $id);
    }

    $semestre = obtener_semestre_activo();
    if (!$semestre) {
        // La página ya muestra el aviso administrativo permanente; no se guarda nada.
        redirigir_entidad($entidad, $id);
    }

    $registro = $id ? obtener_registro($entidad, $id) : null;
    if (!$registro) {
        flash('error', $entidad === 'equipo'
            ? 'El equipo seleccionado no existe o no está activo.'
            : 'La ' . $E['etiqueta'] . ' seleccionada no existe.');
        redirigir_entidad($entidad, null);
    }

    return [$registro, $semestre];
}

/** Registro (equipo, cancha…) por id. Los equipos deben estar activos; las canchas se editan en cualquier estado. */
function obtener_registro(string $entidad, int $id): ?array
{
    $E = entidad($entidad);
    $sql = 'SELECT * FROM ' . $E['tabla'] . ' WHERE id = ?' . ($E['solo_activos'] ? ' AND activo = 1' : '');
    $st = db()->prepare($sql);
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* ------------------------------------------------------------------ */
/* Semestre activo (ÚNICO lugar donde se decide el semestre)           */
/* ------------------------------------------------------------------ */

function obtener_semestre_activo(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $st = db()->query(
        'SELECT id, nombre, fecha_inicio, fecha_fin
           FROM semestres
          WHERE activo = 1
          ORDER BY id DESC
          LIMIT 1'
    );
    $fila = $st->fetch();
    $cache = $fila ?: null;
    return $cache;
}

/* ------------------------------------------------------------------ */
/* Equipos                                                             */
/* ------------------------------------------------------------------ */

function listar_equipos(): array
{
    return db()->query(
        'SELECT id, nombre, capitan_nombre FROM equipos WHERE activo = 1 ORDER BY nombre'
    )->fetchAll();
}

function obtener_equipo(int $id): ?array
{
    $st = db()->prepare('SELECT id, nombre, capitan_nombre FROM equipos WHERE id = ? AND activo = 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Resumen para administración: qué equipos ya registraron disponibilidad en el semestre.
 * "Registrada" = al menos un bloque semanal activo.
 */
function resumen_registro_equipos(int $semestreId): array
{
    $st = db()->prepare(
        'SELECT e.id, e.nombre, e.capitan_nombre,
                (SELECT COUNT(*) FROM disponibilidad_equipo d
                  WHERE d.equipo_id = e.id AND d.semestre_id = ? AND d.activo = 1) AS bloques,
                (SELECT COUNT(DISTINCT d.dia_semana) FROM disponibilidad_equipo d
                  WHERE d.equipo_id = e.id AND d.semestre_id = ? AND d.activo = 1) AS dias,
                (SELECT MAX(d.creado_en) FROM disponibilidad_equipo d
                  WHERE d.equipo_id = e.id AND d.semestre_id = ?) AS ultima,
                (SELECT COUNT(*) FROM excepciones_disponibilidad x
                  WHERE x.equipo_id = e.id AND x.semestre_id = ?) AS excepciones
           FROM equipos e
          WHERE e.activo = 1
          ORDER BY e.nombre'
    );
    $st->execute([$semestreId, $semestreId, $semestreId, $semestreId]);
    $filas = $st->fetchAll();
    foreach ($filas as &$f) {
        $f['registrada'] = (int)$f['bloques'] > 0;
    }
    unset($f);
    usort($filas, fn($a, $b) => [$b['registrada'], $a['nombre']] <=> [$a['registrada'], $b['nombre']]);
    return $filas;
}

/* ------------------------------------------------------------------ */
/* Disponibilidad semanal                                              */
/* ------------------------------------------------------------------ */

/** Bloques activos agrupados por día: [1 => [ ['id'=>..,'hora_inicio'=>'13:00','hora_fin'=>'15:00'], ...], ...] */
function obtener_disponibilidad(int $registroId, int $semestreId, string $entidad = 'equipo'): array
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'SELECT id, dia_semana, hora_inicio, hora_fin
           FROM ' . $E['disp'] . '
          WHERE ' . $E['col'] . ' = ? AND semestre_id = ? AND activo = 1
          ORDER BY dia_semana, hora_inicio'
    );
    $st->execute([$registroId, $semestreId]);
    $porDia = array_fill_keys(array_keys(DIAS_SEMANA), []);
    foreach ($st->fetchAll() as $f) {
        $d = (int)$f['dia_semana'];
        if (!isset($porDia[$d])) continue;
        $porDia[$d][] = [
            'id'          => (int)$f['id'],
            'hora_inicio' => hora_corta($f['hora_inicio']),
            'hora_fin'    => hora_corta($f['hora_fin']),
        ];
    }
    return $porDia;
}

/**
 * Normaliza y valida lo enviado por el formulario semanal.
 * Entrada esperada: $_POST['dias'][N]['disponible'] y $_POST['dias'][N]['bloques'][i]['inicio'|'fin']
 * Devuelve [bloquesValidos, errores]. bloquesValidos = [dia => [[inicio, fin], ...]]
 */
function validar_semana(array $entrada): array
{
    $errores = [];
    $bloques = array_fill_keys(array_keys(DIAS_SEMANA), []);

    foreach ($entrada as $dia => $datos) {
        if (!ctype_digit((string)$dia) || !isset(DIAS_SEMANA[(int)$dia])) {
            $errores[] = 'Se recibió un día no válido.';
            continue;
        }
    }

    foreach (DIAS_SEMANA as $dia => $nombreDia) {
        $datos = $entrada[$dia] ?? $entrada[(string)$dia] ?? null;
        if (!is_array($datos) || empty($datos['disponible'])) {
            continue; // No disponible: sin bloques.
        }
        $lista = $datos['bloques'] ?? [];
        if (!is_array($lista)) $lista = [];

        $validos = [];
        $conError = false;
        foreach ($lista as $b) {
            if (!is_array($b)) continue;
            $ini = trim((string)($b['inicio'] ?? ''));
            $fin = trim((string)($b['fin'] ?? ''));
            if ($ini === '' && $fin === '') continue; // fila vacía: se ignora

            $hi = normalizar_hora($ini);
            $hf = normalizar_hora($fin);
            if (!$hi || !$hf) {
                $errores[] = "$nombreDia: indica hora de inicio y hora de fin válidas (HH:MM).";
                $conError = true;
                continue;
            }
            if ($hf <= $hi) {
                $errores[] = "$nombreDia: la hora de fin (" . substr($hf, 0, 5) . ") debe ser posterior a la de inicio (" . substr($hi, 0, 5) . ').';
                $conError = true;
                continue;
            }
            $validos[] = [$hi, $hf];
        }

        if ($conError) {
            continue;
        }
        if (!$validos) {
            $errores[] = "$nombreDia está marcado como disponible pero no tiene horarios. Agrega un horario o márcalo como no disponible.";
            continue;
        }
        if (count($validos) > MAX_BLOQUES_POR_DIA) {
            $errores[] = "$nombreDia: máximo " . MAX_BLOQUES_POR_DIA . ' horarios por día.';
            continue;
        }

        usort($validos, fn($a, $b) => strcmp($a[0], $b[0]));
        for ($i = 1; $i < count($validos); $i++) {
            [$aIni, $aFin] = $validos[$i - 1];
            [$bIni, $bFin] = $validos[$i];
            if ($aIni === $bIni && $aFin === $bFin) {
                $errores[] = "$nombreDia: el horario " . rango($aIni, $aFin) . ' está repetido.';
            } elseif ($bIni < $aFin) {
                $errores[] = "$nombreDia: los horarios " . rango($aIni, $aFin) . ' y ' . rango($bIni, $bFin)
                    . ' se enciman. Únelos en uno solo (' . rango($aIni, max($aFin, $bFin)) . ') o corrige las horas.';
            }
        }
        $bloques[$dia] = $validos;
    }

    return [$bloques, array_values(array_unique($errores))];
}

/**
 * Reemplaza la disponibilidad semanal del equipo en una transacción.
 * No borra filas: conserva las que no cambiaron, desactiva (activo = 0) las que se quitaron
 * e inserta las nuevas. Así se preserva el historial y no se generan duplicados.
 * Devuelve ['insertados'=>n, 'desactivados'=>n, 'sin_cambio'=>n].
 */
function guardar_semana(int $registroId, int $semestreId, array $bloques, string $entidad = 'equipo'): array
{
    $E = entidad($entidad);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Bloquea las filas del equipo para evitar dos guardados simultáneos.
        $st = $pdo->prepare(
            'SELECT id, dia_semana, hora_inicio, hora_fin
               FROM ' . $E['disp'] . '
              WHERE ' . $E['col'] . ' = ? AND semestre_id = ? AND activo = 1
              FOR UPDATE'
        );
        $st->execute([$registroId, $semestreId]);
        $actuales = [];
        foreach ($st->fetchAll() as $f) {
            $clave = (int)$f['dia_semana'] . '|' . normalizar_hora($f['hora_inicio']) . '|' . normalizar_hora($f['hora_fin']);
            $actuales[$clave][] = (int)$f['id'];
        }

        $deseados = [];
        foreach ($bloques as $dia => $lista) {
            foreach ($lista as [$ini, $fin]) {
                $deseados[(int)$dia . '|' . $ini . '|' . $fin] = [(int)$dia, $ini, $fin];
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO ' . $E['disp'] . ' (' . $E['col'] . ', semestre_id, dia_semana, hora_inicio, hora_fin, activo)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $des = $pdo->prepare(
            'UPDATE ' . $E['disp'] . ' SET activo = 0 WHERE id = ? AND ' . $E['col'] . ' = ? AND semestre_id = ?'
        );

        $r = ['insertados' => 0, 'desactivados' => 0, 'sin_cambio' => 0];
        foreach ($actuales as $clave => $ids) {
            $conservar = isset($deseados[$clave]) ? array_shift($ids) : null;
            if ($conservar) $r['sin_cambio']++;
            foreach ($ids as $id) { // sobrantes o duplicados previos
                $des->execute([$id, $registroId, $semestreId]);
                $r['desactivados']++;
            }
            unset($deseados[$clave]);
        }
        foreach ($deseados as [$dia, $ini, $fin]) {
            $ins->execute([$registroId, $semestreId, $dia, $ini, $fin]);
            $r['insertados']++;
        }

        $pdo->commit();
        return $r;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Desactiva un bloque SOLO si pertenece al equipo y semestre indicados. */
function eliminar_bloque(int $bloqueId, int $registroId, int $semestreId, string $entidad = 'equipo'): bool
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'UPDATE ' . $E['disp'] . ' SET activo = 0
          WHERE id = ? AND ' . $E['col'] . ' = ? AND semestre_id = ? AND activo = 1'
    );
    $st->execute([$bloqueId, $registroId, $semestreId]);
    return $st->rowCount() === 1;
}

/* ------------------------------------------------------------------ */
/* Excepciones                                                         */
/* ------------------------------------------------------------------ */

function obtener_excepciones(int $registroId, int $semestreId, string $entidad = 'equipo'): array
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'SELECT id, fecha, tipo, hora_inicio, hora_fin, motivo
           FROM ' . $E['exc'] . '
          WHERE ' . $E['col'] . ' = ? AND semestre_id = ?
          ORDER BY fecha, hora_inicio'
    );
    $st->execute([$registroId, $semestreId]);
    return $st->fetchAll();
}

/** Devuelve [datosNormalizados, errores]. */
function validar_excepcion(array $in, array $semestre, int $registroId, string $entidad = 'equipo'): array
{
    $E = entidad($entidad);
    $errores = [];
    $fecha  = trim((string)($in['fecha'] ?? ''));
    $tipo   = (string)($in['tipo'] ?? '');
    $ini    = trim((string)($in['hora_inicio'] ?? ''));
    $fin    = trim((string)($in['hora_fin'] ?? ''));
    $motivo = trim((string)($in['motivo'] ?? ''));

    $d = DateTime::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        $errores[] = 'Indica una fecha válida.';
    } else {
        $desde = substr((string)$semestre['fecha_inicio'], 0, 10);
        $hasta = substr((string)$semestre['fecha_fin'], 0, 10);
        if ($fecha < $desde || $fecha > $hasta) {
            $errores[] = 'La fecha debe estar dentro del semestre activo (' . fecha_mx($desde) . ' – ' . fecha_mx($hasta) . ').';
        }
    }

    if (!in_array($tipo, ['no_disponible', 'disponibilidad_extra'], true)) {
        $errores[] = 'Selecciona un tipo de excepción válido.';
    }

    $hi = $ini !== '' ? normalizar_hora($ini) : null;
    $hf = $fin !== '' ? normalizar_hora($fin) : null;
    if (($ini !== '' && !$hi) || ($fin !== '' && !$hf)) {
        $errores[] = 'Las horas deben tener formato HH:MM.';
    }

    if ($tipo === 'disponibilidad_extra') {
        if (!$hi || !$hf) {
            $errores[] = 'Para disponibilidad extraordinaria la hora de inicio y la de fin son obligatorias.';
        }
    } elseif ($tipo === 'no_disponible') {
        // Horas opcionales: ambas vacías = todo el día; si se indica una, se piden las dos.
        if (($hi && !$hf) || (!$hi && $hf)) {
            $errores[] = 'Si indicas un horario de no disponibilidad, llena inicio y fin (o deja ambas vacías para todo el día).';
        }
    }
    if ($hi && $hf && $hf <= $hi) {
        $errores[] = 'La hora de fin debe ser posterior a la hora de inicio.';
    }

    if (mb_strlen($motivo) > 255) {
        $errores[] = 'El motivo no puede exceder 255 caracteres.';
    }

    // Duplicados / solapes con otras excepciones del mismo equipo en la misma fecha.
    if (!$errores) {
        $st = db()->prepare(
            'SELECT tipo, hora_inicio, hora_fin FROM ' . $E['exc'] . '
              WHERE ' . $E['col'] . ' = ? AND semestre_id = ? AND fecha = ?'
        );
        $st->execute([$registroId, (int)$semestre['id'], $fecha]);
        foreach ($st->fetchAll() as $ex) {
            $exIni = $ex['hora_inicio'] ? normalizar_hora($ex['hora_inicio']) : null;
            $exFin = $ex['hora_fin'] ? normalizar_hora($ex['hora_fin']) : null;
            $todoDiaNuevo = !$hi;
            $todoDiaExist = !$exIni;
            if ($ex['tipo'] === $tipo && $exIni === $hi && $exFin === $hf) {
                $errores[] = 'Esa excepción ya está registrada para el ' . fecha_mx($fecha) . '.';
                break;
            }
            if ($todoDiaNuevo || $todoDiaExist || ($hi < $exFin && $exIni < $hf)) {
                $errores[] = 'Ya existe una excepción que se encima con este horario el ' . fecha_mx($fecha)
                    . ' (' . etiqueta_tipo($ex['tipo']) . ($exIni ? ' ' . rango($exIni, $exFin) : ', todo el día')
                    . '). Elimínala primero o ajusta las horas.';
                break;
            }
        }
    }

    return [[
        'fecha'       => $fecha,
        'tipo'        => $tipo,
        'hora_inicio' => $hi,
        'hora_fin'    => $hf,
        'motivo'      => $motivo !== '' ? $motivo : null,
    ], $errores];
}

function guardar_excepcion(int $registroId, int $semestreId, array $d, string $entidad = 'equipo'): void
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'INSERT INTO ' . $E['exc'] . ' (' . $E['col'] . ', semestre_id, fecha, tipo, hora_inicio, hora_fin, motivo)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$registroId, $semestreId, $d['fecha'], $d['tipo'], $d['hora_inicio'], $d['hora_fin'], $d['motivo']]);
}

/** Elimina una excepción SOLO si pertenece al equipo y semestre indicados. */
function eliminar_excepcion(int $excepcionId, int $registroId, int $semestreId, string $entidad = 'equipo'): bool
{
    $E = entidad($entidad);
    $st = db()->prepare(
        'DELETE FROM ' . $E['exc'] . ' WHERE id = ? AND ' . $E['col'] . ' = ? AND semestre_id = ?'
    );
    $st->execute([$excepcionId, $registroId, $semestreId]);
    return $st->rowCount() === 1;
}

function etiqueta_tipo(string $tipo): string
{
    return $tipo === 'disponibilidad_extra' ? 'Disponibilidad extra' : 'No disponible';
}

/* ------------------------------------------------------------------ */
/* Utilidades                                                          */
/* ------------------------------------------------------------------ */

/** Acepta H:MM, HH:MM o HH:MM:SS; devuelve 'HH:MM:00' o null. */
function normalizar_hora(?string $h): ?string
{
    if ($h === null) return null;
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($h), $m)) {
        return null;
    }
    return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
}

function rango(string $ini, string $fin): string
{
    return substr($ini, 0, 5) . '–' . substr($fin, 0, 5);
}

function entero_positivo($v): ?int
{
    if (is_int($v)) return $v > 0 ? $v : null;
    if (is_string($v) && ctype_digit($v) && (int)$v > 0) return (int)$v;
    return null;
}

/** Total de horas semanales disponibles (para el resumen). */
function horas_semanales(array $porDia): float
{
    $min = 0;
    foreach ($porDia as $bloques) {
        foreach ($bloques as $b) {
            [$h1, $m1] = array_map('intval', explode(':', $b['hora_inicio']));
            [$h2, $m2] = array_map('intval', explode(':', $b['hora_fin']));
            $min += ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
        }
    }
    return $min / 60;
}

/* ------------------------------------------------------------------ */
/* Vistas reutilizables (vistas/semana.php, excepciones.php, resumen.php) */
/* ------------------------------------------------------------------ */

/**
 * Configuración que reciben las vistas compartidas.
 * $urls: ['semana', 'bloque', 'excepcion', 'eliminar_excepcion'] → acción de cada formulario.
 * $ocultos: campos extra por formulario (p. ej. ['semana' => ['accion' => 'guardar_semana']]).
 */
function vista_config(string $entidad, array $registro, ?array $semestre, array $urls, array $ocultos = [], array $num = [2, 3, 4]): array
{
    $E  = entidad($entidad);
    $id = (int)$registro['id'];
    $V = [
        'entidad' => $entidad, 'col' => $E['col'], 'id' => $id, 'articulo' => $E['articulo'],
        'semestre' => $semestre, 'editable' => (bool)$semestre, 'url' => $urls, 'ocultos' => $ocultos, 'num' => $num,
        'por_dia' => array_fill_keys(array_keys(DIAS_SEMANA), []), 'excepciones' => [],
        'form_semana' => null, 'form_exc' => null,
    ];
    if ($semestre) {
        $V['por_dia']     = obtener_disponibilidad($id, (int)$semestre['id'], $entidad);
        $V['excepciones'] = obtener_excepciones($id, (int)$semestre['id'], $entidad);
        $V['form_semana'] = tomar_formulario(clave_formulario($entidad, 'semana', $id));
        $V['form_exc']    = tomar_formulario(clave_formulario($entidad, 'excepcion', $id));
    }
    return $V;
}

/** Clave de sesión para recordar un formulario con error. Equipos conservan la clave de la Fase 2. */
function clave_formulario(string $entidad, string $form, int $id): string
{
    return ($entidad === 'equipo' ? '' : $entidad . '_') . $form . '_' . $id;
}

/** CSRF + id del registro + campos extra del formulario indicado. */
function vista_ocultos(array $V, string $form): string
{
    $html = csrf_campo();
    if (empty($V['sin_id'])) {   // capitán: el servidor usa la sesión, no se envía id
        $html .= '<input type="hidden" name="' . e($V['col']) . '" value="' . (int)$V['id'] . '">';
    }
    foreach ($V['ocultos'][$form] ?? [] as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $html;
}

/** Lo que se pinta en el formulario semanal: lo reenviado (si hubo error) o lo guardado. */
function filas_formulario(int $dia, array $porDia, ?array $formSemana): array
{
    if ($formSemana !== null) {
        $d = $formSemana[$dia] ?? $formSemana[(string)$dia] ?? [];
        $disp = !empty($d['disponible']);
        $filas = [];
        foreach ((is_array($d['bloques'] ?? null) ? $d['bloques'] : []) as $b) {
            if (!is_array($b)) continue;
            $filas[] = ['id' => null, 'hora_inicio' => (string)($b['inicio'] ?? ''), 'hora_fin' => (string)($b['fin'] ?? '')];
        }
        return [$disp, $filas];
    }
    return [!empty($porDia[$dia]), $porDia[$dia]];
}

/**
 * Sólo rutas relativas del propio sitio (evita redirecciones abiertas).
 * Acepta 'x/…', '../x/…' o '/x/…'; rechaza esquemas, '//' y caracteres de control.
 */
function url_retorno_segura(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || strpos($url, '//') === 0
        || strpos($url, '\\') !== false || preg_match('/[\x00-\x1f]/', $url)) {
        return CAPITAN_RETORNO_DEF;
    }
    return $url;
}

/** Registra el error real en el log del servidor y devuelve un mensaje genérico. */
function error_interno(Throwable $ex, string $contexto): string
{
    error_log('[disponibilidad] ' . $contexto . ': ' . $ex->getMessage());
    return 'No se pudo completar la operación. Inténtalo de nuevo; si persiste, avisa al administrador.';
}
