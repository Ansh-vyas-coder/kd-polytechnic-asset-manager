<?php
session_start();
require 'db.php';

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

// Handle Add User (POST request)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $role = $_POST['role'];

    $plain_password = bin2hex(random_bytes(8));
    $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $full_name, $email, $password_hash, $role);

    if ($stmt->execute()) {
        header("Location: manage-users.php?status=user_added&new_password=" . urlencode($plain_password) . "&user_name=" . urlencode($full_name));
    } else {
        header("Location: manage-users.php?status=error&message=" . urlencode($stmt->error));
    }
    $stmt->close();
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

// Fetch all users from the database to display
$users = [];
$result = $conn->query("SELECT id, full_name, email, role, created_at FROM users ORDER BY created_at DESC");
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}
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
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">

<div class="h-screen flex overflow-hidden">

  <!-- Sidebar -->
  <aside id="sidebar" class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 lg:static transition-transform duration-200 ease-out">
    <div class="h-16 flex items-center gap-3 px-4 border-b border-gray-200 shrink-0">
      <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center shrink-0 p-1">
        <img src="kdp_logo.jpeg" alt="KDP Logo" class="w-full h-full object-contain">
      </div>
      <span class="font-bold text-sm tracking-tight text-gray-900">Smart Asset Manager</span>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
      <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i> Dashboard
      </a>
      <a href="dashboard.php?view=add-asset" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="plus-square" style="width:18px;height:18px"></i> Add Item(s)
      </a>
      <a href="dashboard.php?view=register" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="book-open" style="width:18px;height:18px"></i> Virtual Register
      </a>
      <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-blue-50 text-blue-600 text-sm font-semibold">
        <i data-lucide="users" style="width:18px;height:18px"></i> Manage Users
      </a>
    </nav>
  </aside>

  <div id="overlay" class="fixed inset-0 bg-gray-900/30 z-30 hidden"></div>

  <div class="flex-1 flex flex-col min-w-0">
    <!-- Header -->
    <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <button id="menuBtn" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                <i data-lucide="menu" style="width:20px;height:20px"></i>
            </button>
        </div>
        <div class="flex items-center gap-3 sm:gap-4 shrink-0">
            <div class="relative">
                <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
                    <div class="hidden sm:block text-left leading-tight">
                        <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-xs text-gray-400"><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?> - Computer Dept.</p>
                    </div>
                    <i data-lucide="chevron-down" class="hidden sm:block text-gray-400 group-hover:text-gray-600 transition-colors" style="width:16px;height:16px"></i>
                </button>
                <div id="userMenuDropdown" class="absolute top-full right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 hidden z-10">
                    <div class="p-3 border-b border-gray-100">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
                    </div>
                    <div class="p-1.5">
                        <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                            <i data-lucide="log-out" style="width:16px;height:16px"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6">
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
                    A secure, random password will be automatically generated for the new user.
                </p>
              <button type="submit" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-6 py-2.5 border border-transparent text-sm font-bold rounded-lg text-white bg-gradient-to-br from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md shadow-blue-500/30 hover:shadow-lg hover:shadow-blue-500/40">
                <i data-lucide="user-plus" style="width:16px;height:16px"></i>
                Add User
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
                  <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
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
      </div>
    </main>
  </div>
</div>

<!-- Success Modal for New User Password -->
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

<!-- Error/Success Notification -->
<div id="notification" class="fixed top-5 right-5 bg-red-500 text-white py-2.5 px-5 rounded-lg shadow-xl text-sm font-medium hidden">
  <!-- Message will be inserted here -->
</div>

<script>
  lucide.createIcons();

  // Sidebar and User Menu interactivity
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const menuBtn = document.getElementById('menuBtn');
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenuDropdown = document.getElementById('userMenuDropdown');

  menuBtn.addEventListener('click', () => {
    sidebar.classList.remove('-translate-x-full');
    overlay.classList.remove('hidden');
  });
  overlay.addEventListener('click', () => {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
  });
  userMenuBtn.addEventListener('click', () => userMenuDropdown.classList.toggle('hidden'));
  document.addEventListener('click', (event) => {
    if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
      userMenuDropdown.classList.add('hidden');
    }
  });

  // --- New Modal and Notification Logic ---
  document.addEventListener('DOMContentLoaded', () => {
    const passwordModal = document.getElementById('passwordModal');
    const modalUserName = document.getElementById('modalUserName');
    const generatedPasswordInput = document.getElementById('generatedPassword');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const copyPasswordBtn = document.getElementById('copyPasswordBtn');
    const copyFeedback = document.getElementById('copyFeedback');
    const notification = document.getElementById('notification');

    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');

    if (status === 'user_added') {
      const newPassword = urlParams.get('new_password');
      const userName = urlParams.get('user_name');
      
      modalUserName.textContent = userName;
      generatedPasswordInput.value = newPassword;
      passwordModal.classList.remove('hidden');
      lucide.createIcons(); // Re-render icons inside the modal

    } else if (status === 'error') {
      const message = urlParams.get('message') || 'An unknown error occurred.';
      notification.textContent = `Error: ${message}`;
      notification.classList.remove('hidden', 'bg-emerald-500');
      notification.classList.add('bg-red-500');
      setTimeout(() => notification.classList.add('hidden'), 5000);
    }
    else if (status === 'user_deleted') {
        notification.textContent = 'User has been successfully deleted.';
        notification.classList.remove('hidden', 'bg-red-500');
        notification.classList.add('bg-emerald-500'); // Green for success
        setTimeout(() => notification.classList.add('hidden'), 4000);
    }


    // Clean the URL to prevent the modal from reappearing on refresh
    if (urlParams.has('status')) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    closeModalBtn.addEventListener('click', () => {
      passwordModal.classList.add('hidden');
    });

    copyPasswordBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(generatedPasswordInput.value).then(() => {
        copyFeedback.classList.remove('opacity-0');
        setTimeout(() => {
          copyFeedback.classList.add('opacity-0');
        }, 2000);
      });
    });
  });

</script>

</body>
</html>