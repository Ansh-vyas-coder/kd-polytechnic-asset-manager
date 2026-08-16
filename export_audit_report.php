<?php
session_start();
require 'db.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("HTTP/1.1 403 Forbidden"); // User not logged in or not a valid role
    exit("Access denied.");
}

// Get and validate the audit ID from the URL
$audit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($audit_id <= 0) {
    exit("Invalid audit ID.");
}

// --- Fetch main audit details ---
$audit_stmt = $conn->prepare(
    "SELECT a.id, a.location_id, a.audit_date, a.status, a.audited_by_user_id, u.full_name as audited_by
     FROM audits a
     JOIN users u ON a.audited_by_user_id = u.id
     WHERE a.id = ?"
);
if (!$audit_stmt) {
    die("Database error preparing to fetch audit details.");
}
$audit_stmt->bind_param("i", $audit_id);
$audit_stmt->execute();
$audit_result = $audit_stmt->get_result();
$audit_details = $audit_result->fetch_assoc();
$audit_stmt->close();

if (!$audit_details) {
    exit("Audit report not found.");
}

// Security check: Staff can only export their own audit results
if ($_SESSION['role'] === 'staff' && $audit_details['audited_by_user_id'] != $_SESSION['user_id']) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied. You can only export your own audit reports.");
}

// --- Fetch all audit items ---
$items_stmt = $conn->prepare(
    "SELECT
        ai.verification_status,
        ai.condition,
        ai.note,
        ai.expected_location_id,
        ai.scanned_location_id,
        a.asset_name, 
        a.category_id,
        a.asset_no
    FROM audit_items ai
    JOIN assets a ON ai.asset_id = a.id
    WHERE ai.audit_id = ?
    ORDER BY ai.verification_status, a.asset_name"
);
if (!$items_stmt) {
    die("Database error preparing to fetch audit items.");
}
$items_stmt->bind_param("i", $audit_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$all_items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// --- Process items into categories ---
$present_items_by_category_and_name = [];
$missing_items_by_category_and_name = [];
$misplaced_items_by_category_and_name = [];

// Define categories for display
$categories_map = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

foreach ($all_items as $item) {
    $category_id = $item['category_id'];
    $asset_name = $item['asset_name'];
    switch ($item['verification_status']) {
        case 'Present':
            if (!isset($present_items_by_category_and_name[$category_id])) $present_items_by_category_and_name[$category_id] = [];
            if (!isset($present_items_by_category_and_name[$category_id][$asset_name])) $present_items_by_category_and_name[$category_id][$asset_name] = [];
            $present_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
        case 'Missing':
            if (!isset($missing_items_by_category_and_name[$category_id])) $missing_items_by_category_and_name[$category_id] = [];
            if (!isset($missing_items_by_category_and_name[$category_id][$asset_name])) $missing_items_by_category_and_name[$category_id][$asset_name] = [];
            $missing_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
        case 'Misplaced':
            if (!isset($misplaced_items_by_category_and_name[$category_id])) $misplaced_items_by_category_and_name[$category_id] = [];
            if (!isset($misplaced_items_by_category_and_name[$category_id][$asset_name])) $misplaced_items_by_category_and_name[$category_id][$asset_name] = [];
            $misplaced_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
    }
}
$present_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $present_items_by_category_and_name));
$missing_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $missing_items_by_category_and_name));
$misplaced_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $misplaced_items_by_category_and_name));

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// --- STYLES ---
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];
$sectionTitleStyle = [
    'font' => ['bold' => true, 'size' => 12],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
];
$subSectionTitleStyle = [
    'font' => ['bold' => true, 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
];
$assetNameTitleStyle = [
    'font' => ['bold' => true, 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
];

$row = 1;

// --- MAIN REPORT DETAILS ---
$sheet->setCellValue('A'.$row, 'Audit Report #' . htmlspecialchars($audit_details['id']));
$sheet->mergeCells('A'.$row.':D'.$row);
$sheet->getStyle('A'.$row)->applyFromArray(['font' => ['bold' => true, 'size' => 16]]);
$row += 2;

$sheet->setCellValue('A'.$row, 'Location:');
$sheet->setCellValue('B'.$row, htmlspecialchars($audit_details['location_id']));
$sheet->setCellValue('C'.$row, 'Audited By:');
$sheet->setCellValue('D'.$row, htmlspecialchars($audit_details['audited_by']));
$sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
$row++;

$sheet->setCellValue('A'.$row, 'Date:');
$sheet->setCellValue('B'.$row, date('M d, Y h:i A', strtotime($audit_details['audit_date'])));
$sheet->setCellValue('C'.$row, 'Status:');
$sheet->setCellValue('D'.$row, htmlspecialchars($audit_details['status']));
$sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
$row += 2;

// --- PRESENT ITEMS ---
if (!empty($present_items_by_category_and_name)) {
    $sheet->setCellValue('A'.$row, 'Present Items (Total: ' . $present_count . ')');
    $sheet->mergeCells('A'.$row.':D'.$row);
    $sheet->getStyle('A'.$row)->applyFromArray($sectionTitleStyle);
    $row++;

    foreach ($present_items_by_category_and_name as $category_id => $asset_names_in_category) {
        $sheet->setCellValue('A'.$row, htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'));
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->applyFromArray($subSectionTitleStyle);
        $row++;

        foreach ($asset_names_in_category as $asset_name => $items) {
            $sheet->setCellValue('A'.$row, htmlspecialchars($asset_name) . ' (' . count($items) . ')');
            $sheet->mergeCells('A'.$row.':D'.$row);
            $sheet->getStyle('A'.$row)->applyFromArray($assetNameTitleStyle);
            $row++;

            $sheet->setCellValue('A'.$row, 'Asset No.');
            $sheet->setCellValue('B'.$row, 'Condition');
            $sheet->setCellValue('C'.$row, 'Note');
            $sheet->getStyle('A'.$row.':C'.$row)->applyFromArray($headerStyle);
            $sheet->mergeCells('C'.$row.':D'.$row);
            $row++;

            foreach ($items as $item) {
                $sheet->setCellValue('A'.$row, htmlspecialchars($item['asset_no']));
                $sheet->setCellValue('B'.$row, htmlspecialchars($item['condition'] ?: 'N/A'));
                $sheet->setCellValue('C'.$row, htmlspecialchars($item['note'] ?: '-'));
                $sheet->mergeCells('C'.$row.':D'.$row);
                $row++;
            }
            $row++; // Blank row after each asset name group
        }
        $row++; // Blank row after each category group
    }
}

// --- MISSING ITEMS ---
if (!empty($missing_items_by_category_and_name)) {
    $sheet->setCellValue('A'.$row, 'Missing Items (Total: ' . $missing_count . ')');
    $sheet->mergeCells('A'.$row.':D'.$row);
    $sheet->getStyle('A'.$row)->applyFromArray($sectionTitleStyle);
    $row++;

    foreach ($missing_items_by_category_and_name as $category_id => $asset_names_in_category) {
        $sheet->setCellValue('A'.$row, htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'));
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->applyFromArray($subSectionTitleStyle);
        $row++;

        foreach ($asset_names_in_category as $asset_name => $items) {
            $sheet->setCellValue('A'.$row, htmlspecialchars($asset_name) . ' (' . count($items) . ')');
            $sheet->mergeCells('A'.$row.':D'.$row);
            $sheet->getStyle('A'.$row)->applyFromArray($assetNameTitleStyle);
            $row++;

            $sheet->setCellValue('A'.$row, 'Asset No.');
            $sheet->setCellValue('B'.$row, 'Expected At');
            $sheet->setCellValue('C'.$row, 'Found At');
            $sheet->setCellValue('D'.$row, 'Note');
            $sheet->getStyle('A'.$row.':D'.$row)->applyFromArray($headerStyle);
            $row++;


            foreach ($items as $item) {
                $sheet->setCellValue('A'.$row, htmlspecialchars($item['asset_no']));
                $sheet->setCellValue('B'.$row, htmlspecialchars($item['expected_location_id']));
                $sheet->mergeCells('B'.$row.':D'.$row);
                $row++;
                $sheet->setCellValue('C'.$row, htmlspecialchars($item['scanned_location_id'] ?: 'N/A'));
                $sheet->setCellValue('D'.$row, htmlspecialchars($item['note'] ?: '-'));
            }
            $row++; // Blank row after each asset name group
        }
        $row++; // Blank row after each category group
    }
}

// --- MISPLACED ITEMS ---
if (!empty($misplaced_items_by_category_and_name)) {
    $sheet->setCellValue('A'.$row, 'Misplaced Items (Total: ' . $misplaced_count . ')');
    $sheet->mergeCells('A'.$row.':D'.$row);
    $sheet->getStyle('A'.$row)->applyFromArray($sectionTitleStyle);
    $row++;

    foreach ($misplaced_items_by_category_and_name as $category_id => $asset_names_in_category) {
        $sheet->setCellValue('A'.$row, htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'));
        $sheet->mergeCells('A'.$row.':D'.$row);
        $sheet->getStyle('A'.$row)->applyFromArray($subSectionTitleStyle);
        $row++;

        foreach ($asset_names_in_category as $asset_name => $items) {
            $sheet->setCellValue('A'.$row, htmlspecialchars($asset_name) . ' (' . count($items) . ')');
            $sheet->mergeCells('A'.$row.':D'.$row);
            $sheet->getStyle('A'.$row)->applyFromArray($assetNameTitleStyle);
            $row++;

            $sheet->setCellValue('A'.$row, 'Asset No.');
            $sheet->setCellValue('B'.$row, 'Found At');
            $sheet->setCellValue('C'.$row, 'Expected At');
            $sheet->getStyle('A'.$row.':C'.$row)->applyFromArray($headerStyle);
            $sheet->mergeCells('C'.$row.':D'.$row);
            $row++;

            foreach ($items as $item) {
                $sheet->setCellValue('A'.$row, htmlspecialchars($item['asset_no']));
                $sheet->setCellValue('B'.$row, htmlspecialchars($item['scanned_location_id']));
                $sheet->setCellValue('C'.$row, htmlspecialchars($item['expected_location_id']));
                $sheet->mergeCells('C'.$row.':D'.$row);
                $row++;
            }
            $row++; // Blank row after each asset name group
        }
        $row++; // Blank row after each category group
    }
}

// --- AUTOSIZE COLUMNS ---
foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// --- OUTPUT ---
$filename = "Audit_Report_" . $audit_id . "_" . date('Y-m-d') . ".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>