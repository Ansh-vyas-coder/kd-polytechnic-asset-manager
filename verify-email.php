<?php
// verify-email.php — Email verification & password dispatch handler
require_once 'db.php';
require_once 'mailer.php';

$token = trim($_GET['token'] ?? '');
$status = 'invalid'; // 'success', 'already_verified', 'expired', 'invalid'
$user_data = null;
$generated_password = '';
$error_message = '';

if (!empty($token)) {
    // Check if token matches a user pending verification
    $stmt = $conn->prepare("SELECT id, full_name, email, role, email_verified, verification_token_expires FROM users WHERE verification_token = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();

        if ($user) {
            if ((int)$user['email_verified'] === 1) {
                $status = 'already_verified';
                $user_data = $user;
            } elseif (!empty($user['verification_token_expires']) && strtotime($user['verification_token_expires']) < time()) {
                $status = 'expired';
                $user_data = $user;
            } else {
                // Token is valid and active! Generate password and activate account
                $plain_password = bin2hex(random_bytes(5)); // 10 chars hex password
                $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);

                $update_stmt = $conn->prepare("UPDATE users SET email_verified = 1, email_verified_at = NOW(), password_hash = ?, verification_token = NULL, verification_token_expires = NULL WHERE id = ?");
                if ($update_stmt) {
                    $update_stmt->bind_param("si", $password_hash, $user['id']);
                    if ($update_stmt->execute()) {
                        $status = 'success';
                        $user_data = $user;
                        $generated_password = $plain_password;

                        // Send Email with Password & Credentials
                        $login_url = get_app_base_url() . '/login.html';
                        send_password_credentials_email($user['full_name'], $user['email'], $user['role'], $plain_password, $login_url);

                        // Notify admins
                        $notify_msg = "User '{$user['full_name']}' verified their email and activated their account.";
                        create_admin_notification($conn, $notify_msg, 'manage-users.php');
                    } else {
                        $status = 'invalid';
                        $error_message = "Failed to activate account. Database error: " . $update_stmt->error;
                    }
                    $update_stmt->close();
                } else {
                    $status = 'invalid';
                    $error_message = "Database prepare failed.";
                }
            }
        } else {
            $status = 'invalid';
            $error_message = "The verification link is invalid, expired, or has already been used.";
        }
    } else {
        $status = 'invalid';
        $error_message = "Database query failed.";
    }
} else {
    $status = 'invalid';
    $error_message = "No verification token provided.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Email Verification - KDP Asset Manager</title>
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
      <div class="w-16 h-16 mx-auto mb-3 rounded-full bg-white/10 p-1 backdrop-blur-sm shadow-inner flex items-center justify-center">
        <?php if (file_exists(__DIR__ . '/kdp_logo.jpeg')): ?>
          <img src="kdp_logo.jpeg" alt="Logo" class="w-full h-full object-cover rounded-full" />
        <?php else: ?>
          <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
        <?php endif; ?>
      </div>
      <h1 class="text-xl font-extrabold tracking-tight">K.D. Polytechnic, Patan</h1>
      <p class="text-xs text-blue-200 font-medium mt-0.5">Department Asset &amp; Inventory Management System</p>
    </div>

    <!-- Main Content Area -->
    <div class="p-6 sm:p-8">

      <?php if ($status === 'success'): ?>
        <!-- SUCCESS STATE -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-4 shadow-sm ring-8 ring-emerald-50">
            <i data-lucide="check-circle" class="w-9 h-9"></i>
          </div>
          
          <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Email Verified Successfully!</h2>
          <p class="text-sm text-slate-600 mt-2">
            Welcome, <strong class="text-slate-800"><?php echo htmlspecialchars($user_data['full_name']); ?></strong>! Your account has been activated.
          </p>
          <p class="text-xs text-slate-500 mt-1">
            Your login password has been sent to your email (<span class="font-semibold text-slate-700"><?php echo htmlspecialchars($user_data['email']); ?></span>).
          </p>

          <!-- Password Display Card -->
          <div class="mt-6 bg-slate-50 border border-slate-200 rounded-xl p-5 text-left shadow-sm">
            <div class="flex items-center justify-between mb-3 border-b border-slate-200 pb-2">
              <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Your Login Credentials</span>
              <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-blue-100 text-blue-700">
                <?php echo ucfirst(htmlspecialchars($user_data['role'])); ?>
              </span>
            </div>

            <div class="space-y-2.5 text-sm">
              <div>
                <span class="text-xs text-slate-400 block font-medium">Registered Email</span>
                <span class="font-semibold text-slate-800 select-all"><?php echo htmlspecialchars($user_data['email']); ?></span>
              </div>
              <div>
                <span class="text-xs text-slate-400 block font-medium">Auto-Generated Password</span>
                <div class="relative mt-1">
                  <input id="pwdField" type="text" readonly value="<?php echo htmlspecialchars($generated_password); ?>" 
                         class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-base font-mono font-bold text-slate-900 tracking-wider focus:outline-none pr-10" />
                  <button type="button" onclick="copyPassword()" title="Copy Password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition">
                    <i data-lucide="clipboard" class="w-4 h-4"></i>
                  </button>
                </div>
                <span id="copyMsg" class="text-xs text-emerald-600 font-semibold mt-1 inline-block opacity-0 transition-opacity">✓ Password copied to clipboard!</span>
              </div>
            </div>
          </div>

          <!-- Login CTA Button -->
          <div class="mt-6">
            <a href="login.html" 
               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 px-6 py-3 text-sm font-bold text-white shadow-md shadow-blue-500/25 transition-all">
              <i data-lucide="log-in" class="w-4 h-4"></i>
              Proceed to Login
            </a>
          </div>

          <p class="text-xs text-slate-400 mt-4">
            💡 For your security, please change your password from your profile settings after logging in.
          </p>
        </div>

      <?php elseif ($status === 'already_verified'): ?>
        <!-- ALREADY VERIFIED STATE -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mb-4 shadow-sm ring-8 ring-blue-50">
            <i data-lucide="badge-check" class="w-9 h-9"></i>
          </div>
          
          <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Already Verified!</h2>
          <p class="text-sm text-slate-600 mt-2">
            Your email (<strong class="text-slate-800"><?php echo htmlspecialchars($user_data['email']); ?></strong>) has already been verified and your account is active.
          </p>

          <div class="mt-6">
            <a href="login.html" 
               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 px-6 py-3 text-sm font-bold text-white shadow-md shadow-blue-500/25 transition-all">
              <i data-lucide="log-in" class="w-4 h-4"></i>
              Go to Login Page
            </a>
          </div>
        </div>

      <?php elseif ($status === 'expired'): ?>
        <!-- EXPIRED STATE -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mb-4 shadow-sm ring-8 ring-amber-50">
            <i data-lucide="clock" class="w-9 h-9"></i>
          </div>
          
          <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Verification Link Expired</h2>
          <p class="text-sm text-slate-600 mt-2">
            This verification link has expired (links are valid for 24 hours).
          </p>
          <p class="text-xs text-slate-500 mt-2">
            Please ask your system administrator to resend the verification link from the <strong>Manage Users</strong> portal.
          </p>

          <div class="mt-6">
            <a href="login.html" 
               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-slate-200 hover:bg-slate-300 px-6 py-3 text-sm font-semibold text-slate-700 transition-all">
              Back to Login
            </a>
          </div>
        </div>

      <?php else: ?>
        <!-- INVALID STATE -->
        <div class="text-center">
          <div class="w-16 h-16 mx-auto bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mb-4 shadow-sm ring-8 ring-rose-50">
            <i data-lucide="alert-triangle" class="w-9 h-9"></i>
          </div>
          
          <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Invalid Verification Link</h2>
          <p class="text-sm text-slate-600 mt-2">
            <?php echo htmlspecialchars($error_message ?: 'This link is invalid or has expired.'); ?>
          </p>
          <p class="text-xs text-slate-500 mt-2">
            If you need assistance, please contact your college administrator.
          </p>

          <div class="mt-6">
            <a href="login.html" 
               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 px-6 py-3 text-sm font-bold text-white shadow-md shadow-blue-500/25 transition-all">
              Return to Login
            </a>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Footer -->
    <div class="bg-slate-50 border-t border-slate-100 p-4 text-center text-xs text-slate-400">
      Government Polytechnic Patan &bull; Gujarat Technical Education
    </div>

  </div>

  <script>
    lucide.createIcons();

    function copyPassword() {
      const pwdInput = document.getElementById('pwdField');
      if (!pwdInput) return;
      pwdInput.select();
      pwdInput.setSelectionRange(0, 99999);
      navigator.clipboard.writeText(pwdInput.value).then(() => {
        const msg = document.getElementById('copyMsg');
        if (msg) {
          msg.classList.remove('opacity-0');
          setTimeout(() => { msg.classList.add('opacity-0'); }, 3000);
        }
      });
    }
  </script>
</body>
</html>
