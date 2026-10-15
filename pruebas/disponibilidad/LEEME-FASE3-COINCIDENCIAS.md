# Fase 3 · Motor de coincidencias entre equipos — ENTORNO DE PRUEBAS

Destino: `/public_html/pruebas/disponibilidad/` (NUNCA en producción). `db.php` no viene en el paquete y no se toca.
Panel: `https://pruebas.ligachapingo.com/disponibilidad/admin/coincidencias.php`

## Archivos
**Nuevos**
| Archivo | Qué hace |
|---|---|
| lib/intervalos.php | Álgebra pura de horarios: unir, intersectar N listas, restar, generar slots. Sin BD. |
| lib/disponibilidad.php | `calcularDisponibilidadEfectiva()` (pura) y `obtenerDisponibilidadEfectiva(equipoId, fecha)` / `…Rango()`. Único lugar con las reglas de excepciones. |
| lib/coincidencias.php | Motor genérico por "fuentes" + validación de la consulta. |
| lib/acceso.php | Contexto capitán/admin preparado (sin login nuevo). |
| lib/.htaccess, tests/.htaccess | Bloquean el acceso web a esas carpetas. |
| admin/coincidencias.php | Panel administrativo (sólo lectura, por GET). |
| assets/coincidencias.css | Estilos del panel. |
| tests/casos.php | Casos A–E + extras. Se ejecuta por consola: `php tests/casos.php`. |

**Modificados (mínimo)**
- `funciones.php`: +1 línea `require_once __DIR__ . '/lib/acceso.php';`
- `index.php`: el pie muestra un enlace al panel sólo en el entorno de pruebas.

**SQL:** ninguno. El motor sólo lee (`SELECT`).

## Arquitectura
```
admin/coincidencias.php  (pantalla: formulario + resultados)
        │
lib/coincidencias.php    validarConsultaCoincidencias() → buscarCoincidenciasEquipos()
        │                calcularCoincidencias(fuentes[], fechas, duración, paso, ocupados[])
        │                   └─ fuente = {tipo, id, nombre, por_fecha}  ← hoy equipo; mañana cancha
lib/disponibilidad.php   obtenerDisponibilidadEfectivaRango()  (2 consultas por fuente)
        │                calcularDisponibilidadEfectiva()      (reglas de excepciones, pura)
lib/intervalos.php       ∪  ∩  −  slots   (minutos desde 00:00)
```
Reglas de disponibilidad efectiva:
1. Base = bloques recurrentes activos del día de la semana.
2. \+ `disponibilidad_extra` de la fecha.
3. − `no_disponible` de la fecha. Sin horas = todo el día → `[]`; con horas = sólo se resta esa franja. **Las restricciones siempre ganan.**

Slots: los inicios se alinean a la rejilla del intervalo (con 30 min → :00 y :30) y nunca terminan fuera de la coincidencia.
Límites: duración de 20 a 180 min en múltiplos de 5 (sugeridas 40–90); intervalo 10/15/20/30/60; rango máximo de 62 días dentro del semestre.

**Siguiente fase (canchas):** crear `fuenteCancha()` con la misma forma y pasarla como tercera fuente. Los horarios ocupados entran en el parámetro `$ocupados` (se restan con `intervalos_restar`). No hay que tocar el motor.

**Integración con capitanes (futuro):** `lib/acceso.php → contexto_acceso()` es el único punto donde se leerá la sesión del Estado de cuenta. Los endpoints de escritura deberán usar `equipo_autorizado()` en lugar de `$_POST['equipo_id']` o `?equipo=`. El selector de equipos es sólo de pruebas/administración. Mientras tanto, el panel admin responde 403 fuera de `pruebas.*` / localhost.

## Resultados de los casos A–E (tests/casos.php — 19/19 correctos)
| Caso | Entrada | Esperado | Obtenido |
|---|---|---|---|
| A | Lun 16:00–20:00 ∩ Lun 18:00–21:00 | 18:00–20:00 | 18:00–20:00 ✔ |
| B | (13–15, 18–21) ∩ 14–19 | 14:00–15:00, 18:00–19:00 | 14:00–15:00, 18:00–19:00 ✔ |
| C | Coinciden, A con no_disponible ese día | ningún horario | [] + "PSG no está disponible por excepción registrada." ✔ |
| D | A sin base + extra 10–14; B 09–12 | 10:00–12:00 | 10:00–12:00 ✔ |
| E | 18–20, 60 min, cada 30 | 18:00–19:00, 18:30–19:30, 19:00–20:00 | igual ✔ |

También se verificó contra MySQL local con el mismo esquema (Chapitudos vs Nosotros los Guapos, 28/09–18/10): mismos resultados, 32 slots en 9 fechas.

## Datos para probar en staging (desde la vista del capitán, `/disponibilidad/`)
Usa dos equipos sin horarios o ajusta los que tengas:
- **Chapitudos:** Lunes 16:00–20:00 · Martes 13:00–15:00 y 18:00–21:00 · Miércoles 16:00–21:00 · Excepción 14/10/2026 No disponible (sin horas) · Excepción 18/10/2026 Disponibilidad extra 10:00–14:00.
- **Nosotros los Guapos:** Lunes 18:00–21:00 · Martes 14:00–19:00 · Miércoles 17:00–21:00 · Domingo 09:00–12:00.

## Checklist de pruebas manuales
- [ ] El panel abre en pruebas y muestra la franja "ENTORNO DE PRUEBAS" y el semestre activo.
- [ ] Chapitudos vs Guapos, 12/10–18/10, 60 min, cada 30:
  - [ ] Lunes 12: 18:00, 18:30, 19:00 (caso A/E)
  - [ ] Martes 13: 14:00–15:00 y 18:00–19:00 (caso B)
  - [ ] Miércoles 14: sin coincidencia, "Chapitudos no está disponible por excepción registrada" (caso C)
  - [ ] Domingo 18: 10:00, 10:30, 11:00 (caso D)
- [ ] "Ver disponibilidad de cada equipo" muestra los bloques de cada uno y el resultado.
- [ ] "Fechas sin coincidencia" explica cada fecha.
- [ ] Duración 90 → el lunes sólo 18:00–19:30 y 18:30–20:00; nunca un slot que termine después de las 20:00.
- [ ] Intervalo 60 → sólo inicios en hora cerrada.
- [ ] Dos equipos sin cruce → "Los equipos no tienen horarios compatibles durante el periodo seleccionado."
- [ ] Mismo equipo en A y B → error. Fecha final antes de la inicial → error. Rango > 62 días → error. Fechas fuera del semestre → error.
- [ ] Alterar la URL (`duracion=7`, `paso=17`, `equipo_b=999`) → mensajes de error claros, sin errores de PHP/PDO.
- [ ] Marcar un equipo con `activo = 0` → "no está activo". Regresarlo a 1.
- [ ] Después de buscar, en phpMyAdmin no cambió ninguna tabla (herramienta sólo de lectura).
- [ ] `/disponibilidad/tests/casos.php` y `/disponibilidad/lib/…` desde el navegador → bloqueados (403/404).
- [ ] La vista del capitán `/disponibilidad/` sigue funcionando igual que antes.
- [ ] Móvil, tablet y computadora sin scroll horizontal.
- [ ] Producción (ligachapingo.com) sin cambios.
