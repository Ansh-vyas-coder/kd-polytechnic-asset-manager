<?php
require 'db.php';

header('Content-Type: application/json');

// Fetch only faculty users added through Manage Users.
$stmt = $conn->prepare("SELECT full_name FROM users WHERE role = 'staff' ORDER BY full_name ASC");
$stmt->execute();
$result = $stmt->get_result();

$faculty_names = [];
while ($row = $result->fetch_assoc()) {
    $faculty_names[] = $row['full_name'];
}
$stmt->close();
$conn->close();

echo json_encode($faculty_names);
