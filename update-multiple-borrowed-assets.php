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

$raw_ids = isset($_POST['ids']) ? $_POST['ids'] : [];

if (!is_array($raw_ids)) {
    $raw_ids = explode(',', (string)$raw_ids);
}

if (empty($raw_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No assets selected.']);
    exit();
}

$ids = array_map('intval', $raw_ids);
$ids = array_filter($ids, function($id) { return $id > 0; });
$ids = array_values($ids);

if (empty($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid asset IDs.']);
    exit();
}

$location = isset($_POST['location']) && trim((string)$_POST['location']) !== '' ? trim((string)$_POST['location']) : null;
$assigned_to = isset($_POST['assigned_to']) && trim((string)$_POST['assigned_to']) !== '' ? trim((string)$_POST['assigned_to']) : null;
$status = isset($_POST['status']) && trim((string)$_POST['status']) !== '' ? trim((string)$_POST['status']) : null;
$remarks = isset($_POST['remarks']) && trim((string)$_POST['remarks']) !== '' ? trim((string)$_POST['remarks']) : null;

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
    $params[] = $location;
}

if ($assigned_to !== null) {
    $fields[] = 'assigned_to = ?';
    $types .= 's';
    $params[] = $assigned_to;
}

if ($status !== null) {
    $fields[] = 'status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($remarks !== null) {
    $fields[] = 'remarks = ?';
    $types .= 's';
    $params[] = $remarks;
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE borrowed_assets SET " . implode(', ', $fields) . " WHERE id IN ($placeholders)";

    $all_params = array_merge($params, $ids);
    $all_types = $types . str_repeat('i', count($ids));

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare update statement: ' . $conn->error);
    }

    // Helper to create references for bind_param
    $refValues = function (&$arr) {
        $refs = [];
        foreach ($arr as $key => &$value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    };

    $all_params = array_merge($params, $ids);
    array_unshift($all_params, $all_types);

    $bindParams = $refValues($all_params);
    if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
        throw new Exception('Failed to bind parameters: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Failed to update borrowed assets: ' . $stmt->error);
    }

    $count = $stmt->affected_rows;
    echo json_encode(['status' => 'success', 'message' => "$count borrowed asset(s) updated successfully."]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
$conn->close();
