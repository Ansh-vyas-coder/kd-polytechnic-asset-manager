<?php
// register-ajax.php — handles AJAX calls from the virtual register
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
header('Content-Type: application/json');

$action = trim($_POST['action'] ?? '');

if ($action === 'save_page_notes') {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $page_no     = trim($_POST['page_no'] ?? '');
    $notes       = $_POST['notes'] ?? '';

    if ($category_id < 1 || $category_id > 4 || $page_no === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit();
    }

    // Ensure table exists (safety net)
    $conn->query("
        CREATE TABLE IF NOT EXISTS register_page_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            page_no VARCHAR(100) NOT NULL,
            notes TEXT NULL,
            UNIQUE KEY (category_id, page_no)
        )
    ");

    $stmt = $conn->prepare("
        INSERT INTO register_page_notes (category_id, page_no, notes)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE notes = VALUES(notes)
    ");
    $stmt->bind_param("iss", $category_id, $page_no, $notes);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['success' => false, 'message' => $err]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
