<?php
session_start();
require 'db.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

// --- START: Retire asset handling (merged from retire_asset.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $retireId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($retireId <= 0) {
        header("Location: dashboard.php");
        exit();
    }

    // Get asset information before deleting
    $stmt = $conn->prepare("
        SELECT category_id, asset_name
        FROM assets
        WHERE id = ?
    ");

    $stmt->bind_param("i", $retireId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        header("Location: dashboard.php");
        exit();
    }

    $retiredAsset = $result->fetch_assoc();
    $stmt->close();

    // Delete asset
    $stmt = $conn->prepare("
        DELETE FROM assets
        WHERE id = ?
    ");

    $stmt->bind_param("i", $retireId);

    if ($stmt->execute()) {

        header(
            "Location: view-asset-details.php?category_id=" .
            $retiredAsset['category_id'] .
            "&asset_name=" .
            urlencode($retiredAsset['asset_name'])
        );

    } else {

        header(
            "Location: category-list.php?id=" .
            $retireId
        );
    }

    $stmt->close();
    exit();
}
// --- END: Retire asset handling ---

// Get asset ID from URL
$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no ID or invalid ID, redirect with error
if ($asset_id <= 0) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Invalid asset ID"));
    exit();
}

// Fetch asset from database using prepared statement
$stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
if (!$stmt) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Database error"));
    exit();
}

$stmt->bind_param("i", $asset_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if asset exists
if ($result->num_rows === 0) {
    $stmt->close();
    header("Location: dashboard.php?status=error&message=" . urlencode("Asset not found"));
    exit();
}

$asset = $result->fetch_assoc();
$stmt->close();

// Fetch items related to the current asset
$items = [];
$stmt_items = $conn->prepare("SELECT id, item_no, asset_no, assigned_to, remarks FROM assets WHERE asset_name = ? AND category_id = ? ORDER BY item_no ASC");
if ($stmt_items) {
    $stmt_items->bind_param("si", $asset['asset_name'], $asset['category_id']);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    if ($result_items) {
        $items = $result_items->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_items->close();
}

// Get category name from category_id
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];
$category_name = $categories[$asset['category_id']] ?? 'Unknown';

function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Details - <?php echo htmlspecialchars($asset['asset_name']); ?></title>
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
        <aside id="sidebar" class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 lg:static transition-transform duration-200 ease-out">
            <div class="h-16 flex items-center gap-3 px-4 border-b border-gray-200 shrink-0">
                <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center shrink-0 p-1">
                    <img src="kdp_logo.jpeg"
                        alt="KDP Logo" class="w-full h-full object-contain">
                </div>
                <span class="font-bold text-sm tracking-tight text-gray-900">Smart Asset Manager</span>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                    <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i>
                    Dashboard
                </a>
                <a href="dashboard.php?view=add-asset" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
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
                <div class="max-w-5xl mx-auto">
                    <nav class="flex flex-wrap items-center text-sm text-slate-500 mb-6 gap-2">
                        <a href="dashboard.php" class="hover:text-slate-800">Dashboard</a>
                        <span>&gt;</span>
                        <a href="view-assets.php?category_id=<?php echo (int)$asset['category_id']; ?>" class="hover:text-slate-800"><?php echo htmlspecialchars($category_name); ?></a>
                        <span>&gt;</span>
                        <a href="view-asset-details.php?category_id=<?php echo (int)$asset['category_id']; ?>&asset_name=<?php echo urlencode($asset['asset_name']); ?>" class="hover:text-slate-800"><?php echo htmlspecialchars($asset['asset_name']); ?></a>
                        <span>&gt;</span>
                        <span class="text-slate-800 font-medium break-all"><?php echo htmlspecialchars($asset['asset_no']); ?></span>
                    </nav>

                    <!-- Original Asset Card Structure Unchanged (except max width adapted for Dashboard layout) -->
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm flex flex-col lg:flex-row overflow-hidden">

                        <div class="w-full lg:w-2/3 p-5 sm:p-7 lg:p-8 min-w-0">
                            <div class="mb-6">
                                <div class="flex items-center gap-2 text-[#1e3271] font-medium text-sm mb-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
                                        <rect x="9" y="9" width="6" height="6"></rect>
                                        <line x1="9" y1="1" x2="9" y2="4"></line>
                                        <line x1="15" y1="1" x2="15" y2="4"></line>
                                        <line x1="9" y1="20" x2="9" y2="23"></line>
                                        <line x1="15" y1="20" x2="15" y2="23"></line>
                                        <line x1="20" y1="9" x2="23" y2="9"></line>
                                        <line x1="20" y1="14" x2="23" y2="14"></line>
                                        <line x1="1" y1="9" x2="4" y2="9"></line>
                                        <line x1="1" y1="14" x2="4" y2="14"></line>
                                    </svg>
                                    <?php echo htmlspecialchars($category_name); ?>
                                </div>
                                <h1 class="text-2xl sm:text-3xl font-bold text-[#0f172a] break-words"><?php echo htmlspecialchars($asset['asset_name']); ?></h1>
                            </div>

                            <hr class="border-slate-100 mb-8">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-y-8 sm:gap-x-4 mb-10">
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Asset Name</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['asset_name']); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Asset No.</p>
                                    <p class="font-semibold text-slate-900 break-all"><?php echo htmlspecialchars($asset['asset_no']); ?></p>
                                </div>

                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Category</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($category_name); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Quantity</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['quantity']); ?></p>
                                </div>

                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Page No.</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['page_no']); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Item No.</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['item_no']); ?></p>
                                </div>

                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Gem Order No.</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['gem_order_no']); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Gem Invoice No.</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['gem_invoice_no']); ?></p>
                                </div>

                                <div class="col-span-1 sm:col-span-2">
                                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 sm:gap-x-4">

                                        <div class="sm:col-span-2 break-words">
                                            <p class="text-sm text-slate-500 mb-1">GPR No.</p>
                                            <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['gpr_no']); ?></p>
                                        </div>

                                        <div class="break-words">
                                            <p class="text-sm text-slate-500 mb-1">GPR Page No.</p>
                                            <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['pr_page_no']); ?></p>
                                        </div>

                                        <div class="break-words">
                                            <p class="text-sm text-slate-500 mb-1">GPR Item No.</p>
                                            <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['gpr_item_no']); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Location</p>
                                    <p class="font-semibold text-slate-900 flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="text-slate-400 flex-shrink-0">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                        <?php echo htmlspecialchars($asset['location']); ?>
                                    </p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Date of Issue</p>
                                    <p class="font-semibold text-slate-900"><?php echo date('M d, Y', strtotime($asset['date_of_issue'])); ?></p>
                                </div>

                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Cost</p>
                                    <p class="font-semibold text-slate-900">₹<?php echo htmlspecialchars(number_format($asset['cost'], 2)); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Assign to Faculty</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($asset['assigned_to']); ?></p>
                                </div>
                            </div>

                            <div>
                                <p class="text-sm text-slate-500 mb-2">Remarks</p>
                                <div class="bg-slate-50 rounded-lg p-4 text-slate-700 text-sm border border-slate-100 break-words">
                                    <?php echo htmlspecialchars($asset['remarks']); ?>
                                </div>
                            </div>
                        </div>

                        <div class="w-full lg:w-1/3 bg-[#fcfdfd] p-5 sm:p-7 lg:p-8 border-t lg:border-t-0 lg:border-l border-slate-100 flex flex-col min-w-0">

                            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Asset Information</h3>

                            <div class="space-y-4 mb-auto">
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 text-sm">
                                    <span class="text-slate-500">Category:</span>
                                    <span class="text-slate-800 font-medium"><?php echo htmlspecialchars($category_name); ?></span>
                                </div>
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 text-sm">
                                    <span class="text-slate-500">Item No:</span>
                                    <span class="text-slate-800 font-medium"><?php echo htmlspecialchars($asset['item_no']); ?></span>
                                </div>
                            </div>

                            <div class="mt-8"></div>

                            <div class="space-y-3">
                                <button onclick="openEditModal(<?php echo (int)$asset['id']; ?>)"
                                    class="w-full bg-[#20347a] hover:bg-[#18275c] text-white font-medium py-2.5 px-4 rounded-lg flex items-center justify-center gap-2 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                    Edit Asset
                                </button>

                                <form id="retireForm" action="category_list.php" method="POST">

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?php echo (int)$asset['id']; ?>">

                                    <button
                                        type="button"
                                        onclick="retireAsset()"
                                        class="w-full bg-white border border-[#fecaca] text-[#dc2626] hover:bg-red-50 font-medium py-2.5 px-4 rounded-lg flex items-center justify-center gap-2 transition-colors">

                                        <svg xmlns="http://www.w3.org/2000/svg"
                                            width="16"
                                            height="16"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2">

                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path>
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>

                                        </svg>

                                        Retire Asset

                                    </button>

                                </form>
                            </div>
                        </div>

                    </div>

                    <h2 class="text-xl sm:text-2xl font-bold text-[#0f172a] mb-6 mt-8">Individual Items</h2>
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
                                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">No individual items found for this asset.</td>
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

    <div id="editAssetModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div id="editAssetModalContent" class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
            <!-- Content will be loaded here from edit_asset.php -->
        </div>
    </div>

    <!-- Retire Asset Confirmation Modal -->
    <div id="retireModal"
        class="fixed inset-0 bg-black/50 hidden items-center justify-center z-[999]">

        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">

            <div class="p-6">

                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="w-6 h-6 text-red-600"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor">

                            <path stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 9v2m0 4h.01M12 3L2 21h20L12 3z" />
                        </svg>
                    </div>

                    <h2 class="text-lg font-semibold text-gray-900">
                        Retire Asset
                    </h2>
                </div>

                <p class="text-gray-700">
                    Are you sure you want to retire this asset?
                </p>

                <p class="text-sm text-gray-500 mt-2">
                    This action can't be undone.
                </p>

                <div class="flex justify-end gap-3 mt-8">

                    <button
                        onclick="closeRetireModal()"
                        class="px-5 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 transition">

                        Cancel
                    </button>

                    <button
                        onclick="confirmRetire()"
                        class="px-5 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 transition">

                        Retire
                    </button>

                </div>

            </div>

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

        document.addEventListener('click', (event) => {
            if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                userMenuDropdown.classList.add('hidden');
            }
        });

        function openEditModal(assetId) {
            const modal = document.getElementById('editAssetModal');
            const content = document.getElementById('editAssetModalContent');

            content.innerHTML = '<div class="p-8 text-center">Loading...</div>';
            modal.classList.remove('hidden');

            fetch(`edit_asset.php?id=${assetId}&embed=1`)
                .then(response => response.text())
                .then(html => {
                    content.innerHTML = html;
                    const scriptElement = content.querySelector('script');
                    if (scriptElement) {
                        const newScript = document.createElement('script');
                        newScript.innerHTML = scriptElement.innerHTML;
                        document.body.appendChild(newScript);
                    }
                })
                .catch(error => {
                    content.innerHTML = `<div class="p-8 text-center text-red-500">Error loading content: ${error}</div>`;
                });
        }

        function closeEditModal() {
            const modal = document.getElementById('editAssetModal');
            modal.classList.add('hidden');
            document.getElementById('editAssetModalContent').innerHTML = '';
        }

        function retireAsset() {
            document.getElementById("retireModal").classList.remove("hidden");
            document.getElementById("retireModal").classList.add("flex");
        }

        function closeRetireModal() {
            document.getElementById("retireModal").classList.add("hidden");
            document.getElementById("retireModal").classList.remove("flex");
        }

        function confirmRetire() {
            document.getElementById("retireForm").submit();
        }
    </script>
</body>

</html>