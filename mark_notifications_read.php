<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'User not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];

$sql = "";
$params = [];
$types = "";

if (isset($data['notification_id'])) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $types = "ii";
    $params[] = $data['notification_id'];
    $params[] = $user_id;
} elseif (!isset($data['notification_id']) || (isset($data['mark_all_as_read']) && $data['mark_all_as_read'])) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $types = "i";
    $params[] = $user_id;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Notifications updated.']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update notifications.']);
}

$stmt->close();
$conn->close();
?>
