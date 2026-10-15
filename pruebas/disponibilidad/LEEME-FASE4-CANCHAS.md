# Fase 4 · Canchas — ENTORNO DE PRUEBAS

Destino: `/public_html/pruebas/disponibilidad/` (NUNCA en producción). `db.php` no viene en el paquete y no se toca.
Paneles: `/disponibilidad/admin/canchas.php` y `/disponibilidad/admin/coincidencias.php`

## 1. Orden de instalación
1. Sube los archivos (sobrescribe los de la Fase 3; conserva `db.php`).
2. En phpMyAdmin, sobre `u577450088_liga_test`, abre `sql/fase4-canchas.sql`:
   - Ejecuta primero `SHOW COLUMNS FROM semestres LIKE 'id';`. Si dice **unsigned**, cambia `semestre_id INT NOT NULL` por `semestre_id INT UNSIGNED NOT NULL` en las 2 tablas.
   - Ejecuta el resto. Es idempotente: si lo corres dos veces no duplica nada.
3. Si aún no ejecutas el SQL, el sistema **no se rompe**: los equipos y las coincidencias sin cancha funcionan igual que en la Fase 3, y los paneles muestran un aviso.
4. Opcional: borra la carpeta `sql/` del servidor después de ejecutarlo (está protegida por `.htaccess`, pero no hace falta dejarla).

## 2. SQL utilizado (no destructivo)
`CREATE TABLE IF NOT EXISTS` para:
- `canchas`: id, nombre (UNIQUE), ubicacion, activo, creado_en
- `disponibilidad_cancha`: id, cancha_id → canchas, semestre_id → semestres, dia_semana, hora_inicio, hora_fin, activo, creado_en
- `excepciones_cancha`: id, cancha_id → canchas, semestre_id → semestres, fecha, tipo ENUM, hora_inicio, hora_fin, motivo, creado_en

Además, `INSERT … WHERE NOT EXISTS` de **DICIFO**. Las tablas llevan índices por (cancha, semestre, …). No hay DROP, ALTER, DELETE ni UPDATE.

## 3. Archivos
**Nuevos**
| Archivo | Qué hace |
|---|---|
| sql/fase4-canchas.sql (+ .htaccess) | Tablas y DICIFO |
| lib/canchas.php | Catálogo: listar, crear, validar, activar/desactivar, `canchas_instaladas()` |
| lib/acciones.php | Flujo de escritura compartido: guardar semana, eliminar bloque, guardar y eliminar excepción, para **cualquier entidad** |
| admin/canchas.php | Panel: catálogo, semana, excepciones y resumen |
| admin/canchas-accion.php | Único endpoint POST de canchas (CSRF + `requerir_admin()` + acciones en lista blanca) |
| vistas/semana.php, vistas/excepciones.php, vistas/resumen.php | Secciones extraídas de `index.php`, compartidas por equipos y canchas |
| vistas/cabecera-admin.php | Cabecera y menú del panel admin |

**Modificados**
| Archivo | Cambio |
|---|---|
| funciones.php | Registro `ENTIDADES` (equipo / cancha); las funciones de disponibilidad ganan `$entidad = 'equipo'` (opcional, compatible); `preparar_escritura($entidad)`, `redirigir_entidad()`, `obtener_registro()`, `vista_config()`, `vista_ocultos()` |
| lib/disponibilidad.php | `cargarRecurrente()` y `cargarExcepciones()` genéricas; `obtenerDisponibilidadEfectivaEntidadRango()`; **`obtenerDisponibilidadEfectivaCancha()`** y `…CanchaRango()` |
| lib/coincidencias.php | `fuenteCancha()`; validación de la cancha; `comun_equipos` en el resultado; explicación de conflictos por etapas |
| admin/coincidencias.php | Selector de cancha ("Sin cancha" = Fase 3), detalle por fuente, cabecera compartida |
| index.php | Usa las vistas compartidas; se ve y funciona igual |
| guardar-disponibilidad.php, eliminar-bloque.php, guardar-excepcion.php, eliminar-excepcion.php | Llaman a `lib/acciones.php` con `'equipo'` (misma URL y mismo comportamiento) |
| assets/disponibilidad.js, assets/coincidencias.css | Textos neutros (equipo/cancha), menú admin, estilos de canchas |
| tests/casos.php | Casos 1–5 y extras |

## 4. Funciones nuevas
`entidad()`, `obtener_registro()`, `redirigir_entidad()`, `vista_config()`, `vista_ocultos()`, `clave_formulario()`, `cargarRecurrente()`, `cargarExcepciones()`, `obtenerDisponibilidadEfectivaEntidadRango()`, `obtenerDisponibilidadEfectivaCancha()`, `obtenerDisponibilidadEfectivaCanchaRango()`, `fuenteCancha()`, `canchas_instaladas()`, `listar_canchas()`, `obtener_cancha()`, `validar_cancha()`, `crear_cancha()`, `cambiar_estado_cancha()`, `accion_guardar_semana()`, `accion_eliminar_bloque()`, `accion_guardar_excepcion()`, `accion_eliminar_excepcion()`.

## 5. Cómo se extendió el motor de la Fase 3 (sin reescribirlo)
- `calcularCoincidencias()` ya aceptaba N fuentes: la cancha es la **tercera fuente**, con `tipo = 'cancha'`. El núcleo (∩, slots) no cambió.
- La cancha usa **exactamente** `calcularDisponibilidadEfectiva()`: las mismas reglas de excepciones (base ∪ extra − no_disponible; las restricciones ganan).
- Las tablas de equipo y cancha tienen la misma forma. `ENTIDADES` en `funciones.php` es una lista blanca con nombres de tabla y columna. Guardar, validar, solapes, transacciones y eliminar con verificación de pertenencia son **el mismo código** para ambos. Agregar otra entidad = una fila más.
- Explicación de conflictos, derivada del algoritmo en este orden:
  1. algún equipo sin disponibilidad o con excepción;
  2. los equipos no se cruzan;
  3. los equipos coinciden en X, pero la cancha no ese día / no en ese horario (con su horario);
  4. todo ocupado.
- **Siguiente fase (partidos):** `buscarCoincidenciasEquipos($p, $ocupados)` y `calcularCoincidencias(…, $ocupados)` ya restan `[fecha => [[ini, fin], …]]`, probado en `tests/casos.php`. Bastará con cargar los partidos programados de esa cancha y pasarlos ahí.

## 6. Seguridad
- **Autorización centralizada:** `requerir_admin()` → `contexto_acceso()`, sin login nuevo. Se llama en cada página y endpoint de `admin/`; ocultar un enlace no es la protección. Fuera de `pruebas.*` responde 403.
- **Escrituras:** todas por POST + CSRF + validación en servidor.
- **Transacciones:** reemplazo semanal en transacción con `SELECT … FOR UPDATE`.
- **Pertenencia:** borrar un bloque o excepción exige `id + cancha_id + semestre_id`. Probado: no se puede borrar un bloque de un equipo ni de otra cancha manipulando el formulario.
- **Nombres de tabla:** sólo salen de la lista blanca `ENTIDADES`, nunca del navegador.
- **Capitanes (futuro):** los endpoints de equipos marcan dónde entrará `equipo_autorizado()` en lugar de `$_POST['equipo_id']`.

## 7. Resultados de los casos obligatorios (tests/casos.php · 33/33 correctos)
| Caso | Esperado | Obtenido |
|---|---|---|
| 1 · A 16–20 ∩ B 18–21 ∩ Cancha 17–22 | 18:00–20:00 | 18:00–20:00 ✔ |
| 2 · A 16–21 ∩ B 18–22 ∩ Cancha 08–17 | sin coincidencia | sin coincidencia ✔ — "Los equipos coinciden entre 18:00 y 21:00, pero DICIFO no está disponible en ese horario (DICIFO: 08:00–17:00)." |
| 3 · Equipos 18–21, cancha 17–22 con no_disponible | sin coincidencia | sin coincidencia ✔ — "…pero DICIFO no está disponible ese día por excepción registrada." |
| 4 · Cancha sin base + extra 18–20, equipos 17–21 | 18:00–20:00 | 18:00–20:00 ✔ |
| 5 · 18–20, 60 min, cada 30 | 18:00, 18:30, 19:00 | 18:00–19:00, 18:30–19:30, 19:00–20:00 ✔ |

Verificado también contra MySQL local con el mismo esquema:
- **Chapitudos vs Nosotros los Guapos + DICIFO, 28/09–04/10:** lunes 28 con 3 horarios (18:00, 18:30, 19:00).
- **Martes 29:** "Los equipos coinciden 14:00–15:00, 18:00–19:00, pero DICIFO no tiene disponibilidad ese día."
- **Miércoles 30:** "…pero DICIFO no está disponible ese día por excepción registrada."
- **Fases 1 a 3:** siguen pasando.

## 8. Checklist de pruebas manuales
**Instalación**
- [ ] Antes del SQL: `/disponibilidad/` funciona, coincidencias sin cancha funciona y `admin/canchas.php` muestra el aviso "no están instaladas".
- [ ] SQL ejecutado (dos veces) → sólo una DICIFO.

**Canchas** (`admin/canchas.php`)
- [ ] Aparece DICIFO como Activa.
- [ ] Crear "dicifo" → "Ya existe…". Crear "Preparatoria" → se crea y abre su semana.
- [ ] Desactivar Preparatoria → pide confirmación y ya no aparece en el selector de coincidencias. Reactivarla.
- [ ] DICIFO: Lunes 16:00–21:00, Miércoles 14:00–20:00, Sábado 08:00–12:00 + 12:00–16:00 → un solo "Guardar".
- [ ] Sábado 08:00–12:00 + 11:00–16:00 → aviso de solape (navegador y servidor).
- [ ] Excepción 30/09/2026 No disponible (motivo "Mantenimiento") → aparece "Todo el día".
- [ ] Excepción extra sin horas → rechazada. Fecha fuera del semestre → rechazada.
- [ ] Eliminar un bloque desde el resumen y una excepción → piden confirmación.
- [ ] Volver a entrar → se ve exactamente lo guardado (compara con phpMyAdmin: `disponibilidad_cancha`, `excepciones_cancha`).

**Coincidencias** (`admin/coincidencias.php`)
- [ ] Selector "Cancha" con "Sin cancha" + DICIFO (sin las inactivas).
- [ ] Chapitudos vs Guapos, 28/09–04/10, DICIFO, 60 / 30:
  - [ ] Lunes 28: 18:00, 18:30, 19:00
  - [ ] Martes 29 y miércoles 30: explicación de la cancha
- [ ] Misma búsqueda "Sin cancha" → resultados de la Fase 3.
- [ ] "Ver disponibilidad…" muestra equipos, cancha (etiqueta CANCHA), coincidencia de equipos y resultado con cancha.
- [ ] URL manipulada `cancha=999` → "La cancha seleccionada no existe"; cancha inactiva → "está desactivada".

**Seguridad y entorno**
- [ ] `admin/canchas-accion.php` por GET → 405.
- [ ] `/disponibilidad/lib/`, `/tests/` y `/sql/` desde el navegador → bloqueados.
- [ ] Vista de capitán `/disponibilidad/` idéntica a la Fase 2.
- [ ] Móvil, tablet y computadora sin scroll horizontal.
- [ ] Producción sin cambios.
