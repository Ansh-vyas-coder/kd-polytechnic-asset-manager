<?php
session_start();
require 'db.php';

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$current_page = 'assigned-assets';

// Fetch assigned assets
$sql_where_clauses = ["assigned_to IS NOT NULL", "assigned_to != ''", "retire_at IS NULL"];
$sql_params = [];
$sql_types = "";

if ($_SESSION['role'] === 'staff') {
    $sql_where_clauses[] = "assigned_to = ?";
    $sql_types .= "s";
    $sql_params[] = $_SESSION['user_name'];
}

$items = [];
$sql = "SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, updated_at FROM assets WHERE " . implode(" AND ", $sql_where_clauses) . " ORDER BY assigned_to ASC, updated_at DESC";

if (!empty($sql_params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($sql_types, ...$sql_params);
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $items = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Helper function to generate initials
if (!function_exists('getInitials')) {
    function getInitials($name)
    {
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        return substr($initials, 0, 2);
    }
}

// Categories for display
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Assigned Assets - KDP Asset Manager</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
  tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
</script>
<style>
  html, body { font-family: 'Inter', sans-serif; }
  .clickable-row:hover { background-color: #f9fafb; cursor: pointer; }
</style>
<link rel="stylesheet" href="loader/loader.css" />
</head>

<body class="h-screen bg-slate-50 text-slate-900 antialiased">
  <?php include 'loader/loader.html'; ?>

<div class="h-screen flex overflow-hidden">

  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>

  <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
    <!-- Header -->
    <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                <i data-lucide="menu" style="width:20px;height:20px"></i>
            </button>
        </div>
        <div class="flex items-center gap-3 sm:gap-4 shrink-0">
            <div class="relative">
                <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
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
    <div class="flex-1 overflow-y-auto flex flex-col">
      <main class="flex-1 bg-slate-50 p-4 lg:p-6">
        <div class="max-w-7xl mx-auto">
          <div class="flex items-center justify-between mb-6">
              <div>
                  <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
                      <?php echo $_SESSION['role'] === 'staff' ? 'My Assigned Assets' : 'All Assigned Assets'; ?>
                  </h1>
                  <p class="text-sm text-gray-500 mt-1">List of all assets currently checked out to staff.</p>
              </div>
          </div>

          <!-- Assigned Assets Table -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="overflow-x-auto">
              <table class="w-full text-sm min-w-[720px]">
                <thead class="bg-gray-50">
                  <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3 font-medium">Asset Name</th>
                    <th class="px-6 py-3 font-medium">Asset ID</th>
                    <th class="px-6 py-3 font-medium">Category</th>
                    <th class="px-6 py-3 font-medium">Assigned To</th>
                    <th class="px-6 py-3 font-medium">Location</th>
                    <th class="px-6 py-3 font-medium text-right">Last Updated</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($items)): ?>
                      <tr>
                          <td colspan="6" class="text-center py-16 text-gray-500">
                              <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                  <i data-lucide="user-check" class="w-7 h-7 text-gray-400"></i>
                              </div>
                              <h3 class="font-semibold text-gray-800">No Assets Assigned</h3>
                              <p class="text-sm mt-1">There are no assets currently checked out.</p>
                          </td>
                      </tr>
                  <?php else: ?>
                      <?php foreach ($items as $item): ?>
                      <tr class="clickable-row" data-href="view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>">
                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($categories[$item['category_id']] ?? 'Unknown'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-800 font-medium"><?php echo htmlspecialchars($item['assigned_to']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500 text-right"><?php echo date('M d, Y', strtotime($item['updated_at'])); ?></td>
                      </tr>
                      <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </main>
      <?php include 'footer.php'; ?>
    </div>
  </div>
</div>

<script>
  lucide.createIcons();

  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenuDropdown = document.getElementById('userMenuDropdown');
  const sidebar = document.getElementById('sidebar');
  const mainContent = document.getElementById('mainContent');
  const menuBtn = document.getElementById('menuBtn');

  function toggleSidebar() {
      sidebar.classList.toggle('-translate-x-full');
      mainContent.classList.toggle('lg:ml-64');
  }

  menuBtn.addEventListener('click', toggleSidebar);
  if (window.innerWidth < 1024) {
      sidebar.classList.add('-translate-x-full');
      mainContent.classList.remove('lg:ml-64');
  }

  userMenuBtn.addEventListener('click', () => userMenuDropdown.classList.toggle('hidden'));
  document.addEventListener('click', (event) => {
    if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
      userMenuDropdown.classList.add('hidden');
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
      const rows = document.querySelectorAll('.clickable-row');
      rows.forEach(row => {
          row.addEventListener('click', () => {
              window.location.href = row.dataset.href;
          });
      });
  });
</script>
<script src="loader/loader.js"></script>
</body>
</html>