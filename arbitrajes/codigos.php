<?php
/* =====================================================================
   Liga Chapingo — Códigos de capitán (herramienta de la mesa directiva)

   Esta página vive dentro de la carpeta de administración y solo abre si
   ya iniciaste sesión en la app de arbitrajes: usa la MISMA sesión y la
   MISMA contraseña, no inventa otro acceso.

   No se enlaza desde el sitio público ni desde el portal de capitanes.
   Entra escribiendo la dirección:  /arbitrajes/codigos.php

   Solo lee. No modifica estado.json ni ningún otro archivo.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/config.php';                 /* MINUTOS_SESION */
require __DIR__ . '/../capitanes/config.php';    /* SEMILLA_CAPITANES, DATOS_ARBITRAJES */
require __DIR__ . '/../capitanes/codigos-lib.php';

/* ---------- misma sesión que la app de arbitrajes ---------- */
$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('pctes');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => $seguro,
    ]);
} else {
    session_set_cookie_params(0, '/', '', $seguro, true);
}
session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$vencida = MINUTOS_SESION > 0
        && isset($_SESSION['visto'])
        && (time() - (int)$_SESSION['visto']) > MINUTOS_SESION * 60;

if (empty($_SESSION['ok']) || $vencida) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Acceso restringido</title>'
       . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#F4F3EC;color:#14231C;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
       . 'div{background:#fff;border:1px solid #E2E4DB;border-radius:14px;padding:28px;max-width:420px;text-align:center}'
       . 'a{color:#1B6B45;font-weight:600}</style>'
       . '<div><h1 style="font-size:22px;margin:0 0 10px">Acceso restringido</h1>'
       . '<p style="color:#5F6F67;font-size:15px;margin:0 0 18px">Esta página es solo para la mesa directiva. '
       . 'Entra primero a la app de arbitrajes con tu contraseña y vuelve a abrirla.</p>'
       . '<a href="index.html">Ir a la app de arbitrajes</a></div>';
    exit;
}
$_SESSION['visto'] = time();

/* ---------- datos ---------- */
$d = leerEstadoArbitrajes();
$S = $d['estado'] ?? [];
$equipos = equiposDelEstado(is_array($S) ? $S : []);

function nom($lista, $id): string {
    if (!is_array($lista)) return '';
    foreach ($lista as $x) {
        if (is_array($x) && (string)($x['id'] ?? '') === (string)$id) return (string)($x['nombre'] ?? '');
    }
    return '';
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* dirección del portal, calculada a partir de esta misma petición */
$host   = (string)($_SERVER['HTTP_HOST'] ?? 'ligachapingo.com');
$base   = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/arbitrajes/codigos.php')))), '/');
$portal = ($seguro ? 'https://' : 'http://') . $host . $base . '/capitanes/';

/* agrupa por edición · disciplina · rama · categoría */
$grupos = [];
foreach ($equipos as $e) {
    if (!is_array($e) || !isset($e['id'])) continue;
    $clave = nom($S['ediciones'] ?? [], $e['edicionId'] ?? '') . ' · '
           . nom($S['disciplinas'] ?? [], $e['disciplinaId'] ?? '') . ' · '
           . nom($S['ramas'] ?? [], $e['ramaId'] ?? '') . ' · '
           . nom($S['categorias'] ?? [], $e['categoriaId'] ?? '');
    $grupos[$clave][] = $e;
}
ksort($grupos);
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Códigos de capitán · Liga Chapingo</title>
<style>
:root{--verde:#1B6B45;--verde-900:#0C3523;--crema:#F4F3EC;--linea:#E2E4DB;--gris:#5F6F67}
*{box-sizing:border-box}
body{margin:0;background:var(--crema);color:#14231C;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.55}
.wrap{max-width:980px;margin:0 auto;padding:26px 18px 60px}
h1{font-size:26px;margin:0 0 6px;color:var(--verde-900)}
.sub{color:var(--gris);font-size:14px;margin:0 0 20px}
.aviso{background:#FBF3DC;border:1px solid #F0E2B6;color:#6B520B;border-radius:11px;padding:13px 15px;font-size:13.5px;margin-bottom:20px}
.barra{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
input[type=search]{flex:1;min-width:210px;border:1px solid var(--linea);border-radius:10px;padding:10px 13px;font:inherit;background:#fff}
.btn{border:1px solid var(--linea);background:#fff;border-radius:10px;padding:9px 14px;font:inherit;font-size:13.5px;font-weight:600;cursor:pointer;color:var(--verde-900);text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--verde);color:var(--verde)}
.grupo{margin-bottom:26px}
.grupo h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--gris);margin:0 0 10px;font-weight:700}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--linea);border-radius:12px;overflow:hidden}
th,td{padding:10px 13px;text-align:left;border-bottom:1px solid #F0F2EE;vertical-align:middle}
th{background:#FAFBF8;font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--gris)}
tr:last-child td{border-bottom:0}
.cod{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:16px;font-weight:700;letter-spacing:.1em;color:var(--verde)}
.eq{font-weight:600}
.enc{color:var(--gris);font-size:13px}
.oculto{display:none}
@media print{.barra,.aviso,.btn{display:none}body{background:#fff}}
@media (max-width:640px){
  th:nth-child(3),td:nth-child(3){display:none}
  .wrap{padding:18px 14px 50px}
}
</style>
</head>
<body>
<div class="wrap">
  <h1>Códigos de capitán</h1>
  <p class="sub"><?= count($equipos) ?> equipos · portal de consulta: <b><?= h($portal) ?></b></p>

  <div class="aviso">
    Cada código abre <b>únicamente</b> el estado de cuenta de ese equipo, y solo para consulta.
    Los códigos se calculan a partir de la semilla que está en <code>capitanes/config.php</code>:
    si algún día cambias esa semilla, todos los códigos cambian y hay que repartirlos de nuevo.
  </div>

  <div class="barra">
    <input type="search" id="buscar" placeholder="Buscar equipo, representante o código…" autocomplete="off">
    <button class="btn" type="button" onclick="window.print()">Imprimir la lista</button>
    <a class="btn" href="index.html">Volver a la app</a>
  </div>

<?php foreach ($grupos as $titulo => $lista): ?>
  <div class="grupo">
    <h2><?= h(trim($titulo, ' ·')) ?></h2>
    <table>
      <thead><tr><th>Equipo</th><th>Código</th><th>Representante</th><th></th></tr></thead>
      <tbody>
      <?php
        usort($lista, function ($a, $b) {
            return strnatcasecmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
        });
        foreach ($lista as $e):
          $cod   = codigoEquipo((string)$e['id']);
          $bonito = codigoBonito($cod);
          $mensaje = 'Código de acceso al portal de Liga Chapingo, donde podrás consultar tu estado de cuenta: ' . $bonito . "\n"
                   . $portal;
      ?>
        <tr data-b="<?= h(mb_strtolower(((string)($e['nombre'] ?? '')) . ' ' . ((string)($e['encargado'] ?? '')) . ' ' . $bonito, 'UTF-8')) ?>">
          <td class="eq"><?= h($e['nombre'] ?? '') ?></td>
          <td class="cod"><?= h($bonito) ?></td>
          <td class="enc"><?= h($e['encargado'] ?? '—') ?></td>
          <td><button class="btn" type="button" data-copiar="<?= h($mensaje) ?>">Copiar aviso</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php if (!$equipos): ?>
  <p class="sub">No se encontraron equipos. Revisa que <code>capitanes/config.php</code> apunte a la misma carpeta de datos que <code>arbitrajes/config.php</code>.</p>
<?php endif; ?>
</div>

<script>
document.getElementById('buscar').addEventListener('input', function(ev){
  var q = ev.target.value.trim().toLowerCase();
  Array.prototype.forEach.call(document.querySelectorAll('tr[data-b]'), function(tr){
    tr.classList.toggle('oculto', !!q && tr.getAttribute('data-b').indexOf(q) < 0);
  });
  Array.prototype.forEach.call(document.querySelectorAll('.grupo'), function(g){
    var hay = g.querySelectorAll('tr[data-b]:not(.oculto)').length;
    g.classList.toggle('oculto', !hay);
  });
});
Array.prototype.forEach.call(document.querySelectorAll('[data-copiar]'), function(b){
  b.addEventListener('click', function(){
    var t = b.getAttribute('data-copiar');
    var ok = function(){ var v = b.textContent; b.textContent = 'Copiado'; setTimeout(function(){ b.textContent = v; }, 1400); };
    if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(ok, function(){ window.prompt('Copia el aviso:', t); });
    else window.prompt('Copia el aviso:', t);
  });
});
</script>
</body>
</html>
