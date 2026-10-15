<?php
/**
 * Vista reutilizable: formulario y lista de excepciones.
 * Recibe $V (ver vista_config() en funciones.php). Sirve para equipos y canchas.
 */
?>
    <!-- 3. EXCEPCIONES -->
    <section class="tarjeta" id="excepciones">
        <h2 class="tarjeta__titulo"><span class="num"><?= (int)$V['num'][1] ?></span> Excepciones<?= empty($V['capitan']) ? ' de disponibilidad' : '' ?></h2>
        <?php if (!empty($V['capitan'])): ?>
            <p class="texto-suave">¿Hay algún día específico en el que no puedan jugar? ¿O un día en que excepcionalmente sí puedan?</p>
        <?php else: ?>
            <p class="texto-suave">Fechas concretas en que <?= e($V['articulo']) ?> <strong>no está disponible</strong> aunque normalmente sí, o en que <strong>tiene disponibilidad extra</strong>.</p>
        <?php endif; ?>

        <?php $fx = $V['form_exc'] ?? ['fecha' => '', 'tipo' => 'no_disponible', 'hora_inicio' => '', 'hora_fin' => '', 'motivo' => '']; ?>
        <?php if (!empty($V['exc_plegado'])): ?>
        <details class="plegable-exc" <?= $V['form_exc'] !== null ? 'open' : '' ?>>
            <summary class="btn btn--sec btn--ancho">+ Agregar excepción</summary>
        <?php endif; ?>
        <form method="post" action="<?= e($V['url']['excepcion']) ?>" class="form-exc" id="form-exc" data-sujeto="<?= e($V['articulo']) ?>" novalidate>
            <?= vista_ocultos($V, 'excepcion') ?>
            <fieldset <?= $V['editable'] ? '' : 'disabled' ?>>
                <div class="rejilla-exc">
                    <label class="campo">
                        <span>Fecha</span>
                        <input type="date" name="fecha" required value="<?= e($fx['fecha']) ?>"
                            <?php if ($V['semestre']): ?>min="<?= e(substr((string)$V['semestre']['fecha_inicio'], 0, 10)) ?>" max="<?= e(substr((string)$V['semestre']['fecha_fin'], 0, 10)) ?>"<?php endif; ?>>
                    </label>
                    <div class="campo">
                        <span>Tipo</span>
                        <div class="segmentado">
                            <label><input type="radio" name="tipo" value="no_disponible" <?= $fx['tipo'] !== 'disponibilidad_extra' ? 'checked' : '' ?>><span>No disponible</span></label>
                            <label><input type="radio" name="tipo" value="disponibilidad_extra" <?= $fx['tipo'] === 'disponibilidad_extra' ? 'checked' : '' ?>><span>Disponibilidad extra</span></label>
                        </div>
                    </div>
                    <label class="campo">
                        <span>Hora inicio <em class="js-opc">(opcional)</em></span>
                        <input type="time" step="900" name="hora_inicio" value="<?= e(hora_corta($fx['hora_inicio'])) ?>">
                    </label>
                    <label class="campo">
                        <span>Hora fin <em class="js-opc">(opcional)</em></span>
                        <input type="time" step="900" name="hora_fin" value="<?= e(hora_corta($fx['hora_fin'])) ?>">
                    </label>
                    <label class="campo campo--ancho">
                        <span>Motivo <em>(opcional)</em></span>
                        <input type="text" name="motivo" maxlength="255" placeholder="Ej. examen departamental, viaje de prácticas…" value="<?= e($fx['motivo']) ?>">
                    </label>
                </div>
                <p class="texto-suave js-ayuda-exc">Si dejas las horas vacías, <?= e($V['articulo']) ?> queda no disponible todo el día.</p>
                <p class="dia__error js-error-exc" role="alert" hidden></p>
                <div class="barra-guardar">
                    <button type="submit" class="btn btn--sec" <?= $V['editable'] ? '' : 'disabled' ?>>Registrar excepción</button>
                </div>
            </fieldset>
        </form>
        <?php if (!empty($V['exc_plegado'])): ?>
        </details>
        <?php endif; ?>

        <h3 class="subtitulo">Excepciones registradas</h3>
        <?php if (!$V['excepciones']): ?>
            <p class="texto-suave">Sin excepciones registradas.</p>
        <?php else: ?>
            <ul class="lista-exc">
                <?php foreach ($V['excepciones'] as $ex): ?>
                <li class="exc exc--<?= e($ex['tipo']) ?>">
                    <div class="exc__fecha"><?= e(fecha_mx((string)$ex['fecha'])) ?></div>
                    <div class="exc__info">
                        <span class="etiqueta etiqueta--<?= e($ex['tipo']) ?>"><?= e(etiqueta_tipo((string)$ex['tipo'])) ?></span>
                        <span class="exc__hora">
                            <?= $ex['hora_inicio'] ? e(hora_corta($ex['hora_inicio']) . '–' . hora_corta($ex['hora_fin'])) : 'Todo el día' ?>
                        </span>
                        <?php if (!empty($ex['motivo'])): ?><span class="exc__motivo"><?= e($ex['motivo']) ?></span><?php endif; ?>
                    </div>
                    <form method="post" action="<?= e($V['url']['eliminar_excepcion']) ?>" class="js-confirmar"
                          data-confirmar="¿Eliminar la excepción del <?= e(fecha_mx((string)$ex['fecha'])) ?>?">
                        <?= vista_ocultos($V, 'eliminar_excepcion') ?>
                        <input type="hidden" name="excepcion_id" value="<?= (int)$ex['id'] ?>">
                        <button type="submit" class="btn-texto btn-texto--peligro" <?= $V['editable'] ? '' : 'disabled' ?>>Eliminar</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
