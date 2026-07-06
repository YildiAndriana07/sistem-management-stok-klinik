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

$daftarKategori = ['Obat', 'Alkes', 'Habis Pakai'];
$daftarSatuan   = ['pcs', 'box', 'strip', 'botol', 'ampul', 'tablet', 'kapsul', 'ml', 'kotak', 'pack'];

$errors = [];
$old = [
    'nama'           => '',
    'sku'            => '',
    'kategori'       => '',
    'stok'           => '',
    'ambang_minimum' => '15',
    'satuan'         => '',
    'catatan'        => '',
];

try {
    $pdo = getDbConnection();

    // =========================================================
    // === Proses submit form tambah barang
    // =========================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_item') {
        $old['nama']           = trim((string) ($_POST['nama'] ?? ''));
        $old['sku']            = trim((string) ($_POST['sku'] ?? ''));
        $old['kategori']       = trim((string) ($_POST['kategori'] ?? ''));
        $old['stok']           = (string) ($_POST['stok'] ?? '');
        $old['ambang_minimum'] = (string) ($_POST['ambang_minimum'] ?? '15');
        $old['satuan']         = trim((string) ($_POST['satuan'] ?? ''));
        $old['catatan']        = trim((string) ($_POST['catatan'] ?? ''));

        // --- Validasi ---
        if ($old['nama'] === '') {
            $errors['nama'] = 'Nama barang wajib diisi.';
        }
        if ($old['sku'] === '') {
            $errors['sku'] = 'SKU wajib diisi.';
        }
        if (!in_array($old['kategori'], $daftarKategori, true)) {
            $errors['kategori'] = 'Pilih kategori yang valid.';
        }
        if ($old['satuan'] === '') {
            $errors['satuan'] = 'Satuan wajib diisi.';
        }
        if ($old['stok'] === '' || !is_numeric($old['stok']) || (int) $old['stok'] < 0) {
            $errors['stok'] = 'Stok awal harus berupa angka 0 atau lebih.';
        }
        if ($old['ambang_minimum'] === '' || !is_numeric($old['ambang_minimum']) || (int) $old['ambang_minimum'] < 0) {
            $errors['ambang_minimum'] = 'Ambang minimum harus berupa angka 0 atau lebih.';
        }

        if (empty($errors)) {
            $stok    = max(0, (int) $old['stok']);
            $ambang  = max(0, (int) $old['ambang_minimum']);
            $status  = $stok <= $ambang ? 'low' : 'available';

            try {
                $stmt = $pdo->prepare('
                    INSERT INTO items (nama, sku, kategori, stok, ambang_minimum, satuan, status, catatan, poli)
                    VALUES (:nama, :sku, :kategori, :stok, :ambang, :satuan, :status, :catatan, "umum")
                ');
                $stmt->execute([
                    ':nama'    => $old['nama'],
                    ':sku'     => $old['sku'],
                    ':kategori'=> $old['kategori'],
                    ':stok'    => $stok,
                    ':ambang'  => $ambang,
                    ':satuan'  => $old['satuan'],
                    ':status'  => $status,
                    ':catatan' => $old['catatan'] !== '' ? $old['catatan'] : null,
                ]);

                header('Location: input-barang.php?' . http_build_query([
                    'status' => 'success',
                    'msg'    => "Barang \"{$old['nama']}\" berhasil ditambahkan.",
                ]));
                exit;

            } catch (PDOException $e) {
                // 23000 = integrity constraint violation (kemungkinan SKU duplikat)
                if ($e->getCode() === '23000') {
                    $errors['sku'] = 'SKU ini sudah digunakan oleh barang lain.';
                } else {
                    $errors['general'] = 'Gagal menyimpan barang. Silakan coba lagi.';
                }
            }
        }
    }

    // === Barang yang baru ditambahkan (untuk panel samping) ===
    $stmt = $pdo->query('SELECT nama, sku, kategori, stok, satuan, status FROM items WHERE poli = "umum" ORDER BY id DESC LIMIT 5');
    $recentItems = $stmt->fetchAll();

} catch (Throwable $e) {
    $recentItems = [];
}

function kategoriBadgeIB(string $kategori): string {
    $map = [
        'Obat' => 'bg-secondary-container text-on-secondary-container',
        'Alkes' => 'bg-tertiary-container/20 text-tertiary',
        'Habis Pakai' => 'bg-primary-fixed text-on-primary-fixed-variant',
    ];
    $cls = $map[$kategori] ?? 'bg-surface-variant text-on-surface-variant';
    return '<span class="px-2.5 py-1 ' . $cls . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($kategori) . '</span>';
}

function kategoriIconIB(string $kategori): string {
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
<title>Admin — Input Barang</title>
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

  ::-webkit-scrollbar { height: 6px; width: 6px; }
  ::-webkit-scrollbar-thumb { background: #bfc9c1; border-radius: 99px; }

  @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes toastIn { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
  @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(24px); } }
  @keyframes shake { 10%,90%{transform:translateX(-1px)} 20%,80%{transform:translateX(2px)} 30%,50%,70%{transform:translateX(-4px)} 40%,60%{transform:translateX(4px)} }

  .animate-card { animation: fadeInUp .4s ease both; }
  .animate-row { animation: fadeInUp .35s ease both; }
  .toast-enter { animation: toastIn .25s ease both; }
  .toast-leave { animation: toastOut .25s ease both; }
  .field-error { animation: shake .4s; }

  .status-preview-dot { transition: background-color .2s ease; }
</style>
</head>
<body class="bg-background text-on-surface overflow-x-hidden">

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
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-white/80 hover:bg-white/10 hover:text-white transition-all duration-200" href="Dashboard.php" title="Dashboard">
      <span class="material-symbols-outlined text-[20px] shrink-0">dashboard</span><span class="sidebar-label text-[15px] whitespace-nowrap">Dashboard</span>
    </a>
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-white shadow-card transition-all duration-200" href="input-barang.php" title="Input Barang">
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
      <input class="w-full pl-10 pr-md py-2 bg-surface-container-low border-none rounded-full focus:ring-2 focus:ring-primary/25 text-[14px] placeholder:text-outline" placeholder="Cari obat atau alat kesehatan…" type="text" disabled>
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
      <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-1">Input Barang Baru</h2>
      <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Tambahkan obat, alat kesehatan, atau barang habis pakai ke inventaris Poli Umum.</p>
    </div>
    <a href="Dashboard.php" class="flex items-center justify-center gap-sm border border-outline-variant text-on-surface-variant px-lg py-3 rounded-xl font-bold text-[14px] hover:bg-surface-variant/40 transition-all w-full sm:w-auto">
      <span class="material-symbols-outlined text-[20px]">arrow_back</span>
      <span>Kembali ke Dashboard</span>
    </a>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-lg items-start">

    <!-- Form Tambah Barang -->
    <div class="lg:col-span-2 animate-card bg-surface-container-lowest p-lg rounded-2xl border border-outline-variant/60 shadow-card">
      <?php if (!empty($errors['general'])): ?>
        <div class="mb-md flex items-center gap-sm bg-error-container text-on-error-container px-md py-3 rounded-xl text-[13px] font-bold">
          <span class="material-symbols-outlined text-[20px]">error</span><?= htmlspecialchars($errors['general']) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="input-barang.php" novalidate>
        <input type="hidden" name="action" value="create_item">

        <div class="mb-lg">
          <h3 class="font-display text-[15px] font-bold text-on-surface mb-1">Informasi Barang</h3>
          <p class="text-[12px] text-outline">Field bertanda <span class="text-error">*</span> wajib diisi.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-md mb-md">
          <div class="sm:col-span-2">
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">Nama Barang <span class="text-error">*</span></label>
            <input type="text" name="nama" value="<?= htmlspecialchars($old['nama']) ?>" placeholder="Contoh: Paracetamol 500mg"
              class="w-full px-md py-2.5 bg-surface border <?= isset($errors['nama']) ? 'border-error field-error' : 'border-outline-variant' ?> rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
            <?php if (isset($errors['nama'])): ?><p class="text-[12px] text-error mt-1"><?= htmlspecialchars($errors['nama']) ?></p><?php endif; ?>
          </div>

          <div>
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">SKU <span class="text-error">*</span></label>
            <input type="text" name="sku" value="<?= htmlspecialchars($old['sku']) ?>" placeholder="Contoh: OB-0142"
              class="w-full px-md py-2.5 bg-surface border <?= isset($errors['sku']) ? 'border-error field-error' : 'border-outline-variant' ?> rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all font-mono">
            <?php if (isset($errors['sku'])): ?><p class="text-[12px] text-error mt-1"><?= htmlspecialchars($errors['sku']) ?></p><?php endif; ?>
          </div>

          <div>
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">Kategori <span class="text-error">*</span></label>
            <select name="kategori" id="input-kategori"
              class="w-full px-md py-2.5 bg-surface border <?= isset($errors['kategori']) ? 'border-error field-error' : 'border-outline-variant' ?> rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
              <option value="" disabled <?= $old['kategori'] === '' ? 'selected' : '' ?>>Pilih kategori…</option>
              <?php foreach ($daftarKategori as $kat): ?>
                <option value="<?= htmlspecialchars($kat) ?>" <?= $old['kategori'] === $kat ? 'selected' : '' ?>><?= htmlspecialchars($kat) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['kategori'])): ?><p class="text-[12px] text-error mt-1"><?= htmlspecialchars($errors['kategori']) ?></p><?php endif; ?>
          </div>

          <div>
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">Stok Awal <span class="text-error">*</span></label>
            <input type="number" name="stok" id="input-stok" min="0" value="<?= htmlspecialchars($old['stok']) ?>" placeholder="0"
              class="w-full px-md py-2.5 bg-surface border <?= isset($errors['stok']) ? 'border-error field-error' : 'border-outline-variant' ?> rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
            <?php if (isset($errors['stok'])): ?><p class="text-[12px] text-error mt-1"><?= htmlspecialchars($errors['stok']) ?></p><?php endif; ?>
          </div>

          <div>
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">Satuan <span class="text-error">*</span></label>
            <input type="text" name="satuan" list="satuan-options" value="<?= htmlspecialchars($old['satuan']) ?>" placeholder="Contoh: strip, box, pcs"
              class="w-full px-md py-2.5 bg-surface border <?= isset($errors['satuan']) ? 'border-error field-error' : 'border-outline-variant' ?> rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
            <datalist id="satuan-options">
              <?php foreach ($daftarSatuan as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
            </datalist>
            <?php if (isset($errors['satuan'])): ?><p class="text-[12px] text-error mt-1"><?= htmlspecialchars($errors['satuan']) ?></p><?php endif; ?>
          </div>

          <div>
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">Ambang Batas Minimum <span class="text-error">*</span></label>
            <input type="number" name="ambang_minimum" id="input-ambang" min="0" value="<?= htmlspecialchars($old['ambang_minimum']) ?>" placeholder="15"
              class="w-full px-md py-2.5 bg-surface border <?= isset($errors['ambang_minimum']) ? 'border-error field-error' : 'border-outline-variant' ?> rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all">
            <?php if (isset($errors['ambang_minimum'])): ?><p class="text-[12px] text-error mt-1"><?= htmlspecialchars($errors['ambang_minimum']) ?></p><?php endif; ?>
            <p class="text-[11px] text-outline mt-1">Sistem akan menandai barang "Stok Rendah" jika stok ≤ nilai ini.</p>
          </div>

          <div class="sm:col-span-2 flex items-center gap-sm bg-surface-container-low rounded-xl px-md py-3">
            <span class="text-[12px] font-bold text-outline uppercase tracking-wide">Status Awal:</span>
            <span id="status-preview-dot" class="w-1.5 h-1.5 rounded-full bg-primary status-preview-dot"></span>
            <span id="status-preview-text" class="text-[13px] font-bold text-primary">Tersedia</span>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-[12px] font-bold text-outline uppercase tracking-wide mb-1.5">Catatan (opsional)</label>
            <textarea name="catatan" rows="3" placeholder="Contoh: disimpan di lemari pendingin, batch khusus, dll."
              class="w-full px-md py-2.5 bg-surface border border-outline-variant rounded-xl text-[14px] focus:ring-2 focus:ring-primary/25 focus:border-primary outline-none transition-all resize-none"><?= htmlspecialchars($old['catatan']) ?></textarea>
          </div>
        </div>

        <div class="flex gap-sm mt-lg">
          <a href="Dashboard.php" class="flex-1 sm:flex-none text-center py-2.5 px-lg rounded-xl border border-outline-variant font-bold text-[14px] text-on-surface-variant hover:bg-surface-variant/40 transition-colors">Batal</a>
          <button type="submit" class="flex-1 flex items-center justify-center gap-sm py-2.5 px-lg rounded-xl bg-primary text-white font-bold text-[14px] hover:bg-primary-container active:scale-[0.98] transition-all">
            <span class="material-symbols-outlined text-[20px]">save</span>Simpan Barang
          </button>
        </div>
      </form>
    </div>

    <!-- Panel: Barang yang baru ditambahkan -->
    <div class="animate-card bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden" style="animation-delay:.06s">
      <div class="p-lg border-b border-outline-variant/60">
        <h3 class="font-display text-[15px] font-bold text-on-surface">Baru Ditambahkan</h3>
        <p class="text-[12px] text-outline mt-0.5">5 barang terakhir masuk ke inventaris</p>
      </div>
      <?php if (empty($recentItems)): ?>
        <div class="p-lg text-center">
          <span class="material-symbols-outlined text-[32px] text-outline-variant mb-1">inventory_2</span>
          <p class="text-[13px] text-outline">Belum ada barang ditambahkan.</p>
        </div>
      <?php else: ?>
        <div class="divide-y divide-outline-variant/30">
          <?php foreach ($recentItems as $i => $ri): ?>
          <div class="p-md flex items-center gap-sm animate-row" style="animation-delay:<?= $i * 0.05 ?>s">
            <span class="material-symbols-outlined text-primary bg-primary/10 p-2 rounded-lg text-[18px] shrink-0"><?= kategoriIconIB($ri['kategori']) ?></span>
            <div class="min-w-0 flex-1">
              <p class="text-[13px] font-bold text-on-surface truncate"><?= htmlspecialchars($ri['nama']) ?></p>
              <div class="flex items-center gap-xs mt-0.5">
                <?= kategoriBadgeIB($ri['kategori']) ?>
                <span class="text-[11px] text-outline"><?= (int) $ri['stok'] ?> <?= htmlspecialchars($ri['satuan']) ?></span>
              </div>
            </div>
            <?php if ($ri['status'] === 'low'): ?>
              <span class="w-1.5 h-1.5 rounded-full bg-error shrink-0" title="Stok Rendah"></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</main>

<!-- Footer -->
<footer id="footer-shell" class="ml-64 bg-surface-container-lowest border-t border-outline-variant/60 py-md px-margin-mobile sm:px-margin-desktop flex flex-col sm:flex-row gap-sm justify-between items-center text-center sm:text-left">
  <div class="flex flex-col sm:flex-row items-center gap-xs sm:gap-md">
    <span class="text-[12px] font-bold text-primary">Dashboard Admin Poli</span>
    <span class="text-[12px] text-outline">© <?= date('Y') ?> Klinik Pratama UIN Bandung. Sistem berjalan normal.</span>
  </div>
  <div class="flex gap-lg">
    <span class="text-[12px] text-outline">v2.6.0</span>
  </div>
</footer>

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

  // === Preview status (Tersedia / Rendah) berdasarkan stok vs ambang ===
  const stokInput   = document.getElementById('input-stok');
  const ambangInput = document.getElementById('input-ambang');
  const dot  = document.getElementById('status-preview-dot');
  const text = document.getElementById('status-preview-text');

  function updateStatusPreview() {
    const stok   = parseInt(stokInput?.value || '0', 10) || 0;
    const ambang = parseInt(ambangInput?.value || '0', 10) || 0;
    const isLow  = stok <= ambang;
    dot.classList.toggle('bg-primary', !isLow);
    dot.classList.toggle('bg-error', isLow);
    text.classList.toggle('text-primary', !isLow);
    text.classList.toggle('text-error', isLow);
    text.textContent = isLow ? 'Stok Rendah' : 'Tersedia';
  }
  [stokInput, ambangInput].forEach(el => el && el.addEventListener('input', updateStatusPreview));
  updateStatusPreview();

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
      window.history.replaceState({}, document.title, 'input-barang.php');
    }
    <?php endif; ?>
  });
</script>
</body>
</html>