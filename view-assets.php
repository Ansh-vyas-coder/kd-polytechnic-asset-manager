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
$category_id  = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$group_filter = isset($_GET['group']) ? strtolower(trim($_GET['group'])) : ''; // e.g. "mouse"
$group_display = ''; // default to avoid undefined variable warnings
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Define categories
$categories = [
  1 => 'Expandable',
  2 => 'Consumables',
  3 => 'Deadstock',
  4 => 'Furniture'
];

if ($category_id === 0 || !array_key_exists($category_id, $categories)) {
  header("Location: dashboard.php?status=error&message=" . urlencode("Invalid category specified."));
  exit();
}

$category_name = $categories[$category_id];

// Helper: display name of a group key
function getGroupDisplay(string $key): string {
  return ucfirst(strtolower($key));
}

// ============================================================
// MODE A: No group selected → show GENERIC GROUPS
//   Groups by last word of asset_name (SUBSTRING_INDEX trick)
//   "HP Mouse", "Mouse", "Lenovo Mouse" → all become group "mouse"
//   "Crompton Table Fan" → group "fan"
//   No hardcoded list — works for any future product automatically
// ============================================================
if ($group_filter === '') {
  $base_where = "category_id = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
  $params = [$category_id];
  $types  = "i";

  if ($_SESSION['role'] === 'staff') {
    $base_where .= " AND assigned_to = ?";
    $params[] = $_SESSION['user_name'];
    $types .= "s";
  }

  if (!empty($search_query)) {
    $base_where .= " AND (asset_name LIKE ? OR item_no LIKE ? OR location LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
  }

  $sql = "
    SELECT
      LOWER(TRIM(SUBSTRING_INDEX(TRIM(asset_name), ' ', -1))) AS group_key,
      COUNT(DISTINCT item_no)  AS total_item_nos,
      SUM(quantity)            AS total_quantity,
      MIN(date_of_issue)       AS first_issue_date
    FROM assets
    WHERE {$base_where}
    GROUP BY group_key
    ORDER BY group_key ASC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $mode       = 'groups';
  $page_title = "Assets: {$category_name}";
}

// ============================================================
// MODE B: Group selected → show all item_no entries in that group
//   e.g. group=mouse shows: HP Mouse I-5, Lenovo Mouse I-7, Mouse I-9
// ============================================================
else {
  $base_where = "category_id = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)
                 AND LOWER(TRIM(SUBSTRING_INDEX(TRIM(asset_name), ' ', -1))) = ?";
  $params = [$category_id, $group_filter];
  $types  = "is";

  if ($_SESSION['role'] === 'staff') {
    $base_where .= " AND assigned_to = ?";
    $params[] = $_SESSION['user_name'];
    $types .= "s";
  }

  if (!empty($search_query)) {
    $base_where .= " AND (asset_name LIKE ? OR item_no LIKE ? OR location LIKE ? OR date_of_issue LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssss";
  }

  $sql = "
    SELECT
      item_no,
      asset_name,
      SUM(quantity)      AS total_quantity,
      COUNT(*)           AS total_records,
      MIN(date_of_issue) AS first_issue_date,
      GROUP_CONCAT(DISTINCT location ORDER BY location SEPARATOR ', ') AS locations
    FROM assets
    WHERE {$base_where}
    GROUP BY item_no, asset_name
    ORDER BY item_no ASC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $mode          = 'items';
  $group_display = getGroupDisplay($group_filter);
  $page_title    = "{$group_display} — {$category_name}";
}

// Helper function to generate initials
if (!function_exists('getInitials')) {
  function getInitials($name) {
    $words = explode(' ', $name);
    $init = '';
    foreach ($words as $w) $init .= strtoupper(substr($w, 0, 1));
    return substr($init, 0, 2);
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($page_title); ?> - KDP Asset Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
  </script>
  <style>
    html, body { font-family: 'Inter', sans-serif; }
    .clickable-row:hover { background-color: #f9fafb; cursor: pointer; }
    .group-card { transition: all .18s ease; border: 1px solid #f1f5f9; }
    .group-card:hover { background: #eff6ff; border-color: #bfdbfe; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,.1); }
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

          <?php
          $status_msg = $_GET['status'] ?? '';
          $new_batch_id = trim($_GET['new_batch_id'] ?? '');
          if ($status_msg === 'asset_added'): ?>
          <div class="mb-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 shadow-sm">
            <div class="flex items-center gap-3">
              <span class="text-2xl">✅</span>
              <div>
                <p class="text-sm font-bold text-emerald-800">Assets added successfully!</p>
                <p class="text-xs text-emerald-600 mt-0.5">The new assets have been added to the register.</p>
              </div>
            </div>
            <?php if (!empty($new_batch_id)): ?>
            <a href="download-qr.php?batch_id=<?php echo urlencode($new_batch_id); ?>"
               class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white shadow hover:bg-emerald-700 transition shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
              Download QR Labels (PDF)
            </a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Breadcrumb Navigation -->
          <div class="mb-6">
            <nav class="text-sm font-medium text-gray-500 mb-3">
              <a href="dashboard.php" class="hover:text-blue-600 transition-colors">Dashboard</a>
              <span class="mx-2 text-gray-400">&gt;</span>
              <?php if ($mode === 'items'): ?>
                <a href="view-assets.php?category_id=<?php echo $category_id; ?>" class="hover:text-blue-600 transition-colors"><?php echo htmlspecialchars($category_name); ?></a>
                <span class="mx-2 text-gray-400">&gt;</span>
                <span class="text-gray-900"><?php echo htmlspecialchars($group_display); ?></span>
              <?php else: ?>
                <span class="text-gray-900"><?php echo htmlspecialchars($category_name); ?></span>
              <?php endif; ?>
            </nav>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
                  <?php if ($mode === 'items'): ?>
                    <span class="text-blue-600"><?php echo htmlspecialchars($group_display); ?></span>
                    <span class="text-gray-400 font-normal text-lg"> — <?php echo htmlspecialchars($category_name); ?></span>
                  <?php else: ?>
                    <?php echo htmlspecialchars($category_name); ?>
                  <?php endif; ?>
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                  <?php if ($mode === 'items'): ?>
                    All variants of <strong><?php echo htmlspecialchars($group_display); ?></strong> with their Item Numbers
                  <?php else: ?>
                    Select a group to browse Item Numbers &amp; assets
                  <?php endif; ?>
                </p>
              </div>
              <form action="view-assets.php" method="GET" class="flex items-center gap-2">
                <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                <?php if ($mode === 'items'): ?>
                  <input type="hidden" name="group" value="<?php echo htmlspecialchars($group_filter); ?>">
                <?php endif; ?>
                <div class="relative flex-grow">
                  <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                         placeholder="Search <?php echo $mode === 'items' ? htmlspecialchars($group_display) : htmlspecialchars($category_name); ?>..."
                         class="w-full pl-4 pr-10 py-2.5 text-sm rounded-full bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                  <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <i data-lucide="search" style="width:16px;height:16px"></i>
                  </button>
                </div>
                <?php if (!empty($search_query)): ?>
                  <a href="view-assets.php?category_id=<?php echo $category_id; ?><?php echo $mode === 'items' ? '&group=' . urlencode($group_filter) : ''; ?>"
                     class="px-4 py-2.5 text-sm font-medium text-gray-600 bg-gray-100 rounded-full hover:bg-gray-200">Clear</a>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <?php if ($mode === 'groups'): ?>
          <!-- MODE A: Groups as table list -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-gray-50">
                  <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3 font-medium">Group Name</th>
                    <th class="px-6 py-3 font-medium">Item Nos</th>
                    <th class="px-6 py-3 font-medium">Total Qty</th>
                    <th class="px-6 py-3 font-medium">First Added</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($rows)): ?>
                    <tr>
                      <td colspan="5" class="text-center py-16 text-gray-500">
                        <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                          <i data-lucide="search-slash" class="w-7 h-7 text-gray-400"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800">No assets found</h3>
                        <p class="text-sm mt-1"><?php echo !empty($search_query) ? 'No results for &quot;' . htmlspecialchars($search_query) . '&quot;.' : 'There are no assets in this category yet.'; ?></p>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($rows as $grp):
                      $gk    = $grp['group_key'];
                      $gname = getGroupDisplay($gk);
                      $gLink = "view-assets.php?category_id={$category_id}&group=" . urlencode($gk);
                    ?>
                    <tr class="clickable-row transition-colors duration-150" onclick="window.location.href='<?php echo $gLink; ?>'">
                      <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900 capitalize"><?php echo htmlspecialchars($gname); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo $grp['total_item_nos']; ?> item no<?php echo $grp['total_item_nos'] != 1 ? 's' : ''; ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-700 font-bold"><?php echo $grp['total_quantity']; ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?php echo date('M d, Y', strtotime($grp['first_issue_date'])); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?php echo $gLink; ?>" class="text-indigo-600 hover:text-indigo-900" onclick="event.stopPropagation()">Browse</a>
                      </td>
                    </tr>
                    <?php endforeach; 
                        if (isset($batch_lookup_stmt) && $batch_lookup_stmt instanceof mysqli_stmt) {
                          $batch_lookup_stmt->close();
                        }
                    ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php else: ?>
          <!-- ============================
               MODE B: Item No table
               ============================ -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-gray-50">
                  <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3 font-medium">Item No</th>
                    <th class="px-6 py-3 font-medium">Asset Name</th>
                    <th class="px-6 py-3 font-medium">Total Qty</th>
                    <th class="px-6 py-3 font-medium">First Added</th>
                    <th class="px-6 py-3 font-medium">Location(s)</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($rows)): ?>
                    <tr>
                      <td colspan="6" class="text-center py-16 text-gray-500">
                        <h3 class="font-semibold text-gray-800">No assets found</h3>
                        <p class="text-sm mt-1"><?php echo !empty($search_query) ? 'No results for &quot;' . htmlspecialchars($search_query) . '&quot;.' : 'Nothing in this group yet.'; ?>
                        </p>
                      </td>
                    </tr>
                  <?php else: ?>
                            <?php
                            // Prepare statement to find a representative batch_id for each item_no
                            $batch_lookup_stmt = $conn->prepare("SELECT batch_id, id FROM assets WHERE category_id = ? AND asset_name = ? AND item_no = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) ORDER BY date_of_issue DESC LIMIT 1");
                            foreach ($rows as $asset):
                              $item_no = (int)$asset['item_no'];
                              $batch_id_for_link = '';
                              if ($batch_lookup_stmt) {
                                $batch_lookup_stmt->bind_param('isi', $category_id, $asset['asset_name'], $item_no);
                                $batch_lookup_stmt->execute();
                                $batch_res = $batch_lookup_stmt->get_result();
                                if ($batch_res && ($br = $batch_res->fetch_assoc())) {
                                  if (!empty($br['batch_id'])) {
                                    $batch_id_for_link = $br['batch_id'];
                                  } else {
                                    // use uncategorized id marker expected by view-batch-details
                                    $batch_id_for_link = 'batch_uncategorized_' . (int)$br['id'];
                                  }
                                }
                              }
                              $link = "view-batch-details.php?category_id={$category_id}&asset_name=" . urlencode($asset['asset_name']) . "&batch_id=" . urlencode($batch_id_for_link) . "&group=" . urlencode($group_filter);
                            ?>
                            <tr class="clickable-row transition-colors duration-150" onclick="window.location.href='<?php echo $link; ?>'">
                      <td class="px-6 py-4 whitespace-nowrap">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-blue-50 text-blue-700 text-xs font-bold border border-blue-100"><?php echo $item_no; ?></span>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900 capitalize"><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-700 font-bold">
                        <?php echo $asset['total_quantity']; ?>
                        <span class="text-xs font-normal text-gray-400">(<?php echo $asset['total_records']; ?> record<?php echo $asset['total_records'] != 1 ? 's' : ''; ?>)</span>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-500"><?php echo date('M d, Y', strtotime($asset['first_issue_date'])); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-gray-500 text-xs"><?php echo htmlspecialchars($asset['locations'] ?? 'N/A'); ?></td>
                      <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div class="flex items-center justify-end gap-3" onclick="event.stopPropagation()">
                          <a href="download-qr.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset['asset_name']); ?>&item_no=<?php echo $item_no; ?>"
                             title="Download QR Labels for all assets of this item"
                             class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            QR
                          </a>
                          <a href="<?php echo $link; ?>" class="text-indigo-600 hover:text-indigo-900">View Assets</a>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

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
      const userMenuBtn = document.getElementById('userMenuB



