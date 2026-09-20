# Rotación de secretos

Todos los valores que alguna vez estuvieron en Git deben considerarse comprometidos, incluso después de retirarlos del último commit.

| Secreto | Acción | Comprobación |
|---|---|---|
| Llave de Copa Fácil | Revocar la anterior y generar otra | La conexión funciona con la nueva y la anterior falla |
| Panel público | Crear contraseña única de 20 o más caracteres | `admin.html` acepta solo la nueva |
| Tesorería | Crear otra contraseña única de 20 o más caracteres | `/arbitrajes/` acepta solo la nueva |
| Semilla de capitanes | Generar 64 o más caracteres aleatorios | Los códigos viejos fallan y se distribuyen los nuevos |

Después de la rotación, revisar respuestas API, HTML y JavaScript para confirmar que ningún valor nuevo llega al navegador. Eliminar copias de configuración real de equipos compartidos y guardar los secretos únicamente en el gestor autorizado y en el archivo externo protegido.

