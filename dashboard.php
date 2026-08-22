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
if ($_SESSION['role'] !== 'admin') {
  if (!in_array($pageView, ['my-assets', 'audit', 'dashboard'])) {
    $pageView = 'dashboard';
  }
}
$showAddAsset = $pageView === 'add-asset';
$showAddBorrowedAsset = $pageView === 'add-borrowed-asset';
$showRegister = $pageView === 'register';
$showGenerateReport = $pageView === 'generate-report';
$showMyAssets = $pageView === 'my-assets';
$showWriteOffAssets = $pageView === 'write-off-assets';
$showTransferAssets = $pageView === 'transfer-assets';
$showLoanedAssets = $pageView === 'loaned-assets';
$showAudit = $pageView === 'audit';

// --- START: Fetch asset counts for dashboard widgets ---
$thisMonth = date('Y-m');
$isStaff = ($_SESSION['role'] === 'staff');
$staffName = $_SESSION['user_name'];

$category_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
if ($isStaff) {
  $stmt = $conn->prepare("SELECT category_id, SUM(quantity) as total_quantity FROM assets WHERE assigned_to = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) GROUP BY category_id");
  $stmt->bind_param("s", $staffName);
  $stmt->execute();
  $result = $stmt->get_result();
} else {
  $result = $conn->query("SELECT category_id, SUM(quantity) as total_quantity FROM assets WHERE retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) GROUP BY category_id");
}
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $category_counts[$row['category_id']] = (int)$row['total_quantity'];
  }
  if ($isStaff && isset($stmt)) $stmt->close();
}

// This-month counts
$month_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
if ($isStaff) {
  $stmt = $conn->prepare("SELECT category_id, SUM(quantity) as total_quantity FROM assets WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND assigned_to = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) GROUP BY category_id");
  $stmt->bind_param("ss", $thisMonth, $staffName);
  $stmt->execute();
  $mResult = $stmt->get_result();
} else {
  $mResult = $conn->query("SELECT category_id, SUM(quantity) as total_quantity FROM assets WHERE DATE_FORMAT(created_at, '%Y-%m') = '$thisMonth' AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) GROUP BY category_id");
}
if ($mResult) {
  while ($row = $mResult->fetch_assoc()) {
    $month_counts[$row['category_id']] = (int)$row['total_quantity'];
  }
  if ($isStaff && isset($stmt)) $stmt->close();
}

// --- START: Fetch items for maintenance widgets (limited and total counts) ---
$items_not_working = [];
$all_items_not_working = [];
$items_under_maintenance = [];
$all_items_under_maintenance = [];
$maintenance_params = [];
$maintenance_types = "";
$total_not_working_count = 0;
$total_under_maintenance_count = 0;

if ($isStaff) {
    $maintenance_types .= "s";
    $maintenance_params[] = $staffName;
}

// Fetch Not Working / Missing items (limited to 4)
$not_working_sql_limited = "SELECT asset_no, asset_name, location, category_id, status FROM assets WHERE status IN ('Not Working', 'Missing') AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
if ($isStaff) {
    $not_working_sql_limited .= " AND assigned_to = ?";
}
$not_working_sql_limited .= " ORDER BY updated_at DESC LIMIT 4";

if (!empty($maintenance_params)) {
    $not_working_stmt = $conn->prepare($not_working_sql_limited);
    $not_working_stmt->bind_param($maintenance_types, ...$maintenance_params);
    $not_working_stmt->execute();
    $not_working_result = $not_working_stmt->get_result();
} else {
    $not_working_result = $conn->query($not_working_sql_limited);
}

if ($not_working_result) {
    while ($row = $not_working_result->fetch_assoc()) {
        $items_not_working[] = $row;
    }
    if (isset($not_working_stmt)) {
        $not_working_stmt->close();
    }
}

// Fetch total count for Not Working / Missing items
$not_working_count_sql = "SELECT COUNT(*) as total_count FROM assets WHERE status IN ('Not Working', 'Missing') AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
if ($isStaff) {
    $not_working_count_sql .= " AND assigned_to = ?";
}
if (!empty($maintenance_params)) {
    $not_working_count_stmt = $conn->prepare($not_working_count_sql);
    $not_working_count_stmt->bind_param($maintenance_types, ...$maintenance_params);
    $not_working_count_stmt->execute();
    $not_working_count_result = $not_working_count_stmt->get_result()->fetch_assoc();
    $total_not_working_count = $not_working_count_result['total_count'];
    $not_working_count_stmt->close();
} else {
    $not_working_count_result = $conn->query($not_working_count_sql)->fetch_assoc();
    $total_not_working_count = $not_working_count_result['total_count'];
}

// Fetch ALL Not Working / Missing items for modal
$not_working_sql_all = "SELECT asset_no, asset_name, location, category_id, status FROM assets WHERE status IN ('Not Working', 'Missing') AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
if ($isStaff) {
    $not_working_sql_all .= " AND assigned_to = ?";
}
$not_working_sql_all .= " ORDER BY updated_at DESC";

if (!empty($maintenance_params)) {
    $not_working_all_stmt = $conn->prepare($not_working_sql_all);
    $not_working_all_stmt->bind_param($maintenance_types, ...$maintenance_params);
    $not_working_all_stmt->execute();
    $not_working_all_result = $not_working_all_stmt->get_result();
} else {
    $not_working_all_result = $conn->query($not_working_sql_all);
}

if ($not_working_all_result) {
    $all_items_not_working = $not_working_all_result->fetch_all(MYSQLI_ASSOC);
    if (isset($not_working_all_stmt)) $not_working_all_stmt->close();
}

// Fetch Under Maintenance items (limited to 4)
$under_maintenance_sql_limited = "SELECT asset_no, asset_name, location, category_id, status FROM assets WHERE status = 'Under Maintenance' AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
if ($isStaff) {
    $under_maintenance_sql_limited .= " AND assigned_to = ?";
}
$under_maintenance_sql_limited .= " ORDER BY updated_at DESC LIMIT 4";

if (!empty($maintenance_params)) {
    $under_maintenance_stmt = $conn->prepare($under_maintenance_sql_limited);
    $under_maintenance_stmt->bind_param($maintenance_types, ...$maintenance_params);
    $under_maintenance_stmt->execute();
    $under_maintenance_result = $under_maintenance_stmt->get_result();
} else {
    $under_maintenance_result = $conn->query($under_maintenance_sql_limited);
}
if ($under_maintenance_result) {
    while ($row = $under_maintenance_result->fetch_assoc()) {
        $items_under_maintenance[] = $row;
    }
    if (isset($under_maintenance_stmt)) {
        $under_maintenance_stmt->close();
    }
}

// Fetch total count for Under Maintenance items
$under_maintenance_count_sql = "SELECT COUNT(*) as total_count FROM assets WHERE status = 'Under Maintenance' AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
if ($isStaff) {
    $under_maintenance_count_sql .= " AND assigned_to = ?";
}
if (!empty($maintenance_params)) {
    $under_maintenance_count_stmt = $conn->prepare($under_maintenance_count_sql);
    $under_maintenance_count_stmt->bind_param($maintenance_types, ...$maintenance_params);
    $under_maintenance_count_stmt->execute();
    $under_maintenance_count_result = $under_maintenance_count_stmt->get_result()->fetch_assoc();
    $total_under_maintenance_count = $under_maintenance_count_result['total_count'];
    $under_maintenance_count_stmt->close();
} else {
    $under_maintenance_count_result = $conn->query($under_maintenance_count_sql)->fetch_assoc();
    $total_under_maintenance_count = $under_maintenance_count_result['total_count'];
}

// Fetch ALL Under Maintenance items for modal
$under_maintenance_sql_all = "SELECT asset_no, asset_name, location, category_id, status FROM assets WHERE status = 'Under Maintenance' AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL)";
if ($isStaff) {
    $under_maintenance_sql_all .= " AND assigned_to = ?";
}
$under_maintenance_sql_all .= " ORDER BY updated_at DESC";

if (!empty($maintenance_params)) {
    $under_maintenance_all_stmt = $conn->prepare($under_maintenance_sql_all);
    $under_maintenance_all_stmt->bind_param($maintenance_types, ...$maintenance_params);
    $under_maintenance_all_stmt->execute();
    $under_maintenance_all_result = $under_maintenance_all_stmt->get_result();
} else {
    $under_maintenance_all_result = $conn->query($under_maintenance_sql_all);
}

if ($under_maintenance_all_result) {
    $all_items_under_maintenance = $under_maintenance_all_result->fetch_all(MYSQLI_ASSOC);
    if (isset($under_maintenance_all_stmt)) $under_maintenance_all_stmt->close();
}

// --- END: Fetch items for maintenance widgets (limited and total counts) ---
// --- START: New Grouped Recent Activity ---

// Helper function to group assets by batch
function get_grouped_assets($conn, $period = 'all', $limit = null)
{
  $thisMonth = date('Y-m');
  $isStaff = ($_SESSION['role'] === 'staff');
  $staffName = $_SESSION['user_name'];

  $sql = "SELECT id, asset_no, asset_name, category_id, location, assigned_to, created_at, batch_id, cost FROM assets";
  $params = [];
  $types = "";

  $where_clauses = ["retire_at IS NULL", "(transferred = 0 OR transferred IS NULL)"];
  if ($period === 'month') {
    $where_clauses[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
    $types .= "s";
    $params[] = $thisMonth;
  }

  if ($isStaff) {
    $where_clauses[] = "assigned_to = ?";
    $types .= "s";
    $params[] = $staffName;
  }

  if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
  }

  $sql .= " ORDER BY created_at DESC";

  $stmt = $conn->prepare($sql);
  if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $result = $stmt->get_result();

  $asset_batches = [];
  if ($result) {
    while ($asset = $result->fetch_assoc()) {
      $batch_id = $asset['batch_id'];
      if (empty($batch_id)) {
        $batch_id = 'batch_uncategorized_' . $asset['id'];
      }

      if (!isset($asset_batches[$batch_id])) {
        $asset_batches[$batch_id] = [
          'details' => $asset,
          'items' => []
        ];
      }
      $asset_batches[$batch_id]['items'][] = $asset;
    }
  }
  $stmt->close();

  if ($limit !== null) {
    return array_slice($asset_batches, 0, $limit, true);
  }

  return $asset_batches;
}

// Fetch grouped assets for both periods
$recentAllGrouped = get_grouped_assets($conn, 'all', 4);
$recentMonthGrouped = get_grouped_assets($conn, 'month', 4);

// Fetch all assets for the "View All" modal
$recentAllGrouped_modal = get_grouped_assets($conn, 'all');
$recentMonthGrouped_modal = get_grouped_assets($conn, 'month');

// --- END: New Grouped Recent Activity ---
// --- END: Fetch asset counts ---

// Fetch all unique locations for the audit dropdown
$locations = [];
$loc_result = $conn->query("SELECT DISTINCT location FROM assets WHERE location IS NOT NULL AND location != '' ORDER BY location ASC");
if ($loc_result) {
    while ($loc_row = $loc_result->fetch_assoc()) {
        $locations[] = $loc_row['location'];
    }
    $loc_result->free();
}

// NEW: Fetch staff users for assignment dropdown
$staff_users = [];
if ($_SESSION['role'] === 'admin') {
    $staff_result = $conn->query("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name ASC");
    if ($staff_result) {
        $staff_users = $staff_result->fetch_all(MYSQLI_ASSOC);
    }
}

// NEW: Fetch assigned audits
$assigned_audits = [];
if ($_SESSION['role'] === 'admin') {
    // Admins can see all assigned audits
    $assigned_audits_result = $conn->query("
        SELECT a.id, a.location_id, a.audit_date, a.status, u.full_name as assigned_to_name, assigner.full_name as assigned_by_name
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        LEFT JOIN users assigner ON a.assigned_by_user_id = assigner.id
        WHERE a.assigned_by_user_id IS NOT NULL
        ORDER BY FIELD(a.status, 'Assigned', 'In Progress', 'Completed'), a.audit_date DESC
    ");
} else { // Staff can only see audits assigned TO them
    $assigned_audits_stmt = $conn->prepare("
        SELECT a.id, a.location_id, a.audit_date, a.status, u.full_name as assigned_to_name
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.status = 'Assigned' AND a.audited_by_user_id = ?
        ORDER BY a.audit_date DESC
    ");
    $assigned_audits_stmt->bind_param("i", $_SESSION['user_id']);
    $assigned_audits_stmt->execute();
    $assigned_audits_result = $assigned_audits_stmt->get_result();
}
if ($assigned_audits_result) {
    $assigned_audits = $assigned_audits_result->fetch_all(MYSQLI_ASSOC);
}
if (isset($assigned_audits_stmt)) $assigned_audits_stmt->close();

// Fetch ongoing audits for the audit page
$ongoing_audits = [];
$user_id = $_SESSION['user_id'];
if ($_SESSION['role'] === 'admin') {
    $ongoing_audits_result = $conn->query("
        SELECT a.id, a.location_id, a.audit_date, u.full_name
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.status = 'In Progress'
        ORDER BY a.audit_date DESC
    ");
} else { // Staff can only see their own
    $ongoing_audits_stmt = $conn->prepare("
        SELECT a.id, a.location_id, a.audit_date, u.full_name
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.status = 'In Progress' AND a.audited_by_user_id = ?
        ORDER BY a.audit_date DESC
    ");
    $ongoing_audits_stmt->bind_param("i", $user_id);
    $ongoing_audits_stmt->execute();
    $ongoing_audits_result = $ongoing_audits_stmt->get_result();
}
if ($ongoing_audits_result) {
    $ongoing_audits = $ongoing_audits_result->fetch_all(MYSQLI_ASSOC);
}
if (isset($ongoing_audits_stmt)) $ongoing_audits_stmt->close();

// Fetch completed audits for the audit page
$completed_audits = [];
if ($_SESSION['role'] === 'admin') {
    $completed_audits_result = $conn->query("
        SELECT a.id, a.location_id, a.audit_date, u.full_name FROM audits a JOIN users u ON a.audited_by_user_id = u.id WHERE a.status = 'Completed' ORDER BY a.audit_date DESC LIMIT 10
    ");
} else { // Staff can only see their own
    $completed_audits_stmt = $conn->prepare("
        SELECT a.id, a.location_id, a.audit_date, u.full_name FROM audits a JOIN users u ON a.audited_by_user_id = u.id WHERE a.status = 'Completed' AND a.audited_by_user_id = ? ORDER BY a.audit_date DESC LIMIT 10
    ");
    $completed_audits_stmt->bind_param("i", $user_id);
    $completed_audits_stmt->execute();
    $completed_audits_result = $completed_audits_stmt->get_result();
}
if ($completed_audits_result) {
    $completed_audits = $completed_audits_result->fetch_all(MYSQLI_ASSOC);
}
if (isset($completed_audits_stmt)) $completed_audits_stmt->close();

// NEW: Count for sidebar notification badge
$assigned_to_me_count = 0;
if ($_SESSION['role'] === 'staff') {
    $assigned_to_me_count = count($assigned_audits);
}

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

$current_page = $pageView;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>KDP Asset Manager — <?php echo ($_SESSION['role'] === 'staff') ? 'Faculty Dashboard' : 'Dashboard'; ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="loader/loader.css" />
  <link rel="stylesheet" href="notifications.css" />

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
  <?php include 'loader/loader.html'; ?>
  <div class="h-screen flex overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <!-- Overlay for mobile sidebar -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

    <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">

      <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
          <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
            <i data-lucide="menu" style="width:20px;height:20px"></i>
          </button>
          <div id="search-container" class="relative w-full flex items-center">
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
          <div id="notification-wrapper" class="notification-wrapper">
              <button type="button" class="notification-bell" aria-label="Notifications">
                  <i data-lucide="bell" style="width:19px;height:19px"></i>
                  <span class="notification-badge"></span>
              </button>
              <div class="notification-panel">
                  <div class="notification-panel-header">
                      <h4>Notifications</h4>
                      <div class="notification-panel-actions">
                          <button type="button" id="mark-all-read">Mark all as read</button>
                          <button type="button" id="clear-all" title="Clear notifications" aria-label="Clear notifications">
                              <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                          </button>
                      </div>
                  </div>
                  <ul class="notification-list">
                      <li class="notification-item empty">Loading...</li>
                  </ul>
              </div>
          </div>
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

      <div class="flex-1 overflow-y-auto flex flex-col">
        <main class="flex-1 bg-gray-50 p-4 lg:p-6">
        <?php if ($showRegister): ?>
          <div id="registerView">
            <?php include 'register.php'; ?>
          </div>
        <?php elseif ($showGenerateReport): ?>
          <?php define('IS_EMBEDDED', true);
          include 'generate-report.php';
          ?>
        <?php elseif ($showWriteOffAssets): ?>
          <?php
          if (!defined('IS_EMBEDDED')) {
            define('IS_EMBEDDED', true);
          }
          include 'write-off-assets.php';
          ?>
        <?php elseif ($showTransferAssets): ?>
          <?php
          if (!defined('IS_EMBEDDED')) {
            define('IS_EMBEDDED', true);
          }
          include 'transfer-assets.php';
          ?>
        <?php elseif ($showAddBorrowedAsset): ?>
          <?php
          if (!defined('IS_EMBEDDED')) {
            define('IS_EMBEDDED', true);
          }
          include 'add-borrowed-asset.php';
          ?>
        <?php elseif ($showLoanedAssets): ?>
          <?php
          if (!defined('IS_EMBEDDED')) {
            define('IS_EMBEDDED', true);
          }
          include 'loaned-assets.php';
          ?>
        <?php elseif ($showMyAssets): ?>
          <?php
          if (!defined('IS_EMBEDDED')) {
            define('IS_EMBEDDED', true);
          }
          include 'generate-report.php';
          ?>
        <?php elseif ($showAudit): ?>
          <div id="auditView">
            <?php
            if (!defined('IS_EMBEDDED')) {
                define('IS_EMBEDDED', true);
            }
            include 'audit-start.php';
            ?>
          </div>
        <?php elseif (!$showAddAsset && !$showGenerateReport && !$showMyAssets && !$showWriteOffAssets && !$showTransferAssets && !$showAudit): ?>
          <div id="dashboardView">
            <div class="flex items-start sm:items-center justify-between flex-wrap gap-3">
              <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
                  <?php echo ($_SESSION['role'] === 'staff') ? 'Faculty Dashboard' : 'Dashboard'; ?>
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                  <?php echo ($_SESSION['role'] === 'staff') ? "Welcome back, here's your assigned assets overview" : "Welcome back, here's your department overview"; ?>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <!-- All time / This month toggle -->
                <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 gap-1" id="periodToggle">
                  <button id="btnAllTime"
                    onclick="setPeriod('all')"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md bg-blue-600 text-white transition-all">
                    All Time
                  </button>
                  <button id="btnThisMonth"
                    onclick="setPeriod('month')"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all inline-flex items-center gap-1.5">
                    <i data-lucide="calendar" style="width:13px;height:13px"></i> This Month
                  </button>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mt-6">

              <?php
              $cats = [
                1 => ['label' => 'Expandable',  'icon' => 'package',        'color' => 'blue',    'hover' => 'blue'],
                2 => ['label' => 'Consumables', 'icon' => 'flask-conical',  'color' => 'purple',  'hover' => 'purple'],
                3 => ['label' => 'Deadstock',   'icon' => 'alert-triangle', 'color' => 'amber',   'hover' => 'amber'],
                4 => ['label' => 'Furniture',   'icon' => 'armchair',       'color' => 'emerald', 'hover' => 'emerald'],
              ];
              foreach ($cats as $cid => $cat):
                $total = $category_counts[$cid];
                $monthly = $month_counts[$cid];
              ?>
                <a href="view-assets.php?category_id=<?php echo $cid; ?>"
                  class="block bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:shadow-lg transition-all duration-300">
                  <div class="flex items-start justify-between">
                    <p class="text-sm font-medium text-gray-500"><?php echo $cat['label']; ?></p>
                    <div class="w-9 h-9 rounded-lg bg-<?php echo $cat['color']; ?>-50 flex items-center justify-center shrink-0">
                      <i data-lucide="<?php echo $cat['icon']; ?>" class="text-<?php echo $cat['color']; ?>-600" style="width:18px;height:18px"></i>
                    </div>
                  </div>
                  <!-- All-time count -->
                  <p id="count-all-<?php echo $cid; ?>" class="text-3xl font-bold text-gray-900 mt-3 tracking-tight"><?php echo number_format($total); ?></p>
                  <!-- This-month count (hidden by default) -->
                  <p id="count-month-<?php echo $cid; ?>" class="text-3xl font-bold text-gray-900 mt-3 tracking-tight hidden"><?php echo number_format($monthly); ?></p>
                  <!-- Sub-text all-time -->
                  <p id="sub-all-<?php echo $cid; ?>" class="text-xs font-medium text-emerald-600 mt-2 inline-flex items-center gap-1">
                    <i data-lucide="layers" style="width:13px;height:13px"></i> Total assets
                  </p>
                  <!-- Sub-text this-month -->
                  <p id="sub-month-<?php echo $cid; ?>" class="text-xs font-medium mt-2 inline-flex items-center gap-1 hidden <?php echo $monthly > 0 ? 'text-emerald-600' : 'text-gray-400'; ?>">
                    <?php if ($monthly > 0): ?>
                      <i data-lucide="trending-up" style="width:13px;height:13px"></i> +<?php echo $monthly; ?> added this month
                    <?php else: ?>
                      <i data-lucide="minus" style="width:13px;height:13px"></i> None added this month
                    <?php endif; ?>
                  </p>
                </a>
              <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">

              <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 self-start">
                <div class="flex items-center justify-between mb-4">
                  <div>
                    <h2 class="font-semibold text-gray-900">Recent Activity
                      <span id="activityLabel" class="text-xs font-normal text-gray-400 ml-1">(All Time)</span>
                    </h2>
                  </div>
                  <div>
                    <button id="viewAllBtn" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors">View All</button>
                  </div>
                </div>
                <div class="overflow-x-auto -mx-1">
                  <table class="w-full text-sm min-w-[560px]">
                    <thead>
                      <tr class="text-left text-[11px] text-gray-400 uppercase tracking-wider">
                        <th class="pb-3 px-1 font-medium">ASSET NO</th>
                        <th class="pb-3 px-1 font-medium">EQUIPMENT NAME</th>
                        <th class="pb-3 px-1 font-medium">CATEGORY</th>
                        <th class="pb-3 px-1 font-medium">LOCATION</th>
                        <th class="pb-3 px-1 font-medium text-right">DATE ADDED</th>
                      </tr>
                    </thead>
                    <tbody id="activityBody" class="divide-y divide-gray-100">
                      <?php
                      $catColors = [1 => 'emerald', 2 => 'blue', 3 => 'amber', 4 => 'purple'];
                      $catLabels = [1 => 'Expandable', 2 => 'Consumables', 3 => 'Deadstock', 4 => 'Furniture'];

                      // Loop for "All Time" grouped assets
                      foreach ($recentAllGrouped as $batch_id => $batch):
                        $details = $batch['details'];
                        $cid = (int)$details['category_id'];
                        $color = $catColors[$cid] ?? 'gray';
                        $label = $catLabels[$cid] ?? 'Unknown';
                        $loc = htmlspecialchars($details['location'] ?: ($details['assigned_to'] ?: '—'));
                        $group_key = strtolower(trim(preg_replace('/^.*\s/', '', $details['asset_name'])));
                      ?>
                        <tr class="row-all cursor-pointer" onclick="window.location.href='view-assets.php?category_id=<?php echo $cid; ?>&group=<?php echo urlencode($group_key); ?>'">
                          <td class="py-3.5 px-1 font-medium text-gray-900"><?php echo htmlspecialchars($details['asset_no']); ?></td>
                          <td class="py-3.5 px-1 text-gray-600"><?php echo htmlspecialchars($details['asset_name']); ?></td>
                          <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-600"><?php echo $label; ?></span></td>
                          <td class="py-3.5 px-1 text-gray-500"><?php echo $loc; ?></td>
                          <td class="py-3.5 px-1 text-gray-500 text-right"><?php echo date('Y-m-d', strtotime($details['created_at'])); ?></td>
                        </tr>
                      <?php endforeach; ?>

                      <?php if (empty($recentAllGrouped)): ?>
                        <tr class="row-all">
                          <td colspan="5" class="py-6 text-center text-gray-400 text-sm">No assets found.</td>
                        </tr>
                      <?php endif; ?>

                      <?php
                      // Loop for "This Month" grouped assets
                      foreach ($recentMonthGrouped as $batch_id => $batch):
                        $details = $batch['details'];
                        $cid = (int)$details['category_id'];
                        $color = $catColors[$cid] ?? 'gray';
                        $label = $catLabels[$cid] ?? 'Unknown';
                        $loc = htmlspecialchars($details['location'] ?: ($details['assigned_to'] ?: '—'));
                        $group_key = strtolower(trim(preg_replace('/^.*\s/', '', $details['asset_name'])));
                      ?>
                        <tr class="row-month hidden cursor-pointer" onclick="window.location.href='view-assets.php?category_id=<?php echo $cid; ?>&group=<?php echo urlencode($group_key); ?>'">
                          <td class="py-3.5 px-1 font-medium text-gray-900"><?php echo htmlspecialchars($details['asset_no']); ?></td>
                          <td class="py-3.5 px-1 text-gray-600"><?php echo htmlspecialchars($details['asset_name']); ?></td>
                          <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-600"><?php echo $label; ?></span></td>
                          <td class="py-3.5 px-1 text-gray-500"><?php echo $loc; ?></td>
                          <td class="py-3.5 px-1 text-gray-500 text-right"><?php echo date('Y-m-d', strtotime($details['created_at'])); ?></td>
                        </tr>
                      <?php endforeach; ?>

                      <?php if (empty($recentMonthGrouped)): ?>
                        <tr class="row-month hidden">
                          <td colspan="5" class="py-6 text-center text-gray-400 text-sm">No assets added this month.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 flex flex-col">
                  <h2 class="font-semibold text-gray-900">Category Overview</h2>
                  <p class="text-xs text-gray-400 mt-1 mb-6">Total assets by category</p>

                  <div class="flex-1 flex items-end justify-between gap-3 min-h-[180px]">

                    <!-- Expandable -->
                    <div class="flex flex-col items-center gap-2 flex-1">
                      <span class="text-[11px] font-semibold text-gray-600">
                        <?php echo number_format($category_counts[1]); ?>
                      </span>

                      <div
                        class="w-full max-w-[34px] bg-gradient-to-t from-blue-500 to-blue-400 rounded-t-md transition-all duration-500"
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
                        class="w-full max-w-[34px] bg-gradient-to-t from-purple-500 to-purple-400 rounded-t-md transition-all duration-500"
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
                        class="w-full max-w-[34px] bg-gradient-to-t from-amber-500 to-amber-400 rounded-t-md transition-all duration-500"
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
                        class="w-full max-w-[34px] bg-gradient-to-t from-emerald-500 to-emerald-400 rounded-t-md transition-all duration-500"
                        style="height:<?php echo $chartHeights[4]; ?>px">
                      </div>

                      <span class="text-[11px] text-gray-400">
                        Furniture
                      </span>
                    </div>

                  </div>
                </div>
            </div>

            <?php if (!$isStaff): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-6">
                <!-- Not Working Items Widget -->
                  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                      <div class="flex items-start justify-between mb-4">
                          <div>
                              <h2 class="font-semibold text-gray-900">Not Working / Missing Items</h2>
                              <p class="text-xs text-gray-400 mt-1">Assets reported as not functional.</p>
                          </div>
                          <div class="w-9 h-9 rounded-lg bg-red-50 flex items-center justify-center shrink-0">
                              <i data-lucide="alert-octagon" class="text-red-600" style="width:18px;height:18px"></i>
                          </div>
                      </div>
                      <div>
                          <?php if (empty($items_not_working)): ?>
                              <div class="text-center py-6 border-t border-gray-100">
                                  <p class="text-sm text-gray-500 mt-4">No items are reported as 'Not Working' or 'Missing'.</p>
                              </div>
                          <?php else: ?>
                              <ul class="divide-y divide-gray-100 -mx-5 lg:-mx-6">
                                  <?php foreach ($items_not_working as $item): ?>
                                      <li class="px-5 lg:px-6 py-3 hover:bg-gray-50 transition-colors">
                                          <a href="view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>" class="block">
                                              <div class="flex items-center justify-between">
                                                  <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($item['asset_name']); ?></p>
                                                  <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-red-100 text-red-700">
                                                      <?php echo htmlspecialchars($item['status']); ?>
                                                  </span>
                                              </div>
                                              <div class="text-xs text-gray-500 mt-1 flex items-center gap-x-3">
                                                  <span>ID: <strong class="font-mono text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></strong></span>
                                                  <?php if (!empty($item['location'])): ?>
                                                      <span>| At: <strong class="text-gray-600"><?php echo htmlspecialchars($item['location']); ?></strong></span>
                                                  <?php endif; ?>
                                              </div>
                                          </a>
                                      </li>
                                  <?php endforeach; ?>
                              </ul>
                          <?php endif; ?>
                        <?php if ($total_not_working_count > 4): ?>
                            <div class="text-center pt-4 border-t border-gray-100 mt-4">
                                <button type="button" id="viewAllNotWorkingBtn" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors">View All (<?php echo $total_not_working_count; ?>)</button>
                            </div>
                        <?php endif; ?>
                      </div>
                  </div>
                  <!-- Items Under Maintenance Widget -->
                  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                      <div class="flex items-start justify-between mb-4">
                          <div>
                              <h2 class="font-semibold text-gray-900">Under Maintenance</h2>
                              <p class="text-xs text-gray-400 mt-1">Assets currently being repaired.</p>
                          </div>
                          <div class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center shrink-0">
                              <i data-lucide="wrench" class="text-amber-600" style="width:18px;height:18px"></i>
                          </div>
                      </div>
                      <div>
                          <?php if (empty($items_under_maintenance)): ?>
                              <div class="text-center py-6 border-t border-gray-100">
                                  <p class="text-sm text-gray-500 mt-4">No assets are under maintenance.</p>
                              </div>
                          <?php else: ?>
                              <ul class="divide-y divide-gray-100 -mx-5 lg:-mx-6">
                                  <?php foreach ($items_under_maintenance as $item): ?>
                                      <li class="px-5 lg:px-6 py-3 hover:bg-gray-50 transition-colors">
                                          <a href="view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>" class="block">
                                              <div class="flex items-center justify-between">
                                                  <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($item['asset_name']); ?></p>
                                                  <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                                                      <?php echo htmlspecialchars($item['status']); ?>
                                                  </span>
                                              </div>
                                              <div class="text-xs text-gray-500 mt-1 flex items-center gap-x-3">
                                                  <span>ID: <strong class="font-mono text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></strong></span>
                                                  <?php if (!empty($item['location'])): ?>
                                                      <span>| At: <strong class="text-gray-600"><?php echo htmlspecialchars($item['location']); ?></strong></span>
                                                  <?php endif; ?>
                                              </div>
                                          </a>
                                      </li>
                                  <?php endforeach; ?>
                              </ul>
                          <?php endif; ?>
                        <?php if ($total_under_maintenance_count > 4): ?>
                            <div class="text-center pt-4 border-t border-gray-100 mt-4">
                                <button type="button" id="viewAllUnderMaintenanceBtn" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors">View All (<?php echo $total_under_maintenance_count; ?>)</button>
                            </div>
                        <?php endif; ?>
                      </div>
                  </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
          <div id="assetView" class="w-full">
            <?php define('IS_EMBEDDED', true);
            include 'add-asset.php'; ?>
          </div>
        <?php endif; ?>
        </main>
        <?php include 'footer.php'; ?>
      </div>
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

  <!-- View All Recent Activity Modal -->
  <div id="recentActivityModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col transform transition-all">
      <div class="p-5 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-lg font-bold text-gray-900">All Recent Activity</h3>
        <button type="button" id="closeActivityModalBtn" class="p-1 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600">
          <i data-lucide="x" style="width:20px;height:20px"></i>
        </button>
      </div>
      <div class="p-5 flex-1 overflow-y-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-[11px] text-gray-400 uppercase tracking-wider">
              <th class="pb-3 px-1 font-medium">ASSET NO</th>
              <th class="pb-3 px-1 font-medium">EQUIPMENT NAME</th>
              <th class="pb-3 px-1 font-medium">CATEGORY</th>
              <th class="pb-3 px-1 font-medium">LOCATION</th>
              <th class="pb-3 px-1 font-medium text-right">DATE ADDED</th>
            </tr>
          </thead>
          <tbody id="activityModalBody" class="divide-y divide-gray-100">
            <?php
            // The modal starts from the all-time activity list. The month view is
            // handled client-side by data attributes so all-time never hides rows.
            $all_modal_assets = $recentAllGrouped_modal;
            uasort($all_modal_assets, function ($a, $b) {
              return strtotime($b['details']['created_at']) - strtotime($a['details']['created_at']);
            });

            // Loop for "All" modal assets
            foreach ($all_modal_assets as $batch_id => $batch):
              $details = $batch['details'];
              $cid = (int)$details['category_id'];
              $color = $catColors[$cid] ?? 'gray';
              $label = $catLabels[$cid] ?? 'Unknown';
              $loc = htmlspecialchars($details['location'] ?: ($details['assigned_to'] ?: '—'));
              $is_month_row = strpos($details['created_at'], $thisMonth) === 0;
              $group_key = strtolower(trim(preg_replace('/^.*\s/', '', $details['asset_name'])));
            ?>
              <tr class="activity-modal-row" data-is-month="<?php echo $is_month_row ? '1' : '0'; ?>" onclick="window.location.href='view-assets.php?category_id=<?php echo $cid; ?>&group=<?php echo urlencode($group_key); ?>'">
                <td class="py-3.5 px-1 font-medium text-gray-900"><?php echo htmlspecialchars($details['asset_no']); ?></td>
                <td class="py-3.5 px-1 text-gray-600"><?php echo htmlspecialchars($details['asset_name']); ?></td>
                <td class="py-3.5 px-1"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-600"><?php echo $label; ?></span></td>
                <td class="py-3.5 px-1 text-gray-500"><?php echo $loc; ?></td>
                <td class="py-3.5 px-1 text-gray-500 text-right"><?php echo date('Y-m-d', strtotime($details['created_at'])); ?></td>
              </tr>
            <?php endforeach; ?>

            <?php if (empty($all_modal_assets)): ?>
              <tr>
                <td colspan="5" class="py-10 text-center text-gray-500">No recent activity to display.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if (!$isStaff): ?>
  <!-- View All Not Working Modal -->
  <div id="notWorkingModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col transform transition-all">
          <div class="p-5 border-b border-gray-200 flex items-center justify-between">
              <h3 class="text-lg font-bold text-gray-900">All 'Not Working / Missing' Items</h3>
              <button type="button" id="closeNotWorkingModalBtn" class="p-1 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600">
                  <i data-lucide="x" style="width:20px;height:20px"></i>
              </button>
          </div>
          <div class="p-5 flex-1 overflow-y-auto">
              <table class="w-full text-sm">
                  <thead>
                      <tr class="text-left text-[11px] text-gray-400 uppercase tracking-wider">
                          <th class="pb-3 px-1 font-medium">EQUIPMENT NAME</th>
                          <th class="pb-3 px-1 font-medium">ASSET ID</th>
                          <th class="pb-3 px-1 font-medium">LOCATION</th>
                          <th class="pb-3 px-1 font-medium">STATUS</th>
                      </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                      <?php foreach ($all_items_not_working as $item): ?>
                          <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location.href='view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>'">
                              <td class="py-3.5 px-1 font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                              <td class="py-3.5 px-1 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></td>
                              <td class="py-3.5 px-1 text-gray-500"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                              <td class="py-3.5 px-1">
                                  <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-red-100 text-red-700">
                                      <?php echo htmlspecialchars($item['status']); ?>
                                  </span>
                              </td>
                          </tr>
                      <?php endforeach; ?>
                      <?php if (empty($all_items_not_working)): ?>
                          <tr><td colspan="4" class="py-10 text-center text-gray-500">No items are reported as 'Not Working' or 'Missing'.</td></tr>
                      <?php endif; ?>
                  </tbody>
              </table>
          </div>
      </div>
  </div>

  <!-- View All Under Maintenance Modal -->
  <div id="underMaintenanceModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col transform transition-all">
          <div class="p-5 border-b border-gray-200 flex items-center justify-between">
              <h3 class="text-lg font-bold text-gray-900">All 'Under Maintenance' Items</h3>
              <button type="button" id="closeUnderMaintenanceModalBtn" class="p-1 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600">
                  <i data-lucide="x" style="width:20px;height:20px"></i>
              </button>
          </div>
          <div class="p-5 flex-1 overflow-y-auto">
              <table class="w-full text-sm">
                  <thead>
                      <tr class="text-left text-[11px] text-gray-400 uppercase tracking-wider">
                          <th class="pb-3 px-1 font-medium">EQUIPMENT NAME</th>
                          <th class="pb-3 px-1 font-medium">ASSET ID</th>
                          <th class="pb-3 px-1 font-medium">LOCATION</th>
                          <th class="pb-3 px-1 font-medium">STATUS</th>
                      </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                      <?php foreach ($all_items_under_maintenance as $item): ?>
                          <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location.href='view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>'">
                              <td class="py-3.5 px-1 font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                              <td class="py-3.5 px-1 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></td>
                              <td class="py-3.5 px-1 text-gray-500"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                              <td class="py-3.5 px-1">
                                  <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                                      <?php echo htmlspecialchars($item['status']); ?>
                                  </span>
                              </td>
                          </tr>
                      <?php endforeach; ?>
                      <?php if (empty($all_items_under_maintenance)): ?>
                          <tr><td colspan="4" class="py-10 text-center text-gray-500">No assets are under maintenance.</td></tr>
                      <?php endif; ?>
                  </tbody>
              </table>
          </div>
      </div>
  </div>
  <?php endif; ?>

  <script>
    lucide.createIcons();

    // --- Period Toggle: All Time / This Month ---
    function setPeriod(period) {
      const isMonth = period === 'month';

      // Toggle button styles
      const btnAll = document.getElementById('btnAllTime');
      const btnMonth = document.getElementById('btnThisMonth');
      if (btnAll && btnMonth) {
        if (isMonth) {
          btnAll.className = 'text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all';
          btnMonth.className = 'text-xs font-semibold px-3 py-1.5 rounded-md bg-blue-600 text-white transition-all inline-flex items-center gap-1.5';
        } else {
          btnAll.className = 'text-xs font-semibold px-3 py-1.5 rounded-md bg-blue-600 text-white transition-all';
          btnMonth.className = 'text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all inline-flex items-center gap-1.5';
        }
      }

      // Toggle category card counts + sub-text (cats 1-4)
      [1, 2, 3, 4].forEach(id => {
        const countAll = document.getElementById('count-all-' + id);
        const countMonth = document.getElementById('count-month-' + id);
        const subAll = document.getElementById('sub-all-' + id);
        const subMonth = document.getElementById('sub-month-' + id);
        if (countAll) countAll.classList.toggle('hidden', isMonth);
        if (countMonth) countMonth.classList.toggle('hidden', !isMonth);
        if (subAll) subAll.classList.toggle('hidden', isMonth);
        if (subMonth) subMonth.classList.toggle('hidden', !isMonth);
      });

      // Toggle activity rows
      document.querySelectorAll('.row-all').forEach(r => r.classList.toggle('hidden', isMonth));
      document.querySelectorAll('.row-month').forEach(r => r.classList.toggle('hidden', !isMonth));

      // Update activity label
      const lbl = document.getElementById('activityLabel');
      if (lbl) lbl.textContent = isMonth ? '(This Month)' : '(All Time)';

      lucide.createIcons();
    }


    const userMenuBtn = document.getElementById('userMenuBtn');
    const userMenuDropdown = document.getElementById('userMenuDropdown');
    const changePasswordBtn = document.getElementById('changePasswordBtn');
    const passwordModal = document.getElementById('passwordModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelModalBtn = document.getElementById('cancelModalBtn');
    const changePasswordForm = document.getElementById('changePasswordForm');
    const notification = document.getElementById('notification');

    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const menuBtn = document.getElementById('menuBtn');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    // --- User Menu Dropdown Logic ---
    userMenuBtn.addEventListener('click', () => {
      userMenuDropdown.classList.toggle('hidden');
    });

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
    const IS_STAFF = <?php echo ($_SESSION['role'] === 'staff') ? 'true' : 'false'; ?>;
    const CURRENT_USER = <?php echo json_encode($_SESSION['user_name'] ?? ''); ?>;
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
              const infoHtml = `
              <p class="font-semibold text-sm text-gray-800 capitalize">${item.asset_name}</p>
              <div class="text-xs text-gray-500 mt-1 flex items-center gap-x-3">
                <span>in <strong class="font-medium text-gray-600">${item.category_name}</strong></span>
                ${item.location ? `<span>| Lab/Location: <strong class="font-medium text-gray-600">${item.location}</strong></span>` : ''}
                ${item.assigned_to ? `<span>| With: <strong class="font-medium text-gray-600">${item.assigned_to}</strong></span>` : ''}
              </div>
            `;

              if (IS_STAFF) {
                const isOwn = item.assigned_to && item.assigned_to.toLowerCase() === CURRENT_USER.toLowerCase();
                if (isOwn) {
                  // Staff viewing their OWN asset — clickable
                  resultsHtml += `
                  <li>
                    <a href="view-asset-details.php?category_id=${item.category_id}&asset_name=${encodeURIComponent(item.asset_name)}"
                       class="flex items-center justify-between p-3 rounded-md hover:bg-blue-50 border border-transparent hover:border-blue-100 transition-colors">
                      <div>${infoHtml}</div>
                      <i data-lucide="arrow-right" class="w-4 h-4 text-blue-400"></i>
                    </a>
                  </li>
                `;
                } else {
                  // Staff viewing someone else's asset — info only
                  resultsHtml += `
                  <li>
                    <div class="flex items-center justify-between p-3 rounded-md bg-gray-50 cursor-default select-none">
                      <div>${infoHtml}</div>
                      <span class="text-xs text-gray-400 ml-2 whitespace-nowrap">Info only</span>
                    </div>
                  </li>
                `;
                }
              } else {
                // Admin: clickable link to asset details
                resultsHtml += `
                <li>
                  <a href="view-asset-details.php?category_id=${item.category_id}&asset_name=${encodeURIComponent(item.asset_name)}"
                     class="flex items-center justify-between p-3 rounded-md hover:bg-gray-50 transition-colors">
                    <div>${infoHtml}</div>
                    <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                  </a>
                </li>
              `;
              }
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

    // --- View All Activity Modal Logic ---
    const activityModal = document.getElementById('recentActivityModal');
    const viewAllBtn = document.getElementById('viewAllBtn');
    const closeActivityModalBtn = document.getElementById('closeActivityModalBtn');

    if (viewAllBtn && activityModal && closeActivityModalBtn) {
      viewAllBtn.addEventListener('click', () => {
        // Check which tab is active on the main page
        const isMonthView = document.getElementById('btnThisMonth').classList.contains('bg-blue-600');

        // All Time should show every recent activity row; This Month filters only
        // rows whose created_at belongs to the current month.
        document.querySelectorAll('.activity-modal-row').forEach(row => {
          const isMonthRow = row.dataset.isMonth === '1';
          row.classList.toggle('hidden', isMonthView && !isMonthRow);
        });

        activityModal.classList.remove('hidden');
        lucide.createIcons(); // Re-render icons if any are in the modal
      });

      closeActivityModalBtn.addEventListener('click', () => {
        activityModal.classList.add('hidden');
      });

      activityModal.addEventListener('click', (e) => {
        if (e.target === activityModal) {
          activityModal.classList.add('hidden');
        }
      });
    }

    // --- View All Maintenance Modals Logic ---
    const notWorkingModal = document.getElementById('notWorkingModal');
    const viewAllNotWorkingBtn = document.getElementById('viewAllNotWorkingBtn');
    const closeNotWorkingModalBtn = document.getElementById('closeNotWorkingModalBtn');

    if (viewAllNotWorkingBtn && notWorkingModal && closeNotWorkingModalBtn) {
        viewAllNotWorkingBtn.addEventListener('click', () => {
            notWorkingModal.classList.remove('hidden');
            lucide.createIcons();
        });
        closeNotWorkingModalBtn.addEventListener('click', () => {
            notWorkingModal.classList.add('hidden');
        });
        notWorkingModal.addEventListener('click', (e) => {
            if (e.target === notWorkingModal) {
                notWorkingModal.classList.add('hidden');
            }
        });
    }

    const underMaintenanceModal = document.getElementById('underMaintenanceModal');
    const viewAllUnderMaintenanceBtn = document.getElementById('viewAllUnderMaintenanceBtn');
    const closeUnderMaintenanceModalBtn = document.getElementById('closeUnderMaintenanceModalBtn');

    if (viewAllUnderMaintenanceBtn && underMaintenanceModal && closeUnderMaintenanceModalBtn) {
        viewAllUnderMaintenanceBtn.addEventListener('click', () => {
            underMaintenanceModal.classList.remove('hidden');
            lucide.createIcons();
        });
        closeUnderMaintenanceModalBtn.addEventListener('click', () => {
            underMaintenanceModal.classList.add('hidden');
        });
        underMaintenanceModal.addEventListener('click', (e) => {
            if (e.target === underMaintenanceModal) {
                underMaintenanceModal.classList.add('hidden');
            }
        });
    }

  </script>
  <script src="loader/loader.js"></script>
  <script src="notifications.js"></script>
</body>

</html>