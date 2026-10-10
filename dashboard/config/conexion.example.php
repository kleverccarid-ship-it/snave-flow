<?php
/**
 * Plantilla de conexión. Copia este archivo como `conexion.php` en la misma
 * carpeta y pon tus propios datos. `conexion.php` está en .gitignore: nunca
 * se sube, para que las credenciales no salgan del servidor.
 */
date_default_timezone_set('America/Lima');

$host     = "localhost";
$database = "CAMBIA_ESTO";
$user     = "CAMBIA_ESTO";
$pass     = "CAMBIA_ESTO";

try {
    $conn = new PDO("mysql:host=$host;dbname=$database", $user, $pass, array(
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'; SET time_zone = '-05:00';",
        PDO::ATTR_TIMEOUT => "30",
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ));
} catch (PDOException $e) {
    die("PDO Connection Error: " . $e->getMessage());
}
