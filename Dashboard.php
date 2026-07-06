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

$totalStok       = 0;
$stokHampirHabis = 0;
$items           = [];
$kategoriSummary = [];
$daftarKategori  = ['Obat', 'Alkes', 'Habis Pakai'];

try {
    $pdo = getDbConnection();

    // =========================================================
    // === CRUD: proses aksi (Update Stok, Catat Pemakaian, Hapus)
    // =========================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $flashStatus = 'success';
        $flashMsg    = '';

        try {
            if ($action === 'update_item') {
                $id      = (int) ($_POST['id'] ?? 0);
                $stok    = max(0, (int) ($_POST['stok'] ?? 0));
                $ambang  = max(0, (int) ($_POST['ambang_minimum'] ?? 15));
                $catatan = trim((string) ($_POST['catatan'] ?? ''));
                $status  = $stok <= $ambang ? 'low' : 'available';

                $stmt = $pdo->prepare('UPDATE items SET stok = :stok, ambang_minimum = :ambang, status = :status, catatan = :catatan WHERE id = :id AND poli = "umum"');
                $stmt->execute([
                    ':stok'   => $stok,
                    ':ambang' => $ambang,
                    ':status' => $status,
                    ':catatan'=> $catatan !== '' ? $catatan : null,
                    ':id'     => $id,
                ]);
                $flashMsg = 'Stok barang berhasil diperbarui.';

            } elseif ($action === 'use_item') {
                $id     = (int) ($_POST['id'] ?? 0);
                $jumlah = max(0, (int) ($_POST['jumlah_pakai'] ?? 0));

                $stmt = $pdo->prepare('SELECT stok, ambang_minimum FROM items WHERE id = :id AND poli = "umum"');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();

                if ($row) {
                    if ($jumlah > (int) $row['stok']) {
                        $flashStatus = 'error';
                        $flashMsg = 'Jumlah pemakaian melebihi stok yang tersedia.';
                    } else {
                        $newStok   = max(0, (int) $row['stok'] - $jumlah);
                        $newStatus = $newStok <= (int) $row['ambang_minimum'] ? 'low' : 'available';
                        $stmt2 = $pdo->prepare('UPDATE items SET stok = :stok, status = :status WHERE id = :id');
                        $stmt2->execute([':stok' => $newStok, ':status' => $newStatus, ':id' => $id]);
                        $flashMsg = 'Pemakaian barang berhasil dicatat.';
                    }
                } else {
                    $flashStatus = 'error';
                    $flashMsg = 'Barang tidak ditemukan.';
                }

            } elseif ($action === 'delete_item') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM items WHERE id = :id AND poli = "umum"');
                $stmt->execute([':id' => $id]);
                $flashMsg = 'Barang berhasil dihapus.';
            }
        } catch (Throwable $e) {
            $flashStatus = 'error';
            $flashMsg = 'Gagal memproses permintaan. Silakan coba lagi.';
        }

        header('Location: Dashboard.php?' . http_build_query(['status' => $flashStatus, 'msg' => $flashMsg]));
        exit;
    }

    // =========================================================
    // === Ambil data ringkasan & inventaris
    // =========================================================
    $stmt = $pdo->query('SELECT COALESCE(SUM(stok), 0) AS total FROM items WHERE poli = "umum"');
    $row = $stmt->fetch();
    if ($row) $totalStok = (int) $row['total'];

    $stmt = $pdo->query('SELECT COUNT(*) AS jumlah FROM items WHERE poli = "umum" AND stok <= ambang_minimum');
    $row = $stmt->fetch();
    if ($row) $stokHampirHabis = (int) $row['jumlah'];

    $stmt = $pdo->query('SELECT id, nama, sku, kategori, stok, ambang_minimum, satuan, status, catatan FROM items WHERE poli = "umum" ORDER BY nama ASC');
    $result = $stmt->fetchAll();
    if ($result) $items = $result;

    // Ringkasan per kategori: total item, total stok, jumlah tersedia, jumlah rendah,
    // dan kuantitas stok yang berstatus tersedia (dipakai untuk grafik)
    $stmt = $pdo->query('
        SELECT
            kategori,
            COUNT(*) AS jumlah_item,
            COALESCE(SUM(stok), 0) AS total_stok,
            SUM(CASE WHEN stok <= ambang_minimum THEN 1 ELSE 0 END) AS jumlah_rendah,
            SUM(CASE WHEN stok > ambang_minimum THEN 1 ELSE 0 END) AS jumlah_tersedia,
            COALESCE(SUM(CASE WHEN stok > ambang_minimum THEN stok ELSE 0 END), 0) AS stok_tersedia
        FROM items
        WHERE poli = "umum"
        GROUP BY kategori
    ');
    foreach ($stmt->fetchAll() as $r) {
        $kategoriSummary[$r['kategori']] = $r;
    }

} catch (Throwable $e) {
    // Koneksi/tabel belum tersedia — tampilkan halaman kosong sebagai fallback
}

// Data grafik "Stok Tersedia per Kategori" (dipakai oleh Chart.js di bawah)
$chartLabels = $daftarKategori;
$chartStokTersedia = array_map(
    fn($kat) => (int) ($kategoriSummary[$kat]['stok_tersedia'] ?? 0),
    $daftarKategori
);
$totalStokTersedia = array_sum($chartStokTersedia);

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

function kategoriIcon(string $kategori): string {
    $map = [
        'Obat' => 'medication',
        'Alkes' => 'medical_services',
        'Habis Pakai' => 'inventory_2',
    ];
    return $map[$kategori] ?? 'category';
}

$flashStatus = $_GET['status'] ?? null;
$flashMsg    = $_GET['msg'] ?? null;
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Admin — Dashboard Poli Umum</title>
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
  #sidebar { transition: transform .25s ease, width .25s ease; }
  #main-content, #topbar, #footer-shell { transition: margin-left .25s ease, width .25s ease; }

  /* === Sidebar collapse (desktop) === */
  @media (min-width: 1024px) {
    body.sidebar-collapsed #sidebar { width: 84px !important; }
    body.sidebar-collapsed #sidebar .sidebar-label { display: none !important; }
    body.sidebar-collapsed #sidebar .logo-row { justify-content: center; }
    body.sidebar-collapsed #sidebar nav a { justify-content: center; }
    body.sidebar-collapsed #sidebar .mt-auto > div { justify-content: center; }
    body.sidebar-collapsed #main-content,
    body.sidebar-collapsed #topbar,
    body.sidebar-collapsed #footer-shell { margin-left: 84px !important; }
    body.sidebar-collapsed #collapse-chevron { transform: rotate(180deg); }
  }

  /* === Efek ripple saat menu navigasi diklik === */
  .ripple-container { position: relative; overflow: hidden; }
  .ripple {
    position: absolute; border-radius: 50%; transform: scale(0);
    background: rgba(255,255,255,.7); mix-blend-mode: overlay;
    animation: rippleEffect .55s ease-out forwards; pointer-events: none;
  }
  @keyframes rippleEffect { to { transform: scale(4.5); opacity: 0; } }
  .nav-link:active { transform: scale(.97); }
  .nav-link { transition: transform .15s ease, background-color .2s ease, color .2s ease; }

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

  /* === Animasi === */
  @keyframes fadeInUp { from { opacity: 0; transform: translateY(14px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
  @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes toastIn { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
  @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(24px); } }
  @keyframes shake { 10%,90%{transform:translateX(-1px)} 20%,80%{transform:translateX(2px)} 30%,50%,70%{transform:translateX(-4px)} 40%,60%{transform:translateX(4px)} }
  @keyframes floatBlob { 0%,100%{transform:translateY(0) translateX(0) scale(1)} 33%{transform:translateY(-24px) translateX(14px) scale(1.05)} 66%{transform:translateY(14px) translateX(-10px) scale(.97)} }
  @keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }
  @keyframes glowPulse { 0%,100%{box-shadow:0 0 0 0 rgba(15,82,56,.18)} 50%{box-shadow:0 0 0 8px rgba(15,82,56,0)} }
  @keyframes iconPop { 0%{transform:scale(.6) rotate(-8deg); opacity:0} 60%{transform:scale(1.12) rotate(3deg); opacity:1} 100%{transform:scale(1) rotate(0)} }
  @keyframes underlineGrow { from { transform: scaleX(0); } to { transform: scaleX(1); } }

  .animate-row { animation: fadeInUp .35s cubic-bezier(.22,1,.36,1) both; }
  .animate-card { animation: fadeInUp .5s cubic-bezier(.22,1,.36,1) both; }
  .icon-badge { animation: iconPop .55s cubic-bezier(.22,1,.36,1) both; }
  .kategori-row:active { transform: scale(0.995); }
  .status-chip { cursor: pointer; }
  .status-chip:active { transform: scale(0.95); }
  .status-chip { transition: all .2s ease; }

  .row-hidden { display: none !important; }
  .card-hidden { display: none !important; }

  .modal-overlay { transition: opacity .2s ease; }
  .modal-panel { transition: opacity .2s ease, transform .2s ease; }
  .modal-hidden .modal-overlay { opacity: 0; pointer-events: none; }
  .modal-hidden .modal-panel { opacity: 0; transform: scale(.95) translateY(8px); }
  .modal-hidden { pointer-events: none; }

  .toast-enter { animation: toastIn .25s ease both; }
  .toast-leave { animation: toastOut .25s ease both; }

  .shake { animation: shake .4s; }

  .count-up { transition: color .3s ease; }

  .stat-card { position: relative; overflow: hidden; transition: transform .3s cubic-bezier(.22,1,.36,1), box-shadow .3s ease; }
  .stat-card::after {
    content: ''; position: absolute; top: -30%; right: -15%; width: 90px; height: 90px; border-radius: 999px;
    background: radial-gradient(circle, rgba(15,82,56,.08), transparent 70%);
    opacity: 0; transition: opacity .35s ease;
  }
  .stat-card:hover::after { opacity: 1; }
  .stat-card:hover { transform: translateY(-3px); }

  .chart-card:hover { box-shadow: 0 4px 8px rgba(15,82,56,.06), 0 16px 32px -12px rgba(15,82,56,.16); }

  .section-title-bar { position: relative; display: inline-block; }
  .section-title-bar::after {
    content: ''; position: absolute; left: 0; bottom: -6px; height: 3px; width: 100%;
    background: linear-gradient(90deg, #0f5238, #95d4b3); border-radius: 999px;
    transform-origin: left; animation: underlineGrow .6s cubic-bezier(.22,1,.36,1) .1s both;
  }

  .bg-blob {
    position: fixed; border-radius: 999px; filter: blur(70px); pointer-events: none; z-index: 0;
    animation: floatBlob 16s ease-in-out infinite;
  }
  .bg-pattern-dots {
    background-image: radial-gradient(circle at 1.5px 1.5px, rgba(15,82,56,.05) 1.2px, transparent 0);
    background-size: 28px 28px;
  }
  .live-dot { position: relative; }
  .live-dot::after {
    content: ''; position: absolute; inset: -6px; border-radius: 999px;
    border: 2px solid rgba(15,82,56,.35); animation: glowPulse 2s ease-out infinite;
  }

  table tbody tr, #cards-body > div { transition: background-color .2s ease, transform .15s ease; }
</style>
</head>
<body class="bg-background text-on-surface overflow-x-hidden">

<!-- Dekorasi background: blob gradasi lembut + pola titik -->
<div class="bg-blob w-[420px] h-[420px] bg-primary/10 top-[-8%] right-[-6%]"></div>
<div class="bg-blob w-[380px] h-[380px] bg-secondary/10 bottom-[-10%] left-[10%]" style="animation-delay:-6s;"></div>
<div class="fixed inset-0 bg-pattern-dots pointer-events-none z-0"></div>

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
<div id="toast-container" class="fixed top-20 right-4 z-[70] flex flex-col gap-2 w-[90vw] max-w-sm"></div>

<!-- Sidebar Navigation -->
<aside id="sidebar" class="h-screen w-64 fixed left-0 top-0 flex flex-col py-lg px-md z-50" style="background:linear-gradient(180deg,#0f5238,#0b3e2c);">
  <button id="sidebar-collapse-btn" onclick="toggleSidebarCollapse()" title="Ciutkan/Perluas sidebar" class="hidden lg:flex absolute -right-3 top-9 w-6 h-6 rounded-full bg-white border border-outline-variant/60 shadow-card items-center justify-center text-primary hover:scale-110 active:scale-90 transition-transform z-10">
    <span class="material-symbols-outlined text-[16px] transition-transform duration-300" id="collapse-chevron">chevron_left</span>
  </button>

  <div class="logo-row flex items-center gap-md mb-xl px-xs">
    <div class="w-10 h-10 rounded-xl bg-white/10 border border-white/20 flex items-center justify-center shrink-0 overflow-hidden">
      <img src="assets/logo-uin.png" alt="Logo UIN Sunan Gunung Djati" class="w-full h-full object-contain p-1">
    </div>
    <div class="sidebar-label overflow-hidden whitespace-nowrap">
      <h1 class="font-display text-[18px] font-bold text-white leading-tight">ADMIN</h1>
      <p class="text-[12px] text-white/60">Poli Umum</p>
    </div>
    <button onclick="toggleSidebar()" class="sidebar-label lg:hidden ml-auto p-1.5 text-white/70 hover:text-white">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>

  <nav class="flex-1 space-y-1">
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-white shadow-card transition-all duration-200" href="Dashboard.php" title="Dashboard">
      <span class="material-symbols-outlined text-[20px] shrink-0">dashboard</span><span class="sidebar-label text-[15px] whitespace-nowrap">Dashboard</span>
    </a>
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-white/80 hover:bg-white/10 hover:text-white transition-all duration-200" href="input-barang.php" title="Input Barang">
      <span class="material-symbols-outlined text-[20px] shrink-0">inventory_2</span><span class="sidebar-label text-[15px] whitespace-nowrap">Input Barang</span>
    </a>
  </nav>

  <div class="mt-auto pt-md border-t border-white/15">
    <div class="flex items-center gap-md mt-md px-xs">
      <div class="w-9 h-9 rounded-full text-primary flex items-center justify-center font-bold shrink-0 shadow-sm bg-white ring-2 ring-white/30"><?= htmlspecialchars($initial) ?></div>
      <div class="sidebar-label overflow-hidden flex-1 whitespace-nowrap">
        <p class="text-[13px] font-bold truncate text-white"><?= htmlspecialchars($fullName) ?></p>
        <p class="text-[10px] text-white/60 uppercase tracking-wider"><?= htmlspecialchars(str_replace('_', ' ', $role)) ?></p>
      </div>
      <a href="logout.php" title="Logout" class="sidebar-label ripple-container relative overflow-hidden p-1.5 text-white/70 hover:text-white rounded-lg hover:bg-white/10 transition-colors shrink-0">
        <span class="material-symbols-outlined text-[20px]">logout</span>
      </a>
    </div>
  </div>
</aside>

<!-- Top Navigation -->
<header id="topbar" class="fixed top-0 right-0 left-0 h-16 bg-surface/85 backdrop-blur-md z-30 flex justify-between items-center gap-md px-margin-mobile sm:px-margin-desktop ml-64 border-b border-outline-variant/50 shadow-[0_1px_0_rgba(15,82,56,.04)]">
  <div class="flex items-center gap-md flex-1 min-w-0">
    <button onclick="toggleSidebar()" class="lg:hidden p-2 -ml-2 text-on-surface-variant hover:text-primary shrink-0">
      <span class="material-symbols-outlined">menu</span>
    </button>
    <div class="relative w-full max-w-md search-box">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-[20px]">search</span>
      <input id="search-input" class="w-full pl-10 pr-md py-2 bg-surface-container-low border-none rounded-full focus:ring-2 focus:ring-primary/25 text-[14px] placeholder:text-outline" placeholder="Cari obat atau alat kesehatan…" type="text">
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
<main id="main-content" class="ml-64 pt-24 pb-16 px-margin-mobile sm:px-margin-desktop min-h-screen relative z-10">
  <div class="mb-lg flex flex-col sm:flex-row sm:justify-between sm:items-end gap-md">
    <div>
      <h2 class="section-title-bar font-display text-[22px] sm:text-[26px] font-bold text-primary mb-2">Ringkasan Inventaris</h2>
      <p class="text-on-surface-variant text-[14px] sm:text-[15px] mt-2">Pantau ketersediaan logistik medis Poli Umum secara real-time.</p>
    </div>
    <a href="input-barang.php" class="flex items-center justify-center gap-sm bg-primary text-white px-lg py-3 rounded-xl font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all shadow-card w-full sm:w-auto">
      <span class="material-symbols-outlined text-[20px]">add</span>
      <span>Tambah Barang Baru</span>
    </a>
  </div>

  <!-- Grafik Stok Tersedia -->
  <div class="chart-card animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card transition-shadow relative overflow-hidden mb-8" style="animation-delay:.02s">
    <div class="absolute top-0 left-0 right-0 h-1" style="background:linear-gradient(90deg,#0f5238,#95d4b3,#0f5238); background-size:200% 100%; animation: shimmer 4s linear infinite;"></div>
    <div class="flex justify-between items-start mb-md relative">
      <div>
        <span class="text-[12px] font-bold uppercase tracking-widest text-outline">Jumlah Stok Tersedia <span id="stat-total-context" class="text-primary normal-case tracking-normal transition-opacity"></span></span>
        <div class="flex items-baseline gap-xs mt-1">
          <span id="stat-total-stok" class="text-[28px] font-display font-bold text-on-surface count-up" data-target="<?= (int) $totalStokTersedia ?>">0</span>
          <span class="text-[12px] text-outline">unit tersedia di semua kategori</span>
        </div>
      </div>
      <span class="icon-badge material-symbols-outlined text-primary bg-primary/10 p-2 rounded-xl text-[20px]">bar_chart</span>
    </div>
    <div class="relative h-56 mt-md">
      <canvas id="chartStokTersedia"
        data-labels='<?= json_encode($chartLabels) ?>'
        data-values='<?= json_encode($chartStokTersedia) ?>'></canvas>
    </div>
  </div>

  <!-- Stok per Kategori (tabel + grafik distribusi) -->
  <?php
    $totalRendahCount = 0;
    foreach ($items as $it) { if ($it['status'] === 'low') $totalRendahCount++; }
    $totalTersediaCount = count($items) - $totalRendahCount;
    $totalItemCount = count($items);
  ?>
  <div class="mb-8 bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex flex-col sm:flex-row sm:items-center justify-between gap-1">
      <h3 class="font-display text-[15px] font-bold text-on-surface">Stok per Kategori</h3>
      <span class="text-[12px] text-outline">Klik baris untuk memfilter daftar di bawah</span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-surface-container text-outline text-[11px] border-b border-outline-variant/60">
            <th class="py-md px-lg font-bold uppercase tracking-wider">Kategori</th>
            <th class="py-md px-md font-bold uppercase tracking-wider text-center">Item</th>
            <th class="py-md px-md font-bold uppercase tracking-wider text-center">Total Stok</th>
            <th class="py-md px-md font-bold uppercase tracking-wider">Distribusi</th>
            <th class="py-md px-md font-bold uppercase tracking-wider text-center">Tersedia</th>
            <th class="py-md px-lg font-bold uppercase tracking-wider text-center">Rendah</th>
          </tr>
        </thead>
        <tbody id="kategori-filter">
          <?php
            $pctTersediaAll = $totalItemCount > 0 ? round($totalTersediaCount / $totalItemCount * 100) : 0;
            $pctRendahAll   = $totalItemCount > 0 ? 100 - $pctTersediaAll : 0;
          ?>
          <tr data-kategori="semua" onclick="filterKategori('semua', this)"
              class="kategori-row active cursor-pointer transition-all duration-200 border-l-4 border-l-primary bg-primary/5 border-b border-outline-variant/30 animate-row" style="animation-delay:.03s">
            <td class="py-md px-lg">
              <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[20px]">apps</span>
                <span class="font-display font-bold text-[14px] text-on-surface">Semua Kategori</span>
              </div>
            </td>
            <td class="py-md px-md text-center font-mono text-[13px]"><?= $totalItemCount ?></td>
            <td class="py-md px-md text-center font-mono text-[13px]"><?= number_format($totalStok, 0, ',', '.') ?></td>
            <td class="py-md px-md">
              <div class="w-full max-w-[140px] h-2.5 rounded-full overflow-hidden bg-surface-variant flex">
                <div class="h-full bg-primary transition-all duration-700" style="width:<?= $pctTersediaAll ?>%"></div>
                <div class="h-full bg-error transition-all duration-700" style="width:<?= $pctRendahAll ?>%"></div>
              </div>
            </td>
            <td class="py-md px-md text-center text-[13px] font-bold text-primary"><?= $totalTersediaCount ?></td>
            <td class="py-md px-lg text-center text-[13px] font-bold text-error"><?= $totalRendahCount ?></td>
          </tr>
          <?php foreach ($daftarKategori as $i => $kat):
              $s = $kategoriSummary[$kat] ?? ['jumlah_item' => 0, 'total_stok' => 0, 'jumlah_rendah' => 0, 'jumlah_tersedia' => 0];
              $jml = (int) $s['jumlah_item'];
              $pctTersedia = $jml > 0 ? round(((int) $s['jumlah_tersedia']) / $jml * 100) : 0;
              $pctRendah   = $jml > 0 ? 100 - $pctTersedia : 0;
          ?>
          <tr data-kategori="<?= htmlspecialchars($kat) ?>" onclick="filterKategori('<?= htmlspecialchars($kat) ?>', this)"
              class="kategori-row cursor-pointer transition-all duration-200 border-l-4 border-l-transparent hover:bg-surface-container-low border-b border-outline-variant/30 animate-row" style="animation-delay:<?= .08 + $i * .05 ?>s">
            <td class="py-md px-lg">
              <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[20px]"><?= kategoriIcon($kat) ?></span>
                <span class="font-display font-bold text-[14px] text-on-surface"><?= htmlspecialchars($kat) ?></span>
              </div>
            </td>
            <td class="py-md px-md text-center font-mono text-[13px]"><?= $jml ?></td>
            <td class="py-md px-md text-center font-mono text-[13px]"><?= number_format((int) $s['total_stok'], 0, ',', '.') ?></td>
            <td class="py-md px-md">
              <div class="w-full max-w-[140px] h-2.5 rounded-full overflow-hidden bg-surface-variant flex">
                <div class="h-full bg-primary transition-all duration-700" style="width:<?= $pctTersedia ?>%"></div>
                <div class="h-full bg-error transition-all duration-700" style="width:<?= $pctRendah ?>%"></div>
              </div>
            </td>
            <td class="py-md px-md text-center text-[13px] font-bold text-primary"><?= (int) $s['jumlah_tersedia'] ?></td>
            <td class="py-md px-lg text-center text-[13px] font-bold text-error"><?= (int) $s['jumlah_rendah'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="flex gap-2 p-md border-t border-outline-variant/60 bg-surface-container-low" id="status-filter">
      <button type="button" data-status="semua" onclick="filterStatus('semua', this)" class="status-chip active px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-primary text-primary bg-primary/5 shadow-[0_2px_8px_rgba(15,82,56,.15)]">Semua Status</button>
      <button type="button" data-status="available" onclick="filterStatus('available', this)" class="status-chip px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-outline-variant/60 text-on-surface-variant hover:border-primary/40 transition-all">Tersedia</button>
      <button type="button" data-status="low" onclick="filterStatus('low', this)" class="status-chip px-3 py-1.5 rounded-full text-[12px] font-bold border-2 border-outline-variant/60 text-on-surface-variant hover:border-primary/40 transition-all">Stok Rendah</button>
    </div>
  </div>

  <!-- Inventaris -->
  <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex flex-col md:flex-row md:items-center justify-between gap-md">
      <h3 class="font-display text-[17px] font-bold text-on-surface">Daftar Inventaris Poli Umum</h3>
      <div class="flex flex-wrap items-center gap-sm">
        <button type="button" onclick="downloadLaporan()" class="flex items-center gap-xs px-md py-2 border border-outline-variant rounded-xl text-[13px] text-primary hover:bg-primary/5 transition-colors font-bold">
          <span class="material-symbols-outlined text-[18px]">download</span><span class="hidden sm:inline">Download List</span>
        </button>
        <span id="filter-summary" class="text-[12px] text-outline font-medium"></span>
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
          <tbody id="table-body" class="divide-y divide-outline-variant/30">
            <?php foreach ($items as $i => $item): ?>
            <tr class="hover:bg-primary/[.03] hover:shadow-[inset_3px_0_0_#0f5238] transition-all group animate-row"
                style="animation-delay:<?= min($i * 0.03, 0.4) ?>s"
                data-kategori="<?= htmlspecialchars($item['kategori']) ?>"
                data-status="<?= htmlspecialchars($item['status']) ?>"
                data-nama="<?= htmlspecialchars(mb_strtolower($item['nama'])) ?>"
                data-stok="<?= (int) $item['stok'] ?>">
              <td class="py-md px-lg">
                <div class="font-bold text-on-surface text-[14px]"><?= htmlspecialchars($item['nama']) ?></div>
                <div class="text-[12px] text-outline">SKU: <?= htmlspecialchars($item['sku']) ?></div>
              </td>
              <td class="py-md px-md"><?= kategoriBadge($item['kategori']) ?></td>
              <td class="py-md px-md text-center font-mono text-[14px]"><?= htmlspecialchars((string) $item['stok']) ?> <span class="text-outline text-[12px]"><?= htmlspecialchars($item['satuan']) ?></span></td>
              <td class="py-md px-md"><?= statusBadge($item['status']) ?></td>
              <td class="py-md px-lg text-right">
                <div class="flex justify-end gap-xs opacity-0 group-hover:opacity-100 transition-opacity">
                  <button type="button" class="p-2 text-primary hover:bg-primary/10 rounded-lg transition-transform hover:scale-110" title="Update Stok"
                    onclick='openEditModal(<?= (int) $item["id"] ?>, <?= json_encode($item["nama"]) ?>, <?= (int) $item["stok"] ?>, <?= (int) $item["ambang_minimum"] ?>, <?= json_encode($item["satuan"]) ?>, <?= json_encode($item["catatan"] ?? "") ?>)'>
                    <span class="material-symbols-outlined text-[20px]">edit_note</span>
                  </button>
                  <button type="button" class="p-2 text-secondary hover:bg-secondary/10 rounded-lg transition-transform hover:scale-110" title="Catat Penggunaan"
                    onclick='openUseModal(<?= (int) $item["id"] ?>, <?= json_encode($item["nama"]) ?>, <?= (int) $item["stok"] ?>, <?= json_encode($item["satuan"]) ?>)'>
                    <span class="material-symbols-outlined text-[20px]">output</span>
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

      <!-- Tampilan kartu: mobile / layar sempit -->
      <div id="cards-body" class="mobile-cards divide-y divide-outline-variant/40">
        <?php foreach ($items as $i => $item): ?>
        <div class="p-lg flex flex-col gap-sm animate-row"
             style="animation-delay:<?= min($i * 0.03, 0.4) ?>s"
             data-kategori="<?= htmlspecialchars($item['kategori']) ?>"
             data-status="<?= htmlspecialchars($item['status']) ?>"
             data-nama="<?= htmlspecialchars(mb_strtolower($item['nama'])) ?>"
             data-stok="<?= (int) $item['stok'] ?>">
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
            <?= statusBadge($item['status'], 'card') ?>
          </div>
          <div class="flex gap-sm mt-2">
            <button type="button" class="flex-1 flex items-center justify-center gap-xs py-2 border border-outline-variant rounded-lg text-[13px] font-bold text-primary active:scale-95 transition-transform"
              onclick='openEditModal(<?= (int) $item["id"] ?>, <?= json_encode($item["nama"]) ?>, <?= (int) $item["stok"] ?>, <?= (int) $item["ambang_minimum"] ?>, <?= json_encode($item["satuan"]) ?>, <?= json_encode($item["catatan"] ?? "") ?>)'>
              <span class="material-symbols-outlined text-[18px]">edit_note</span>Update
            </button>
            <button type="button" class="flex-1 flex items-center justify-center gap-xs py-2 border border-outline-variant rounded-lg text-[13px] font-bold text-secondary active:scale-95 transition-transform"
              onclick='openUseModal(<?= (int) $item["id"] ?>, <?= json_encode($item["nama"]) ?>, <?= (int) $item["stok"] ?>, <?= json_encode($item["satuan"]) ?>)'>
              <span class="material-symbols-outlined text-[18px]">output</span>Pakai
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
        <p class="text-on-surface-variant font-medium">Tidak ada barang yang cocok dengan filter ini.</p>
      </div>

      <div class="p-md bg-surface-container-low flex flex-col sm:flex-row gap-sm justify-between items-center">
        <p class="text-[12px] text-outline">Menampilkan <span id="visible-count"><?= count($items) ?></span> dari <?= count($items) ?> item</p>
        <div class="flex gap-xs">
          <button class="w-8 h-8 flex items-center justify-center border border-outline-variant rounded-lg hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-[18px]">chevron_left</span></button>
          <button class="w-8 h-8 flex items-center justify-center border border-outline-variant rounded-lg bg-primary text-white font-bold text-[13px]">1</button>
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
    <span class="text-[12px] text-outline">v2.7.0</span>
  </div>
</footer>

<!-- ============================================================= -->
<!-- Modal: Update Stok -->
<!-- ============================================================= -->
<div id="modal-edit" class="modal-hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
  <div class="modal-overlay absolute inset-0 bg-black/40" onclick="closeModal('modal-edit')"></div>
  <form method="POST" action="Dashboard.php" class="modal-panel relative bg-surface-container-lowest rounded-2xl shadow-card-hover w-full max-w-sm p-lg">
    <input type="hidden" name="action" value="update_item">
    <input type="hidden" name="id" id="edit-id">
    <div class="flex items-center justify-between mb-md">
      <h3 class="font-display font-bold text-[17px] text-on-surface">Update Stok Barang</h3>
      <button type="button" onclick="closeModal('modal-edit')" class="p-1 text-outline hover:text-error rounded-lg"><span class="material-symbols-outlined">close</span></button>
    </div>
    <p id="edit-nama" class="text-[14px] font-bold text-primary mb-md"></p>
    <div class="space-y-md">
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Jumlah Stok (<span id="edit-satuan"></span>)</label>
        <input type="number" name="stok" id="edit-stok" min="0" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Ambang Batas Minimum</label>
        <input type="number" name="ambang_minimum" id="edit-ambang" min="0" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Catatan (opsional)</label>
        <textarea name="catatan" id="edit-catatan" rows="2" class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all resize-none"></textarea>
      </div>
    </div>
    <div class="flex gap-sm mt-lg">
      <button type="button" onclick="closeModal('modal-edit')" class="flex-1 py-2.5 rounded-xl border border-outline-variant font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-colors">Batal</button>
      <button type="submit" class="flex-1 py-2.5 rounded-xl bg-primary text-white font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all">Simpan</button>
    </div>
  </form>
</div>

<!-- ============================================================= -->
<!-- Modal: Catat Penggunaan -->
<!-- ============================================================= -->
<div id="modal-use" class="modal-hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
  <div class="modal-overlay absolute inset-0 bg-black/40" onclick="closeModal('modal-use')"></div>
  <form method="POST" action="Dashboard.php" class="modal-panel relative bg-surface-container-lowest rounded-2xl shadow-card-hover w-full max-w-sm p-lg">
    <input type="hidden" name="action" value="use_item">
    <input type="hidden" name="id" id="use-id">
    <div class="flex items-center justify-between mb-md">
      <h3 class="font-display font-bold text-[17px] text-on-surface">Catat Penggunaan</h3>
      <button type="button" onclick="closeModal('modal-use')" class="p-1 text-outline hover:text-error rounded-lg"><span class="material-symbols-outlined">close</span></button>
    </div>
    <p id="use-nama" class="text-[14px] font-bold text-secondary mb-1"></p>
    <p class="text-[12px] text-outline mb-md">Stok saat ini: <span id="use-stok" class="font-bold"></span> <span id="use-satuan"></span></p>
    <div>
      <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1">Jumlah Dipakai</label>
      <input type="number" name="jumlah_pakai" id="use-jumlah" min="1" required class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-secondary/25 focus:border-secondary outline-none transition-all">
    </div>
    <div class="flex gap-sm mt-lg">
      <button type="button" onclick="closeModal('modal-use')" class="flex-1 py-2.5 rounded-xl border border-outline-variant font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-colors">Batal</button>
      <button type="submit" class="flex-1 py-2.5 rounded-xl bg-secondary text-white font-bold text-[14px] hover:opacity-90 active:scale-[0.98] transition-all">Catat</button>
    </div>
  </form>
</div>

<!-- ============================================================= -->
<!-- Modal: Hapus Barang -->
<!-- ============================================================= -->
<div id="modal-delete" class="modal-hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
  <div class="modal-overlay absolute inset-0 bg-black/40" onclick="closeModal('modal-delete')"></div>
  <form method="POST" action="Dashboard.php" class="modal-panel relative bg-surface-container-lowest rounded-2xl shadow-card-hover w-full max-w-sm p-lg text-center">
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

  // === Collapse/expand sidebar (khusus desktop), tersimpan di localStorage kalau tersedia ===
  function toggleSidebarCollapse() {
    document.body.classList.toggle('sidebar-collapsed');
    try {
      localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    } catch (e) { /* localStorage tidak tersedia, abaikan */ }
  }
  (function restoreSidebarState() {
    try {
      if (localStorage.getItem('sidebarCollapsed') === '1') {
        document.body.classList.add('sidebar-collapsed');
      }
    } catch (e) { /* abaikan */ }
  })();

  // === Efek ripple saat item navigasi di sidebar diklik ===
  document.querySelectorAll('.ripple-container').forEach(el => {
    el.addEventListener('click', function (e) {
      const rect = this.getBoundingClientRect();
      const ripple = document.createElement('span');
      const size = Math.max(rect.width, rect.height);
      ripple.className = 'ripple';
      ripple.style.width = ripple.style.height = size + 'px';
      ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
      ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
      this.appendChild(ripple);
      setTimeout(() => ripple.remove(), 550);
    });
  });

  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    searchInput.addEventListener('focus', () => searchInput.parentElement.classList.add('ring-2', 'ring-primary/20'));
    searchInput.addEventListener('blur', () => searchInput.parentElement.classList.remove('ring-2', 'ring-primary/20'));
    searchInput.addEventListener('input', () => applyFilters());
  }

  // === Grafik Stok Tersedia per Kategori ===
  const chartCanvas = document.getElementById('chartStokTersedia');
  const chartLabels = chartCanvas ? JSON.parse(chartCanvas.dataset.labels || '[]') : [];
  const chartValues = chartCanvas ? JSON.parse(chartCanvas.dataset.values || '[]') : [];
  let stokChart = null;

  if (chartCanvas && window.Chart) {
    stokChart = new Chart(chartCanvas, {
      type: 'bar',
      data: {
        labels: chartLabels,
        datasets: [{
          label: 'Stok Tersedia',
          data: chartValues,
          backgroundColor: '#2d6a4f',
          hoverBackgroundColor: '#0f5238',
          borderRadius: 8,
          maxBarThickness: 64,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 500, easing: 'easeOutCubic' },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0f5238',
            padding: 10,
            titleFont: { family: 'Lexend', weight: '600' },
            bodyFont: { family: 'Inter' },
            callbacks: {
              label: (ctx) => `${ctx.formattedValue} unit tersedia`
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { family: 'Inter', weight: '600' }, color: '#404943' }
          },
          y: {
            beginAtZero: true,
            grid: { color: '#e1e3e2' },
            ticks: { font: { family: 'Inter' }, color: '#707973', precision: 0 }
          }
        }
      }
    });
  }

  // === State filter ===
  let currentKategori = 'semua';
  let currentStatus = 'semua';

  function filterKategori(kategori, row) {
    currentKategori = kategori;
    document.querySelectorAll('.kategori-row').forEach(el => {
      el.classList.remove('active', 'border-l-primary', 'bg-primary/5');
      el.classList.add('border-l-transparent');
    });
    row.classList.add('active', 'border-l-primary', 'bg-primary/5');
    row.classList.remove('border-l-transparent');
    applyFilters();
  }

  function filterStatus(status, btn) {
    currentStatus = status;
    document.querySelectorAll('#status-filter .status-chip').forEach(el => {
      el.classList.remove('active', 'border-primary', 'text-primary', 'bg-primary/5');
      el.classList.add('border-outline-variant/60', 'text-on-surface-variant');
      el.style.boxShadow = '';
    });
    btn.classList.add('active', 'border-primary', 'text-primary', 'bg-primary/5');
    btn.classList.remove('border-outline-variant/60', 'text-on-surface-variant');
    btn.style.boxShadow = '0 2px 8px rgba(15,82,56,.15)';
    applyFilters();
  }

  function applyFilters() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#table-body tr, #cards-body > div');
    let visible = 0;

    rows.forEach(row => {
      const matchKategori = currentKategori === 'semua' || row.dataset.kategori === currentKategori;
      const matchStatus = currentStatus === 'semua' || row.dataset.status === currentStatus;
      const matchSearch = !query || (row.dataset.nama || '').includes(query);
      const show = matchKategori && matchStatus && matchSearch;
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
      if (currentKategori !== 'semua') parts.push(currentKategori);
      if (currentStatus !== 'semua') parts.push(currentStatus === 'low' ? 'Stok Rendah' : 'Tersedia');
      summaryEl.textContent = parts.length ? `Filter: ${parts.join(' · ')}` : '';
    }

    updateSummaryCards();
  }

  // === Kartu "Jumlah Stok Tersedia" & grafiknya ikut menyesuaikan filter ===
  function updateSummaryCards() {
    // Hanya hitung dari baris tabel (bukan kartu mobile) supaya tidak terhitung dobel,
    // karena keduanya me-render item yang sama.
    const rows = document.querySelectorAll('#table-body tr');
    let totalTersedia = 0;
    const perKategori = {};
    chartLabels.forEach(k => perKategori[k] = 0);

    rows.forEach(row => {
      if (row.classList.contains('row-hidden')) return;
      if (row.dataset.status === 'available') {
        const stok = parseInt(row.dataset.stok || '0', 10);
        totalTersedia += stok;
        if (perKategori.hasOwnProperty(row.dataset.kategori)) {
          perKategori[row.dataset.kategori] += stok;
        }
      }
    });

    animateStatChange('stat-total-stok', totalTersedia, 0);

    const contextLabel = currentKategori === 'semua' ? '' : `· ${currentKategori}`;
    const totalContextEl = document.getElementById('stat-total-context');
    if (totalContextEl) totalContextEl.textContent = contextLabel;

    if (stokChart) {
      stokChart.data.datasets[0].data = chartLabels.map(k => perKategori[k]);
      stokChart.update();
    }
  }

  function animateStatChange(id, target, pad) {
    const el = document.getElementById(id);
    if (!el) return;
    const start = parseInt((el.textContent || '0').replace(/\D/g, ''), 10) || 0;
    if (start === target) {
      el.textContent = pad ? String(target).padStart(pad, '0') : target.toLocaleString('id-ID');
      return;
    }
    const duration = 450;
    const startTime = performance.now();
    function tick(now) {
      const progress = Math.min((now - startTime) / duration, 1);
      const value = Math.round(start + (target - start) * (1 - Math.pow(1 - progress, 3)));
      el.textContent = pad ? String(value).padStart(pad, '0') : value.toLocaleString('id-ID');
      if (progress < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  // === Download laporan PDF (ikut filter kategori & status yang sedang aktif) ===
  function downloadLaporan() {
    const params = new URLSearchParams({
      kategori: currentKategori,
      status: currentStatus,
    });
    window.open('laporan-pdf.php?' + params.toString(), '_blank');
  }

  // === Modal helpers ===
  function openModal(id) { document.getElementById(id).classList.remove('modal-hidden'); }
  function closeModal(id) { document.getElementById(id).classList.add('modal-hidden'); }

  function openEditModal(id, nama, stok, ambang, satuan, catatan) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-nama').textContent = nama;
    document.getElementById('edit-stok').value = stok;
    document.getElementById('edit-ambang').value = ambang;
    document.getElementById('edit-satuan').textContent = satuan;
    document.getElementById('edit-catatan').value = catatan || '';
    openModal('modal-edit');
  }

  function openUseModal(id, nama, stok, satuan) {
    document.getElementById('use-id').value = id;
    document.getElementById('use-nama').textContent = nama;
    document.getElementById('use-stok').textContent = stok;
    document.getElementById('use-satuan').textContent = satuan;
    document.getElementById('use-jumlah').max = stok;
    document.getElementById('use-jumlah').value = '';
    openModal('modal-use');
  }

  function openDeleteModal(id, nama) {
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-nama').textContent = nama;
    openModal('modal-delete');
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      ['modal-edit', 'modal-use', 'modal-delete'].forEach(closeModal);
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

  // === Angka berjalan (count-up) untuk kartu ringkasan ===
  function animateCount(el) {
    const target = parseInt(el.dataset.target || '0', 10);
    const pad = parseInt(el.dataset.pad || '0', 10);
    const duration = 700;
    const start = performance.now();
    function tick(now) {
      const progress = Math.min((now - start) / duration, 1);
      const value = Math.round(target * (1 - Math.pow(1 - progress, 3)));
      el.textContent = pad ? String(value).padStart(pad, '0') : value.toLocaleString('id-ID');
      if (progress < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.count-up').forEach(animateCount);

    <?php if ($flashMsg): ?>
    showToast(<?= json_encode($flashStatus) ?>, <?= json_encode($flashMsg) ?>);
    if (window.history.replaceState) {
      window.history.replaceState({}, document.title, 'Dashboard.php');
    }
    <?php endif; ?>
  });
</script>
</body>
</html>