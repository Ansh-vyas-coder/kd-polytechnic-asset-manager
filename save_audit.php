<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

// Check if the request is a POST request
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php?view=audit");
    exit();
}

$audit_id = isset($_POST['audit_id']) ? (int)$_POST['audit_id'] : 0;
$posted_assets = isset($_POST['assets']) ? $_POST['assets'] : [];
$misplaced_asset_ids = isset($_POST['misplaced_assets']) ? $_POST['misplaced_assets'] : [];

if ($audit_id <= 0) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Invalid audit ID."));
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // 1. Fetch audit details and all expected assets for the location
    $audit_stmt = $conn->prepare("SELECT location_id FROM audits WHERE id = ? AND status = 'In Progress'");
    $audit_stmt->bind_param("i", $audit_id);
    $audit_stmt->execute();
    $audit_result = $audit_stmt->get_result();
    $audit_session = $audit_result->fetch_assoc();
    $audit_stmt->close();

    if (!$audit_session) {
        throw new Exception("Audit session not found or already completed.");
    }
    $location_id = $audit_session['location_id'];

    // Fetch all assets that were expected at this location
    $expected_assets_stmt = $conn->prepare("SELECT id FROM assets WHERE location = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)");
    $expected_assets_stmt->bind_param("s", $location_id);
    $expected_assets_stmt->execute();
    $expected_assets_result = $expected_assets_stmt->get_result();
    $expected_asset_ids = [];
    while ($row = $expected_assets_result->fetch_assoc()) {
        $expected_asset_ids[] = (int)$row['id'];
    }
    $expected_assets_stmt->close();

    // Prepare statement for inserting audit items
    $insert_item_stmt = $conn->prepare(
        "INSERT INTO audit_items (audit_id, asset_id, expected_location_id, scanned_location_id, verification_status, `condition`, note) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    // 2. Process all expected assets ('Present' or 'Missing')
    foreach ($expected_asset_ids as $asset_id) {
        $asset_data = $posted_assets[$asset_id] ?? null;

        if (isset($asset_data['status']) && $asset_data['status'] === 'Present') {
            // Item was marked as Present
            $status = 'Present';
            $condition = $asset_data['condition'] ?? 'Good';
            $note = !empty($asset_data['note']) ? trim($asset_data['note']) : null;
            $scanned_location = $location_id; // It was found where it was expected
            $insert_item_stmt->bind_param("iisssss", $audit_id, $asset_id, $location_id, $scanned_location, $status, $condition, $note);
        } else {
            // Item was not checked, so it's Missing
            $status = 'Missing';
            $condition = null; // No condition if missing
            $note = null; // No note if missing
            $scanned_location = null;
            $insert_item_stmt->bind_param("iisssss", $audit_id, $asset_id, $location_id, $scanned_location, $status, $condition, $note);
        }
        $insert_item_stmt->execute();
    }

    // 3. Process 'Misplaced' assets
    if (!empty($misplaced_asset_ids)) {
        $asset_details_stmt = $conn->prepare("SELECT location FROM assets WHERE id = ?");
        foreach ($misplaced_asset_ids as $asset_id) {
            $asset_id = (int)$asset_id;
            $asset_details_stmt->bind_param("i", $asset_id);
            $asset_details_stmt->execute();
            $asset_details = $asset_details_stmt->get_result()->fetch_assoc();
            $expected_location = $asset_details ? $asset_details['location'] : 'Unknown';
            $status = 'Misplaced';
            $condition = 'Good'; // Default condition for misplaced items
            $note = 'Found during audit at ' . $location_id; // Default note for misplaced items
            $insert_item_stmt->bind_param("iisssss", $audit_id, $asset_id, $expected_location, $location_id, $status, $condition, $note);
            $insert_item_stmt->execute();
        }
        $asset_details_stmt->close();
    }
    $insert_item_stmt->close();

    // 4. Update the main audit status to 'Completed'
    $update_audit_stmt = $conn->prepare("UPDATE audits SET status = 'Completed' WHERE id = ?");
    $update_audit_stmt->bind_param("i", $audit_id);
    $update_audit_stmt->execute();
    $update_audit_stmt->close();

    $conn->commit();
    header("Location: dashboard.php?view=audit&status=success&message=" . urlencode("Audit #{$audit_id} completed successfully."));
    exit();
} catch (Exception $e) {
    $conn->rollback();
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("An error occurred: " . $e->getMessage()));
    exit();
}
?>