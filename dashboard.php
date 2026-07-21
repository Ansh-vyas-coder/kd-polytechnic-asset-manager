<?php
session_start();
require 'db.php'; // Include the database connection

// If the user is not logged in, redirect them to the login page

// Prevent browser caching of the dashboard page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id'])) {
  header("Location: login.html");
  exit();
}

$pageView = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
$showAddAsset = $pageView === 'add-asset';
$showRegister = $pageView === 'register';

// --- START: Fetch asset counts for dashboard widgets ---
$category_counts = [
  1 => 0, // Expandable
  2 => 0, // Consumables
  3 => 0, // Deadstock
  4 => 0  // Furniture
];

$result = $conn->query("SELECT category_id, SUM(quantity) as total_quantity FROM assets GROUP BY category_id");
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $category_counts[$row['category_id']] = (int)$row['total_quantity'];
  }
}
// --- END: Fetch asset counts ---

// Maximum value for chart scaling
$maxCount = max($category_counts);

if ($maxCount <= 0) {
  $maxCount = 1;
}

// Maximum chart height (pixels)
$chartMaxHeight = 180;

$chartHeights = [];

foreach ($category_counts as $id => $count) {
  $chartHeights[$id] = max(
    15,
    round(($count / $maxCount) * $chartMaxHeight)
  );
}

// Helper function to generate initials from a name
function getInitials($name)
{
  $words = explode(' ', $name);
  $initials = '';
  foreach ($words as $word) {
    $initials .= strtoupper(substr($word, 0, 1));
  }
  return substr($initials, 0, 2); // Return the first 2 initials
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>KDP Asset Manager — Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
          },
        },
      },
    };
  </script>
  <style>
    html,
    body {
      font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
    }

    ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }

    ::-webkit-scrollbar-track {
      background: transparent;
    }

    ::-webkit-scrollbar-thumb {
      background: #E5E7EB;
      border-radius: 9999px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #D1D5DB;
    }
  </style>
</head>

<body class="h-screen bg-gray-50 text-gray-900 antialiased">

  <div class="h-screen flex overflow-hidden">

    <aside id="sidebar"
      class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 lg:static transition-transform duration-200 ease-out">

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
      <a href="dashboard.php?view=dashboard" class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?php echo !$showAddAsset ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'; ?> text-sm font-medium">
        <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i>
        Dashboard
      </a>
      <a href="dashboard.php?view=add-asset" class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?php echo $showAddAsset ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'; ?> text-sm font-medium transition-colors">
        <i data-lucide="plus-square" style="width:18px;height:18px"></i>
        Add Item(s)
      </a>
      <a href="dashboard.php?view=register" class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?php echo $showRegister ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'; ?> text-sm font-medium transition-colors">
        <i data-lucide="book-open" style="width:18px;height:18px"></i>
        Virtual Register
      </a>
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="users" style="width:18px;height:18px"></i>
        Manage Users
      </a>
      <?php endif; ?>
    </nav>
  </aside>

  <div id="overlay" class="fixed inset-0 bg-gray-900/30 z-30 hidden"></div>

  <div class="flex-1 flex flex-col min-w-0">

    <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
      <div class="flex items-center gap-2 flex-1 min-w-0">
        <button id="menuBtn" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
          <i data-lucide="menu" style="width:20px;height:20px"></i>
        </button>
        <div class="relative w-full max-w-md">
          <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="width:16px;height:16px"></i>
          <input type="text" placeholder="Search assets, locations, categories..."
            class="w-full pl-10 pr-4 py-2.5 rounded-full bg-gray-50 border border-gray-200 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300 transition" />
        </div>
        <span class="font-bold text-sm tracking-tight text-gray-900">Smart Asset Manager</span>
      </div>

      <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        <a href="dashboard.php?view=dashboard" class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?php echo !$showAddAsset ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'; ?> text-sm font-medium">
          <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i>
          Dashboard
        </a>
        <a href="dashboard.php?view=add-asset" class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?php echo $showAddAsset ? 'bg-blue-50 text-blue-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'; ?> text-sm font-medium transition-colors">
          <i data-lucide="plus-square" style="width:18px;height:18px"></i>
          Add Item(s)
        </a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
          <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
            <i data-lucide="users" style="width:18px;height:18px"></i>
            Manage Users
          </a>
        <?php endif; ?>
      </nav>
    </aside>

    <div id="overlay" class="fixed inset-0 bg-gray-900/30 z-30 hidden"></div>

    <div class="flex-1 flex flex-col min-w-0">

      <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
          <button id="menuBtn" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
            <i data-lucide="menu" style="width:20px;height:20px"></i>
          </button>
          <div class="relative w-full max-w-md">
            <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="width:16px;height:16px"></i>
            <input type="text" placeholder="Search assets, locations, categories..."
              class="w-full pl-10 pr-4 py-2.5 rounded-full bg-gray-50 border border-gray-200 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300 transition" />
          </div>
        </div>

    <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6">

      <?php if ($showRegister): ?>
      <div id="registerView">
        <?php include 'register.php'; ?>
      </div>
      <?php elseif (!$showAddAsset): ?>
      <div id="dashboardView">
        <div class="flex items-start sm:items-center justify-between flex-wrap gap-3">
          <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Welcome back, here's your department overview</p>
          </div>
          <div class="flex items-center gap-2">
            <button class="text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-lg px-3.5 py-2 hover:bg-gray-50 transition-colors inline-flex items-center gap-1.5">
              <i data-lucide="calendar" style="width:15px;height:15px"></i>
              <span class="hidden sm:inline">This month</span>
            </button>
            <button class="text-sm font-medium text-white bg-blue-600 rounded-lg px-3.5 py-2 hover:bg-blue-700 transition-colors inline-flex items-center gap-1.5">
              <i data-lucide="download" style="width:15px;height:15px"></i>
              Export
            </button>
            <!-- Dropdown Menu -->
            <div id="userMenuDropdown" class="absolute top-full right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 hidden z-10">
              <div class="p-3 border-b border-gray-100">
                <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
              </div>
              <div class="p-1.5">
                <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                  <i data-lucide="log-out" style="width:16px;height:16px"></i>
                  Logout
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6">

        <?php if (!$showAddAsset): ?>
          <div id="dashboardView">
            <div class="flex items-start sm:items-center justify-between flex-wrap gap-3">
              <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Dashboard</h1>
                <p class="text-sm text-gray-500 mt-1">Welcome back, here's your department overview</p>
              </div>
              <div class="flex items-center gap-2">
                <button class="text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-lg px-3.5 py-2 hover:bg-gray-50 transition-colors inline-flex items-center gap-1.5">
                  <i data-lucide="calendar" style="width:15px;height:15px"></i>
                  <span class="hidden sm:inline">This month</span>
                </button>
                <button class="text-sm font-medium text-white bg-blue-600 rounded-lg px-3.5 py-2 hover:bg-blue-700 transition-colors inline-flex items-center gap-1.5">
                  <i data-lucide="download" style="width:15px;height:15px"></i>
                  Export
                </button>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mt-6">

              <a href="view-assets.php?category_id=1" class="block bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md hover:border-blue-200 transition-all duration-200">
                <div class="flex items-start justify-between">
                  <p class="text-sm font-medium text-gray-500">Expandable</p>
                  <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                    <i data-lucide="package" class="text-blue-600" style="width:18px;height:18px"></i>
                  </div>
                </div>
                <p class="text-3xl font-bold text-gray-900 mt-3 tracking-tight"><?php echo number_format($category_counts[1]); ?></p>
                <p class="text-xs font-medium text-emerald-600 mt-2 inline-flex items-center gap-1">
                  <i data-lucide="trending-up" style="width:13px;height:13px"></i> +12% from last month
                </p>
              </a>

              <a href="view-assets.php?category_id=2" class="block bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md hover:border-purple-200 transition-all duration-200">
                <div class="flex items-start justify-between">
                  <p class="text-sm font-medium text-gray-500">Consumables</p>
                  <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center shrink-0">
                    <i data-lucide="flask-conical" class="text-purple-600" style="width:18px;height:18px"></i>
                  </div>
                </div>
                <p class="text-3xl font-bold text-gray-900 mt-3 tracking-tight"><?php echo number_format($category_counts[2]); ?></p>
                <p class="text-xs font-medium text-emerald-600 mt-2 inline-flex items-center gap-1">
                  <i data-lucide="trending-up" style="width:13px;height:13px"></i> +3 new this month
                </p>
              </a>

              <a href="view-assets.php?category_id=3" class="block bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md hover:border-amber-200 transition-all duration-200">
                <div class="flex items-start justify-between">
                  <p class="text-sm font-medium text-gray-500">Deadstock</p>
                  <div class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
                    <i data-lucide="alert-triangle" class="text-amber-600" style="width:18px;height:18px"></i>
                  </div>
                </div>
                <p class="text-3xl font-bold text-gray-900 mt-3 tracking-tight"><?php echo number_format($category_counts[3]); ?></p>
                <p class="text-xs font-medium text-rose-600 mt-2 inline-flex items-center gap-1">
                  <i data-lucide="alert-circle" style="width:13px;height:13px"></i> Needs attention
                </p>
              </a>

              <a href="view-assets.php?category_id=4" class="block bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-md hover:border-emerald-200 transition-all duration-200">
                <div class="flex items-start justify-between">
                  <p class="text-sm font-medium text-gray-500">Furniture</p>
                  <div class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0">
                    <i data-lucide="armchair" class="text-emerald-600" style="width:18px;height:18px"></i>
                  </div>
                </div>
                <p class="text-3xl font-bold text-gray-900 mt-3 tracking-tight"><?php echo number_format($category_counts[4]); ?></p>
                <p class="text-xs font-medium text-emerald-600 mt-2 inline-flex items-center gap-1">
                  <i data-lucide="trending-up" style="width:13px;height:13px"></i> +4 new this month
                </p>
              </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">

              <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                <div class="flex items-center justify-between mb-4">
                  <h2 class="font-semibold text-gray-900">Recent Activity</h2>
                  <a href="#" class="text-xs font-medium text-blue-600 hover:text-blue-700">View all</a>
                </div>
                <div class="overflow-x-auto -mx-1">
                  <table class="w-full text-sm min-w-[560px]">
                    <thead>
                      <tr class="text-left text-[11px] text-gray-400 uppercase tracking-wider">
                        <th class="pb-3 px-1 font-medium">ASSET ID</th>
                        <th class="pb-3 px-1 font-medium">EQUIPMENT NAME</th>
                        <th class="pb-3 px-1 font-medium">CATEGORY</th>
                        <th class="pb-3 px-1 font-medium">LOCATION</th>
                        <th class="pb-3 px-1 font-medium text-right">DATE ADDED</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr>
                        <td class="py-3.5 px-1 font-medium text-gray-900">KD-EXP-001</td>
                        <td class="py-3.5 px-1 text-gray-600">Arduino Uno R3</td>
                        <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-600">Expandable</span></td>
                        <td class="py-3.5 px-1 text-gray-500">Lab F004</td>
                        <td class="py-3.5 px-1 text-gray-500 text-right">2026-07-16</td>
                      </tr>
                      <tr>
                        <td class="py-3.5 px-1 font-medium text-gray-900">KD-CON-055</td>
                        <td class="py-3.5 px-1 text-gray-600">Whiteboard Markers</td>
                        <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-600">Consumables</span></td>
                        <td class="py-3.5 px-1 text-gray-500">Staff Room</td>
                        <td class="py-3.5 px-1 text-gray-500 text-right">2026-07-15</td>
                      </tr>
                      <tr>
                        <td class="py-3.5 px-1 font-medium text-gray-900">KD-DEAD-012</td>
                        <td class="py-3.5 px-1 text-gray-600">Broken Dell Monitor</td>
                        <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-600">Deadstock</span></td>
                        <td class="py-3.5 px-1 text-gray-500">Storage Room</td>
                        <td class="py-3.5 px-1 text-gray-500 text-right">2026-07-10</td>
                      </tr>
                      <tr>
                        <td class="py-3.5 px-1 font-medium text-gray-900">KD-FURN-008</td>
                        <td class="py-3.5 px-1 text-gray-600">Ergonomic Lab Chair</td>
                        <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-50 text-purple-600">Furniture</span></td>
                        <td class="py-3.5 px-1 text-gray-500">Lab F002</td>
                        <td class="py-3.5 px-1 text-gray-500 text-right">2026-07-01</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 flex flex-col">
                <h2 class="font-semibold text-gray-900">Category Overview</h2>
                <p class="text-xs text-gray-400 mt-1 mb-6">Assets by category, this month</p>

                <div class="flex-1 flex items-end justify-between gap-3 min-h-[180px]">

                  <!-- Expandable -->
                  <div class="flex flex-col items-center gap-2 flex-1">
                    <span class="text-[11px] font-semibold text-gray-600">
                      <?php echo number_format($category_counts[1]); ?>
                    </span>

                    <div
                      class="w-full max-w-[34px] bg-blue-500 rounded-t-md transition-all duration-500"
                      style="height:<?php echo $chartHeights[1]; ?>px">
                    </div>

                    <span class="text-[11px] text-gray-400">
                      Expandable
                    </span>
                  </div>

                  <!-- Consumables -->
                  <div class="flex flex-col items-center gap-2 flex-1">
                    <span class="text-[11px] font-semibold text-gray-600">
                      <?php echo number_format($category_counts[2]); ?>
                    </span>

                    <div
                      class="w-full max-w-[34px] bg-purple-500 rounded-t-md transition-all duration-500"
                      style="height:<?php echo $chartHeights[2]; ?>px">
                    </div>

                    <span class="text-[11px] text-gray-400">
                      Consumables
                    </span>
                  </div>

                  <!-- Deadstock -->
                  <div class="flex flex-col items-center gap-2 flex-1">
                    <span class="text-[11px] font-semibold text-gray-600">
                      <?php echo number_format($category_counts[3]); ?>
                    </span>

                    <div
                      class="w-full max-w-[34px] bg-amber-500 rounded-t-md transition-all duration-500"
                      style="height:<?php echo $chartHeights[3]; ?>px">
                    </div>

                    <span class="text-[11px] text-gray-400">
                      Deadstock
                    </span>
                  </div>
                  
                  <!-- Furniture -->
                  <div class="flex flex-col items-center gap-2 flex-1">
                    <span class="text-[11px] font-semibold text-gray-600">
                      <?php echo number_format($category_counts[4]); ?>
                    </span>

                    <div
                      class="w-full max-w-[34px] bg-emerald-500 rounded-t-md transition-all duration-500"
                      style="height:<?php echo $chartHeights[4]; ?>px">
                    </div>

                    <span class="text-[11px] text-gray-400">
                      Furniture
                    </span>
                  </div>

                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div id="assetView" class="w-full">
            <?php define('IS_EMBEDDED', true);
            include 'add-asset.php'; ?>
          </div>
        <?php endif; ?>

      </main>
    </div>
  </div>

  <script>
    lucide.createIcons();

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    const menuBtn = document.getElementById('menuBtn');
    const userMenuBtn = document.getElementById('userMenuBtn');
    const userMenuDropdown = document.getElementById('userMenuDropdown');

    function openSidebar() {
      sidebar.classList.remove('-translate-x-full');
      overlay.classList.remove('hidden');
    }

    function closeSidebar() {
      sidebar.classList.add('-translate-x-full');
      overlay.classList.add('hidden');
    }

    menuBtn.addEventListener('click', openSidebar);
    overlay.addEventListener('click', closeSidebar);

    userMenuBtn.addEventListener('click', () => {
      userMenuDropdown.classList.toggle('hidden');
    });

    // Close dropdown if clicked outside
    document.addEventListener('click', (event) => {
      if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
        userMenuDropdown.classList.add('hidden');
      }
    });
  </script>

</body>

</html>