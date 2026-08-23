<?php
/**
 * download-qr.php
 * Generates a PDF of QR-code labels for one or more assets.
 *
 * GET params (one required):
 *   ?asset_no=KDP/COMP/...   -> single label
 *   ?batch_id=batch_xyz      -> all assets in that batch
 *   ?ids=1,2,3               -> specific asset IDs (comma-separated)
 */

session_start();
require 'db.php';
require_once 'vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$assets = [];

if (!empty($_GET['asset_no'])) {
    $asset_no = trim($_GET['asset_no']);
    $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM assets WHERE asset_no = ? LIMIT 1");
    $stmt->bind_param("s", $asset_no);
    $stmt->execute();
    $assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($assets)) {
        $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM borrowed_assets WHERE asset_no = ? LIMIT 1");
        $stmt->bind_param("s", $asset_no);
        $stmt->execute();
        $assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

} elseif (!empty($_GET['batch_id'])) {
    $batch_id = trim($_GET['batch_id']);
    $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM assets WHERE batch_id = ? AND asset_no IS NOT NULL AND asset_no != '' ORDER BY id ASC");
    $stmt->bind_param("s", $batch_id);
    $stmt->execute();
    $assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} elseif (!empty($_GET['ids'])) {
    $ids_raw = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    $source = trim($_GET['source'] ?? '');
    if (!empty($ids_raw)) {
        $placeholders = implode(',', array_fill(0, count($ids_raw), '?'));
        $types = str_repeat('i', count($ids_raw));
        if ($source === 'borrowed') {
            $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM borrowed_assets WHERE id IN ($placeholders) ORDER BY id ASC");
        } else {
            $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM assets WHERE id IN ($placeholders) ORDER BY id ASC");
        }
        $stmt->bind_param($types, ...$ids_raw);
        $stmt->execute();
        $assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} elseif (isset($_GET['category_id']) && isset($_GET['asset_name']) && isset($_GET['item_no'])) {
    $cat_id = (int)$_GET['category_id'];
    $name = trim($_GET['asset_name']);
    $item_no = (int)$_GET['item_no'];
    $source = trim($_GET['source'] ?? '');
    if ($source === 'borrowed') {
        $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM borrowed_assets WHERE category_id = ? AND asset_name = ? AND item_no = ? AND (status IS NULL OR status <> 'Returned') AND asset_no IS NOT NULL AND asset_no != '' ORDER BY id ASC");
    } else {
        $stmt = $conn->prepare("SELECT id, asset_name, asset_no, item_no, category_id FROM assets WHERE category_id = ? AND asset_name = ? AND item_no = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) AND asset_no IS NOT NULL AND asset_no != '' ORDER BY id ASC");
    }
    $stmt->bind_param("isi", $cat_id, $name, $item_no);
    $stmt->execute();
    $assets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if (empty($assets)) {
    http_response_code(404);
    exit('No assets found.');
}

$COLS     = 4;
$PER_PAGE = 20;

$css = '<style>
    body  { font-family: dejavusans, sans-serif; margin:0; padding:0; }
    table.grid { width:100%; border-collapse:collapse; }
    td.cell {
        width:25%;
        text-align:center;
        vertical-align:middle;
        padding:3mm 2mm;
        border:0.4pt solid #cccccc;
        height:53mm;
    }
    .asset-no {
        font-size:6.5pt;
        font-weight:bold;
        color:#111;
        word-break:break-all;
        margin-top:5mm;
        line-height:1.3;
    }
    .asset-name {
        font-size:6pt;
        color:#555;
        margin-top:1mm;
        word-break:break-word;
    }
    .empty-cell { background:#fafafa; }
</style>';

$html  = '<!DOCTYPE html><html><head>' . $css . '</head><body>';
$pages = array_chunk($assets, $PER_PAGE);

foreach ($pages as $pageIdx => $page_assets) {
    if ($pageIdx > 0) {
        $html .= '<pagebreak />';
    }
    $html .= '<table class="grid">';
    $rows  = array_chunk($page_assets, $COLS);
    while (count($rows) < 5) { $rows[] = []; }

    foreach ($rows as $row) {
        $html .= '<tr>';
        for ($c = 0; $c < $COLS; $c++) {
            if (isset($row[$c]) && !empty($row[$c]['asset_no'])) {
                $a    = $row[$c];
                $code = htmlspecialchars($a['asset_no'], ENT_XML1 | ENT_QUOTES);
                $html .= '<td class="cell">';
                $html .= '<barcode code="' . $code . '" type="QR" size="1.2" error="M" />';
                $html .= '<div class="asset-no">' . htmlspecialchars($a['asset_no']) . '</div>';
                if (!empty($a['asset_name'])) {
                    $html .= '<div class="asset-name">' . htmlspecialchars($a['asset_name']) . '</div>';
                }
                $html .= '</td>';
            } else {
                $html .= '<td class="cell empty-cell"></td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
}

$html .= '</body></html>';

$mpdf = new \Mpdf\Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_left'   => 7,
    'margin_right'  => 7,
    'margin_top'    => 7,
    'margin_bottom' => 7,
    'default_font'  => 'dejavusans',
]);

$count = count($assets);
$mpdf->SetTitle("QR Labels ({$count} assets) - KDP Asset Manager");
$mpdf->WriteHTML($html);

$filename = 'QR_Labels_' . date('Ymd_His') . '.pdf';
$mpdf->Output($filename, 'D');
exit();
