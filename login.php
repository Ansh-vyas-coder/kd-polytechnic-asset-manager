<?php
session_start(); // Start a session to remember the user
require 'db.php'; // Bring in your bridge

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
            
            // Send them to the secure dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            echo "<script>alert('Invalid password!'); window.location.href='login.html';</script>";
        }
    } else {
        echo "<script>alert('User not found!'); window.location.href='login.html';</script>";
    }
    $stmt->close();
}
?>