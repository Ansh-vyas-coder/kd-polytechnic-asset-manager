<?php
session_start();
require 'db.php';
require_once 'notification_utils.php';

// Security check: admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php?view=audit");
    exit();
}

$location_id = isset($_POST['location_id']) ? trim($_POST['location_id']) : '';
$assign_to_user_id = isset($_POST['assign_to_user_id']) ? (int)$_POST['assign_to_user_id'] : 0;
$assigned_by_user_id = (int)$_SESSION['user_id'];

if (empty($location_id) || $assign_to_user_id <= 0) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Location and staff member are required to assign an audit."));
    exit();
}

// Check if an audit for this location is already in progress or assigned
$check_stmt = $conn->prepare("SELECT id, status FROM audits WHERE location_id = ? AND (status = 'In Progress' OR status = 'Assigned') LIMIT 1");
$check_stmt->bind_param("s", $location_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
if ($check_result->num_rows > 0) {
    $existing_audit = $check_result->fetch_assoc();
    $check_stmt->close();
    $status_msg = $existing_audit['status'] === 'In Progress' ? 'an audit is already in progress' : 'an audit has already been assigned';
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Cannot assign: {$status_msg} for this location."));
    exit();
}
$check_stmt->close();

// Create a new audit record with 'Assigned' status
$stmt = $conn->prepare("INSERT INTO audits (location_id, audited_by_user_id, assigned_by_user_id, status, audit_date) VALUES (?, ?, ?, 'Assigned', NOW())");
$stmt->bind_param("sii", $location_id, $assign_to_user_id, $assigned_by_user_id);

if ($stmt->execute()) {
    $stmt->close();
    $message = "You have been assigned to audit the location: " . htmlspecialchars($location_id);
    $link = "dashboard.php?view=audit";
    create_notification($conn, $assign_to_user_id, $message, $link);
    header("Location: dashboard.php?view=audit&status=success&message=" . urlencode("Audit for '{$location_id}' assigned successfully."));
} else {
    $error_message = urlencode($stmt->error);
    $stmt->close();
    header("Location: dashboard.php?view=audit&status=error&message=" . $error_message);
}
exit();
?>