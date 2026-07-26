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

// Get status from URL
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Validate status_filter
$allowed_statuses = ['Not Working', 'Under Maintenance'];
if (!in_array($status_filter, $allowed_statuses)) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Invalid status specified."));
    exit();
}

// Define categories to get the name from the ID
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

$sql_where_clauses = ["status = ?", "retire_at IS NULL"];
$sql_params = ["s", $status_filter];

// Staff can only view rows assigned to themselves
if ($_SESSION['role'] === 'staff') {
    $sql_where_clauses[] = "assigned_to = ?";
    $sql_params[0] .= "s";
    $sql_params[] = $_SESSION['user_name'];
}

// Fetch assets with the specified status
$items = [];
$sql = "SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, remarks, updated_at FROM assets WHERE " . implode(" AND ", $sql_where_clauses) . " ORDER BY updated_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param(...$sql_params);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $items = $result->fetch_all(MYSQLI_ASSOC);
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
    <title><?php echo htmlspecialchars($status_filter); ?> Items - KDP Asset Manager</title>
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
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="dashboard.php?view=add-asset" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                        <i data-lucide="plus-square" style="width:18px;height:18px"></i> Add Item(s)
                    </a>
                    <a href="dashboard.php?view=register" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                        <i data-lucide="book-open" style="width:18px;height:18px"></i> Virtual Register
                    </a>
                    <a href="dashboard.php?view=generate-report" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                        <i data-lucide="file-spreadsheet" style="width:18px;height:18px"></i> Generate Report
                    </a>
                    <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                        <i data-lucide="users" style="width:18px;height:18px"></i> Manage Users
                    </a>
                <?php endif; ?>
                <?php if ($_SESSION['role'] === 'staff'): ?>
                    <a href="dashboard.php?view=my-assets" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                        <i data-lucide="file-spreadsheet" style="width:18px;height:18px"></i> My Assigned Assets
                    </a>
                <?php endif; ?>
            </nav>
        </aside>

        <div id="overlay" class="fixed inset-0 bg-gray-900/30 z-30 hidden"></div>

        <div class="flex-1 flex flex-col min-w-0 h-screen overflow-hidden">
            <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-end px-4 lg:px-6 gap-4 shrink-0">
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

            <div class="flex-1 overflow-y-auto flex flex-col">
                <main class="flex-1 bg-gray-50 p-4 lg:p-6">
                    <!-- Breadcrumb Navigation -->
                    <div class="mb-6">
                        <nav class="text-sm font-medium text-gray-500 mb-3">
                            <a href="dashboard.php" class="hover:text-blue-600 transition-colors">Dashboard</a>
                            <span class="mx-2 text-gray-400">&gt;</span>
                            <span class="text-gray-900 capitalize"><?php echo htmlspecialchars($status_filter); ?> Items</span>
                        </nav>
                        <h1 class="text-2xl font-bold text-gray-900 tracking-tight capitalize"><?php echo htmlspecialchars($status_filter); ?> Items</h1>
                    </div>

                    <!-- Items Table -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                        <th class="px-6 py-3 font-medium">Asset ID</th>
                                        <th class="px-6 py-3 font-medium">Equipment Name</th>
                                        <th class="px-6 py-3 font-medium">Category</th>
                                        <th class="px-6 py-3 font-medium">Location</th>
                                        <th class="px-6 py-3 font-medium">Assigned To</th>
                                        <th class="px-6 py-3 font-medium">Status</th>
                                        <th class="px-6 py-3 font-medium text-right">Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if (empty($items)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-16 text-gray-500">
                                                <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                                    <i data-lucide="check-circle" class="w-7 h-7 text-gray-400"></i>
                                                </div>
                                                <h3 class="font-semibold text-gray-800">No items found</h3>
                                                <p class="text-sm mt-1">All assets are currently operational or under a different status.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($items as $item): ?>
                                            <tr class="clickable-row transition-colors duration-150"
                                                data-href="view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>">
                                                <td class="px-6 py-4 whitespace-nowrap font-mono text-xs"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900 capitalize"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($categories[$item['category_id']] ?? 'Unknown'); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-gray-600 truncate"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($item['assigned_to'] ?: 'N/A'); ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full
                                                        <?php if ($item['status'] === 'Not Working'): ?> bg-red-100 text-red-700
                                                        <?php elseif ($item['status'] === 'Under Maintenance'): ?> bg-amber-100 text-amber-700
                                                        <?php else: ?> bg-gray-100 text-gray-700
                                                        <?php endif; ?>">
                                                        <?php echo htmlspecialchars($item['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-gray-500 text-right"><?php echo date('M d, Y', strtotime($item['updated_at'])); ?></td>
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
    <script src="loader/loader.js"></script>
</body>

</html>