# Fase 0 Seguridad respaldo y restauración

## Estado de la ejecución

La rama `backup/fase0-2026-09-20` conserva sin cambios el commit de producción `55b89ae477d4c73d1acefad94a4631a5982c72bf`. El archivo `arbitrajes/datos/estado.json` no fue modificado ni eliminado. Su blob de referencia es `daebe43e906eaee7e178e9c68f4e2482be37545a` y su tamaño registrado es 116295 bytes.

## Acciones obligatorias antes de desplegar

1. Revocar y generar una llave nueva en Copa Fácil.
2. Crear contraseñas nuevas y distintas para el panel público y tesorería.
3. Generar una semilla nueva de al menos 64 caracteres para capitanes. Esto invalida todos los códigos anteriores.
4. Copiar `config.example.php` como `/home/TU_USUARIO/liga-chapingo-secrets.php`, fuera de `public_html`, y colocar ahí solamente los valores nuevos.
5. Mover `estado.json`, sus respaldos y el contenido editable fuera de `public_html`; actualizar las rutas en el archivo de secretos.
6. Cambiar los permisos del archivo de secretos a 600 y los directorios de datos a 700 o al mínimo requerido por PHP.
7. No desplegar esta rama hasta completar los pasos anteriores: si falta un secreto, el acceso administrativo queda cerrado de forma segura.

## Respaldo verificable en Hostinger

Antes de desplegar, descargar desde el Administrador de archivos una copia de `estado.json` y de la carpeta `respaldos`. Guardar una segunda copia fuera de Hostinger. Verificar localmente el JSON con `php -r '$j=json_decode(file_get_contents("estado.json"),true); exit(is_array($j)&&isset($j["rev"],$j["estado"])?0:1);'` y registrar `sha256sum estado.json`.

## Restauración

1. Poner temporalmente el sitio en mantenimiento y evitar nuevos registros.
2. Copiar el archivo actual a `estado-pre-restauracion-FECHA.json`; no borrarlo.
3. Subir el respaldo elegido con un nombre temporal dentro de `LIGA_DATOS_DIR`.
4. Validar que sea JSON, contenga `rev` y `estado`, y comparar su hash con el registrado.
5. Renombrar el archivo temporal a `estado.json` desde el mismo directorio para que el cambio sea atómico.
6. Probar inicio de sesión, lectura del estado, saldo de al menos tres equipos y portal de capitanes.
7. Si alguna comprobación falla, volver a colocar `estado-pre-restauracion-FECHA.json` como `estado.json`.

## Rollback del código

La referencia de recuperación es la rama `backup/fase0-2026-09-20`. Volver a desplegar esa rama restaura el código anterior, pero no debe reemplazar la base financiera vigente. Git protege el código; los datos se restauran exclusivamente con el procedimiento anterior.

