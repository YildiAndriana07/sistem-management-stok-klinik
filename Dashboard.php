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

// === Ambil data ringkasan & inventaris ===
$totalStok = 0;
$stokHampirHabis = 0;
$items = [];

try {
    $pdo = getDbConnection();

    $stmt = $pdo->query('SELECT COALESCE(SUM(stok), 0) AS total FROM items WHERE poli = "umum"');
    $row = $stmt->fetch();
    if ($row) $totalStok = (int) $row['total'];

    $stmt = $pdo->query('SELECT COUNT(*) AS jumlah FROM items WHERE poli = "umum" AND stok <= 15');
    $row = $stmt->fetch();
    if ($row) $stokHampirHabis = (int) $row['jumlah'];

    $stmt = $pdo->query('SELECT nama, sku, kategori, stok, satuan, status FROM items WHERE poli = "umum" ORDER BY nama ASC LIMIT 10');
    $result = $stmt->fetchAll();
    if ($result) $items = $result;

} catch (Throwable $e) {
    // Koneksi/tabel belum tersedia — tampilkan data contoh sebagai fallback
}

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
<title>Vitalis Admin — Dashboard Poli Umum</title>
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

  /* === Layout dasar: sidebar tetap di desktop, off-canvas di mobile/tablet === */
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

  /* Tabel ↔ kartu: tabel hanya tampil di layar lebar, kartu tampil di mobile */
  .desktop-table { display: none; }
  .mobile-cards { display: block; }
  @media (min-width: 768px) {
    .desktop-table { display: block; }
    .mobile-cards { display: none; }
  }

  ::-webkit-scrollbar { height: 6px; width: 6px; }
  ::-webkit-scrollbar-thumb { background: #bfc9c1; border-radius: 99px; }
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
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-primary/10 transition-all duration-200" href="Dashboard.php">
      <span class="material-symbols-outlined text-[20px]">dashboard</span><span class="text-[15px]">Dashboard</span>
    </a>
    <a class="flex items-center gap-md py-2.5 px-md rounded-xl text-on-surface-variant hover:bg-surface-variant/50 transition-colors" href="input-barang.php">
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
  <div class="mb-lg flex flex-col sm:flex-row sm:justify-between sm:items-end gap-md">
    <div>
      <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-1">Ringkasan Inventaris</h2>
      <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Pantau ketersediaan logistik medis Poli Umum secara real-time.</p>
    </div>
    <a href="input-barang.php" class="flex items-center justify-center gap-sm bg-primary text-white px-lg py-3 rounded-xl font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all shadow-card w-full sm:w-auto">
      <span class="material-symbols-outlined text-[20px]">add</span>
      <span>Tambah Barang Baru</span>
    </a>
  </div>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-gutter mb-12">
    <div class="bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card hover:shadow-card-hover transition-shadow relative overflow-hidden">
      <div class="absolute -top-6 -right-6 w-28 h-28 bg-primary/5 rounded-full"></div>
      <div class="flex justify-between items-start mb-md relative">
        <span class="text-[12px] font-bold uppercase tracking-widest text-outline">Total Stok Tersedia</span>
        <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">inventory</span>
      </div>
      <div class="flex items-baseline gap-xs relative">
        <span class="text-[34px] font-display font-bold text-on-surface"><?= number_format($totalStok, 0, ',', '.') ?></span>
        <span class="text-[12px] font-bold text-primary bg-primary/10 px-2 py-0.5 rounded-full">+12% bln ini</span>
      </div>
    </div>
    <div class="bg-surface-container-lowest p-lg rounded-2xl border border-error/20 shadow-card hover:shadow-card-hover transition-shadow relative overflow-hidden">
      <div class="absolute -top-6 -right-6 w-28 h-28 bg-error/5 rounded-full"></div>
      <div class="flex justify-between items-start mb-md relative">
        <span class="text-[12px] font-bold uppercase tracking-widest text-error">Stok Hampir Habis</span>
        <span class="material-symbols-outlined text-error bg-error-container p-2 rounded-xl text-[20px]">warning</span>
      </div>
      <div class="flex items-baseline gap-xs relative">
        <span class="text-[34px] font-display font-bold text-error"><?= str_pad((string)$stokHampirHabis, 2, '0', STR_PAD_LEFT) ?></span>
        <span class="text-[12px] text-outline">item butuh perhatian</span>
      </div>
    </div>
  </div>

  <!-- Inventaris -->
  <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex flex-col md:flex-row md:items-center justify-between gap-md">
      <h3 class="font-display text-[17px] font-bold text-on-surface">Daftar Inventaris Poli Umum</h3>
      <div class="flex flex-wrap items-center gap-sm">
        <button class="flex items-center gap-xs px-md py-2 border border-outline-variant rounded-xl text-[13px] text-primary hover:bg-primary/5 transition-colors font-bold">
          <span class="material-symbols-outlined text-[18px]">download</span><span class="hidden sm:inline">Download List</span>
        </button>
        <div class="relative">
          <select class="appearance-none pl-md pr-9 py-2 bg-surface border border-outline-variant rounded-xl text-[13px] font-medium focus:ring-primary focus:border-primary outline-none">
            <option>Semua Kategori</option>
            <option>Alkes</option>
            <option>Obat</option>
            <option>Habis Pakai</option>
          </select>
          <span class="material-symbols-outlined absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-outline text-[18px]">expand_more</span>
        </div>
      </div>
    </div>

    <?php if (empty($items)): ?>
      <div class="py-16 px-lg text-center">
        <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">inventory_2</span>
        <p class="text-on-surface-variant font-medium">Belum ada barang tercatat.</p>
        <p class="text-[13px] text-outline mt-1">Klik "Tambah Barang Baru" untuk mulai mencatat inventaris.</p>
      </div>
    <?php else: ?>

      <!-- Tampilan tabel: layar md ke atas -->
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
          <tbody class="divide-y divide-outline-variant/30">
            <?php foreach ($items as $item): ?>
            <tr class="hover:bg-surface-container-low transition-colors group">
              <td class="py-md px-lg">
                <div class="font-bold text-on-surface text-[14px]"><?= htmlspecialchars($item['nama']) ?></div>
                <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
              </td>
              <td class="py-md px-md"><?= kategoriBadge($item['kategori']) ?></td>
              <td class="py-md px-md text-center font-mono text-[14px]"><?= htmlspecialchars((string)$item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span></td>
              <td class="py-md px-md"><?= statusBadge($item['status']) ?></td>
              <td class="py-md px-lg text-right">
                <div class="flex justify-end gap-xs opacity-0 group-hover:opacity-100 transition-opacity">
                  <button class="p-2 text-primary hover:bg-primary/10 rounded-lg" title="Update Stok">
                    <span class="material-symbols-outlined text-[20px]">edit_note</span>
                  </button>
                  <button class="p-2 text-secondary hover:bg-secondary/10 rounded-lg" title="Catat Penggunaan">
                    <span class="material-symbols-outlined text-[20px]">output</span>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Tampilan kartu: mobile / layar sempit -->
      <div class="mobile-cards divide-y divide-outline-variant/40">
        <?php foreach ($items as $item): ?>
        <div class="p-lg flex flex-col gap-sm">
          <div class="flex justify-between items-start gap-md">
            <div class="min-w-0">
              <div class="font-bold text-on-surface text-[15px] truncate"><?= htmlspecialchars($item['nama']) ?></div>
              <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
            </div>
            <?= kategoriBadge($item['kategori']) ?>
          </div>
          <div class="flex justify-between items-center mt-1">
            <div class="font-mono text-[14px] text-on-surface">
              <?= htmlspecialchars((string)$item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span>
            </div>
            <?= statusBadge($item['status'], 'card') ?>
          </div>
          <div class="flex gap-sm mt-2">
            <button class="flex-1 flex items-center justify-center gap-xs py-2 border border-outline-variant rounded-lg text-[13px] font-bold text-primary">
              <span class="material-symbols-outlined text-[18px]">edit_note</span>Update
            </button>
            <button class="flex-1 flex items-center justify-center gap-xs py-2 border border-outline-variant rounded-lg text-[13px] font-bold text-secondary">
              <span class="material-symbols-outlined text-[18px]">output</span>Pakai
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="p-md bg-surface-container-low flex flex-col sm:flex-row gap-sm justify-between items-center">
        <p class="text-[12px] text-outline">Menampilkan 1–<?= count($items) ?> dari <?= count($items) ?> item</p>
        <div class="flex gap-xs">
          <button class="w-8 h-8 flex items-center justify-center border border-outline-variant rounded-lg hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-[18px]">chevron_left</span></button>
          <button class="w-8 h-8 flex items-center justify-center border border-outline-variant rounded-lg bg-primary text-white font-bold text-[13px]">1</button>
          <button class="w-8 h-8 flex items-center justify-center border border-outline-variant rounded-lg hover:bg-surface-container-highest transition-colors text-[13px]">2</button>
          <button class="w-8 h-8 flex items-center justify-center border border-outline-variant rounded-lg hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-[18px]">chevron_right</span></button>
        </div>
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

  const searchInput = document.querySelector('input[type="text"]');
  if (searchInput) {
    searchInput.addEventListener('focus', () => searchInput.parentElement.classList.add('ring-2', 'ring-primary/20'));
    searchInput.addEventListener('blur', () => searchInput.parentElement.classList.remove('ring-2', 'ring-primary/20'));
  }
</script>
</body>
</html>