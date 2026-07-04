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

// === Filter dari query string ===
$kategoriFilter = $_GET['kategori'] ?? '';
$statusFilter   = $_GET['status'] ?? '';
$dariTanggal    = $_GET['dari'] ?? '';
$sampaiTanggal  = $_GET['sampai'] ?? '';

$kategoriList = ['Obat', 'Alkes', 'Habis Pakai'];

// === Data laporan ===
$totalItem       = 0;
$totalStok       = 0;
$stokRendah      = 0;
$kategoriSummary = [];
$items           = [];
$lowStockItems   = [];

try {
    $pdo = getDbConnection();

    // Ringkasan umum
    $stmt = $pdo->query('SELECT COUNT(*) AS jumlah, COALESCE(SUM(stok), 0) AS total FROM items WHERE poli = "umum"');
    $row = $stmt->fetch();
    if ($row) {
        $totalItem = (int) $row['jumlah'];
        $totalStok = (int) $row['total'];
    }

    $stmt = $pdo->query('SELECT COUNT(*) AS jumlah FROM items WHERE poli = "umum" AND status = "low"');
    $row = $stmt->fetch();
    if ($row) $stokRendah = (int) $row['jumlah'];

    // Breakdown per kategori
    $stmt = $pdo->query('SELECT kategori, COUNT(*) AS jumlah_item, COALESCE(SUM(stok), 0) AS total_stok
                          FROM items WHERE poli = "umum" GROUP BY kategori ORDER BY total_stok DESC');
    $kategoriSummary = $stmt->fetchAll();

    // Item stok rendah (untuk panel perhatian)
    $stmt = $pdo->query('SELECT nama, sku, kategori, stok, satuan, ambang_minimum
                          FROM items WHERE poli = "umum" AND status = "low" ORDER BY stok ASC LIMIT 6');
    $lowStockItems = $stmt->fetchAll();

    // Query utama dengan filter
    $where  = ['poli = "umum"'];
    $params = [];

    if ($kategoriFilter !== '' && in_array($kategoriFilter, $kategoriList, true)) {
        $where[] = 'kategori = :kategori';
        $params['kategori'] = $kategoriFilter;
    }
    if ($statusFilter === 'low' || $statusFilter === 'available') {
        $where[] = 'status = :status';
        $params['status'] = $statusFilter;
    }
    if ($dariTanggal !== '') {
        $where[] = 'DATE(created_at) >= :dari';
        $params['dari'] = $dariTanggal;
    }
    if ($sampaiTanggal !== '') {
        $where[] = 'DATE(created_at) <= :sampai';
        $params['sampai'] = $sampaiTanggal;
    }

    $sql = 'SELECT nama, sku, kategori, stok, satuan, status, created_at FROM items WHERE '
         . implode(' AND ', $where) . ' ORDER BY nama ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

} catch (Throwable $e) {
    // Koneksi/tabel belum tersedia — halaman tetap tampil dengan data kosong
}

// === Export CSV ===
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-inventaris-poli-umum-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8 dengan benar
    fputcsv($out, ['Nama Barang', 'SKU', 'Kategori', 'Stok', 'Satuan', 'Status', 'Tanggal Dicatat']);
    foreach ($items as $item) {
        fputcsv($out, [
            $item['nama'],
            $item['sku'],
            $item['kategori'],
            $item['stok'],
            $item['satuan'],
            $item['status'] === 'low' ? 'Stok Rendah' : 'Tersedia',
            !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '',
        ]);
    }
    fclose($out);
    exit;
}

function statusBadgeLaporan(string $status): string {
    $isLow = $status === 'low';
    $color = $isLow ? 'error' : 'primary';
    $label = $isLow ? 'Stok Rendah' : 'Tersedia';
    return '<div class="inline-flex items-center gap-1.5 text-label-sm font-bold text-' . $color . '">'
         . '<span class="w-1.5 h-1.5 rounded-full bg-' . $color . ($isLow ? ' animate-pulse' : '') . '"></span>' . $label . '</div>';
}

function kategoriBadgeLaporan(string $kategori): string {
    $map = [
        'Obat' => 'bg-secondary-container text-on-secondary-container',
        'Alkes' => 'bg-tertiary-container/20 text-tertiary',
        'Habis Pakai' => 'bg-primary-fixed text-on-primary-fixed-variant',
    ];
    $cls = $map[$kategori] ?? 'bg-surface-variant text-on-surface-variant';
    return '<span class="px-2.5 py-1 ' . $cls . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($kategori) . '</span>';
}

function kategoriDot(string $kategori): string {
    $map = ['Obat' => 'bg-secondary', 'Alkes' => 'bg-tertiary', 'Habis Pakai' => 'bg-primary'];
    return $map[$kategori] ?? 'bg-outline';
}
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Vitalis Admin — Laporan Poli Umum</title>
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

  .desktop-table { display: none; }
  .mobile-cards { display: block; }
  @media (min-width: 768px) {
    .desktop-table { display: block; }
    .mobile-cards { display: none; }
  }

  ::-webkit-scrollbar { height: 6px; width: 6px; }
  ::-webkit-scrollbar-thumb { background: #bfc9c1; border-radius: 99px; }

  .form-input, .form-select {
    width: 100%; padding: 9px 14px; background: #f8faf9; border: 1px solid #bfc9c1;
    border-radius: 0.75rem; font-size: 13px; color: #191c1c; transition: all .15s ease;
  }
  .form-input:focus, .form-select:focus {
    outline: none; border-color: #0f5238; box-shadow: 0 0 0 3px rgba(15,82,56,.12); background: #ffffff;
  }

  @media print {
    #sidebar, #sidebar-overlay, #topbar, #footer-shell, .no-print { display: none !important; }
    #main-content { margin-left: 0 !important; padding-top: 0 !important; }
    body { background: #ffffff; }
  }
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
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="input-barang.php">
      <span class="material-symbols-outlined text-[20px]">inventory_2</span><span class="text-[15px]">Input Barang</span>
    </a>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-primary/10 transition-all duration-200" href="laporan.php">
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

  <div class="mb-lg flex flex-col sm:flex-row sm:justify-between sm:items-end gap-md">
    <div>
      <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-1">Laporan Inventaris</h2>
      <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Rekap stok, kategori, dan status barang Poli Umum.</p>
    </div>
    <div class="flex gap-sm no-print">
      <button onclick="window.print()" class="flex items-center justify-center gap-sm border border-outline-variant px-lg py-3 rounded-xl font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-all">
        <span class="material-symbols-outlined text-[20px]">print</span>
        <span class="hidden sm:inline">Cetak Laporan</span>
      </button>
      <a href="laporan.php?export=csv<?= $kategoriFilter ? '&kategori=' . urlencode($kategoriFilter) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?>" class="flex items-center justify-center gap-sm bg-primary text-white px-lg py-3 rounded-xl font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all shadow-card">
        <span class="material-symbols-outlined text-[20px]">download</span>
        <span class="hidden sm:inline">Export CSV</span>
      </a>
    </div>
  </div>

  <!-- Ringkasan -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-gutter mb-lg">
    <div class="bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card">
      <div class="flex items-center justify-between mb-md">
        <span class="text-[11px] font-bold uppercase tracking-widest text-outline">Total Item</span>
        <span class="material-symbols-outlined text-primary bg-primary/10 p-1.5 rounded-lg text-[18px]">category</span>
      </div>
      <span class="text-[28px] font-display font-bold text-on-surface"><?= number_format($totalItem, 0, ',', '.') ?></span>
    </div>
    <div class="bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card">
      <div class="flex items-center justify-between mb-md">
        <span class="text-[11px] font-bold uppercase tracking-widest text-outline">Total Stok</span>
        <span class="material-symbols-outlined text-primary bg-primary/10 p-1.5 rounded-lg text-[18px]">inventory</span>
      </div>
      <span class="text-[28px] font-display font-bold text-on-surface"><?= number_format($totalStok, 0, ',', '.') ?></span>
    </div>
    <div class="bg-surface-container-lowest p-lg rounded-2xl border border-error/20 shadow-card">
      <div class="flex items-center justify-between mb-md">
        <span class="text-[11px] font-bold uppercase tracking-widest text-error">Stok Rendah</span>
        <span class="material-symbols-outlined text-error bg-error-container p-1.5 rounded-lg text-[18px]">warning</span>
      </div>
      <span class="text-[28px] font-display font-bold text-error"><?= number_format($stokRendah, 0, ',', '.') ?></span>
    </div>
    <div class="bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card">
      <div class="flex items-center justify-between mb-md">
        <span class="text-[11px] font-bold uppercase tracking-widest text-outline">Kategori</span>
        <span class="material-symbols-outlined text-primary bg-primary/10 p-1.5 rounded-lg text-[18px]">label</span>
      </div>
      <span class="text-[28px] font-display font-bold text-on-surface"><?= count($kategoriSummary) ?></span>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter mb-lg">

    <!-- Breakdown per kategori -->
    <div class="lg:col-span-2 bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card p-lg">
      <h3 class="font-display text-[16px] font-bold text-on-surface mb-md">Distribusi Stok per Kategori</h3>
      <?php if (empty($kategoriSummary)): ?>
        <p class="text-[13px] text-outline py-md">Belum ada data kategori.</p>
      <?php else: ?>
        <div class="space-y-md">
          <?php
            $maxStok = max(array_column($kategoriSummary, 'total_stok')) ?: 1;
            foreach ($kategoriSummary as $k):
              $persen = round(((int)$k['total_stok'] / $maxStok) * 100);
          ?>
          <div>
            <div class="flex justify-between items-center mb-1.5">
              <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full <?= kategoriDot($k['kategori']) ?>"></span>
                <span class="text-[13px] font-bold text-on-surface"><?= htmlspecialchars($k['kategori']) ?></span>
                <span class="text-[12px] text-outline">(<?= (int)$k['jumlah_item'] ?> item)</span>
              </div>
              <span class="text-[13px] font-mono text-on-surface-variant"><?= number_format((int)$k['total_stok'], 0, ',', '.') ?></span>
            </div>
            <div class="w-full h-2 bg-surface-container rounded-full overflow-hidden">
              <div class="h-full <?= kategoriDot($k['kategori']) ?> rounded-full" style="width: <?= $persen ?>%"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Panel stok rendah -->
    <div class="bg-surface-container-lowest rounded-2xl border border-error/20 shadow-card p-lg">
      <h3 class="font-display text-[16px] font-bold text-error mb-md flex items-center gap-2">
        <span class="material-symbols-outlined text-[20px]">warning</span>
        Perlu Perhatian
      </h3>
      <?php if (empty($lowStockItems)): ?>
        <p class="text-[13px] text-outline py-md">Tidak ada item dengan stok rendah saat ini.</p>
      <?php else: ?>
        <div class="space-y-sm">
          <?php foreach ($lowStockItems as $li): ?>
            <div class="flex justify-between items-center py-2 border-b border-outline-variant/30 last:border-0">
              <div class="min-w-0">
                <p class="text-[13px] font-bold text-on-surface truncate"><?= htmlspecialchars($li['nama']) ?></p>
                <p class="text-[11px] text-outline">SKU: <?= htmlspecialchars($li['sku']) ?></p>
              </div>
              <span class="text-[13px] font-mono font-bold text-error shrink-0 ml-2"><?= htmlspecialchars((string)$li['stok']) ?> <?= htmlspecialchars($li['satuan']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter -->
  <form method="GET" class="no-print bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card p-lg mb-lg">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-md items-end">
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5" for="kategori">Kategori</label>
        <select id="kategori" name="kategori" class="form-select">
          <option value="">Semua Kategori</option>
          <?php foreach ($kategoriList as $k): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $kategoriFilter === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5" for="status">Status</label>
        <select id="status" name="status" class="form-select">
          <option value="">Semua Status</option>
          <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Tersedia</option>
          <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Stok Rendah</option>
        </select>
      </div>
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5" for="dari">Dari Tanggal</label>
        <input type="date" id="dari" name="dari" value="<?= htmlspecialchars($dariTanggal) ?>" class="form-input">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5" for="sampai">Sampai Tanggal</label>
        <input type="date" id="sampai" name="sampai" value="<?= htmlspecialchars($sampaiTanggal) ?>" class="form-input">
      </div>
      <div class="flex gap-sm">
        <button type="submit" class="flex-1 flex items-center justify-center gap-xs bg-primary text-white px-md py-2.5 rounded-xl font-bold text-[13px] hover:bg-primary-container transition-all">
          <span class="material-symbols-outlined text-[18px]">filter_alt</span>Terapkan
        </button>
        <a href="laporan.php" class="flex items-center justify-center px-md py-2.5 border border-outline-variant rounded-xl font-bold text-[13px] text-on-surface-variant hover:bg-surface-variant/40 transition-all">Reset</a>
      </div>
    </div>
  </form>

  <!-- Tabel laporan -->
  <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex flex-col sm:flex-row sm:items-center justify-between gap-sm">
      <h3 class="font-display text-[17px] font-bold text-on-surface">Detail Inventaris</h3>
      <p class="text-[12px] text-outline"><?= count($items) ?> item ditemukan</p>
    </div>

    <?php if (empty($items)): ?>
      <div class="py-16 px-lg text-center">
        <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">assessment</span>
        <p class="text-on-surface-variant font-medium">Tidak ada data untuk filter yang dipilih.</p>
        <p class="text-[13px] text-outline mt-1">Coba ubah kategori, status, atau rentang tanggal.</p>
      </div>
    <?php else: ?>

      <div class="desktop-table overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-surface-container text-outline text-[11px] border-b border-outline-variant/60">
              <th class="py-md px-lg font-bold uppercase tracking-wider">Nama Barang</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Kategori</th>
              <th class="py-md px-md font-bold uppercase tracking-wider text-center">Stok</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Status</th>
              <th class="py-md px-lg font-bold uppercase tracking-wider text-right">Tanggal Dicatat</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/30">
            <?php foreach ($items as $item): ?>
            <tr class="hover:bg-surface-container-low transition-colors">
              <td class="py-md px-lg">
                <div class="font-bold text-on-surface text-[14px]"><?= htmlspecialchars($item['nama']) ?></div>
                <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
              </td>
              <td class="py-md px-md"><?= kategoriBadgeLaporan($item['kategori']) ?></td>
              <td class="py-md px-md text-center font-mono text-[14px]"><?= htmlspecialchars((string)$item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span></td>
              <td class="py-md px-md"><?= statusBadgeLaporan($item['status']) ?></td>
              <td class="py-md px-lg text-right text-[13px] text-outline">
                <?= !empty($item['created_at']) ? htmlspecialchars(date('d M Y', strtotime($item['created_at']))) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="mobile-cards divide-y divide-outline-variant/40">
        <?php foreach ($items as $item): ?>
        <div class="p-lg flex flex-col gap-sm">
          <div class="flex justify-between items-start gap-md">
            <div class="min-w-0">
              <div class="font-bold text-on-surface text-[15px] truncate"><?= htmlspecialchars($item['nama']) ?></div>
              <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
            </div>
            <?= kategoriBadgeLaporan($item['kategori']) ?>
          </div>
          <div class="flex justify-between items-center mt-1">
            <div class="font-mono text-[14px] text-on-surface">
              <?= htmlspecialchars((string)$item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span>
            </div>
            <?= statusBadgeLaporan($item['status']) ?>
          </div>
          <div class="text-[12px] text-outline mt-1">
            Dicatat: <?= !empty($item['created_at']) ? htmlspecialchars(date('d M Y', strtotime($item['created_at']))) : '—' ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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