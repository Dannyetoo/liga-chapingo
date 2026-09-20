# ligachapingo.com — sitio público de Liga Premier Chapingo

Este paquete es **la página inicial** del dominio. No toca la app de tesorería:
la app se queda igual, solo se mueve a la carpeta `arbitrajes/` y el sitio la enlaza.

Requisitos: hosting con **PHP 7.3 o superior**. No hace falta base de datos.

---

## 1. Cambia la contraseña del panel

Abre `config-sitio.php` y sustituye la contraseña de ejemplo:

```php
define('CLAVE_PANEL', 'chapingo2026');   // <-- cámbiala
```

Es la contraseña del panel que edita el contenido del sitio. **No es** la clave de la
app de arbitrajes (esa sigue en `arbitrajes/config.php`, con su propio valor).

## 2. Sube los archivos

En el Administrador de archivos de cPanel, entra a `public_html` y sube ahí:

```
public_html/
├── index.html              la página inicial
├── admin.html              panel para editar el contenido
├── contenido.php           guarda y lee el contenido del sitio
├── config-sitio.php        tu contraseña y tus ajustes
├── .htaccess               protege la configuración y bloquea el listado de carpetas
├── manifest.webmanifest    para instalarla como app en el celular
├── robots.txt
├── sitemap.xml
├── icono-192.png
├── icono-512.png
├── icono-180.png
├── contenido/              (permisos 755) textos, eventos, convocatorias…
│   └── .htaccess           impide descargar el contenido por el navegador
├── img/                    (permisos 755) fotos y PDF que subas desde el panel
│   └── .htaccess           carpeta pública, pero sin ejecutar código
├── capitanes/              PORTAL DE CONSULTA de los capitanes (solo lectura)
│   ├── index.html          acceso con código y estado de cuenta del equipo
│   ├── api.php             lee estado.json y responde solo del equipo en sesión
│   ├── codigos-lib.php     cómo se calcula el código de cada equipo
│   ├── config.php          tu semilla secreta y tus ajustes
│   ├── .htaccess
│   └── datos/              (permisos 755) solo lleva la cuenta de intentos fallidos
│       └── .htaccess
└── arbitrajes/             AQUÍ va la app de tesorería, tal como está hoy
    ├── index.html
    ├── api.php
    ├── codigos.php         lista de códigos de capitán (solo para la mesa directiva)
    ├── config.php
    ├── .htaccess
    ├── manifest.webmanifest
    ├── icono-192.png · icono-512.png · icono-180.png
    └── datos/              (permisos 755, la crea PHP sola)
```

Las carpetas `contenido/` e `img/` deben tener permisos **755** y ser escribibles por
PHP. Si tu cPanel muestra el propietario como `nobody`, usa **775**.
`contenido.json` y la carpeta `contenido/respaldos/` se crean solas la primera vez.

> Si al subir por FTP no ves los archivos `.htaccess`, activa "mostrar archivos ocultos"
> en tu cliente FTP o créalos desde el Administrador de archivos de cPanel.

## 3. Mueve la app a `arbitrajes/`

Si la app ya está en `public_html/tesoreria/`, basta con **renombrar la carpeta** a
`arbitrajes`. Todo lo de adentro se queda igual: `api.php`, `config.php`, `datos/` y
sus respaldos siguen funcionando porque las rutas son relativas.

Si prefieres conservar el nombre `tesoreria/`, entra al panel del sitio y en
**Arbitrajes → Carpeta del sistema** escribe `tesoreria/`. El menú y los botones
apuntarán ahí.

## 3 bis. El portal de capitanes

El sitio público ya **no** manda a nadie a la app de tesorería. Los botones
"Consultar estado de cuenta" llevan a `capitanes/`, una pantalla aparte donde el
capitán ve **solo su equipo y solo para consulta**: no puede registrar pagos,
editar cargos, tocar tickets ni ver a otro equipo.

1. Abre `capitanes/config.php` y cambia la semilla:

   ```php
   define('SEMILLA_CAPITANES', 'iX2aNMsbiXKXxOIqy4jLHvs9tdLKbLptuGmrA9ctgUKMlDxL'); // <-- cámbiala
   ```

   De esa semilla salen los códigos. Si la cambias después, **todos los códigos
   cambian** y hay que repartirlos otra vez. Guárdala donde guardas la clave de
   la app de arbitrajes.

2. Revisa que `DATOS_ARBITRAJES` apunte a la misma carpeta que `DATOS_DIR` de
   `arbitrajes/config.php`. Si dejaste todo en su lugar, ya está bien.

3. Entra a la app de arbitrajes con tu contraseña de siempre y abre
   **https://ligachapingo.com/arbitrajes/codigos.php**. Ahí está la lista de los
   equipos con su código, un buscador, el botón "Copiar aviso" (deja listo el
   mensaje de WhatsApp) y un botón para imprimirla.

   Esa página **no se enlaza desde ningún lado**: se entra escribiendo la
   dirección, y solo abre si ya iniciaste sesión en arbitrajes.

4. Reparte el código a cada capitán. Con él entra a
   **https://ligachapingo.com/capitanes/**.

Cómo queda repartido el acceso:

| Quién | Dónde entra | Qué puede hacer |
|---|---|---|
| Visitante | `ligachapingo.com` | Ver la liga, inscribirse y consultar clasificaciones |
| Capitán | `ligachapingo.com/capitanes/` | **Solo leer** el estado de cuenta de su equipo |
| Mesa directiva | `ligachapingo.com/arbitrajes/` | Cobrar, registrar, corregir — todo, como siempre |

La separación no depende de esconder botones: `capitanes/api.php` no tiene
ninguna acción que escriba, y el equipo que devuelve lo decide la sesión del
servidor, no lo que mande el navegador.

## 4. Entra al panel y publica tu contenido

Ve a **https://ligachapingo.com/admin.html**, escribe la contraseña y edita:

| Pestaña | Qué controla |
|---|---|
| Identidad | Nombre de la liga, línea del encabezado y lema del pie |
| Portada | Etiqueta, título grande y párrafo de entrada |
| Torneo actual | Nombre, estado, jornada, horarios, sede, cifras y enlaces de calendario |
| Eventos | Juntas, jornadas especiales, premiaciones (con fecha, lugar e imagen) |
| Convocatorias | Título, fechas, si está vigente y el **PDF descargable** |
| Campeones | Un registro por campeón: año, edición, disciplina, rama, categoría, equipo |
| Galería | Fotos con pie, se abren en grande al tocarlas |
| Uniformes | Prenda, precio, descripción y foto |
| Arbitrajes | Textos de la franja verde y la carpeta a la que apunta |
| Contacto | WhatsApp, teléfono, correo, dirección y redes sociales |

Los cambios se ven en el sitio **al dar "Guardar y publicar"**. Si dos personas editan
al mismo tiempo, el que guarda después recibe aviso y se recarga la versión del servidor,
para no pisar el trabajo del otro.

**El WhatsApp del apartado Contacto también alimenta** los botones "Pedir informes" de
uniformes y el formulario de contacto. Escríbelo con lada: `5215512345678` o
`55 1234 5678` — el sitio limpia el formato solo.

## 5. Sube fotos y convocatorias

Dentro del panel, cada imagen y cada PDF tienen un botón **Subir archivo**. El archivo
se guarda en `img/` con un nombre limpio y único; no necesitas FTP. Se aceptan
JPG, PNG, WEBP, GIF y PDF, hasta **8 MB** por archivo (se cambia en `config-sitio.php`,
en `MAX_MB`).

Para que la página cargue rápido, sube las fotos de la galería a un ancho máximo
de unos **1600 px**.

---

## Cómo guarda el contenido

Todo vive en `contenido/contenido.json`. Cada guardado incrementa un número de revisión,
y antes de la primera escritura de cada día se guarda una copia en `contenido/respaldos/`.
Se conservan los últimos 30 días.

Si `contenido.php` no responde (PHP apagado, archivo sin subir), **el sitio no se cae**:
`index.html` trae adentro una copia de respaldo del contenido y se muestra igual, solo
que sin tus últimas ediciones.

## Seguridad — lo que conviene saber

- Pon el dominio en **HTTPS**. En cPanel activa el certificado gratuito de Let's Encrypt
  y luego quita los `#` del bloque `RewriteEngine On` en el `.htaccess` de la raíz.
- La protección de `contenido/` depende de `.htaccess`, que solo funciona en **Apache**.
  Si tu hosting usa **nginx**, mueve la carpeta fuera de `public_html` y apunta a ella
  desde `config-sitio.php`:
  ```php
  define('CONTENIDO_DIR', '/home/TU_USUARIO/contenido-liga');
  ```
- El panel es una sola contraseña compartida, igual que la app. Cámbiala cuando alguien
  deje la mesa directiva.
- El código de capitán es **por equipo** y solo sirve para mirar. Aunque se filtre, con él
  no se puede cobrar, corregir ni ver otro equipo. Si quieres invalidarlos todos de golpe,
  cambia `SEMILLA_CAPITANES` en `capitanes/config.php` y reparte los nuevos.
- Tras 20 códigos equivocados desde la misma conexión, el portal deja de aceptar intentos
  durante 15 minutos. Ese conteo vive en `capitanes/datos/`, que debe poder escribirse (755).
- `img/` es pública a propósito (las fotos tienen que verse), pero su `.htaccess` impide
  que ahí se ejecute cualquier código, y `contenido.php` solo acepta imágenes y PDF.

## Instalarlo como app en el celular

- **Android / Chrome:** menú ⋮ → "Agregar a pantalla principal".
- **iPhone / Safari:** compartir → "Agregar a inicio".

Queda con el escudo de la liga y accesos directos a Arbitrajes y Convocatorias.

## Si algo falla

| Síntoma | Causa probable |
|---|---|
| El sitio se ve pero con los textos de ejemplo | `contenido.php` no se subió o PHP está apagado. Abre `https://ligachapingo.com/contenido.php?a=ver` — debe responder texto JSON. |
| "No se pudo contactar al servidor" al guardar | La carpeta `contenido/` no tiene permisos de escritura. Ponla en 755 o 775. |
| "No se pudo escribir en la carpeta img/" | Mismo caso, pero con `img/`. |
| El botón Arbitrajes da 404 | La app no está en `public_html/arbitrajes/`. Muévela ahí o cambia la ruta en el panel. |
| "Ese código no corresponde a ningún equipo" con un código bueno | Cambiaste `SEMILLA_CAPITANES` después de repartirlos. Vuelve a la semilla anterior o reparte los nuevos desde `arbitrajes/codigos.php`. |
| `codigos.php` dice "Acceso restringido" | Se venció la sesión de arbitrajes. Entra a `arbitrajes/` con tu contraseña y vuelve a abrirla. |
| El portal de capitanes no muestra movimientos | `capitanes/config.php` apunta a otra carpeta de datos. Debe ser la misma que `DATOS_DIR` de `arbitrajes/config.php`. |
| La tabla de Segunda Fuerza sigue diciendo "Tabla en camino" | Falta pegar el iframe en `index.html`, donde dice `<!-- INSERTAR IFRAME CLASIFICACIÓN SEGUNDA FUERZA -->`. |
| La galería se ve vacía | Las fotos se suben desde el panel, pestaña **Galería**; sin imagen la tarjeta no se muestra. |
