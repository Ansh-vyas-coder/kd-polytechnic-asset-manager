<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. Validate new passwords match
    if ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
    } elseif (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
    } else {
        // 2. Fetch current user's password hash
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($current_password, $user['password_hash'])) {
            // 3. Current password is correct, hash the new one
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // 4. Update the database
            $update_stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $update_stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($update_stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Your password has been updated successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
            }
            $update_stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'The current password you entered is incorrect.']);
        }
    }
} else {
    // Handle non-POST requests if necessary
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>