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
$user_role = $_SESSION['role'] ?? 'staff';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = trim($_POST['action'] ?? '');

    // ─── ACTION 1: REQUEST EMAIL CHANGE ───
    if ($action === 'request_email_change') {
        $current_password = $_POST['current_password'] ?? '';
        $new_email = strtolower(trim($_POST['new_email'] ?? ''));

        if (empty($current_password)) {
            echo json_encode(['success' => false, 'message' => 'Please enter your current password to authorize this email change request.']);
            exit();
        }

        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid new email address.']);
            exit();
        }

        // Fetch current user details
        $stmt = $conn->prepare("SELECT id, full_name, email, role, password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'The current password you entered is incorrect.']);
            exit();
        }

        if (strtolower($user['email']) === $new_email) {
            echo json_encode(['success' => false, 'message' => 'The new email is the same as your current email.']);
            exit();
        }

        // Check if new email is already in use by any user
        $chk_stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1");
        $chk_stmt->bind_param("si", $new_email, $user_id);
        $chk_stmt->execute();
        $chk_res = $chk_stmt->get_result()->fetch_assoc();
        $chk_stmt->close();

        if ($chk_res) {
            echo json_encode(['success' => false, 'message' => 'This email address is already in use by another user account.']);
            exit();
        }

        // Check if user is a solo admin (no other active admin exists)
        $admin_count_res = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND id != " . (int)$user_id);
        $other_admins_count = $admin_count_res ? (int)$admin_count_res->fetch_assoc()['count'] : 0;
        $is_solo_admin = ($user['role'] === 'admin' && $other_admins_count === 0);

        // Delete any existing unverified requests for this user
        $conn->query("DELETE FROM email_change_requests WHERE user_id = {$user_id} AND status = 'pending_verification'");

        // Generate secure 64-hex-char verification token
        $token = bin2hex(random_bytes(32));
        $token_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $ins_stmt = $conn->prepare("INSERT INTO email_change_requests (user_id, current_email, new_email, verification_token, token_expires, status) VALUES (?, ?, ?, ?, ?, 'pending_verification')");
        $ins_stmt->bind_param("issss", $user_id, $user['email'], $new_email, $token, $token_expires);
        
        if ($ins_stmt->execute()) {
            $ins_stmt->close();

            // Send verification email to the NEW email address
            $verify_url = get_app_base_url() . "/verify-email-change.php?token=" . urlencode($token);
            $mail_res = send_email_change_verification_email($user['full_name'], $new_email, $verify_url, $user['role'], $is_solo_admin);

            if (!$mail_res['success']) {
                error_log("Failed to send email change verification: " . ($mail_res['error'] ?? 'Unknown'));
            }

            $info_msg = ($user['role'] === 'admin' && $is_solo_admin)
                ? "A verification link has been sent to {$new_email}. Once verified, your email will update immediately."
                : "A verification link has been sent to {$new_email}. Once verified, your request will be routed for administrator approval.";

            echo json_encode([
                'success' => true,
                'message' => $info_msg
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create request: ' . $ins_stmt->error]);
            $ins_stmt->close();
        }
        exit();
    }

    // ─── ACTION 2: APPROVE EMAIL CHANGE (ADMIN ONLY) ───
    if ($action === 'approve_email_change') {
        if ($user_role !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access. Administrator privileges required.']);
            exit();
        }

        $request_id = (int)($_POST['request_id'] ?? 0);
        if ($request_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
            exit();
        }

        $stmt = $conn->prepare("SELECT r.*, u.full_name, u.role as user_role FROM email_change_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? AND r.status = 'pending_admin_approval' LIMIT 1");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
            exit();
        }

        // Double check new email is still available
        $chk = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1");
        $chk->bind_param("si", $req['new_email'], $req['user_id']);
        $chk->execute();
        $already_taken = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($already_taken) {
            echo json_encode(['success' => false, 'message' => "The email '{$req['new_email']}' is already registered to another user."]);
            exit();
        }

        // Apply change to users table
        $up_user = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
        $up_user->bind_param("si", $req['new_email'], $req['user_id']);
        $up_user->execute();
        $up_user->close();

        // Update request record
        $up_req = $conn->prepare("UPDATE email_change_requests SET status = 'approved', actioned_by_user_id = ?, actioned_at = NOW() WHERE id = ?");
        $up_req->bind_param("ii", $user_id, $request_id);
        $up_req->execute();
        $up_req->close();

        // If target user is the currently logged-in admin, update session email
        if ((int)$req['user_id'] === $user_id) {
            $_SESSION['user_email'] = $req['new_email'];
        }

        // Send confirmation emails to both old and new addresses
        send_email_change_status_email($req['full_name'], $req['current_email'], 'approved', $req['new_email']);
        send_email_change_status_email($req['full_name'], $req['new_email'], 'approved', $req['new_email']);

        // In-app notification for the user
        create_notification($conn, $req['user_id'], "Your request to change email to '{$req['new_email']}' has been approved by the administrator.", "dashboard.php");

        echo json_encode([
            'success' => true,
            'message' => "Email change request for '{$req['full_name']}' approved successfully. Email updated to '{$req['new_email']}'."
        ]);
        exit();
    }

    // ─── ACTION 3: REJECT EMAIL CHANGE (ADMIN ONLY) ───
    if ($action === 'reject_email_change') {
        if ($user_role !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access. Administrator privileges required.']);
            exit();
        }

        $request_id = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Declined by administrator.');

        if ($request_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
            exit();
        }

        $stmt = $conn->prepare("SELECT r.*, u.full_name FROM email_change_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? AND r.status = 'pending_admin_approval' LIMIT 1");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
            exit();
        }

        // Update request record
        $up_req = $conn->prepare("UPDATE email_change_requests SET status = 'rejected', actioned_by_user_id = ?, actioned_at = NOW(), rejection_reason = ? WHERE id = ?");
        $up_req->bind_param("isi", $user_id, $reason, $request_id);
        $up_req->execute();
        $up_req->close();

        // Send notification email to old email
        send_email_change_status_email($req['full_name'], $req['current_email'], 'rejected', $req['new_email'], $reason);

        // In-app notification
        create_notification($conn, $req['user_id'], "Your request to change email to '{$req['new_email']}' was rejected: {$reason}", "dashboard.php");

        echo json_encode([
            'success' => true,
            'message' => "Email change request for '{$req['full_name']}' has been rejected."
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
