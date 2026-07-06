<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// === Auth Guard ===
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$fullName       = $_SESSION['full_name'] ?? 'Administrator';
$daftarKategori = ['Obat', 'Alkes', 'Habis Pakai'];

// =========================================================
// === Ambil & validasi filter dari query string
// =========================================================
$filterKategori = $_GET['kategori'] ?? 'semua';
$filterStatus   = $_GET['status'] ?? 'semua';

if (!in_array($filterKategori, $daftarKategori, true)) {
    $filterKategori = 'semua';
}
if (!in_array($filterStatus, ['available', 'low'], true)) {
    $filterStatus = 'semua';
}

$items          = [];
$totalStok      = 0;
$totalTersedia  = 0;
$totalRendah    = 0;

try {
    $pdo = getDbConnection();

    $where  = ['poli = "umum"'];
    $params = [];

    if ($filterKategori !== 'semua') {
        $where[] = 'kategori = :kategori';
        $params[':kategori'] = $filterKategori;
    }
    if ($filterStatus !== 'semua') {
        $where[] = 'status = :status';
        $params[':status'] = $filterStatus;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT nama, sku, kategori, stok, ambang_minimum, satuan, status, catatan
        FROM items
        WHERE $whereSql
        ORDER BY kategori ASC, nama ASC
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    foreach ($items as $it) {
        $totalStok += (int) $it['stok'];
        if ($it['status'] === 'low') {
            $totalRendah++;
        } else {
            $totalTersedia++;
        }
    }
} catch (Throwable $e) {
    // Jika koneksi/tabel gagal, laporan akan tampil kosong dengan total nol
}

// =========================================================
// === Susun label & kelompokkan item per kategori
// =========================================================
$generatedAt = date('d F Y, H:i') . ' WIB';

$filterLabelParts = [];
if ($filterKategori !== 'semua') $filterLabelParts[] = $filterKategori;
if ($filterStatus !== 'semua')   $filterLabelParts[] = $filterStatus === 'low' ? 'Stok Rendah' : 'Tersedia';
$filterLabel = $filterLabelParts ? implode(' · ', $filterLabelParts) : 'Semua Kategori & Status';

$grouped = [];
foreach ($items as $it) {
    $grouped[$it['kategori']][] = $it;
}

function statusLabelPdf(string $status): string {
    return $status === 'low' ? 'Stok Rendah' : 'Tersedia';
}

function statusColorPdf(string $status): string {
    return $status === 'low' ? '#ba1a1a' : '#0f5238';
}

// =========================================================
// === Bangun HTML laporan
// =========================================================
$logoPath = __DIR__ . '/assets/logo-uin.png';
$logoTag  = file_exists($logoPath) ? '<img src="' . $logoPath . '" class="logo">' : '';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page {
    margin: 100px 40px 70px 40px;
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica', Arial, sans-serif;
    color: #191c1c;
    font-size: 10.5px;
  }
  .header-bar {
    position: fixed;
    top: -85px;
    left: 0;
    right: 0;
    height: 70px;
    border-bottom: 2px solid #0f5238;
    padding-bottom: 8px;
  }
  .header-bar table { width: 100%; border-collapse: collapse; }
  .header-bar .logo { width: 36px; height: 36px; }
  .header-title { font-size: 16px; font-weight: bold; color: #0f5238; margin: 0 0 2px 0; }
  .header-sub { font-size: 10px; color: #707973; margin: 0; }
  .header-meta { text-align: right; font-size: 9.5px; color: #707973; }

  .footer-bar {
    position: fixed;
    bottom: -55px;
    left: 0;
    right: 0;
    height: 40px;
    border-top: 1px solid #bfc9c1;
    padding-top: 6px;
    font-size: 9px;
    color: #707973;
  }
  .footer-bar table { width: 100%; }

  .report-title {
    font-size: 14px;
    font-weight: bold;
    color: #191c1c;
    margin: 0 0 2px 0;
  }
  .report-sub {
    font-size: 10px;
    color: #707973;
    margin: 0 0 16px 0;
  }

  .summary-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
  }
  .summary-table td {
    width: 25%;
    background: #f2f4f3;
    border: 1px solid #e1e3e2;
    padding: 8px 10px;
    text-align: left;
  }
  .summary-label {
    font-size: 8.5px;
    color: #707973;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: block;
    margin-bottom: 2px;
  }
  .summary-value {
    font-size: 15px;
    font-weight: bold;
    color: #0f5238;
  }
  .summary-value.error { color: #ba1a1a; }

  .kategori-heading {
    font-size: 11.5px;
    font-weight: bold;
    color: #ffffff;
    background: #0f5238;
    padding: 5px 10px;
    margin: 14px 0 0 0;
  }

  table.items {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4px;
  }
  table.items th {
    background: #e6e9e8;
    color: #404943;
    font-size: 8.5px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    text-align: left;
    padding: 6px 8px;
    border-bottom: 1px solid #bfc9c1;
  }
  table.items td {
    padding: 6px 8px;
    border-bottom: 1px solid #e1e3e2;
    vertical-align: top;
  }
  table.items tr:nth-child(even) td {
    background: #f8faf9;
  }
  .col-center { text-align: center; }
  .nama-cell { font-weight: bold; }
  .sku-cell { color: #707973; font-size: 9px; }
  .status-pill {
    font-weight: bold;
    font-size: 9px;
  }
  .catatan-cell { color: #707973; font-size: 9px; font-style: italic; }

  .empty-state {
    text-align: center;
    color: #707973;
    padding: 40px 0;
    font-size: 11px;
  }
</style>
</head>
<body>

  <div class="header-bar">
    <table>
      <tr>
        <td style="width: 40px;"><?= $logoTag ?></td>
        <td>
          <p class="header-title">Vitalis Admin — Poli Umum</p>
          <p class="header-sub">Klinik Pratama UIN Sunan Gunung Djati Bandung</p>
        </td>
        <td class="header-meta">
          Dicetak oleh: <?= htmlspecialchars($fullName) ?><br>
          <?= htmlspecialchars($generatedAt) ?>
        </td>
      </tr>
    </table>
  </div>

  <div class="footer-bar">
    <table>
      <tr>
        <td>Laporan Inventaris — Vitalis Admin v2.6.0</td>
        <td style="text-align: right;">
          <script type="text/php">
            if (isset($pdf)) {
              $font = $fontMetrics->getFont("Helvetica");
              $pdf->page_text(520, 800, "Halaman {PAGE_NUM} dari {PAGE_COUNT}", $font, 9, array(0.44,0.47,0.45));
            }
          </script>
        </td>
      </tr>
    </table>
  </div>

  <p class="report-title">Laporan Inventaris Poli Umum</p>
  <p class="report-sub">Filter: <?= htmlspecialchars($filterLabel) ?></p>

  <table class="summary-table">
    <tr>
      <td>
        <span class="summary-label">Total Item</span>
        <span class="summary-value"><?= number_format(count($items), 0, ',', '.') ?></span>
      </td>
      <td>
        <span class="summary-label">Total Stok</span>
        <span class="summary-value"><?= number_format($totalStok, 0, ',', '.') ?></span>
      </td>
      <td>
        <span class="summary-label">Tersedia</span>
        <span class="summary-value"><?= number_format($totalTersedia, 0, ',', '.') ?></span>
      </td>
      <td>
        <span class="summary-label">Stok Rendah</span>
        <span class="summary-value error"><?= number_format($totalRendah, 0, ',', '.') ?></span>
      </td>
    </tr>
  </table>

  <?php if (empty($items)): ?>

    <p class="empty-state">Tidak ada barang yang cocok dengan filter ini.</p>

  <?php else: ?>
    <?php foreach ($grouped as $kategori => $groupItems): ?>

      <div class="kategori-heading"><?= htmlspecialchars($kategori) ?> — <?= count($groupItems) ?> item</div>
      <table class="items">
        <thead>
          <tr>
            <th style="width: 28%;">Nama Barang</th>
            <th style="width: 14%;">SKU</th>
            <th style="width: 10%;" class="col-center">Stok</th>
            <th style="width: 10%;" class="col-center">Ambang Min.</th>
            <th style="width: 14%;">Status</th>
            <th style="width: 24%;">Catatan</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groupItems as $item): ?>
          <tr>
            <td class="nama-cell"><?= htmlspecialchars($item['nama']) ?></td>
            <td class="sku-cell"><?= htmlspecialchars($item['sku']) ?></td>
            <td class="col-center"><?= (int) $item['stok'] ?> <?= htmlspecialchars($item['satuan']) ?></td>
            <td class="col-center"><?= (int) $item['ambang_minimum'] ?></td>
            <td class="status-pill" style="color: <?= statusColorPdf($item['status']) ?>;">
              <?= statusLabelPdf($item['status']) ?>
            </td>
            <td class="catatan-cell"><?= htmlspecialchars($item['catatan'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endforeach; ?>
  <?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

// =========================================================
// === Render ke PDF dengan Dompdf
// =========================================================
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', true); // diperlukan untuk nomor halaman di footer

$dompdf = new Dompdf($options);
$dompdf->setPaper('A4', 'portrait');
$dompdf->loadHtml($html);
$dompdf->render();

$filenameSuffix = $filterKategori !== 'semua' || $filterStatus !== 'semua' ? '-Filtered' : '';
$filename = 'Laporan-Inventaris-PoliUmum' . $filenameSuffix . '-' . date('Ymd-His') . '.pdf';

$dompdf->stream($filename, ['Attachment' => true]);
exit;