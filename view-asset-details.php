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
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$asset_name_raw = isset($_GET['asset_name']) ? trim($_GET['asset_name']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Define categories to get the name from the ID
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

// Check if the parameters are valid
if ($category_id === 0 || !array_key_exists($category_id, $categories) || empty($asset_name_raw)) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Invalid asset specified."));
    exit();
}

$category_name = $categories[$category_id];

$sql_where_clauses = ["category_id = ?", "asset_name = ?"];
$sql_params = ["is", $category_id, $asset_name_raw];

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

// Helper function to generate initials
if (!function_exists('getInitials')) {
    function getInitials($name) {
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
  tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
</script>
<style>
  html, body { 
    font-family: 'Inter', sans-serif; 
  }
  .clickable-row:hover {
    background-color: #f9fafb;
    cursor: pointer;
  }
</style>
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">

<div class="h-screen flex overflow-hidden">

  <!-- Sidebar -->
  <aside id="sidebar" class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 lg:translate-x-0 lg:static transition-transform duration-200 ease-out">
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
      <?php if ($_SESSION['role'] === 'admin'): ?>
      <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
        <i data-lucide="users" style="width:18px;height:18px"></i> Manage Users
      </a>
      <?php endif; ?>
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
      <div class="max-w-7xl mx-auto">
        
        <!-- Breadcrumb Navigation -->
        <div class="mb-6">
            <nav class="text-sm font-medium text-gray-500 mb-3">
                <a href="dashboard.php" class="hover:text-blue-600 transition-colors">Dashboard</a>
                <span class="mx-2 text-gray-400">&gt;</span>
                <a href="view-assets.php?category_id=<?php echo $category_id; ?>" class="hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($category_name); ?></a>
                <span class="mx-2 text-gray-400">&gt;</span>
                <span class="text-gray-900 capitalize"><?php echo htmlspecialchars($asset_name_raw); ?></span>
            </nav>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight capitalize"><?php echo htmlspecialchars($asset_name_raw); ?> Details</h1>
                <form action="view-asset-details.php" method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                    <div class="relative flex-grow">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by location or date..." class="w-full pl-4 pr-10 py-2.5 text-sm rounded-full bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                            <i data-lucide="search" style="width:16px;height:16px"></i>
                        </button>
                    </div>
                    <?php if (!empty($search_query)): ?>
                        <a href="view-asset-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>" class="px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 rounded-full hover:bg-gray-200">Clear</a>
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
                    <?php $details = $batch['details']; $items = $batch['items']; ?>
                    <tr 
                        class="clickable-row transition-colors duration-150" 
                        data-href="view-batch-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>&batch_id=<?php echo urlencode($batch_id); ?>"
                    >
                      <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($details['date_of_issue'])); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-600 font-bold"><?php echo count($items); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-600 truncate"><?php echo htmlspecialchars($details['location'] ?: 'N/A'); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-600">₹<?php echo htmlspecialchars(number_format($details['cost'], 2)); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="view-batch-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>&batch_id=<?php echo urlencode($batch_id); ?>" class="text-indigo-600 hover:text-indigo-900" onclick="event.stopPropagation()">View Record</a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
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

</body>
</html>