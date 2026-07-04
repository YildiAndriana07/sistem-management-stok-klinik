<?php
require_once __DIR__ . '/config.php';

// === Auth Guard ===
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// === Role Guard: hanya admin_poli2 (Poli Gigi) & super_admin yang boleh akses ===
$roleDashboardMap = [
    'admin_poli1'  => 'Dashboard.php',
    'admin_poli2'  => 'dashboard-poli-gigi.php',
    'admin_poli3'  => 'dashboard-poli-kia.php',
];
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole !== 'admin_poli2' && $currentRole !== 'super_admin') {
    $redirectTo = $roleDashboardMap[$currentRole] ?? 'login.php';
    header('Location: ' . $redirectTo);
    exit;
}

$fullName = $_SESSION['full_name'] ?? 'Administrator';
$role     = $_SESSION['role'] ?? 'Admin';
$initial  = strtoupper(substr($fullName, 0, 1));

$daftarKategori = ['Obat', 'Alat Gigi', 'Bahan Tambal', 'Habis Pakai'];
$daftarSatuanUmum = ['pcs', 'box', 'botol', 'tube', 'strip', 'pack', 'ampul', 'set'];
$items = [];

try {
    $pdo = getDbConnection();

    // =========================================================
    // === CRUD: proses aksi (Tambah, Edit, Hapus)
    // =========================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $flashStatus = 'success';
        $flashMsg    = '';

        try {
            if ($action === 'add_item') {
                $nama    = trim((string) ($_POST['nama'] ?? ''));
                $sku     = trim((string) ($_POST['sku'] ?? ''));
                $kategori= (string) ($_POST['kategori'] ?? '');
                $stok    = max(0, (int) ($_POST['stok'] ?? 0));
                $ambang  = max(0, (int) ($_POST['ambang_minimum'] ?? 15));
                $satuan  = trim((string) ($_POST['satuan'] ?? ''));
                $catatan = trim((string) ($_POST['catatan'] ?? ''));

                if ($nama === '' || $sku === '' || $satuan === '' || !in_array($kategori, $daftarKategori, true)) {
                    $flashStatus = 'error';
                    $flashMsg = 'Semua field wajib diisi dengan benar.';
                } else {
                    $status = $stok <= $ambang ? 'low' : 'available';
                    $stmt = $pdo->prepare('INSERT INTO items (nama, sku, kategori, stok, ambang_minimum, satuan, status, poli, catatan) VALUES (:nama, :sku, :kategori, :stok, :ambang, :satuan, :status, "gigi", :catatan)');
                    $stmt->execute([
                        ':nama'    => $nama,
                        ':sku'     => $sku,
                        ':kategori'=> $kategori,
                        ':stok'    => $stok,
                        ':ambang'  => $ambang,
                        ':satuan'  => $satuan,
                        ':status'  => $status,
                        ':catatan' => $catatan !== '' ? $catatan : null,
                    ]);
                    $flashMsg = 'Barang baru berhasil ditambahkan.';
                }

            } elseif ($action === 'edit_item') {
                $id      = (int) ($_POST['id'] ?? 0);
                $nama    = trim((string) ($_POST['nama'] ?? ''));
                $sku     = trim((string) ($_POST['sku'] ?? ''));
                $kategori= (string) ($_POST['kategori'] ?? '');
                $stok    = max(0, (int) ($_POST['stok'] ?? 0));
                $ambang  = max(0, (int) ($_POST['ambang_minimum'] ?? 15));
                $satuan  = trim((string) ($_POST['satuan'] ?? ''));
                $catatan = trim((string) ($_POST['catatan'] ?? ''));

                if ($nama === '' || $sku === '' || $satuan === '' || !in_array($kategori, $daftarKategori, true)) {
                    $flashStatus = 'error';
                    $flashMsg = 'Semua field wajib diisi dengan benar.';
                } else {
                    $status = $stok <= $ambang ? 'low' : 'available';
                    $stmt = $pdo->prepare('UPDATE items SET nama = :nama, sku = :sku, kategori = :kategori, stok = :stok, ambang_minimum = :ambang, satuan = :satuan, status = :status, catatan = :catatan WHERE id = :id AND poli = "gigi"');
                    $stmt->execute([
                        ':nama'    => $nama,
                        ':sku'     => $sku,
                        ':kategori'=> $kategori,
                        ':stok'    => $stok,
                        ':ambang'  => $ambang,
                        ':satuan'  => $satuan,
                        ':status'  => $status,
                        ':catatan' => $catatan !== '' ? $catatan : null,
                        ':id'      => $id,
                    ]);
                    $flashMsg = 'Data barang berhasil diperbarui.';
                }

            } elseif ($action === 'delete_item') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM items WHERE id = :id AND poli = "gigi"');
                $stmt->execute([':id' => $id]);
                $flashMsg = 'Barang berhasil dihapus.';
            }
        } catch (PDOException $e) {
            $flashStatus = 'error';
            if ((int) $e->getCode() === 23000) {
                $flashMsg = 'SKU sudah dipakai barang lain. Gunakan SKU yang berbeda.';
            } else {
                $flashMsg = 'Gagal memproses permintaan. Silakan coba lagi.';
            }
        } catch (Throwable $e) {
            $flashStatus = 'error';
            $flashMsg = 'Gagal memproses permintaan. Silakan coba lagi.';
        }

        header('Location: input-barang-gigi.php?' . http_build_query(['status' => $flashStatus, 'msg' => $flashMsg]));
        exit;
    }

    // =========================================================
    // === Ambil data barang Poli Gigi
    // =========================================================
    $stmt = $pdo->query('SELECT id, nama, sku, kategori, stok, ambang_minimum, satuan, status, catatan FROM items WHERE poli = "gigi" ORDER BY nama ASC');
    $result = $stmt->fetchAll();
    if ($result) $items = $result;

} catch (Throwable $e) {
    // Koneksi/tabel belum tersedia — tampilkan halaman kosong sebagai fallback
}

function statusBadge(string $status): string {
    $isLow = $status === 'low';
    $color = $isLow ? 'error' : 'primary';
    $label = $isLow ? 'Stok Rendah' : 'Tersedia';
    return '<div class="inline-flex items-center gap-1.5 text-label-sm font-bold rounded-full text-' . $color . '">'
         . '<span class="w-1.5 h-1.5 rounded-full bg-' . $color . ($isLow ? ' animate-pulse' : '') . '"></span>' . $label . '</div>';
}

function kategoriBadge(string $kategori): string {
    $map = [
        'Obat' => 'bg-secondary-container text-on-secondary-container',
        'Alat Gigi' => 'bg-tertiary-container/20 text-tertiary',
        'Bahan Tambal' => 'bg-error-container/40 text-error',
        'Habis Pakai' => 'bg-primary-fixed text-on-primary-fixed-variant',
    ];
    $cls = $map[$kategori] ?? 'bg-surface-variant text-on-surface-variant';
    return '<span class="px-2.5 py-1 ' . $cls . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($kategori) . '</span>';
}

$flashStatus = $_GET['status'] ?? null;
$flashMsg    = $_GET['msg'] ?? null;
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Admin — Input Barang Poli Gigi</title>
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

  #sidebar { transition: transform .25s ease; }
  #main-content, #topbar, #footer-shell { transition: margin-left .25s ease, width .25s ease; }

  @media (max-width: 1023px) {
    #sidebar { transform: translateX(-100%); width: 272px; }
    #sidebar.open { transform: translateX(0); }
    #topbar, #main-content, #footer-shell { margin-left: 0 !important; width: 100% !important; }
    #sidebar-overlay.show { display: block; }
  }
  #sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(10,20,15,.45); z-index: 40; backdrop-filter: blur(2px); }

  .desktop-table { display: none; }
  .mobile-cards { display: block; }
  @media (min-width: 768px) {
    .desktop-table { display: block; }
    .mobile-cards { display: none; }
  }

  ::-webkit-scrollbar { height: 6px; width: 6px; }
  ::-webkit-scrollbar-thumb { background: #bfc9c1; border-radius: 99px; }

  @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes toastIn { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
  @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(24px); } }

  .animate-row { animation: fadeInUp .35s ease both; }
  .animate-card { animation: fadeInUp .4s ease both; }

  .row-hidden { display: none !important; }
  .card-hidden { display: none !important; }

  .modal-overlay { transition: opacity .2s ease; }
  .modal-panel { transition: opacity .2s ease, transform .2s ease; }
  .modal-hidden .modal-overlay { opacity: 0; pointer-events: none; }
  .modal-hidden .modal-panel { opacity: 0; transform: scale(.95) translateY(8px); }
  .modal-hidden { pointer-events: none; }

  .toast-enter { animation: toastIn .25s ease both; }
  .toast-leave { animation: toastOut .25s ease both; }
</style>
</head>
<body class="bg-background text-on-surface overflow-x-hidden">

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
<div id="toast-container" class="fixed top-20 right-4 z-[70] flex flex-col gap-2 w-[90vw] max-w-sm"></div>

<!-- Sidebar Navigation -->
<aside id="sidebar" class="h-screen w-64 fixed left-0 top-0 bg-surface-container-lowest border-r border-outline-variant/60 flex flex-col py-lg px-md z-50">
  <div class="flex items-center gap-md mb-xl px-xs">
    <div class="w-10 h-10 rounded-xl bg-white border border-outline-variant/40 flex items-center justify-center shrink-0 overflow-hidden">
      <img src="assets/logo-uin.png" alt="Logo UIN Sunan Gunung Djati" class="w-full h-full object-contain p-1">
    </div>
    <div>
      <h1 class="font-display text-[18px] font-bold text-primary leading-tight">ADMIN</h1>
      <p class="text-[12px] text-outline">Poli Gigi</p>
    </div>
    <button onclick="toggleSidebar()" class="lg:hidden ml-auto p-1.5 text-outline hover:text-primary">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <nav class="flex-1 space-y-1">
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="dashboard-poli-gigi.php">
      <span class="material-symbols-outlined text-[20px]">dashboard</span><span class="text-[15px]">Dashboard</span>
    </a>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-primary/10 transition-all duration-200" href="input-barang-gigi.php">
      <span class="material-symbols-outlined text-[20px]">inventory_2</span><span class="text-[15px]">Input Barang</span>
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
    <div class="relative w-full max-w-md">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-[20px]">search</span>
      <input id="search-input" class="w-full pl-10 pr-md py-2 bg-surface-container-low border-none rounded-full focus:ring-2 focus:ring-primary/25 text-[14px] placeholder:text-outline" placeholder="Cari barang Poli Gigi…" type="text">
    </div>
  </div>
  <div class="flex items-center gap-sm shrink-0">
    <span class="text-[12px] text-primary font-bold hidden sm:inline">Sistem Aktif</span>
    <div class="w-2 h-2 rounded-full bg-primary"></div>
  </div>
</header>

<!-- Main Content -->
<main id="main-content" class="ml-64 pt-24 pb-16 px-margin-mobile sm:px-margin-desktop min-h-screen">
  <div class="mb-lg">
    <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-1">Input Barang Poli Gigi</h2>
    <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Tambahkan barang baru atau kelola data barang yang sudah tercatat.</p>
  </div>

  <!-- Form Tambah Barang -->
  <div class="animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card mb-8">
    <div class="flex items-center gap-sm mb-lg">
      <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">add_circle</span>
      <h3 class="font-display text-[16px] font-bold text-on-surface">Tambah Barang Baru</h3>
    </div>
    <form method="POST" action="input-barang-gigi.php" class="grid grid-cols-1 md:grid-cols-2 gap-md">
      <input type="hidden" name="action" value="add_item">
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Nama Barang</label>
        <input type="text" name="nama" required placeholder="cth. Tang Cabut Gigi Dewasa" class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">SKU</label>
        <input type="text" name="sku" required placeholder="cth. GIGI-001" class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Kategori</label>
        <select name="kategori" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
          <option value="" disabled selected>Pilih kategori</option>
          <?php foreach ($daftarKategori as $kat): ?>
          <option value="<?= htmlspecialchars($kat) ?>"><?= htmlspecialchars($kat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Satuan</label>
        <input list="daftar-satuan" type="text" name="satuan" required placeholder="cth. pcs, box, botol" class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
        <datalist id="daftar-satuan">
          <?php foreach ($daftarSatuanUmum as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Stok Awal</label>
        <input type="number" name="stok" min="0" value="0" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Ambang Batas Minimum</label>
        <input type="number" name="ambang_minimum" min="0" value="15" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div class="md:col-span-2">
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Catatan (opsional)</label>
        <textarea name="catatan" rows="2" placeholder="Info tambahan, lokasi penyimpanan, dsb." class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all resize-none"></textarea>
      </div>
      <div class="md:col-span-2 flex justify-end">
        <button type="submit" class="flex items-center gap-sm bg-primary text-white px-lg py-2.5 rounded-xl font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all shadow-card">
          <span class="material-symbols-outlined text-[20px]">save</span>
          <span>Simpan Barang</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Daftar Barang -->
  <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex flex-col md:flex-row md:items-center justify-between gap-md">
      <h3 class="font-display text-[17px] font-bold text-on-surface">Daftar Barang Poli Gigi</h3>
      <span id="visible-info" class="text-[12px] text-outline font-medium"></span>
    </div>

    <?php if (empty($items)): ?>
      <div class="py-16 px-lg text-center">
        <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">inventory_2</span>
        <p class="text-on-surface-variant font-medium">Belum ada barang tercatat.</p>
        <p class="text-[13px] text-outline mt-1">Gunakan form di atas untuk menambahkan barang pertama.</p>
      </div>
    <?php else: ?>

      <!-- Tabel: layar md ke atas -->
      <div class="desktop-table overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-surface-container text-outline text-[11px] border-b border-outline-variant/60">
              <th class="py-md px-lg font-bold uppercase tracking-wider">Nama Barang</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Kategori</th>
              <th class="py-md px-md font-bold uppercase tracking-wider text-center">Stok</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Status</th>
              <th class="py-md px-lg font-bold uppercase tracking-wider text-right">Aksi</th>
            </tr>
          </thead>
          <tbody id="table-body" class="divide-y divide-outline-variant/30">
            <?php foreach ($items as $i => $item): ?>
            <tr class="hover:bg-surface-container-low transition-colors group animate-row"
                style="animation-delay:<?= min($i * 0.03, 0.4) ?>s"
                data-nama="<?= htmlspecialchars(mb_strtolower($item['nama'])) ?>">
              <td class="py-md px-lg">
                <div class="font-bold text-on-surface text-[14px]"><?= htmlspecialchars($item['nama']) ?></div>
                <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
              </td>
              <td class="py-md px-md"><?= kategoriBadge($item['kategori']) ?></td>
              <td class="py-md px-md text-center font-mono text-[14px]"><?= htmlspecialchars((string) $item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span></td>
              <td class="py-md px-md"><?= statusBadge($item['status']) ?></td>
              <td class="py-md px-lg text-right">
                <div class="flex justify-end gap-xs opacity-0 group-hover:opacity-100 transition-opacity">
                  <button type="button" class="p-2 text-primary hover:bg-primary/10 rounded-lg transition-transform hover:scale-110" title="Edit Barang"
                    onclick='openEditModal(<?= json_encode($item) ?>)'>
                    <span class="material-symbols-outlined text-[20px]">edit_note</span>
                  </button>
                  <button type="button" class="p-2 text-error hover:bg-error/10 rounded-lg transition-transform hover:scale-110" title="Hapus"
                    onclick='openDeleteModal(<?= (int) $item["id"] ?>, <?= json_encode($item["nama"]) ?>)'>
                    <span class="material-symbols-outlined text-[20px]">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Kartu: mobile -->
      <div id="cards-body" class="mobile-cards divide-y divide-outline-variant/40">
        <?php foreach ($items as $i => $item): ?>
        <div class="p-lg flex flex-col gap-sm animate-row"
             style="animation-delay:<?= min($i * 0.03, 0.4) ?>s"
             data-nama="<?= htmlspecialchars(mb_strtolower($item['nama'])) ?>">
          <div class="flex justify-between items-start gap-md">
            <div class="min-w-0">
              <div class="font-bold text-on-surface text-[15px] truncate"><?= htmlspecialchars($item['nama']) ?></div>
              <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
            </div>
            <?= kategoriBadge($item['kategori']) ?>
          </div>
          <div class="flex justify-between items-center mt-1">
            <div class="font-mono text-[14px] text-on-surface">
              <?= htmlspecialchars((string) $item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span>
            </div>
            <?= statusBadge($item['status']) ?>
          </div>
          <div class="flex gap-sm mt-2">
            <button type="button" class="flex-1 flex items-center justify-center gap-xs py-2 border border-outline-variant rounded-lg text-[13px] font-bold text-primary active:scale-95 transition-transform"
              onclick='openEditModal(<?= json_encode($item) ?>)'>
              <span class="material-symbols-outlined text-[18px]">edit_note</span>Edit
            </button>
            <button type="button" class="w-11 flex items-center justify-center py-2 border border-outline-variant rounded-lg text-error active:scale-95 transition-transform"
              onclick='openDeleteModal(<?= (int) $item["id"] ?>, <?= json_encode($item["nama"]) ?>)'>
              <span class="material-symbols-outlined text-[18px]">delete</span>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="empty-filtered" class="hidden py-16 px-lg text-center">
        <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">search_off</span>
        <p class="text-on-surface-variant font-medium">Tidak ada barang yang cocok dengan pencarian.</p>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Footer -->
<footer id="footer-shell" class="ml-64 bg-surface-container-lowest border-t border-outline-variant/60 py-md px-margin-mobile sm:px-margin-desktop flex flex-col sm:flex-row gap-sm justify-between items-center text-center sm:text-left">
  <div class="flex flex-col sm:flex-row items-center gap-xs sm:gap-md">
    <span class="text-[12px] font-bold text-primary">Input Barang — Poli Gigi</span>
    <span class="text-[12px] text-outline">© <?= date('Y') ?> Klinik Pratama UIN Bandung. Sistem berjalan normal.</span>
  </div>
  <div class="flex gap-lg">
    <span class="text-[12px] text-outline">v2.6.0</span>
  </div>
</footer>

<!-- ============================================================= -->
<!-- Modal: Edit Barang (lengkap) -->
<!-- ============================================================= -->
<div id="modal-edit" class="modal-hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
  <div class="modal-overlay absolute inset-0 bg-black/40" onclick="closeModal('modal-edit')"></div>
  <form method="POST" action="input-barang-gigi.php" class="modal-panel relative bg-surface-container-lowest rounded-2xl shadow-card-hover w-full max-w-md p-lg max-h-[90vh] overflow-y-auto">
    <input type="hidden" name="action" value="edit_item">
    <input type="hidden" name="id" id="edit-id">
    <div class="flex items-center justify-between mb-md">
      <h3 class="font-display font-bold text-[17px] text-on-surface">Edit Barang</h3>
      <button type="button" onclick="closeModal('modal-edit')" class="p-1 text-outline hover:text-error rounded-lg"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="space-y-md">
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Nama Barang</label>
        <input type="text" name="nama" id="edit-nama" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">SKU</label>
        <input type="text" name="sku" id="edit-sku" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Kategori</label>
        <select name="kategori" id="edit-kategori" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
          <?php foreach ($daftarKategori as $kat): ?>
          <option value="<?= htmlspecialchars($kat) ?>"><?= htmlspecialchars($kat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-md">
        <div>
          <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Stok</label>
          <input type="number" name="stok" id="edit-stok" min="0" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
        </div>
        <div>
          <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Ambang Minimum</label>
          <input type="number" name="ambang_minimum" id="edit-ambang" min="0" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
        </div>
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Satuan</label>
        <input list="daftar-satuan" type="text" name="satuan" id="edit-satuan" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Catatan (opsional)</label>
        <textarea name="catatan" id="edit-catatan" rows="2" class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all resize-none"></textarea>
      </div>
    </div>
    <div class="flex gap-sm mt-lg">
      <button type="button" onclick="closeModal('modal-edit')" class="flex-1 py-2.5 rounded-xl border border-outline-variant font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-colors">Batal</button>
      <button type="submit" class="flex-1 py-2.5 rounded-xl bg-primary text-white font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all">Simpan Perubahan</button>
    </div>
  </form>
</div>

<!-- ============================================================= -->
<!-- Modal: Hapus Barang -->
<!-- ============================================================= -->
<div id="modal-delete" class="modal-hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
  <div class="modal-overlay absolute inset-0 bg-black/40" onclick="closeModal('modal-delete')"></div>
  <form method="POST" action="input-barang-gigi.php" class="modal-panel relative bg-surface-container-lowest rounded-2xl shadow-card-hover w-full max-w-sm p-lg text-center">
    <input type="hidden" name="action" value="delete_item">
    <input type="hidden" name="id" id="delete-id">
    <div class="w-14 h-14 rounded-full bg-error-container flex items-center justify-center mx-auto mb-md">
      <span class="material-symbols-outlined text-error text-[28px]">delete</span>
    </div>
    <h3 class="font-display font-bold text-[17px] text-on-surface mb-1">Hapus Barang?</h3>
    <p class="text-[14px] text-on-surface-variant mb-lg">Yakin ingin menghapus <span id="delete-nama" class="font-bold text-error"></span>? Tindakan ini tidak dapat dibatalkan.</p>
    <div class="flex gap-sm">
      <button type="button" onclick="closeModal('modal-delete')" class="flex-1 py-2.5 rounded-xl border border-outline-variant font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-colors">Batal</button>
      <button type="submit" class="flex-1 py-2.5 rounded-xl bg-error text-white font-bold text-[14px] hover:opacity-90 active:scale-[0.98] transition-all">Hapus</button>
    </div>
  </form>
</div>

<script>
  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
  }

  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    searchInput.addEventListener('input', () => applyFilters());
  }

  function applyFilters() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#table-body tr, #cards-body > div');
    let visible = 0;

    rows.forEach(row => {
      const show = !query || (row.dataset.nama || '').includes(query);
      row.classList.toggle('row-hidden', !show);
      row.classList.toggle('card-hidden', !show);
      if (show) visible++;
    });

    const emptyState = document.getElementById('empty-filtered');
    if (emptyState) emptyState.classList.toggle('hidden', visible !== 0 || rows.length === 0);

    const infoEl = document.getElementById('visible-info');
    if (infoEl) infoEl.textContent = query ? `${visible} hasil ditemukan` : '';
  }

  // === Modal helpers ===
  function openModal(id) { document.getElementById(id).classList.remove('modal-hidden'); }
  function closeModal(id) { document.getElementById(id).classList.add('modal-hidden'); }

  function openEditModal(item) {
    document.getElementById('edit-id').value = item.id;
    document.getElementById('edit-nama').value = item.nama;
    document.getElementById('edit-sku').value = item.sku;
    document.getElementById('edit-kategori').value = item.kategori;
    document.getElementById('edit-stok').value = item.stok;
    document.getElementById('edit-ambang').value = item.ambang_minimum;
    document.getElementById('edit-satuan').value = item.satuan;
    document.getElementById('edit-catatan').value = item.catatan || '';
    openModal('modal-edit');
  }

  function openDeleteModal(id, nama) {
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-nama').textContent = nama;
    openModal('modal-delete');
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      ['modal-edit', 'modal-delete'].forEach(closeModal);
    }
  });

  // === Toast notifikasi ===
  function showToast(status, message) {
    if (!message) return;
    const container = document.getElementById('toast-container');
    const isError = status === 'error';
    const el = document.createElement('div');
    el.className = `toast-enter flex items-center gap-sm p-md rounded-xl shadow-card-hover text-[13px] font-bold text-white ${isError ? 'bg-error' : 'bg-primary'}`;
    el.innerHTML = `<span class="material-symbols-outlined text-[20px]">${isError ? 'error' : 'check_circle'}</span><span class="flex-1">${message}</span>`;
    container.appendChild(el);
    setTimeout(() => {
      el.classList.remove('toast-enter');
      el.classList.add('toast-leave');
      setTimeout(() => el.remove(), 250);
    }, 3500);
  }

  window.addEventListener('DOMContentLoaded', () => {
    <?php if ($flashMsg): ?>
    showToast(<?= json_encode($flashStatus) ?>, <?= json_encode($flashMsg) ?>);
    if (window.history.replaceState) {
      window.history.replaceState({}, document.title, 'input-barang-gigi.php');
    }
    <?php endif; ?>
  });
</script>
</body>
</html>