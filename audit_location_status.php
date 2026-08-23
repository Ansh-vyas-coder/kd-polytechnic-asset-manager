<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: login.html"); // User not logged in or not a valid role
    exit();
}

$selected_location_id = isset($_GET['location_id']) ? trim($_GET['location_id']) : '';
$recent_completed_audit = null;
$total_audits_count = 0;
$audit_items = [];

if (empty($selected_location_id)) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("No location selected to view status."));
    exit();
}

// Fetch the most recent completed audit for the selected location
$user_id = $_SESSION['user_id'];

if ($_SESSION['role'] === 'admin') {
    $audit_stmt = $conn->prepare("
        SELECT a.id, a.audit_date, u.full_name as audited_by
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.location_id = ? AND a.status = 'Completed'
        ORDER BY a.audit_date DESC
        LIMIT 1
    ");
    $audit_stmt->bind_param("s", $selected_location_id);
} else { // Staff can only see the status if they were the last one to audit it.
    $audit_stmt = $conn->prepare("
        SELECT a.id, a.audit_date, u.full_name as audited_by
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.location_id = ? AND a.status = 'Completed' AND a.audited_by_user_id = ?
        ORDER BY a.audit_date DESC
        LIMIT 1
    ");
    $audit_stmt->bind_param("si", $selected_location_id, $user_id);
}
if ($audit_stmt) {
    $audit_stmt->execute();
    $audit_result = $audit_stmt->get_result();
    $recent_completed_audit = $audit_result->fetch_assoc();
    $audit_stmt->close();
}

// Also fetch the total count of completed audits for this location
if ($_SESSION['role'] === 'admin') {
    $count_stmt = $conn->prepare("SELECT COUNT(id) as total FROM audits WHERE location_id = ? AND status = 'Completed'");
    $count_stmt->bind_param("s", $selected_location_id);
} else {
    $count_stmt = $conn->prepare("SELECT COUNT(id) as total FROM audits WHERE location_id = ? AND status = 'Completed' AND audited_by_user_id = ?");
    $count_stmt->bind_param("si", $selected_location_id, $user_id);
}
if ($count_stmt) {
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_audits_count = (int)($count_result->fetch_assoc()['total'] ?? 0);
    $count_stmt->close();
}

if ($recent_completed_audit) {
    // Fetch audit items for this completed audit
    $items_stmt = $conn->prepare("
        SELECT
            ai.verification_status,
            ai.condition,
            ai.note,
            ai.expected_location_id,
            ai.scanned_location_id,
            a.asset_name,
            a.asset_no,
            a.category_id
        FROM audit_items ai
        JOIN assets a ON ai.asset_id = a.id
        WHERE ai.audit_id = ?
        ORDER BY ai.verification_status, a.category_id, a.asset_name ASC, a.asset_no ASC
    ");
    $items_stmt->bind_param("i", $recent_completed_audit['id']);
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
}

// Helper function to generate initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

$current_page = 'audit'; // for sidebar active state
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit Status: <?php echo htmlspecialchars($selected_location_id); ?> - KDP Asset Manager</title>
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
        html, body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">
    <?php include 'loader/loader.html'; ?>
    <div class="h-screen flex overflow-hidden">
        <?php include 'sidebar.php'; ?>
        <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

        <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
        <?php include 'topbar.php'; ?>

            <div class="flex-1 overflow-y-auto flex flex-col">
                <main class="flex-1 bg-gray-50 p-4 lg:p-6">
                    <div class="max-w-7xl mx-auto space-y-6">
                        <div class="flex items-center justify-between mb-6">
                            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Audit Status for <?php echo htmlspecialchars($selected_location_id); ?></h1>
                            <a href="dashboard.php?view=audit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-300">
                                <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
                                Back to Audit Dashboard
                            </a>
                        </div>

                        <?php if ($recent_completed_audit): ?>
                            <!-- The Snapshot Card -->
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                                <div>
                                    <h2 class="text-lg font-bold text-gray-900">Most Recent Audit for <?php echo htmlspecialchars($selected_location_id); ?></h2>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Completed on <strong class="text-gray-700"><?php echo date('M d, Y \a\t h:i A', strtotime($recent_completed_audit['audit_date'])); ?></strong>
                                        by <strong class="text-gray-700"><?php echo htmlspecialchars($recent_completed_audit['audited_by']); ?></strong>.
                                    </p>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-3 shrink-0 w-full sm:w-auto">
                                    <a href="export_audit_pdf.php?id=<?php echo $recent_completed_audit['id']; ?>" target="_blank" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                                        <i data-lucide="file-type-2" style="width:16px;height:16px"></i> PDF
                                    </a>
                                    <a href="export_audit_report.php?id=<?php echo $recent_completed_audit['id']; ?>" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                                        <i data-lucide="file-spreadsheet" style="width:16px;height:16px"></i> Excel
                                    </a>
                                    <?php if ($total_audits_count > 1): ?>
                                        <a href="location_audits.php?location_id=<?php echo urlencode($selected_location_id); ?>" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-lg bg-gray-700 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-gray-500/30 transition hover:bg-gray-800">
                                            <i data-lucide="history" style="width:18px;height:18px"></i>
                                            View All <?php echo $total_audits_count; ?> Audits
                                        </a>
                                    <?php endif; ?>
                                    <form action="start_audit.php" method="POST" class="w-full sm:w-auto">
                                        <input type="hidden" name="location_id" value="<?php echo htmlspecialchars($selected_location_id); ?>">
                                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:bg-blue-700">
                                            <i data-lucide="play-circle" style="width:18px;height:18px"></i>
                                            Start New Audit
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <?php if (empty($all_items)): ?>
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 text-center text-gray-500">
                                    <h2 class="text-lg font-bold text-gray-900 mb-2">No Items Recorded</h2>
                                    <p>No items were recorded for this audit session.</p>
                                </div>
                            <?php else: ?>
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
                                                                    <th class="px-6 py-3 font-medium">Condition</th>
                                                                    <th class="px-6 py-3 font-medium">Note</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-100">
                                                                <?php foreach ($items as $item): ?>
                                                                <tr>
                                                                    <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
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
                                                                    <th class="px-6 py-3 font-medium">Expected Location</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-100">
                                                                <?php foreach ($items as $item): ?>
                                                                <tr>
                                                                    <td class="px-6 py-4 font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no']); ?></td>
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
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 text-center text-gray-500">
                                <h2 class="text-lg font-bold text-gray-900 mb-2">No Completed Audits Found</h2>
                                <p>There are no completed audit reports for <strong class="text-gray-700"><?php echo htmlspecialchars($selected_location_id); ?></strong> yet.</p>
                                <form action="start_audit.php" method="POST" class="mt-4">
                                    <input type="hidden" name="location_id" value="<?php echo htmlspecialchars($selected_location_id); ?>">
                                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:bg-blue-700">
                                        <i data-lucide="play-circle" style="width:18px;height:18px"></i>
                                        Start New Audit for This Location
                                    </button>
                                </form>
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
  <?php include 'page_scripts.php'; ?>
</body>
</html>


