<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Only admins can update borrowed assets.']);
    exit();
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid asset ID.']);
    exit();
}

$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : null;
$status = isset($_POST['status']) ? trim($_POST['status']) : null;
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;

if ($status === null && $location === null && $assigned_to === null && $remarks === null) {
    echo json_encode(['status' => 'error', 'message' => 'No fields to update.']);
    exit();
}

$allowed_statuses = ['active', 'Returned', 'Under Maintenance', 'Not Working', 'Missing'];

if ($status !== null && !in_array($status, $allowed_statuses, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid status value.']);
    exit();
}

$fields = [];
$types = '';
$params = [];

if ($location !== null) {
    $fields[] = 'location = ?';
    $types .= 's';
    $params[] = $location !== '' ? $location : null;
}

if ($assigned_to !== null) {
    $fields[] = 'assigned_to = ?';
    $types .= 's';
    $params[] = $assigned_to !== '' ? $assigned_to : null;
}

if ($status !== null) {
    $fields[] = 'status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($remarks !== null) {
    $fields[] = 'remarks = ?';
    $types .= 's';
    $params[] = $remarks !== '' ? $remarks : null;
}

$params[] = $id;
$types .= 'i';

$sql = "UPDATE borrowed_assets SET " . implode(', ', $fields) . " WHERE id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare update statement.']);
    exit();
}

$bind_names = [];
$bind_names[] = $types;
foreach ($params as $key => &$value) {
    $bind_names[$key + 1] = &$value;
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Borrowed asset updated successfully.']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'No changes made.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update borrowed asset: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
