<?php
require_once __DIR__ . '/config.php';

// === Auth Guard ===
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// === Role Guard: hanya super_admin yang boleh akses halaman ini ===
$roleDashboardMap = [
    'admin_poli1' => 'Dashboard.php',
    'admin_poli2' => 'dashboard-poli-gigi.php',
    'admin_poli3' => 'dashboard-poli-kia.php',
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

$roleLabels = [
    'admin_poli1' => 'Poli Umum',
    'admin_poli2' => 'Poli Gigi',
];

$errors     = [];
$oldInput   = ['full_name' => '', 'username' => '', 'role' => 'admin_poli1'];
$hasilAdmin = null; // info sukses (sekali tampil via session flash)

// =========================================================
// Proses: TAMBAH ADMIN (username & password diisi manual)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tambah_admin') {
    $namaInput     = trim($_POST['full_name'] ?? '');
    $usernameInput = trim($_POST['username'] ?? '');
    $passwordInput = (string) ($_POST['password'] ?? '');
    $passwordUlang = (string) ($_POST['password_confirm'] ?? '');
    $roleInput     = trim($_POST['role'] ?? '');

    $oldInput = ['full_name' => $namaInput, 'username' => $usernameInput, 'role' => $roleInput];

    if ($namaInput === '' || mb_strlen($namaInput) < 3) {
        $errors[] = 'Nama lengkap minimal 3 karakter.';
    }
    if (!array_key_exists($roleInput, $roleLabels)) {
        $errors[] = 'Poli tujuan tidak valid.';
    }
    if ($usernameInput === '' || mb_strlen($usernameInput) < 4) {
        $errors[] = 'Username minimal 4 karakter.';
    } elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $usernameInput)) {
        $errors[] = 'Username hanya boleh berisi huruf, angka, titik, dan underscore (tanpa spasi).';
    }
    if ($passwordInput === '' || mb_strlen($passwordInput) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    } elseif ($passwordInput !== $passwordUlang) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }

    if (empty($errors)) {
        try {
            $pdo = getDbConnection();

            // Cek duplikat username -> ditolak, Super Admin harus ganti manual
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $checkStmt->execute([$usernameInput]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                $errors[] = 'Username "' . $usernameInput . '" sudah digunakan. Silakan pilih username lain.';
            } else {
                $passwordHash = password_hash($passwordInput, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password_hash, role, full_name, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$usernameInput, $passwordHash, $roleInput, $namaInput, $_SESSION['user_id']]);

                $_SESSION['admin_baru'] = [
                    'fullName' => $namaInput,
                    'role'     => $roleLabels[$roleInput],
                    'username' => $usernameInput,
                    'password' => $passwordInput,
                    'isReset'  => false,
                ];
                header('Location: tambah-admin.php?sukses=1');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Gagal menyimpan data admin baru. Silakan coba lagi.';
        }
    }
}

// =========================================================
// Proses: RESET PASSWORD (password baru diisi manual)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $targetId       = (int) ($_POST['user_id'] ?? 0);
    $passwordBaru   = (string) ($_POST['new_password'] ?? '');
    $passwordUlang2 = (string) ($_POST['new_password_confirm'] ?? '');

    $resetErrors = [];
    if ($passwordBaru === '' || mb_strlen($passwordBaru) < 8) {
        $resetErrors[] = 'Password baru minimal 8 karakter.';
    } elseif ($passwordBaru !== $passwordUlang2) {
        $resetErrors[] = 'Konfirmasi password baru tidak cocok.';
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT username, full_name, role FROM users WHERE id = ? AND role IN ('admin_poli1','admin_poli2')");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            $resetErrors[] = 'Akun admin tidak ditemukan.';
        }

        if (empty($resetErrors)) {
            $passwordHash = password_hash($passwordBaru, PASSWORD_BCRYPT);
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $update->execute([$passwordHash, $targetId]);

            $_SESSION['admin_baru'] = [
                'fullName' => $target['full_name'],
                'role'     => $roleLabels[$target['role']] ?? $target['role'],
                'username' => $target['username'],
                'password' => $passwordBaru,
                'isReset'  => true,
            ];
            header('Location: tambah-admin.php?sukses=1');
            exit;
        } else {
            $_SESSION['flash_error'] = implode(' ', $resetErrors);
            $_SESSION['reset_target_id'] = $targetId; // biar modal reset kebuka lagi
            header('Location: tambah-admin.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Gagal mereset password.';
        header('Location: tambah-admin.php');
        exit;
    }
}

// =========================================================
// Proses: HAPUS ADMIN
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_admin') {
    $targetId = (int) ($_POST['user_id'] ?? 0);
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role IN ('admin_poli1','admin_poli2')");
        $stmt->execute([$targetId]);
        $_SESSION['flash_info'] = 'Akun admin berhasil dihapus.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus akun admin.';
    }
    header('Location: tambah-admin.php');
    exit;
}

// === Ambil flash messages ===
if (isset($_GET['sukses']) && !empty($_SESSION['admin_baru'])) {
    $hasilAdmin = $_SESSION['admin_baru'];
    unset($_SESSION['admin_baru']);
}
$flashInfo  = $_SESSION['flash_info'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
$resetTargetId = $_SESSION['reset_target_id'] ?? null;
unset($_SESSION['flash_info'], $_SESSION['flash_error'], $_SESSION['reset_target_id']);

// === Ambil daftar admin poli yang sudah ada ===
$daftarAdmin = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.role, u.created_at, c.full_name AS dibuat_oleh
        FROM users u
        LEFT JOIN users c ON c.id = u.created_by
        WHERE u.role IN ('admin_poli1', 'admin_poli2')
        ORDER BY u.created_at DESC
    ");
    $daftarAdmin = $stmt->fetchAll();
} catch (Throwable $e) {
    // fallback: tabel belum siap, tampilkan daftar kosong
}

function poliBadgeAdmin(string $roleKey): string {
    $map = [
        'admin_poli1' => ['label' => 'Poli Umum', 'cls' => 'bg-primary/10 text-primary'],
        'admin_poli2' => ['label' => 'Poli Gigi', 'cls' => 'bg-secondary/10 text-secondary'],
    ];
    $m = $map[$roleKey] ?? ['label' => $roleKey, 'cls' => 'bg-surface-variant text-on-surface-variant'];
    return '<span class="px-2.5 py-1 ' . $m['cls'] . ' rounded-full text-[11px] font-bold uppercase tracking-wide whitespace-nowrap">' . htmlspecialchars($m['label']) . '</span>';
}
?>
<!DOCTYPE html>
<html class="light" lang="id">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Tambah Admin — Super Admin</title>
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
  #sidebar { transition: transform .25s ease, width .25s ease; }
  #main-content, #topbar, #footer-shell { transition: margin-left .25s ease, width .25s ease; }
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
  .ripple-container { position: relative; overflow: hidden; }
  .ripple {
    position: absolute; border-radius: 50%; transform: scale(0);
    background: rgba(255,255,255,.7); mix-blend-mode: overlay;
    animation: rippleEffect .55s ease-out forwards; pointer-events: none;
  }
  @keyframes rippleEffect { to { transform: scale(4.5); opacity: 0; } }
  .nav-link { transition: transform .15s ease, background-color .2s ease, color .2s ease; }
  .nav-link:active { transform: scale(.97); }
  @media (max-width: 1023px) {
    #sidebar { transform: translateX(-100%); width: 272px; }
    #sidebar.open { transform: translateX(0); }
    #topbar, #main-content, #footer-shell { margin-left: 0 !important; width: 100% !important; }
    #sidebar-overlay.show { display: block; }
  }
  #sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(10,20,15,.45); z-index: 40; backdrop-filter: blur(2px); }
  @keyframes fadeInUp { from { opacity: 0; transform: translateY(14px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
  .animate-row { animation: fadeInUp .35s cubic-bezier(.22,1,.36,1) both; }
  .animate-card { animation: fadeInUp .5s cubic-bezier(.22,1,.36,1) both; }
  ::-webkit-scrollbar { height: 6px; width: 6px; }
  ::-webkit-scrollbar-thumb { background: #bfc9c1; border-radius: 99px; }
  .modal-backdrop { background: rgba(10,20,15,.55); }
</style>
</head>
<body class="bg-background text-on-surface overflow-x-hidden">

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>

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
      <h1 class="font-display text-[18px] font-bold text-white leading-tight">SUPER ADMIN</h1>
      <p class="text-[12px] text-white/60">Seluruh Poli</p>
    </div>
    <button onclick="toggleSidebar()" class="sidebar-label lg:hidden ml-auto p-1.5 text-white/70 hover:text-white">
      <span class="material-symbols-outlined">close</span>
    </button>
  </div>

  <nav class="flex-1 space-y-1">
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-white/80 hover:bg-white/10 hover:text-white transition-all duration-200" href="dashboard-super-admin.php" title="Ringkasan Semua Poli">
      <span class="material-symbols-outlined text-[20px] shrink-0">space_dashboard</span><span class="sidebar-label text-[15px] whitespace-nowrap">Ringkasan Semua Poli</span>
    </a>
    <p class="sidebar-label px-md pt-md pb-1 text-[10px] font-bold uppercase tracking-widest text-white/50 whitespace-nowrap">Kelola per Poli</p>
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-white/80 hover:bg-white/10 hover:text-white transition-all duration-200" href="Dashboard.php" title="Poli Umum">
      <span class="material-symbols-outlined text-[20px] shrink-0">stethoscope</span><span class="sidebar-label text-[15px] whitespace-nowrap">Poli Umum</span>
    </a>
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-white/80 hover:bg-white/10 hover:text-white transition-all duration-200" href="dashboard-poli-gigi.php" title="Poli Gigi">
      <span class="material-symbols-outlined text-[20px] shrink-0">dentistry</span><span class="sidebar-label text-[15px] whitespace-nowrap">Poli Gigi</span>
    </a>
    <p class="sidebar-label px-md pt-md pb-1 text-[10px] font-bold uppercase tracking-widest text-white/50 whitespace-nowrap">Administrasi</p>
    <a class="nav-link ripple-container relative overflow-hidden flex items-center gap-md py-2.5 px-md rounded-xl text-primary font-bold bg-white shadow-card transition-all duration-200" href="tambah-admin.php" title="Tambah Admin">
      <span class="material-symbols-outlined text-[20px] shrink-0">person_add</span><span class="sidebar-label text-[15px] whitespace-nowrap">Tambah Admin</span>
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
    <h2 class="font-display font-bold text-[16px] text-on-surface">Tambah Admin Poli</h2>
  </div>
  <div class="flex items-center gap-md sm:gap-lg shrink-0">
    <div class="flex items-center gap-sm">
      <span class="text-[12px] text-primary font-bold hidden sm:inline">Sistem Aktif</span>
      <div class="w-2 h-2 rounded-full bg-primary pulse-dot"></div>
    </div>
  </div>
</header>

<!-- Main Content -->
<main id="main-content" class="ml-64 pt-24 pb-16 px-margin-mobile sm:px-margin-desktop min-h-screen relative z-10">

  <div class="mb-lg">
    <h2 class="font-display text-[22px] sm:text-[26px] font-bold text-primary mb-2">Tambah Admin Poli</h2>
    <p class="text-on-surface-variant text-[14px] sm:text-[15px]">Buat akun baru untuk Admin Poli Umum atau Poli Gigi. Username & password ditentukan langsung oleh Super Admin.</p>
  </div>

  <div class="grid lg:grid-cols-5 gap-lg items-start">

    <!-- Kolom kiri: Form / Hasil -->
    <div class="lg:col-span-2 bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card p-lg animate-card">

      <?php if ($hasilAdmin): ?>
        <!-- === Tampilan hasil sukses (sekali tampil) === -->
        <div class="flex items-center gap-2 text-primary mb-md">
          <span class="material-symbols-outlined">check_circle</span>
          <span class="font-bold text-[15px]"><?= !empty($hasilAdmin['isReset']) ? 'Password berhasil direset!' : 'Akun admin berhasil dibuat!' ?></span>
        </div>
        <p class="text-[12px] text-outline mb-md">Catat / salin kredensial ini sekarang. Password tidak akan ditampilkan lagi setelah halaman ini ditutup atau dimuat ulang.</p>
        <div class="bg-surface-container-low rounded-xl p-md space-y-2 text-[13px] mb-md">
          <div class="flex justify-between"><span class="text-outline">Nama</span><span class="font-bold"><?= htmlspecialchars($hasilAdmin['fullName']) ?></span></div>
          <div class="flex justify-between"><span class="text-outline">Poli</span><span class="font-bold"><?= htmlspecialchars($hasilAdmin['role']) ?></span></div>
          <div class="flex justify-between items-center"><span class="text-outline">Username</span><span id="hasil-username" class="font-mono font-bold"><?= htmlspecialchars($hasilAdmin['username']) ?></span></div>
          <div class="flex justify-between items-center"><span class="text-outline">Password</span><span id="hasil-password" class="font-mono font-bold"><?= htmlspecialchars($hasilAdmin['password']) ?></span></div>
        </div>
        <div class="flex gap-2">
          <button type="button" onclick="salinKredensial()" class="flex-1 py-2.5 border-2 border-primary text-primary rounded-xl font-bold text-[13px] hover:bg-primary/5 transition-colors">Salin</button>
          <a href="tambah-admin.php" class="flex-1 py-2.5 bg-primary text-on-primary rounded-xl font-bold text-[13px] text-center hover:bg-primary/90 transition-colors">Selesai</a>
        </div>

      <?php else: ?>
        <?php if ($flashInfo): ?>
          <div class="mb-md text-[13px] text-primary bg-primary/10 rounded-lg px-3 py-2.5 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">info</span><?= htmlspecialchars($flashInfo) ?>
          </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
          <div class="mb-md text-[13px] text-error bg-error-container/40 rounded-lg px-3 py-2.5 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span><?= htmlspecialchars($flashError) ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
          <div class="mb-md text-[13px] text-error bg-error-container/40 rounded-lg px-3 py-2.5">
            <?php foreach ($errors as $err): ?><div><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- === Form tambah admin (username & password manual) === -->
        <form method="POST" action="tambah-admin.php" class="space-y-md">
          <input type="hidden" name="action" value="tambah_admin">
          <div>
            <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Nama Lengkap</label>
            <input type="text" name="full_name" required minlength="3"
              value="<?= htmlspecialchars($oldInput['full_name']) ?>"
              class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary/25 focus:border-primary text-[14px]"
              placeholder="Contoh: Siti Nurhaliza">
          </div>
          <div>
            <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Ditempatkan di</label>
            <div class="grid grid-cols-2 gap-2">
              <label class="flex items-center gap-2 px-3.5 py-2.5 border-2 border-outline-variant/60 rounded-xl cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5 transition-all">
                <input type="radio" name="role" value="admin_poli1" <?= $oldInput['role'] !== 'admin_poli2' ? 'checked' : '' ?> class="accent-[#0f5238]">
                <span class="text-[13px] font-bold">Poli Umum</span>
              </label>
              <label class="flex items-center gap-2 px-3.5 py-2.5 border-2 border-outline-variant/60 rounded-xl cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5 transition-all">
                <input type="radio" name="role" value="admin_poli2" <?= $oldInput['role'] === 'admin_poli2' ? 'checked' : '' ?> class="accent-[#0f5238]">
                <span class="text-[13px] font-bold">Poli Gigi</span>
              </label>
            </div>
          </div>
          <div>
            <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Username</label>
            <input type="text" name="username" required minlength="4" pattern="[a-zA-Z0-9._]+"
              value="<?= htmlspecialchars($oldInput['username']) ?>"
              class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary/25 focus:border-primary text-[14px] font-mono"
              placeholder="Contoh: admin.gigi01">
            <p class="text-[11px] text-outline mt-1">Huruf, angka, titik, underscore. Minimal 4 karakter.</p>
          </div>
          <div>
            <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Password</label>
            <input type="password" name="password" required minlength="8"
              class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary/25 focus:border-primary text-[14px]"
              placeholder="Minimal 8 karakter">
          </div>
          <div>
            <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Konfirmasi Password</label>
            <input type="password" name="password_confirm" required minlength="8"
              class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary/25 focus:border-primary text-[14px]"
              placeholder="Ulangi password">
          </div>
          <button type="submit" class="w-full py-2.5 bg-primary text-on-primary rounded-xl font-bold text-[14px] hover:bg-primary/90 transition-colors">
            Buat Akun Admin
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Kolom kanan: Daftar admin yang sudah ada -->
    <div class="lg:col-span-3 bg-surface-container-lowest rounded-2xl border border-outline-variant/60 shadow-card overflow-hidden animate-card" style="animation-delay:.08s">
      <div class="p-lg border-b border-outline-variant/60 flex items-center justify-between">
        <h3 class="font-display text-[16px] font-bold text-on-surface">Daftar Admin Poli</h3>
        <span class="text-[12px] text-outline"><?= count($daftarAdmin) ?> admin terdaftar</span>
      </div>

      <?php if (empty($daftarAdmin)): ?>
        <div class="py-14 px-lg text-center">
          <span class="material-symbols-outlined text-[36px] text-outline-variant mb-2">group_off</span>
          <p class="text-on-surface-variant text-[13px] font-medium">Belum ada admin poli yang ditambahkan.</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-surface-container text-outline text-[11px] border-b border-outline-variant/60">
                <th class="py-md px-lg font-bold uppercase tracking-wider">Nama</th>
                <th class="py-md px-md font-bold uppercase tracking-wider">Username</th>
                <th class="py-md px-md font-bold uppercase tracking-wider">Poli</th>
                <th class="py-md px-md font-bold uppercase tracking-wider">Dibuat oleh</th>
                <th class="py-md px-md font-bold uppercase tracking-wider">Tanggal</th>
                <th class="py-md px-lg font-bold uppercase tracking-wider text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/30">
              <?php foreach ($daftarAdmin as $i => $admin): ?>
              <tr class="animate-row hover:bg-primary/[.03] transition-colors" style="animation-delay:<?= min($i * 0.03, 0.3) ?>s">
                <td class="py-md px-lg font-bold text-[14px]"><?= htmlspecialchars($admin['full_name']) ?></td>
                <td class="py-md px-md font-mono text-[13px] text-outline"><?= htmlspecialchars($admin['username']) ?></td>
                <td class="py-md px-md"><?= poliBadgeAdmin($admin['role']) ?></td>
                <td class="py-md px-md text-[13px] text-on-surface-variant"><?= htmlspecialchars($admin['dibuat_oleh'] ?? '—') ?></td>
                <td class="py-md px-lg text-[13px] text-outline"><?= date('d M Y', strtotime($admin['created_at'])) ?></td>
                <td class="py-md px-lg text-right">
                  <div class="flex justify-end gap-1">
                    <button type="button" title="Reset Password" onclick="openResetModal(<?= (int) $admin['id'] ?>, '<?= htmlspecialchars(addslashes($admin['full_name'])) ?>')"
                      class="p-2 text-outline hover:text-primary hover:bg-primary/10 rounded-lg transition-colors">
                      <span class="material-symbols-outlined text-[18px]">lock_reset</span>
                    </button>
                    <form method="POST" action="tambah-admin.php" onsubmit="return confirm('Hapus akun <?= htmlspecialchars(addslashes($admin['full_name'])) ?>? Tindakan ini tidak dapat dibatalkan.');">
                      <input type="hidden" name="action" value="hapus_admin">
                      <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                      <button type="submit" title="Hapus Admin" class="p-2 text-outline hover:text-error hover:bg-error-container/40 rounded-lg transition-colors">
                        <span class="material-symbols-outlined text-[18px]">delete</span>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- Footer -->
<footer id="footer-shell" class="ml-64 bg-surface-container-lowest border-t border-outline-variant/60 py-md px-margin-mobile sm:px-margin-desktop flex flex-col sm:flex-row gap-sm justify-between items-center text-center sm:text-left">
  <div class="flex flex-col sm:flex-row items-center gap-xs sm:gap-md">
    <span class="text-[12px] font-bold text-primary">Dashboard Super Admin</span>
    <span class="text-[12px] text-outline">© <?= date('Y') ?> Klinik Pratama UIN Bandung. Sistem berjalan normal.</span>
  </div>
  <div class="flex gap-lg">
    <span class="text-[12px] text-outline">v1.1.0</span>
  </div>
</footer>

<!-- ===== Modal: Reset Password (manual) ===== -->
<div id="modal-reset-password" class="fixed inset-0 z-[100] hidden items-center justify-center p-md modal-backdrop">
  <div class="bg-surface-container-lowest w-full max-w-sm rounded-2xl shadow-card-hover overflow-hidden">
    <div class="p-lg border-b border-outline-variant/60 flex items-center justify-between">
      <h3 class="font-display font-bold text-[16px] text-on-surface">Reset Password</h3>
      <button type="button" onclick="closeResetModal()" class="p-1.5 text-outline hover:text-error rounded-lg hover:bg-error-container/30">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <form method="POST" action="tambah-admin.php" class="p-lg space-y-md">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="reset-user-id" value="">
      <p class="text-[13px] text-on-surface-variant">Password baru untuk <span id="reset-admin-name" class="font-bold text-on-surface"></span>:</p>
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Password Baru</label>
        <input type="password" name="new_password" required minlength="8"
          class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary/25 focus:border-primary text-[14px]"
          placeholder="Minimal 8 karakter">
      </div>
      <div>
        <label class="block text-[12px] font-bold text-on-surface-variant mb-1.5">Konfirmasi Password Baru</label>
        <input type="password" name="new_password_confirm" required minlength="8"
          class="w-full px-3.5 py-2.5 bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary/25 focus:border-primary text-[14px]"
          placeholder="Ulangi password baru">
      </div>
      <button type="submit" class="w-full py-2.5 bg-primary text-on-primary rounded-xl font-bold text-[14px] hover:bg-primary/90 transition-colors">
        Simpan Password Baru
      </button>
    </form>
  </div>
</div>

<script>
  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
  }
  function toggleSidebarCollapse() {
    document.body.classList.toggle('sidebar-collapsed');
    try {
      localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    } catch (e) {}
  }
  (function restoreSidebarState() {
    try {
      if (localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
    } catch (e) {}
  })();
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
  function salinKredensial() {
    const teks = `Username: ${document.getElementById('hasil-username').textContent}\nPassword: ${document.getElementById('hasil-password').textContent}`;
    navigator.clipboard.writeText(teks).then(() => alert('Kredensial disalin ke clipboard.'));
  }
  function openResetModal(userId, namaAdmin) {
    document.getElementById('reset-user-id').value = userId;
    document.getElementById('reset-admin-name').textContent = namaAdmin;
    const modal = document.getElementById('modal-reset-password');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }
  function closeResetModal() {
    const modal = document.getElementById('modal-reset-password');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }
  <?php if ($resetTargetId): ?>
  openResetModal(<?= (int) $resetTargetId ?>, '');
  <?php endif; ?>
</script>
</body>
</html>