# Panel de coordinación (perfil de coordinador)

Módulo independiente. Sube la carpeta `coordinador/` completa a `public_html/`.
Se abre en `https://TU-DOMINIO/coordinador/`. No necesita base de datos ni SQL, y no modifica
ningún otro módulo del sitio.

## Acceso
- **Coordinador:** contraseña inicial `Coord-3KM5-FRC3` (está en `config.php`). En la primera
  entrada el panel obliga a cambiarla; la nueva queda cifrada en `datos/acceso.json`.
  Si se olvida: borra `datos/acceso.json` y entra otra vez con la inicial.
- **Capitán (sólo lectura):** pestaña «Capitán» de la misma página, con el código de su equipo
  (Equipos → ficha del equipo → «Acceso del capitán»). Sólo ve lo de su equipo.

## Qué incluye
| Módulo | Contenido |
|---|---|
| Inicio | Indicadores con enlace, accesos rápidos (equipo, torneo, partido, resultado, pago), próximos partidos, pendientes y adeudos |
| Torneos | Temporadas, categorías y torneos: formato, fechas, equipos, estado, puntuación y desempate; generador de rol |
| Equipos | Alta/edición, capitán, inscripción, plantilla, calendario, resultados, estado de cuenta, historial y «Ver como capitán» |
| Calendario | Jornadas y partidos (fecha, hora, sede, cancha), lista o mes, filtros, reprogramar/suspender/cancelar con motivo, cruces de horario |
| Resultados | Captura y corrección con motivo e historial, tablas automáticas, validaciones de duplicados y estados |
| Finanzas | Conceptos, cargos, pagos, ajustes, gastos, estados de cuenta, cortes de caja y cierre de temporada (MXN, DD/MM/AAAA, hora CDMX) |
| Configuración | Nombre, logotipo, color, contacto, sedes y canchas, contraseña, datos y bitácora |

## Datos
- Se guardan **en el servidor**, en `coordinador/datos/` (carpeta cerrada a internet):
  `real.json` (tu liga) y `demo.json` (demostración). Nunca se mezclan.
- Una copia por día de cada uno en `datos/respaldos/` (se conservan 30).
- La carpeta `datos/` necesita permiso de escritura (normalmente ya lo tiene).
- «Copiar equipos desde Tesorería» sólo LEE `arbitrajes/datos/estado.json`; no copia cobros.

## Archivos
`index.html`, `estilos.css`, `api.php`, `config.php`, `.htaccess`, `datos/.htaccess`,
`js/` (nucleo, demo, inicio, torneos, equipos, calendario, resultados, finanzas, configuracion,
capitan, arranque).

## Limitaciones (pendientes para un modelo de renta)
- Una sola liga por instalación y un solo usuario coordinador (una contraseña).
- Sin pagos en línea, suscripciones, facturación ni avisos externos.
- Guardado completo por cambio: pensado para capturar desde un dispositivo a la vez.
- Eliminación directa y grupos: los cruces de fases siguientes se arman a mano; sin penales aparte.
- Los datos viven en archivos JSON; para muchas ligas o mucho volumen conviene pasar a base de datos.
