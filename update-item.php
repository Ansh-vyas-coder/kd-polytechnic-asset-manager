<?php
session_start();
require 'db.php';
require_once 'remarks_utils.php';

header('Content-Type: application/json');

// Security check: ensure user is logged in and request is POST
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Only admins can edit items.']);
    exit();
}

// Get POST data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : null;
$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$status = isset($_POST['status']) ? trim($_POST['status']) : null;
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
$transfer_to = isset($_POST['transfer_to']) ? trim($_POST['transfer_to']) : null;

// Basic validation
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item ID.']);
    exit();
}

// --- START NOTIFICATION PREP: Fetch current state ---
$old_asset_data = null;
$prep_stmt = $conn->prepare("SELECT asset_name, asset_no, quantity, assigned_to, location, remarks, category_id, batch_id FROM assets WHERE id = ?");
if ($prep_stmt) {
    $prep_stmt->bind_param("i", $id);
    $prep_stmt->execute();
    $result = $prep_stmt->get_result();
    if ($result->num_rows > 0) {
        $old_asset_data = $result->fetch_assoc();
    }
    $prep_stmt->close();
}
// --- END NOTIFICATION PREP ---

if (!empty($transfer_to)) {
    $transfer_note_body = remarks_build_transfer_body([$old_asset_data], $transfer_to, 'transferred to');
    $base_remarks = $remarks !== null ? $remarks : ($old_asset_data['remarks'] ?? '');
    $transfer_remarks = remarks_upsert_block($base_remarks, 'Transfer Note', $transfer_note_body);
    $sql = "UPDATE assets SET 
                assigned_to = NULL,
                location = NULL,
                transfer_to = ?,
                transfer_date = NOW(),
                transferred = 1,
                remarks = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("ssi", $transfer_to, $transfer_remarks, $id);
} else {
    $remarks_value = $remarks;
    if ($location !== null && $old_asset_data['location'] !== $location) {
        $lab_note_body = remarks_build_lab_change_body([$old_asset_data], $location);
        $base_remarks = $remarks !== null ? $remarks : ($old_asset_data['remarks'] ?? '');
        $remarks_value = remarks_upsert_block($base_remarks, 'Lab Reassign Note', $lab_note_body);
    }
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
    $stmt->bind_param(
        "ssssi",
        $assigned_to,
        $location,
        $status,
        $remarks_value,
        $id
    );
}

// Execute and check for success
if ($stmt->execute()) {
    // --- START NOTIFICATION LOGIC ---
    require_once 'notification_utils.php';

    if ($old_asset_data) {
        $editor_name = htmlspecialchars($_SESSION['user_name'] ?? 'System');
        $is_admin_editor = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
        $original_asset_name = htmlspecialchars($old_asset_data['asset_name']);
        $link = "view-batch-details.php?category_id={$old_asset_data['category_id']}&asset_name=" . urlencode($old_asset_data['asset_name']) . "&batch_id=" . urlencode($old_asset_data['batch_id']);

        if (!empty($transfer_to)) {
            $message = "Asset '{$original_asset_name}' was transferred to " . htmlspecialchars($transfer_to) . " by {$editor_name}.";
            create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);

            $old_user_id = get_user_id_by_name($conn, $old_asset_data['assigned_to']);
            if ($old_user_id && $old_user_id != $_SESSION['user_id']) {
                create_notification($conn, $old_user_id, "Asset '{$original_asset_name}' is no longer assigned to you (transferred to " . htmlspecialchars($transfer_to) . ").", $link);
            }
        } else {
            // 1. Notify about assignment changes
            if ($old_asset_data['assigned_to'] !== $assigned_to) {
                $message = "";
                if (empty($old_asset_data['assigned_to']) && !empty($assigned_to)) {
                    $message = "Asset '{$original_asset_name}' was assigned to " . htmlspecialchars($assigned_to) . " by {$editor_name}.";
                } elseif (!empty($old_asset_data['assigned_to']) && empty($assigned_to)) {
                    $message = "Asset '{$original_asset_name}' was returned from " . htmlspecialchars($old_asset_data['assigned_to']) . " by {$editor_name}.";
                } else {
                    $message = "Asset '{$original_asset_name}' was reassigned from " . htmlspecialchars($old_asset_data['assigned_to']) . " to " . htmlspecialchars($assigned_to) . " by {$editor_name}.";
                }
                
                create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);
                
                $old_user_id = get_user_id_by_name($conn, $old_asset_data['assigned_to']);
                $new_user_id = get_user_id_by_name($conn, $assigned_to);

                if ($old_user_id && $old_user_id != $_SESSION['user_id']) {
                    create_notification($conn, $old_user_id, "Asset '{$original_asset_name}' is no longer assigned to you.", $link);
                }
                if ($new_user_id && $new_user_id != $_SESSION['user_id']) {
                    create_notification($conn, $new_user_id, "Asset '{$original_asset_name}' has been assigned to you.", $link);
                }
            }
            // 2. Notify admins about location changes by non-admins
            elseif (!$is_admin_editor && $old_asset_data['location'] !== $location) {
                 $message = "Location for '{$original_asset_name}' was updated to " . htmlspecialchars($location) . " by {$editor_name}.";
                 create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);
            }
        }
    }
    // --- END NOTIFICATION LOGIC ---

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
