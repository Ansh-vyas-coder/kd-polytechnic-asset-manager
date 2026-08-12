<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['column']) && isset($input['value'])) {
    $column = trim($input['column']);
    $value = trim($input['value']);
    $assetId = isset($input['id']) ? (int)$input['id'] : null;
    $batchId = isset($input['batch_id']) ? trim($input['batch_id']) : null;

    // White-list columns to prevent SQL injection
    $allowedColumns = [
        'asset_name',
        'cost',
        'location',
        'remarks',
        'date_of_issue',
        'gem_invoice_no',
        'gem_order_no',
        'pr_page_no',
        'gpr_item_no',
        'gpr_no',
        'unit',
        'item_no'
    ];

    if (!in_array($column, $allowedColumns)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid column name']);
        exit();
    }

    // Determine query
    if (!empty($batchId)) {
        $stmt = $conn->prepare("UPDATE assets SET $column = ? WHERE batch_id = ?");
        $stmt->bind_param("ss", $value, $batchId);
    } else if ($assetId !== null) {
        $stmt = $conn->prepare("UPDATE assets SET $column = ? WHERE id = ?");
        $stmt->bind_param("si", $value, $assetId);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing identifier']);
        exit();
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
    }
    $stmt->close();
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
}
