<?php
/**
 * Vista reutilizable: disponibilidad semanal con múltiples bloques.
 * Recibe $V (ver vista_config() en funciones.php). Sirve para equipos y canchas.
 */
?>
    <!-- 2. DISPONIBILIDAD SEMANAL -->
    <section class="tarjeta" id="semanal">
        <h2 class="tarjeta__titulo"><span class="num"><?= (int)$V['num'][0] ?></span> Disponibilidad semanal</h2>
        <p class="texto-suave"><?= !empty($V['capitan'])
            ? 'Marca los días en que tu equipo puede jugar. Puedes poner varios horarios en un mismo día. Todo se guarda con un solo botón.'
            : 'Marca los días en que ' . e($V['articulo']) . ' está disponible y agrega uno o varios horarios por día. Todo se guarda con un solo botón.' ?></p>

        <form method="post" action="<?= e($V['url']['semana']) ?>" id="form-semana" novalidate>
            <?= vista_ocultos($V, 'semana') ?>
            <fieldset <?= $V['editable'] ? '' : 'disabled' ?> class="semana">
                <?php foreach (DIAS_SEMANA as $dia => $nombreDia):
                    [$disp, $filas] = filas_formulario($dia, $V['por_dia'], $V['form_semana']);
                    if ($disp && !$filas) $filas = [['id' => null, 'hora_inicio' => '', 'hora_fin' => '']];
                ?>
                <div class="dia <?= $disp ? 'is-on' : '' ?>" data-dia="<?= $dia ?>">
                    <div class="dia__cab">
                        <span class="dia__nombre"><?= e($nombreDia) ?></span>
                        <label class="interruptor">
                            <input type="checkbox" name="dias[<?= $dia ?>][disponible]" value="1" <?= $disp ? 'checked' : '' ?> class="js-toggle-dia">
                            <span class="interruptor__pista" aria-hidden="true"></span>
                            <span class="interruptor__txt" data-on="Disponible" data-off="No disponible"><?= $disp ? 'Disponible' : 'No disponible' ?></span>
                        </label>
                    </div>
                    <div class="dia__bloques js-bloques">
                        <?php foreach ($filas as $i => $f): ?>
                        <div class="bloque" <?= $f['id'] ? 'data-guardado="1"' : '' ?>>
                            <label>
                                <span>Inicio</span>
                                <input type="time" step="900" name="dias[<?= $dia ?>][bloques][<?= $i ?>][inicio]" value="<?= e(hora_corta($f['hora_inicio'])) ?>">
                            </label>
                            <span class="bloque__guion" aria-hidden="true">–</span>
                            <label>
                                <span>Fin</span>
                                <input type="time" step="900" name="dias[<?= $dia ?>][bloques][<?= $i ?>][fin]" value="<?= e(hora_corta($f['hora_fin'])) ?>">
                            </label>
                            <button type="button" class="btn-icono js-quitar" aria-label="Quitar horario" title="Quitar horario">×</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn-agregar js-agregar">+ Agregar otro horario</button>
                    <p class="dia__error js-error" role="alert" hidden></p>
                </div>
                <?php endforeach; ?>
            </fieldset>

            <div class="barra-guardar">
                <span class="js-estado texto-suave" aria-live="polite"></span>
                <button type="submit" class="btn btn--pri" <?= $V['editable'] ? '' : 'disabled' ?>>Guardar disponibilidad semanal</button>
            </div>
        </form>

        <template id="tpl-bloque">
            <div class="bloque">
                <label><span>Inicio</span><input type="time" step="900" data-campo="inicio"></label>
                <span class="bloque__guion" aria-hidden="true">–</span>
                <label><span>Fin</span><input type="time" step="900" data-campo="fin"></label>
                <button type="button" class="btn-icono js-quitar" aria-label="Quitar horario" title="Quitar horario">×</button>
            </div>
        </template>
    </section>
