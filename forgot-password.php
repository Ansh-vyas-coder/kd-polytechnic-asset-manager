<?php
/**
 * forgot-password.php
 * Handles the 3-step Forgot Password flow via OTP email.
 *
 * POST actions:
 *   action=send_otp      — validate email exists, generate OTP, email it
 *   action=verify_otp    — check OTP is valid and not expired (returns a short-lived reset token)
 *   action=reset_password — validate reset token + new passwords, update hash
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$action = trim($_POST['action'] ?? '');

// ─────────────────────────────────────────────────────────────────────────────
// ACTION 1: SEND OTP
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'send_otp') {
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit();
    }

    // Check user exists and is verified
    $stmt = $conn->prepare("SELECT id, full_name, email_verified FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Don't reveal whether email exists — generic message
        echo json_encode(['success' => true, 'message' => 'If this email is registered, an OTP has been sent to it.']);
        $stmt->close();
        exit();
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    if ((int)$user['email_verified'] === 0) {
        echo json_encode(['success' => false, 'message' => 'This account has not been verified yet. Please contact the administrator.']);
        exit();
    }

    // Rate-limit: prevent spam (check if OTP was sent within last 60 seconds)
    $check = $conn->prepare("SELECT password_otp_expires FROM users WHERE id = ? AND password_otp_expires > NOW() AND password_otp_expires < DATE_ADD(NOW(), INTERVAL 9 MINUTE)");
    $check->bind_param("i", $user['id']);
    $check->execute();
    $check_result = $check->get_result();
    if ($check_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'An OTP was recently sent. Please wait a moment before requesting another.']);
        $check->close();
        exit();
    }
    $check->close();

    // Generate 6-digit OTP and set expiry (10 minutes)
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $otp_hash = password_hash($otp, PASSWORD_DEFAULT); // Store hashed OTP
    $expires_at = date('Y-m-d H:i:s', time() + 600); // 10 min

    $upd = $conn->prepare("UPDATE users SET password_otp = ?, password_otp_expires = ? WHERE id = ?");
    $upd->bind_param("ssi", $otp, $expires_at, $user['id']);
    $upd->execute();
    $upd->close();

    // Send the OTP email using the forgot-password specific function
    $mail_result = send_forgot_password_otp_email($user['full_name'], $email, $otp);

    if (!$mail_result['success']) {
        // Roll back OTP so user can retry
        $conn->query("UPDATE users SET password_otp = NULL, password_otp_expires = NULL WHERE id = " . (int)$user['id']);
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please check mail configuration.']);
        exit();
    }

    // Store user_id in session temporarily for this flow
    $_SESSION['fp_user_id'] = $user['id'];
    $_SESSION['fp_email']   = $email;

    echo json_encode(['success' => true, 'message' => 'OTP sent successfully to your registered email address.']);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION 2: VERIFY OTP
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'verify_otp') {
    $otp_input = trim($_POST['otp'] ?? '');

    if (empty($otp_input) || !isset($_SESSION['fp_user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid session. Please restart the process.']);
        exit();
    }

    $user_id = (int)$_SESSION['fp_user_id'];

    $stmt = $conn->prepare("SELECT password_otp, password_otp_expires FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        $stmt->close();
        exit();
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if (empty($row['password_otp']) || empty($row['password_otp_expires'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP was requested. Please request a new one.']);
        exit();
    }

    // Check expiry
    if (strtotime($row['password_otp_expires']) < time()) {
        // Clear expired OTP
        $conn->query("UPDATE users SET password_otp = NULL, password_otp_expires = NULL WHERE id = $user_id");
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit();
    }

    // Verify OTP against stored hash or plain
    $is_valid_otp = false;
    if (password_verify($otp_input, $row['password_otp']) || hash_equals($row['password_otp'], $otp_input)) {
        $is_valid_otp = true;
    }

    if (!$is_valid_otp) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
        exit();
    }

    // OTP verified — generate a short-lived reset token and store in session
    $reset_token = bin2hex(random_bytes(32));
    $_SESSION['fp_reset_token']   = $reset_token;
    $_SESSION['fp_reset_expires'] = time() + 600; // 10 more minutes to set password
    $_SESSION['fp_otp_verified']  = true;

    // Clear the OTP from DB so it can't be reused
    $conn->query("UPDATE users SET password_otp = NULL, password_otp_expires = NULL WHERE id = $user_id");

    echo json_encode(['success' => true, 'message' => 'OTP verified. You may now set a new password.', 'reset_token' => $reset_token]);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION 3: RESET PASSWORD
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'reset_password') {
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $reset_token      = trim($_POST['reset_token'] ?? '');

    // Validate session
    if (
        empty($_SESSION['fp_otp_verified']) ||
        empty($_SESSION['fp_reset_token']) ||
        empty($_SESSION['fp_user_id']) ||
        !hash_equals($_SESSION['fp_reset_token'], $reset_token) ||
        time() > ($_SESSION['fp_reset_expires'] ?? 0)
    ) {
        echo json_encode(['success' => false, 'message' => 'Session expired or invalid. Please restart the process.']);
        exit();
    }

    if (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
        exit();
    }

    if ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    }

    $user_id = (int)$_SESSION['fp_user_id'];
    $email   = $_SESSION['fp_email'] ?? '';

    // Fetch user name for confirmation email
    $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        $stmt->close();
        exit();
    }
    $user = $result->fetch_assoc();
    $stmt->close();

    // Update password
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $upd->bind_param("si", $new_hash, $user_id);
    $upd->execute();
    $upd->close();

    // Clear forgot-password session variables
    unset($_SESSION['fp_user_id'], $_SESSION['fp_email'], $_SESSION['fp_reset_token'], $_SESSION['fp_reset_expires'], $_SESSION['fp_otp_verified']);

    // Send confirmation email
    send_password_changed_confirmation_email($user['full_name'], $user['email']);

    echo json_encode(['success' => true, 'message' => 'Your password has been reset successfully. You can now log in with your new password.']);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit();
