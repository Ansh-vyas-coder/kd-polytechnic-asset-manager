<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['category_id']) && isset($input['page_no'])) {
    $categoryId = (int)$input['category_id'];
    $pageNo = trim($input['page_no']);
    $notes = isset($input['notes']) ? trim($input['notes']) : '';

    // Ensure table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS register_page_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            page_no VARCHAR(100) NOT NULL,
            notes TEXT NULL,
            UNIQUE KEY (category_id, page_no)
        )
    ");

    $stmt = $conn->prepare("INSERT INTO register_page_notes (category_id, page_no, notes) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE notes = VALUES(notes)");
    $stmt->bind_param("iss", $categoryId, $pageNo, $notes);
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
