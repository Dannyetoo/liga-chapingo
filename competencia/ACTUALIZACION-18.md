# Interfaz 18 · 18/10/2026

Aplicación del archivo `pruebas/mejora de interfaz 18.xml` sobre el módulo unificado `/competencia/`, al que redirige `/coordinador/` cuando LC_COMPETENCIA_ACTIVA=1.

## Instalación en el hosting

1. Respaldar la base de datos y los archivos actuales de Competencia.
2. Ejecutar `competencia/schema.sql` antes de subir la API. Es repetible y añade `cp_movimiento_detalle` para guardar el receptor explícito sin modificar los movimientos anteriores. Se necesita la instalación previa de `estadisticas.sql` para la tabla general y las estadísticas.
3. Subir los archivos del paquete conservando la carpeta `competencia/`. Mantener las configuraciones y los datos del servidor. No contiene credenciales ni datos operativos.
4. Conservar los iconos existentes `/icono-192.png` y `/icono-512.png`.
5. Abrir `/competencia/` por HTTPS e iniciar sesión. En Chrome Android usar «Instalar app» cuando esté disponible o el menú del navegador → «Instalar aplicación / Añadir a pantalla de inicio». Admite teléfono y tableta en ambas orientaciones. Es una PWA; no incluye APK ni publicación en Play Store.

El service worker no almacena sesiones, cuentas ni documentos. La consulta y las escrituras necesitan conexión; una interrupción muestra una página de reconexión. Los enlaces a Cancha y Busca un rival conservan la autenticación de Disponibilidad.

## Cambios incluidos

- Diseño compacto azul y plata, navegación secundaria en el pie, acceso destacado a Torneo y Estadísticas junto al nombre.
- Cinco indicadores que abren detalles de equipos por categoría, cargos y partidos jugados, pagos, adeudos y gastos. Saldos cero o a favor en verde y deuda en rojo.
- Partidos de hoy, roles de juego y tabla general consultada del motor de estadísticas existente.
- Resumen financiero del PDF del 04/10/2026: ingresos $33,466; salidas $22,692; saldo $10,774; Adán $9,835 pendiente de entregar; Administración $939. El PDF no separa todos los rubros solicitados; no se inventaron cifras.
- Corte indicado al 18/10/2026 de $939 y nota de devoluciones BLACK KAISER $350, NOSOTROS LOS GUAPOS 2.0 $350, PARIS $350 y LA 4T $300. No se insertan automáticamente en el libro ni se descuentan de nuevo de caja.
- Receptor explícito para pagos/gastos nuevos; el histórico sin receptor indica «Sin registrar». Los cargos guardan «No aplica».
- Exportación CSV de reportes y enlace al portal de capitanes. Las contraseñas existentes no se recuperan: se conserva su hash; al generar/restablecer se muestra la nueva una sola vez.

## Alcance pendiente del XML

Esta entrega cubre la interfaz y PWA, pero no completa todas las ampliaciones funcionales del XML. Quedan pendientes: Match automático continuo que concilie ambos sistemas de reservas; registro de jugadores por código con vinculación Google; edición ampliada del perfil con logotipo/comprobante; préstamos y métodos de pago estructurados; tickets ligados individualmente a cargos; puntos extra y titulares; logotipos reales y reconocimientos semanales en el inicio. Se mantienen la captura y las estadísticas ya existentes en Torneo. Los cargos históricos no tienen siempre relación con una jornada y se presentan como tales.

## Validación

JavaScript: `node --check` en app.js, interfaz18.js, instalacion.js y sw.js. Pruebas de navegador Edge con respuestas simuladas en 360, 800 y 1440 px: cinco tarjetas, apertura/cierre de detalles, contenido financiero y navegación del pie; sin errores JS ni desbordamiento de página. Revisión visual de teléfono. Manifest JSON válido e iconos existentes.

No se ejecutó PHP/MySQL por ausencia de intérprete PHP en el entorno. Es necesario probar escrituras, permisos y migración en una base de ensayo del hosting antes de publicar. No se verificó instalación en hardware Android ni se desplegó al servidor.
