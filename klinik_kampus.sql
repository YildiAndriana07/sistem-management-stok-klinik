-- schema.sql
-- Database untuk Klinik Pratama UIN Bandung (login + Dashboard Admin Poli Umum, Poli Gigi & Super Admin)
-- File ini AMAN dijalankan baik untuk setup baru maupun database yang sudah ada:
--   - Tabel yang belum ada akan dibuat (CREATE TABLE IF NOT EXISTS)
--   - Kolom "catatan" yang hilang akan otomatis ditambahkan tanpa menghapus data
--   - Kolom "created_by" yang hilang akan otomatis ditambahkan tanpa menghapus data
--
-- Penyebab error "Gagal menyimpan data" saat submit Input Barang:
-- form/query INSERT & UPDATE menyertakan kolom "catatan", tapi kolom itu
-- belum ada di tabel items. File ini menambahkannya.
--
-- Kolom "created_by" ditambahkan untuk fitur "Tambah Admin" di Dashboard
-- Super Admin, supaya tiap akun admin poli tercatat dibuat oleh super admin
-- yang mana.

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

-- User untuk masuk ke Dashboard Admin Poli Gigi (role: admin_poli2)
-- username: admin.poligigi   | password: admin123
-- (pakai hash yang sama dengan admin.poliumum karena password default-nya sama)
INSERT INTO users (username, password_hash, role, full_name) VALUES
('admin.poligigi', '$2y$10$Z9FR9O33t0g4PxgE6QIL6OGYJe5uveIAHt/kpgZGtilsR5XblWIPG', 'admin_poli2', 'Admin Poli Gigi')
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    full_name = VALUES(full_name);

-- User untuk masuk ke Dashboard Super Admin (role: super_admin)
-- username: super.admin   | password: admin123
-- (pakai hash yang sama juga, supaya password default seluruh akun konsisten)
INSERT INTO users (username, password_hash, role, full_name) VALUES
('super.admin', '$2y$10$Z9FR9O33t0g4PxgE6QIL6OGYJe5uveIAHt/kpgZGtilsR5XblWIPG', 'super_admin', 'Super Admin')
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    full_name = VALUES(full_name);

-- =========================================================
-- Tambahkan kolom "created_by" di tabel users kalau belum ada
-- (mencatat super admin mana yang membuat akun admin poli tsb,
-- dipakai oleh fitur Tambah Admin / tambah-admin.php)
-- aman dijalankan berkali-kali, tidak akan error meski kolomnya
-- sudah ada atau tabel users baru dibuat di atas
-- =========================================================
SET @kolom_created_by_ada := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'klinik_kampus'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'created_by'
);

SET @sql_alter_users := IF(
    @kolom_created_by_ada = 0,
    'ALTER TABLE users ADD COLUMN created_by INT NULL DEFAULT NULL AFTER role',
    'SELECT "Kolom created_by sudah ada, tidak perlu ditambahkan" AS info'
);

PREPARE stmt2 FROM @sql_alter_users;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- =========================================================
-- Tabel items: data inventaris yang dipakai di laporan.php
-- dan input-barang.php
-- =========================================================
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    sku VARCHAR(30) NOT NULL UNIQUE,
    kategori ENUM('Obat', 'Alkes', 'Habis Pakai', 'Alat Gigi', 'Bahan Tambal') NOT NULL,
    stok INT NOT NULL DEFAULT 0,
    ambang_minimum INT NOT NULL DEFAULT 15,
    satuan VARCHAR(20) NOT NULL,
    status ENUM('available', 'low') NOT NULL DEFAULT 'available',
    poli ENUM('umum', 'gigi', 'kia') NOT NULL DEFAULT 'umum',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- Update ENUM kategori supaya mencakup kategori khusus Poli Gigi
-- (aman dijalankan berkali-kali, tidak menghapus data yang sudah ada
-- selama nilai kategori lama masih ada di daftar ENUM baru)
-- =========================================================
ALTER TABLE items
    MODIFY COLUMN kategori ENUM('Obat', 'Alkes', 'Habis Pakai', 'Alat Gigi', 'Bahan Tambal') NOT NULL;

-- =========================================================
-- Tambahkan kolom "catatan" kalau belum ada (aman dijalankan berkali-kali,
-- tidak akan error meski kolomnya sudah ada atau tabelnya baru dibuat di atas)
-- =========================================================
SET @kolom_ada := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'klinik_kampus'
      AND TABLE_NAME = 'items'
      AND COLUMN_NAME = 'catatan'
);

SET @sql_alter := IF(
    @kolom_ada = 0,
    'ALTER TABLE items ADD COLUMN catatan TEXT NULL DEFAULT NULL AFTER status',
    'SELECT "Kolom catatan sudah ada, tidak perlu ditambahkan" AS info'
);

PREPARE stmt FROM @sql_alter;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Cek hasil akhir struktur tabel
DESCRIBE users;
DESCRIBE items;