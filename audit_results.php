<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: login.html"); // User not logged in or not a valid role
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
    "SELECT a.id, a.location_id, a.audit_date, a.status, a.audited_by_user_id, u.full_name as audited_by
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

// Security check: Staff can only view their own audit results
if ($_SESSION['role'] === 'staff' && $audit_details['audited_by_user_id'] != $_SESSION['user_id']) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("You are not authorized to view this audit report."));
    exit();
}

// --- Fetch all audit items, including assets borrowed from other departments ---
$items_stmt = $conn->prepare(
    "SELECT * FROM (
        SELECT
            CASE
                WHEN ai.verification_status = 'Missing' AND ai.scanned_location_id IS NOT NULL THEN 'Present'
                ELSE ai.verification_status
            END AS verification_status,
            ai.condition,
            ai.note,
            ai.expected_location_id,
            ai.scanned_location_id,
            a.asset_name,
            a.category_id,
            a.asset_no,
            'Owned' AS asset_type
        FROM audit_items ai
        JOIN assets a ON ai.asset_id = a.id
        WHERE ai.audit_id = ?

        UNION ALL

        SELECT
            bai.verification_status,
            bai.condition,
            bai.note,
            bai.expected_location_id,
            bai.scanned_location_id,
            ba.asset_name,
            ba.category_id,
            ba.asset_no,
            'Borrowed' AS asset_type
        FROM borrowed_audit_items bai
        JOIN borrowed_assets ba ON bai.borrowed_asset_id = ba.id
        WHERE bai.audit_id = ?
    ) AS audit_report_items
    ORDER BY verification_status, asset_name"
);
if (!$items_stmt) {
    die("Database error preparing to fetch audit items.");
}
$items_stmt->bind_param("ii", $audit_id, $audit_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$all_items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// --- Process items into categories ---
$present_items_by_category_and_name = [];
$missing_items_by_category_and_name = [];
$misplaced_items_by_category_and_name = [];

// Define categories for display
$categories_map = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

foreach ($all_items as $item) {
    $category_id = $item['category_id'];
    $asset_name = $item['asset_name'];
    switch ($item['verification_status']) {
        case 'Present':
            if (!isset($present_items_by_category_and_name[$category_id])) $present_items_by_category_and_name[$category_id] = [];
            if (!isset($present_items_by_category_and_name[$category_id][$asset_name])) $present_items_by_category_and_name[$category_id][$asset_name] = [];
            $present_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
        case 'Missing':
            if (!isset($missing_items_by_category_and_name[$category_id])) $missing_items_by_category_and_name[$category_id] = [];
            if (!isset($missing_items_by_category_and_name[$category_id][$asset_name])) $missing_items_by_category_and_name[$category_id][$asset_name] = [];
            $missing_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
        case 'Misplaced':
            if (!isset($misplaced_items_by_category_and_name[$category_id])) $misplaced_items_by_category_and_name[$category_id] = [];
            if (!isset($misplaced_items_by_category_and_name[$category_id][$asset_name])) $misplaced_items_by_category_and_name[$category_id][$asset_name] = [];
            $misplaced_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
    }
}
$present_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $present_items_by_category_and_name));
$missing_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $missing_items_by_category_and_name));
$misplaced_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $misplaced_items_by_category_and_name));

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
  <link rel="stylesheet" href="notifications.css" />
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        };
    </script>
    <style>
        @media print {
            body {
                background-color: white;
            }
            .no-print {
                display: none !important;
            }
            main {
                padding: 0 !important;
            }
        }
        html, body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">
    <div class="no-print"><?php include 'loader/loader.html'; ?></div>
    <div class="h-screen flex overflow-hidden">
        <div class="no-print"><?php include 'sidebar.php'; ?></div>
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden no-print"></div>

        <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
        <?php include 'topbar.php'; ?>

            <div class="flex-1 overflow-y-auto flex flex-col">
                <main class="flex-1 bg-gray-50 p-4 lg:p-6">
                    <div class="max-w-5xl mx-auto">
                        <?php if (isset($_GET['status']) && $_GET['status'] === 'completed'): ?><div class="no-print">
                            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg mb-6" role="alert">
                                <p class="font-bold">Audit Completed Successfully!</p>
                                <p>The audit results have been saved to the database.</p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Report Header -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div>
                                    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Audit Report #<?php echo $audit_details['id']; ?></h1>
                                    <p class="text-sm text-gray-500 mt-1">
                                        For Location: <strong class="font-semibold text-gray-700"><?php echo htmlspecialchars($audit_details['location_id']); ?></strong>
                                    </p>
                                </div>
                                <div class="flex flex-col sm:items-end gap-3">
                                    <div class="text-sm text-gray-500 text-left sm:text-right">
                                        <p>Completed on: <strong class="font-semibold text-gray-700"><?php echo date('M d, Y h:i A', strtotime($audit_details['audit_date'])); ?></strong></p>
                                        <p>Audited by: <strong class="font-semibold text-gray-700"><?php echo htmlspecialchars($audit_details['audited_by']); ?></strong></p>
                                    </div>
                                    <div class="flex items-center gap-2 no-print">
                                        <a href="export_audit_pdf.php?id=<?php echo $audit_id; ?>" target="_blank" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">📄 Download PDF</a>
                                        <a href="export_audit_report.php?id=<?php echo $audit_id; ?>" class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">📥 Download Excel</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stat Cards -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                            <div class="bg-green-50 border border-green-200 rounded-xl p-5">
                                <p class="text-sm font-medium text-green-700">Present Items</p>
                                <p class="text-3xl font-bold text-green-800 mt-2"><?php echo $present_count; ?></p>
                            </div>
                            <div class="bg-red-50 border border-red-200 rounded-xl p-5">
                                <p class="text-sm font-medium text-red-700">Missing Items</p>
                                <p class="text-3xl font-bold text-red-800 mt-2"><?php echo $missing_count; ?></p>
                            </div>
                            <div class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                                <p class="text-sm font-medium text-amber-700">Misplaced Items</p>
                                <p class="text-3xl font-bold text-amber-800 mt-2"><?php echo $misplaced_count; ?></p>
                            </div>
                        </div>

                        <!-- Present Items -->
                        <?php if (!empty($present_items_by_category_and_name)): ?>
                        <div class="mb-8">
                            <h2 class="text-xl font-bold text-gray-800 mb-4">Present Items</h2>
                            <?php foreach ($present_items_by_category_and_name as $category_id => $asset_names_in_category): ?>
                                <div class="mb-6 ml-4">
                                    <h3 class="text-lg font-bold text-gray-700 mb-3 pl-3 border-l-4 border-green-500">
                                        <?php echo htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'); ?>
                                    </h3>
                                    <?php foreach ($asset_names_in_category as $asset_name => $items): ?>
                                        <div class="mb-4 ml-4">
                                            <h4 class="text-md font-semibold text-gray-700 mb-2 pl-2 border-l-2 border-green-300">
                                                <?php echo htmlspecialchars($asset_name); ?> <span class="text-sm font-normal text-gray-500">(<?php echo count($items); ?>)</span>
                                            </h4>
                                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                                <table class="w-full text-sm">
                                                    <thead class="bg-gray-50">
                                                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                                            <th class="px-6 py-3 font-medium">Asset No.</th>
                                                            <th class="px-6 py-3 font-medium">Type</th>
                                                            <th class="px-6 py-3 font-medium">Found At</th>
                                                            <th class="px-6 py-3 font-medium">Condition</th>
                                                            <th class="px-6 py-3 font-medium">Note</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100">
                                                        <?php foreach ($items as $item): ?>
                                                        <tr>
                                                            <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                                            <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $item['asset_type'] === 'Borrowed' ? 'bg-violet-100 text-violet-800' : 'bg-blue-100 text-blue-800'; ?>"><?php echo htmlspecialchars($item['asset_type']); ?></span></td>
                                                            <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($item['scanned_location_id'] ?: $item['expected_location_id']); ?></td>
                                                            <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800"><?php echo htmlspecialchars($item['condition'] ?: 'N/A'); ?></span></td>
                                                            <td class="px-6 py-4 text-gray-600 text-xs"><?php echo htmlspecialchars($item['note'] ?: '-'); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Missing Items -->
                        <?php if (!empty($missing_items_by_category_and_name)): ?>
                        <div class="mb-8">
                            <h2 class="text-xl font-bold text-gray-800 mb-4">Missing Items</h2>
                            <?php foreach ($missing_items_by_category_and_name as $category_id => $asset_names_in_category): ?>
                                <div class="mb-6 ml-4">
                                    <h3 class="text-lg font-bold text-gray-700 mb-3 pl-3 border-l-4 border-red-500">
                                        <?php echo htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'); ?>
                                    </h3>
                                    <?php foreach ($asset_names_in_category as $asset_name => $items): ?>
                                        <div class="mb-4 ml-4">
                                            <h4 class="text-md font-semibold text-gray-700 mb-2 pl-2 border-l-2 border-red-300">
                                                <?php echo htmlspecialchars($asset_name); ?> <span class="text-sm font-normal text-gray-500">(<?php echo count($items); ?>)</span>
                                            </h4>
                                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                                <table class="w-full text-sm">
                                                    <thead class="bg-gray-50">
                                                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                                            <th class="px-6 py-3 font-medium">Asset No.</th>
                                                            <th class="px-6 py-3 font-medium">Type</th>
                                                            <th class="px-6 py-3 font-medium">Expected At</th>
                                                            <th class="px-6 py-3 font-medium">Note</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100">
                                                        <?php foreach ($items as $item): ?>
                                                        <tr>
                                                            <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                                            <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?php echo $item['asset_type'] === 'Borrowed' ? 'bg-violet-100 text-violet-800' : 'bg-blue-100 text-blue-800'; ?>"><?php echo htmlspecialchars($item['asset_type']); ?></span></td>
                                                            <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($item['expected_location_id']); ?></td>
                                                            <td class="px-6 py-4 text-gray-600 text-xs"><?php echo htmlspecialchars($item['note'] ?: '-'); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Misplaced Items -->
                        <?php if (!empty($misplaced_items_by_category_and_name)): ?>
                        <div class="mb-8">
                            <h2 class="text-xl font-bold text-gray-800 mb-4">Misplaced Items</h2>
                            <?php foreach ($misplaced_items_by_category_and_name as $category_id => $asset_names_in_category): ?>
                                <div class="mb-6 ml-4">
                                    <h3 class="text-lg font-bold text-gray-700 mb-3 pl-3 border-l-4 border-amber-500">
                                        <?php echo htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'); ?>
                                    </h3>
                                    <?php foreach ($asset_names_in_category as $asset_name => $items): ?>
                                        <div class="mb-4 ml-4">
                                            <h4 class="text-md font-semibold text-gray-700 mb-2 pl-2 border-l-2 border-amber-300">
                                                <?php echo htmlspecialchars($asset_name); ?> <span class="text-sm font-normal text-gray-500">(<?php echo count($items); ?>)</span>
                                            </h4>
                                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                                <table class="w-full text-sm">
                                                    <thead class="bg-gray-50">
                                                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                                            <th class="px-6 py-3 font-medium">Asset No.</th>
                                                            <th class="px-6 py-3 font-medium">Found At</th>
                                                            <th class="px-6 py-3 font-medium">Expected At</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100">
                                                        <?php foreach ($items as $item): ?>
                                                        <tr>
                                                            <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
                                                            <td class="px-6 py-4"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800"><?php echo htmlspecialchars($item['scanned_location_id']); ?></span></td>
                                                            <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($item['expected_location_id']); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </main>
                <div class="no-print"><?php include 'footer.php'; ?></div>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();
        // Clean the URL to prevent the success message from reappearing on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href.split('?')[0] + '?id=<?php echo $audit_id; ?>');
        }
    </script>
    <script src="loader/loader.js"></script>
  <?php include 'page_scripts.php'; ?>
</body>
</html>


