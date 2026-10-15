<?php

declare(strict_types=1);

/*
 * Conexión MySQL
 * ENTORNO DE PRUEBAS - Liga Chapingo
 */

$host = 'localhost';
$dbname = 'u577450088_liga_chapingo';
$username = 'u577450088_liga_chapingo';
$password = 'Dannietom#3';

try {

    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    http_response_code(500);

    exit('Error de conexión con la base de datos.');

}