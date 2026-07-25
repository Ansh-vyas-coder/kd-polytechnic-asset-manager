<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Security check: ensure user is logged in and request is POST
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

// Get POST data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : null;
$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$status = isset($_POST['status']) ? trim($_POST['status']) : null;
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;

// Basic validation
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item ID.']);
    exit();
}

// Prepare UPDATE statement
$sql = "UPDATE assets SET 
            assigned_to = ?, 
            location = ?,
            status = ?,
            remarks = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement: ' . $conn->error]);
    exit();
}

// Bind parameters
$stmt->bind_param(
    "ssssi", // s for assigned_to, s for location, s for status, s for remarks, i for id
    $assigned_to,
    $location,
    $status,
    $remarks,
    $id
);

// Execute and check for success
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Item updated successfully.']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'No changes were made to the item.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update item: ' . $stmt->error]);
}

$stmt->close();
$conn->close();

?>
