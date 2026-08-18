<?php
session_start();
require 'db.php';
require_once 'notification_utils.php';

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

$fetch_stmt = $conn->prepare("SELECT asset_name, asset_no, loan_to, assigned_to, category_id, batch_id FROM assets WHERE id = ?");
$asset_details = null;
if ($fetch_stmt) {
    $fetch_stmt->bind_param("i", $id);
    $fetch_stmt->execute();
    $result = $fetch_stmt->get_result();
    $asset_details = $result->fetch_assoc();
    $fetch_stmt->close();
}

$stmt = $conn->prepare("UPDATE assets SET status = 'active', loan_to = NULL, loan_date = NULL, return_date = NULL WHERE id = ? AND status = 'Loaned'");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        $editor_name = htmlspecialchars($_SESSION['user_name'] ?? 'System');
        $asset_name = htmlspecialchars($asset_details['asset_name'] ?? 'Asset');
        $asset_no = htmlspecialchars($asset_details['asset_no'] ?? $asset_name);
        $link = "view-batch-details.php?category_id=" . (int)($asset_details['category_id'] ?? 0) . "&asset_name=" . urlencode($asset_details['asset_name'] ?? '') . "&batch_id=" . urlencode($asset_details['batch_id'] ?? '');

        $message = "{$editor_name} returned asset {$asset_no} from loan";
        create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);

        $assigned_user = trim((string)($asset_details['assigned_to'] ?? ''));
        if ($assigned_user !== '') {
            $faculty_user_id = get_user_id_by_name($conn, $assigned_user);
            if ($faculty_user_id && $faculty_user_id != ($_SESSION['user_id'] ?? 0)) {
                create_notification($conn, $faculty_user_id, "Asset {$asset_no} was returned from loan by {$editor_name}.", $link);
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Asset returned successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Asset not found or not loaned.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to return asset: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
