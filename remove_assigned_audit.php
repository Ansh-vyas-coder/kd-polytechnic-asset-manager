<?php
session_start();
require 'db.php';
require_once 'notification_utils.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.html');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php?view=audit');
    exit();
}

$audit_id = isset($_POST['audit_id']) ? (int)$_POST['audit_id'] : 0;
if ($audit_id <= 0) {
    header('Location: dashboard.php?view=audit&status=error&message=' . urlencode('Invalid assigned audit.'));
    exit();
}

// Only pending delegated audits can be withdrawn. Audits with work in progress or completed reports are retained.
$audit_stmt = $conn->prepare(
    "SELECT location_id, audited_by_user_id
     FROM audits
     WHERE id = ? AND status = 'Assigned' AND assigned_by_user_id IS NOT NULL"
);
$audit_stmt->bind_param('i', $audit_id);
$audit_stmt->execute();
$audit = $audit_stmt->get_result()->fetch_assoc();
$audit_stmt->close();

if (!$audit) {
    header('Location: dashboard.php?view=audit&status=error&message=' . urlencode('Only pending assigned audits can be removed.'));
    exit();
}

$delete_stmt = $conn->prepare("DELETE FROM audits WHERE id = ? AND status = 'Assigned' AND assigned_by_user_id IS NOT NULL");
$delete_stmt->bind_param('i', $audit_id);
$delete_stmt->execute();
$was_deleted = $delete_stmt->affected_rows === 1;
$delete_stmt->close();

if (!$was_deleted) {
    header('Location: dashboard.php?view=audit&status=error&message=' . urlencode('The assigned audit could not be removed.'));
    exit();
}

$message = 'Your assigned audit for ' . $audit['location_id'] . ' has been withdrawn.';
create_notification($conn, (int)$audit['audited_by_user_id'], $message, 'dashboard.php?view=audit');

header('Location: dashboard.php?view=audit&status=success&message=' . urlencode("Assigned audit for '{$audit['location_id']}' was removed."));
exit();
?>
