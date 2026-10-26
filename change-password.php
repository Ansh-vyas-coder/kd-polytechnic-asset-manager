<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';
header('Content-Type: application/json');

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in first.']);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

function mask_email_display(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 2) {
        $masked_name = substr($name, 0, 1) . '*';
    } else {
        $masked_name = substr($name, 0, 2) . str_repeat('*', max(3, $len - 2));
    }
    return $masked_name . '@' . $parts[1];
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = trim($_POST['action'] ?? 'change_password');

    // ─── ACTION 1: SEND OTP ───
    if ($action === 'send_otp') {
        $current_password = $_POST['current_password'] ?? '';
        if (empty($current_password)) {
            echo json_encode(['success' => false, 'message' => 'Please enter your current password to receive an OTP.']);
            exit();
        }

        // Fetch user from database
        $stmt = $conn->prepare("SELECT id, full_name, email, password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'The current password you entered is incorrect.']);
            exit();
        }

        if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'No valid email found associated with your account.']);
            exit();
        }

        // Generate 6-digit cryptographic OTP
        $otp = sprintf("%06d", random_int(100000, 999999));
        $update_stmt = $conn->prepare("UPDATE users SET password_otp = ?, password_otp_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?");
        $update_stmt->bind_param("si", $otp, $user_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Send OTP email
        $mail_result = send_password_otp_email($user['full_name'], $user['email'], $otp);
        if (!$mail_result['success']) {
            error_log("Failed to send password OTP email: " . ($mail_result['error'] ?? 'Unknown error'));
        }

        $masked = mask_email_display($user['email']);
        echo json_encode([
            'success' => true,
            'message' => "OTP sent successfully to your registered email ({$masked}). Code is valid for 10 minutes."
        ]);
        exit();
    }

    // ─── ACTION 2: VERIFY OTP AND CHANGE PASSWORD ───
    $current_password = $_POST['current_password'] ?? '';
    $otp = trim($_POST['otp'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your current password.']);
        exit();
    }

    if (empty($otp)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit OTP. Click "Send OTP to Email" if you have not received one.']);
        exit();
    }

    if ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
        exit();
    }

    if (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters long.']);
        exit();
    }

    // Fetch user details
    $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, password_otp, password_otp_expires FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User account not found.']);
        exit();
    }

    // Verify current password
    if (!password_verify($current_password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'The current password you entered is incorrect.']);
        exit();
    }

    // Verify OTP code
    if (empty($user['password_otp']) || $user['password_otp'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP entered. Please check your email or click "Send OTP to Email" to generate a new one.']);
        exit();
    }

    // Verify OTP expiry
    if (empty($user['password_otp_expires']) || strtotime($user['password_otp_expires']) < time()) {
        echo json_encode(['success' => false, 'message' => 'The OTP has expired. Please request a new OTP.']);
        exit();
    }

    // All verifications passed! Update password & clear OTP
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $update_stmt = $conn->prepare("UPDATE users SET password_hash = ?, password_otp = NULL, password_otp_expires = NULL WHERE id = ?");
    $update_stmt->bind_param("si", $new_password_hash, $user_id);
    
    if ($update_stmt->execute()) {
        $update_stmt->close();

        // Send confirmation email
        send_password_changed_confirmation_email($user['full_name'], $user['email']);

        // In-app notification
        create_notification($conn, $user_id, "Your account password was successfully updated.", "dashboard.php");

        echo json_encode(['success' => true, 'message' => 'Your password has been updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
        $update_stmt->close();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}