-- schema.sql
-- Database untuk Klinik Pratama UIN Bandung (login + Dashboard Admin Poli Umum)
-- Versi ini fokus untuk 1 akun dulu: Admin Poli Umum

CREATE DATABASE IF NOT EXISTS klinik_kampus CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE klinik_kampus;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin_poli1', 'admin_poli2', 'admin_poli3', 'super_admin') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User untuk masuk ke Dashboard Admin Poli Umum (role: admin_poli1)
-- username: admin.poliumum   | password: admin123
INSERT INTO users (username, password_hash, role, full_name) VALUES
('admin.poliumum', '$2y$10$Z9FR9O33t0g4PxgE6QIL6OGYJe5uveIAHt/kpgZGtilsR5XblWIPG', 'admin_poli1', 'Admin Poli Umum')
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    full_name = VALUES(full_name);

-- =========================================================
-- Tabel items: data inventaris yang dipakai di laporan.php
-- =========================================================
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    sku VARCHAR(30) NOT NULL UNIQUE,
    kategori ENUM('Obat', 'Alkes', 'Habis Pakai') NOT NULL,
    stok INT NOT NULL DEFAULT 0,
    ambang_minimum INT NOT NULL DEFAULT 15,
    satuan VARCHAR(20) NOT NULL,
    status ENUM('available', 'low') NOT NULL DEFAULT 'available',
    poli ENUM('umum', 'gigi', 'kia') NOT NULL DEFAULT 'umum',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;