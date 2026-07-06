<?php
require_once __DIR__ . '/config.php';

// Hapus semua data session
$_SESSION = [];
session_destroy();

// Hapus cookie remember token kalau ada
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

header('Location: login.php');
exit;