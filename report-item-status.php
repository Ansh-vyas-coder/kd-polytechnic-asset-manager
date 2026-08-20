<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) {
    header("Location: dashboard.php");
    exit();
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
$asset_name = isset($_POST['asset_name']) ? trim((string)$_POST['asset_name']) : '';
$batch_id = isset($_POST['batch_id']) ? trim((string)$_POST['batch_id']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';

$allowed_statuses = ['Not Working', 'Missing', 'Under Maintenance'];

if ($id <= 0 || $category_id <= 0 || $asset_name === '' || $batch_id === '' || !in_array($status, $allowed_statuses, true)) {
    header("Location: 404.php");
    exit();
}

$stmt = $conn->prepare("SELECT id, asset_no, asset_name, assigned_to, location, category_id, batch_id, status FROM assets WHERE id = ? AND category_id = ? AND asset_name = ? AND batch_id = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)");
if (!$stmt) {
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Database error."));
    exit();
}

$stmt->bind_param("iiss", $id, $category_id, $asset_name, $batch_id);
$stmt->execute();
$result = $stmt->get_result();
$asset = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$asset) {
    header("Location: 404.php");
    exit();
}

if ($role === 'staff' && (trim((string)($asset['assigned_to'] ?? '')) !== trim((string)$_SESSION['user_name']))) {
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("You can only report assets assigned to you."));
    exit();
}

$reporter_name = $_SESSION['user_name'] ?? 'Unknown';
$reported_role = $role;
$reported_assigned_to = trim((string)($asset['assigned_to'] ?? ''));

$update = $conn->prepare("
    UPDATE assets
    SET status = ?,
        status_marked_by = ?,
        status_marked_role = ?,
        status_marked_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
");

if (!$update) {
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Unable to update status."));
    exit();
}

$update->bind_param("sssi", $status, $reporter_name, $reported_role, $id);

if ($update->execute()) {
    $report_stmt = $conn->prepare("
        INSERT INTO asset_status_reports
            (asset_id, category_id, asset_name, batch_id, reported_by_user_id, reported_by_name, reported_by_role, reported_assigned_to, reported_status, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if ($report_stmt) {
        $reported_by_user_id = (int)$_SESSION['user_id'];
        $report_stmt->bind_param(
            "iississsss",
            $id,
            $category_id,
            $asset_name,
            $batch_id,
            $reported_by_user_id,
            $reporter_name,
            $reported_role,
            $reported_assigned_to,
            $status,
            $note
        );
        $report_stmt->execute();
        $report_stmt->close();
    }

    // Notify all admins when staff reports missing or not working assets
    if ($role === 'staff' && in_array($status, ['Not Working', 'Missing'], true)) {
        $asset_no = trim((string)($asset['asset_no'] ?? $asset_name));
        $asset_location = trim((string)($asset['location'] ?? 'Unknown'));
        $notification_message = "{$reporter_name} marked {$asset_no} as {$status} at {$asset_location}";
        $notification_link = "view-batch-details.php?category_id={$category_id}&asset_name=" . urlencode($asset_name) . "&batch_id=" . urlencode($batch_id);
        create_admin_notification($conn, $notification_message, $notification_link, $_SESSION['user_id'] ?? null);
    }

    $update->close();
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name) . "&batch_id=" . urlencode($batch_id) . "&status=success&message=" . urlencode("Status marked as {$status}."));
    exit();
}

$error = $update->error;
$update->close();

header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Failed to update status: " . $error));
exit();
?>
