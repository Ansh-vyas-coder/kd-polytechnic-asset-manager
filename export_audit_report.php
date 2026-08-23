<?php
session_start();
require 'db.php';

$autoload_path = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload_path)) {
    http_response_code(500);
    exit("Spreadsheet export dependencies are missing. Run composer install and try again.");
}
require_once $autoload_path;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied.");
}

$audit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($audit_id <= 0) { exit("Invalid audit ID."); }

$audit_stmt = $conn->prepare("SELECT a.id, a.location_id, a.audit_date, a.status, a.audited_by_user_id, u.full_name as audited_by FROM audits a JOIN users u ON a.audited_by_user_id = u.id WHERE a.id = ?");
if (!$audit_stmt) { die("Database error preparing to fetch audit details."); }
$audit_stmt->bind_param("i", $audit_id);
$audit_stmt->execute();
$audit_details = $audit_stmt->get_result()->fetch_assoc();
$audit_stmt->close();

if (!$audit_details) { exit("Audit report not found."); }

if ($_SESSION['role'] === 'staff' && $audit_details['audited_by_user_id'] != $_SESSION['user_id']) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied. You can only export your own audit reports.");
}

$items_stmt = $conn->prepare("SELECT ai.verification_status, ai.`condition`, ai.note, ai.expected_location_id, ai.scanned_location_id, a.asset_name, a.category_id, a.asset_no, 'dept' AS source FROM audit_items ai JOIN assets a ON ai.asset_id = a.id WHERE ai.audit_id = ? ORDER BY ai.verification_status, a.asset_name");
if (!$items_stmt) { die("Database error preparing to fetch audit items."); }
$items_stmt->bind_param("i", $audit_id);
$items_stmt->execute();
$all_items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

$borrowed_stmt = $conn->prepare("SELECT bai.verification_status, bai.`condition`, bai.note, bai.expected_location_id, bai.scanned_location_id, ba.asset_name, ba.category_id, ba.asset_no, 'borrowed' AS source FROM borrowed_audit_items bai JOIN borrowed_assets ba ON bai.borrowed_asset_id = ba.id WHERE bai.audit_id = ? ORDER BY bai.verification_status, ba.asset_name");
if ($borrowed_stmt) {
    $borrowed_stmt->bind_param("i", $audit_id);
    $borrowed_stmt->execute();
    $all_items = array_merge($all_items, $borrowed_stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $borrowed_stmt->close();
}

$present_items   = [];
$missing_items   = [];
$misplaced_items = [];

$categories_map = [1 => 'Expandable', 2 => 'Consumables', 3 => 'Deadstock', 4 => 'Furniture'];

foreach ($all_items as $item) {
    $cid  = $item['category_id'];
    $name = $item['asset_name'] . ($item['source'] === 'borrowed' ? ' [Borrowed]' : '');
    switch ($item['verification_status']) {
        case 'Present':
            if (!isset($present_items[$cid][$name])) $present_items[$cid][$name] = [];
            $present_items[$cid][$name][] = $item; break;
        case 'Missing':
            if (!isset($missing_items[$cid][$name])) $missing_items[$cid][$name] = [];
            $missing_items[$cid][$name][] = $item; break;
        case 'Misplaced':
            if (!isset($misplaced_items[$cid][$name])) $misplaced_items[$cid][$name] = [];
            $misplaced_items[$cid][$name][] = $item; break;
    }
}
$present_count   = array_sum(array_map(fn($g) => array_sum(array_map('count', $g)), $present_items));
$missing_count   = array_sum(array_map(fn($g) => array_sum(array_map('count', $g)), $missing_items));
$misplaced_count = array_sum(array_map(fn($g) => array_sum(array_map('count', $g)), $misplaced_items));

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$hdr   = ['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1E3A5F']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]];
$sec   = ['font'=>['bold'=>true,'size'=>12],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E2E8F0']]];
$sub   = ['font'=>['bold'=>true,'size'=>11],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F1F5F9']]];
$aname = ['font'=>['bold'=>true,'size'=>10],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'F8FAFC']]];

$row = 1;
$sheet->setCellValue('A'.$row, 'Audit Report #'.$audit_details['id']); $sheet->mergeCells('A'.$row.':D'.$row); $sheet->getStyle('A'.$row)->applyFromArray(['font'=>['bold'=>true,'size'=>16]]); $row+=2;
$sheet->setCellValue('A'.$row, 'Location:'); $sheet->setCellValue('B'.$row, $audit_details['location_id']); $sheet->setCellValue('C'.$row, 'Audited By:'); $sheet->setCellValue('D'.$row, $audit_details['audited_by']); $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true); $row++;
$sheet->setCellValue('A'.$row, 'Date:'); $sheet->setCellValue('B'.$row, date('M d, Y h:i A', strtotime($audit_details['audit_date']))); $sheet->setCellValue('C'.$row, 'Status:'); $sheet->setCellValue('D'.$row, $audit_details['status']); $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true); $row+=2;

foreach ([['Present Items', $present_count, $present_items, 'asset_no,condition,note'], ['Missing Items', $missing_count, $missing_items, 'asset_no,expected_location_id,scanned_location_id,note'], ['Misplaced Items', $misplaced_count, $misplaced_items, 'asset_no,scanned_location_id,expected_location_id']] as [$title, $count, $groups, $colsStr]) {
    if (empty($groups)) continue;
    $sheet->setCellValue('A'.$row, "$title (Total: $count)"); $sheet->mergeCells('A'.$row.':D'.$row); $sheet->getStyle('A'.$row)->applyFromArray($sec); $row++;
    foreach ($groups as $cid => $names) {
        $sheet->setCellValue('A'.$row, $categories_map[$cid] ?? 'Unknown'); $sheet->mergeCells('A'.$row.':D'.$row); $sheet->getStyle('A'.$row)->applyFromArray($sub); $row++;
        foreach ($names as $name => $items) {
            $sheet->setCellValue('A'.$row, "$name (".count($items).")"); $sheet->mergeCells('A'.$row.':D'.$row); $sheet->getStyle('A'.$row)->applyFromArray($aname); $row++;
            $cols = explode(',', $colsStr);
            $headers = ['asset_no'=>'Asset No.','condition'=>'Condition','note'=>'Note','expected_location_id'=>'Expected At','scanned_location_id'=>'Found At'];
            $col_letters = ['A','B','C','D'];
            foreach ($cols as $i => $c) { $sheet->setCellValue($col_letters[$i].$row, $headers[$c] ?? $c); }
            $sheet->getStyle('A'.$row.':'.($col_letters[count($cols)-1]).$row)->applyFromArray($hdr); $row++;
            foreach ($items as $item) {
                foreach ($cols as $i => $c) { $sheet->setCellValue($col_letters[$i].$row, $item[$c] ?: '-'); }
                $row++;
            }
            $row++;
        }
        $row++;
    }
}

foreach (range('A','D') as $c) { $sheet->getColumnDimension($c)->setAutoSize(true); }

$filename = "Audit_Report_{$audit_id}_".date('Y-m-d').".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit();
?>
