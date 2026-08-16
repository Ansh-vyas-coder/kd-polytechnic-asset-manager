<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: login.html"); // User not logged in or not a valid role
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
    $audit_stmt = $conn->prepare("SELECT location_id, audited_by_user_id FROM audits WHERE id = ? AND status = 'In Progress'");
    $audit_stmt->bind_param("i", $audit_id);
    $audit_stmt->execute();
    $audit_result = $audit_stmt->get_result();
    $audit_session = $audit_result->fetch_assoc();
    $audit_stmt->close();

    if (!$audit_session) {
        throw new Exception("Audit session not found or already completed.");
    }
    // Security check: Staff can only save their own audits
    if ($_SESSION['role'] === 'staff' && $audit_session['audited_by_user_id'] != $_SESSION['user_id']) {
        $conn->rollback();
        // Using a generic message to avoid revealing information
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

    // New: Prepare a statement to check for misplaced items from other audits
    $check_misplaced_stmt = $conn->prepare(
        "SELECT ai.scanned_location_id
         FROM audit_items ai
         JOIN audits a ON ai.audit_id = a.id
         WHERE ai.asset_id = ?
           AND ai.verification_status = 'Misplaced'
         ORDER BY a.audit_date DESC
         LIMIT 1"
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
            // Item was not checked, so it's 'Missing' from this location.
            // Let's see if it has already been found elsewhere.
            $check_misplaced_stmt->bind_param("i", $asset_id);
            $check_misplaced_stmt->execute();
            $misplaced_result = $check_misplaced_stmt->get_result();
            $found_elsewhere = $misplaced_result->fetch_assoc();

            $status = 'Missing'; // It is always 'Missing' from its expected location if not 'Present'.
            $condition = null; // No condition if missing
            $scanned_location = null;
            $note = null;

            if ($found_elsewhere) {
                // It was found in another location. Let's record that.
                $scanned_location = $found_elsewhere['scanned_location_id'];
                $note = 'Found at location: ' . $scanned_location;
            }

            $insert_item_stmt->bind_param("iisssss", $audit_id, $asset_id, $location_id, $scanned_location, $status, $condition, $note);
        }
        $insert_item_stmt->execute();
    }
    $check_misplaced_stmt->close();

    // 3. Process 'Misplaced' assets
    if (!empty($misplaced_asset_ids)) {
        $asset_details_stmt = $conn->prepare("SELECT location FROM assets WHERE id = ?");
        $update_missing_stmt = $conn->prepare(
            "UPDATE audit_items SET scanned_location_id = ?, note = ? WHERE asset_id = ? AND verification_status = 'Missing' AND scanned_location_id IS NULL"
        );
        foreach ($misplaced_asset_ids as $asset_id) {
            $asset_id = (int)$asset_id;
            $asset_details_stmt->bind_param("i", $asset_id);
            $asset_details_stmt->execute();
            $asset_details = $asset_details_stmt->get_result()->fetch_assoc();
            $expected_location = $asset_details ? $asset_details['location'] : 'Unknown';
            $status = 'Misplaced';
            $condition = 'Good'; // Default condition for misplaced items
            $note = 'Found during audit at ' . $location_id; // Default note for misplaced items

            // Insert the 'Misplaced' record for THIS audit
            $insert_item_stmt->bind_param("iisssss", $audit_id, $asset_id, $expected_location, $location_id, $status, $condition, $note);
            $insert_item_stmt->execute();

            // Now, update any 'Missing' records for this asset in other audits.
            $update_note = 'Found at location: ' . $location_id;
            $update_missing_stmt->bind_param("ssi", $location_id, $update_note, $asset_id);
            $update_missing_stmt->execute();
        }
        $asset_details_stmt->close();
        $update_missing_stmt->close();
    }
    $insert_item_stmt->close();

    // 4. Update the main audit status to 'Completed'
    $update_audit_stmt = $conn->prepare("UPDATE audits SET status = 'Completed' WHERE id = ?");
    $update_audit_stmt->bind_param("i", $audit_id);
    $update_audit_stmt->execute();
    $update_audit_stmt->close();

    $conn->commit();
    // Redirect to the results page upon successful completion
    header("Location: audit_results.php?id={$audit_id}&status=completed");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("An error occurred: " . $e->getMessage()));
    exit();
}
?>