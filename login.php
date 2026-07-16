<?php
session_start(); // Start a session to remember the user
require 'db.php'; // Bring in your bridge

// Set the content type to JSON for all responses
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Securely query the database for this email
    $stmt = $conn->prepare("SELECT id, full_name, password_hash, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Since we are testing, we are checking the plain text password we entered earlier
        if ($password === $user['password_hash']) {
            // Success! Store their data in the session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_email'] = $email; // Store email in session

            // Send a success response
            echo json_encode(['success' => true]);
        } else {
            // Send an error response for invalid password
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }
    } else {
        // Send an error response for user not found
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    }
    $stmt->close();
}