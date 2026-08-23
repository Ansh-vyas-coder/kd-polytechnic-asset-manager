<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$asset_no = isset($_GET['asset_no']) ? trim($_GET['asset_no']) : '';
$audit_id = isset($_GET['audit_id']) ? (int)$_GET['audit_id'] : 0;

if (empty($asset_no) || $audit_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing asset number or audit ID.']);
    exit();
}

// Get the location of the current audit
$audit_stmt = $conn->prepare("SELECT location_id FROM audits WHERE id = ?");
$audit_stmt->bind_param("i", $audit_id);
$audit_stmt->execute();
$audit_result = $audit_stmt->get_result();
$audit_session = $audit_result->fetch_assoc();
$audit_stmt->close();

if (!$audit_session) {
    http_response_code(404);
    echo json_encode(['error' => 'Audit session not found.']);
    exit();
}
$audit_location = $audit_session['location_id'];

// Find the asset by its asset_no
$asset_stmt = $conn->prepare("SELECT id, asset_name, asset_no, location, 'dept' AS source FROM assets WHERE asset_no = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) LIMIT 1");
$asset_stmt->bind_param("s", $asset_no);
$asset_stmt->execute();
$asset_result = $asset_stmt->get_result();
$asset = $asset_result->fetch_assoc();
$asset_stmt->close();

if (!$asset) {
    // Check in borrowed_assets table
    $borrowed_stmt = $conn->prepare("SELECT id, asset_name, asset_no, location, 'borrowed' AS source FROM borrowed_assets WHERE asset_no = ? AND (status IS NULL OR status <> 'Returned') LIMIT 1");
    $borrowed_stmt->bind_param("s", $asset_no);
    $borrowed_stmt->execute();
    $asset = $borrowed_stmt->get_result()->fetch_assoc();
    $borrowed_stmt->close();
}

if (!$asset) {
    echo json_encode(['error' => 'Asset not found or is retired/transferred.']);
    exit();
}

// Check if the asset's location is the same as the audit location
if ($asset['location'] === $audit_location) {
    echo json_encode(['error' => 'This asset is expected in this location. Mark it as "Present" below.']);
    exit();
}

// Success, return the asset details
echo json_encode($asset);
?>