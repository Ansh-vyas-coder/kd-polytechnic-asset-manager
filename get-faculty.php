<?php
require 'db.php';

header('Content-Type: application/json');

$stmt = $conn->prepare("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name ASC");
$stmt->execute();
$result = $stmt->get_result();

$faculty = [];
while ($row = $result->fetch_assoc()) {
    $faculty[] = [
        'id' => (int)$row['id'],
        'full_name' => $row['full_name']
    ];
}

$stmt->close();
$conn->close();

echo json_encode($faculty);
