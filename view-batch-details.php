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
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'all';
$valid_filters = ['all', 'active', 'under maintenance', 'not working', 'retired'];
if (!in_array($filter_status, $valid_filters, true)) {
    $filter_status = 'all';
}

// Define categories to get the name from the ID
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

// Check if the parameters are valid
if ($category_id === 0 || !array_key_exists($category_id, $categories) || empty($asset_name_raw) || empty($batch_id)) {
    header("Location: 404.php");
    exit();
}

$category_name = $categories[$category_id];
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$is_staff = isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
$show_actions_column = $is_admin || $is_staff;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retire_batch') {
    if (!$is_admin) {
        header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Only admins can retire records."));
        exit();
    }

    $post_category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $post_asset_name = isset($_POST['asset_name']) ? trim($_POST['asset_name']) : '';
    $post_batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';

    if ($post_category_id !== $category_id || $post_asset_name !== $asset_name_raw || $post_batch_id !== $batch_id) {
        header("Location: view-asset-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&status=error&message=" . urlencode("Invalid retire request."));
        exit();
    }

    if (strpos($batch_id, 'batch_uncategorized_') === 0) {
        $retire_id = (int)substr($batch_id, strlen('batch_uncategorized_'));
        $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW() WHERE id = ? AND category_id = ? AND asset_name = ? AND retire_at IS NULL");
        if ($stmt) {
            $stmt->bind_param("iis", $retire_id, $category_id, $asset_name_raw);
        }
    } else {
        $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW() WHERE batch_id = ? AND category_id = ? AND asset_name = ? AND retire_at IS NULL");
        if ($stmt) {
            $stmt->bind_param("sis", $batch_id, $category_id, $asset_name_raw);
        }
    }

    if ($stmt && $stmt->execute()) {
        $stmt->close();
        header("Location: view-asset-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&status=retired");
        exit();
    }

    if ($stmt) {
        $stmt->close();
    }
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Unable to retire record."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retire_item') {
    if (!$is_admin) {
        header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Only admins can retire assets."));
        exit();
    }

    $post_category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $post_asset_name = isset($_POST['asset_name']) ? trim($_POST['asset_name']) : '';
    $post_batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';
    $retire_item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;

    if ($post_category_id !== $category_id || $post_asset_name !== $asset_name_raw || $post_batch_id !== $batch_id || $retire_item_id <= 0) {
        header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Invalid retire asset request."));
        exit();
    }

    if (strpos($batch_id, 'batch_uncategorized_') === 0) {
        $uncategorized_id = (int)substr($batch_id, strlen('batch_uncategorized_'));
        $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW() WHERE id = ? AND id = ? AND category_id = ? AND asset_name = ? AND retire_at IS NULL");
        if ($stmt) {
            $stmt->bind_param("iiis", $retire_item_id, $uncategorized_id, $category_id, $asset_name_raw);
        }
    } else {
        $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW() WHERE id = ? AND batch_id = ? AND category_id = ? AND asset_name = ? AND retire_at IS NULL");
        if ($stmt) {
            $stmt->bind_param("isis", $retire_item_id, $batch_id, $category_id, $asset_name_raw);
        }
    }

    if ($stmt && $stmt->execute()) {
        $stmt->close();

        $remaining_items = 0;
        if (strpos($batch_id, 'batch_uncategorized_') === 0) {
            $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM assets WHERE id = ? AND category_id = ? AND asset_name = ? AND retire_at IS NULL");
            if ($count_stmt) {
                $count_stmt->bind_param("iis", $retire_item_id, $category_id, $asset_name_raw);
            }
        } else {
            $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM assets WHERE batch_id = ? AND category_id = ? AND asset_name = ? AND retire_at IS NULL");
            if ($count_stmt) {
                $count_stmt->bind_param("sis", $batch_id, $category_id, $asset_name_raw);
            }
        }

        if ($count_stmt && $count_stmt->execute()) {
            $count_result = $count_stmt->get_result();
            if ($count_result) {
                $remaining_items = (int)($count_result->fetch_assoc()['total'] ?? 0);
            }
            $count_stmt->close();
        }

        if ($remaining_items > 0) {
            header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=item_retired");
        } else {
            header("Location: view-asset-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&status=item_retired");
        }
        exit();
    }

    if ($stmt) {
        $stmt->close();
    }
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Unable to retire asset."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_batch') {
    if (!$is_admin) {
        header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Only admins can delete records."));
        exit();
    }

    $post_category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $post_asset_name = isset($_POST['asset_name']) ? trim($_POST['asset_name']) : '';
    $post_batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';

    if ($post_category_id !== $category_id || $post_asset_name !== $asset_name_raw || $post_batch_id !== $batch_id) {
        header("Location: view-asset-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&status=error&message=" . urlencode("Invalid delete request."));
        exit();
    }

    if (strpos($batch_id, 'batch_uncategorized_') === 0) {
        $delete_id = (int)substr($batch_id, strlen('batch_uncategorized_'));
        $stmt = $conn->prepare("DELETE FROM assets WHERE id = ? AND category_id = ? AND asset_name = ?");
        if ($stmt) {
            $stmt->bind_param("iis", $delete_id, $category_id, $asset_name_raw);
        }
    } else {
        $stmt = $conn->prepare("DELETE FROM assets WHERE batch_id = ? AND category_id = ? AND asset_name = ?");
        if ($stmt) {
            $stmt->bind_param("sis", $batch_id, $category_id, $asset_name_raw);
        }
    }

    if ($stmt && $stmt->execute()) {
        $stmt->close();

        $remaining_count = 0;
        $remaining_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM assets WHERE category_id = ? AND asset_name = ? AND retire_at IS NULL");
        if ($remaining_stmt) {
            $remaining_stmt->bind_param("is", $category_id, $asset_name_raw);
            $remaining_stmt->execute();
            $remaining_result = $remaining_stmt->get_result();
            if ($remaining_result) {
                $remaining_count = (int)($remaining_result->fetch_assoc()['total'] ?? 0);
            }
            $remaining_stmt->close();
        }

        if ($remaining_count > 0) {
            header("Location: view-asset-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&status=deleted");
        } else {
            header("Location: view-assets.php?category_id=" . $category_id . "&status=deleted");
        }
        exit();
    }

    if ($stmt) {
        $stmt->close();
    }
    header("Location: view-batch-details.php?category_id=" . $category_id . "&asset_name=" . urlencode($asset_name_raw) . "&batch_id=" . urlencode($batch_id) . "&status=error&message=" . urlencode("Unable to delete record."));
    exit();
}

// Fetch batch details (one item) for the record summary
$batch_details = null;
if (strpos($batch_id, 'batch_uncategorized_') === 0) {
    $uncategorized_id = (int)substr($batch_id, strlen('batch_uncategorized_'));
    $batch_sql = "SELECT * FROM assets WHERE id = ? AND category_id = ? AND asset_name = ? LIMIT 1";
    $batch_params = ["iis", $uncategorized_id, $category_id, $asset_name_raw];
} else {
    $batch_sql = "SELECT * FROM assets WHERE batch_id = ? AND category_id = ? AND asset_name = ? LIMIT 1";
    $batch_params = ["sis", $batch_id, $category_id, $asset_name_raw];
}

$batch_stmt = $conn->prepare($batch_sql);
$batch_stmt->bind_param(...$batch_params);
$batch_stmt->execute();
$batch_result = $batch_stmt->get_result();
if ($batch_result) {
    $batch_details = $batch_result->fetch_assoc();
}
$batch_stmt->close();

if (!$batch_details) {
    header("Location: 404.php");
    exit();
}

// Fetch filtered items for the table
$items = [];
if (strpos($batch_id, 'batch_uncategorized_') === 0) {
    $uncategorized_id = (int)substr($batch_id, strlen('batch_uncategorized_'));
    $sql = "SELECT * FROM assets WHERE id = ? AND category_id = ? AND asset_name = ?";
    $params = ["iis", $uncategorized_id, $category_id, $asset_name_raw];
} else {
    $sql = "SELECT * FROM assets WHERE batch_id = ? AND category_id = ? AND asset_name = ?";
    $params = ["sis", $batch_id, $category_id, $asset_name_raw];
}

if ($filter_status === 'retired') {
    $sql .= " AND retire_at IS NOT NULL";
} else {
    $sql .= " AND retire_at IS NULL";
}

if ($filter_status !== 'all' && $filter_status !== 'retired') {
    $sql .= " AND LOWER(status) = ?";
    $params[0] .= "s";
    $params[] = $filter_status;
}

// Staff can only view rows assigned to themselves
if ($_SESSION['role'] === 'staff') {
    $sql .= " AND assigned_to = ?";
    $params[0] .= "s";
    $params[] = $_SESSION['user_name'];
}

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

$total_quantity_in_batch = count($items);
$total_cost_of_batch = $total_quantity_in_batch * (float)$batch_details['cost'];

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
    <title>Record Details - KDP Asset Manager</title>
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
    </style>

    <link rel="stylesheet" href="loader/loader.css" />
</head>

<body class="h-screen bg-gray-50 text-gray-900 antialiased">
    <?php include 'loader/loader.html'; ?>
    <div class="h-screen flex overflow-hidden">

        <?php include 'sidebar.php'; ?>

        <!-- Overlay for mobile sidebar -->
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

        <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
            <!-- Header -->
            <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6">
                <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                    <i data-lucide="menu" style="width:20px;height:20px"></i>
                </button>
                <div class="flex items-center gap-3 sm:gap-4 shrink-0">
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
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-gray-900">Record Summary</h2>
                            <?php if ($is_admin && $filter_status !== 'retired'): ?>
                                <button type="button" id="openDeleteModalBtn" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    Delete
                                </button>
                            <?php endif; ?>
                        </div>
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
                        <!-- Action buttons -->
                        <?php if ($is_admin && $filter_status !== 'retired'): ?>
                            <div class="flex justify-end mt-6 space-x-3">
                                <button id="openEditModalBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Edit
                                </button>
                                <button type="button" id="openRetireModalBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                    Retire
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex justify-end mt-6 mb-6 space-x-3 relative">
                        <div class="relative">
                            <button type="button" id="filterBtn" class="inline-flex items-center gap-1.5 px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                Filter
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ml-0.5"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div id="filterDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 z-50 overflow-hidden">
                                <?php
                                $filter_options = [
                                    'all' => 'All',
                                    'active' => 'Active',
                                    'under maintenance' => 'Under Maintenance',
                                    'not working' => 'Not Working',
                                    'retired' => 'Retired'
                                ];
                                $base_params = $_GET;
                                foreach ($filter_options as $value => $label):
                                    $base_params['filter_status'] = $value;
                                    $filter_url = 'view-batch-details.php?' . http_build_query($base_params);
                                    $active_class = ($filter_status === $value) ? 'bg-gray-100 text-gray-900 font-medium' : 'text-gray-700 hover:bg-gray-50';
                                ?>
                                    <a href="<?php echo htmlspecialchars($filter_url); ?>" class="block px-4 py-2 text-sm <?php echo $active_class; ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                        <?php if ($filter_status === $value): ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="inline ml-1"><polyline points="20 6 9 17 4 12"/></svg>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($is_admin): ?>
                            <button type="button" id="openBulkEditBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Bulk Edit
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Items Table -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                        <th class="px-6 py-3 font-medium bulk-edit-checkbox-col hidden">
                                            <input type="checkbox" id="selectAllBulk" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        </th>
                                        <th class="px-6 py-3 font-medium">Item No</th>
                                        <th class="px-6 py-3 font-medium">Asset No</th>
                                        <th class="px-6 py-3 font-medium">Assigned To</th>
                                        <th class="px-6 py-3 font-medium">Location</th>
                                        <th class="px-6 py-3 font-medium">Status</th>
                                        <th class="px-6 py-3 font-medium">Remarks</th>
                                        <?php if ($show_actions_column && $filter_status !== 'retired'): ?>
                                            <th class="px-6 py-3 font-medium">Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-16 text-gray-500">
                                                <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                                    <i data-lucide="search-slash" class="w-7 h-7 text-gray-400"></i>
                                                </div>
                                                <h3 class="font-semibold text-gray-800">No items found</h3>
                                                <p class="text-sm mt-1"><?php echo !empty($search_query) ? 'Your search for "' . htmlspecialchars($search_query) . '" did not return any results.' : 'There are no items in this record.'; ?></p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($items as $item): ?>
                                            <?php
                                            $status_value = trim($item['status'] ?: 'N/A');
                                            $is_staff_report = ($item['status_marked_role'] ?? '') === 'staff' && in_array($status_value, ['Not Working', 'Missing'], true);
                                            ?>
                                            <tr class="text-gray-600">
                                                <td class="px-6 py-4 bulk-edit-checkbox-col hidden">
                                                    <input type="checkbox" class="bulk-edit-checkbox h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500" data-item-id="<?php echo htmlspecialchars($item['id']); ?>" data-assigned-to="<?php echo htmlspecialchars($item['assigned_to']); ?>" data-location="<?php echo htmlspecialchars($item['location']); ?>" data-status="<?php echo htmlspecialchars($item['status']); ?>" data-remarks="<?php echo htmlspecialchars($item['remarks']); ?>">
                                                </td>
                                                <td class="px-6 py-4 font-mono text-xs"><?php echo htmlspecialchars($item['item_no']); ?></td>
                                                <td class="px-6 py-4 font-mono text-xs"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($item['assigned_to'] ?: 'N/A'); ?></td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                                                <td class="px-6 py-4">
                                                    <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo strtolower($status_value) === 'active' ? 'bg-emerald-100 text-emerald-800' : (strtolower($status_value) === 'under maintenance' ? 'bg-amber-100 text-amber-800' : (strtolower($status_value) === 'not working' || strtolower($status_value) === 'missing' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')); ?>">
                                                        <?php echo htmlspecialchars($status_value); ?>
                                                    </span>
                                                    <?php if ($is_staff_report): ?>
                                                        <div class="mt-1">
                                                            <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                                                                Staff reported
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 text-xs"><?php echo htmlspecialchars($item['remarks'] ?: 'None'); ?></td>
                                                <?php if ($show_actions_column && $filter_status !== 'retired'): ?>
                                                    <td class="px-6 py-4 text-sm whitespace-nowrap">
                                                        <?php if ($is_admin): ?>
                                                            <button type="button"
                                                                class="edit-item-btn text-blue-600 hover:text-blue-800 font-medium mr-2"
                                                                data-id="<?php echo htmlspecialchars($item['id']); ?>"
                                                                data-assigned-to="<?php echo htmlspecialchars($item['assigned_to']); ?>"
                                                                data-location="<?php echo htmlspecialchars($item['location']); ?>"
                                                                data-status="<?php echo htmlspecialchars($item['status']); ?>"
                                                                data-remarks="<?php echo htmlspecialchars($item['remarks']); ?>">
                                                                Edit
                                                            </button>
                                                            <button type="button"
                                                                class="retire-item-btn text-red-600 hover:text-red-800 font-medium"
                                                                data-id="<?php echo htmlspecialchars($item['id']); ?>"
                                                                data-asset-no="<?php echo htmlspecialchars($item['asset_no']); ?>">
                                                                Retire Asset
                                                            </button>
                                                        <?php else: ?>
                                                            <div class="flex flex-wrap gap-2">
                                                                <form method="POST" action="report-item-status.php" class="inline staff-report-form">
                                                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                                                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                                                                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                                                                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                                                                    <input type="hidden" name="status" value="Not Working">
                                                                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100 transition">
                                                                        Not Working
                                                                    </button>
                                                                </form>
                                                                <form method="POST" action="report-item-status.php" class="inline staff-report-form">
                                                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                                                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                                                                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                                                                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                                                                    <input type="hidden" name="status" value="Missing">
                                                                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100 transition">
                                                                        Missing
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Bulk Edit Floating Action Bar -->
                    <div id="bulkEditActionBar" class="fixed bottom-6 left-1/2 -translate-x-1/2 bg-white border border-gray-200 shadow-lg rounded-xl px-6 py-3 flex items-center gap-4 z-50 hidden">
                        <span id="bulkEditCount" class="text-sm font-medium text-gray-700">0 items selected</span>
                        <div class="flex gap-3">
                            <button type="button" id="bulkEditEditBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Edit
                            </button>
                            <button type="button" id="bulkEditCancelBtn" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                Cancel
                            </button>
                        </div>
                    </div>
                </main>
                <?php include 'footer.php'; ?>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
        <!-- Retire Asset Confirmation Modal -->
        <div id="retireItemModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Retire this asset?</h3>
                        <p class="mt-2 text-sm text-gray-600">This asset will be removed from the website view. It will not be deleted from the database, and the retire time will be saved.</p>
                        <p id="retireItemAssetNo" class="mt-2 text-xs font-mono text-gray-500 break-all"></p>
                    </div>
                </div>
                <form method="POST" action="view-batch-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>&batch_id=<?php echo urlencode($batch_id); ?>" class="mt-6 flex justify-end space-x-3">
                    <input type="hidden" name="action" value="retire_item">
                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                    <input type="hidden" name="item_id" id="retireItemId">
                    <button type="button" id="cancelRetireItemBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Confirm</button>
                </form>
            </div>
        </div>

        <!-- Retire Confirmation Modal -->
        <div id="retireModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Retire this record?</h3>
                        <p class="mt-2 text-sm text-gray-600">This record will be removed from the website view. It will not be deleted from the database, and the retire time will be saved.</p>
                    </div>
                </div>
                <form method="POST" action="view-batch-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>&batch_id=<?php echo urlencode($batch_id); ?>" class="mt-6 flex justify-end space-x-3">
                    <input type="hidden" name="action" value="retire_batch">
                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                    <button type="button" id="cancelRetireBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Confirm</button>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Delete this record?</h3>
                        <p class="mt-2 text-sm text-gray-600">Are you sure you want to delete this record? This action cannot be undone.</p>
                    </div>
                </div>
                <form method="POST" action="view-batch-details.php?category_id=<?php echo $category_id; ?>&asset_name=<?php echo urlencode($asset_name_raw); ?>&batch_id=<?php echo urlencode($batch_id); ?>" class="mt-6 flex justify-end space-x-3">
                    <input type="hidden" name="action" value="delete_batch">
                    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars($asset_name_raw); ?>">
                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                    <button type="button" id="cancelDeleteBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">Confirm</button>
                </form>
            </div>
        </div>

        <!-- Edit Batch Modal -->
        <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 p-5 border w-full max-w-xl shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center border-b pb-3 mb-5">
                    <h3 class="text-xl font-semibold text-gray-900">Edit Record Details</h3>
                    <button id="closeEditModalBtn" class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form id="editForm">
                    <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch_id); ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                            <select name="location" id="location" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                                <!-- Options will be populated by JavaScript -->
                            </select>
                        </div>
                        <div>
                            <label for="cost" class="block text-sm font-medium text-gray-700 mb-1">Cost per Item</label>
                            <input type="number" step="0.01" name="cost" id="cost" value="<?php echo htmlspecialchars($batch_details['cost']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                        <div>
                            <label for="page_no" class="block text-sm font-medium text-gray-700 mb-1">Page No.</label>
                            <input type="text" name="page_no" id="page_no" value="<?php echo htmlspecialchars($batch_details['page_no']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                        <div>
                            <label for="gem_order_no" class="block text-sm font-medium text-gray-700 mb-1">GeM Order No.</label>
                            <input type="text" name="gem_order_no" id="gem_order_no" value="<?php echo htmlspecialchars($batch_details['gem_order_no']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                        <div>
                            <label for="gem_invoice_no" class="block text-sm font-medium text-gray-700 mb-1">GeM Invoice No.</label>
                            <input type="text" name="gem_invoice_no" id="gem_invoice_no" value="<?php echo htmlspecialchars($batch_details['gem_invoice_no']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                        <div>
                            <label for="gpr_no" class="block text-sm font-medium text-gray-700 mb-1">GPR No.</label>
                            <input type="text" name="gpr_no" id="gpr_no" value="<?php echo htmlspecialchars($batch_details['gpr_no']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                        <div>
                            <label for="pr_page_no" class="block text-sm font-medium text-gray-700 mb-1">GPR Page No.</label>
                            <input type="text" name="pr_page_no" id="pr_page_no" value="<?php echo htmlspecialchars($batch_details['pr_page_no']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                        <div>
                            <label for="gpr_item_no" class="block text-sm font-medium text-gray-700 mb-1">GPR Item No.</label>
                            <input type="text" name="gpr_item_no" id="gpr_item_no" value="<?php echo htmlspecialchars($batch_details['gpr_item_no']); ?>" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" id="cancelEditBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Update</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Item Modal -->
        <div id="itemEditModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 p-5 border w-full max-w-xl shadow-lg rounded-md bg-white">
                <div class="flex justify-between items-center border-b pb-3 mb-5">
                    <h3 class="text-xl font-semibold text-gray-900">Edit Item Details</h3>
                    <button id="closeItemEditModalBtn" class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form id="itemEditForm">
                    <input type="hidden" name="id" id="item_id">
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label for="item_assigned_to" class="block text-sm font-medium text-gray-700 mb-1">Assigned To</label>
                            <select name="assigned_to" id="item_assigned_to" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                                <option value="">Loading faculty...</option>
                            </select>
                        </div>
                        <div>
                            <label for="item_location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                            <select name="location" id="item_location" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                                <!-- Options will be populated by JavaScript -->
                            </select>
                        </div>
                        <div>
                            <label for="item_status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="item_status" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                                <option value="Active">Active</option>
                                <option value="Under Maintenance">Under Maintenance</option>
                                <option value="Not Working">Not Working</option>
                            </select>
                        </div>
                        <div>
                            <label for="item_remarks" class="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                            <textarea name="remarks" id="item_remarks" rows="3" class="w-full px-3 py-2 text-sm rounded-md bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50"></textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" id="cancelItemEditBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Update</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_staff): ?>
        <!-- Staff Status Confirmation Modal -->
        <div id="staffStatusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Confirm status update</h3>
                        <p id="staffStatusModalText" class="mt-2 text-sm text-gray-600">
                            Are you sure you want to continue?
                        </p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" id="cancelStaffStatusBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                    <button type="button" id="confirmStaffStatusBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">OK</button>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <script>
        lucide.createIcons();

        // --- Sidebar Toggle Logic ---
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const menuBtn = document.getElementById('menuBtn');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

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

        const filterBtn = document.getElementById('filterBtn');
        const filterDropdown = document.getElementById('filterDropdown');

        if (filterBtn && filterDropdown) {
            filterBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                filterDropdown.classList.toggle('hidden');
            });

            document.addEventListener('click', function(e) {
                if (!filterDropdown.contains(e.target) && e.target !== filterBtn) {
                    filterDropdown.classList.add('hidden');
                }
            });
        }

        <?php if ($is_admin): ?>
            // Batch Edit Modal handling
            const editModal = document.getElementById('editModal');
            const openEditModalBtn = document.getElementById('openEditModalBtn');
            const closeEditModalBtn = document.getElementById('closeEditModalBtn');
            const cancelEditBtn = document.getElementById('cancelEditBtn');
            const editForm = document.getElementById('editForm');
            const retireModal = document.getElementById('retireModal');
            const openRetireModalBtn = document.getElementById('openRetireModalBtn');
            const cancelRetireBtn = document.getElementById('cancelRetireBtn');
            const retireItemModal = document.getElementById('retireItemModal');
            const retireItemId = document.getElementById('retireItemId');
            const retireItemAssetNo = document.getElementById('retireItemAssetNo');
            const cancelRetireItemBtn = document.getElementById('cancelRetireItemBtn');

            document.querySelectorAll('.retire-item-btn').forEach(button => {
                button.addEventListener('click', function() {
                    retireItemId.value = this.dataset.id;
                    retireItemAssetNo.textContent = this.dataset.assetNo ? `Asset No: ${this.dataset.assetNo}` : '';
                    retireItemModal.classList.remove('hidden');
                    lucide.createIcons();
                });
            });

            if (cancelRetireItemBtn) {
                cancelRetireItemBtn.addEventListener('click', () => {
                    retireItemModal.classList.add('hidden');
                });
            }

            if (openRetireModalBtn) {
                openRetireModalBtn.addEventListener('click', () => {
                    retireModal.classList.remove('hidden');
                    lucide.createIcons();
                });
            }

            if (cancelRetireBtn) {
                cancelRetireBtn.addEventListener('click', () => {
                    retireModal.classList.add('hidden');
                });
            }

            const deleteModal = document.getElementById('deleteModal');
            const openDeleteModalBtn = document.getElementById('openDeleteModalBtn');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');

            if (openDeleteModalBtn) {
                openDeleteModalBtn.addEventListener('click', () => {
                    deleteModal.classList.remove('hidden');
                    lucide.createIcons();
                });
            }

            if (cancelDeleteBtn) {
                cancelDeleteBtn.addEventListener('click', () => {
                    deleteModal.classList.add('hidden');
                });
            }

            if (openEditModalBtn) {
                openEditModalBtn.addEventListener('click', () => {
                    const currentLocation = <?php echo json_encode($batch_details['location']); ?>;
                    populateLocationOptions('location', currentLocation);
                    editModal.classList.remove('hidden');
                    lucide.createIcons(); // Re-render icons if any in modal
                });
            }

            if (closeEditModalBtn) {
                closeEditModalBtn.addEventListener('click', () => {
                    editModal.classList.add('hidden');
                });
            }

            if (cancelEditBtn) {
                cancelEditBtn.addEventListener('click', () => {
                    editModal.classList.add('hidden');
                });
            }

            window.addEventListener('click', (event) => {
                if (event.target === editModal) {
                    editModal.classList.add('hidden');
                }
                if (event.target === retireModal) {
                    retireModal.classList.add('hidden');
                }
                if (event.target === retireItemModal) {
                    retireItemModal.classList.add('hidden');
                }
                if (event.target === deleteModal) {
                    deleteModal.classList.add('hidden');
                }
            });

            // --- Bulk Edit Logic ---
            let bulkEditMode = false;
            let selectedItems = new Set();

            function exitBulkEditMode() {
                bulkEditMode = false;
                selectedItems.clear();
                document.querySelectorAll('.bulk-edit-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                const selectAll = document.getElementById('selectAllBulk');
                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
                document.getElementById('bulkEditActionBar').classList.add('hidden');
                document.querySelectorAll('.bulk-edit-checkbox-col').forEach(el => {
                    el.classList.add('hidden');
                });
            }

            if (openBulkEditBtn) {
                openBulkEditBtn.addEventListener('click', () => {
                    bulkEditMode = !bulkEditMode;
                    if (!bulkEditMode) {
                        exitBulkEditMode();
                    } else {
                        document.querySelectorAll('.bulk-edit-checkbox-col').forEach(el => {
                            el.classList.remove('hidden');
                        });
                    }
                });
            }

            if (document.getElementById('selectAllBulk')) {
                document.getElementById('selectAllBulk').addEventListener('change', function() {
                    const checked = this.checked;
                    document.querySelectorAll('.bulk-edit-checkbox').forEach(cb => {
                        cb.checked = checked;
                        const itemId = cb.dataset.itemId;
                        if (checked) {
                            selectedItems.add(itemId);
                        } else {
                            selectedItems.delete(itemId);
                        }
                    });
                    updateBulkEditActionBar();
                });
            }

            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('bulk-edit-checkbox')) {
                    const itemId = e.target.dataset.itemId;
                    if (e.target.checked) {
                        selectedItems.add(itemId);
                    } else {
                        selectedItems.delete(itemId);
                    }
                    updateBulkEditActionBar();
                    const allCheckboxes = document.querySelectorAll('.bulk-edit-checkbox');
                    const selectAll = document.getElementById('selectAllBulk');
                    if (selectAll) {
                        selectAll.checked = selectedItems.size === allCheckboxes.length;
                        selectAll.indeterminate = selectedItems.size > 0 && selectedItems.size < allCheckboxes.length;
                    }
                }
            });

            function updateBulkEditActionBar() {
                const actionBar = document.getElementById('bulkEditActionBar');
                const count = document.getElementById('bulkEditCount');
                if (selectedItems.size > 0) {
                    actionBar.classList.remove('hidden');
                    count.textContent = selectedItems.size + ' item' + (selectedItems.size > 1 ? 's' : '') + ' selected';
                } else {
                    actionBar.classList.add('hidden');
                }
            }

            if (document.getElementById('bulkEditCancelBtn')) {
                document.getElementById('bulkEditCancelBtn').addEventListener('click', () => {
                    exitBulkEditMode();
                });
            }

            if (document.getElementById('bulkEditEditBtn')) {
                document.getElementById('bulkEditEditBtn').addEventListener('click', () => {
                    if (selectedItems.size === 0) return;
                    const firstItem = document.querySelector('.bulk-edit-checkbox:checked');
                    if (firstItem) {
                        const assignedToSelect = document.getElementById('item_assigned_to');
                        const locationSelect = document.getElementById('item_location');
                        const statusSelect = document.getElementById('item_status');
                        const remarksTextarea = document.getElementById('item_remarks');

                        if (typeof facultyData !== 'undefined' && facultyData.length > 0) {
                            populateFacultyOptions(assignedToSelect, firstItem.dataset.assignedTo || '');
                        } else {
                            assignedToSelect.value = firstItem.dataset.assignedTo || '';
                        }
                        populateLocationOptions('item_location', firstItem.dataset.location || '');
                        statusSelect.value = firstItem.dataset.status || 'Active';
                        remarksTextarea.value = firstItem.dataset.remarks || '';

                        itemEditModal.classList.remove('hidden');
                        lucide.createIcons();
                    }
                });
            }

            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);

                    fetch('update-batch.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.status === 'success') {
                                alert('Record updated successfully!');
                                window.location.reload();
                            } else {
                                alert('Error updating record: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An unexpected error occurred. Please check the console for details.');
                        });
                });
            }

            // Item Edit Modal handling
            const itemEditModal = document.getElementById('itemEditModal');
            const closeItemEditModalBtn = document.getElementById('closeItemEditModalBtn');
            const cancelItemEditBtn = document.getElementById('cancelItemEditBtn');
            const itemEditForm = document.getElementById('itemEditForm');

            document.querySelectorAll('.edit-item-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const assignedTo = this.dataset.assignedTo;
                    const location = this.dataset.location;
                    const status = this.dataset.status;
                    const remarks = this.dataset.remarks;

                    document.getElementById('item_id').value = id;

                    const assignedToSelect = document.getElementById('item_assigned_to');
                    if (typeof facultyData !== 'undefined' && facultyData.length > 0) {
                        populateFacultyOptions(assignedToSelect, assignedTo);
                    } else {
                        assignedToSelect.value = assignedTo || '';
                    }

                    populateLocationOptions('item_location', location);
                    document.getElementById('item_status').value = status;
                    document.getElementById('item_remarks').value = remarks;

                    itemEditModal.classList.remove('hidden');
                    lucide.createIcons();
                });
            });

            if (closeItemEditModalBtn) {
                closeItemEditModalBtn.addEventListener('click', () => {
                    itemEditModal.classList.add('hidden');
                });
            }

            if (cancelItemEditBtn) {
                cancelItemEditBtn.addEventListener('click', () => {
                    itemEditModal.classList.add('hidden');
                });
            }

            window.addEventListener('click', (event) => {
                if (event.target === itemEditModal) {
                    itemEditModal.classList.add('hidden');
                }
            });

            if (itemEditForm) {
                itemEditForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);

                    if (bulkEditMode && selectedItems.size > 0) {
                        selectedItems.forEach(id => {
                            formData.append('item_ids[]', id);
                        });
                        formData.append('bulk_edit', '1');

                        fetch('update-bulk-items.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.status === 'success') {
                                    alert('Items updated successfully!');
                                    exitBulkEditMode();
                                    window.location.reload();
                                } else {
                                    alert('Error updating items: ' + data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An unexpected error occurred. Please check the console for details.');
                            });
                    } else {
                        fetch('update-item.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.status === 'success') {
                                    alert('Item updated successfully!');
                                    window.location.reload();
                                } else {
                                    alert('Error updating item: ' + data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An unexpected error occurred. Please check the console for details.');
                            });
                    }
                });
            }

            // --- Faculty Dropdown Logic for Item Edit Modal ---
            let facultyData = [];

            function populateFacultyOptions(selectElement, currentValue) {
                if (!selectElement) return;

                let optionsHTML = '<option value="">Select faculty</option>';

                facultyData.forEach(name => {
                    optionsHTML += `<option value="${name}">${name}</option>`;
                });

                selectElement.innerHTML = optionsHTML;

                if (currentValue && facultyData.includes(currentValue)) {
                    selectElement.value = currentValue;
                } else {
                    selectElement.value = "";
                }
            }

            fetch('get-faculty.php')
                .then(response => response.json())
                .then(allNames => {
                    facultyData = allNames;
                })
                .catch(() => {
                    facultyData = [];
                });

            // --- Location Dropdown Logic for Item Edit Modal ---
            const locationStorageKey = 'kd_polytechnic_saved_locations';

            function getSavedLocations() {
                try {
                    return JSON.parse(localStorage.getItem(locationStorageKey)) || [];
                } catch (error) {
                    return [];
                }
            }

            function populateLocationOptions(elementId, currentValue) {
                const locationSelect = document.getElementById(elementId);
                if (!locationSelect) return;
                const savedLocations = getSavedLocations();

                let optionsHTML = `
            <option value="">Select location</option>
            
            <optgroup label="Ground Floor">
                <option value="F001 - STAFF ROOM">F001 - STAFF ROOM</option>
                <option value="F002 - HOD OFFICE">F002 - HOD OFFICE</option>
                <option value="F003 - CLASS ROOM - 1">F003 - CLASS ROOM - 1</option>
                <option value="F004 - CLASS ROOM - 2">F004 - CLASS ROOM - 2</option>
                <option value="F005 - TRAINING AND PLACEMENT ROOM">F005 - TRAINING AND PLACEMENT ROOM</option>
                <option value="F006 - SERVER ROOM">F006 - SERVER ROOM</option>
                <option value="F007 - BASIC PROGRAMMING LAB">F007 - BASIC PROGRAMMING LAB</option>
                <option value="F008 - ELECTRIC ROOM">F008 - ELECTRIC ROOM</option>
                <option value="F009 - DRINKING WATER, TOILET">F009 - DRINKING WATER, TOILET</option>
                <option value="F010 - ADVANCE PROGRAMMING LAB">F010 - ADVANCE PROGRAMMING LAB</option>
                <option value="F011 - DATABASE PROGRAMMING LAB">F011 - DATABASE PROGRAMMING LAB</option>
                <option value="F012 - WEB DEVELOPMENT LAB">F012 - WEB DEVELOPMENT LAB</option>
            </optgroup>

            <optgroup label="First Floor">
                <option value="F101 - DEPARTMENT LIBRARY">F101 - DEPARTMENT LIBRARY</option>
                <option value="F102 - COMPUTER NETWORK LAB">F102 - COMPUTER NETWORK LAB</option>
                <option value="F103 - COMPUTER MAINTENANCE LAB">F103 - COMPUTER MAINTENANCE LAB</option>
                <option value="F104 - BASIC ELECTRONICS LAB">F104 - BASIC ELECTRONICS LAB</option>
                <option value="F105 - ELECTRIC ROOM">F105 - ELECTRIC ROOM</option>
                <option value="F106 - DRINKING WATER, TOILET">F106 - DRINKING WATER, TOILET</option>
                <option value="F107 - SEMINAR HALL">F107 - SEMINAR HALL</option>
                <option value="F108 - ADVANCE WEB DEVELOPMENT LAB">F108 - ADVANCE WEB DEVELOPMENT LAB</option>
                <option value="F109 - CONFERENCE ROOM">F109 - CONFERENCE ROOM</option>
                <option value="F110 - STAFF ROOM">F110 - STAFF ROOM</option>
                <option value="F111 - CLASS ROOM - 3">F111 - CLASS ROOM - 3</option>
                <option value="F112 - CLASS ROOM - 4">F112 - CLASS ROOM - 4</option>
            </optgroup>
        `;

                if (savedLocations.length > 0) {
                    optionsHTML += `<optgroup label="Custom Locations">`;
                    savedLocations.forEach(location => {
                        optionsHTML += `<option value="${location}">${location}</option>`;
                    });
                    optionsHTML += `</optgroup>`;
                }

                locationSelect.innerHTML = optionsHTML;

                // Set the selected value
                if (currentValue && Array.from(locationSelect.options).some(option => option.value === currentValue)) {
                    locationSelect.value = currentValue;
                } else {
                    locationSelect.value = ""; // Default to "Select location" if not found
                }
            }
        <?php endif; ?>

        <?php if ($is_staff): ?>
            const staffStatusModal = document.getElementById('staffStatusModal');
            const staffStatusModalText = document.getElementById('staffStatusModalText');
            const cancelStaffStatusBtn = document.getElementById('cancelStaffStatusBtn');
            const confirmStaffStatusBtn = document.getElementById('confirmStaffStatusBtn');
            let pendingStaffForm = null;

            function hideStaffStatusModal() {
                if (staffStatusModal) {
                    staffStatusModal.classList.add('hidden');
                }
                pendingStaffForm = null;
            }

            document.querySelectorAll('.staff-report-form').forEach(form => {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    pendingStaffForm = this;

                    const statusInput = this.querySelector('input[name="status"]');
                    const statusValue = statusInput ? statusInput.value : 'this asset';

                    if (staffStatusModalText) {
                        staffStatusModalText.textContent = `Are you sure you want to mark this asset as "${statusValue}"? Click OK to continue.`;
                    }

                    if (staffStatusModal) {
                        staffStatusModal.classList.remove('hidden');
                        lucide.createIcons();
                    }
                });
            });

            if (cancelStaffStatusBtn) {
                cancelStaffStatusBtn.addEventListener('click', hideStaffStatusModal);
            }

            if (confirmStaffStatusBtn) {
                confirmStaffStatusBtn.addEventListener('click', () => {
                    if (pendingStaffForm) {
                        pendingStaffForm.submit();
                    }
                    hideStaffStatusModal();
                });
            }

            window.addEventListener('click', (event) => {
                if (event.target === staffStatusModal) {
                    hideStaffStatusModal();
                }
            });
        <?php endif; ?>
    </script>

    <script src="loader/loader.js"></script>

</body>

</html>