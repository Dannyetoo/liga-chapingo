<?php
/**
 * Entrada desde el Estado de cuenta (botón «Busca un rival»).
 * No es un login: recibe por POST el pase firmado que entregó /capitanes/ para el equipo
 * de SU sesión, lo verifica (lib/puente-capitanes.php) y abre el contexto de capitán.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/lib/puente-capitanes.php';

header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/** El POST debe venir de este mismo sitio (el formulario lo arma la página del Estado de cuenta). */
function origen_mismo_sitio(): bool
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '' || $o === 'null') return true;   // algunos navegadores no lo envían; el pase sigue siendo obligatorio
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return strtolower((string)parse_url($o, PHP_URL_HOST)) . (parse_url($o, PHP_URL_PORT) ? ':' . parse_url($o, PHP_URL_PORT) : '') === $host;
}

$estado = 'sin_pase';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pase = is_string($_POST['pase'] ?? null) ? $_POST['pase'] : '';
    if (!origen_mismo_sitio()) {
        $estado = 'pase_invalido';
    } elseif ($pase !== '') {
        try {
            $r = puente_resolver_pase($pase);
        } catch (Throwable $ex) {
            error_log('[disponibilidad] puente: ' . $ex->getMessage());
            $r = ['estado' => 'no_disponible'];
        }
        $estado = $r['estado'];
        if ($estado === 'ok' && ($equipo = obtener_equipo((int)$r['equipo_id']))) {
            iniciar_contexto_capitan((int)$equipo['id'], 'estado_cuenta', $r['retorno'] ?? null, $r['id_estado'] ?? null);
            header('Location: rival.php', true, 303);   // «Busca un rival» sustituye a la disponibilidad semanal
            exit;
        }
        if ($estado === 'ok') $estado = 'sin_vinculo';   // vinculado a un equipo desactivado
    }
}

$textos = [
    'sin_pase'      => ['Entra desde tu Estado de cuenta', 'Abre tu Estado de cuenta y pulsa «Busca un rival».'],
    'pase_invalido' => ['El acceso ya no es válido', 'Vuelve a tu Estado de cuenta y pulsa otra vez «Busca un rival».'],
    'pase_usado'    => ['El acceso ya se usó', 'Por seguridad cada acceso sirve una sola vez. Vuelve a tu Estado de cuenta y pulsa otra vez «Busca un rival».'],
    'sin_vinculo'   => ['Tu equipo aún no está dado de alta', 'Tu equipo todavía no está habilitado para registrar disponibilidad. Avisa a la mesa directiva.'],
    'no_disponible' => ['Disponibilidad no habilitada', 'La disponibilidad del equipo todavía no está habilitada. Avisa a la mesa directiva.'],
];
[$titulo, $texto] = $textos[$estado] ?? $textos['sin_pase'];
http_response_code($estado === 'sin_pase' ? 200 : 403);
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Busca un rival · Liga Chapingo</title>
<link rel="stylesheet" href="assets/disponibilidad.css?v=5"></head>
<body>
<?php if (es_entorno_pruebas()): ?><div class="banda-pruebas" role="status">ENTORNO DE PRUEBAS</div><?php endif; ?>
<main class="contenedor" style="padding-top:24px">
  <section class="tarjeta tarjeta--centro">
    <h2 class="tarjeta__titulo"><?= e($titulo) ?></h2>
    <p><?= e($texto) ?></p>
    <a class="btn btn--pri" href="<?= e(url_retorno_segura(CAPITAN_RETORNO_DEF)) ?>">Ir a mi estado de cuenta</a>
  </section>
</main>
</body></html>
