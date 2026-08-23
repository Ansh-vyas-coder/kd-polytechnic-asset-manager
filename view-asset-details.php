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

// Get parameters from URL
$category_id    = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$asset_name_raw = isset($_GET['asset_name']) ? trim($_GET['asset_name']) : '';
$item_no_filter = isset($_GET['item_no']) ? (int)$_GET['item_no'] : 0;
$group_filter   = isset($_GET['group']) ? strtolower(trim($_GET['group'])) : ''; // e.g. "mouse"
$search_query   = isset($_GET['search']) ? trim($_GET['search']) : '';

// Derive group display name
function vadGroupDisplay(string $k): string { return ucfirst(strtolower($k)); }
$group_display = $group_filter !== '' ? vadGroupDisplay($group_filter) : '';

// Define categories to get the name from the ID
$categories = [
  1 => 'Expandable',
  2 => 'Consumables',
  3 => 'Deadstock',
  4 => 'Furniture'
];

// Check if the parameters are valid
if ($category_id === 0 || !array_key_exists($category_id, $categories) || empty($asset_name_raw)) {
  header("Location: 404.php");
  exit();
}

$category_name = $categories[$category_id];

$sql_where_clauses = ["category_id = ?", "asset_name = ?", "retire_at IS NULL", "(transferred = 0 OR transferred IS NULL)"];
$sql_params = ["is", $category_id, $asset_name_raw];

// If a specific item_no is requested, filter to only that item
if ($item_no_filter > 0) {
  $sql_where_clauses[] = "item_no = ?";
  $sql_params[0] .= "i";
  $sql_params[] = $item_no_filter;
}

// Staff can only view rows assigned to themselves
if ($_SESSION['role'] === 'staff') {
  $sql_where_clauses[] = "assigned_to = ?";
  $sql_params[0] .= "s";
  $sql_params[] = $_SESSION['user_name'];
}

if (!empty($search_query)) {
  $sql_where_clauses[] = "(location LIKE ? OR date_of_issue LIKE ?)";
  $sql_params[0] .= "ss";
  $search_term = "%" . $search_query . "%";
  $sql_params[] = $search_term;
  $sql_params[] = $search_term;
}

// Fetch asset details for the specified category and name from the database
$asset_batches = [];
$sql = "SELECT * FROM assets WHERE " . implode(" AND ", $sql_where_clauses) . " ORDER BY date_of_issue DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param(...$sql_params);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
  while ($asset = $result->fetch_assoc()) {
    $batch_id = $asset['batch_id'];
    if (empty($batch_id)) {
      // Fallback for older records without a batch_id
      $batch_id = 'batch_uncategorized_' . $asset['id'];
    }

    if (!isset($asset_batches[$batch_id])) {
      // This is the first item of a new batch, initialize it
      $asset_batches[$batch_id] = [
        'details' => $asset, // Store the first item as representative details
        'items' => []
      ];
    }
    $asset_batches[$batch_id]['items'][] = $asset;
  }
}
$stmt->close();

if (empty($asset_batches)) {
  header("Location: 404.php");
  exit();
}

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
  <title>Details for <?php echo htmlspecialchars($asset_name_raw); ?> - KDP Asset Manager</title>
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
  <link rel="stylesheet" href="notifications.css" />
</head>

  <body class="h-screen bg-gray-50 text-gray-900 antialiased">
    <?php include 'loader/loader.html'; ?>
    <div class="h-screen flex overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <!-- Overlay for mobile sidebar -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

    <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
      <!-- Header -->
      <?php include 'topbar.php'; ?>

      <!-- Main Content -->
      <div class="flex-1 overflow-y-auto flex flex-col">
        <main class="flex-1 bg-gray-50 p-4 lg:p-6">

          <!-- Breadcrumb Navigation -->
          <div class="mb-6">
            <nav class="text-sm font-medium text-gray-500 mb-3">
              <a href="dashboard.php" class="hover:text-blue-600 transition-colors">Dashboard</a>
              <span class="mx-2 text-gray-400">&gt;</span>
              <a href="view-assets.php?category_id=<?php echo $category_id; ?>" class="hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($category_name); ?></a>
              <?php if ($group_filter !== ''): ?>
                <span class="mx-2 text-gray-400">&gt;</span>
                <a href="view-assets.php?category_id=<?php echo $category_id; ?>&group=<?php echo urlencode($group_filter); ?>" class="hover:text-blue-600 transition-colors capitalize"><?php echo htmlspecialchars($group_display); ?></a>
              <?php endif; ?>
              <span class="mx-2 text-gray-400">&gt;</span>
              <span class="text-gray-900 capitalize"><?php echo htmlspecialchars($asset_name_raw); ?><?php echo $item_no_filter > 0 ? ' (I-' . $item_no_filter . ')' : ''; ?></span>
            </nav>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <h1 class="text-2xl font-bold text-gray-900 tracking-tight capitalize">
                <?php if ($item_no_filter > 0): ?>
                  <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-blue-100 text-blue-700 text-sm font-bold mr-2"><?php echo $item_no_filter; ?></span>
                <?php endif; ?>
                <?php echo htmlspecialchars($asset_name_raw); ?> Details
              </h1>
              <form action="view-asset-details.php" method="GET" class="flex items-center gap-2">
                <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                <?php if ($item_no_filter > 0): ?>
                  <input type="hidden" name="item_no" value="<?php echo $item_no_filter; ?>">
                <?php endif; ?>
                <?php if ($group_filter !== ''): ?>
                  <input type="hidden" name="group" value="<?php echo htmlspecialchars($group_filter); ?>">
                <?php endif; ?>
                <div class="relative flex-grow">
                  <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by location or date..." class="w-full pl-4 pr-10 py-2.5 text-sm rounded-full bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                  <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <i data-lucide="search" style="width:16px;height:16px"></i>
                  </button>
                </div>
                <?php if (!empty($search_query)): ?>
                  <?php
                    $clear_url = "view-asset-details.php?category_id={$category_id}&asset_name=" . urlencode($asset_name_raw);
                    if ($item_no_filter > 0) $clear_url .= "&item_no={$item_no_filter}";
                    if ($group_filter !== '') $clear_url .= "&group=" . urlencode($group_filter);
                  ?>
                  <a href="<?php echo $clear_url; ?>" class="px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 rounded-full hover:bg-gray-200">Clear</a>
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
                    <th class="px-6 py-3 font-medium">Date Added</th>
                    <th class="px-6 py-3 font-medium">Quantity</th>
                    <th class="px-6 py-3 font-medium">Location</th>
                    <th class="px-6 py-3 font-medium">Cost per Item</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($asset_batches)): ?>
                    <tr>
                      <td colspan="5" class="text-center py-16 text-gray-500">
                        <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                          <i data-lucide="search-slash" class="w-7 h-7 text-gray-400"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800">No records found</h3>
                        <p class="text-sm mt-1"><?php echo !empty($search_query) ? 'Your search for "' . htmlspecialchars($search_query) . '" did not return any results.' : 'There are no entry records for this asset yet.'; ?></p>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($asset_batches as $batch_id => $batch): ?>
                      <?php
                        $details = $batch['details'];
                        $items   = $batch['items'];
                        $batch_link = "view-batch-details.php?category_id={$category_id}&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id);
                        if ($item_no_filter > 0) $batch_link .= "&item_no={$item_no_filter}";
                        if ($group_filter !== '') $batch_link .= "&group=" . urlencode($group_filter);
                      ?>
                      <tr class="clickable-row transition-colors duration-150" data-href="<?php echo $batch_link; ?>">
                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($details['date_of_issue'])); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600 font-bold"><?php echo count($items); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600 truncate"><?php echo htmlspecialchars($details['location'] ?: 'N/A'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600">₹<?php echo htmlspecialchars(number_format($details['cost'], 2)); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                          <div class="flex items-center justify-end gap-3" onclick="event.stopPropagation()">
                            <?php if (!empty($batch_id)): ?>
                            <a href="download-qr.php?batch_id=<?php echo urlencode($batch_id); ?>"
                               title="Download QR Labels for this batch"
                               class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700 transition">
                              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                              QR
                            </a>
                            <?php endif; ?>
                            <a href="<?php echo $batch_link; ?>" class="text-indigo-600 hover:text-indigo-900">View Record</a>
                          </div>
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
      const rows = document.querySelectorAll('.clickable-row');
      rows.forEach(row => {
        row.addEventListener('click', () => {
          window.location.href = row.dataset.href;
        });
      });
    });
  </script>
  <script>
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
        userMenuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('hidden');
        });
        document.addEventListener('click', function(e) {
            if (!userMenuDropdown.contains(e.target) && e.target !== userMenuBtn) {
                userMenuDropdown.classList.add('hidden');
            }
        });
    }
  </script>

  <script src="loader/loader.js"></script>

  <?php include 'page_scripts.php'; ?>
</body>

</html>


