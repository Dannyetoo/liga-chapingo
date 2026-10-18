# Sistema de competencia y espacios digitales

Implementación PHP/PDO y JavaScript sin compilación. El código se incorpora en `/competencia/`.

## Relaciones y permisos

`cp_usuarios (administrador) → cp_espacios.propietario_id → coordinador_id → cp_equipos → usuario_id`.
Cada edición tiene un espacio y varias categorías. Partidos, movimientos e incidencias pertenecen al espacio. Las claves foráneas compuestas impiden mezclar equipos de distintos espacios. El administrador consulta todos los torneos; sólo escribe en los propios. El coordinador opera exclusivamente sus espacios y no asigna coordinadores. El equipo consulta sus partidos y su cuenta, y puede reportar incidencias desde Match. Las contraseñas se cifran con `password_hash`; restablecerlas o desactivar un equipo invalida sus sesiones.

## Instalación

Requiere PHP 7.4+ con PDO MySQL, mbstring y JSON, MySQL 5.7+ o MariaDB y HTTPS. Se reutiliza la conexión de `disponibilidad/db.php`. No se necesita acceso de escritura a los archivos de Tesorería.

1. Ejecutar `schema.sql` en la base seleccionada del hosting. No contiene DROP ni cambia tablas anteriores.
2. En la terminal del hosting, revisar la migración (sin escritura):

```sh
php competencia/migrar.php --admin=TU_USUARIO_ADMIN --edicion=ed3
```

3. Definir `LC_JONATHAN_CLAVE` como secreto del entorno (mínimo 12 caracteres) y aplicar:

```sh
php competencia/migrar.php --admin=TU_USUARIO_ADMIN --edicion=ed3 --aplicar
```

El administrador debe existir y estar activo en `admin_usuarios`; se copia su hash a su cuenta personal nueva. Jonathan se crea como `jonathan.pina`. No se imprimen contraseñas. La migración abarca todos los equipos de la edición sin filtrar activos; en la copia local son **48**. Conserva `origen_ref`, no duplica cobros ni restablece cuentas al repetirse. Si hay asignaciones incompatibles, revierte toda la transacción.

4. Volver a ejecutar `schema.sql` si el módulo ya estaba instalado: añade `cp_codigos_migrados` sin alterar las tablas existentes. Hacerlo antes de desplegar la API nueva o ejecutar la migración. Abrir `/competencia/`; en Usuarios aparece el nombre de acceso de cada equipo. Los equipos sin cuenta reciben un usuario estable `equipo-…` y conservan su código de capitán como contraseña (con o sin guion, mayúsculas o minúsculas). Sólo se guarda su hash. Las cuentas existentes, desactivaciones y contraseñas restablecidas se respetan al repetir la migración. Distribuir los nombres de usuario por el canal habitual; no hace falta cambiar los códigos. Los accesos nuevos o restablecidos manualmente siguen usando contraseñas aleatorias.
5. Verificar permisos con cuentas de dos administradores, coordinadores de distintos espacios y dos equipos. Tras verificar, definir `LC_COMPETENCIA_ACTIVA=1` en el hosting para que `/coordinador/` y `/capitanes/` redirijan al panel unificado y sus APIs anteriores dejen de aceptar sesiones. El corte afecta a todos los equipos: entregar primero los accesos de las ediciones que deban seguir consultándose. Para revertir ese enrutamiento, retirar la variable; el libro nuevo permanece en SQL.

## Importar partidos del Coordinador anterior

Los partidos de `coordinador/datos/real.json` usan IDs de equipo propios, distintos de Tesorería, así que se importan con una tabla de equivalencias revisada a mano. Requiere haber ejecutado `schema.sql` (crea `cp_partidos_importados`) y `migrar.php --aplicar`. Hacerlo antes de definir `LC_COMPETENCIA_ACTIVA=1`.

1. Generar la tabla (no escribe en la base):

```sh
php competencia/importar-partidos.php --edicion=ed3 --generar
```

Crea `coordinador/datos/equivalencias-partidos-ed3.csv` (carpeta protegida, fuera de git) y una lista de referencia `…-equipos-competencia.csv` con los `origen_ref` válidos. Las coincidencias por nombre sólo son sugerencias: revisar cada fila y escribir en `origen_ref` el ID correcto, u `OMITIR` para equipos de otras ediciones (sus partidos no se importan).

2. Vista previa: `php competencia/importar-partidos.php --edicion=ed3`. Muestra partidos por estado y torneo, omitidos, cruces y errores. No escribe nada.
3. Aplicar: añadir `--aplicar`. Es una sola transacción; repetirlo no duplica partidos ya importados.

Conversión: `finalizado` → `jugado` con marcador; `cancelado` → `cancelado`; `programado` y `suspendido` → `programado` (para que coordinación los reprograme). Los partidos sin fecha u hora, o finalizados sin marcador, se omiten y se listan. Cancha = «Sede · Cancha»; duración = la configurada en la liga anterior. Si un partido programado choca con uno ya capturado en el sistema nuevo, se detiene; `--permitir-cruces` lo importa igualmente. Opciones: `--origen=RUTA` y `--mapa=RUTA`.

## Endpoints

Todas las escrituras usan POST JSON y `X-CSRF-Token` de `GET api.php?a=sesion`. El acceso también exige CSRF. Sesión independiente, 30 minutos de inactividad y 8 horas máximas; cada petición revalida usuario, contraseña, estado y asignación.

| Acción `a` | Método | Uso |
|---|---|---|
| sesion / entrar / salir | GET / POST / POST | Sesión personal |
| espacios | GET | Espacios visibles según rol |
| general | GET | Métricas de todos los espacios, administrador |
| panel&espacio_id=ID | GET | Agenda, equipos, cuentas, finanzas e incidencias filtrados |
| crear_espacio / asignar | POST | Crear o asignar espacios propios |
| crear_coordinador | POST | Crear cuenta de coordinación, administrador propietario |
| equipo | POST | Vincular ID de Tesorería a su edición exacta |
| usuario_equipo / desactivar_equipo | POST | Generar/restablecer o revocar acceso del equipo propio |
| partido | POST | Programar, reprogramar, cancelar o capturar marcador; incluye amistosos |
| movimiento | POST | Cargo, pago o gasto inmutable; clave de idempotencia `solicitud` |
| incidencia / reporte | POST | Reportar como equipo / resolver como operador |

Excepto sesión y listados generales, los cuerpos incluyen `espacio_id`. El servidor deriva la identidad del equipo de la sesión; los IDs enviados nunca conceden permisos.

## Fuentes y límites de integración

El histórico financiero se sigue leyendo en vivo de `arbitrajes/datos/estado.json`; los movimientos nuevos se guardan en `cp_movimientos`. Las cuentas y dashboards del sistema unificado suman ambas fuentes sin copiar cobros. Se usan centavos para los totales. Los movimientos históricos `ocultoCapitan` no se entregan al equipo. Los gastos nuevos se muestran separados: los gastos históricos generales no tienen asignación fiable por espacio y no se reparten automáticamente.

Al activar el corte, los informes de `finanzas-fuente.php` incorporan el libro nuevo mediante un adaptador de sólo lectura. La interfaz antigua de captura de Tesorería conserva su JSON y no debe guardar el estado combinado. Para equipos migrados debe usarse `/competencia/`. Los códigos HMAC de los equipos se convierten en hashes de contraseña durante la migración usando la semilla original de `capitanes/config.php`. Las cuentas de Google y sesiones antiguas requieren entrar de nuevo. El sistema nuevo de amistosos no modifica las reservas/propuestas existentes de `/disponibilidad/`: su detección de cruces abarca los partidos de todos los espacios nuevos. Antes del corte definitivo hay que conciliar esas reservas y los partidos del Coordinador anterior; no se asocian por nombres para evitar vincular equipos incorrectos.

Las tablas anteriores y archivos de datos no se migran destructivamente. El portal público y Copa Fácil conservan su fuente actual. Las cuentas antiguas de administración mantienen sus permisos históricos; no dar a un nuevo coordinador una cuenta antigua de administrador.

El Dashboard general del administrador incluye los reportes de incidencias de todos los espacios, con equipo, fecha, estado y respuesta. Los saldos conservan el detalle histórico y se actualizan en vivo; la migración imprime cargos, pagos y saldo para conciliarlos, sin insertar un saldo inicial que duplique los movimientos.

## Verificación

`node --check competencia/app.js` comprueba sintaxis JavaScript. `php competencia/tests.php` verifica reglas de acceso, cálculo y fechas sin base de datos. Para validación de integración en una base de ensayo: ejecutar el SQL, migrar dos veces y comprobar que no aumentan equipos/cobros; intentar IDs ajenos en cada POST, repetir solicitud financiera, cambiar coordinador con sesión abierta, restablecer contraseña con sesión abierta, y programar partidos simultáneos con cruces de cancha/equipo. No activar el corte antes de esas comprobaciones.

Validación local realizada el 15/10/2026: sintaxis JavaScript correcta; análisis de sintaxis PHP con `php-parser` correcto; vistas de coordinador y equipo comprobadas en navegador con respuestas simuladas; `git diff --check` sin errores. No hay intérprete PHP ni servidor MySQL disponible en este entorno: las pruebas PHP y de integración permanecen pendientes. No se ejecutó SQL ni la migración contra el hosting.

## Torneo y estadísticas (estilo Copa Fácil) · act1, 16/10/2026

Página `torneo.html` (+ `torneo.js`, `torneo.css`, cálculos en `torneo-lib.php`). Secciones: tabla general por categoría, goleo, mejor portero, disciplina (amarillas, rojas, suspendidos), calendario por jornada y fase, llaves de eliminación (ida/vuelta y penales), perfil y plantilla de cada equipo, calificaciones 1–5. La pestaña **Captura** sólo aparece a quien puede escribir en el espacio (coordinación asignada o administración propietaria).

**Instalación**
1. Ejecutar `estadisticas.sql` después de `schema.sql` (repetible; añade el rol `jugador` a `cp_usuarios` y crea `cp_jugadores`, `cp_partido_info`, `cp_eventos`, `cp_porteros`, `cp_sanciones`, `cp_votos`, `cp_documentos`, `cp_pases_usados`).
2. Subir `api.php`, `lib.php`, `app.js`, `app.css`, `.htaccess`, `torneo*.{html,js,css}`, `torneo-lib.php`, `entrar-capitan.php` y la carpeta `documentos/` (cerrada a internet).
3. En el portal de capitanes subir `codigos-lib.php`, `api.php` e `index.html` (nuevo botón y acción `a=torneo`).
4. En Captura: «Importar plantillas de Tesorería» (usa `jugadores` de `estado.json`; repetible, no duplica), asignar jornada/fase/llave a cada partido y capturar la cédula (goles, autogoles, tarjetas, porteros).

**Botón guiado**: aparece en «Estado de cuenta» de todos los usuarios de `/competencia/` y en el estado de cuenta del portal de capitanes. Desde capitanes se usa un pase firmado de un solo uso (clave derivada distinta a la de disponibilidad) que `entrar-capitan.php` canjea por la sesión de la cuenta del equipo.

**Reglas** (constantes en `torneo-lib.php`): 3 pts ganado, 1 empate; desempate DG y GF; sólo la fase regular suma a la tabla; amistosos no cuentan. Roja = 1 partido; cada 5 amarillas = 1 partido; las sanciones manuales cuentan los partidos del equipo jugados después de la fecha indicada. Mejor portero: menor promedio de goles recibidos con al menos la mitad de partidos del portero con más juegos; si un partido no tiene portero capturado y el equipo tiene un único portero activo, se le atribuye.

**Calificaciones**: sólo usuarios con rol `jugador` ligados a una ficha activa. No pueden calificarse a sí mismos ni a su propio equipo; un voto por objetivo, se puede cambiar. Los promedios son anónimos. Al dar de baja la ficha se cierra su acceso. Las cuentas de jugador se crean en Captura → Plantillas (contraseña aleatoria de 12 caracteres, se muestra una vez).

**Documentos de perfil**: `cp_documentos` adjunta un PDF a un usuario (`usuario_id`) o a todo un espacio (`usuario_id` NULL). El archivo va en `competencia/documentos/` y sólo sale por `api.php?a=documento&id=…` a quien tiene permiso; aparece en «Mis documentos».

**Verificación** (16/10/2026, copia local con MariaDB y `php -S`): esquema aplicado dos veces sin errores; migración real de ed3 (48 equipos); más de 50 comprobaciones de integración (tabla, goleo, suspensiones, llaves con penales, porteros, permisos por rol, votos, baja de jugador, documentos, puente de un solo uso, pase de disponibilidad rechazado); `php tests-torneo.php` correcto; vistas revisadas en escritorio y celular sin errores de consola. `tests.php` ya fallaba antes de este cambio en «Centavos, aislamiento de equipos, ocultos» (compara `70` entero con `70.0`).
