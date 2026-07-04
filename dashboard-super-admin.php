<?php
require_once __DIR__ . '/config.php';

// === Auth Guard ===
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// === Role Guard: hanya super_admin yang boleh akses halaman ini ===
$roleDashboardMap = [
    'admin_poli1'  => 'Dashboard.php',
    'admin_poli2'  => 'dashboard-poli-gigi.php',
    'admin_poli3'  => 'dashboard-poli-kia.php',
];
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole !== 'super_admin') {
    $redirectTo = $roleDashboardMap[$currentRole] ?? 'login.php';
    header('Location: ' . $redirectTo);
    exit;
}

$fullName = $_SESSION['full_name'] ?? 'Administrator';
$role     = $_SESSION['role'] ?? 'Super Admin';
$initial  = strtoupper(substr($fullName, 0, 1));

// === Daftar poli yang dipantau di halaman ini ===
$poliInfo = [
    'umum' => ['label' => 'Poli Umum', 'icon' => 'stethoscope', 'dashboard' => 'Dashboard.php'],
    'gigi' => ['label' => 'Poli Gigi', 'icon' => 'dentistry',    'dashboard' => 'dashboard-poli-gigi.php'],
];

// Union kategori dari kedua poli (urutan ditentukan manual biar rapi di chart/legend)
$daftarKategori = ['Obat', 'Alkes', 'Alat Gigi', 'Bahan Tambal', 'Habis Pakai'];

$totalItemAll   = 0;
$totalStokAll   = 0;
$rendahAll      = 0;
$poliSummary    = []; // ['umum' => [...], 'gigi' => [...]]
$kategoriPerPoli = []; // ['umum' => ['Obat' => stok_tersedia, ...], 'gigi' => [...]]
$items          = [];

try {
    $pdo = getDbConnection();

    // --- Ringkasan total gabungan ---
    $stmt = $pdo->query("SELECT COALESCE(SUM(stok),0) AS total FROM items WHERE poli IN ('umum','gigi')");
    $row = $stmt->fetch();
    if ($row) $totalStokAll = (int) $row['total'];

    $stmt = $pdo->query("SELECT COUNT(*) AS jumlah FROM items WHERE poli IN ('umum','gigi')");
    $row = $stmt->fetch();
    if ($row) $totalItemAll = (int) $row['jumlah'];

    $stmt = $pdo->query("SELECT COUNT(*) AS jumlah FROM items WHERE poli IN ('umum','gigi') AND stok <= ambang_minimum");
    $row = $stmt->fetch();
    if ($row) $rendahAll = (int) $row['jumlah'];

    // --- Ringkasan per poli ---
    $stmt = $pdo->query("
        SELECT
            poli,
            COUNT(*) AS jumlah_item,
            COALESCE(SUM(stok),0) AS total_stok,
            SUM(CASE WHEN stok <= ambang_minimum THEN 1 ELSE 0 END) AS jumlah_rendah,
            SUM(CASE WHEN stok > ambang_minimum THEN 1 ELSE 0 END) AS jumlah_tersedia
        FROM items
        WHERE poli IN ('umum','gigi')
        GROUP BY poli
    ");
    foreach ($stmt->fetchAll() as $r) {
        $poliSummary[$r['poli']] = $r;
    }

    // --- Stok tersedia per kategori, dipecah per poli (untuk grafik perbandingan) ---
    $stmt = $pdo->query("
        SELECT
            poli,
            kategori,
            COALESCE(SUM(CASE WHEN stok > ambang_minimum THEN stok ELSE 0 END),0) AS stok_tersedia
        FROM items
        WHERE poli IN ('umum','gigi')
        GROUP BY poli, kategori
    ");
    foreach ($stmt->fetchAll() as $r) {
        $kategoriPerPoli[$r['poli']][$r['kategori']] = (int) $r['stok_tersedia'];
    }

    // --- Data gabungan untuk tabel ---
    $stmt = $pdo->query("
        SELECT id, poli, nama, sku, kategori, stok, ambang_minimum, satuan, status, catatan
        FROM items
        WHERE poli IN ('umum','gigi')
        ORDER BY poli ASC, nama ASC
    ");
    $items = $stmt->fetchAll();

} catch (Throwable $e) {
    // Koneksi/tabel belum tersedia — tampilkan halaman kosong sebagai fallback
}

// Pastikan key poli selalu ada walau datanya kosong
foreach (['umum', 'gigi'] as $p) {
    if (!isset($poliSummary[$p])) {
        $poliSummary[$p] = ['jumlah_item' => 0, 'total_stok' => 0, 'jumlah_rendah' => 0, 'jumlah_tersedia' => 0];
    }
    if (!isset($kategoriPerPoli[$p])) $kategoriPerPoli[$p] = [];
}

$chartLabels     = $daftarKategori;
$chartUmumValues = array_map(fn($k) => (int) ($kategoriPerPoli['umum'][$k] ?? 0), $daftarKategori);
$chartGigiValues = array_map(fn($k) => (int) ($kategoriPerPoli['gigi'][$k] ?? 0), $daftarKategori);

function statusBadge(string $status, string $context = 'table'): string {
    $isLow = $status === 'low';
    $color = $isLow ? 'error' : 'primary';
    $label = $isLow ? 'Stok Rendah' : 'Tersedia';
    $size  = $context === 'card' ? 'text-[11px] px-2.5 py-1' : 'text-label-sm';
    return '<div class="inline-flex items-center gap-1.5 ' . $size . ' font-bold rounded-full ' . ($context === 'card' ? "bg-{$color}/10 text-{$color}" : "text-{$color}") . '">'
         . '<span class="w-1.5 h-1.5 rounded-full bg-' . $color . ($isLow ? ' animate-pulse' : '') . '"></span>' . $label . '</div>';
}

function kategoriBadge(string $kategori): string {
    $map = [
        'Obat'         => 'bg-secondary-container text-on-secondary-container',
        'Alkes'        => 'bg-tertiary-container/20 text-tertiary',
        'Alat Gigi'    => 'bg-tertiary-container/20 text-tertiary',
        'Bahan Tambal' => 'bg-error-container/40 text-error',
        'Habis Pakai'  => 'bg-primary-fixed text-on-primary-fixed-variant',
    ];
    $cls = $map[$kategori] ?? 'bg-surface-variant text-on-surface-variant';
    return '<span class="px-2.5 py-1 ' . $cls . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($kategori) . '</span>';
}

function poliBadge(string $poli): string {
    $map = [
        'umum' => ['label' => 'Poli Umum', 'cls' => 'bg-primary/10 text-primary'],
        'gigi' => ['label' => 'Poli Gigi', 'cls' => 'bg-secondary/10 text-secondary'],
    ];
    $m = $map[$poli] ?? ['label' => $poli, 'cls' => 'bg-surface-variant text-on-surface-variant'];
    return '<span class="px-2.5 py-1 ' . $m['cls'] . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($m['label']) . '</span>';
}

function kategoriIcon(string $kategori): string {
    $map = [
        'Obat' => 'medication', 'Alkes' => 'medical_services', 'Alat Gigi' => 'dentistry',
        'Bahan Tambal' => 'science', 'Habis Pakai' => 'inventory_2',
    ];
    return $map[$kategori] ?? 'category';
}
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Super Admin — Ringkasan Seluruh Poli</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
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
        fontFamily: { display: ["Lexend", "sans-serif"], body: ["Inter", "sans-serif"] },
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

  @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes floatSlow { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-8px)} }
  @keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }

  .animate-row { animation: fadeInUp .35s ease both; }
  .animate-card { animation: fadeInUp .45s ease both; }
  .poli-card:hover .poli-icon { animation: floatSlow 1.6s ease-in-out infinite; }
  .status-chip, .poli-chip { cursor: pointer; }
  .status-chip:active, .poli-chip:active { transform: scale(0.95); }

  .row-hidden { display: none !important; }
  .card-hidden { display: none !important; }

  .count-up { transition: color .3s ease; }

  .ring-progress { transition: stroke-dashoffset 1s cubic-bezier(.4,0,.2,1); }

  .skeleton-shimmer {
    background: linear-gradient(90deg, rgba(191,201,193,.15) 25%, rgba(191,201,193,.35) 37%, rgba(191,201,193,.15) 63%);
    background-size: 400% 100%;
    animation: shimmer 1.4s ease infinite;
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
      <h1 class="font-display text-[18px] font-bold text-primary leading-tight">SUPER ADMIN</h1>
      <p class="text-[12px] text-outline">Seluruh Poli</p>
    </div>
    <button onclick="toggleSidebar()" class="lg:hidden ml-auto p-1.5 text-outline hover:text-primary">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>
  <nav class="flex-1 space-y-1">
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-primary/10 transition-all duration-200" href="dashboard-super-admin.php">
      <span class="material-symbols-outlined text-[20px]">space_dashboard</span><span class="text-[15px]">Ringkasan Semua Poli</span>
    </a>
    <p class="px-md pt-md pb-1 text-[10px] font-bold uppercase tracking-widest text-outline">Kelola per Poli</p>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="Dashboard.php">
      <span class="material-symbols-outlined text-[20px]">stethoscope</span><span class="text-[15px]">Poli Umum</span>
    </a>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="dashboard-poli-gigi.php">
      <span class="material-symbols-outlined text-[20px]">dentistry</span><span class="text-[15px]">Poli Gigi</span>
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
      <input id="search-input" class="w-full pl-10 pr-md py-2 bg-surface-container-low border-none rounded-full focus:ring-2 focus:ring-primary/25 text-[14px] placeholder:text-outline" placeholder="Cari barang di semua poli…" type="text">
    </div>
  </div>
  <div class="flex items-center gap-md sm:gap-lg shrink-0">
    <button class="relative p-2 text-on-surface-variant hover:text-primary transition-colors">
      <span class="material-symbols-outlined">notifications</span>
      <?php if ($rendahAll > 0): ?><span class="absolute top-1.5 right-1.5 w-2 h-2 bg-error rounded-full border-2 border-surface"></span><?php endif; ?>
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
  <div class="mb-lg">
    <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-1">Ringkasan Seluruh Poli</h2>
    <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Pantau inventaris Poli Umum & Poli Gigi dalam satu tampilan terpusat.</p>
  </div>

  <!-- Stat cards gabungan -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-md mb-8">
    <div class="animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-shadow" style="animation-delay:.02s">
      <div class="flex items-center justify-between mb-sm">
        <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">inventory_2</span>
      </div>
      <div class="text-[26px] font-display font-bold text-on-surface count-up" data-target="<?= (int) $totalItemAll ?>">0</div>
      <div class="text-[12px] text-outline font-medium mt-1">Total Item Terdaftar</div>
    </div>
    <div class="animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-shadow" style="animation-delay:.06s">
      <div class="flex items-center justify-between mb-sm">
        <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">bar_chart</span>
      </div>
      <div class="text-[26px] font-display font-bold text-on-surface count-up" data-target="<?= (int) $totalStokAll ?>">0</div>
      <div class="text-[12px] text-outline font-medium mt-1">Total Stok Keseluruhan</div>
    </div>
    <div class="animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-shadow" style="animation-delay:.10s">
      <div class="flex items-center justify-between mb-sm">
        <span class="material-symbols-outlined text-error bg-error-container/50 p-2 rounded-xl text-[20px]">warning</span>
      </div>
      <div class="text-[26px] font-display font-bold <?= $rendahAll > 0 ? 'text-error' : 'text-on-surface' ?> count-up" data-target="<?= (int) $rendahAll ?>">0</div>
      <div class="text-[12px] text-outline font-medium mt-1">Barang Stok Rendah</div>
    </div>
    <div class="animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-shadow" style="animation-delay:.14s">
      <div class="flex items-center justify-between mb-sm">
        <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">domain</span>
      </div>
      <div class="text-[26px] font-display font-bold text-on-surface count-up" data-target="2">0</div>
      <div class="text-[12px] text-outline font-medium mt-1">Poli Terpantau</div>
    </div>
  </div>

  <!-- Kartu ringkasan per poli -->
  <div class="grid md:grid-cols-2 gap-md mb-8">
    <?php foreach ($poliInfo as $key => $info):
        $s = $poliSummary[$key];
        $jml = (int) $s['jumlah_item'];
        $pctTersedia = $jml > 0 ? round(((int) $s['jumlah_tersedia']) / $jml * 100) : 0;
        $circumference = 2 * M_PI * 26;
        $offset = $circumference * (1 - $pctTersedia / 100);
    ?>
    <a href="<?= htmlspecialchars($info['dashboard']) ?>" class="poli-card group animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-all hover:-translate-y-0.5 flex items-center gap-lg" style="animation-delay:.18s">
      <div class="relative w-16 h-16 shrink-0">
        <svg viewBox="0 0 60 60" class="w-16 h-16 -rotate-90">
          <circle cx="30" cy="30" r="26" fill="none" stroke="#e1e3e2" stroke-width="6"></circle>
          <circle cx="30" cy="30" r="26" fill="none" stroke="#0f5238" stroke-width="6" stroke-linecap="round"
                  stroke-dasharray="<?= $circumference ?>" stroke-dashoffset="<?= $circumference ?>"
                  class="ring-progress" data-offset="<?= $offset ?>"></circle>
        </svg>
        <span class="poli-icon material-symbols-outlined absolute inset-0 flex items-center justify-center text-primary text-[24px]"><?= $info['icon'] ?></span>
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between">
          <h3 class="font-display font-bold text-[16px] text-on-surface"><?= htmlspecialchars($info['label']) ?></h3>
          <span class="material-symbols-outlined text-outline text-[18px] group-hover:translate-x-1 group-hover:text-primary transition-all">arrow_forward</span>
        </div>
        <div class="flex flex-wrap gap-x-lg gap-y-1 mt-2 text-[12px]">
          <span class="text-on-surface-variant"><b class="text-on-surface"><?= $jml ?></b> item</span>
          <span class="text-on-surface-variant"><b class="text-on-surface"><?= number_format((int) $s['total_stok'], 0, ',', '.') ?></b> unit stok</span>
          <span class="<?= (int) $s['jumlah_rendah'] > 0 ? 'text-error font-bold' : 'text-primary' ?>"><?= (int) $s['jumlah_rendah'] ?> stok rendah</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Grafik perbandingan stok tersedia per kategori antar poli -->
  <div class="animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-shadow relative overflow-hidden mb-8" style="animation-delay:.22s">
    <div class="flex justify-between items-start mb-md">
      <div>
        <span class="text-[12px] font-bold uppercase tracking-widest text-outline">Perbandingan Stok Tersedia per Kategori</span>
        <p class="text-[12px] text-outline mt-1">Poli Umum vs Poli Gigi, berdasarkan unit tersedia</p>
      </div>
      <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">stacked_bar_chart</span>
    </div>
    <div class="relative h-64 mt-md">
      <canvas id="chartPerbandingan"
        data-labels='<?= json_encode($chartLabels) ?>'
        data-umum='<?= json_encode($chartUmumValues) ?>'
        data-gigi='<?= json_encode($chartGigiValues) ?>'></canvas>
    </div>
  </div>

  <!-- Tabel gabungan inventaris -->
  <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex flex-col gap-md">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-md">
        <h3 class="font-display text-[17px] font-bold text-on-surface">Inventaris Gabungan Seluruh Poli</h3>
        <span id="filter-summary" class="text-[12px] text-outline font-medium"></span>
      </div>
      <div class="flex flex-wrap gap-2" id="poli-filter">
        <button type="button" data-poli="semua" onclick="filterPoli('semua', this)" class="poli-chip active px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-primary text-primary bg-primary/5 transition-all">Semua Poli</button>
        <button type="button" data-poli="umum" onclick="filterPoli('umum', this)" class="poli-chip px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-outline-variant/60 text-on-surface-variant hover:border-primary/40 transition-all">Poli Umum</button>
        <button type="button" data-poli="gigi" onclick="filterPoli('gigi', this)" class="poli-chip px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-outline-variant/60 text-on-surface-variant hover:border-primary/40 transition-all">Poli Gigi</button>
        <div class="w-[1px] bg-outline-variant/60 mx-1 hidden sm:block"></div>
        <button type="button" data-status="semua" onclick="filterStatus('semua', this)" class="status-chip active px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-primary text-primary bg-primary/5 transition-all">Semua Status</button>
        <button type="button" data-status="available" onclick="filterStatus('available', this)" class="status-chip px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-outline-variant/60 text-on-surface-variant hover:border-primary/40 transition-all">Tersedia</button>
        <button type="button" data-status="low" onclick="filterStatus('low', this)" class="status-chip px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-outline-variant/60 text-on-surface-variant hover:border-primary/40 transition-all">Stok Rendah</button>
      </div>
    </div>

    <?php if (empty($items)): ?>
      <div class="py-16 px-lg text-center">
        <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">inventory_2</span>
        <p class="text-on-surface-variant font-medium">Belum ada data barang dari kedua poli.</p>
      </div>
    <?php else: ?>

      <!-- Tabel: layar md ke atas -->
      <div class="desktop-table overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-surface-container text-outline text-[11px] border-b border-outline-variant/60">
              <th class="py-md px-lg font-bold uppercase tracking-wider">Nama Barang</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Poli</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Kategori</th>
              <th class="py-md px-md font-bold uppercase tracking-wider text-center">Stok</th>
              <th class="py-md px-md font-bold uppercase tracking-wider">Status</th>
              <th class="py-md px-lg font-bold uppercase tracking-wider text-right">Kelola</th>
            </tr>
          </thead>
          <tbody id="table-body" class="divide-y divide-outline-variant/30">
            <?php foreach ($items as $i => $item): ?>
            <tr class="hover:bg-surface-container-low transition-colors group animate-row"
                style="animation-delay:<?= min($i * 0.02, 0.4) ?>s"
                data-poli="<?= htmlspecialchars($item['poli']) ?>"
                data-kategori="<?= htmlspecialchars($item['kategori']) ?>"
                data-status="<?= htmlspecialchars($item['status']) ?>"
                data-nama="<?= htmlspecialchars(mb_strtolower($item['nama'])) ?>"
                data-stok="<?= (int) $item['stok'] ?>">
              <td class="py-md px-lg">
                <div class="font-bold text-on-surface text-[14px]"><?= htmlspecialchars($item['nama']) ?></div>
                <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
              </td>
              <td class="py-md px-md"><?= poliBadge($item['poli']) ?></td>
              <td class="py-md px-md"><?= kategoriBadge($item['kategori']) ?></td>
              <td class="py-md px-md text-center font-mono text-[14px]"><?= htmlspecialchars((string) $item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span></td>
              <td class="py-md px-md"><?= statusBadge($item['status']) ?></td>
              <td class="py-md px-lg text-right">
                <a href="<?= htmlspecialchars($poliInfo[$item['poli']]['dashboard'] ?? '#') ?>" title="Kelola di dashboard poli"
                   class="inline-flex items-center gap-1 p-2 text-primary hover:bg-primary/10 rounded-lg transition-transform hover:scale-110 opacity-0 group-hover:opacity-100">
                  <span class="material-symbols-outlined text-[20px]">open_in_new</span>
                </a>
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
             style="animation-delay:<?= min($i * 0.02, 0.4) ?>s"
             data-poli="<?= htmlspecialchars($item['poli']) ?>"
             data-kategori="<?= htmlspecialchars($item['kategori']) ?>"
             data-status="<?= htmlspecialchars($item['status']) ?>"
             data-nama="<?= htmlspecialchars(mb_strtolower($item['nama'])) ?>"
             data-stok="<?= (int) $item['stok'] ?>">
          <div class="flex justify-between items-start gap-md">
            <div class="min-w-0">
              <div class="font-bold text-on-surface text-[15px] truncate"><?= htmlspecialchars($item['nama']) ?></div>
              <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
            </div>
            <?= poliBadge($item['poli']) ?>
          </div>
          <div class="flex items-center gap-2"><?= kategoriBadge($item['kategori']) ?></div>
          <div class="flex justify-between items-center mt-1">
            <div class="font-mono text-[14px] text-on-surface">
              <?= htmlspecialchars((string) $item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span>
            </div>
            <?= statusBadge($item['status'], 'card') ?>
          </div>
          <a href="<?= htmlspecialchars($poliInfo[$item['poli']]['dashboard'] ?? '#') ?>" class="mt-2 flex items-center justify-center gap-xs py-2 border border-outline-variant rounded-lg text-[13px] font-bold text-primary active:scale-95 transition-transform">
            <span class="material-symbols-outlined text-[18px]">open_in_new</span>Kelola di Dashboard Poli
          </a>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="empty-filtered" class="hidden py-16 px-lg text-center">
        <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">search_off</span>
        <p class="text-on-surface-variant font-medium">Tidak ada barang yang cocok dengan filter ini.</p>
      </div>

      <div class="p-md bg-surface-container-low flex justify-between items-center">
        <p class="text-[12px] text-outline">Menampilkan <span id="visible-count"><?= count($items) ?></span> dari <?= count($items) ?> item</p>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Footer -->
<footer id="footer-shell" class="ml-64 bg-surface-container-lowest border-t border-outline-variant/60 py-md px-margin-mobile sm:px-margin-desktop flex flex-col sm:flex-row gap-sm justify-between items-center text-center sm:text-left">
  <div class="flex flex-col sm:flex-row items-center gap-xs sm:gap-md">
    <span class="text-[12px] font-bold text-primary">Dashboard Super Admin</span>
    <span class="text-[12px] text-outline">© <?= date('Y') ?> Klinik Pratama UIN Bandung. Sistem berjalan normal.</span>
  </div>
  <div class="flex gap-lg">
    <span class="text-[12px] text-outline">v1.0.0</span>
  </div>
</footer>

<script>
  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
  }

  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    searchInput.addEventListener('focus', () => searchInput.parentElement.classList.add('ring-2', 'ring-primary/20'));
    searchInput.addEventListener('blur', () => searchInput.parentElement.classList.remove('ring-2', 'ring-primary/20'));
    searchInput.addEventListener('input', () => applyFilters());
  }

  // === Ring progress animasi (kartu ringkasan per poli) ===
  window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.ring-progress').forEach(circle => {
      const offset = parseFloat(circle.dataset.offset);
      requestAnimationFrame(() => {
        setTimeout(() => { circle.style.strokeDashoffset = offset; }, 150);
      });
    });
    document.querySelectorAll('.count-up').forEach(animateCount);
  });

  // === Grafik perbandingan stok tersedia per kategori (Poli Umum vs Poli Gigi) ===
  const chartCanvas = document.getElementById('chartPerbandingan');
  if (chartCanvas && window.Chart) {
    const labels = JSON.parse(chartCanvas.dataset.labels || '[]');
    const umum = JSON.parse(chartCanvas.dataset.umum || '[]');
    const gigi = JSON.parse(chartCanvas.dataset.gigi || '[]');

    new Chart(chartCanvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Poli Umum', data: umum, backgroundColor: '#0f5238', hoverBackgroundColor: '#0e5138', borderRadius: 8, maxBarThickness: 36 },
          { label: 'Poli Gigi', data: gigi, backgroundColor: '#95d4b3', hoverBackgroundColor: '#7bc39f', borderRadius: 8, maxBarThickness: 36 },
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 600, easing: 'easeOutCubic' },
        plugins: {
          legend: {
            position: 'top', align: 'end',
            labels: { usePointStyle: true, pointStyle: 'circle', font: { family: 'Inter', weight: '600' }, color: '#404943' }
          },
          tooltip: {
            backgroundColor: '#0f5238', padding: 10,
            titleFont: { family: 'Lexend', weight: '600' }, bodyFont: { family: 'Inter' },
            callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.formattedValue} unit tersedia` }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { family: 'Inter', weight: '600' }, color: '#404943' } },
          y: { beginAtZero: true, grid: { color: '#e1e3e2' }, ticks: { font: { family: 'Inter' }, color: '#707973', precision: 0 } }
        }
      }
    });
  }

  // === State filter ===
  let currentPoli = 'semua';
  let currentStatus = 'semua';

  function filterPoli(poli, btn) {
    currentPoli = poli;
    document.querySelectorAll('#poli-filter .poli-chip').forEach(el => {
      el.classList.remove('active', 'border-primary', 'text-primary', 'bg-primary/5');
      el.classList.add('border-outline-variant/60', 'text-on-surface-variant');
    });
    btn.classList.add('active', 'border-primary', 'text-primary', 'bg-primary/5');
    btn.classList.remove('border-outline-variant/60', 'text-on-surface-variant');
    applyFilters();
  }

  function filterStatus(status, btn) {
    currentStatus = status;
    document.querySelectorAll('#poli-filter .status-chip').forEach(el => {
      el.classList.remove('active', 'border-primary', 'text-primary', 'bg-primary/5');
      el.classList.add('border-outline-variant/60', 'text-on-surface-variant');
    });
    btn.classList.add('active', 'border-primary', 'text-primary', 'bg-primary/5');
    btn.classList.remove('border-outline-variant/60', 'text-on-surface-variant');
    applyFilters();
  }

  function applyFilters() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#table-body tr, #cards-body > div');
    let visible = 0;

    rows.forEach(row => {
      const matchPoli = currentPoli === 'semua' || row.dataset.poli === currentPoli;
      const matchStatus = currentStatus === 'semua' || row.dataset.status === currentStatus;
      const matchSearch = !query || (row.dataset.nama || '').includes(query);
      const show = matchPoli && matchStatus && matchSearch;
      row.classList.toggle('row-hidden', !show);
      row.classList.toggle('card-hidden', !show);
      if (show) visible++;
    });

    const emptyState = document.getElementById('empty-filtered');
    if (emptyState) emptyState.classList.toggle('hidden', visible !== 0);

    const visibleCountEl = document.getElementById('visible-count');
    if (visibleCountEl) visibleCountEl.textContent = visible;

    const summaryEl = document.getElementById('filter-summary');
    if (summaryEl) {
      const parts = [];
      if (currentPoli !== 'semua') parts.push(currentPoli === 'umum' ? 'Poli Umum' : 'Poli Gigi');
      if (currentStatus !== 'semua') parts.push(currentStatus === 'low' ? 'Stok Rendah' : 'Tersedia');
      summaryEl.textContent = parts.length ? `Filter: ${parts.join(' · ')}` : '';
    }
  }

  // === Angka berjalan (count-up) untuk kartu ringkasan ===
  function animateCount(el) {
    const target = parseInt(el.dataset.target || '0', 10);
    const duration = 700;
    const start = performance.now();
    function tick(now) {
      const progress = Math.min((now - start) / duration, 1);
      const value = Math.round(target * (1 - Math.pow(1 - progress, 3)));
      el.textContent = value.toLocaleString('id-ID');
      if (progress < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
</script>
</body>
</html>