<?php
require_once __DIR__ . '/config.php';

// === Auth Guard ===
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$fullName = $_SESSION['full_name'] ?? 'Administrator';
$role     = $_SESSION['role'] ?? 'Admin';
$initial  = strtoupper(substr($fullName, 0, 1));

// === Daftar kategori & satuan untuk dropdown ===
$kategoriList = ['Obat', 'Alkes', 'Habis Pakai'];
$satuanList   = ['pcs', 'box', 'botol', 'strip', 'tube', 'pack', 'unit'];

$errors      = [];
$success     = '';
$editId      = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editingItem = null;

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    $pdo = null;
}

// === Proses aksi (create / update / delete) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = $_POST['action'] ?? 'create';

    // --- DELETE ---
    if ($action === 'delete') {
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM items WHERE id = :id');
                $stmt->execute(['id' => $deleteId]);
                $success = 'Barang berhasil dihapus dari inventaris.';
            } catch (Throwable $e) {
                $errors[] = 'Gagal menghapus barang. Silakan coba lagi.';
            }
        }
    }

    // --- CREATE / UPDATE ---
    if ($action === 'create' || $action === 'update') {
        $id       = (int)($_POST['id'] ?? 0);
        $nama     = trim($_POST['nama'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        $stok     = $_POST['stok'] ?? '';
        $satuan   = trim($_POST['satuan'] ?? '');
        $ambang   = $_POST['ambang_minimum'] ?? 15;
        $catatan  = trim($_POST['catatan'] ?? '');

        // Validasi sederhana
        if ($nama === '') $errors[] = 'Nama barang wajib diisi.';
        if ($sku === '') $errors[] = 'SKU wajib diisi.';
        if (!in_array($kategori, $kategoriList, true)) $errors[] = 'Kategori tidak valid.';
        if ($stok === '' || !is_numeric($stok) || (int)$stok < 0) $errors[] = 'Stok harus berupa angka dan tidak boleh negatif.';
        if ($satuan === '') $errors[] = 'Satuan wajib diisi.';
        if ($action === 'update' && $id <= 0) $errors[] = 'Item yang diedit tidak valid.';

        if (empty($errors)) {
            try {
                // Cek SKU duplikat (kecuali milik item yang sedang diedit)
                $cek = $pdo->prepare('SELECT COUNT(*) FROM items WHERE sku = :sku AND id != :id');
                $cek->execute(['sku' => $sku, 'id' => $action === 'update' ? $id : 0]);

                if ((int)$cek->fetchColumn() > 0) {
                    $errors[] = 'SKU sudah digunakan. Gunakan SKU lain.';
                } else {
                    $status = ((int)$stok <= (int)$ambang) ? 'low' : 'available';

                    if ($action === 'create') {
                        $stmt = $pdo->prepare(
                            'INSERT INTO items (nama, sku, kategori, stok, satuan, ambang_minimum, status, catatan, poli, created_at)
                             VALUES (:nama, :sku, :kategori, :stok, :satuan, :ambang, :status, :catatan, "umum", NOW())'
                        );
                        $stmt->execute([
                            'nama' => $nama, 'sku' => $sku, 'kategori' => $kategori, 'stok' => (int)$stok,
                            'satuan' => $satuan, 'ambang' => (int)$ambang, 'status' => $status, 'catatan' => $catatan,
                        ]);
                        $success = 'Barang berhasil ditambahkan!';
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE items SET nama = :nama, sku = :sku, kategori = :kategori, stok = :stok,
                             satuan = :satuan, ambang_minimum = :ambang, status = :status, catatan = :catatan
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'nama' => $nama, 'sku' => $sku, 'kategori' => $kategori, 'stok' => (int)$stok,
                            'satuan' => $satuan, 'ambang' => (int)$ambang, 'status' => $status, 'catatan' => $catatan,
                            'id' => $id,
                        ]);
                        $success = 'Perubahan barang berhasil disimpan!';
                    }

                    // Reset field setelah sukses (kembali ke mode tambah baru)
                    $nama = $sku = $catatan = '';
                    $kategori = '';
                    $stok = '';
                    $satuan = '';
                    $ambang = 15;
                    $editId = 0;
                }
            } catch (Throwable $e) {
                $errors[] = 'Gagal menyimpan data. Silakan coba lagi.';
            }
        } else {
            // Validasi gagal saat update: tetap di mode edit agar form terisi ulang
            if ($action === 'update') $editId = $id;
        }
    }
}

// === Ambil data item yang sedang diedit (jika ada) ===
if ($editId > 0 && $pdo && empty($nama ?? '')) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = :id');
        $stmt->execute(['id' => $editId]);
        $editingItem = $stmt->fetch();
        if ($editingItem) {
            $nama     = $editingItem['nama'];
            $sku      = $editingItem['sku'];
            $kategori = $editingItem['kategori'];
            $stok     = $editingItem['stok'];
            $satuan   = $editingItem['satuan'];
            $ambang   = $editingItem['ambang_minimum'] ?? 15;
            $catatan  = $editingItem['catatan'] ?? '';
        } else {
            $editId = 0;
        }
    } catch (Throwable $e) {
        $editId = 0;
    }
}

// Nilai default untuk re-populate form jika belum diisi
$nama     = $nama ?? '';
$sku      = $sku ?? '';
$kategori = $kategori ?? '';
$stok     = $stok ?? '';
$satuan   = $satuan ?? '';
$ambang   = $ambang ?? 15;
$catatan  = $catatan ?? '';

// === Ambil seluruh daftar barang Poli Umum untuk tabel CRUD ===
$allItems = [];
if ($pdo) {
    try {
        $stmt = $pdo->query('SELECT * FROM items WHERE poli = "umum" ORDER BY nama ASC');
        $allItems = $stmt->fetchAll();
    } catch (Throwable $e) {
        $allItems = [];
    }
}

function statusBadgeCrud(string $status): string {
    $isLow = $status === 'low';
    $color = $isLow ? 'error' : 'primary';
    $label = $isLow ? 'Stok Rendah' : 'Tersedia';
    return '<div class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full bg-' . $color . '/10 text-' . $color . '">'
         . '<span class="w-1.5 h-1.5 rounded-full bg-' . $color . '"></span>' . $label . '</div>';
}

function kategoriBadgeCrud(string $kategori): string {
    $map = [
        'Obat' => 'bg-secondary-container text-on-secondary-container',
        'Alkes' => 'bg-tertiary-container/20 text-tertiary',
        'Habis Pakai' => 'bg-primary-fixed text-on-primary-fixed-variant',
    ];
    $cls = $map[$kategori] ?? 'bg-surface-variant text-on-surface-variant';
    return '<span class="px-2.5 py-1 ' . $cls . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($kategori) . '</span>';
}
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Vitalis Admin — Input Barang</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Lexend:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<script id="tailwind-config">
  tailwind.config = {
    darkMode: "class",
    theme: {
      extend: {
        colors: {
          "outline": "#707973", "on-surface-variant": "#404943", "error-container": "#ffdad6",
          "on-error": "#ffffff", "error": "#ba1a1a", "tertiary-container": "#3f6754",
          "primary-fixed-dim": "#95d4b3", "on-secondary": "#ffffff", "surface-container-high": "#e6e9e8",
          "tertiary-fixed": "#c1ecd4", "primary-fixed": "#b1f0ce", "on-tertiary": "#ffffff",
          "on-secondary-fixed": "#002112", "tertiary": "#274f3d", "secondary-fixed": "#c0edd0",
          "on-background": "#191c1c", "surface-dim": "#d8dada", "on-secondary-fixed-variant": "#264f39",
          "on-primary-container": "#a8e7c5", "on-tertiary-fixed-variant": "#274e3d", "on-tertiary-fixed": "#002114",
          "surface-container": "#eceeed", "surface": "#f8faf9", "secondary-fixed-dim": "#a4d1b4",
          "tertiary-fixed-dim": "#a5d0b9", "surface-variant": "#e1e3e2", "surface-container-lowest": "#ffffff",
          "on-surface": "#191c1c", "secondary": "#3e6750", "surface-container-highest": "#e1e3e2",
          "primary": "#0f5238", "on-error-container": "#93000a", "background": "#f6f8f7",
          "primary-container": "#2d6a4f", "surface-tint": "#2c694e", "on-tertiary-container": "#b8e3cb",
          "on-secondary-container": "#426b54", "inverse-on-surface": "#eff1f0", "secondary-container": "#bdeacd",
          "outline-variant": "#bfc9c1", "inverse-surface": "#2e3131", "on-primary-fixed": "#002114",
          "surface-bright": "#f8faf9", "on-primary-fixed-variant": "#0e5138", "on-primary": "#ffffff",
          "inverse-primary": "#95d4b3", "surface-container-low": "#f2f4f3"
        },
        borderRadius: { DEFAULT: "0.25rem", lg: "0.5rem", xl: "0.75rem", "2xl": "1.25rem", full: "9999px" },
        spacing: { unit: "4px", md: "16px", xs: "4px", "margin-desktop": "48px", gutter: "20px", sm: "8px", "margin-mobile": "16px", xl: "40px", lg: "24px" },
        fontFamily: {
          display: ["Lexend", "sans-serif"],
          body: ["Inter", "sans-serif"]
        },
        boxShadow: {
          card: "0 1px 2px rgba(15,82,56,.04), 0 8px 24px -8px rgba(15,82,56,.10)",
          "card-hover": "0 4px 8px rgba(15,82,56,.06), 0 16px 32px -12px rgba(15,82,56,.16)"
        }
      }
    }
  }
</script>
<style>
  body { font-family: 'Inter', sans-serif; }
  .font-display { font-family: 'Lexend', sans-serif; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; display: inline-block; line-height: 1; }

  .pulse-dot { position: relative; }
  .pulse-dot::after {
    content: ''; position: absolute; width: 100%; height: 100%; top: 0; left: 0;
    background: currentColor; border-radius: 50%; opacity: .45; animation: pulse 2s cubic-bezier(.4,0,.6,1) infinite;
  }
  @keyframes pulse { 0%{transform:scale(1);opacity:.45} 100%{transform:scale(2.6);opacity:0} }

  #sidebar { transition: transform .25s ease; }
  #main-content, #topbar, #footer-shell { transition: margin-left .25s ease, width .25s ease; }

  @media (max-width: 1023px) {
    #sidebar { transform: translateX(-100%); width: 272px; }
    #sidebar.open { transform: translateX(0); }
    #topbar, #main-content, #footer-shell { margin-left: 0 !important; width: 100% !important; }
    #sidebar-overlay.show { display: block; }
  }
  #sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(10,20,15,.45); z-index: 40; backdrop-filter: blur(2px); }

  @media (max-width: 640px) { #topbar .search-box { display: none; } }

  ::-webkit-scrollbar { height: 6px; width: 6px; }
  ::-webkit-scrollbar-thumb { background: #bfc9c1; border-radius: 99px; }

  /* Input fields */
  .form-input, .form-select, .form-textarea {
    width: 100%; padding: 10px 14px; background: #f8faf9; border: 1px solid #bfc9c1;
    border-radius: 0.75rem; font-size: 14px; color: #191c1c; transition: all .15s ease;
  }
  .form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: #0f5238; box-shadow: 0 0 0 3px rgba(15,82,56,.12); background: #ffffff;
  }
  .form-input.error, .form-select.error { border-color: #ba1a1a; }
  .field-error { color: #ba1a1a; font-size: 12px; font-weight: 600; margin-top: 4px; }
</style>
</head>
<body class="bg-background text-on-surface overflow-x-hidden">

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Sidebar Navigation -->
<aside id="sidebar" class="h-screen w-64 fixed left-0 top-0 bg-surface-container-lowest border-r border-outline-variant/60 flex flex-col py-lg px-md z-50">
  <div class="flex items-center gap-md mb-xl px-xs">
    <div class="w-10 h-10 rounded-xl bg-white border border-outline-variant/40 flex items-center justify-center shrink-0 overflow-hidden">
      <img src="assets/logo-uin.png" alt="Logo UIN Sunan Gunung Djati" class="w-full h-full object-contain p-1">
    </div>
    <div>
      <h1 class="font-display text-[18px] font-bold text-primary leading-tight">Vitalis Admin</h1>
      <p class="text-[12px] text-outline">Poli Umum</p>
    </div>
    <button onclick="toggleSidebar()" class="lg:hidden ml-auto p-1.5 text-outline hover:text-primary">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <nav class="flex-1 space-y-1">
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="Dashboard.php">
      <span class="material-symbols-outlined text-[20px]">dashboard</span><span class="text-[15px]">Dashboard</span>
    </a>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-primary/10 transition-all duration-200" href="input-barang.php">
      <span class="material-symbols-outlined text-[20px]">inventory_2</span><span class="text-[15px]">Input Barang</span>
    </a>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="laporan.php">
      <span class="material-symbols-outlined text-[20px]">assessment</span><span class="text-[15px]">Laporan</span>
    </a>
  </nav>
  <div class="mt-auto pt-md border-t border-outline-variant/60">
    <div class="flex items-center gap-md mt-md px-xs">
      <div class="w-9 h-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold shrink-0"><?= htmlspecialchars($initial) ?></div>
      <div class="overflow-hidden flex-1">
        <p class="text-[13px] font-bold truncate"><?= htmlspecialchars($fullName) ?></p>
        <p class="text-[10px] text-outline uppercase tracking-wider"><?= htmlspecialchars(str_replace('_', ' ', $role)) ?></p>
      </div>
      <a href="logout.php" title="Logout" class="p-1.5 text-outline hover:text-error rounded-lg hover:bg-error-container/40 transition-colors shrink-0">
        <span class="material-symbols-outlined text-[20px]">logout</span>
      </a>
    </div>
  </div>
</aside>

<!-- Top Navigation -->
<header id="topbar" class="fixed top-0 right-0 left-0 h-16 bg-surface/85 backdrop-blur-md z-30 flex justify-between items-center gap-md px-margin-mobile sm:px-margin-desktop ml-64 border-b border-outline-variant/50">
  <div class="flex items-center gap-md flex-1 min-w-0">
    <button onclick="toggleSidebar()" class="lg:hidden p-2 -ml-2 text-on-surface-variant hover:text-primary shrink-0">
      <span class="material-symbols-outlined">menu</span>
    </button>
    <div class="relative w-full max-w-md search-box">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-[20px]">search</span>
      <input class="w-full pl-10 pr-md py-2 bg-surface-container-low border-none rounded-full focus:ring-2 focus:ring-primary/25 text-[14px] placeholder:text-outline" placeholder="Cari obat atau alat kesehatan…" type="text">
    </div>
  </div>
  <div class="flex items-center gap-md sm:gap-lg shrink-0">
    <button class="relative p-2 text-on-surface-variant hover:text-primary transition-colors">
      <span class="material-symbols-outlined">notifications</span>
      <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-error rounded-full border-2 border-surface"></span>
    </button>
    <div class="h-7 w-[1px] bg-outline-variant hidden sm:block"></div>
    <div class="flex items-center gap-sm">
      <span class="text-[12px] text-primary font-bold hidden sm:inline">Sistem Aktif</span>
      <div class="w-2 h-2 rounded-full bg-primary pulse-dot"></div>
    </div>
  </div>
</header>

<!-- Main Content -->
<main id="main-content" class="ml-64 pt-24 pb-16 px-margin-mobile sm:px-margin-desktop min-h-screen">

  <!-- Breadcrumb & Header -->
  <div class="mb-lg">
    <div class="flex items-center gap-xs text-[12px] text-outline mb-2">
      <a href="Dashboard.php" class="hover:text-primary">Dashboard</a>
      <span class="material-symbols-outlined text-[14px]">chevron_right</span>
      <span class="text-on-surface-variant font-medium">Input Barang</span>
    </div>
    <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-1">Tambah Barang Baru</h2>
    <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Catat item obat atau alat kesehatan baru ke inventaris Poli Umum.</p>
  </div>

  <?php if ($success): ?>
    <div class="mb-lg flex items-start gap-md bg-primary/10 border border-primary/20 text-primary rounded-2xl p-lg">
      <span class="material-symbols-outlined">check_circle</span>
      <div>
        <p class="font-bold text-[14px]">Barang berhasil ditambahkan!</p>
        <p class="text-[13px] text-on-surface-variant mt-0.5">Item baru sudah tercatat dalam inventaris. Anda dapat menambahkan barang lain atau kembali ke dashboard.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="mb-lg flex items-start gap-md bg-error-container/40 border border-error/20 text-error rounded-2xl p-lg">
      <span class="material-symbols-outlined">error</span>
      <div>
        <p class="font-bold text-[14px]">Terjadi kesalahan saat menyimpan:</p>
        <ul class="text-[13px] mt-1 list-disc list-inside space-y-0.5">
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 gap-gutter items-start">

    <!-- Form -->
    <form method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card p-lg sm:p-xl space-y-lg" novalidate>

      <div>
        <h3 class="font-display text-[16px] font-bold text-on-surface mb-1">Informasi Barang</h3>
        <p class="text-[13px] text-outline">Lengkapi detail item sesuai data fisik di gudang Poli Umum.</p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-md">
        <div class="sm:col-span-2">
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="nama">Nama Barang <span class="text-error">*</span></label>
          <input type="text" id="nama" name="nama" value="<?= htmlspecialchars($nama) ?>" placeholder="Contoh: Paracetamol 500mg" class="form-input" required>
        </div>

        <div>
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="sku">SKU / Kode Barang <span class="text-error">*</span></label>
          <input type="text" id="sku" name="sku" value="<?= htmlspecialchars($sku) ?>" placeholder="Contoh: OBT-0012" class="form-input" required>
        </div>

        <div>
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="kategori">Kategori <span class="text-error">*</span></label>
          <select id="kategori" name="kategori" class="form-select" required>
            <option value="" disabled <?= $kategori === '' ? 'selected' : '' ?>>Pilih kategori</option>
            <?php foreach ($kategoriList as $k): ?>
              <option value="<?= htmlspecialchars($k) ?>" <?= $kategori === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="stok">Jumlah Stok Awal <span class="text-error">*</span></label>
          <input type="number" min="0" id="stok" name="stok" value="<?= htmlspecialchars((string)$stok) ?>" placeholder="0" class="form-input" required>
        </div>

        <div>
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="satuan">Satuan <span class="text-error">*</span></label>
          <select id="satuan" name="satuan" class="form-select" required>
            <option value="" disabled <?= $satuan === '' ? 'selected' : '' ?>>Pilih satuan</option>
            <?php foreach ($satuanList as $s): ?>
              <option value="<?= htmlspecialchars($s) ?>" <?= $satuan === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="sm:col-span-2">
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="ambang_minimum">
            Ambang Batas Stok Rendah
            <span class="text-outline font-normal normal-case">(item akan ditandai "Stok Rendah" jika stok ≤ angka ini)</span>
          </label>
          <input type="number" min="0" id="ambang_minimum" name="ambang_minimum" value="<?= htmlspecialchars((string)$ambang) ?>" placeholder="15" class="form-input sm:max-w-[200px]">
        </div>

        <div class="sm:col-span-2">
          <label class="block text-[13px] font-bold text-on-surface-variant mb-1.5" for="catatan">Catatan <span class="text-outline font-normal normal-case">(opsional)</span></label>
          <textarea id="catatan" name="catatan" rows="3" placeholder="Contoh: Disimpan di lemari pendingin, suhu 2–8°C" class="form-textarea"><?= htmlspecialchars($catatan) ?></textarea>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-sm pt-md border-t border-outline-variant/60">
        <button type="submit" class="flex items-center justify-center gap-sm bg-primary text-white px-lg py-3 rounded-xl font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all shadow-card">
          <span class="material-symbols-outlined text-[20px]">save</span>
          <span>Simpan Barang</span>
        </button>
        <a href="Dashboard.php" class="flex items-center justify-center gap-sm border border-outline-variant px-lg py-3 rounded-xl font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-all">
          <span>Batal</span>
        </a>
      </div>
    </form>
  </div>
</main>

<!-- Footer -->
<footer id="footer-shell" class="ml-64 bg-surface-container-lowest border-t border-outline-variant/60 py-md px-margin-mobile sm:px-margin-desktop flex flex-col sm:flex-row gap-sm justify-between items-center text-center sm:text-left">
  <div class="flex flex-col sm:flex-row items-center gap-xs sm:gap-md">
    <span class="text-[12px] font-bold text-primary">Dashboard Admin Poli</span>
    <span class="text-[12px] text-outline">© <?= date('Y') ?> Klinik Pratama UIN Bandung. Sistem berjalan normal.</span>
  </div>
  <div class="flex gap-lg">
    <span class="text-[12px] text-outline">v2.5.0</span>
  </div>
</footer>

<script>
  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
  }

  const searchInput = document.querySelector('input[type="text"][placeholder*="Cari"]');
  if (searchInput) {
    searchInput.addEventListener('focus', () => searchInput.parentElement.classList.add('ring-2', 'ring-primary/20'));
    searchInput.addEventListener('blur', () => searchInput.parentElement.classList.remove('ring-2', 'ring-primary/20'));
  }
</script>
</body>
</html>