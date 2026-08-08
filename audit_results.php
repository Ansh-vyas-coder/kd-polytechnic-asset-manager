<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

// Get and validate the audit ID from the URL
$audit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($audit_id <= 0) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Invalid audit ID."));
    exit();
}

// --- Fetch main audit details ---
$audit_stmt = $conn->prepare(
    "SELECT a.id, a.location_id, a.audit_date, a.status, u.full_name as audited_by
     FROM audits a
     JOIN users u ON a.audited_by_user_id = u.id
     WHERE a.id = ?"
);
if (!$audit_stmt) {
    die("Database error preparing to fetch audit details.");
}
$audit_stmt->bind_param("i", $audit_id);
$audit_stmt->execute();
$audit_result = $audit_stmt->get_result();
$audit_details = $audit_result->fetch_assoc();
$audit_stmt->close();

if (!$audit_details) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Audit report not found."));
    exit();
}

// --- Fetch all audit items ---
$items_stmt = $conn->prepare(
    "SELECT
        ai.verification_status,
        ai.condition,
        ai.note,
        ai.expected_location_id,
        ai.scanned_location_id,
        a.asset_name,
        a.asset_no
    FROM audit_items ai
    JOIN assets a ON ai.asset_id = a.id
    WHERE ai.audit_id = ?
    ORDER BY ai.verification_status, a.asset_name"
);
if (!$items_stmt) {
    die("Database error preparing to fetch audit items.");
}
$items_stmt->bind_param("i", $audit_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$all_items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// --- Process items into categories ---
$present_items = [];
$missing_items = [];
$misplaced_items = [];

foreach ($all_items as $item) {
    switch ($item['verification_status']) {
        case 'Present':
            $present_items[] = $item;
            break;
        case 'Missing':
            $missing_items[] = $item;
            break;
        case 'Misplaced':
            $misplaced_items[] = $item;
            break;
    }
}

// Helper function for user menu
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

$current_page = 'audit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit Report #<?php echo $audit_id; ?> - KDP Asset Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="loader/loader.css" />
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        };
    </script>
    <style>
        html, body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">
    <?php include 'loader/loader.html'; ?>
    <div class="h-screen flex overflow-hidden">
        <?php include 'sidebar.php'; ?>
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

        <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
            <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 shrink-0">
                <div class="flex items-center gap-2">
                    <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                        <i data-lucide="menu" style="width:20px;height:20px"></i>
                    </button>
                    <h1 class="text-lg font-semibold">Audit Report</h1>
                </div>
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="relative">
                        <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
                        </button>
                    </div>
                </div>
            </header>

            <div class="flex-1 overflow-y-auto flex flex-col">
                <main class="flex-1 bg-gray-50 p-4 lg:p-6">
                    <div class="max-w-5xl mx-auto">
                        <!-- Report Header -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div>
                                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Audit Report #<?php echo $audit_details['id']; ?></h1>
                                    <p class="text-sm text-gray-500 mt-1">
                                        For Location: <strong class="font-semibold text-gray-700"><?php echo htmlspecialchars($audit_details['location_id']); ?></strong>
                                    </p>
                                </div>
                                <div class="text-sm text-gray-500 text-left sm:text-right">
                                    <p>Completed on: <strong class="font-semibold text-gray-700"><?php echo date('M d, Y h:i A', strtotime($audit_details['audit_date'])); ?></strong></p>
                                    <p>Audited by: <strong class="font-semibold text-gray-700"><?php echo htmlspecialchars($audit_details['audited_by']); ?></strong></p>
                                </div>
                            </div>
                        </div>

                        <!-- Stat Cards -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                            <div class="bg-green-50 border border-green-200 rounded-xl p-5">
                                <p class="text-sm font-medium text-green-700">Present Items</p>
                                <p class="text-3xl font-bold text-green-800 mt-2"><?php echo count($present_items); ?></p>
                            </div>
                            <div class="bg-red-50 border border-red-200 rounded-xl p-5">
                                <p class="text-sm font-medium text-red-700">Missing Items</p>
                                <p class="text-3xl font-bold text-red-800 mt-2"><?php echo count($missing_items); ?></p>
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                                <p class="text-sm font-medium text-amber-700">Misplaced Items</p>
                                <p class="text-3xl font-bold text-amber-800 mt-2"><?php echo count($misplaced_items); ?></p>
                            </div>
                        </div>

                        <!-- Present Items Table -->
                        <?php if (!empty($present_items)): ?>
                        <div class="mb-8">
                            <h2 class="text-xl font-bold text-gray-800 mb-4">Present Items</h2>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                            <th class="px-6 py-3 font-medium">Asset Name</th>
                                            <th class="px-6 py-3 font-medium">Asset No.</th>
                                            <th class="px-6 py-3 font-medium">Condition</th>
                                            <th class="px-6 py-3 font-medium">Note</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($present_items as $item): ?>
                                        <tr>
                                            <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                                            <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                            <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800"><?php echo htmlspecialchars($item['condition'] ?: 'N/A'); ?></span></td>
                                            <td class="px-6 py-4 text-gray-600 text-xs"><?php echo htmlspecialchars($item['note'] ?: '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Missing Items Table -->
                        <?php if (!empty($missing_items)): ?>
                        <div class="mb-8">
                            <h2 class="text-xl font-bold text-gray-800 mb-4">Missing Items</h2>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                            <th class="px-6 py-3 font-medium">Asset Name</th>
                                            <th class="px-6 py-3 font-medium">Asset No.</th>
                                            <th class="px-6 py-3 font-medium">Expected Location</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($missing_items as $item): ?>
                                        <tr>
                                            <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                                            <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                            <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($item['expected_location_id']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Misplaced Items Table -->
                        <?php if (!empty($misplaced_items)): ?>
                        <div class="mb-8">
                            <h2 class="text-xl font-bold text-gray-800 mb-4">Misplaced Items</h2>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                            <th class="px-6 py-3 font-medium">Asset Name</th>
                                            <th class="px-6 py-3 font-medium">Asset No.</th>
                                            <th class="px-6 py-3 font-medium">Found At</th>
                                            <th class="px-6 py-3 font-medium">Expected At</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($misplaced_items as $item): ?>
                                        <tr>
                                            <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                                            <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                            <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800"><?php echo htmlspecialchars($item['scanned_location_id']); ?></span></td>
                                            <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($item['expected_location_id']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </main>
                <?php include 'footer.php'; ?>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();
    </script>
    <script src="loader/loader.js"></script>
</body>
</html>