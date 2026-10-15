<?php
/**
 * Liga Chapingo — Contexto de acceso (SIN login nuevo).
 *
 * CAPITÁN        → sólo SU equipo. El equipo_id vive en la sesión PHP ($_SESSION[SESION_CAPITAN]),
 *                  la escribe ÚNICAMENTE el puente con el Estado de cuenta (entrar.php →
 *                  lib/puente-capitanes.php) y NUNCA sale de ?equipo=, de un <input> ni de JavaScript.
 * ADMINISTRADOR  → cualquier equipo, canchas, coincidencias. Hoy sólo existe dentro del entorno
 *                  de pruebas (subdominio protegido con contraseña). Fuera de él: 403.
 *
 * Regla: TODA página y TODO endpoint consulta contexto_acceso() en el servidor.
 * Mostrar u ocultar un enlace nunca es la protección.
 */

declare(strict_types=1);

const SESION_CAPITAN        = 'disp_capitan';
const CAPITAN_INACTIVIDAD_S = 7200;          // 2 h sin actividad → se pide volver a entrar desde el Estado de cuenta
const CAPITAN_RETORNO_DEF   = '../capitanes/'; // "Volver a mi estado de cuenta" (se ajusta al revisar /capitanes/)

/** true en pruebas.ligachapingo.com, localhost o consola. */
function es_entorno_pruebas(): bool
{
    if (PHP_SAPI === 'cli') return true;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return strpos($host, 'pruebas.') === 0 || in_array($host, ['localhost', '127.0.0.1'], true);
}

/**
 * Abre el contexto de capitán para un equipo YA verificado por el Estado de cuenta.
 * Sólo deben llamarla entrar.php (puente real) y admin/simular-capitan.php (pruebas).
 */
function iniciar_contexto_capitan(int $equipoId, string $origen, ?string $retorno = null): void
{
    session_regenerate_id(true);          // evita fijación de sesión al cambiar de identidad
    $_SESSION[SESION_CAPITAN] = [
        'equipo_id' => $equipoId,
        'origen'    => $origen,             // 'estado_cuenta' | 'simulacion'
        'retorno'   => $retorno ?: CAPITAN_RETORNO_DEF,
        'inicio'    => time(),
        'ultimo'    => time(),
    ];
    unset($_SESSION['csrf_token']);         // token nuevo para la nueva identidad
    unset($GLOBALS['__capitan_memo']);
}

function cerrar_contexto_capitan(): void
{
    unset($_SESSION[SESION_CAPITAN], $_SESSION['csrf_token'], $GLOBALS['__capitan_memo']);
    session_regenerate_id(true);
}

/** Datos del capitán en sesión si siguen vigentes y su equipo existe y está activo; si no, null. */
function capitan_en_sesion(): ?array
{
    if (array_key_exists('__capitan_memo', $GLOBALS)) return $GLOBALS['__capitan_memo'];
    $c = $_SESSION[SESION_CAPITAN] ?? null;
    $r = null;
    if (is_array($c) && isset($c['equipo_id']) && is_int($c['equipo_id'])) {
        if (time() - (int)($c['ultimo'] ?? 0) > CAPITAN_INACTIVIDAD_S) {
            unset($_SESSION[SESION_CAPITAN]);
        } elseif (!($equipo = obtener_equipo($c['equipo_id']))) {   // sólo equipos activos
            unset($_SESSION[SESION_CAPITAN]);
        } else {
            $_SESSION[SESION_CAPITAN]['ultimo'] = time();
            $c['equipo'] = $equipo;
            $r = $c;
        }
    }
    return $GLOBALS['__capitan_memo'] = $r;
}

/**
 * Punto ÚNICO de decisión de rol.
 * Devuelve ['rol' => 'capitan'|'admin'|null, 'equipo_id' => ?int, 'origen' => ?string, 'retorno' => ?string].
 * Un capitán en sesión SIEMPRE es capitán, también en pruebas (así se prueba de verdad).
 */
function contexto_acceso(): array
{
    $c = capitan_en_sesion();
    if ($c) {
        return ['rol' => 'capitan', 'equipo_id' => $c['equipo_id'], 'origen' => $c['origen'], 'retorno' => $c['retorno']];
    }
    if (es_entorno_pruebas()) {
        return ['rol' => 'admin', 'equipo_id' => null, 'origen' => 'pruebas', 'retorno' => null];
    }
    // FUTURO: aquí se reconocerá al administrador real de producción.
    return ['rol' => null, 'equipo_id' => null, 'origen' => null, 'retorno' => null];
}

function es_capitan(): bool { return contexto_acceso()['rol'] === 'capitan'; }
function es_admin(): bool   { return contexto_acceso()['rol'] === 'admin'; }

/** Corta la petición si el contexto no es administrativo. */
function requerir_admin(): void
{
    if (!es_admin()) {
        http_response_code(403);
        exit(es_capitan()
            ? 'Esta sección es sólo para administración.'
            : 'Acceso restringido.');
    }
}

/** Equipo que el capitán autenticado puede modificar (null si no es capitán). */
function equipo_autorizado(): ?int
{
    $c = contexto_acceso();
    return $c['rol'] === 'capitan' ? $c['equipo_id'] : null;
}

/**
 * Equipo sobre el que se permite ESCRIBIR en esta petición.
 *  - capitán: SIEMPRE el de su sesión. Si el formulario trae otro equipo_id → se rechaza.
 *  - admin:   el que venga en el formulario (se valida después que exista).
 *  - nadie:   403.
 * Devuelve [equipoId|null, mensajeError|null].
 */
function equipo_objetivo_escritura(): array
{
    $c = contexto_acceso();
    $enviado = $_POST['equipo_id'] ?? null;
    if ($c['rol'] === 'capitan') {
        $txt = is_scalar($enviado) ? (string)$enviado : '[no escalar]';
        if ($enviado !== null && $txt !== '' && $txt !== (string)$c['equipo_id']) {
            error_log('[disponibilidad] capitán del equipo ' . $c['equipo_id'] . ' intentó escribir en equipo_id=' . substr($txt, 0, 20));
            return [$c['equipo_id'], 'Sólo puedes modificar la disponibilidad de tu equipo.'];
        }
        return [$c['equipo_id'], null];
    }
    if ($c['rol'] === 'admin') {
        return [entero_positivo($enviado), null];
    }
    http_response_code(403);
    exit('Acceso restringido. Entra desde tu Estado de cuenta.');
}
