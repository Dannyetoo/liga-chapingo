# Integración Estado de cuenta → Disponibilidad — ENTORNO DE PRUEBAS

Destino: `/public_html/pruebas/` (**nunca** producción). No se toca `db.php` ni `capitanes/config.php`.

## 1. Diagnóstico del sistema de autenticación existente (`/capitanes/`)
- **Acceso:** el capitán escribe un código de 8 caracteres. `api.php?a=entrar` lo compara, en tiempo constante, con `codigoEquipo(id)`, que es un HMAC-SHA256 de `SEMILLA_CAPITANES` sobre cada equipo de `arbitrajes/datos/estado.json`. Los códigos no se guardan en ningún lado. Hay freno de 20 intentos por IP cada 15 min.
- **Sesión:** PHP propia, `session_name('lchcap')`, cookie limitada a la ruta **`/capitanes/`**, HttpOnly, SameSite=Lax. Guarda `$_SESSION['rol'] = 'capitan'` y **`$_SESSION['equipoId']` = id del equipo en estado.json** (única fuente de verdad). Expira tras `MINUTOS_SESION_CAPITAN` (120) min sin actividad.
- **Datos:** financieros en JSON, sólo lectura. El id del equipo **no** es `equipos.id` de MySQL (p. ej. JSON «París San Germán FC», MySQL «PSG»).
- **Consecuencia:** `/disponibilidad/` no puede leer la sesión `lchcap`, porque su cookie no viaja fuera de `/capitanes/`, y no debe poder hacerlo.

## 2. Cómo se vinculó Estado de cuenta ↔ equipos.id
**a) Pase firmado de un solo uso** (paso entre módulos, sin segundo login):
1. El capitán, ya dentro de su Estado de cuenta, pulsa **«Disponibilidad del equipo»**.
2. `capitanes/api.php?a=disponibilidad` (POST) exige la sesión de capitán abierta. Firma un pase con: el `equipoId` **de la sesión**, un vencimiento de **2 minutos** y un **nonce** aleatorio.
   - La clave se deriva de `SEMILLA_CAPITANES` con otra etiqueta, así que un pase no sirve como código ni al revés.
   - Esta acción no lee ni escribe finanzas.
3. El navegador envía el pase por **POST** a `/disponibilidad/entrar.php`, así que no queda en la URL, el historial ni los registros.
4. `entrar.php` comprueba:
   - la firma;
   - la vigencia;
   - que el nonce no se haya usado (registro con bloqueo de archivo en `disponibilidad/datos/`, protegido por `.htaccess`);
   - que la petición venga del mismo sitio.

   Después traduce el id del JSON a `equipos.id` con el vínculo explícito y abre el contexto de capitán.

**b) Vínculo explícito**, tabla `equipos_estado_cuenta` (`estado_equipo_id` UNIQUE → `equipo_id`):
- Se administra en **`admin/vinculos.php`**. Lista los equipos del Estado de cuenta (leídos del JSON, sólo lectura) y, para cada uno, permite:
  - vincular con un equipo existente;
  - crear el equipo en MySQL y vincularlo en una transacción;
  - «Crear y vincular todos los pendientes».
- **Nunca se empareja por nombre.** `estado_nombre` es sólo referencia visual.
- Un equipo sin vínculo **no entra**: ve «Tu equipo aún no está dado de alta. Avisa a la mesa directiva.»

**c) Sesiones aisladas:** `/disponibilidad/` usa su propia cookie `lchdisp`, con ruta `/disponibilidad/`. No se mezcla con `lchcap` ni con `/arbitrajes/`.

**d) «Volver a mi estado de cuenta»** cierra la sesión de disponibilidad y regresa al portal, que sigue abierto. Para volver a entrar se pulsa otra vez el botón, sin escribir el código. Así no quedan sesiones abiertas en teléfonos compartidos.

## 3. SQL (no destructivo) — `sql/integracion-capitanes.sql`
```sql
SHOW COLUMNS FROM equipos LIKE 'id';   -- si dice "unsigned": equipo_id INT UNSIGNED
CREATE TABLE IF NOT EXISTS equipos_estado_cuenta (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipo_id INT NOT NULL,
  estado_equipo_id VARCHAR(64) NOT NULL,
  estado_nombre VARCHAR(150) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_estado_equipo (estado_equipo_id),
  KEY idx_eec_equipo (equipo_id),
  CONSTRAINT fk_eec_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
Además, cuando se usa «Crear nuevo y vincular», se hacen `INSERT` en `equipos` y en `equipos_estado_cuenta`. No hay ALTER, DROP ni DELETE sobre datos existentes. «Quitar vínculo» borra sólo la fila del vínculo, nunca el equipo ni sus horarios.

## 4. Archivos
**`/capitanes/` (3 modificados; `config.php` sin tocar):**

| Archivo | Cambio |
|---|---|
| `codigos-lib.php` | +funciones del pase: `paseDisponibilidad()`, `verificaPaseDisponibilidad()`, `clavePuente()` |
| `api.php` | +acción `a=disponibilidad` (POST, requiere sesión, no toca finanzas) |
| `index.html` | +botón «Disponibilidad del equipo» (estilo del portal, visible en teléfono) y su JavaScript |

Detalle línea por línea en `capitanes/CAMBIOS.diff`.

**`/disponibilidad/`**
- **Creados:**
  - `lib/vinculos.php`, `admin/vinculos.php`, `sql/integracion-capitanes.sql`;
  - `admin/equipos.php` (resumen ✓ / ○), `admin/simular-capitan.php` (sólo pruebas), `salir.php`;
  - `docs/ARQUITECTURA-PROPUESTAS.md`, `docs/boton-estado-cuenta.html`.
- **Modificados:**
  - `lib/puente-capitanes.php`: puente real.
  - `entrar.php`: recibe el pase.
  - `lib/acceso.php`: contexto capitán/admin y `equipo_objetivo_escritura()`.
  - `funciones.php`: sesión propia `lchdisp`, la escritura de equipos usa la sesión, canchas sólo admin, `url_retorno_segura()`, `resumen_registro_equipos()`.
  - `index.php`: modos capitán / admin / sin acceso.
  - `vistas/semana.php`, `vistas/excepciones.php`, `vistas/cabecera-admin.php`, `lib/coincidencias.php` (comentario del principio), CSS.

## 5. Medidas de autorización
- El equipo del capitán sale **sólo** de `$_SESSION['disp_capitan']`, que únicamente abre `entrar.php` con un pase válido. `?equipo=` se ignora y los formularios del capitán no envían `equipo_id`.
- Cada escritura (guardar semana, eliminar bloque, guardar o eliminar excepción) pasa por `equipo_objetivo_escritura()`. Si llega un `equipo_id` distinto (URL, POST, arreglo, JavaScript, petición manual), se **rechaza** y queda en el log.
- Eliminar exige `id + equipo de la sesión + semestre`.
- Todo `admin/` (equipos, vínculos, coincidencias, canchas, simulador) pasa por `requerir_admin()`: un capitán recibe 403. Fuera de `pruebas.*` también es 403 (en producción aún no hay rol admin).
- `session_regenerate_id` al entrar y al salir, token CSRF nuevo por identidad, `use_strict_mode`, HttpOnly, SameSite=Lax, Secure con HTTPS, 2 h de inactividad.
- Pase: HMAC-SHA256, 2 min, un solo uso, POST, verificación de origen. Si la semilla no está configurada, el botón responde «todavía no está habilitada» (falla cerrado).

## 6. Resultados de las pruebas (réplica local con los archivos reales del portal)
| # | Paso | Resultado |
|---|---|---|
| 1–2 | Entrar al Estado de cuenta con el código de «París San Germán FC» | Portal abierto; botón visible arriba de Movimientos |
| 3–6 | Pulsar el botón | Llega a `/disponibilidad/` con **PSG**, sin selector |
| – | Equipo sin vínculo | «Tu equipo aún no está dado de alta» (403) |
| 7–8 | Lunes 16:00–20:00 + Miércoles 18:00–21:00 → Guardar | Guardado en `equipo_id` 1 |
| 9 | «Volver a mi estado de cuenta» | Regresa al portal (sigue abierto); la sesión de disponibilidad se cierra |
| 10–11 | Pulsar otra vez el botón | Mismos horarios, sin volver a escribir el código |
| 12–13 | `?equipo=2`, POST con `equipo_id=2` / `equipo_id[]=2`, borrar bloques y excepciones de Monkey | Rechazados; **Monkey CFC sin cambios** |
| – | Reusar el pase / alterarlo / firmarlo con otra semilla / vencido / desde otro origen | Rechazado |
| – | Capitán abre `admin/*` o intenta `simular-capitan` | 403 |
| 14 | Administración → Equipos | PSG ✓ con 2 días y 2 horarios |

Cookies verificadas: `lchcap` → `/capitanes/` y `lchdisp` → `/disponibilidad/`, ambas HttpOnly. Motor: 33/33 casos. Coincidencias con cancha y vista admin, iguales que en la Fase 4.

## 7. Instalación (en este orden)
1. **Respaldo** de `capitanes/api.php`, `capitanes/codigos-lib.php` y `capitanes/index.html`.
2. Sube `/disponibilidad/` (sin `db.php`). Debe estar junto a `/capitanes/`, ambos dentro de `/public_html/pruebas/`.
3. Ejecuta `sql/integracion-capitanes.sql` en `u577450088_liga_test`, revisando antes el tipo de `equipos.id`.
4. Sube los 3 archivos de `capitanes/`.
5. Confirma que `capitanes/config.php` empieza con `<?php` y que `SEMILLA_CAPITANES` es la real (≥ 16 caracteres).
6. Abre `disponibilidad/admin/vinculos.php` y vincula cada equipo del Estado de cuenta (o usa «Crear y vincular todos los pendientes»).
7. Haz la prueba de aceptación con un código real (checklist).

## 8. Checklist de pruebas
- [ ] `admin/vinculos.php` lista los equipos reales del Estado de cuenta con su edición y categoría.
- [ ] Vincular el Equipo A con su equipo de disponibilidad.
- [ ] Entrar al Estado de cuenta del Equipo A con su código → aparece «Disponibilidad del equipo».
- [ ] Pulsar → se ve el nombre del Equipo A, sin selector de equipo.
- [ ] Registrar Lunes 16:00–20:00 y Miércoles 18:00–21:00 → Guardar.
- [ ] «Volver a mi estado de cuenta» → regresa al portal, sin pedir código.
- [ ] Pulsar el botón otra vez → se ven exactamente esos horarios.
- [ ] Cambiar la URL a `?equipo=<id de otro equipo>` → sigue mostrando el Equipo A.
- [ ] Con las herramientas del navegador, editar el formulario para añadir `equipo_id` de otro equipo → «Sólo puedes modificar la disponibilidad de tu equipo.»
- [ ] Recargar el pase (botón Atrás tras entrar) → «El acceso ya se usó».
- [ ] Un equipo sin vínculo → «Tu equipo aún no está dado de alta».
- [ ] Administración → Equipos: el Equipo A aparece con ✓. En phpMyAdmin, `disponibilidad_equipo` tiene los horarios con el `equipo_id` correcto.
- [ ] El Estado de cuenta sigue mostrando cargos, pagos, tickets y multas igual que antes.
- [ ] Producción sin cambios.

## 9. Confirmación: qué NO se implementó
No hay generación de partidos ni de rivales, jornadas, propuestas, aceptación, contrapropuestas, reservas, notificaciones, WhatsApp, correo, bloqueo de cancha, calendario ni optimización. **Guardar disponibilidad no crea partidos.**

## 10. Qué queda preparado para propuestas y formalización
- **Identidad lista para proponer y aceptar:** `equipo_autorizado()` da el equipo del capitán desde la sesión; proponer y aceptar usarán exactamente eso.
- **Mismo cálculo al proponer y al aceptar:** `calcularCoincidencias(..., $ocupados)` ya descuenta horarios ocupados.
- **Motivo de rechazo listo:** `explicarSinCoincidencia()` da el motivo cuando la validación final rechace una propuesta.
- **Canal para notificaciones:** el pase firmado es el patrón para llevar al capitán desde su Estado de cuenta a otras pantallas futuras (p. ej. «Nueva propuesta de partido»).
- **Diseño documentado:** `docs/ARQUITECTURA-PROPUESTAS.md` recoge el principio **«una propuesta no es un partido»**, la validación atómica en transacción y las tablas futuras (jornadas, partidos, propuestas_partido, notificaciones, reservas_temporales). Ninguna está creada todavía.
