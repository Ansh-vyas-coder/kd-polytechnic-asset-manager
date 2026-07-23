<?php
session_start();
require 'db.php';

// Set response header to JSON
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';
$asset_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

if (empty($batch_id) && $asset_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing identifiers.']);
    exit();
}

// Update the remarks field
if (!empty($batch_id)) {
    $stmt = $conn->prepare("UPDATE assets SET remarks = ? WHERE batch_id = ?");
    $stmt->bind_param("ss", $remarks, $batch_id);
} else {
    $stmt = $conn->prepare("UPDATE assets SET remarks = ? WHERE id = ?");
    $stmt->bind_param("si", $remarks, $asset_id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Remarks updated successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
exit();
