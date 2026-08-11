<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Only admins can return assets.']);
    exit();
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid asset ID.']);
    exit();
}

$stmt = $conn->prepare("UPDATE assets SET status = 'active', loan_to = NULL, loan_date = NULL, return_date = NULL WHERE id = ? AND status = 'Loaned'");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Asset returned successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Asset not found or not loaned.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to return asset: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
