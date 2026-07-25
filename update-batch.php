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
$batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';
$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$cost = isset($_POST['cost']) ? (float)$_POST['cost'] : null;
$page_no = isset($_POST['page_no']) ? trim($_POST['page_no']) : null;
$gem_order_no = isset($_POST['gem_order_no']) ? trim($_POST['gem_order_no']) : null;
$gem_invoice_no = isset($_POST['gem_invoice_no']) ? trim($_POST['gem_invoice_no']) : null;
$gpr_no = isset($_POST['gpr_no']) ? trim($_POST['gpr_no']) : null;
$pr_page_no = isset($_POST['pr_page_no']) ? trim($_POST['pr_page_no']) : null;
$gpr_item_no = isset($_POST['gpr_item_no']) ? trim($_POST['gpr_item_no']) : null;

// Basic validation
if (empty($batch_id) || $cost === null || $cost < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input. Batch ID and a valid cost are required.']);
    exit();
}

// Prepare UPDATE statement
// These details are the same for all items in the batch.
$sql = "UPDATE assets SET 
            location = ?, 
            cost = ?, 
            page_no = ?, 
            gem_order_no = ?, 
            gem_invoice_no = ?, 
            gpr_no = ?, 
            pr_page_no = ?, 
            gpr_item_no = ?
        WHERE batch_id = ?";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement: ' . $conn->error]);
    exit();
}

// Bind parameters
$stmt->bind_param(
    "sdsssssss",
    $location,
    $cost,
    $page_no,
    $gem_order_no,
    $gem_invoice_no,
    $gpr_no,
    $pr_page_no,
    $gpr_item_no,
    $batch_id
);

// Execute and check for success
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success']);
    } else {
        // This can happen if the submitted data is the same as the existing data
        echo json_encode(['status' => 'success', 'message' => 'No changes were made.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update record: ' . $stmt->error]);
}

$stmt->close();
$conn->close();

?>
