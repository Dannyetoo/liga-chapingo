# Botón «Disponibilidad del equipo» en el Estado de cuenta

`index.html` es una sola página para todos los equipos, así que el botón aparece
automáticamente en el Estado de cuenta de **cada** equipo, arriba de «Movimientos».
Cada capitán llega sólo a la disponibilidad de SU equipo (la identidad sale de su sesión).

## Subir a /public_html/pruebas/capitanes/ (respalda antes los originales)
1. `codigos-lib.php`  → funciones del pase firmado
2. `api.php`          → acción `a=disponibilidad`
3. `index.html`       → el botón
(`config.php` NO se toca. Detalle de cambios: `CAMBIOS.diff`.)

## Requisitos para que el botón funcione
- `/disponibilidad/` (paquete de integración) subido junto a `/capitanes/`.
- Tabla `equipos_estado_cuenta` creada (`integracion-capitanes.sql`).
- Cada equipo vinculado en `/disponibilidad/admin/vinculos.php`.
  Si un equipo aún no está vinculado, el botón se ve igual, pero al pulsarlo el capitán
  lee «Tu equipo aún no está dado de alta. Avisa a la mesa directiva.»
- `SEMILLA_CAPITANES` real en `config.php` (≥ 16 caracteres).
