# Módulo de disponibilidad · Liga Chapingo (fase 2) — ENTORNO DE PRUEBAS

Destino: `/public_html/pruebas/disponibilidad/`  (NUNCA en producción)

## 1. Antes de subir
1. En el Administrador de archivos de Hostinger, renombra el `index.php` actual a `index-anterior.php` (respaldo).
2. **No subas ni reemplaces `db.php`.** Este paquete no lo incluye.
3. Abre tu `db.php` y confirma cómo se llama la variable PDO. `funciones.php` la detecta sola si es
   `$pdo`, `$conn`, `$db`, `$conexion` o una función `conectar()` / `getPDO()`.
   Si usa otro nombre, agrega en `funciones.php`, dentro de `db()`, ese nombre a la lista.

## 2. Archivos
| Archivo | Estado | Función |
|---|---|---|
| index.php | reemplaza | Vista: equipo → semana → excepciones → resumen |
| funciones.php | nuevo | Conexión, CSRF, semestre activo, validaciones, consultas |
| guardar-disponibilidad.php | nuevo | Guarda toda la semana en una transacción |
| eliminar-bloque.php | nuevo | Quita un horario guardado (sólo si es del equipo) |
| guardar-excepcion.php | nuevo | Registra excepción |
| eliminar-excepcion.php | nuevo | Elimina excepción (sólo si es del equipo) |
| assets/disponibilidad.js | nuevo | Bloques dinámicos, validación previa, confirmaciones |
| assets/disponibilidad.css | nuevo | Estilos responsive |
| db.php | **sin cambios** | — |

## 3. SQL
**No se requiere ningún SQL** para que funcione. Tablas y datos existentes se conservan.

Opcional (sólo rendimiento, no destructivo; ejecutar en `u577450088_liga_test` desde phpMyAdmin):
```sql
ALTER TABLE disponibilidad_equipo
  ADD INDEX idx_disp_equipo_sem (equipo_id, semestre_id, activo, dia_semana);
ALTER TABLE excepciones_disponibilidad
  ADD INDEX idx_exc_equipo_sem_fecha (equipo_id, semestre_id, fecha);
```
Verificación recomendada (las transacciones requieren InnoDB):
```sql
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = 'u577450088_liga_test';
```

## 4. Decisiones de diseño
- **Semestre**: sólo `obtener_semestre_activo()` en `funciones.php` decide el semestre (`activo = 1 ORDER BY id DESC LIMIT 1`). No hay `semestre_id = 1` en ningún lado. Sin semestre activo: aviso administrativo y formularios deshabilitados (el servidor también rechaza).
- **Guardar semana**: en una transacción con `SELECT … FOR UPDATE`; compara lo guardado con lo enviado, **conserva** lo que no cambió, **desactiva** (`activo = 0`) lo que se quitó e inserta lo nuevo. No se borran filas de disponibilidad (queda historial) y los duplicados previos se limpian solos.
- **Eliminar**: todo `UPDATE/DELETE` lleva `WHERE id = ? AND equipo_id = ? AND semestre_id = ?`; cambiar el id en el navegador no permite tocar datos de otro equipo.
- **Excepciones**: `no_disponible` sin horas = todo el día; con horas = franja. `disponibilidad_extra` exige horas. Fecha dentro del semestre activo. Rechaza duplicados y solapes en la misma fecha. Las excepciones sí se borran (la tabla no tiene columna `activo`).
- **Seguridad**: PDO con prepared statements, `ATTR_EMULATE_PREPARES=false`, CSRF por sesión en todos los POST, `htmlspecialchars` en toda salida, errores de PDO sólo a `error_log` (el usuario ve un mensaje genérico), POST→redirect→GET.
- **Siguiente fase**: `obtener_disponibilidad()` devuelve bloques por día listos para cruzar Equipo A + Equipo B + cancha; las validaciones de solape (`validar_semana`) se pueden reutilizar para canchas.

## 5. Checklist de pruebas manuales
- [ ] Se ve la franja amarilla "ENTORNO DE PRUEBAS" siempre, también al hacer scroll.
- [ ] El chip del encabezado muestra "Semestre de prueba 2026-2".
- [ ] Al elegir un equipo en el selector, la página cambia sola a ese equipo.
- [ ] Con un equipo que ya tenía horarios (los del index anterior), aparecen cargados en la semana y en el resumen.
- [ ] Activar Lunes y Martes; en Martes poner 13:00–15:00, pulsar "+ Agregar otro horario" y poner 18:00–21:00 (sin recargar).
- [ ] Pulsar **una sola vez** "Guardar disponibilidad semanal" → mensaje verde; el resumen muestra ambos días.
- [ ] Lunes 16:00–20:00 + 18:00–21:00 → mensaje "se enciman" (en el navegador y, si se desactiva JS, desde el servidor).
- [ ] Hora fin igual o menor a inicio → rechazado.
- [ ] Dos bloques idénticos el mismo día → "está repetido".
- [ ] Día marcado como disponible sin horas → mensaje claro.
- [ ] Tras un error, lo capturado no se pierde.
- [ ] Guardar dos veces lo mismo → "No había cambios" y en phpMyAdmin no hay filas duplicadas con `activo = 1`.
- [ ] Quitar un bloque con "×" en el formulario → pide confirmación; al guardar desaparece.
- [ ] Quitar un bloque con "×" en el resumen → pide confirmación y se elimina al momento.
- [ ] Excepción 14/10/2026 "No disponible" sin horas → aparece como "Todo el día".
- [ ] Excepción 18/10/2026 "Disponibilidad extra" 10:00–14:00 → aparece en la lista.
- [ ] "Disponibilidad extra" sin horas → rechazada. Fecha fuera del semestre → rechazada.
- [ ] Misma excepción dos veces → "ya está registrada".
- [ ] Eliminar excepción → pide confirmación y desaparece.
- [ ] Motivo con `<b>hola</b>` → se muestra como texto, no en negritas.
- [ ] Cerrar el navegador, volver a entrar → se ve exactamente lo guardado (comparar con phpMyAdmin).
- [ ] En phpMyAdmin poner `activo = 0` al semestre → aparece aviso administrativo y no deja guardar. Regresarlo a `1`.
- [ ] Probar en teléfono (sin scroll horizontal), tablet y computadora.
- [ ] Confirmar que ligachapingo.com (producción) no cambió.
