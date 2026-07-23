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
$category_id = $_POST['category_id'] ?? 'all';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';
$selected_columns = $_POST['columns'] ?? [];

if (empty($selected_columns)) {
    header("Location: dashboard.php?view=generate-report&error=no_columns");
    exit();
}

// --- Build the SQL query ---
$sql_columns = implode(", ", array_map(function ($col) {
    // Basic sanitization for column names
    return "`" . str_replace("`", "", $col) . "`";
}, $selected_columns));

$sql = "SELECT " . $sql_columns . " FROM assets";

$where_clauses = [];
$params = [];
$types = '';

// Category filter
if ($category_id !== 'all' && is_numeric($category_id)) {
    $where_clauses[] = "category_id = ?";
    $types .= 'i';
    $params[] = $category_id;
}

// Date range filter
if (!empty($start_date)) {
    $where_clauses[] = "date_of_issue >= ?";
    $types .= 's';
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $where_clauses[] = "date_of_issue <= ?";
    $types .= 's';
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

// --- Generate CSV file ---
$filename = "asset_report_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Add header row
fputcsv($output, $selected_columns);

// Add data rows
$categories = [1 => 'Expandable', 2 => 'Consumables', 3 => 'Deadstock', 4 => 'Furniture'];
while ($row = $result->fetch_assoc()) {
    // If category_id is one of the columns, replace it with the name
    if (isset($row['category_id']) && in_array('category_id', $selected_columns)) {
        $row['category_id'] = $categories[$row['category_id']] ?? 'Unknown';
    }
    fputcsv($output, $row);
}

fclose($output);
$stmt->close();
$conn->close();
exit();
?>