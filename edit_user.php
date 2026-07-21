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
    header("Location: manage-users.php?status=error&message=Invalid User ID");
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
    header("Location: manage-users.php?status=error&message=User not found");
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
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">

<div class="h-screen flex overflow-hidden">

  <!-- Sidebar -->
  <aside class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 lg:static">
    <div class="h-16 flex items-center gap-3 px-4 border-b border-gray-200 shrink-0">
      <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center shrink-0 p-1">
        <img src="https://scontent.famd8-1.fna.fbcdn.net/v/t39.30808-6/482345949_1144079087415361_6640568596786112832_n.jpg?stp=dst-jpg_tt6&cstp=mx1379x1379&ctp=s1379x1379&_nc_cat=111&ccb=1-7&_nc_sid=6ee11a&_nc_ohc=dqzfFwO_7hEQ7kNvwHlS2Lc&_nc_oc=AdqNTZYLxmQKL2WaL9V7X7C6O9y9HIZNlpZiBqOTr3chZ-WT57nGAbpKFdbH0IayXk4&_nc_zt=23&_nc_ht=scontent.famd8-1.fna&_nc_gid=512jtex-NyXTQ9YEE2yRCg&_nc_ss=7b289&oh=00_AQCFoi8YrRQThI_Qg2e3SPWGXJTNIXX5tSQO7LOxr-Rw5w&oe=6A5E8523" alt="KDP Logo" class="w-full h-full object-contain">
      </div>
      <span class="font-bold text-sm tracking-tight text-gray-900">Smart Asset Manager</span>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
      <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i> Dashboard
      </a>
      <a href="dashboard.php?view=register" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="book-open" style="width:18px;height:18px"></i> Virtual Register
      </a>
      <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-blue-50 text-blue-600 text-sm font-medium">
        <i data-lucide="users" style="width:18px;height:18px"></i> Manage Users
      </a>
    </nav>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <!-- Header -->
    <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-end px-4 lg:px-6">
        <div class="flex items-center gap-3 sm:gap-4">
            <div class="relative">
                <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6">
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
  </div>
</div>

<script>
  lucide.createIcons();
</script>

</body>
</html>