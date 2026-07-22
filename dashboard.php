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

    <div class="h-16 flex items-center gap-3 px-4 border-b border-gray-200 shrink-0">
      <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center shrink-0 p-1">
        <img src="https://scontent.famd8-1.fna.fbcdn.net/v/t39.30808-6/482345949_1144079087415361_6640568596786112832_n.jpg?stp=dst-jpg_tt6&cstp=mx1379x1379&ctp=s1379x1379&_nc_cat=111&ccb=1-7&_nc_sid=6ee11a&_nc_ohc=dqzfFwO_7hEQ7kNvwHlS2Lc&_nc_oc=AdqNTZYLxmQKL2WaL9V7X7C6O9y9HIZNlpZiBqOTr3chZ-WT57nGAbpKFdbH0IayXk4&_nc_zt=23&_nc_ht=scontent.famd8-1.fna&_nc_gid=512jtex-NyXTQ9YEE2yRCg&_nc_ss=7b289&oh=00_AQCFoi8YrRQThI_Qg2e3SPWGXJTNIXX5tSQO7LOxr-Rw5w&oe=6A5E8523" 
             alt="KDP Logo" class="w-full h-full object-contain">
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
        <div id="search-container" class="relative w-full max-w-md flex items-center">
          <div class="absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none">
            <i id="search-icon" data-lucide="search" class="text-gray-400" style="width:16px;height:16px"></i>
            <div id="search-spinner" class="hidden animate-spin rounded-full h-4 w-4 border-b-2 border-gray-900"></div>
          </div>
          <input type="text" id="searchInput" placeholder="Search assets, locations, categories..."
            class="w-full pl-10 pr-4 py-2.5 rounded-l-full bg-gray-50 border border-r-0 border-gray-200 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300 transition" autocomplete="off" />
          <button id="searchButton" class="px-4 py-2.5 bg-blue-600 text-white rounded-r-full hover:bg-blue-700 transition-colors text-sm font-semibold">
            Search
          </button>
          <div id="searchResults" class="absolute top-full mt-2 w-full bg-white rounded-lg shadow-xl border border-gray-100 hidden z-20 overflow-hidden">
            <!-- Search results will be populated here -->
          </div>
        </div>
      </div>


      <div class="flex items-center gap-3 sm:gap-4 shrink-0">
        <button class="relative p-2 rounded-lg hover:bg-gray-100 text-gray-500 transition-colors">
          <i data-lucide="bell" style="width:19px;height:19px"></i>
          <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-rose-500 rounded-full ring-2 ring-white"></span>
        </button>
        <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>
        <div class="relative">
          <button id="userMenuBtn" class="flex items-center gap-2.5 group">
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
            <div class="hidden sm:block text-left leading-tight">
              <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
              <p class="text-xs text-gray-400"><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?> - Computer Dept.</p>
            </div>
            <i data-lucide="chevron-down" class="hidden sm:block text-gray-400 group-hover:text-gray-600 transition-colors" style="width:16px;height:16px"></i>
          </button>
          <!-- Dropdown Menu -->
          <div id="userMenuDropdown" class="absolute top-full right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 hidden z-10">
            <div class="p-3 border-b border-gray-100">
              <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
              <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
            </div>
            <div class="p-1.5">
              <button id="changePasswordBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                <i data-lucide="key-round" style="width:16px;height:16px"></i>
                Change Password
              </button>
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

<!-- Change Password Modal -->
<div id="passwordModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all" id="passwordModalContent">
    <form id="changePasswordForm" method="POST" action="change-password.php">
      <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900">Change Your Password</h3>
            <button type="button" id="closeModalBtn" class="p-1 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        
        <div id="modal-notification" class="hidden text-sm mb-4"></div>

        <div class="space-y-4">
          <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
            <input type="password" name="current_password" id="current_password" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
          
          <div>
            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
            <input type="password" name="new_password" id="new_password" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-gray-500 mt-1">Must be at least 8 characters long.</p>
          </div>

          <div>
            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirm_password" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
        </div>
      </div>
      <div class="bg-gray-50 px-6 py-4 rounded-b-xl flex items-center justify-end gap-3">
        <button type="button" id="cancelModalBtn" class="inline-flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
            Cancel
        </button>
        <button type="submit" id="submitPasswordBtn" class="inline-flex justify-center items-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
            <span id="submitBtnText">Update Password</span>
            <div id="submitSpinner" class="spinner hidden" style="border-top-color: #fff; border-right-color: transparent; width: 16px; height: 16px; margin-left: 8px;"></div>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- General Notification Toast -->
<div id="notification" class="fixed top-5 right-5 bg-red-500 text-white py-2.5 px-5 rounded-lg shadow-xl text-sm font-medium hidden z-[60]">
  <!-- Message will be inserted here -->
</div>

  <script>
    lucide.createIcons();

  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const menuBtn = document.getElementById('menuBtn');
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenuDropdown = document.getElementById('userMenuDropdown');
  const changePasswordBtn = document.getElementById('changePasswordBtn');
  const passwordModal = document.getElementById('passwordModal');
  const closeModalBtn = document.getElementById('closeModalBtn');
  const cancelModalBtn = document.getElementById('cancelModalBtn');
  const changePasswordForm = document.getElementById('changePasswordForm');
  const notification = document.getElementById('notification');

    function openSidebar() {
      sidebar.classList.remove('-translate-x-full');
      overlay.classList.remove('hidden');
    }

  function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
  }

  // --- User Menu Dropdown Logic ---
  userMenuBtn.addEventListener('click', () => {
    userMenuDropdown.classList.toggle('hidden');
  });

  menuBtn.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);

  // --- Change Password Modal Logic ---
  function showPasswordModal() {
    passwordModal.classList.remove('hidden');
    changePasswordForm.reset();
    document.getElementById('modal-notification').classList.add('hidden');
  }

  function hidePasswordModal() {
    passwordModal.classList.add('hidden');
  }

  changePasswordBtn.addEventListener('click', (e) => {
    e.preventDefault();
    userMenuDropdown.classList.add('hidden'); // Close dropdown first
    showPasswordModal();
  });

  closeModalBtn.addEventListener('click', hidePasswordModal);
  cancelModalBtn.addEventListener('click', hidePasswordModal);
  passwordModal.addEventListener('click', (e) => {
    if (e.target === passwordModal) {
      hidePasswordModal();
    }
  });

  changePasswordForm.addEventListener('submit', function(event) {
    event.preventDefault();

    const submitBtn = document.getElementById('submitPasswordBtn');
    const btnText = document.getElementById('submitBtnText');
    const spinner = document.getElementById('submitSpinner');
    const modalNotification = document.getElementById('modal-notification');

    submitBtn.disabled = true;
    btnText.textContent = 'Updating...';
    spinner.classList.remove('hidden');
    modalNotification.classList.add('hidden');

    const formData = new FormData(changePasswordForm);

    fetch('change-password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            modalNotification.className = 'bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm';
            modalNotification.textContent = data.message;
            modalNotification.classList.remove('hidden');
            setTimeout(hidePasswordModal, 2000);
        } else {
            modalNotification.className = 'bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm';
            modalNotification.textContent = data.message || 'An error occurred.';
            modalNotification.classList.remove('hidden');
        }
    })
    .finally(() => {
        submitBtn.disabled = false;
        btnText.textContent = 'Update Password';
        spinner.classList.add('hidden');
    });
  });

  // --- Live Search Logic ---
  const searchInput = document.getElementById('searchInput');
  const searchResults = document.getElementById('searchResults');
  const searchContainer = document.getElementById('search-container');
  const searchIcon = document.getElementById('search-icon');
  const searchSpinner = document.getElementById('search-spinner');
  const searchButton = document.getElementById('searchButton');
  let searchTimeout;

  function performSearch() {
    const query = searchInput.value.trim();
    
    if (query.length < 2) {
      searchResults.classList.add('hidden');
      return;
    }

    searchIcon.classList.add('hidden');
    searchSpinner.classList.remove('hidden');

    fetch(`search.php?query=${encodeURIComponent(query)}`)
      .then(response => response.json())
      .then(data => {
        if (data.length > 0) {
          let resultsHtml = '<div class="p-2"><ul class="space-y-1">';
          data.forEach(item => {
            resultsHtml += `
              <li>
                <a href="view-asset-details.php?category_id=${item.category_id}&asset_name=${encodeURIComponent(item.asset_name)}"
                   class="flex items-center justify-between p-3 rounded-md hover:bg-gray-50 transition-colors">
                  <div>
                    <p class="font-semibold text-sm text-gray-800 capitalize">${item.asset_name}</p>
                    <div class="text-xs text-gray-500 mt-1 flex items-center gap-x-3">
                      <span>in <strong class="font-medium text-gray-600">${item.category_name}</strong></span>
                      ${item.location ? `<span>| At: <strong class="font-medium text-gray-600">${item.location}</strong></span>` : ''}
                      ${item.assigned_to ? `<span>| With: <strong class="font-medium text-gray-600">${item.assigned_to}</strong></span>` : ''}
                    </div>
                  </div>
                  <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                </a>
              </li>
            `;
          });
          resultsHtml += '</ul></div>';
          searchResults.innerHTML = resultsHtml;
          searchResults.classList.remove('hidden');
          lucide.createIcons();
        } else {
          searchResults.innerHTML = '<p class="p-4 text-sm text-center text-gray-500">No results found.</p>';
          searchResults.classList.remove('hidden');
        }
      })
      .catch(error => {
        console.error('Search error:', error);
        searchResults.classList.add('hidden');
      })
      .finally(() => {
        searchIcon.classList.remove('hidden');
        searchSpinner.classList.add('hidden');
      });
  };

  searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(performSearch, 300); // Debounce search
  });

  searchButton.addEventListener('click', performSearch);

  searchInput.addEventListener('keydown', function(event) {
    if (event.key === 'Enter') performSearch();
  });

  // Close popups when clicking outside
  document.addEventListener('click', function(event) {
    if (!searchContainer.contains(event.target)) {
      searchResults.classList.add('hidden');
    }
    if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
      userMenuDropdown.classList.add('hidden');
    }
  });
</script>

</body>

</html>