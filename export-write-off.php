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

$tab = $_POST['tab'] ?? $_GET['tab'] ?? 'candidates';
$is_history = ($tab === 'history');

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

// Prepare SQL query based on mode (Candidates vs History)
$in_clause = implode(',', array_fill(0, count($selected_categories), '?'));

if ($is_history) {
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
            batch_id
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
            batch_id
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

$filename_prefix = $is_history ? "Written_Off_Assets_History_" : "Write_Off_Assets_Candidates_";
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
    $sheet->setTitle($is_history ? 'Written-Off Assets' : 'Write-Off Candidates');

    // Title Row
    $maxCol = $is_history ? 'M' : 'L';
    $titleText = $is_history 
        ? 'K.D. Polytechnic, Patan — Archived Written-Off Assets Report'
        : 'K.D. Polytechnic, Patan — Write-Off Assets Candidates Report (5+ Years Old)';

    $sheet->mergeCells("A1:{$maxCol}1");
    $sheet->setCellValue('A1', $titleText);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Subtitle Row
    $cat_names_str = implode(', ', array_map(function($id) use ($category_names) {
        return $category_names[$id] ?? '';
    }, $selected_categories));

    $sheet->mergeCells("A2:{$maxCol}2");
    $sheet->setCellValue('A2', 'Categories: ' . $cat_names_str . ' | Generated: ' . date('d/m/Y H:i') . ' | Total Items: ' . count($assets));
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Table Headers
    if ($is_history) {
        $headers = [
            'Sr No', 'Category', 'Asset No', 'Asset Name', 'Item No',
            'Page No', 'Issue Date', 'Written-Off Date', 'Age (Years)', 'Cost (₹)', 'Location / Faculty', 'Status', 'Remarks'
        ];
    } else {
        $headers = [
            'Sr No', 'Category', 'Asset No', 'Asset Name', 'Item No',
            'Page No', 'Issue Date', 'Age (Years)', 'Cost (₹)', 'Location / Faculty', 'Status', 'Remarks'
        ];
    }

    $col = 'A';
    foreach ($headers as $h) {
        $cell = $col . '4';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $col++;
    }

    // Group by item_no
    $grouped_assets = [];
    foreach ($assets as $asset) {
        $item_no = $asset['item_no'] ?: 'Uncategorized';
        if (!isset($grouped_assets[$item_no])) {
            $grouped_assets[$item_no] = [
                'item_no' => $item_no,
                'asset_name' => $asset['asset_name'],
                'category_id' => $asset['category_id'],
                'page_no' => $asset['page_no'],
                'date_of_issue_min' => $asset['date_of_issue'],
                'date_of_issue_max' => $asset['date_of_issue'],
                'total_cost' => 0,
                'status' => $asset['status'] ?: ($is_history ? 'Retired' : 'Active'),
                'locations' => [],
                'items' => []
            ];
        }
        $grouped_assets[$item_no]['items'][] = $asset;
        $grouped_assets[$item_no]['total_cost'] += (float)$asset['cost'];
        $loc = $asset['location'] ?: ($asset['assigned_to'] ?: 'N/A');
        if (!in_array($loc, $grouped_assets[$item_no]['locations'])) {
            $grouped_assets[$item_no]['locations'][] = $loc;
        }
        if (strtotime($asset['date_of_issue']) < strtotime($grouped_assets[$item_no]['date_of_issue_min'])) {
            $grouped_assets[$item_no]['date_of_issue_min'] = $asset['date_of_issue'];
        }
        if (strtotime($asset['date_of_issue']) > strtotime($grouped_assets[$item_no]['date_of_issue_max'])) {
            $grouped_assets[$item_no]['date_of_issue_max'] = $asset['date_of_issue'];
        }
    }

    // Data rows
    $rowIdx = 5;
    $srNo = 1;
    $today = new DateTime('today');

    foreach ($grouped_assets as $item_no => $group) {
        $cat_name = $category_names[$group['category_id']] ?? 'Unknown';
        $items_count = count($group['items']);
        $locations_str = implode(', ', $group['locations']);
        $date_range_str = date('d/m/Y', strtotime($group['date_of_issue_min'])) . ' - ' . date('d/m/Y', strtotime($group['date_of_issue_max']));

        // Write Group Summary Row
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
            $sheet->setCellValue('L' . $rowIdx, $group['status']);
            $sheet->setCellValue('M' . $rowIdx, "All items under Item No: " . $item_no);

            // Style summary row
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
            $sheet->setCellValue('K' . $rowIdx, $group['status']);
            $sheet->setCellValue('L' . $rowIdx, "All items under Item No: " . $item_no);

            // Style summary row
            $sheet->getStyle('A' . $rowIdx . ':L' . $rowIdx)->getFont()->setBold(true);
            $sheet->getStyle('A' . $rowIdx . ':L' . $rowIdx)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
            $sheet->getStyle('I' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $rowIdx++;

        // Write Sub-rows for each detailed asset
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
                $sheet->setCellValue('L' . $rowIdx, $sub_asset['status'] ?: 'Retired');
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
                $sheet->setCellValue('K' . $rowIdx, $sub_asset['status'] ?: 'Active');
                $sheet->setCellValue('L' . $rowIdx, $sub_asset['remarks'] ?: '');

                $sheet->getStyle('C' . $rowIdx . ':L' . $rowIdx)->getFont()->setItalic(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
                $sheet->getStyle('I' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $rowIdx++;
        }
    }

    // Auto-fit columns
    foreach (range('A', $maxCol) as $colId) {
        $sheet->getColumnDimension($colId)->setAutoSize(true);
    }

    // Set download headers
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
} else {
    // Fallback: Styled HTML Excel download (.xls)
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
        .title { background-color: #1e3a8a; color: #ffffff; font-size: 14px; font-weight: bold; text-align: center; padding: 10px; }
        .subtitle { font-size: 10px; color: #64748b; text-align: center; padding: 6px; }
    </style></head><body><table>';

    $cat_names_str = implode(', ', array_map(function($id) use ($category_names) {
        return $category_names[$id] ?? '';
    }, $selected_categories));

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

    $srNo = 1;
    $today = new DateTime('today');
    foreach ($grouped_assets as $item_no => $group) {
        $cat_name = $category_names[$group['category_id']] ?? 'Unknown';
        $items_count = count($group['items']);
        $locations_str = implode(', ', $group['locations']);
        $date_range_str = date('d/m/Y', strtotime($group['date_of_issue_min'])) . ' - ' . date('d/m/Y', strtotime($group['date_of_issue_max']));

        // Print Group Summary Row
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
        echo '<td>' . htmlspecialchars((string)$group['status']) . '</td>';
        echo '<td>All items under Item No: ' . htmlspecialchars((string)$item_no) . '</td>';
        echo '</tr>';

        // Print Sub-rows for each detailed asset
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
            echo '<td>' . htmlspecialchars((string)($sub_asset['status'] ?: ($is_history ? 'Retired' : 'Active'))) . '</td>';
            echo '<td>' . htmlspecialchars((string)($sub_asset['remarks'] ?: '')) . '</td>';
            echo '</tr>';
        }
    }

    echo '</table></body></html>';
    exit();
}
