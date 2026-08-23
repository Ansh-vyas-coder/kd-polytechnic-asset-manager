<?php
session_start();
require 'db.php';

$autoload_path = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload_path)) {
    http_response_code(500);
    exit("Export dependencies are missing. Run composer install and try again.");
}
require_once $autoload_path;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied.");
}

$format = strtolower(trim($_GET['format'] ?? 'xlsx'));
$selected_faculty = trim($_GET['faculty'] ?? '');

if ($_SESSION['role'] === 'staff') {
    $selected_faculty = $_SESSION['user_name'];
}

$categories = [1 => 'Expandable', 2 => 'Consumables', 3 => 'Deadstock', 4 => 'Furniture'];
$items = [];

if ($_SESSION['role'] === 'staff' || $selected_faculty !== '') {
    $sql = "
        SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, updated_at, 'dept' AS source
        FROM assets
        WHERE assigned_to = ?
          AND assigned_to IS NOT NULL
          AND assigned_to != ''
          AND retire_at IS NULL
          AND (transferred = 0 OR transferred IS NULL)
        UNION ALL
        SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, updated_at, 'borrowed' AS source
        FROM borrowed_assets
        WHERE assigned_to = ?
          AND assigned_to IS NOT NULL
          AND assigned_to != ''
          AND (status IS NULL OR status <> 'Returned')
        ORDER BY assigned_to ASC, updated_at DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        exit("Failed to prepare export query.");
    }
    $stmt->bind_param("ss", $selected_faculty, $selected_faculty);
} else {
    $sql = "
        SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, updated_at, 'dept' AS source
        FROM assets
        WHERE assigned_to IS NOT NULL
          AND assigned_to != ''
          AND retire_at IS NULL
          AND (transferred = 0 OR transferred IS NULL)
        UNION ALL
        SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, updated_at, 'borrowed' AS source
        FROM borrowed_assets
        WHERE assigned_to IS NOT NULL
          AND assigned_to != ''
          AND (status IS NULL OR status <> 'Returned')
        ORDER BY assigned_to ASC, updated_at DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        exit("Failed to prepare export query.");
    }
}

$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $items = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

$report_name = ($_SESSION['role'] === 'staff')
    ? 'My_Assigned_Assets'
    : ($selected_faculty !== '' ? 'Assigned_Assets_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $selected_faculty) : 'Assigned_Assets_All');

if ($format === 'pdf') {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 12,
        'margin_bottom' => 12,
    ]);

    $html = '<h1 style="text-align:center;font-size:18px;margin:0 0 6px;">K.D. Polytechnic Assigned Assets</h1>';
    $html .= '<div style="text-align:center;font-size:11px;color:#555;margin-bottom:14px;">Generated: ' . date('d/m/Y h:i A') . '</div>';
    $html .= '<div style="font-size:12px;margin-bottom:10px;"><strong>Scope:</strong> ' . htmlspecialchars($_SESSION['role'] === 'staff' ? $_SESSION['user_name'] : ($selected_faculty !== '' ? $selected_faculty : 'All assigned assets')) . '</div>';
    $html .= '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-size:10px;">';
    $html .= '<thead><tr style="background:#1e3a8a;color:#fff;">';
    foreach (['Asset Name', 'Asset No', 'Category', 'Assigned To', 'Location', 'Status', 'Last Updated', 'Source'] as $heading) {
        $html .= '<th>' . htmlspecialchars($heading) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    if (empty($items)) {
        $html .= '<tr><td colspan="8" style="text-align:center;">No assigned assets found.</td></tr>';
    } else {
        foreach ($items as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['asset_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['asset_no'] ?: 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($categories[$item['category_id']] ?? 'Unknown') . '</td>';
            $html .= '<td>' . htmlspecialchars($item['assigned_to']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['location'] ?: 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($item['status'] ?: 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars(date('M d, Y', strtotime($item['updated_at']))) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['source'] === 'borrowed' ? 'Borrowed' : 'Department') . '</td>';
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';

    $mpdf->WriteHTML($html);
    $mpdf->SetTitle('Assigned Assets');
    $mpdf->Output($report_name . '.pdf', 'D');
    exit();
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Assigned Assets');

$sheet->setCellValue('A1', 'K.D. Polytechnic Assigned Assets');
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A2', 'Generated: ' . date('d/m/Y h:i A'));
$sheet->mergeCells('A2:H2');
$sheet->setCellValue('A3', 'Scope: ' . ($_SESSION['role'] === 'staff' ? $_SESSION['user_name'] : ($selected_faculty !== '' ? $selected_faculty : 'All assigned assets')));
$sheet->mergeCells('A3:H3');

$headers = ['Asset Name', 'Asset No', 'Category', 'Assigned To', 'Location', 'Status', 'Last Updated', 'Source'];
$headerRow = 5;
foreach ($headers as $index => $heading) {
    $cell = chr(65 + $index) . $headerRow;
    $sheet->setCellValue($cell, $heading);
}

$row = 6;
if (empty($items)) {
    $sheet->setCellValue('A' . $row, 'No assigned assets found.');
    $sheet->mergeCells('A' . $row . ':H' . $row);
} else {
    foreach ($items as $item) {
        $sheet->setCellValueExplicit('A' . $row, (string)$item['asset_name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B' . $row, (string)($item['asset_no'] ?: 'N/A'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C' . $row, (string)($categories[$item['category_id']] ?? 'Unknown'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('D' . $row, (string)$item['assigned_to'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('E' . $row, (string)($item['location'] ?: 'N/A'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F' . $row, (string)($item['status'] ?: 'N/A'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('G' . $row, date('M d, Y', strtotime($item['updated_at'])), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('H' . $row, $item['source'] === 'borrowed' ? 'Borrowed' : 'Department', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $row++;
    }
}

foreach (range('A', 'H') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A5:H5')->getFont()->setBold(true);
$sheet->getStyle('A5:H5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
$sheet->getStyle('A5:H5')->getFont()->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A5:H' . max($row - 1, 5))->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$filename = $report_name . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit();
