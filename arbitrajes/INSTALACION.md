# Tesorería Premier Chapingo — instalación en grupomemos.com

Requisitos: hosting con **PHP 7.3 o superior** (cualquier cPanel típico lo tiene).
No hace falta base de datos.

**Cómo se usa:** entras y eliges la edición (Primera, Segunda, Tercera o Relámpago),
luego la disciplina, la rama y la categoría, y llegas a las tarjetas de equipo. Cada
tarjeta tiene tres botones —**Jugadores**, **Generar cargo** y **Estado**— y un lápiz
para editar el equipo, moverlo de lugar o eliminarlo.

Al dar de alta equipos puedes escribirlos o **importarlos** de cualquier edición, rama o
categoría donde ya hayan participado, con o sin su nómina de jugadores. Dentro de la lista
de jugadores también puedes traer la nómina de otro equipo. Los equipos importados llegan
siempre con saldo en cero: cada edición lleva su propia cuenta.

De cada jugador se guarda **nombre completo y matrícula** al frente, más número, teléfono
y si ya entregó credencial. El buscador del inicio encuentra un equipo por su nombre, por
el nombre de un jugador o por su matrícula, y te lleva directo a cobrarle. El ícono de
gráfica abre el dashboard, el de peso los gastos y el de engrane el catálogo.

**En el dashboard** hay una gráfica por categoría con lo que ha pagado cada equipo
desglosado por concepto, más su saldo pendiente, y debajo la misma información en tabla
(equipo × concepto) con exportación a CSV. Los equipos que se llaman igual en varias
categorías de la misma edición y rama se cuentan como uno solo en los totales; al dar clic
se abre su ficha combinada, que muestra cada categoría con su propio encargado.

**Importar desde CopaFacil.** Dentro de una categoría, el botón *Importar archivo (.xlsx)*
sube uno o más equipos completos con su plantilla; dentro de un equipo, *Importar archivo*
sube solo jugadores. Lee .xlsx y .csv sin depender de internet, detecta la hoja y el renglón
de encabezados, mapea las columnas solo (funciona con los encabezados en portugués de
CopaFacil) y te deja reasignarlas antes de confirmar, con vista previa.

**Conectar Copa Fácil (opcional).** En **Catálogo → Copa Fácil (API)** pones el ID del torneo y
la llave del API (se obtiene dentro de la app de Copa Fácil) y pruebas la conexión. Mejor todavía:
deja la llave en `config.php`, en `CF_API_KEY`, y en la app solo el ID del torneo — así no queda
guardada en el navegador ni en `datos/estado.json`. La llamada la hace `api.php` desde tu servidor,
no el navegador: por eso funciona (no hay bloqueo de CORS) y la llave nunca viaja al cliente.

Ya conectado, el importador tiene una pestaña **Copa Fácil**: lee las fases del torneo, los equipos
de la fase que elijas y los campos que trae la plantilla; marcas los equipos, los trae uno por uno
y pasan por el mismo mapeo de columnas, la misma vista previa y la misma regla de duplicados que el
archivo. Requiere PHP con cURL o `allow_url_fopen` (casi todos los cPanel lo traen).

La documentación de Copa Fácil no dice qué parámetros son obligatorios, y en la práctica `/teams`
exige el parámetro `key` **repetido** (`key=a&key=b`); con la lista separada por comas responde 200
pero con los equipos vacíos. La app prueba las combinaciones en orden, descarta las que responden
sin datos y se queda con la que sirve; la pastilla *ruta ok* te dice cuál fue.

De los equipos también toma el **Representante** y el **Número de contacto**, que llegan como
columnas y se cargan como encargado y teléfono del equipo.

Si algo falla, el botón **Diagnóstico** prueba la llave, el torneo y todas las combinaciones por
separado, te dice cuál sirve y deja el resultado en texto para copiarlo.

**Un jugador, un equipo.** No se acepta a un jugador que ya esté registrado en otro equipo de
la misma rama y edición; en los torneos relámpago la restricción es por categoría (se marca
por edición en Catálogo). Para identificarlo se usa la matrícula, luego la fecha de nacimiento
junto con el nombre, y por último el nombre. Al terminar la carga sale un reporte que enfrenta
al jugador del archivo con el que ya estaba registrado, dónde está y por qué se detectó;
se descarga en CSV.

**Multas individuales.** El botón *Crédito* de la tarjeta del equipo divide un adeudo entre 6
y deja esa parte como multa personal de cada jugador que marques. También hay multas
individuales sueltas desde la ficha del jugador. La multa sigue a la persona aunque cambie de
equipo, categoría o edición, y solo se cancela cobrándola (*Cobrar multa*, que genera su
movimiento y su ticket). El ícono con el número rojo en el encabezado abre la lista de todos
los jugadores con adeudo personal.

**Observaciones.** Los equipos las llevan en el lápiz de la tarjeta y los jugadores en su ficha.

**Colores de inscripción.** Cada equipo se clasifica con un color: verde *inscripción
completa*, ámbar *inscripción a crédito*, gris *por confirmar* y azul *aprobado* para los
equipos especiales. Se calcula solo con los pagos de inscripción y se puede fijar a mano
desde el lápiz de la tarjeta (los fijados a mano llevan un punto). El color aparece en la
franja de la tarjeta, en el buscador, en las tarjetas de navegación, en el dashboard y en
las exportaciones.

**Rastreo del dinero.** Cada cobro guarda quién entregó el dinero y quién lo recibió, y cada
gasto guarda quién pagó y quién fue el beneficiario. El dashboard incluye el corte por
persona (cuánto cobró, cuánto pagó y cuánto debería traer en mano), los ingresos de cada
cobrador desglosados por concepto, los egresos por beneficiario y una bitácora filtrable por
persona y por tipo, exportable a CSV. Al dar clic en una persona se abre todo lo que pasó
por sus manos. El ticket imprime las líneas "Entregó" y "Recibió".

---

## 1. Cambia la clave de acceso

Abre `config.php` y sustituye la clave de ejemplo por la tuya:

```php
define('CLAVE_ACCESO', 'chapingo2026');   // <-- cámbiala
```

Es una sola clave compartida entre quienes cobran. Cámbiala cuando alguien deje la mesa directiva.

## 2. Sube los archivos

En el Administrador de archivos de cPanel, entra a `public_html` y crea la carpeta
`tesoreria`. Sube ahí dentro:

```
tesoreria/
├── index.html            la aplicación
├── api.php               guarda y lee los datos
├── config.php            tu clave y tus ajustes
├── .htaccess             protege config.php y bloquea el listado de carpetas
├── manifest.webmanifest  para instalarla como app en el celular
├── icono-192.png
├── icono-512.png
├── icono-180.png
└── datos/
    └── .htaccess         impide que nadie descargue los datos por el navegador
```

La carpeta `datos/` debe tener permisos **755** y ser escribible por PHP.
Si tu cPanel muestra el propietario como `nobody`, usa **775**.
`estado.json` y la carpeta `respaldos/` se crean solos la primera vez.

> Si al subir por FTP no ves los archivos `.htaccess`, activa "mostrar archivos ocultos"
> en tu cliente FTP o créalos desde el Administrador de archivos de cPanel.

## 3. Entra

Ve a **https://grupomemos.com/tesoreria/**, escribe la clave y listo.
La primera vez el catálogo se siembra solo con los conceptos base
(Liga $650, Relámpago $350, Arbitrajes $175, dos multas y credencialización).

## 4. Trae los datos de la versión de Claude

1. En la versión de Claude: **Catálogo → Descargar respaldo**.
2. En la versión alojada: **Catálogo → Restaurar respaldo**, pega el contenido y confirma.

Los respaldos de la versión anterior (la de cargos y pagos por separado) se convierten
solos: cada pago y cada cargo se vuelven un movimiento, los equipos entran en la Primera
Edición con categoría Libre, y el delegado pasa a ser el encargado. Revisa que hayan
quedado en la edición correcta y muévelos si hace falta.

---

## Cómo guarda los datos

Todo vive en `datos/estado.json` — ediciones, disciplinas, ramas, categorías, equipos,
jugadores, movimientos y gastos. Cada guardado incrementa un número de revisión.
Si dos personas capturan al mismo tiempo, el servidor detecta el choque y la app
fusiona automáticamente: se conservan las altas de ambos y se respetan las bajas de
cada quien. Cada dispositivo revisa cambios cada 20 segundos y al volver a la pestaña.

Antes de la primera escritura de cada día se guarda una copia en `datos/respaldos/`.
Se conservan los últimos 30 días.

## Contraseña de administración

En **Catálogo → Contraseña de administración** se pone una sola contraseña que oculta las
secciones que abarcan a toda la liga. Con casillas eliges cuáles pide:

| Sección | Qué deja de ver quien no la tenga |
|---|---|
| Dashboard | Totales, gráficas, tabla equipo × concepto, cortes por cobrador y bitácora |
| Gastos | Egresos de la liga y saldo neto |
| Multas de jugadores | Adeudos personales de todos los jugadores |
| Catálogo | Ediciones, conceptos, ticket, Copa Fácil y **las contraseñas de categoría** |

Si pones la contraseña sin marcar nada, se protegen las cuatro. Los íconos del encabezado
llevan un candadito cuando la sección está cerrada; al tocarlos se pide la contraseña.
Queda abierta hasta que se cierre el navegador, o hasta que uses **Bloquear**.

> **Anótala.** Si proteges Catálogo, esa misma pantalla queda detrás de la contraseña.
> Para recuperarla habría que editar `datos/estado.json` en el servidor y vaciar
> `"admin":{"clave":""}`.

Conviene proteger **Catálogo** junto con el dashboard: ahí se ven y se cambian las
contraseñas de categoría.

## Contraseña por categoría

Además de la clave general de entrada, cada categoría puede pedir su propia contraseña.
Se configuran en **Catálogo → Contraseñas por categoría**: hay un renglón por
edición · rama · disciplina · categoría, así que Relámpago Varonil Libre puede llevar una
contraseña distinta a la de Primera Varonil Libre. El campo vacío deja la categoría abierta,
y el botón "Poner la misma a toda la rama" llena de golpe todos los renglones de esa rama.

Con contraseña puesta, la tarjeta de la categoría muestra 🔒 **Protegida** y no deja entrar
a los equipos, ni abrir un cargo desde el buscador, ni abrir la ficha de un equipo desde el
dashboard, sin escribirla. Una vez acertada queda abierta hasta que se cierre el navegador;
el botón **Bloquear**, al pie de la lista de equipos, la vuelve a cerrar de inmediato.

Es un candado de "quién toca qué", no un cifrado: las contraseñas se guardan como texto
dentro de `datos/estado.json`, detrás de la clave general.

## Instalarla como app en el celular

- **Android / Chrome:** menú ⋮ → "Agregar a pantalla principal".
- **iPhone / Safari:** compartir → "Agregar a inicio".

Queda con su ícono y sin barra del navegador. Para imprimir tickets desde el celular
necesitas una impresora térmica compatible con AirPrint o con la app de impresión
de tu marca.

## Impresión de tickets

Cuando un pago queda a crédito, el ticket imprime un aviso breve de que debe cubrirse antes
del siguiente juego o Disciplina puede sancionar. El texto se edita en **Catálogo → Ticket**.

El ticket sale a **58 mm de ancho, alto automático, sin márgenes**. En el diálogo de
impresión elige tu impresora térmica y, si aparece, tamaño de papel *58mm × auto* o
*Rollo*. Desactiva "encabezados y pies de página".

---

## Seguridad — lo que conviene saber

- Pon el sitio en **HTTPS**. En cPanel, activa el certificado gratuito de Let's Encrypt
  y luego quita los comentarios del bloque `RewriteEngine On` en el `.htaccess` de la raíz.
- La protección de `datos/` depende de `.htaccess`, que solo funciona en **Apache**.
  Si tu hosting usa **nginx**, mueve la carpeta fuera de `public_html` y apunta a ella
  desde `config.php`:
  ```php
  define('DATOS_DIR', '/home/TU_USUARIO/datos-tesoreria');
  ```
- Es una clave compartida, no un sistema de usuarios: el registro de quién cobró es el
  campo "Recibió" de cada movimiento, que sí queda guardado y sale impreso en el ticket. Si más adelante quieres
  usuarios con nombre y bitácora de movimientos, se puede migrar a MySQL.

## Si algo falla

| Síntoma | Causa probable |
|---|---|
| "No se pudo contactar al servidor" | PHP apagado o `api.php` no se subió. Abre `https://grupomemos.com/tesoreria/api.php?a=rev` — debe responder texto JSON, no descargar un archivo. |
| "Sin conexión — reintentar" al guardar | La carpeta `datos/` no tiene permisos de escritura. Ponla en 755 o 775. |
| Dice "Guardado en este dispositivo" | La app no encontró `api.php` y trabaja en modo local. Revisa que esté en la misma carpeta que `index.html`. |
| Los datos se ven distintos en cada celular | Están en modo local. Mismo caso que el anterior. |
