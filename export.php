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
    'page_no', 'gem_order_no', 'gem_invoice_no', 'gpr_no', 'pr_page_no', 'gpr_item_no'
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

$sql = "SELECT " . $sql_columns . " FROM assets";

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

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY date_of_issue DESC, asset_name ASC";

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
</head><body>';

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

// Data rows
$row_num = 1;
while ($row = $result->fetch_assoc()) {
    echo '<tr>';
    echo '<td style="text-align:center;color:#888;">' . $row_num++ . '</td>';
    foreach ($selected_columns as $col) {
        $val = $row[$col] ?? '';
        // Category: convert ID → name
        if ($col === 'category_id') {
            $val = $categories[$val] ?? $val;
        }
        // Date: force text format to prevent Excel from hiding it
        if ($col === 'date_of_issue') {
            echo '<td style="mso-number-format:\'\@\';">' . htmlspecialchars((string)$val) . '</td>';
            continue;
        }
        echo '<td>' . nl2br(htmlspecialchars((string)$val)) . '</td>';
    }
    echo '</tr>';
}

echo '</table></body></html>';

$stmt->close();
$conn->close();
exit();
?>