<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$query = isset($_GET['query']) ? trim($_GET['query']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

$searchTerm = "%" . $query . "%";

$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

$stmt = $conn->prepare("
    SELECT asset_name, category_id, location, assigned_to
    FROM assets 
    WHERE asset_name LIKE ? OR location LIKE ? OR asset_no LIKE ? OR assigned_to LIKE ?
    GROUP BY asset_name, category_id, location, assigned_to
    LIMIT 15
");
$stmt->bind_param("ssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm);
$stmt->execute();
$result = $stmt->get_result();

$assets = [];
while ($row = $result->fetch_assoc()) {
    $row['category_name'] = $categories[$row['category_id']] ?? 'Unknown';
    $assets[] = $row;
}

echo json_encode($assets);
$stmt->close();