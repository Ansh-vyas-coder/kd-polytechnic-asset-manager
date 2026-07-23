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
$batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Define categories to get the name from the ID
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

// Check if the parameters are valid
if ($category_id === 0 || !array_key_exists($category_id, $categories) || empty($asset_name_raw) || empty($batch_id)) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Invalid record specified."));
    exit();
}

$category_name = $categories[$category_id];

// Fetch asset details for the specified batch
$items = [];
$sql = "SELECT * FROM assets WHERE batch_id = ?";
$params = ["s", $batch_id];

if (!empty($search_query)) {
    $sql .= " AND (item_no LIKE ? OR asset_no LIKE ? OR assigned_to LIKE ? OR remarks LIKE ?)";
    $params[0] .= "ssss";
    $search_term = "%" . $search_query . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY item_no ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param(...$params);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $items = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

if (empty($items)) {
    header("Location: view-asset-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&status=error&message=No items found for this record.");
    exit();
}

$batch_details = $items[0]; // Use the first item for common details like date
$total_quantity_in_batch = count($items);
$total_cost_of_batch = $total_quantity_in_batch * (float)$batch_details['cost'];

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
<title>Record Details - KDP Asset Manager</title>
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
  <aside id="sidebar" class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 lg:translate-x-0 lg:static">
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
                <a href="dashboard.php" class="hover:text-blue-600">Dashboard</a>
                <span class="mx-2 text-gray-400">&gt;</span>
                <a href="view-assets.php?category_id=<?php echo $category_id; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($category_name); ?></a>
                <span class="mx-2 text-gray-400">&gt;</span>
                <a href="view-asset-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>" class="hover:text-blue-600 capitalize"><?php echo htmlspecialchars($asset_name_raw); ?></a>
                <span class="mx-2 text-gray-400">&gt;</span>
                <span class="text-gray-900">Record of <?php echo date('M d, Y', strtotime($batch_details['date_of_issue'])); ?></span>
            </nav>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Record Details</h1>
                <form action="view-batch-details.php" method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                    <div class="relative flex-grow">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search in this record..." class="w-full pl-4 pr-10 py-2.5 text-sm rounded-full bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                            <i data-lucide="search" style="width:16px;height:16px"></i>
                        </button>
                    </div>
                    <?php if (!empty($search_query)): ?>
                        <a href="view-batch-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>&batch_id=<?php echo urlencode($batch_id); ?>" class="px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 rounded-full hover:bg-gray-200">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Record Summary -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Record Summary</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-6 gap-y-5 text-sm">
                <div>
                    <p class="text-gray-500">Location</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['location'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">Cost per Item</p>
                    <p class="font-semibold text-gray-800">₹<?php echo htmlspecialchars(number_format($batch_details['cost'], 2)); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">Total Items</p>
                    <p class="font-semibold text-gray-800"><?php echo $total_quantity_in_batch; ?></p>
                </div>
                <div>
                    <p class="text-gray-500">Total Cost</p>
                    <p class="font-semibold text-gray-800">₹<?php echo htmlspecialchars(number_format($total_cost_of_batch, 2)); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">Page No.</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['page_no'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">GeM Order No.</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['gem_order_no'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">GeM Invoice No.</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['gem_invoice_no'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">GPR No.</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['gpr_no'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">GPR Page No.</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['pr_page_no'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">GPR Item No.</p>
                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($batch_details['gpr_item_no'] ?: 'N/A'); ?></p>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-gray-50">
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                  <th class="px-6 py-3 font-medium">Item No</th>
                  <th class="px-6 py-3 font-medium">Asset No</th>
                  <th class="px-6 py-3 font-medium">Assigned To</th>
                  <th class="px-6 py-3 font-medium">Remarks</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-16 text-gray-500">
                            <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                <i data-lucide="search-slash" class="w-7 h-7 text-gray-400"></i>
                            </div>
                            <h3 class="font-semibold text-gray-800">No items found</h3>
                            <p class="text-sm mt-1"><?php echo !empty($search_query) ? 'Your search for "' . htmlspecialchars($search_query) . '" did not return any results.' : 'There are no items in this record.'; ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <tr class="text-gray-600">
                        <td class="px-6 py-4 font-mono text-xs"><?php echo htmlspecialchars($item['item_no']); ?></td>
                        <td class="px-6 py-4 font-mono text-xs"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                        <td class="px-6 py-4"><?php echo htmlspecialchars($item['assigned_to'] ?: 'N/A'); ?></td>
                        <td class="px-6 py-4 text-xs"><?php echo htmlspecialchars($item['remarks'] ?: 'None'); ?></td>
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
</script>

</body>
</html>