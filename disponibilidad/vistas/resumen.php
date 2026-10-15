<?php
/**
 * Vista reutilizable: resumen de lo guardado en MySQL.
 * Recibe $V (ver vista_config() en funciones.php). Sirve para equipos y canchas.
 */
$diasDisp     = count(array_filter($V['por_dia']));
$totalBloques = array_sum(array_map('count', $V['por_dia']));
$horasSem     = horas_semanales($V['por_dia']);
?>
    <!-- 4. RESUMEN (lo que está guardado en MySQL) -->
    <section class="tarjeta" id="resumen">
        <h2 class="tarjeta__titulo"><span class="num"><?= (int)$V['num'][2] ?></span> Resumen de disponibilidad</h2>
        <p class="texto-suave">Esto es exactamente lo que está guardado ahora mismo.</p>

        <div class="cifras">
            <div class="cifra"><b><?= $diasDisp ?></b><span>días disponibles</span></div>
            <div class="cifra"><b><?= $totalBloques ?></b><span>horarios</span></div>
            <div class="cifra"><b><?= e(rtrim(rtrim(number_format($horasSem, 1), '0'), '.')) ?></b><span>horas / semana</span></div>
            <div class="cifra"><b><?= count($V['excepciones']) ?></b><span>excepciones</span></div>
        </div>

        <div class="resumen-semana">
            <?php foreach (DIAS_SEMANA as $dia => $nombreDia): ?>
            <div class="res-dia <?= $V['por_dia'][$dia] ? '' : 'res-dia--vacio' ?>">
                <div class="res-dia__nombre"><?= e($nombreDia) ?></div>
                <?php if (!$V['por_dia'][$dia]): ?>
                    <div class="res-dia__nada">No disponible</div>
                <?php else: foreach ($V['por_dia'][$dia] as $b): ?>
                    <div class="res-bloque">
                        <span><?= e($b['hora_inicio'] . '–' . $b['hora_fin']) ?></span>
                        <form method="post" action="<?= e($V['url']['bloque']) ?>" class="js-confirmar"
                              data-confirmar="¿Eliminar el horario <?= e($nombreDia . ' ' . $b['hora_inicio'] . '–' . $b['hora_fin']) ?>?">
                            <?= vista_ocultos($V, 'bloque') ?>
                            <input type="hidden" name="bloque_id" value="<?= (int)$b['id'] ?>">
                            <button type="submit" class="btn-icono btn-icono--mini" aria-label="Eliminar horario" title="Eliminar horario" <?= $V['editable'] ? '' : 'disabled' ?>>×</button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
