<?php
session_start();
require 'db.php';
require_once 'mailer.php';

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

// --- START: CONSOLIDATED ACTION HANDLER ---

// Handle Add User (POST request) with Email Verification & Admin Alert
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'staff');

    if (empty($full_name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: manage-users.php?status=error&message=" . urlencode("Please provide a valid full name and email address."));
        exit();
    }

    if (!in_array($role, ['admin', 'staff'])) {
        $role = 'staff';
    }

    // Check if email already exists
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $chk->bind_param("s", $email);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        header("Location: manage-users.php?status=error&message=" . urlencode("A user with email '{$email}' already exists."));
        exit();
    }

    // Generate verification token (expires in 24 hours) and temporary placeholder password
    $verification_token = bin2hex(random_bytes(32));
    $token_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $temp_password_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $email_verified = 0; // Not verified yet!

    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, email_verified, verification_token, verification_token_expires) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssiss", $full_name, $email, $temp_password_hash, $role, $email_verified, $verification_token, $token_expires);

    if ($stmt->execute()) {
        $stmt->close();

        // Build verification link
        $verify_link = get_app_base_url() . "/verify-email.php?token=" . urlencode($verification_token);

        // 1. Send Email Verification message to the new user
        send_verification_email($full_name, $email, $role, $verify_link);

        // 2. In-app notification for admins
        $new_user_name = htmlspecialchars($full_name);
        $message = "New user '{$new_user_name}' was added (Verification Email Sent).";
        create_admin_notification($conn, $message, 'manage-users.php', $_SESSION['user_id']);

        // 3. Send Email Alert to all administrators
        $manage_url = get_app_base_url() . "/manage-users.php";
        $creator_name = $_SESSION['user_name'] ?? 'Administrator';
        $admin_res = $conn->query("SELECT full_name, email FROM users WHERE role = 'admin'");
        if ($admin_res) {
            while ($admin_row = $admin_res->fetch_assoc()) {
                if (!empty($admin_row['email'])) {
                    send_admin_new_user_alert_email(
                        $admin_row['full_name'],
                        $admin_row['email'],
                        $full_name,
                        $email,
                        $role,
                        $creator_name,
                        $manage_url
                    );
                }
            }
            $admin_res->free();
        }

        header("Location: manage-users.php?status=verification_sent&user_name=" . urlencode($full_name) . "&email=" . urlencode($email) . "&verify_link=" . urlencode($verify_link));
    } else {
        header("Location: manage-users.php?status=error&message=" . urlencode($stmt->error));
        $stmt->close();
    }
    exit();
}

// Handle Resend Verification Email (GET request)
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] === 'resend_verification') {
    $user_id_to_resend = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($user_id_to_resend > 0) {
        $stmt = $conn->prepare("SELECT id, full_name, email, role, email_verified FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id_to_resend);
        $stmt->execute();
        $target_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($target_user && (int)$target_user['email_verified'] === 0) {
            $new_token = bin2hex(random_bytes(32));
            $new_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $up = $conn->prepare("UPDATE users SET verification_token = ?, verification_token_expires = ? WHERE id = ?");
            $up->bind_param("ssi", $new_token, $new_expires, $user_id_to_resend);
            $up->execute();
            $up->close();

            $verify_link = get_app_base_url() . "/verify-email.php?token=" . urlencode($new_token);
            send_verification_email($target_user['full_name'], $target_user['email'], $target_user['role'], $verify_link);

            header("Location: manage-users.php?status=verification_resent&user_name=" . urlencode($target_user['full_name']) . "&email=" . urlencode($target_user['email']) . "&verify_link=" . urlencode($verify_link));
            exit();
        }
    }
    header("Location: manage-users.php?status=error&message=" . urlencode("User not found or is already verified."));
    exit();
}

// Handle Delete User (GET request)
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] === 'delete_user') {
    $user_id_to_delete = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($user_id_to_delete === $_SESSION['user_id']) {
        header("Location: manage-users.php?status=error&message=" . urlencode("You cannot delete your own account."));
    } elseif ($user_id_to_delete > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id_to_delete);
        if ($stmt->execute()) {
            header("Location: manage-users.php?status=user_deleted");
        } else {
            header("Location: manage-users.php?status=error&message=" . urlencode($stmt->error));
        }
        $stmt->close();
    }
    exit();
}

// Helper function to generate initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Fetch all users from the database to display (including email_verified status)
$users = [];
$result = $conn->query("SELECT id, full_name, email, role, email_verified, created_at FROM users ORDER BY created_at DESC");
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// Fetch pending email change requests for admin view
$pending_email_changes = [];
$ecr_result = $conn->query("SELECT r.id, r.user_id, r.current_email, r.new_email, r.status, r.requested_at, r.email_verified, u.full_name, u.role as user_role FROM email_change_requests r JOIN users u ON r.user_id = u.id WHERE r.status = 'pending_admin_approval' ORDER BY r.requested_at DESC");
if ($ecr_result) {
    $pending_email_changes = $ecr_result->fetch_all(MYSQLI_ASSOC);
}

$current_page = 'manage-users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Users - KDP Asset Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
  </script>
  <style>
    html, body { font-family: 'Inter', sans-serif; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 9999px; }
    ::-webkit-scrollbar-thumb:hover { background: #D1D5DB; }
  </style>
  <link rel="stylesheet" href="loader/loader.css" />
  <link rel="stylesheet" href="notifications.css" />
</head>
<body class="h-screen bg-slate-50 text-slate-900 antialiased">
  <?php include 'loader/loader.html'; ?>
<div class="h-screen flex overflow-hidden">
  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>
  <!-- Overlay for mobile sidebar -->
  <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

  <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
    <!-- Header -->
    <?php include 'topbar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto flex flex-col">
      <main class="flex-1 bg-slate-50 p-4 lg:p-6">
        <div class="max-w-4xl mx-auto">
          <h1 class="text-2xl font-bold text-gray-900 tracking-tight mb-6">Manage Users</h1>

          <!-- Add New User Form -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Add New User</h2>
            <form action="manage-users.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <input type="hidden" name="action" value="add_user">
              <div>
                <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="full_name" id="full_name" required class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition">
              </div>
              <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="email" required class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition">
              </div>
              <div>
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Access Level (Role)</label>
                <select name="role" id="role" required class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition">
                  <option value="staff">Normal User (Staff)</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
              <div class="md:col-span-2">
                <p class="text-xs text-gray-500 -mt-3 mb-4">
                  An activation email containing a secure verification button will be sent to the user. Once they verify their email, their login password will be automatically generated and emailed to them.
                </p>
                <button type="submit" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-6 py-2.5 border border-transparent text-sm font-bold rounded-lg text-white bg-gradient-to-br from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-600 transition-all shadow-md shadow-blue-500/30 hover:shadow-lg hover:shadow-blue-500/40">
                  <i data-lucide="user-plus" style="width:16px;height:16px"></i>
                  Add User &amp; Send Verification
                </button>
              </div>
            </form>
          </div>

          <!-- Existing Users Table -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="p-5 lg:p-6 border-b border-gray-100">
              <h2 class="text-lg font-semibold text-gray-900">Existing Users</h2>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-gray-50">
                  <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3 font-medium">Name</th>
                    <th class="px-6 py-3 font-medium">Email</th>
                    <th class="px-6 py-3 font-medium">Role</th>
                    <th class="px-6 py-3 font-medium">Email Status</th>
                    <th class="px-6 py-3 font-medium">Date Added</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php foreach ($users as $user): ?>
                  <tr>
                    <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($user['email']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $user['role'] === 'admin' ? 'bg-rose-100 text-rose-700' : 'bg-sky-100 text-sky-700'; ?>">
                        <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                      </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <?php if (!isset($user['email_verified']) || (int)$user['email_verified'] === 1): ?>
                        <span class="px-2.5 py-1 inline-flex items-center gap-1 text-xs leading-5 font-semibold rounded-full bg-emerald-100 text-emerald-800">
                          <i data-lucide="check" style="width:12px;height:12px"></i> Verified
                        </span>
                      <?php else: ?>
                        <span class="px-2.5 py-1 inline-flex items-center gap-1 text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800">
                          <i data-lucide="clock" style="width:12px;height:12px"></i> Pending Verification
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <?php if (isset($user['email_verified']) && (int)$user['email_verified'] === 0): ?>
                        <a href="manage-users.php?action=resend_verification&id=<?php echo $user['id']; ?>"
                           class="text-blue-600 hover:text-blue-900 mr-3 inline-flex items-center gap-1 font-semibold"
                           title="Resend activation email to this user">
                          <i data-lucide="send" style="width:13px;height:13px"></i> Resend Email
                        </a>
                      <?php endif; ?>
                      <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="text-indigo-600 hover:underline">Edit</a>
                      <?php if ($user['id'] !== $_SESSION['user_id']): // Prevent showing delete for self ?>
                        <a href="manage-users.php?action=delete_user&id=<?php echo $user['id']; ?>"
                           class="text-red-600 hover:text-red-900 ml-4"
                           onclick="return confirm('Are you sure you want to permanently delete this user? This action cannot be undone.');">Delete</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if (!empty($pending_email_changes)): ?>
          <!-- Pending Email Change Requests -->
          <div class="bg-white rounded-xl shadow-sm border border-amber-200 mt-6">
            <div class="p-5 lg:p-6 border-b border-amber-100 flex items-center justify-between">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">Pending Email Change Requests</h2>
                <p class="text-xs text-gray-400 mt-0.5">Verified requests awaiting your approval or rejection.</p>
              </div>
              <span class="px-2.5 py-1 bg-amber-100 text-amber-800 text-xs font-bold rounded-full"><?php echo count($pending_email_changes); ?> Pending</span>
            </div>
            <div class="divide-y divide-gray-100">
              <?php foreach ($pending_email_changes as $ecr): ?>
              <div class="p-5 lg:p-6 flex flex-col sm:flex-row sm:items-center gap-4 justify-between" id="ecr-row-<?php echo $ecr['id']; ?>">
                <div class="space-y-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 text-sm"><?php echo htmlspecialchars($ecr['full_name']); ?></span>
                    <span class="text-xs px-2 py-0.5 rounded-full <?php echo $ecr['user_role'] === 'admin' ? 'bg-rose-100 text-rose-700' : 'bg-sky-100 text-sky-700'; ?>">
                      <?php echo ucfirst($ecr['user_role']); ?>
                    </span>
                  </div>
                  <p class="text-xs text-gray-500">
                    <span class="line-through text-gray-400"><?php echo htmlspecialchars($ecr['current_email']); ?></span>
                    <span class="mx-1 text-gray-400">→</span>
                    <strong class="text-indigo-700"><?php echo htmlspecialchars($ecr['new_email']); ?></strong>
                  </p>
                  <p class="text-xs text-gray-400">Requested: <?php echo date('d M Y, h:i A', strtotime($ecr['requested_at'])); ?></p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                  <button type="button"
                    onclick="approveEmailChange(<?php echo $ecr['id']; ?>, this)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white transition shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Approve
                  </button>
                  <button type="button"
                    onclick="rejectEmailChange(<?php echo $ecr['id']; ?>, '<?php echo htmlspecialchars(addslashes($ecr['full_name'])); ?>', this)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg bg-white border border-gray-300 hover:bg-rose-50 hover:border-rose-300 hover:text-rose-700 text-gray-600 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Reject
                  </button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </main>
      <?php include 'footer.php'; ?>
    </div>
  </div>
</div>

<!-- Modal for Verification Email Sent (Hosting Production Ready) -->
<div id="verificationModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all overflow-hidden" id="verificationModalContent">
    <div class="p-6 text-center">
      <div class="w-16 h-16 mx-auto flex items-center justify-center bg-emerald-100 rounded-full mb-4 ring-8 ring-emerald-50">
        <i data-lucide="mail-check" class="text-emerald-600 w-8 h-8"></i>
      </div>
      <h3 class="text-xl font-bold text-gray-900" id="verificationModalTitle">User Created Successfully!</h3>
      <p class="text-sm text-gray-600 mt-2">
        An activation email has been sent to <strong id="verificationModalEmail" class="text-slate-800"></strong> for user <strong id="verificationModalUser" class="text-slate-800"></strong>.
      </p>

      <div class="mt-5 p-4 bg-slate-50 rounded-xl text-xs text-slate-700 border border-slate-200/80 leading-relaxed text-left space-y-1.5">
        <span class="font-bold text-slate-900 block flex items-center gap-1.5">
          <i data-lucide="shield-check" class="w-4 h-4 text-blue-600"></i> Next Step for User:
        </span>
        <p class="text-slate-600">
          The user should open their email inbox and click the <strong>Verify Email &amp; Get Password</strong> button. Their password will be generated automatically and emailed to them.
        </p>
      </div>

      <!-- Hidden field for manual copy fallback -->
      <input id="verificationModalLink" type="hidden" value="">
      
      <!-- Subtle manual fallback if user didn't get email -->
      <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-center gap-2 text-xs text-gray-500">
        <span>Email not received?</span>
        <button id="copyVerifyLinkBtn" type="button" class="text-blue-600 font-semibold hover:underline inline-flex items-center gap-1">
          <i data-lucide="copy" class="w-3.5 h-3.5"></i>
          <span>Copy Activation Link</span>
        </button>
      </div>
      <span id="copyVerifyFeedback" class="text-xs font-medium text-emerald-600 h-4 block mt-1 transition-opacity opacity-0">✓ Activation link copied to clipboard!</span>
    </div>

    <div class="bg-gray-50 px-6 py-4 rounded-b-xl">
      <button id="closeVerificationModalBtn" type="button" class="w-full inline-flex justify-center rounded-lg border border-transparent bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700 transition">
        Done
      </button>
    </div>
  </div>
</div>

<!-- Success Modal for New User Password (Legacy / Direct) -->
<div id="passwordModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all" id="passwordModalContent">
    <div class="p-6 text-center">
      <div class="w-16 h-16 mx-auto flex items-center justify-center bg-emerald-100 rounded-full mb-4">
        <i data-lucide="check-circle-2" class="text-emerald-600 w-8 h-8"></i>
      </div>
      <h3 class="text-lg font-bold text-gray-900">User Created Successfully!</h3>
      <p class="text-sm text-gray-500 mt-2">The user <strong id="modalUserName" class="font-semibold text-gray-700"></strong> has been added.</p>
      <p class="text-sm text-gray-500 mt-1">Please provide them with their auto-generated password. This is the only time it will be shown.</p>
      <div class="mt-6">
        <label class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Generated Password</label>
        <div class="relative mt-1">
          <input id="generatedPassword" type="text" readonly class="w-full bg-gray-100 border-2 border-gray-200 rounded-lg text-center text-lg font-mono tracking-widest py-3 text-gray-800 focus:outline-none">
          <button id="copyPasswordBtn" title="Copy to clipboard" class="absolute top-1/2 right-3 -translate-y-1/2 p-2 rounded-md text-gray-400 hover:bg-gray-200 hover:text-gray-700 transition">
            <i data-lucide="clipboard" class="w-5 h-5"></i>
          </button>
        </div>
        <span id="copyFeedback" class="text-xs font-medium text-emerald-600 h-4 block mt-1 transition-opacity opacity-0">Copied to clipboard!</span>
      </div>
    </div>
    <div class="bg-gray-50 px-6 py-4 rounded-b-xl">
      <button id="closeModalBtn" class="w-full inline-flex justify-center rounded-lg border border-transparent bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
        Done
      </button>
    </div>
  </div>
</div>

<!-- Profile Security Modal (Change Password & Email) -->
<?php include_once __DIR__ . '/profile_security_modal.php'; ?>

<!-- Error/Success Notification -->
<div id="notification" class="fixed top-5 right-5 bg-red-500 text-white py-2.5 px-5 rounded-lg shadow-xl text-sm font-medium hidden z-[60]">
  <!-- Message will be inserted here -->
</div>

<script>
  lucide.createIcons();
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  const mainContent = document.getElementById('mainContent');
  const menuBtn = document.getElementById('menuBtn');
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenuDropdown = document.getElementById('userMenuDropdown');

  function toggleSidebar() {
      if (!sidebar) return;
      if (window.innerWidth < 1024) {
          sidebar.classList.toggle('-translate-x-full');
          if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
      } else {
          sidebar.classList.toggle('lg:translate-x-0');
          if (mainContent) mainContent.classList.toggle('lg:ml-64');
      }
  }

  if (menuBtn) menuBtn.addEventListener('click', toggleSidebar);
  if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);

  if (userMenuBtn && userMenuDropdown) {
      userMenuBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          userMenuDropdown.classList.toggle('hidden');
      });
      document.addEventListener('click', () => {
          userMenuDropdown.classList.add('hidden');
      });
  }

  // --- Modal and Notification Logic ---
  document.addEventListener('DOMContentLoaded', () => {
    const passwordModal = document.getElementById('passwordModal');
    const modalUserName = document.getElementById('modalUserName');
    const generatedPasswordInput = document.getElementById('generatedPassword');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const copyPasswordBtn = document.getElementById('copyPasswordBtn');
    const copyFeedback = document.getElementById('copyFeedback');
    const notification = document.getElementById('notification');

    // Verification Modal Elements
    const verificationModal = document.getElementById('verificationModal');
    const verificationModalTitle = document.getElementById('verificationModalTitle');
    const verificationModalUser = document.getElementById('verificationModalUser');
    const verificationModalEmail = document.getElementById('verificationModalEmail');
    const verificationModalLink = document.getElementById('verificationModalLink');
    const closeVerificationModalBtn = document.getElementById('closeVerificationModalBtn');
    const copyVerifyLinkBtn = document.getElementById('copyVerifyLinkBtn');
    const copyVerifyFeedback = document.getElementById('copyVerifyFeedback');

    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');

    if (status === 'verification_sent' || status === 'verification_resent') {
      const userName = urlParams.get('user_name') || '';
      const email = urlParams.get('email') || '';
      const verifyLink = urlParams.get('verify_link') || '';

      if (verificationModalTitle) {
        verificationModalTitle.textContent = status === 'verification_resent' ? 'Verification Email Resent!' : 'User Created Successfully!';
      }
      if (verificationModalUser) verificationModalUser.textContent = userName;
      if (verificationModalEmail) verificationModalEmail.textContent = email;
      if (verificationModalLink) verificationModalLink.value = verifyLink;
      if (verificationModal) verificationModal.classList.remove('hidden');
      if (window.lucide) lucide.createIcons();
    } else if (status === 'user_added') {
      const newPassword = urlParams.get('new_password');
      const userName = urlParams.get('user_name');
      
      modalUserName.textContent = userName;
      generatedPasswordInput.value = newPassword;
      passwordModal.classList.remove('hidden');
      if (window.lucide) lucide.createIcons();
    } else if (status === 'error') {
      const message = urlParams.get('message') || 'An unknown error occurred.';
      if (notification) {
        notification.textContent = `Error: ${message}`;
        notification.classList.remove('hidden', 'bg-emerald-500');
        notification.classList.add('bg-red-500');
        setTimeout(() => notification.classList.add('hidden'), 5000);
      }
    } else if (status === 'user_deleted') {
      if (notification) {
        notification.textContent = 'User has been successfully deleted.';
        notification.classList.remove('hidden', 'bg-red-500');
        notification.classList.add('bg-emerald-500');
        setTimeout(() => notification.classList.add('hidden'), 4000);
      }
    }

    if (urlParams.has('status')) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    if (closeModalBtn) {
      closeModalBtn.addEventListener('click', () => {
        passwordModal.classList.add('hidden');
      });
    }

    if (copyPasswordBtn) {
      copyPasswordBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(generatedPasswordInput.value).then(() => {
          copyFeedback.classList.remove('opacity-0');
          setTimeout(() => { copyFeedback.classList.add('opacity-0'); }, 2000);
        });
      });
    }

    if (closeVerificationModalBtn) {
      closeVerificationModalBtn.addEventListener('click', () => {
        verificationModal.classList.add('hidden');
      });
    }

    if (copyVerifyLinkBtn) {
      copyVerifyLinkBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(verificationModalLink.value).then(() => {
          copyVerifyFeedback.classList.remove('opacity-0');
          setTimeout(() => { copyVerifyFeedback.classList.add('opacity-0'); }, 2000);
        });
      });
    }
  });

  // --- Email Change Request Actions ---
  async function approveEmailChange(requestId, btn) {
    if (!confirm('Are you sure you want to approve this email change request? The user\'s email will be updated immediately.')) return;
    btn.disabled = true;
    btn.textContent = 'Approving...';
    try {
      const fd = new FormData();
      fd.append('action', 'approve_email_change');
      fd.append('request_id', requestId);
      const res = await fetch('account-actions.php', { method: 'POST', body: fd });
      const data = await res.json();
      const notification = document.getElementById('notification');
      if (data.success) {
        const row = document.getElementById('ecr-row-' + requestId);
        if (row) {
          row.innerHTML = '<div class="p-4 text-sm text-emerald-700 font-medium bg-emerald-50 rounded-lg">✓ ' + (data.message || 'Email change approved successfully.') + '</div>';
          setTimeout(() => row.remove(), 3000);
        }
        if (notification) {
          notification.textContent = data.message || 'Email change approved.';
          notification.className = 'fixed top-5 right-5 bg-emerald-500 text-white py-2.5 px-5 rounded-lg shadow-xl text-sm font-medium z-[60]';
          setTimeout(() => notification.classList.add('hidden'), 4000);
        }
      } else {
        alert('Error: ' + (data.message || 'Could not approve request.'));
        btn.disabled = false;
        btn.textContent = 'Approve';
      }
    } catch (err) {
      alert('Network error. Please try again.');
      btn.disabled = false;
      btn.textContent = 'Approve';
    }
  }

  async function rejectEmailChange(requestId, userName, btn) {
    const reason = prompt('Reject email change for "' + userName + '".\n\nEnter a rejection reason (optional):', 'Declined by administrator.');
    if (reason === null) return;
    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action', 'reject_email_change');
      fd.append('request_id', requestId);
      fd.append('reason', reason || 'Declined by administrator.');
      const res = await fetch('account-actions.php', { method: 'POST', body: fd });
      const data = await res.json();
      const notification = document.getElementById('notification');
      if (data.success) {
        const row = document.getElementById('ecr-row-' + requestId);
        if (row) {
          row.innerHTML = '<div class="p-4 text-sm text-gray-500 font-medium bg-gray-50 rounded-lg">✗ ' + (data.message || 'Request rejected.') + '</div>';
          setTimeout(() => row.remove(), 3000);
        }
        if (notification) {
          notification.textContent = data.message || 'Email change request rejected.';
          notification.className = 'fixed top-5 right-5 bg-amber-500 text-white py-2.5 px-5 rounded-lg shadow-xl text-sm font-medium z-[60]';
          setTimeout(() => notification.classList.add('hidden'), 4000);
        }
      } else {
        alert('Error: ' + (data.message || 'Could not reject request.'));
        btn.disabled = false;
      }
    } catch (err) {
      alert('Network error. Please try again.');
      btn.disabled = false;
    }
  }
</script>
<script src="loader/loader.js"></script>
</body>
</html>
