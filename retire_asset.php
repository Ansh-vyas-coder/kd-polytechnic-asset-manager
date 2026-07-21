<?php
session_start();
require 'db.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$assetId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($assetId <= 0) {
    header("Location: dashboard.php");
    exit();
}

// Get asset information before deleting
$stmt = $conn->prepare("
    SELECT category_id, asset_name
    FROM assets
    WHERE id = ?
");

$stmt->bind_param("i", $assetId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: dashboard.php");
    exit();
}

$asset = $result->fetch_assoc();
$stmt->close();

// Delete asset
$stmt = $conn->prepare("
    DELETE FROM assets
    WHERE id = ?
");

$stmt->bind_param("i", $assetId);

if ($stmt->execute()) {

    header(
        "Location: view-asset-details.php?category_id=" .
        $asset['category_id'] .
        "&asset_name=" .
        urlencode($asset['asset_name'])
    );

} else {

    header(
        "Location: category-list.php?id=" .
        $assetId
    );
}

$stmt->close();
exit();