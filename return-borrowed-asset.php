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
    echo json_encode(['status' => 'error', 'message' => 'Only admins can return borrowed assets.']);
    exit();
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid asset ID.']);
    exit();
}

$fetch_stmt = $conn->prepare("SELECT asset_name, asset_no, assigned_to, borrowed_from, category_id, return_date FROM borrowed_assets WHERE id = ?");
$borrowed_details = null;
if ($fetch_stmt) {
    $fetch_stmt->bind_param("i", $id);
    $fetch_stmt->execute();
    $result = $fetch_stmt->get_result();
    $borrowed_details = $result->fetch_assoc();
    $fetch_stmt->close();
}

$stmt = $conn->prepare("UPDATE borrowed_assets SET status = 'Returned' WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        $editor_name = htmlspecialchars($_SESSION['user_name'] ?? 'System');
        $asset_no = htmlspecialchars($borrowed_details['asset_no'] ?? $borrowed_details['asset_name'] ?? 'Asset');
        $link = "dashboard.php?view=loaned-assets&section=borrowed";
        $message = "Borrowed asset {$asset_no} was returned by {$editor_name}.";
        create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);

        $assigned_user = trim((string)($borrowed_details['assigned_to'] ?? ''));
        if ($assigned_user !== '') {
            $faculty_user_id = get_user_id_by_name($conn, $assigned_user);
            if ($faculty_user_id && $faculty_user_id != ($_SESSION['user_id'] ?? 0)) {
                create_notification($conn, $faculty_user_id, "Borrowed asset {$asset_no} has been returned by {$editor_name}.", $link);
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Borrowed asset returned successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Asset not found or not borrowed.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to return borrowed asset: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
