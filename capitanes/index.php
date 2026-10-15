<?php
declare(strict_types=1);
if (require __DIR__.'/../competencia/activa.php') {
    header('Location: ../competencia/',true,302); exit;
}
readfile(__DIR__.'/index.html');
