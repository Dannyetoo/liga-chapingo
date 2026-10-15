# Arquitectura preparada · Propuestas y formalización automática de partidos

> Documento técnico. **Nada de esto está implementado todavía.**
> Guardar disponibilidad hoy **no** crea partidos, reservas ni propuestas.

## Principio central: UNA PROPUESTA NO ES UN PARTIDO
| Acción | ¿Crea partido confirmado? |
|---|---|
| Guardar disponibilidad | **No** |
| Ver coincidencias | **No** (sólo lectura) |
| Enviar propuesta | **No**: crea una propuesta *pendiente* |
| Aceptar propuesta | **Todavía no**: dispara la validación final |
| Validación final atómica exitosa | **Sí**: el partido queda CONFIRMADO |

```
disponibilidad → motor de coincidencias → posibles rivales → propuesta_partido
      → aceptación del rival → VALIDACIÓN ATÓMICA → partido confirmado
```

## Qué ya existe y se reutilizará
| Pieza actual | Uso futuro |
|---|---|
| `calcularDisponibilidadEfectiva()` (lib/disponibilidad.php) | Única regla de excepciones para equipos y canchas |
| `calcularCoincidencias($fuentes, $fechas, $dur, $paso, $ocupados)` | El mismo cálculo al **proponer** y al **aceptar** |
| Parámetro `$ocupados` | Recibirá los partidos confirmados de la cancha y de cada equipo |
| `contexto_acceso()` / `equipo_autorizado()` | El capitán sólo propone y acepta por **su** equipo (sale de la sesión) |
| `ENTIDADES` (funciones.php) | Lista blanca de tablas; se amplía sin SQL dinámico peligroso |

## Validación final (al aceptar) — debe ser ATÓMICA
Dentro de **una transacción** (InnoDB):
1. Bloquear la propuesta (`SELECT … FOR UPDATE`): debe seguir `pendiente` y **vigente** (no vencida).
2. Verificar que quien acepta es el capitán del equipo invitado (sesión, no formulario).
3. Recalcular con el motor (`calcularCoincidencias`), pasando como `$ocupados` los partidos confirmados:
   - disponibilidad efectiva de A, de B y de la cancha **en ese instante**;
   - cancha sin otro partido en ese horario;
   - ningún equipo con otro compromiso en ese horario;
   - jornada correspondiente y enfrentamiento permitido (reglas de competición).
4. Si todo es válido, `INSERT` del partido + propuesta → `aceptada`. Si algo falló → `rechazada_por_sistema`, con el motivo que ya produce `explicarSinCoincidencia()`.
5. `COMMIT`.

Protección contra carreras (doble reserva) como **segunda barrera en la BD**, no sólo en PHP: índices únicos sobre la ocupación. Por ejemplo, una tabla de ocupación por (cancha, fecha, franja) y por (equipo, fecha, franja), o una verificación de solape dentro de la transacción bloqueando las filas de esa cancha y fecha.

## Entidades futuras (NO creadas todavía)
- `jornadas`: número, rango de fechas, categoría, estado.
- `partidos`: jornada, equipo_local, equipo_visitante, cancha, fecha, hora_inicio, hora_fin, estado (confirmado / jugado / cancelado), creado_desde_propuesta_id.
- `propuestas_partido`: jornada, equipo_propone, equipo_invitado, cancha, fecha, hora_inicio, hora_fin, estado (pendiente / aceptada / rechazada / contrapropuesta / vencida / rechazada_por_sistema), vence_en, motivo.
- `reservas_temporales`: retención corta de un slot mientras el rival responde (opcional; decidir si hace falta).
- `notificaciones`: aviso "Nueva propuesta de partido" dentro del Estado de cuenta.

## Evitar desde ya
- Crear partidos al guardar disponibilidad.
- Confiar en un slot calculado en el pasado: siempre se recalcula al aceptar.
- Tomar `equipo_id` del navegador para proponer o aceptar.
