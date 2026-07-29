<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

// --- START: CONSOLIDATED ACTION HANDLER ---

// Handle Update User (POST request)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    $user_id = $_POST['user_id'];
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $role = $_POST['role'];

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?");
    $stmt->bind_param("sssi", $full_name, $email, $role, $user_id);

    if ($stmt->execute()) {
        header("Location: manage-users.php?status=user_updated");
    } else {
        header("Location: manage-users.php?status=error&message=" . urlencode($stmt->error));
    }
    $stmt->close();
    exit();
}

// --- Display Edit Form (GET request) ---
// Get the user ID from the URL
$user_id_to_edit = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id_to_edit <= 0) {
    header("Location: 404.php");
    exit();
}

// Fetch the user's data from the database
$stmt = $conn->prepare("SELECT full_name, email, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id_to_edit);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: 404.php");
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

$current_page = 'manage-users';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Edit User - KDP Asset Manager</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
  tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
</script>
<style>
  html, body { font-family: 'Inter', sans-serif; }
</style>
    <link rel="stylesheet" href="loader/loader.css" />

<body class="h-screen bg-gray-50 text-gray-900 antialiased">
  <?php include 'loader/loader.html'; ?>

<div class="h-screen flex overflow-hidden">

  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>

  <!-- Overlay for mobile sidebar -->
  <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

  <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
    <!-- Header -->
    <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6">
        <div class="flex items-center gap-2">
            <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                <i data-lucide="menu" style="width:20px;height:20px"></i>
            </button>
        </div>
        <div class="flex items-center gap-3 sm:gap-4">
            <div class="relative">
                <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
                    <div class="hidden sm:block text-left leading-tight">
                        <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-xs text-gray-400"><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?> - Computer Dept.</p>
                    </div>
                    <i data-lucide="chevron-down" class="hidden sm:block text-gray-400 group-hover:text-gray-600 transition-colors" style="width:16px;height:16px"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto flex flex-col">
      <main class="flex-1 bg-gray-50 p-4 lg:p-6">
        <div class="max-w-4xl mx-auto">
          <h1 class="text-2xl font-bold text-gray-900 tracking-tight mb-6">Edit User</h1>

          <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form action="edit_user.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <input type="hidden" name="action" value="update_user">
              <input type="hidden" name="user_id" value="<?php echo $user_id_to_edit; ?>">
              
              <div>
                <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="full_name" id="full_name" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg" value="<?php echo htmlspecialchars($user['full_name']); ?>">
              </div>
              
              <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="email" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg" value="<?php echo htmlspecialchars($user['email']); ?>">
              </div>

              <div>
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Access Level (Role)</label>
                <select name="role" id="role" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg">
                  <option value="staff" <?php echo ($user['role'] === 'staff') ? 'selected' : ''; ?>>Normal User (Staff)</option>
                  <option value="admin" <?php echo ($user['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                </select>
              </div>

              <div class="md:col-span-2 mt-4 flex items-center gap-4">
                <button type="submit" class="w-full sm:w-auto inline-flex justify-center items-center px-6 py-2.5 border border-transparent text-sm font-bold rounded-lg shadow-sm text-white bg-blue-600 hover:bg-blue-700">
                  Save Changes
                </button>
                <a href="manage-users.php" class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</a>
              </div>
            </form>
          </div>
        </div>
      </main>
      <?php include 'footer.php'; ?>
    </div>
  </div>
</div>

<script>
    lucide.createIcons();

    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const menuBtn = document.getElementById('menuBtn');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        if (!sidebar) return;

        if (window.innerWidth < 1024) {
            // Mobile: Toggle the class that hides the sidebar and show/hide the overlay.
            sidebar.classList.toggle('-translate-x-full');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
        } else {
            // Desktop: Toggle the responsive class that shows the sidebar and adjust main content margin.
            sidebar.classList.toggle('lg:translate-x-0');
            if (mainContent) mainContent.classList.toggle('lg:ml-64');
        }
    }

    if (menuBtn) menuBtn.addEventListener('click', toggleSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);

    const userMenuBtn = document.getElementById('userMenuBtn');
    const userMenuDropdown = document.getElementById('userMenuDropdown');
    userMenuBtn.addEventListener('click', () => userMenuDropdown.classList.toggle('hidden'));
    document.addEventListener('click', (event) => { if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) { userMenuDropdown.classList.add('hidden'); } });
</script>

  <script src="loader/loader.js"></script>

</html>