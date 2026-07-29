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

// Get category ID from URL, default to 0 if not set
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Define categories to get the name from the ID
$categories = [
  1 => 'Expandable',
  2 => 'Consumables',
  3 => 'Deadstock',
  4 => 'Furniture'
];

// Check if the category ID is valid
if ($category_id === 0 || !array_key_exists($category_id, $categories)) {
  // Redirect or show an error if the category is invalid
  header("Location: dashboard.php?status=error&message=" . urlencode("Invalid category specified."));
  exit();
}

$category_name = $categories[$category_id];

$sql_where_clauses = ["category_id = ?", "retire_at IS NULL"];
$sql_params = ["i", $category_id];

if (!empty($search_query)) {
  $sql_where_clauses[] = "asset_name LIKE ?";
  $sql_params[0] .= "s";
  $search_term = "%" . $search_query . "%";
  $sql_params[] = $search_term;
}

// Fetch and group assets by name for the specified category
$assets = [];
$sql = "
    SELECT asset_name, 
           SUM(quantity) as total_quantity, 
           COUNT(DISTINCT batch_id) as record_count,
           MIN(date_of_issue) as first_issue_date
    FROM assets 
    WHERE " . implode(" AND ", $sql_where_clauses) . "
    GROUP BY asset_name 
    ORDER BY asset_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(...$sql_params);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
  $assets = $result->fetch_all(MYSQLI_ASSOC);
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>View <?php echo htmlspecialchars($category_name); ?> - KDP Asset Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'sans-serif']
          }
        }
      }
    };
  </script>
  <style>
    html,
    body {
      font-family: 'Inter', sans-serif;
    }

    .clickable-row:hover {
      background-color: #f9fafb;
      cursor: pointer;
    }
  </style>

  <link rel="stylesheet" href="loader/loader.css" />

</head>

<body class="h-screen bg-gray-50 text-gray-900 antialiased">

  <?php include 'loader/loader.html'; ?>
  
  <div class="h-screen flex overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
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
      <div class="flex-1 overflow-y-auto flex flex-col">
        <main class="flex-1 bg-gray-50 p-4 lg:p-6">

          <!-- Breadcrumb Navigation -->
          <div class="mb-6">
            <nav class="text-sm font-medium text-gray-500 mb-3">
              <a href="dashboard.php" class="hover:text-blue-600 transition-colors">Dashboard</a>
              <span class="mx-2 text-gray-400">&gt;</span>
              <span class="text-gray-900"><?php echo htmlspecialchars($category_name); ?></span>
            </nav>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Assets: <?php echo htmlspecialchars($category_name); ?></h1>
              <form action="view-assets.php" method="GET" class="flex items-center gap-2">
                <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                <div class="relative flex-grow">
                  <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search in <?php echo htmlspecialchars($category_name); ?>..." class="w-full pl-4 pr-10 py-2.5 text-sm rounded-full bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                  <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <i data-lucide="search" style="width:16px;height:16px"></i>
                  </button>
                </div>
                <?php if (!empty($search_query)): ?>
                  <a href="view-assets.php?category_id=<?php echo $category_id; ?>" class="px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 rounded-full hover:bg-gray-200">Clear</a>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <!-- Assets Table -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-gray-50">
                  <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3 font-medium">Component Name</th>
                    <th class="px-6 py-3 font-medium">Total Quantity</th>
                    <th class="px-6 py-3 font-medium">Entry Records</th>
                    <th class="px-6 py-3 font-medium">First Added</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($assets)): ?>
                    <tr>
                      <td colspan="5" class="text-center py-16 text-gray-500">
                        <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                          <i data-lucide="search-slash" class="w-7 h-7 text-gray-400"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800">No assets found</h3>
                        <p class="text-sm mt-1"><?php echo !empty($search_query) ? 'Your search for "' . htmlspecialchars($search_query) . '" did not return any results.' : 'There are no assets in this category yet.'; ?></p>
                        No assets found in this category.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($assets as $asset): ?>
                      <tr
                        class="clickable-row transition-colors duration-150"
                        data-href="view-asset-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset['asset_name']); ?>">
                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900 capitalize"><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600 font-bold"><?php echo htmlspecialchars($asset['total_quantity']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($asset['record_count']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?php echo date('M d, Y', strtotime($asset['first_issue_date'])); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                          <a href="view-asset-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset['asset_name']); ?>" class="text-indigo-600 hover:text-indigo-900" onclick="event.stopPropagation()">View Details</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </main>
        <?php include 'footer.php'; ?>
      </div>
    </div>
  </div>

  <script>
    lucide.createIcons();

    document.addEventListener('DOMContentLoaded', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');
      const menuBtn = document.getElementById('menuBtn');
      const userMenuBtn = document.getElementById('userMenuBtn');
      const userMenuDropdown = document.getElementById('userMenuDropdown');

      function toggleSidebar() {
          sidebar.classList.toggle('-translate-x-full');
          mainContent.classList.toggle('lg:ml-64');
      }

      if (menuBtn) {
          menuBtn.addEventListener('click', toggleSidebar);
      }
      if (window.innerWidth < 1024) {
          sidebar.classList.add('-translate-x-full');
          mainContent.classList.remove('lg:ml-64');
      }

      if (userMenuDropdown) {
          userMenuBtn.addEventListener('click', () => userMenuDropdown.classList.toggle('hidden'));
      }
      if (userMenuDropdown) {
          document.addEventListener('click', (event) => { if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) { userMenuDropdown.classList.add('hidden'); } });
      }

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