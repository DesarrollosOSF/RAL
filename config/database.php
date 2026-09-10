<?php
/**
 * Configuración y conexión a base de datos (Control-Sedes).
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'osfcomco_control_sedes');
define('DB_PASS', 'tgp4dxcoSXB184EH');
// configuracion para usar base de datos local
// define('DB_USER', 'root'); 
// define('DB_PASS', '');
define('DB_NAME', 'osfcomco_control_sedes');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection(): PDO {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        die("Error de conexión a BD: " . $e->getMessage());
    }
}

