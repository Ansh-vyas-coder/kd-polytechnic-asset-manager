<?php
// verify-email-change.php — Handles verification of newly requested email addresses
session_start();
require_once 'db.php';
require_once 'mailer.php';

$token = trim($_GET['token'] ?? '');
$status = 'invalid'; // 'solo_admin_approved', 'admin_pending_peer', 'staff_pending_admin', 'already_approved', 'already_pending', 'expired', 'invalid'
$error_message = '';
$req_data = null;

if (!empty($token)) {
    $stmt = $conn->prepare("SELECT r.*, u.full_name, u.role as user_role, u.email as current_user_email 
                            FROM email_change_requests r 
                            JOIN users u ON r.user_id = u.id 
                            WHERE r.verification_token = ? 
                            LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($req) {
            $req_data = $req;

            if ($req['status'] === 'approved') {
                $status = 'already_approved';
            } elseif ($req['status'] === 'rejected') {
                $status = 'already_rejected';
            } elseif (strtotime($req['token_expires']) < time()) {
                $status = 'expired';
            } elseif ($req['status'] === 'pending_admin_approval') {
                $status = 'already_pending';
            } elseif ($req['status'] === 'pending_verification') {
                $user_id = (int)$req['user_id'];
                $user_role = $req['user_role'];
                $new_email = $req['new_email'];

                // Check other active admins count
                $admin_count_res = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND id != {$user_id}");
                $other_admins_count = $admin_count_res ? (int)$admin_count_res->fetch_assoc()['count'] : 0;
                $is_solo_admin = ($user_role === 'admin' && $other_admins_count === 0);

                if ($user_role === 'admin' && $is_solo_admin) {
                    // ─── SOLO ADMIN: IMMEDIATE DIRECT UPDATE ───
                    $up_user = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $up_user->bind_param("si", $new_email, $user_id);
                    $up_user->execute();
                    $up_user->close();

                    $up_req = $conn->prepare("UPDATE email_change_requests SET email_verified = 1, email_verified_at = NOW(), status = 'approved', actioned_by_user_id = ?, actioned_at = NOW() WHERE id = ?");
                    $up_req->bind_param("ii", $user_id, $req['id']);
                    $up_req->execute();
                    $up_req->close();

                    // If currently logged in as this user, update session
                    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
                        $_SESSION['user_email'] = $new_email;
                    }

                    // Send notifications to both addresses
                    send_email_change_status_email($req['full_name'], $req['current_email'], 'approved', $new_email);
                    send_email_change_status_email($req['full_name'], $new_email, 'approved', $new_email);

                    create_notification($conn, $user_id, "Your email was successfully updated to '{$new_email}' (Sole Administrator Direct Update).", "dashboard.php");

                    $status = 'solo_admin_approved';
                } elseif ($user_role === 'admin') {
                    // ─── ADMIN WITH CO-ADMINS: ROUTE TO OTHER ADMIN(S) ───
                    $up_req = $conn->prepare("UPDATE email_change_requests SET email_verified = 1, email_verified_at = NOW(), status = 'pending_admin_approval' WHERE id = ?");
                    $up_req->bind_param("i", $req['id']);
                    $up_req->execute();
                    $up_req->close();

                    // Notify co-admins
                    $notify_msg = "Admin '{$req['full_name']}' verified new email '{$new_email}'. Co-admin approval is required.";
                    create_admin_notification($conn, $notify_msg, 'manage-users.php', $user_id);

                    $status = 'admin_pending_peer';
                } else {
                    // ─── STAFF: ROUTE TO ADMIN APPROVAL ───
                    $up_req = $conn->prepare("UPDATE email_change_requests SET email_verified = 1, email_verified_at = NOW(), status = 'pending_admin_approval' WHERE id = ?");
                    $up_req->bind_param("i", $req['id']);
                    $up_req->execute();
                    $up_req->close();

                    // Notify admins
                    $notify_msg = "Staff member '{$req['full_name']}' verified new email '{$new_email}'. Approval required.";
                    create_admin_notification($conn, $notify_msg, 'manage-users.php');

                    $status = 'staff_pending_admin';
                }
            }
        } else {
            $status = 'invalid';
            $error_message = "The verification link is invalid, expired, or has already been used.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Email Change Verification — KDP Asset Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <style>
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-100 via-blue-50 to-indigo-100 flex flex-col justify-center items-center p-4 antialiased">

  <div class="w-full max-w-lg bg-white rounded-2xl shadow-xl border border-slate-200/80 overflow-hidden">
    
    <!-- Top Branding Banner -->
    <div class="bg-gradient-to-r from-blue-900 via-blue-800 to-indigo-800 p-6 text-center text-white relative">
      <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white/10 backdrop-blur-md mb-3 border border-white/20 shadow-inner">
        <i data-lucide="shield-check" class="w-6 h-6 text-blue-200"></i>
      </div>
      <h1 class="text-xl font-extrabold tracking-tight">K.D. Polytechnic, Patan</h1>
      <p class="text-xs text-blue-200 font-medium mt-0.5 tracking-wide uppercase">Smart Asset Manager &bull; Security Center</p>
    </div>

    <div class="p-6 sm:p-8">

      <?php if ($status === 'solo_admin_approved'): ?>
        <!-- SOLO ADMIN: DIRECT SUCCESS -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4 shadow-sm">
            <i data-lucide="check-circle-2" class="w-9 h-9"></i>
          </div>
          <h2 class="text-2xl font-bold text-gray-900">Email Updated Directly! 🎉</h2>
          <p class="text-sm text-gray-500 mt-2">
            Hello <strong class="text-gray-800"><?php echo htmlspecialchars($req_data['full_name']); ?></strong>, as the sole Administrator of the system, your email change has been verified and applied immediately!
          </p>

          <div class="mt-6 bg-slate-50 border border-slate-200 rounded-xl p-4 text-left">
            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Updated Account Details</div>
            <div class="flex items-center justify-between text-sm py-1 border-b border-gray-100">
              <span class="text-gray-500">Name:</span>
              <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($req_data['full_name']); ?></span>
            </div>
            <div class="flex items-center justify-between text-sm py-1 border-b border-gray-100">
              <span class="text-gray-500">Old Email:</span>
              <span class="text-gray-600 line-through"><?php echo htmlspecialchars($req_data['current_email']); ?></span>
            </div>
            <div class="flex items-center justify-between text-sm py-1 text-emerald-700 font-bold">
              <span>New Active Email:</span>
              <span><?php echo htmlspecialchars($req_data['new_email']); ?></span>
            </div>
          </div>

          <div class="mt-6">
            <a href="dashboard.php" class="w-full inline-flex justify-center items-center gap-2 py-3 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 transition-all">
              <i data-lucide="arrow-right" class="w-4 h-4"></i>
              Go to Dashboard
            </a>
          </div>
        </div>

      <?php elseif ($status === 'admin_pending_peer'): ?>
        <!-- ADMIN WITH OTHER ADMINS: ROUTED TO CO-ADMINS -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mb-4 shadow-sm">
            <i data-lucide="clock" class="w-9 h-9"></i>
          </div>
          <h2 class="text-2xl font-bold text-gray-900">New Email Verified! ⏳</h2>
          <p class="text-sm text-gray-600 mt-2">
            Hello <strong class="text-gray-800"><?php echo htmlspecialchars($req_data['full_name']); ?></strong>, you have successfully verified ownership of <strong class="text-blue-600"><?php echo htmlspecialchars($req_data['new_email']); ?></strong>.
          </p>

          <div class="mt-5 p-4 rounded-xl bg-amber-50 border border-amber-200 text-left text-sm text-amber-800">
            <div class="font-semibold flex items-center gap-1.5 mb-1">
              <i data-lucide="shield-alert" class="w-4 h-4 text-amber-600"></i>
              Co-Administrator Approval Required
            </div>
            Because other administrators exist on this system, your email change request has been routed to them for final confirmation. Once approved, your email will update automatically.
          </div>

          <div class="mt-6">
            <a href="dashboard.php" class="w-full inline-flex justify-center items-center gap-2 py-3 px-4 rounded-xl text-sm font-bold text-gray-700 bg-gray-100 hover:bg-gray-200 transition">
              Go to Dashboard
            </a>
          </div>
        </div>

      <?php elseif ($status === 'staff_pending_admin'): ?>
        <!-- STAFF: ROUTED TO ADMIN APPROVAL -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-sky-100 text-sky-600 rounded-full flex items-center justify-center mb-4 shadow-sm">
            <i data-lucide="send" class="w-9 h-9"></i>
          </div>
          <h2 class="text-2xl font-bold text-gray-900">Email Verified! Request Sent 🚀</h2>
          <p class="text-sm text-gray-600 mt-2">
            Hello <strong class="text-gray-800"><?php echo htmlspecialchars($req_data['full_name']); ?></strong>, your new email <strong class="text-blue-600"><?php echo htmlspecialchars($req_data['new_email']); ?></strong> has been verified successfully.
          </p>

          <div class="mt-5 p-4 rounded-xl bg-sky-50 border border-sky-200 text-left text-sm text-sky-800">
            <div class="font-semibold flex items-center gap-1.5 mb-1">
              <i data-lucide="info" class="w-4 h-4 text-sky-600"></i>
              Awaiting Administrator Approval
            </div>
            Your request has now been submitted to the Administrator for approval. You will receive an email notification as soon as the administrator approves the change.
          </div>

          <div class="mt-6">
            <a href="dashboard.php" class="w-full inline-flex justify-center items-center gap-2 py-3 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 transition">
              Go to Dashboard
            </a>
          </div>
        </div>

      <?php elseif ($status === 'already_approved'): ?>
        <!-- ALREADY APPROVED -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4">
            <i data-lucide="check" class="w-9 h-9"></i>
          </div>
          <h2 class="text-xl font-bold text-gray-900">Request Already Completed</h2>
          <p class="text-sm text-gray-500 mt-2">
            This email change request has already been approved and completed.
          </p>
          <div class="mt-6">
            <a href="login.html" class="w-full inline-flex justify-center items-center py-2.5 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 transition">
              Sign In to Your Account
            </a>
          </div>
        </div>

      <?php elseif ($status === 'already_pending'): ?>
        <!-- ALREADY PENDING ADMIN -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mb-4">
            <i data-lucide="clock" class="w-9 h-9"></i>
          </div>
          <h2 class="text-xl font-bold text-gray-900">Already Verified &amp; Pending</h2>
          <p class="text-sm text-gray-500 mt-2">
            This email address has already been verified and is currently awaiting Administrator approval.
          </p>
          <div class="mt-6">
            <a href="dashboard.php" class="w-full inline-flex justify-center items-center py-2.5 px-4 rounded-xl text-sm font-bold text-gray-700 bg-gray-100 hover:bg-gray-200 transition">
              Go to Dashboard
            </a>
          </div>
        </div>

      <?php elseif ($status === 'expired'): ?>
        <!-- EXPIRED -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mb-4">
            <i data-lucide="alert-triangle" class="w-9 h-9"></i>
          </div>
          <h2 class="text-xl font-bold text-gray-900">Verification Link Expired</h2>
          <p class="text-sm text-gray-500 mt-2">
            This verification link has expired (links are valid for 24 hours). Please log into your account and submit a new email change request.
          </p>
          <div class="mt-6">
            <a href="login.html" class="w-full inline-flex justify-center items-center py-2.5 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 transition">
              Go to Login
            </a>
          </div>
        </div>

      <?php else: ?>
        <!-- INVALID / ERROR -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mb-4">
            <i data-lucide="x-circle" class="w-9 h-9"></i>
          </div>
          <h2 class="text-xl font-bold text-gray-900">Invalid Verification Link</h2>
          <p class="text-sm text-gray-500 mt-2">
            <?php echo htmlspecialchars($error_message ?: "The link you followed is invalid, has expired, or has already been used."); ?>
          </p>
          <div class="mt-6">
            <a href="login.html" class="w-full inline-flex justify-center items-center py-2.5 px-4 rounded-xl text-sm font-bold text-gray-700 bg-gray-100 hover:bg-gray-200 transition">
              Back to Login
            </a>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Footer Note -->
    <div class="bg-gray-50 px-6 py-4 border-t border-gray-100 text-center">
      <p class="text-xs text-gray-400">
        Government Polytechnic Patan &bull; Gujarat Technical Education<br>
        Department of Computer Engineering
      </p>
    </div>

  </div>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }
  </script>
</body>
</html>
