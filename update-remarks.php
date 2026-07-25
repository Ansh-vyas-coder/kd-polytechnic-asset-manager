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

// --- START NOTIFICATION PREP: Fetch current state ---
$asset_info = null;
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
    $sql = "SELECT asset_name FROM assets WHERE ";
    if (!empty($batch_id)) {
        $sql .= "batch_id = ? LIMIT 1";
        $prep_stmt = $conn->prepare($sql);
        $prep_stmt->bind_param("s", $batch_id);
    } else {
        $sql .= "id = ? LIMIT 1";
        $prep_stmt = $conn->prepare($sql);
        $prep_stmt->bind_param("i", $asset_id);
    }

    if ($prep_stmt) {
        $prep_stmt->execute();
        $result = $prep_stmt->get_result();
        if ($result->num_rows > 0) {
            $asset_info = $result->fetch_assoc();
        }
        $prep_stmt->close();
    }
}
// --- END NOTIFICATION PREP ---

// Update the remarks field
if (!empty($batch_id)) {
    $stmt = $conn->prepare("UPDATE assets SET remarks = ? WHERE batch_id = ?");
    $stmt->bind_param("ss", $remarks, $batch_id);
} else {
    $stmt = $conn->prepare("UPDATE assets SET remarks = ? WHERE id = ?");
    $stmt->bind_param("si", $remarks, $asset_id);
}

if ($stmt->execute()) {
    // --- START NOTIFICATION LOGIC ---
    if ($asset_info && isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
        $editor_name = htmlspecialchars($_SESSION['user_name']);
        $asset_name = htmlspecialchars($asset_info['asset_name']);
        
        if (!empty($batch_id)) {
            $link = "view-batch-details.php?batch_id=" . urlencode($batch_id);
            $message = "Remarks for batch '{$asset_name}' were updated by {$editor_name}.";
        } else {
            $link = "view-asset-details.php?id={$asset_id}";
            $message = "Remarks for asset '{$asset_name}' were updated by {$editor_name}.";
        }
        
        create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);
    }
    // --- END NOTIFICATION LOGIC ---

    echo json_encode(['success' => true, 'message' => 'Remarks updated successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database update failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
exit();
