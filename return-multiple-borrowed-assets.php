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

$raw_ids = isset($_POST['ids']) ? $_POST['ids'] : [];

if (!is_array($raw_ids)) {
    $raw_ids = explode(',', (string)$raw_ids);
}

if (empty($raw_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No assets selected.']);
    exit();
}

$ids = array_map('intval', $raw_ids);
$ids = array_filter($ids, function($id) { return $id > 0; });
$ids = array_values($ids);

if (empty($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid asset IDs.']);
    exit();
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare("UPDATE borrowed_assets SET status = 'Returned' WHERE id IN ($placeholders)");

    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }

    // Helper to create references for bind_param
    $refValues = function (&$arr) {
        $refs = [];
        foreach ($arr as $key => &$value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    };

    $types = str_repeat('i', count($ids));
    $params = $ids;
    array_unshift($params, $types);

    $bindParams = $refValues($params);
    if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
        throw new Exception('Failed to bind parameters: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Failed to execute: ' . $stmt->error);
    }

    $count = $stmt->affected_rows;
    $editor_name = htmlspecialchars($_SESSION['user_name'] ?? 'System');
    $link = "dashboard.php?view=loaned-assets&section=borrowed";

    $asset_names_stmt = $conn->prepare("SELECT asset_name FROM borrowed_assets WHERE id IN ($placeholders) ORDER BY id");
    if ($asset_names_stmt) {
        $bindParamsForNames = $refValues($params);
        if (call_user_func_array([$asset_names_stmt, 'bind_param'], $bindParamsForNames)) {
            $asset_names_stmt->execute();
            $asset_names_result = $asset_names_stmt->get_result();
            $asset_names = [];
            while ($row = $asset_names_result->fetch_assoc()) {
                $name = trim((string)($row['asset_name'] ?? ''));
                if ($name !== '') {
                    $asset_names[] = $name;
                }
            }
            $asset_names = array_values(array_unique($asset_names));
            $asset_names_label = implode(', ', array_map(function ($name) {
                return "'" . htmlspecialchars((string)$name) . "'";
            }, $asset_names));
            $admin_message = $count === 1
                ? "Borrowed asset {$asset_names_label} was returned by {$editor_name}."
                : "{$count} borrowed assets ({$asset_names_label}) were returned by {$editor_name}.";
            create_admin_notification($conn, $admin_message, $link, $_SESSION['user_id'] ?? null);
            $asset_names_stmt->close();
        } else {
            $admin_message = "$count borrowed asset(s) were returned by {$editor_name}.";
            create_admin_notification($conn, $admin_message, $link, $_SESSION['user_id'] ?? null);
        }
    } else {
        $admin_message = "$count borrowed asset(s) were returned by {$editor_name}.";
        create_admin_notification($conn, $admin_message, $link, $_SESSION['user_id'] ?? null);
    }

    echo json_encode(['status' => 'success', 'message' => "$count borrowed asset(s) returned successfully."]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
$conn->close();
