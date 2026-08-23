<?php
/**
 * scan-asset.php
 * AJAX endpoint: returns asset details by asset_no (used after QR scan).
 * Returns JSON: {id, asset_name, asset_no, category_id, location, assigned_to, item_no}
 * or            {error: "..."}
 */
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$asset_no = trim($_GET['asset_no'] ?? '');

if (empty($asset_no)) {
    http_response_code(400);
    echo json_encode(['error' => 'No asset_no provided']);
    exit();
}

$stmt = $conn->prepare(
    "SELECT id, asset_name, asset_no, category_id, location, assigned_to, item_no, batch_id
     FROM assets
     WHERE asset_no = ?
       AND retire_at IS NULL
       AND (transferred = 0 OR transferred IS NULL)
     LIMIT 1"
);
$stmt->bind_param("s", $asset_no);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$asset) {
    // Check in borrowed_assets table
    $stmt = $conn->prepare(
        "SELECT id, asset_name, asset_no, category_id, location, assigned_to, item_no, 'borrowed' AS source
         FROM borrowed_assets
         WHERE asset_no = ?
           AND (status IS NULL OR status <> 'Returned')
         LIMIT 1"
    );
    $stmt->bind_param("s", $asset_no);
    $stmt->execute();
    $asset = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($asset && empty($asset['source'])) {
    if (empty($asset['batch_id'])) {
        $asset['batch_id'] = 'batch_uncategorized_' . $asset['id'];
    }
}

if (!$asset) {
    // Check if it exists but is retired/transferred
    $stmt2 = $conn->prepare("SELECT status, retire_at FROM assets WHERE asset_no = ? LIMIT 1");
    $stmt2->bind_param("s", $asset_no);
    $stmt2->execute();
    $check = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if ($check) {
        echo json_encode(['error' => 'Asset is retired or transferred.']);
    } else {
        echo json_encode(['error' => 'Asset not found. Please check the QR code.']);
    }
    exit();
}

echo json_encode($asset);
