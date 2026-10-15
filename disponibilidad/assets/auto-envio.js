/* Envía solo el formulario marcado con data-auto (paso entre Tesorería y el panel). */
(function(){ var f = document.querySelector('form[data-auto]'); if (f) f.submit(); })();
