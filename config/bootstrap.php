<?php
declare(strict_types=1);

/* Carga secretos desde variables de entorno o desde un archivo PHP fuera
   de public_html. Este archivo nunca contiene valores sensibles. */
if (!function_exists('ligaConfig')) {
    function ligaConfig(string $nombre, $predeterminado = '') {
        $valor = getenv($nombre);
        if ($valor !== false && $valor !== '') return $valor;

        static $secretos = null;
        if ($secretos === null) {
            $ruta = getenv('LIGA_CHAPINGO_SECRETS_FILE');
            if ($ruta === false || $ruta === '') {
                $ruta = dirname(__DIR__, 2) . '/liga-chapingo-secrets.php';
            }
            $cargado = is_file($ruta) && is_readable($ruta) ? require $ruta : [];
            $secretos = is_array($cargado) ? $cargado : [];
        }
        return array_key_exists($nombre, $secretos) ? $secretos[$nombre] : $predeterminado;
    }
}

