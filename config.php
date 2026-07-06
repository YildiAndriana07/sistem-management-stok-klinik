<?php
// config.php — Konfigurasi koneksi database
// Sesuaikan dengan kredensial database kamu

define('DB_HOST', 'localhost');
define('DB_NAME', 'klinik_kampus'); // disamakan dengan nama database di schema.sql
define('DB_USER', 'root');
define('DB_PASS', '');

function getDbConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die("Koneksi database gagal: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Mulai session di satu tempat saja
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}