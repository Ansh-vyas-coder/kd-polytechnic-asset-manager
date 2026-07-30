<?php
session_start();
require 'db.php';
require_once 'remarks_utils.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Only admins can edit items.']);
    exit();
}

$item_ids = isset($_POST['item_ids']) ? $_POST['item_ids'] : [];
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : null;
$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$status = isset($_POST['status']) ? trim($_POST['status']) : null;
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
$transfer_to = isset($_POST['transfer_to']) ? trim($_POST['transfer_to']) : null;

if (empty($item_ids) || !is_array($item_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No items selected for bulk update.']);
    exit();
}

if ($action === 'retire_items') {
    $retired_count = 0;
    $errors = [];

    foreach ($item_ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            $errors[] = "Invalid item ID: $id";
            continue;
        }

        $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = ? AND retire_at IS NULL");
        if ($stmt === false) {
            $errors[] = "Failed to prepare statement for item $id: " . $conn->error;
            continue;
        }

        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $retired_count++;
            }
        } else {
            $errors[] = "Failed to retire item $id: " . $stmt->error;
        }
        $stmt->close();
    }

    if (!empty($errors) && $retired_count === 0) {
        echo json_encode(['status' => 'error', 'message' => implode('; ', $errors)]);
    } else {
        echo json_encode(['status' => 'success', 'message' => "{$retired_count} item(s) retired successfully."]);
    }

    $conn->close();
    exit();
}

$old_data = [];
foreach ($item_ids as $id) {
    $id = (int)$id;
    if ($id <= 0) continue;
    $fetch_stmt = $conn->prepare("SELECT asset_name, asset_no, quantity, assigned_to, location, status, remarks, category_id, batch_id FROM assets WHERE id = ?");
    if ($fetch_stmt) {
        $fetch_stmt->bind_param("i", $id);
        $fetch_stmt->execute();
        $result = $fetch_stmt->get_result();
        if ($result->num_rows > 0) {
            $old_data[$id] = $result->fetch_assoc();
        }
        $fetch_stmt->close();
    }
}

$updated_count = 0;
$errors = [];

$selected_items_for_note = [];
$first_selected_id = 0;
foreach ($item_ids as $selected_id) {
    $selected_id = (int)$selected_id;
    if ($selected_id <= 0 || !isset($old_data[$selected_id])) {
        continue;
    }
    if ($first_selected_id === 0) {
        $first_selected_id = $selected_id;
    }
    $selected_items_for_note[] = [
        'id' => $selected_id,
        'asset_no' => $old_data[$selected_id]['asset_no'] ?? '',
        'quantity' => $old_data[$selected_id]['quantity'] ?? 1,
    ];
}

$transfer_remarks = null;
if (!empty($transfer_to) && $first_selected_id > 0) {
    $transfer_remarks = remarks_build_transfer_body($selected_items_for_note, $transfer_to, 'transferred to');
}

foreach ($item_ids as $id) {
    $id = (int)$id;
    if ($id <= 0) {
        $errors[] = "Invalid item ID: $id";
        continue;
    }

    if (!isset($old_data[$id])) {
        $errors[] = "Item not found: $id";
        continue;
    }

    if (!empty($transfer_to)) {
        $base_remarks = $remarks !== null ? $remarks : ($old_data[$id]['remarks'] ?? '');
        $final_remarks = remarks_upsert_block($base_remarks, 'Transfer Note', $transfer_remarks ?? '');
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
            $errors[] = "Failed to prepare statement for item $id: " . $conn->error;
            continue;
        }
        $stmt->bind_param("ssi", $transfer_to, $final_remarks, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $updated_count++;
            }
        } else {
            $errors[] = "Failed to update item $id: " . $stmt->error;
        }

        $stmt->close();
        continue;
    }

    $set_clauses = [];
    $params = [];
    $types = '';

    if ($assigned_to !== null && $old_data[$id]['assigned_to'] !== $assigned_to) {
        $set_clauses[] = "assigned_to = ?";
        $params[] = $assigned_to;
        $types .= 's';
    }

    if ($location !== null && $old_data[$id]['location'] !== $location) {
        $set_clauses[] = "location = ?";
        $params[] = $location;
        $types .= 's';
    }

    if ($status !== null && $old_data[$id]['status'] !== $status) {
        $set_clauses[] = "status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $remarks_needs_lab_note = $location !== null && $old_data[$id]['location'] !== $location;
    if ($remarks !== null || $remarks_needs_lab_note) {
        $remarks_value = $remarks !== null ? $remarks : ($old_data[$id]['remarks'] ?? '');
        if ($remarks_needs_lab_note) {
            $lab_note = remarks_build_lab_change_body([$old_data[$id]], $location);
            $remarks_value = remarks_upsert_block($remarks_value, 'Lab Reassign Note', $lab_note);
        }
        if ($old_data[$id]['remarks'] !== $remarks_value || $remarks_needs_lab_note) {
            $set_clauses[] = "remarks = ?";
            $params[] = $remarks_value;
            $types .= 's';
        }
    }

    if (empty($set_clauses)) {
        continue;
    }

    $set_clauses[] = "updated_at = CURRENT_TIMESTAMP";

    $sql = "UPDATE assets SET " . implode(', ', $set_clauses) . " WHERE id = ?";
    $params[] = $id;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        $errors[] = "Failed to prepare statement for item $id: " . $conn->error;
        continue;
    }

    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $updated_count++;
        }
    } else {
        $errors[] = "Failed to update item $id: " . $stmt->error;
    }

    $stmt->close();
}

if ($updated_count > 0) {
    require_once 'notification_utils.php';
    $editor_name = htmlspecialchars($_SESSION['user_name'] ?? 'System');
    $is_admin_editor = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

    function format_asset_list($assets) {
        $counts = array_count_values($assets);
        $parts = [];
        foreach ($counts as $name => $count) {
            if ($count === 1) {
                $parts[] = "1 {$name}";
            } else {
                $parts[] = "{$count} {$name}s";
            }
        }
        return implode(', ', $parts);
    }

    if ($assigned_to !== null) {
        $affected_assets = [];
        $affected_ids = [];
        $old_assignees = [];
        $new_assignees = [];

        foreach ($old_data as $id => $data) {
            $old_assigned_to = $data['assigned_to'];
            if ($old_assigned_to === $assigned_to) continue;

            $original_asset_name = htmlspecialchars($data['asset_name']);
            $affected_assets[] = $original_asset_name;
            $affected_ids[] = $id;

            if (!empty($old_assigned_to) && $old_assigned_to !== $assigned_to) {
                $old_assignees[$old_assigned_to][] = $original_asset_name;
            }
            if (!empty($assigned_to) && $assigned_to !== $old_assigned_to) {
                $new_assignees[$assigned_to][] = $original_asset_name;
            }
        }

        if (!empty($affected_assets)) {
            $asset_list = format_asset_list($affected_assets);
            $first_affected_id = $affected_ids[0];
            $first_affected = $old_data[$first_affected_id];
            $link = "view-batch-details.php?category_id=" . (int)$first_affected['category_id'] . "&asset_name=" . urlencode($first_affected['asset_name']) . "&batch_id=" . urlencode($first_affected['batch_id']);

            $old_assigned_to_for_link = $first_affected['assigned_to'];
            $assigned_to_escaped = htmlspecialchars($assigned_to);

            if (empty($old_assigned_to_for_link) && !empty($assigned_to)) {
                $message = count($affected_assets) === 1
                    ? "Asset {$asset_list} was assigned to {$assigned_to_escaped} by {$editor_name}."
                    : "Assets {$asset_list} were assigned to {$assigned_to_escaped} by {$editor_name}.";
            } elseif (!empty($old_assigned_to_for_link) && empty($assigned_to)) {
                $old_assigned_to_escaped = htmlspecialchars($old_assigned_to_for_link);
                $message = count($affected_assets) === 1
                    ? "Asset {$asset_list} was returned from {$old_assigned_to_escaped} by {$editor_name}."
                    : "Assets {$asset_list} were returned from {$old_assigned_to_escaped} by {$editor_name}.";
            } else {
                $old_assigned_to_escaped = htmlspecialchars($old_assigned_to_for_link);
                $message = count($affected_assets) === 1
                    ? "Asset {$asset_list} was reassigned from {$old_assigned_to_escaped} to {$assigned_to_escaped} by {$editor_name}."
                    : "Assets {$asset_list} were reassigned from {$old_assigned_to_escaped} to {$assigned_to_escaped} by {$editor_name}.";
            }

            create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);

            foreach ($old_assignees as $old_name => $assets) {
                $old_user_id = get_user_id_by_name($conn, $old_name);
                if (!$old_user_id || $old_user_id == $_SESSION['user_id']) continue;
                $user_asset_list = format_asset_list($assets);
                $notif_message = count($assets) === 1
                    ? "Asset {$user_asset_list} is no longer assigned to you."
                    : "Assets {$user_asset_list} are no longer assigned to you.";
                create_notification($conn, $old_user_id, $notif_message, $link);
            }

            foreach ($new_assignees as $new_name => $assets) {
                $new_user_id = get_user_id_by_name($conn, $new_name);
                if (!$new_user_id || $new_user_id == $_SESSION['user_id']) continue;
                $user_asset_list = format_asset_list($assets);
                $notif_message = count($assets) === 1
                    ? "Asset {$user_asset_list} has been assigned to you."
                    : "Assets {$user_asset_list} have been assigned to you.";
                create_notification($conn, $new_user_id, $notif_message, $link);
            }
        }
    }

    if (!empty($transfer_to)) {
        $affected_assets = [];
        $affected_ids = [];
        $old_assignees = [];

        foreach ($old_data as $id => $data) {
            $original_asset_name = htmlspecialchars($data['asset_name']);
            $affected_assets[] = $original_asset_name;
            $affected_ids[] = $id;

            if (!empty($data['assigned_to'])) {
                $old_assignees[$data['assigned_to']][] = $original_asset_name;
            }
        }

        if (!empty($affected_assets)) {
            $asset_list = format_asset_list($affected_assets);
            $first_affected_id = $affected_ids[0];
            $first_affected = $old_data[$first_affected_id];
            $link = "view-batch-details.php?category_id=" . (int)$first_affected['category_id'] . "&asset_name=" . urlencode($first_affected['asset_name']) . "&batch_id=" . urlencode($first_affected['batch_id']);

            $transfer_to_escaped = htmlspecialchars($transfer_to);
            $message = count($affected_assets) === 1
                ? "Asset {$asset_list} was transferred to {$transfer_to_escaped} by {$editor_name}."
                : "Assets {$asset_list} were transferred to {$transfer_to_escaped} by {$editor_name}.";

            create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);

            foreach ($old_assignees as $old_name => $assets) {
                $old_user_id = get_user_id_by_name($conn, $old_name);
                if (!$old_user_id || $old_user_id == $_SESSION['user_id']) continue;
                $user_asset_list = format_asset_list($assets);
                $notif_message = count($assets) === 1
                    ? "Asset {$user_asset_list} is no longer assigned to you (transferred to {$transfer_to_escaped})."
                    : "Assets {$user_asset_list} are no longer assigned to you (transferred to {$transfer_to_escaped}).";
                create_notification($conn, $old_user_id, $notif_message, $link);
            }
        }
    }

    if (!$is_admin_editor) {
        $changed_fields = [];
        if ($location !== null) $changed_fields[] = 'location';
        if ($status !== null) $changed_fields[] = 'status';
        if ($remarks !== null && $remarks !== '') $changed_fields[] = 'remarks';
        if (!empty($transfer_to)) $changed_fields[] = 'transfer_to';

        if (!empty($changed_fields)) {
            $fields_str = implode(', ', $changed_fields);
            $message = "{$updated_count} asset(s) ({$fields_str}) were bulk-updated by non-admin user {$editor_name}.";
            $first_id = (int)$item_ids[0];
            $link = '';
            $prep_stmt = $conn->prepare("SELECT asset_name, category_id, batch_id FROM assets WHERE id = ?");
            if ($prep_stmt) {
                $prep_stmt->bind_param("i", $first_id);
                $prep_stmt->execute();
                $result = $prep_stmt->get_result();
                if ($result->num_rows > 0) {
                    $first_item = $result->fetch_assoc();
                    $link = "view-batch-details.php?category_id={$first_item['category_id']}&asset_name=" . urlencode($first_item['asset_name']) . "&batch_id=" . urlencode($first_item['batch_id']);
                }
                $prep_stmt->close();
            }
            create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);
        }
    }
}

if (!empty($errors) && $updated_count === 0) {
    echo json_encode(['status' => 'error', 'message' => implode('; ', $errors)]);
} else {
    echo json_encode(['status' => 'success', 'message' => "{$updated_count} item(s) updated successfully."]);
}

$conn->close();
?>
