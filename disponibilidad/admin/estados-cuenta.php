<?php
/**
 * Administración · ACCESO DIRECTO A LOS ESTADOS DE CUENTA (30-sep-2026).
 *
 * Lista los equipos del registro de Tesorería con su código de capitán (8 caracteres) y un botón
 * que abre /capitanes/ con ese código ya escrito: el portal entra solo, sin recordar el dispositivo.
 * El código viaja en el «#» de la dirección (no llega al servidor ni a sus registros) y el portal
 * lo borra de la barra en cuanto lo lee.
 *
 * Sólo lectura. Requiere permiso de finanzas: con el código se ve el estado de cuenta del equipo.
 */
declare(strict_types=1);
require_once __DIR__ . '/../funciones.php';
require_once __DIR__ . '/../lib/vinculos.php';

requerir_finanzas();
cabeceras_admin();
$semestre     = obtener_semestre_activo();
$tituloPagina = 'Estados de cuenta';
$paginaActual = 'estados';

$error = null;
$grupos = [];          // «Edición · Categoría» => [equipos]
$ediciones = [];
$edSel = '';
try {
    if (!cargar_portal_capitanes()) {
        $error = 'No se encontró el portal de capitanes (/capitanes/).';
    } else {
        $d = leerEstadoArbitrajes();
        if ($d === null) {
            $error = 'No se pudo leer el registro de Tesorería.';
        } else {
            $S = $d['estado'];
            $nom = function ($lista, string $id): string {
                foreach ((array)$lista as $x) if (is_array($x) && (string)($x['id'] ?? '') === $id) return (string)($x['nombre'] ?? '');
                return '';
            };
            $ordenEd = [];
            foreach ((array)($S['ediciones'] ?? []) as $i => $x) if (is_array($x)) $ordenEd[(string)($x['id'] ?? '')] = $i;
            $usadas = [];
            foreach (equiposDelEstado($S) as $e) {
                if (!is_array($e) || (string)($e['id'] ?? '') === '') continue;
                $usadas[(string)($e['edicionId'] ?? '')] = true;
            }
            foreach ($ordenEd as $id => $i) if (isset($usadas[$id])) $ediciones[$id] = $nom($S['ediciones'] ?? [], $id);
            $ediciones = array_reverse($ediciones, true);          // la más reciente primero
            $pedida = (string)($_GET['edicion'] ?? '');
            $edSel = isset($ediciones[$pedida]) || $pedida === 'todas' ? $pedida : (string)(array_key_first($ediciones) ?? '');

            foreach (equiposDelEstado($S) as $e) {
                if (!is_array($e) || (string)($e['id'] ?? '') === '') continue;
                $ed = (string)($e['edicionId'] ?? '');
                if ($edSel !== 'todas' && $ed !== $edSel) continue;
                $cat = $nom($S['categorias'] ?? [], (string)($e['categoriaId'] ?? ''));
                $rama = $nom($S['ramas'] ?? [], (string)($e['ramaId'] ?? ''));
                $disc = $nom($S['disciplinas'] ?? [], (string)($e['disciplinaId'] ?? ''));
                $g = implode(' · ', array_filter([$edSel === 'todas' ? $nom($S['ediciones'] ?? [], $ed) : '', $cat, $disc, $rama], 'strlen')) ?: 'Sin categoría';
                $cod = codigoEquipo((string)$e['id']);
                $grupos[$g][] = [
                    'nombre'    => (string)($e['nombre'] ?? ''),
                    'encargado' => (string)($e['encargado'] ?? ''),
                    'codigo'    => $cod,
                    'bonito'    => codigoBonito($cod),
                ];
            }
            ksort($grupos, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($grupos as &$lista) usort($lista, fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));
            unset($lista);
        }
    }
} catch (Throwable $ex) {
    $error = error_interno($ex, 'estados de cuenta');
}
$total = array_sum(array_map('count', $grupos));
$portal = '../../capitanes/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Estados de cuenta · Administración · Liga Chapingo<?= es_entorno_pruebas() ? ' (pruebas)' : '' ?></title>
    <link rel="stylesheet" href="../assets/disponibilidad.css?v=6">
    <link rel="stylesheet" href="../assets/estados-cuenta.css?v=20260930">
    <script src="../assets/estados-cuenta.js?v=20260930" defer></script>
</head>
<body>
<?php require __DIR__ . '/../vistas/cabecera-admin.php'; ?>
<main class="contenedor ec">
<?php if ($error): ?>
    <div class="aviso aviso--error" role="alert"><?= e($error) ?></div>
<?php else: ?>
    <section class="tarjeta">
        <div class="ec-cab">
            <div>
                <h2 class="tarjeta__titulo">Estados de cuenta de los capitanes</h2>
                <p class="texto-suave">«Abrir» entra al portal de capitanes con el código del equipo ya puesto. Es lo mismo que ve el capitán.</p>
            </div>
            <a class="btn btn--sec btn--chico" href="<?= e($portal) ?>" target="_blank" rel="noopener">Portal de capitanes ↗</a>
        </div>
        <form method="get" action="estados-cuenta.php" class="ec-filtros">
            <label>Edición
                <select name="edicion" data-autoenvio>
                    <?php foreach ($ediciones as $id => $n): ?><option value="<?= e($id) ?>" <?= $edSel === $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                    <option value="todas" <?= $edSel === 'todas' ? 'selected' : '' ?>>Todas</option>
                </select>
            </label>
            <label class="ec-buscar">Buscar
                <input type="search" placeholder="Equipo o encargado…" data-buscar autocomplete="off">
            </label>
            <noscript><button type="submit" class="btn btn--sec btn--chico">Ver</button></noscript>
            <span class="texto-suave ec-total" data-total><?= $total ?> equipos</span>
        </form>
    </section>

    <?php if (!$grupos): ?>
        <p class="texto-suave">No hay equipos en esta edición.</p>
    <?php endif; ?>
    <?php foreach ($grupos as $g => $lista): ?>
    <section class="tarjeta ec-grupo" data-grupo>
        <h3 class="ec-grupo__t"><?= e($g) ?> <span class="ec-chip"><?= count($lista) ?></span></h3>
        <ul class="ec-lista">
        <?php foreach ($lista as $q): ?>
            <li class="ec-eq" data-texto="<?= e(mb_strtolower($q['nombre'] . ' ' . $q['encargado'])) ?>">
                <div class="ec-eq__info">
                    <b><?= e($q['nombre']) ?></b>
                    <?php if ($q['encargado'] !== ''): ?><small class="texto-suave"><?= e($q['encargado']) ?></small><?php endif; ?>
                </div>
                <code class="ec-cod" data-cod="<?= e($q['bonito']) ?>" aria-label="Código oculto">••••-••••</code>
                <div class="ec-acc">
                    <button type="button" class="btn-texto" data-ver>Ver</button>
                    <button type="button" class="btn-texto" data-copiar="<?= e($q['bonito']) ?>">Copiar</button>
                    <a class="btn btn--pri btn--chico" href="<?= e($portal . '#codigo=' . rawurlencode($q['codigo'])) ?>" target="_blank" rel="noopener noreferrer">Abrir ↗</a>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
    <p class="texto-suave ec-nota">Al abrir un estado de cuenta desde aquí, el portal no «recuerda» el dispositivo. Si en este navegador ya había otro equipo abierto en el portal, se reemplaza.</p>
<?php endif; ?>
</main>
</body>
</html>
