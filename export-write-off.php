<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied.");
}

$category_names = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

function format_asset_status($status, $retire_at) {
    if ($retire_at !== null) {
        return 'Retired';
    }
    if (empty($status)) {
        return 'Active';
    }
    $lower = strtolower(trim($status));
    if ($lower === 'active') {
        return 'Active';
    }
    if ($lower === 'retired') {
        return 'Retired';
    }
    // Return properly capitalized status (e.g. 'under maintenance' -> 'Under Maintenance')
    return ucwords($lower);
}

function get_group_status($items) {
    $statuses = [];
    $has_retired = false;
    $has_active = false;
    foreach ($items as $item) {
        $item_status = format_asset_status($item['status'], $item['retire_at']);
        if ($item_status === 'Retired') {
            $has_retired = true;
        } else {
            $has_active = true;
        }
        if (!in_array($item_status, $statuses, true)) {
            $statuses[] = $item_status;
        }
    }
    if (count($statuses) === 1) {
        return $statuses[0];
    }
    if ($has_active && $has_retired) {
        return 'Mixed';
    }
    return implode(', ', $statuses);
}

function get_period_between_dates($start_date, $end_date) {
    if (!$start_date || !$end_date) return '';
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $diff = $start->diff($end);
    $total_days = $diff->days;
    if ($total_days >= 365) {
        $years = round($total_days / 365.25, 1);
        return $years . ($years == 1 ? ' Year' : ' Years');
    } elseif ($total_days >= 30) {
        $months = round($total_days / 30.4, 1);
        return $months . ($months == 1 ? ' Month' : ' Months');
    } else {
        return $total_days . ($total_days == 1 ? ' Day' : ' Days');
    }
}

function get_dates_unserviceable($status, $status_marked_at, $retire_at) {
    $status_lower = strtolower(trim($status));
    if ($retire_at !== null) {
        $start = date('d/m/Y', strtotime($retire_at));
        $end = date('d/m/Y');
        return $start . ' to ' . $end;
    }
    if ($status_lower === 'under maintenance' || $status_lower === 'not working' || $status_lower === 'missing') {
        $start = $status_marked_at ? date('d/m/Y', strtotime($status_marked_at)) : date('d/m/Y');
        $end = date('d/m/Y');
        return $start . ' to ' . $end;
    }
    return '';
}

function get_unserviceable_period($status, $status_marked_at, $retire_at) {
    $status_lower = strtolower(trim($status));
    if ($retire_at !== null) {
        return get_period_between_dates($retire_at, date('Y-m-d H:i:s'));
    }
    if ($status_lower === 'under maintenance' || $status_lower === 'not working' || $status_lower === 'missing') {
        $start = $status_marked_at ?: date('Y-m-d H:i:s');
        return get_period_between_dates($start, date('Y-m-d H:i:s'));
    }
    return '';
}

function get_period_of_use_display($date_of_issue, $status, $status_marked_at, $retire_at) {
    if (!$date_of_issue) return '';
    $start_fmt = date('d/m/Y', strtotime($date_of_issue));
    $status_lower = strtolower(trim($status));
    if ($retire_at !== null) {
        $end_date = $retire_at;
        $end_fmt = date('d/m/Y', strtotime($retire_at));
    } elseif ($status_lower === 'not working' || $status_lower === 'under maintenance' || $status_lower === 'missing') {
        $end_date = $status_marked_at ?: date('Y-m-d H:i:s');
        $end_fmt = $status_marked_at ? date('d/m/Y', strtotime($status_marked_at)) : date('d/m/Y');
    } else {
        $end_date = date('Y-m-d H:i:s');
        $end_fmt = date('d/m/Y');
    }
    $start_dt = new DateTime($date_of_issue);
    $end_dt = new DateTime($end_date);
    $diff = $start_dt->diff($end_dt);
    $total_days = $diff->days;
    $years = round($total_days / 365.25, 1);
    $years_str = $years . ($years == 1 ? ' Year' : ' Years');
    return $start_fmt . ' to ' . $end_fmt . ' (' . $years_str . ')';
}

function group_assets_by_item($assets_list) {
    $grouped = [];
    foreach ($assets_list as $asset) {
        $item_no = $asset['item_no'] ?: 'Uncategorized';
        if (!isset($grouped[$item_no])) {
            $grouped[$item_no] = [
                'item_no' => $item_no,
                'asset_name' => $asset['asset_name'],
                'category_id' => $asset['category_id'],
                'page_no' => $asset['page_no'],
                'date_of_issue_min' => $asset['date_of_issue'],
                'date_of_issue_max' => $asset['date_of_issue'],
                'total_cost' => 0,
                'locations' => [],
                'items' => []
            ];
        }
        $grouped[$item_no]['items'][] = $asset;
        $grouped[$item_no]['total_cost'] += (float)$asset['cost'];
        $loc = $asset['location'] ?: ($asset['assigned_to'] ?: 'N/A');
        if (!in_array($loc, $grouped[$item_no]['locations'])) {
            $grouped[$item_no]['locations'][] = $loc;
        }
        if (strtotime($asset['date_of_issue']) < strtotime($grouped[$item_no]['date_of_issue_min'])) {
            $grouped[$item_no]['date_of_issue_min'] = $asset['date_of_issue'];
        }
        if (strtotime($asset['date_of_issue']) > strtotime($grouped[$item_no]['date_of_issue_max'])) {
            $grouped[$item_no]['date_of_issue_max'] = $asset['date_of_issue'];
        }
    }
    return $grouped;
}

$tab = $_POST['tab'] ?? $_GET['tab'] ?? 'candidates';
$is_history = ($tab === 'history');
$is_all = ($tab === 'all');

// Get selected categories from POST or GET
$raw_categories = $_POST['categories'] ?? $_GET['categories'] ?? [];
if (!is_array($raw_categories)) {
    $raw_categories = explode(',', (string)$raw_categories);
}

$selected_categories = [];
foreach ($raw_categories as $cat) {
    $cat_id = (int)$cat;
    if (isset($category_names[$cat_id])) {
        $selected_categories[] = $cat_id;
    }
}

if (empty($selected_categories)) {
    header("HTTP/1.1 400 Bad Request");
    exit("Please select at least one valid category to export.");
}

$name_filter = trim($_POST['name'] ?? $_GET['name'] ?? '');
$issue_from  = trim($_POST['issue_from'] ?? $_GET['issue_from'] ?? '');
$issue_to    = trim($_POST['issue_to'] ?? $_GET['issue_to'] ?? '');
$cutoff_date = date('Y-m-d', strtotime('-5 years'));

// Prepare SQL query based on mode (Candidates vs History vs All)
$in_clause = implode(',', array_fill(0, count($selected_categories), '?'));

if ($is_all) {
    $sql = "
        SELECT
            id,
            asset_no,
            asset_name,
            category_id,
            item_no,
            page_no,
            location,
            assigned_to,
            status,
            date_of_issue,
            retire_at,
            cost,
            remarks,
            batch_id,
            status_marked_at,
            status_marked_note
        FROM assets
        WHERE category_id IN ($in_clause)
          AND (
              retire_at IS NOT NULL
              OR (
                  retire_at IS NULL
                  AND (transferred = 0 OR transferred IS NULL)
                  AND date_of_issue <= ?
              )
          )
    ";
    $types = str_repeat('i', count($selected_categories)) . 's';
    $params = array_merge($selected_categories, [$cutoff_date]);
} elseif ($is_history) {
    $sql = "
        SELECT
            id,
            asset_no,
            asset_name,
            category_id,
            item_no,
            page_no,
            location,
            assigned_to,
            status,
            date_of_issue,
            retire_at,
            cost,
            remarks,
            batch_id,
            status_marked_at,
            status_marked_note
        FROM assets
        WHERE category_id IN ($in_clause)
          AND retire_at IS NOT NULL
    ";
    $types = str_repeat('i', count($selected_categories));
    $params = $selected_categories;
} else {
    $sql = "
        SELECT
            id,
            asset_no,
            asset_name,
            category_id,
            item_no,
            page_no,
            location,
            assigned_to,
            status,
            date_of_issue,
            retire_at,
            cost,
            remarks,
            batch_id,
            status_marked_at,
            status_marked_note
        FROM assets
        WHERE category_id IN ($in_clause)
          AND retire_at IS NULL
          AND (transferred = 0 OR transferred IS NULL)
          AND date_of_issue <= ?
    ";
    $types = str_repeat('i', count($selected_categories)) . 's';
    $params = array_merge($selected_categories, [$cutoff_date]);
}

if ($name_filter !== '') {
    $sql .= " AND asset_name LIKE ?";
    $types .= 's';
    $params[] = '%' . $name_filter . '%';
}

if ($issue_from !== '') {
    $sql .= " AND date_of_issue >= ?";
    $types .= 's';
    $params[] = $issue_from;
}

if ($issue_to !== '') {
    $sql .= " AND date_of_issue <= ?";
    $types .= 's';
    $params[] = $issue_to;
}

if ($is_history) {
    $sql .= " ORDER BY category_id ASC, retire_at DESC, date_of_issue ASC";
} else {
    $sql .= " ORDER BY category_id ASC, date_of_issue ASC, asset_name ASC, item_no ASC";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    exit("Database query error.");
}

// Bind params dynamically
$bind_names = [$types];
foreach ($params as $k => &$v) {
    $bind_names[] = &$v;
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

$stmt->execute();
$result = $stmt->get_result();
$assets = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $assets[] = $row;
    }
}
$stmt->close();

$filename_prefix = $is_all ? "All_Write_Off_Assets_Report_" : ($is_history ? "Written_Off_Assets_History_" : "Write_Off_Assets_Candidates_");
$filename = $filename_prefix . date('Y-m-d') . ".xlsx";

// Check if PhpSpreadsheet is available
$use_phpspreadsheet = false;
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        $use_phpspreadsheet = true;
    }
}

if ($use_phpspreadsheet) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($is_all ? 'All Write-Off Assets' : ($is_history ? 'Written-Off Assets' : 'Write-Off Candidates'));

    if ($is_all) {
        // --- CUSTOM 23-COLUMN STRUCTURE FOR THE MAIN COMBINED EXPORT ---
        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(3)->setRowHeight(28);
        $sheet->getRowDimension(4)->setRowHeight(28);
        $sheet->getRowDimension(5)->setRowHeight(20);

        // Title 1
        $sheet->mergeCells("A1:W1");
        $sheet->setCellValue('A1', 'Institute name - K.D. Polytechnic, Patan');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0000'));
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Title 2
        $sheet->mergeCells("A2:W2");
        $sheet->setCellValue('A2', 'Details for Write off e-waste (Above Rs. 20,000/-)');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0000'));
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Subtitle Row
        $cat_names_str = implode(', ', array_map(function($id) use ($category_names) {
            return $category_names[$id] ?? '';
        }, $selected_categories));

        $sheet->mergeCells("A3:W3");
        $sheet->setCellValue('A3', 'Categories: ' . $cat_names_str . ' | Generated: ' . date('d/m/Y H:i') . ' | Total Items: ' . count($assets));
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(10);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->getRowDimension(4)->setRowHeight(28);
        $sheet->getRowDimension(5)->setRowHeight(28);
        $sheet->getRowDimension(6)->setRowHeight(20);

        // Define merges for headers in rows 4 & 5
        $headers_config = [
            'A' => ['text' => 'Sr. No.', 'merge' => 'A4:A5'],
            'B' => ['text' => 'Details of Dead stock Register', 'merge' => 'B4:D4'],
            'E' => ['text' => 'Name of Articles', 'merge' => 'E4:E5'],
            'F' => ['text' => 'Nature of Articles', 'merge' => 'F4:F5'],
            'G' => ['text' => 'Qty.', 'merge' => 'G4:G5'],
            'H' => ['text' => 'Name of Authority who allowed for purchase of articles.', 'merge' => 'H4:H5'],
            'I' => ['text' => 'Price of each articles Rs. = Ps.', 'merge' => 'I4:I5'],
            'J' => ['text' => 'Date of Purchase', 'merge' => 'J4:J5'],
            'K' => ['text' => 'Dates of which articles rendered unserviceable', 'merge' => 'K4:K5'],
            'L' => ['text' => 'How the articles becomes unserviceable', 'merge' => 'L4:L5'],
            'M' => ['text' => 'The actual period during which articles become unserviceable', 'merge' => 'M4:M5'],
            'N' => ['text' => 'Period of use in Years', 'merge' => 'N4:N5'],
            'O' => ['text' => 'Whether responsibility can be fixed for misuse of early wear.', 'merge' => 'O4:O5'],
            'P' => ['text' => 'Whether defects can be repaired.', 'merge' => 'P4:P5'],
            'Q' => ['text' => 'If so when what will be test of repair', 'merge' => 'Q4:Q5'],
            'R' => ['text' => 'No. of Articles recommended for written off. by the Deputy Director', 'merge' => 'R4:R5'],
            'S' => ['text' => 'Total Amount articles recommended written off. Rs. = P', 'merge' => 'S4:S5'],
            'T' => ['text' => 'Description defects develop or actual condition of the item', 'merge' => 'T4:T5'],
            'U' => ['text' => 'Grant Total Rs. = P', 'merge' => 'U4:U5'],
            'V' => ['text' => 'Department', 'merge' => 'V4:V5'],
            'W' => ['text' => 'Status', 'merge' => 'W4:W5']
        ];

        foreach ($headers_config as $col => $info) {
            $sheet->mergeCells($info['merge']);
            $sheet->setCellValue($col . '4', $info['text']);
        }

        // Subheaders for Dead stock Register in Row 5
        $sheet->setCellValue('B5', 'Vol.');
        $sheet->setCellValue('C5', 'Page No');
        $sheet->setCellValue('D5', 'Item No');

        // Style header cells
        $sheet->getStyle('A4:W5')->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle('A4:W5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A4:W5')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:W5')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A4:W5')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Fill Row 6 numbers
        for ($c = 1; $c <= 23; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $sheet->setCellValue($colLetter . '6', $c);
            $sheet->getStyle($colLetter . '6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($colLetter . '6')->getFont()->setItalic(true)->setSize(9);
            $sheet->getStyle($colLetter . '6')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        // Separate assets
        $written_off_raw = [];
        $marked_raw = [];
        $candidate_raw = [];
        foreach ($assets as $asset) {
            $status_lower = strtolower(trim($asset['status']));
            if ($asset['retire_at'] !== null) {
                $written_off_raw[] = $asset;
            } elseif ($status_lower === 'not working' || $status_lower === 'under maintenance' || $status_lower === 'missing') {
                $marked_raw[] = $asset;
            } else {
                $candidate_raw[] = $asset;
            }
        }

        $sections = [
            ['title' => 'PART 1: WRITTEN-OFF ASSETS (ARCHIVED)', 'data' => $written_off_raw],
            ['title' => 'PART 2: MARKED ASSETS (Under Maintenance / Not Working / Missing)', 'data' => $marked_raw],
            ['title' => 'PART 3: WRITE-OFF CANDIDATES (ACTIVE)', 'data' => $candidate_raw],
        ];

        $rowIdx = 7;
        $srNo = 1;
        $today = new DateTime('today');

        foreach ($sections as $section) {
            if (count($section['data']) === 0) continue;
            $sheet->mergeCells("A{$rowIdx}:W{$rowIdx}");
            $sheet->setCellValue("A{$rowIdx}", $section['title']);
            $sheet->getStyle("A{$rowIdx}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
            $sheet->getStyle("A{$rowIdx}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4B5563');
            $rowIdx++;
            $grouped = group_assets_by_item($section['data']);
            foreach ($grouped as $item_no => $group) {
                $cat_name = $category_names[$group['category_id']] ?? 'Unknown';
                $items_count = count($group['items']);
                
                $min_date_fmt = date('d/m/Y', strtotime($group['date_of_issue_min']));
                $max_date_fmt = date('d/m/Y', strtotime($group['date_of_issue_max']));
                $date_range_str = ($min_date_fmt === $max_date_fmt) ? $min_date_fmt : ($min_date_fmt . ' - ' . $max_date_fmt);

                // Write Group Summary Row
                $sheet->setCellValue('A' . $rowIdx, $srNo++);
                // Dead stock cols: Page in C, Item in D
                $sheet->setCellValueExplicit('C' . $rowIdx, (string)$group['page_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D' . $rowIdx, (string)$item_no, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                
                $sheet->setCellValue('E' . $rowIdx, "GROUP SUMMARY: " . $group['asset_name']);
                $sheet->setCellValue('F' . $rowIdx, $cat_name);
                $sheet->setCellValue('G' . $rowIdx, $items_count);
                
                $sheet->setCellValue('J' . $rowIdx, $date_range_str);
                $sheet->setCellValue('S' . $rowIdx, "");
                $sheet->setCellValue('T' . $rowIdx, "All items under Item No: " . $item_no);
                $sheet->setCellValue('U' . $rowIdx, "");
                $sheet->setCellValue('V' . $rowIdx, 'Computer');
                $sheet->setCellValue('W' . $rowIdx, get_group_status($group['items']));

                $sheet->getStyle('A' . $rowIdx . ':W' . $rowIdx)->getFont()->setBold(true);
                $sheet->getStyle('A' . $rowIdx . ':W' . $rowIdx)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
                $rowIdx++;

                // Write Sub-rows for each detailed asset
                foreach ($group['items'] as $sub_asset) {
                    $sheet->setCellValue('G' . $rowIdx, 1);
                    $sheet->setCellValue('I' . $rowIdx, (float)$sub_asset['cost']);
                    $sheet->setCellValue('J' . $rowIdx, date('d/m/Y', strtotime($sub_asset['date_of_issue'])));
                    $sheet->setCellValue('K' . $rowIdx, get_dates_unserviceable($sub_asset['status'], $sub_asset['status_marked_at'], $sub_asset['retire_at']));
                    $sheet->setCellValue('M' . $rowIdx, get_unserviceable_period($sub_asset['status'], $sub_asset['status_marked_at'], $sub_asset['retire_at']));
                    $sheet->setCellValue('N' . $rowIdx, get_period_of_use_display($sub_asset['date_of_issue'], $sub_asset['status'], $sub_asset['status_marked_at'], $sub_asset['retire_at']));
                    $sheet->setCellValue('S' . $rowIdx, "");
                    $sheet->setCellValue('T' . $rowIdx, $sub_asset['status_marked_note'] ?: ($sub_asset['remarks'] ?: ''));
                    $sheet->setCellValue('U' . $rowIdx, "");
                    $sheet->setCellValue('V' . $rowIdx, 'Computer');
                    $sheet->setCellValue('W' . $rowIdx, format_asset_status($sub_asset['status'], $sub_asset['retire_at']));

                    // E column has sub-item label
                    $sheet->setCellValue('E' . $rowIdx, "  ↳ " . $sub_asset['asset_name'] . " (" . ($sub_asset['asset_no'] ?: 'N/A') . ")");
                    $sheet->getStyle('E' . $rowIdx . ':W' . $rowIdx)->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
                    $sheet->getStyle('I' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
                    $rowIdx++;
                }
            }
        }
        $maxCol = 'W';
    } else {
        $maxCol = $is_history ? 'M' : 'L';
        $titleText = $is_history ? 'K.D. Polytechnic, Patan — Archived Written-Off Assets Report' : 'K.D. Polytechnic, Patan — Write-Off Assets Candidates Report (5+ Years Old)';
        $sheet->mergeCells("A1:{$maxCol}1");
        $sheet->setCellValue('A1', $titleText);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $cat_names_str = implode(', ', array_map(function($id) use ($category_names) { return $category_names[$id] ?? ''; }, $selected_categories));
        $sheet->mergeCells("A2:{$maxCol}2");
        $sheet->setCellValue('A2', 'Categories: ' . $cat_names_str . ' | Generated: ' . date('d/m/Y H:i') . ' | Total Items: ' . count($assets));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $headers = $is_history ? ['Sr No', 'Category', 'Asset No', 'Asset Name', 'Item No', 'Page No', 'Issue Date', 'Written-Off Date', 'Age (Years)', 'Cost (₹)', 'Location / Faculty', 'Status', 'Remarks'] : ['Sr No', 'Category', 'Asset No', 'Asset Name', 'Item No', 'Page No', 'Issue Date', 'Age (Years)', 'Cost (₹)', 'Location / Faculty', 'Status', 'Remarks'];
        $col = 'A';
        foreach ($headers as $h) {
            $cell = $col . '4';
            $sheet->setCellValue($cell, $h);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
            $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $col++;
        }
        $grouped_assets = group_assets_by_item($assets);
        $rowIdx = 5; $srNo = 1; $today = new DateTime('today');
        foreach ($grouped_assets as $item_no => $group) {
            $cat_name = $category_names[$group['category_id']] ?? 'Unknown';
            $items_count = count($group['items']);
            $locations_str = implode(', ', $group['locations']);
            $date_range_str = date('d/m/Y', strtotime($group['date_of_issue_min'])) . ' - ' . date('d/m/Y', strtotime($group['date_of_issue_max']));
            if ($is_history) {
                $sheet->setCellValue('A' . $rowIdx, $srNo++);
                $sheet->setCellValue('B' . $rowIdx, $cat_name);
                $sheet->setCellValue('C' . $rowIdx, "GROUP SUMMARY (" . $items_count . " items)");
                $sheet->setCellValue('D' . $rowIdx, $group['asset_name']);
                $sheet->setCellValueExplicit('E' . $rowIdx, (string)$item_no, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('F' . $rowIdx, (string)$group['page_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('G' . $rowIdx, $date_range_str, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('H' . $rowIdx, "N/A");
                $sheet->setCellValue('I' . $rowIdx, "-");
                $sheet->setCellValue('J' . $rowIdx, (float)$group['total_cost']);
                $sheet->setCellValue('K' . $rowIdx, $locations_str);
                $sheet->setCellValue('L' . $rowIdx, get_group_status($group['items']));
                $sheet->setCellValue('M' . $rowIdx, "All items under Item No: " . $item_no);
                $sheet->getStyle('A' . $rowIdx . ':M' . $rowIdx)->getFont()->setBold(true);
                $sheet->getStyle('A' . $rowIdx . ':M' . $rowIdx)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
                $sheet->getStyle('J' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            } else {
                $sheet->setCellValue('A' . $rowIdx, $srNo++);
                $sheet->setCellValue('B' . $rowIdx, $cat_name);
                $sheet->setCellValue('C' . $rowIdx, "GROUP SUMMARY (" . $items_count . " items)");
                $sheet->setCellValue('D' . $rowIdx, $group['asset_name']);
                $sheet->setCellValueExplicit('E' . $rowIdx, (string)$item_no, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('F' . $rowIdx, (string)$group['page_no'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('G' . $rowIdx, $date_range_str, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('H' . $rowIdx, "-");
                $sheet->setCellValue('I' . $rowIdx, (float)$group['total_cost']);
                $sheet->setCellValue('J' . $rowIdx, $locations_str);
                $sheet->setCellValue('K' . $rowIdx, get_group_status($group['items']));
                $sheet->setCellValue('L' . $rowIdx, "All items under Item No: " . $item_no);
                $sheet->getStyle('A' . $rowIdx . ':L' . $rowIdx)->getFont()->setBold(true);
                $sheet->getStyle('A' . $rowIdx . ':L' . $rowIdx)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
                $sheet->getStyle('I' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $rowIdx++;
            foreach ($group['items'] as $sub_asset) {
                $issue_time = strtotime($sub_asset['date_of_issue']);
                $issue_date_fmt = $issue_time ? date('d/m/Y', $issue_time) : 'N/A';
                $retire_time = !empty($sub_asset['retire_at']) ? strtotime($sub_asset['retire_at']) : null;
                $retire_date_fmt = $retire_time ? date('d/m/Y H:i', $retire_time) : 'N/A';
                $age = $issue_time ? date_diff(date_create($sub_asset['date_of_issue']), $today)->y : 0;
                $holder = $sub_asset['location'] ?: ($sub_asset['assigned_to'] ?: 'N/A');
                if ($is_history) {
                    $sheet->setCellValue('A' . $rowIdx, "");
                    $sheet->setCellValue('B' . $rowIdx, "");
                    $sheet->setCellValueExplicit('C' . $rowIdx, "  ↳ " . ($sub_asset['asset_no'] ?: 'N/A'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('D' . $rowIdx, "  [Sub-Item] " . $sub_asset['asset_name']);
                    $sheet->setCellValue('E' . $rowIdx, "");
                    $sheet->setCellValue('F' . $rowIdx, "");
                    $sheet->setCellValueExplicit('G' . $rowIdx, $issue_date_fmt, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('H' . $rowIdx, $retire_date_fmt, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('I' . $rowIdx, $age);
                    $sheet->setCellValue('J' . $rowIdx, (float)$sub_asset['cost']);
                    $sheet->setCellValue('K' . $rowIdx, $holder);
                    $sheet->setCellValue('L' . $rowIdx, format_asset_status($sub_asset['status'], $sub_asset['retire_at']));
                    $sheet->setCellValue('M' . $rowIdx, $sub_asset['remarks'] ?: '');
                    $sheet->getStyle('C' . $rowIdx . ':M' . $rowIdx)->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
                    $sheet->getStyle('J' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
                } else {
                    $sheet->setCellValue('A' . $rowIdx, "");
                    $sheet->setCellValue('B' . $rowIdx, "");
                    $sheet->setCellValueExplicit('C' . $rowIdx, "  ↳ " . ($sub_asset['asset_no'] ?: 'N/A'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('D' . $rowIdx, "  [Sub-Item] " . $sub_asset['asset_name']);
                    $sheet->setCellValue('E' . $rowIdx, "");
                    $sheet->setCellValue('F' . $rowIdx, "");
                    $sheet->setCellValueExplicit('G' . $rowIdx, $issue_date_fmt, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('H' . $rowIdx, $age);
                    $sheet->setCellValue('I' . $rowIdx, (float)$sub_asset['cost']);
                    $sheet->setCellValue('J' . $rowIdx, $holder);
                    $sheet->setCellValue('K' . $rowIdx, format_asset_status($sub_asset['status'], $sub_asset['retire_at']));
                    $sheet->setCellValue('L' . $rowIdx, $sub_asset['remarks'] ?: '');
                    $sheet->getStyle('C' . $rowIdx . ':L' . $rowIdx)->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
                    $sheet->getStyle('I' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $rowIdx++;
            }
        }
    }
    foreach (range('A', $maxCol) as $colId) {
        $sheet->getColumnDimension($colId)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
} else {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . str_replace('.xlsx', '.xls', $filename) . "\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    echo '<style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; }
        th { background-color: #2563eb; color: #ffffff; font-weight: bold; border: 1px solid #1d4ed8; padding: 8px; text-align: center; }
        td { border: 1px solid #e2e8f0; padding: 6px 8px; vertical-align: top; mso-number-format: "\@"; }
        tr:nth-child(even) td { background-color: #f8fafc; }
        .title { font-size: 14px; font-weight: bold; text-align: center; padding: 10px; color: #ff0000; }
        .subtitle { font-size: 10px; color: #64748b; text-align: center; padding: 6px; }
    </style></head><body><table>';

    $cat_names_str = implode(', ', array_map(function($id) use ($category_names) {
        return $category_names[$id] ?? '';
    }, $selected_categories));

    if ($is_all) {
        // --- CUSTOM 23-COLUMN STRUCTURE FOR HTML FALLBACK ---
        echo '<tr><td colspan="23" class="title">Institute name - K.D. Polytechnic, Patan</td></tr>';
        echo '<tr><td colspan="23" style="font-weight:bold; font-size:12px; color:#ff0000; text-align:center; padding:10px;">Details for Write off e-waste (Above Rs. 20,000/-)</td></tr>';
        echo '<tr><td colspan="23" class="subtitle">Categories: ' . htmlspecialchars($cat_names_str) . ' | Generated: ' . date('d/m/Y H:i') . ' | Total Items: ' . count($assets) . '</td></tr>';
        echo '<tr><td colspan="23"></td></tr>';

        // Table Headers
        echo '<tr>';
        echo '<th rowspan="2">Sr No</th>';
        echo '<th colspan="3">Details of Dead stock Register</th>';
        echo '<th rowspan="2">Name of Articles</th>';
        echo '<th rowspan="2">Nature of Articles</th>';
        echo '<th rowspan="2">Qty.</th>';
        echo '<th rowspan="2">Name of Authority who allowed for purchase of articles.</th>';
        echo '<th rowspan="2">Price of each articles Rs. = Ps.</th>';
        echo '<th rowspan="2">Date of Purchase</th>';
        echo '<th rowspan="2">Dates of which articles rendered unserviceable</th>';
        echo '<th rowspan="2">How the articles becomes unserviceable</th>';
        echo '<th rowspan="2">The actual period during which articles become unserviceable</th>';
        echo '<th rowspan="2">Period of use in Years</th>';
        echo '<th rowspan="2">Whether responsibility can be fixed for misuse of early wear.</th>';
        echo '<th rowspan="2">Whether defects can be repaired.</th>';
        echo '<th rowspan="2">If so when what will be test of repair</th>';
        echo '<th rowspan="2">No. of Articles recommended for written off. by the Deputy Director</th>';
        echo '<th rowspan="2">Total Amount articles recommended written off. Rs. = P</th>';
        echo '<th rowspan="2">Description defects develop or actual condition of the item</th>';
        echo '<th rowspan="2">Grant Total Rs. = P</th>';
        echo '<th rowspan="2">Department</th>';
        echo '<th rowspan="2">Status</th>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Vol.</th>';
        echo '<th>Page No</th>';
        echo '<th>Item No</th>';
        echo '</tr>';

        // Row 6 numbering
        echo '<tr>';
        for ($c = 1; $c <= 23; $c++) {
            echo '<td style="text-align:center; font-style:italic; font-size:9px; font-weight:bold; background-color:#f1f5f9;">' . $c . '</td>';
        }
        echo '</tr>';

        // Separate assets
        $written_off_raw = [];
        $marked_raw = [];
        $candidate_raw = [];
        foreach ($assets as $asset) {
            $status_lower = strtolower(trim($asset['status']));
            if ($asset['retire_at'] !== null) {
                $written_off_raw[] = $asset;
            } elseif ($status_lower === 'not working' || $status_lower === 'under maintenance' || $status_lower === 'missing') {
                $marked_raw[] = $asset;
            } else {
                $candidate_raw[] = $asset;
            }
        }

        $sections = [
            ['title' => 'PART 1: WRITTEN-OFF ASSETS (ARCHIVED)', 'data' => $written_off_raw],
            ['title' => 'PART 2: MARKED ASSETS (Under Maintenance / Not Working / Missing)', 'data' => $marked_raw],
            ['title' => 'PART 3: WRITE-OFF CANDIDATES (ACTIVE)', 'data' => $candidate_raw],
        ];

        $srNo = 1;
        $today = new DateTime('today');

        foreach ($sections as $section) {
            if (count($section['data']) === 0) continue;

            echo '<tr><td colspan="23" style="font-weight:bold; background-color:#4b5563; color:#ffffff; padding:8px;">' . htmlspecialchars($section['title']) . '</td></tr>';

            $grouped = group_assets_by_item($section['data']);
            foreach ($grouped as $item_no => $group) {
                $cat_name = $category_names[$group['category_id']] ?? 'Unknown';
                $items_count = count($group['items']);
                
                $min_date_fmt = date('d/m/Y', strtotime($group['date_of_issue_min']));
                $max_date_fmt = date('d/m/Y', strtotime($group['date_of_issue_max']));
                $date_range_str = ($min_date_fmt === $max_date_fmt) ? $min_date_fmt : ($min_date_fmt . ' - ' . $max_date_fmt);

                // Group summary row
                echo '<tr style="font-weight:bold; background-color:#eff6ff;">';
                echo '<td style="text-align:center;">' . $srNo++ . '</td>';
                echo '<td></td>'; // Vol
                echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars((string)$group['page_no']) . '</td>';
                echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars((string)$item_no) . '</td>';
                echo '<td>GROUP SUMMARY: ' . htmlspecialchars((string)$group['asset_name']) . '</td>';
                echo '<td>' . htmlspecialchars((string)$cat_name) . '</td>';
                echo '<td style="text-align:center;">' . $items_count . '</td>';
                echo '<td></td>'; // Authority
                echo '<td></td>'; // Price
                echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars($date_range_str) . '</td>';
                echo '<td></td>'; // Dates unserviceable
                echo '<td></td>'; // How unserviceable
                echo '<td></td>'; // Actual period
                echo '<td></td>'; // Period of use
                echo '<td></td>'; // Responsibility
                echo '<td></td>'; // Defects repaired
                echo '<td></td>'; // Repair test
                echo '<td></td>'; // No of articles recommended
                echo '<td></td>'; // Total Amount (Col 19 - remains empty)
                echo '<td>All items under Item No: ' . htmlspecialchars((string)$item_no) . '</td>';
                echo '<td></td>'; // Grant Total (Col 21 - remains empty)
                echo '<td>Computer</td>';
                echo '<td>' . htmlspecialchars((string)get_group_status($group['items'])) . '</td>';
                echo '</tr>';

                // Sub-item rows
                foreach ($group['items'] as $sub_asset) {
                    $unserv_dates = get_dates_unserviceable($sub_asset['status'], $sub_asset['status_marked_at'], $sub_asset['retire_at']);
                    $unserv_period = get_unserviceable_period($sub_asset['status'], $sub_asset['status_marked_at'], $sub_asset['retire_at']);
                    $use_period = get_period_of_use_display($sub_asset['date_of_issue'], $sub_asset['status'], $sub_asset['status_marked_at'], $sub_asset['retire_at']);
                    $defects = $sub_asset['status_marked_note'] ?: ($sub_asset['remarks'] ?: '');

                    echo '<tr style="color:#64748b; font-style:italic;">';
                    echo '<td></td>';
                    echo '<td></td>'; // Vol
                    echo '<td></td>'; // Page
                    echo '<td></td>'; // Item No
                    echo '<td>&nbsp;&nbsp;↳ ' . htmlspecialchars((string)$sub_asset['asset_name']) . ' (' . htmlspecialchars((string)$sub_asset['asset_no']) . ')</td>';
                    echo '<td></td>'; // Nature
                    echo '<td style="text-align:center;">1</td>';
                    echo '<td></td>'; // Authority
                    echo '<td style="text-align:right;">' . number_format((float)$sub_asset['cost'], 2) . '</td>';
                    echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars(date('d/m/Y', strtotime($sub_asset['date_of_issue']))) . '</td>';
                    echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars($unserv_dates) . '</td>';
                    echo '<td></td>'; // How unserviceable
                    echo '<td style="text-align:center;">' . htmlspecialchars($unserv_period) . '</td>';
                    echo '<td style="text-align:center;">' . htmlspecialchars($use_period) . '</td>';
                    echo '<td></td>'; // Responsibility
                    echo '<td></td>'; // Defects repaired
                    echo '<td></td>'; // Repair test
                    echo '<td></td>'; // Recommended count
                    echo '<td></td>'; // Total Amount (Col 19 - remains empty)
                    echo '<td>' . htmlspecialchars((string)$defects) . '</td>';
                    echo '<td></td>'; // Grant Total (Col 21 - remains empty)
                    echo '<td>Computer</td>';
                    echo '<td>' . htmlspecialchars((string)format_asset_status($sub_asset['status'], $sub_asset['retire_at'])) . '</td>';
                    echo '</tr>';
                }
            }
        }
    } else {
        // --- OLD PAGE-WISE HTML EXPORT STRUCTURE ---
        $cols_count = $is_history ? 13 : 12;
        $title_text = $is_history 
            ? 'K.D. Polytechnic, Patan — Archived Written-Off Assets Report'
            : 'K.D. Polytechnic, Patan — Write-Off Assets Candidates Report (5+ Years Old)';

        echo '<tr><td colspan="' . $cols_count . '" class="title">' . htmlspecialchars($title_text) . '</td></tr>';
        echo '<tr><td colspan="' . $cols_count . '" class="subtitle">Categories: ' . htmlspecialchars($cat_names_str) . ' | Generated: ' . date('d/m/Y H:i') . ' | Total Items: ' . count($assets) . '</td></tr>';
        echo '<tr><td colspan="' . $cols_count . '"></td></tr>';

        if ($is_history) {
            echo '<tr>
                <th>Sr No</th>
                <th>Category</th>
                <th>Asset No</th>
                <th>Asset Name</th>
                <th>Item No</th>
                <th>Page No</th>
                <th>Issue Date</th>
                <th>Written-Off Date</th>
                <th>Age (Years)</th>
                <th>Cost (₹)</th>
                <th>Location / Faculty</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>';
        } else {
            echo '<tr>
                <th>Sr No</th>
                <th>Category</th>
                <th>Asset No</th>
                <th>Asset Name</th>
                <th>Item No</th>
                <th>Page No</th>
                <th>Issue Date</th>
                <th>Age (Years)</th>
                <th>Cost (₹)</th>
                <th>Location / Faculty</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>';
        }

        $grouped_assets = group_assets_by_item($assets);
        $srNo = 1;
        $today = new DateTime('today');
        foreach ($grouped_assets as $item_no => $group) {
            $cat_name = $category_names[$group['category_id']] ?? 'Unknown';
            $items_count = count($group['items']);
            $locations_str = implode(', ', $group['locations']);
            
            $min_date_fmt = date('d/m/Y', strtotime($group['date_of_issue_min']));
            $max_date_fmt = date('d/m/Y', strtotime($group['date_of_issue_max']));
            $date_range_str = ($min_date_fmt === $max_date_fmt) ? $min_date_fmt : ($min_date_fmt . ' - ' . $max_date_fmt);

            echo '<tr style="font-weight:bold; background-color:#eff6ff;">';
            echo '<td style="text-align:center;">' . $srNo++ . '</td>';
            echo '<td>' . htmlspecialchars($cat_name) . '</td>';
            echo '<td style="font-weight:bold;">GROUP SUMMARY (' . $items_count . ' items)</td>';
            echo '<td>' . htmlspecialchars((string)$group['asset_name']) . '</td>';
            echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars((string)$item_no) . '</td>';
            echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars((string)$group['page_no']) . '</td>';
            echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars($date_range_str) . '</td>';
            if ($is_history) {
                echo '<td style="text-align:center;">N/A</td>';
            }
            echo '<td style="text-align:center;">-</td>';
            echo '<td style="text-align:right;">' . number_format((float)$group['total_cost'], 2) . '</td>';
            echo '<td>' . htmlspecialchars((string)$locations_str) . '</td>';
            echo '<td>' . htmlspecialchars((string)get_group_status($group['items'])) . '</td>';
            echo '<td>All items under Item No: ' . htmlspecialchars((string)$item_no) . '</td>';
            echo '</tr>';

            foreach ($group['items'] as $sub_asset) {
                $issue_time = strtotime($sub_asset['date_of_issue']);
                $issue_date_fmt = $issue_time ? date('d/m/Y', $issue_time) : 'N/A';
                $retire_time = !empty($sub_asset['retire_at']) ? strtotime($sub_asset['retire_at']) : null;
                $retire_date_fmt = $retire_time ? date('d/m/Y H:i', $retire_time) : 'N/A';
                $age = $issue_time ? date_diff(date_create($sub_asset['date_of_issue']), $today)->y : 0;
                $holder = $sub_asset['location'] ?: ($sub_asset['assigned_to'] ?: 'N/A');

                echo '<tr style="color:#64748b; font-style:italic;">';
                echo '<td style="text-align:center;"></td>';
                echo '<td></td>';
                echo '<td style="mso-number-format:\'\@\'; font-family:monospace; padding-left:15px;">&nbsp;&nbsp;↳ ' . htmlspecialchars((string)$sub_asset['asset_no']) . '</td>';
                echo '<td>&nbsp;&nbsp;[Sub-Item] ' . htmlspecialchars((string)$sub_asset['asset_name']) . '</td>';
                echo '<td style="text-align:center;"></td>';
                echo '<td style="text-align:center;"></td>';
                echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars($issue_date_fmt) . '</td>';
                if ($is_history) {
                    echo '<td style="text-align:center; mso-number-format:\'\@\';">' . htmlspecialchars($retire_date_fmt) . '</td>';
                }
                echo '<td style="text-align:center;">' . $age . '</td>';
                echo '<td style="text-align:right;">' . number_format((float)$sub_asset['cost'], 2) . '</td>';
                echo '<td>' . htmlspecialchars((string)$holder) . '</td>';
                echo '<td>' . htmlspecialchars((string)format_asset_status($sub_asset['status'], $sub_asset['retire_at'])) . '</td>';
                echo '<td>' . htmlspecialchars((string)($sub_asset['remarks'] ?: '')) . '</td>';
                echo '</tr>';
            }
        }
    }

    echo '</table></body></html>';
    exit();
}
