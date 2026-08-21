<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?view=generate-report");
    exit();
}

// --- Get parameters from the form ---
$category_id      = $_POST['category_id']  ?? 'all';
$asset_name       = $_POST['asset_name']   ?? 'all';
$location         = $_POST['location']     ?? 'all';
$assigned_to      = $_POST['assigned_to']  ?? 'all';
$start_date       = $_POST['start_date']   ?? '';
$end_date         = $_POST['end_date']     ?? '';
$selected_columns = $_POST['columns']      ?? [];

if ($_SESSION['role'] === 'staff') {
    $assigned_to = $_SESSION['user_name'];
    $location = 'all';
}

// Whitelist of allowed column names (prevent SQL injection via column names)
$allowed_columns = [
    'asset_name', 'category_id', 'item_no', 'asset_no', 'quantity',
    'cost', 'location', 'date_of_issue', 'assigned_to', 'remarks',
    'page_no', 'gem_order_no', 'gem_invoice_no', 'gpr_no', 'pr_page_no', 'gpr_item_no',
    'borrowed_from'
];

// Filter to only allow whitelisted columns
$selected_columns = array_values(array_filter($selected_columns, function($col) use ($allowed_columns) {
    return in_array($col, $allowed_columns, true);
}));

if (empty($selected_columns)) {
    header("Location: dashboard.php?view=generate-report&error=no_columns");
    exit();
}

// --- Build the SQL query ---
$sql_columns = implode(", ", array_map(function ($col) {
    return "`" . $col . "`";
}, $selected_columns));

$where_clauses = [];
$params = [];
$types  = '';

// Category filter
if ($category_id !== 'all' && is_numeric($category_id)) {
    $where_clauses[] = "category_id = ?";
    $types  .= 'i';
    $params[] = (int)$category_id;
}

// Asset name filter
if ($asset_name !== 'all' && $asset_name !== '') {
    $where_clauses[] = "asset_name = ?";
    $types  .= 's';
    $params[] = $asset_name;
}

// Location filter
if ($location !== 'all' && $location !== '') {
    $where_clauses[] = "location = ?";
    $types  .= 's';
    $params[] = $location;
}

// Assigned-to (Faculty) filter
if ($assigned_to !== 'all' && $assigned_to !== '') {
    $where_clauses[] = "assigned_to = ?";
    $types  .= 's';
    $params[] = $assigned_to;
}

// Date range filter
if (!empty($start_date)) {
    $where_clauses[] = "date_of_issue >= ?";
    $types  .= 's';
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $where_clauses[] = "date_of_issue <= ?";
    $types  .= 's';
    $params[] = $end_date;
}

// Map selected columns onto each table separately (borrowed_assets lacks cost / GeM / borrowed_from)
$dept_available = ['asset_name', 'category_id', 'item_no', 'asset_no', 'quantity', 'cost', 'location', 'date_of_issue', 'assigned_to', 'remarks', 'page_no', 'gem_order_no', 'gem_invoice_no', 'gpr_no', 'pr_page_no', 'gpr_item_no'];
$borrowed_available = ['asset_name', 'category_id', 'item_no', 'asset_no', 'quantity', 'location', 'date_of_issue', 'assigned_to', 'remarks', 'page_no', 'borrowed_from'];

function build_report_columns(array $selected_columns, array $available): array {
    $out = [];
    foreach ($selected_columns as $col) {
        if ($col === 'cost') {
            $out[] = in_array('cost', $available, true) ? "`cost`" : "0 AS `cost`";
        } elseif (in_array($col, $available, true)) {
            $out[] = "`" . $col . "`";
        } else {
            $out[] = "NULL AS `" . $col . "`";
        }
    }
    return $out;
}

$dept_col_sql     = build_report_columns($selected_columns, $dept_available);
$borrowed_col_sql = build_report_columns($selected_columns, $borrowed_available);

// "Borrowed From" column option doubles as the include-borrowed toggle
$include_borrowed = in_array('borrowed_from', $selected_columns, true);

$where_text = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
$dept_sql = "SELECT " . implode(", ", $dept_col_sql) . " FROM assets" . $where_text;

if ($include_borrowed) {
    $borrowed_where_text = " WHERE " . implode(" AND ", array_merge(["(status IS NULL OR status <> 'Returned')"], $where_clauses));
    $borrowed_sql = "SELECT " . implode(", ", $borrowed_col_sql) . " FROM borrowed_assets" . $borrowed_where_text;

    $sql = "(" . $dept_sql . ") UNION ALL (" . $borrowed_sql . ") ORDER BY date_of_issue DESC, asset_name ASC";
    // Parameters apply to both UNION sides, so duplicate them
    $types  = $types . $types;
    $params = array_merge($params, $params);
} else {
    $sql = $dept_sql . " ORDER BY date_of_issue DESC, asset_name ASC";
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- Column display labels for the header row ---
$col_labels = [
    'asset_name'     => 'Asset Name',
    'category_id'    => 'Category',
    'item_no'        => 'Item No',
    'asset_no'       => 'Asset No',
    'quantity'       => 'Quantity',
    'cost'           => 'Cost',
    'location'       => 'Location',
    'date_of_issue'  => 'Date of Issue',
    'assigned_to'    => 'Assigned To',
    'remarks'        => 'Remarks',
    'page_no'        => 'Page No',
    'gem_order_no'   => 'GeM Order No',
    'gem_invoice_no' => 'GeM Invoice No',
    'gpr_no'         => 'GPR No',
    'pr_page_no'     => 'GPR Page No',
    'gpr_item_no'    => 'GPR Item No',
];
$header_row = array_map(fn($col) => $col_labels[$col] ?? $col, $selected_columns);

// --- Generate XLS-compatible HTML file (styled Excel export) ---
$filename = "asset_report_" . date('Y-m-d_His') . ".xls";

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$categories = [1 => 'Expandable', 2 => 'Consumables', 3 => 'Deadstock', 4 => 'Furniture'];

echo '<!DOCTYPE html><html><head>
<meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; font-size: 10pt; }
    table { border-collapse: collapse; width: 100%; }
    th {
        background-color: #1e3a5f; color: #ffffff;
        border: 1px solid #aaa; padding: 7px 10px;
        font-weight: bold; white-space: nowrap;
        mso-number-format: "\@";
    }
    td {
        border: 1px solid #cccccc; padding: 6px 9px;
        vertical-align: top; mso-number-format: "\@";
    }
    tr:nth-child(even) td { background-color: #f0f4f8; }
    tr:nth-child(odd) td  { background-color: #ffffff; }
    .report-title { font-size: 14pt; font-weight: bold; text-align: center; padding: 8px; background:#1e3a5f; color:#fff; }
    .report-sub { font-size: 9pt; text-align: center; color: #555; padding: 4px; }
</style>
<body>';
echo '<table>';
// Title row spanning all columns
$col_count = count($selected_columns) + 1; // +1 for # column
echo '<tr><td colspan="' . $col_count . '" class="report-title">K.D. Polytechnic — Asset Report</td></tr>';
echo '<tr><td colspan="' . $col_count . '" class="report-sub">Generated: ' . date('d/m/Y H:i') . '</td></tr>';
echo '<tr><td colspan="' . $col_count . '" style="padding:4px;"></td></tr>';

// Header row
echo '<tr><th>#</th>';
foreach ($header_row as $h) {
    echo '<th>' . htmlspecialchars($h) . '</th>';
}
echo '</tr>';

// Load all rows and group by item_no
$all_rows = [];
while ($row = $result->fetch_assoc()) {
    $all_rows[] = $row;
}

$grouped = [];
foreach ($all_rows as $row) {
    $key = $row['item_no'] ?? 'Uncategorized';
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'item_no'      => $key,
            'asset_name'   => $row['asset_name'] ?? '',
            'category_id'  => $row['category_id'] ?? '',
            'page_no'      => $row['page_no'] ?? '',
            'total_cost'   => 0.0,
            'total_qty'    => 0,
            'items'        => [],
        ];
    }
    $grouped[$key]['items'][]    = $row;
    $grouped[$key]['total_cost'] += (float)($row['cost'] ?? 0);
    $grouped[$key]['total_qty']  += (int)($row['quantity'] ?? 1);
}

// Data rows — summary + sub-rows
$row_num = 1;
foreach ($grouped as $item_no => $grp) {
    $item_count = count($grp['items']);

    // ── Summary / parent row ──
    echo '<tr style="font-weight:700; background:#dbeafe;">';
    echo '<td style="text-align:center;color:#64748b;">' . $row_num++ . '</td>';
    foreach ($selected_columns as $col) {
        $val = '';
        switch ($col) {
            case 'item_no':     $val = $item_no; break;
            case 'asset_name':  $val = $grp['asset_name']; break;
            case 'category_id': $val = $categories[$grp['category_id']] ?? $grp['category_id']; break;
            case 'page_no':     $val = $grp['page_no']; break;
            case 'quantity':    $val = $grp['total_qty']; break;
            case 'cost':        $val = number_format($grp['total_cost'], 2); break;
            case 'asset_no':    $val = 'GROUP SUMMARY (' . $item_count . ' item' . ($item_count !== 1 ? 's' : '') . ')'; break;
            default:
                $uniques = array_unique(array_filter(array_column($grp['items'], $col)));
                $val = implode(', ', array_slice($uniques, 0, 3));
                if (count($uniques) > 3) $val .= ' (+' . (count($uniques) - 3) . ' more)';
        }
        if ($col === 'date_of_issue') {
            echo '<td style="font-weight:700; mso-number-format:\'\@\';">' . htmlspecialchars((string)$val) . '</td>';
        } else {
            echo '<td>' . nl2br(htmlspecialchars((string)$val)) . '</td>';
        }
    }
    echo '</tr>';

    // ── Child / sub-rows ──
    foreach ($grp['items'] as $sub_idx => $sub) {
        echo '<tr style="background:#f8fafc; color:#475569; font-style:italic;">';
        echo '<td style="text-align:center;color:#cbd5e1;font-size:0.75em;">&nbsp;</td>';
        foreach ($selected_columns as $col) {
            $val = $sub[$col] ?? '';
            if ($col === 'category_id') $val = $categories[$val] ?? $val;
            if ($col === 'asset_no')    $val = '  ↳ ' . $val;
            if ($col === 'cost' && $val !== '') $val = number_format((float)$val, 2);
            if ($col === 'date_of_issue') {
                echo '<td style="mso-number-format:\'\@\'; color:#64748b;">' . htmlspecialchars((string)$val) . '</td>';
            } else {
                echo '<td style="color:#64748b;">' . nl2br(htmlspecialchars((string)$val)) . '</td>';
            }
        }
        echo '</tr>';
    }
}

echo '</table>';
echo '</body>';
echo '</html>';

$stmt->close();
$conn->close();
exit();
?>