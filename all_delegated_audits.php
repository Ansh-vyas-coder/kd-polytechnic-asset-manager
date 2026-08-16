<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: login.html");
    exit();
}

// Fetch audits based on role
$all_audits = [];
$page_title = '';

if ($_SESSION['role'] === 'admin') {
    $page_title = 'All Delegated Audits';
    $audits_result = $conn->query("
        SELECT a.id, a.location_id, a.audit_date, a.status, u.full_name as assigned_to_name, assigner.full_name as assigned_by_name
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        LEFT JOIN users assigner ON a.assigned_by_user_id = assigner.id
        WHERE a.assigned_by_user_id IS NOT NULL
        ORDER BY FIELD(a.status, 'Assigned', 'In Progress', 'Completed'), a.audit_date DESC
    ");
    if ($audits_result) {
        $all_audits = $audits_result->fetch_all(MYSQLI_ASSOC);
    }
} else { // staff
    $page_title = 'All Your Assigned Audits';
    $audits_stmt = $conn->prepare("
        SELECT a.id, a.location_id, a.audit_date, u.full_name as assigned_to_name, a.status
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.status = 'Assigned' AND a.audited_by_user_id = ?
        ORDER BY a.audit_date DESC
    ");
    $audits_stmt->bind_param("i", $_SESSION['user_id']);
    $audits_stmt->execute();
    $audits_result = $audits_stmt->get_result();
    if ($audits_result) {
        $all_audits = $audits_result->fetch_all(MYSQLI_ASSOC);
    }
    $audits_stmt->close();
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

$current_page = 'audit';
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
                    <h1 class="text-lg font-semibold"><?php echo htmlspecialchars($page_title); ?></h1>
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
                    <div class="max-w-7xl mx-auto">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900 tracking-tight"><?php echo htmlspecialchars($page_title); ?></h1>
                                <p class="text-sm text-gray-500 mt-1">Showing all relevant audits, from most recent to oldest.</p>
                            </div>
                            <a href="dashboard.php?view=audit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-300">
                                <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
                                Back to Audit Dashboard
                            </a>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                        <th class="px-6 py-3 font-medium">Location</th>
                                        <?php if ($_SESSION['role'] === 'admin'): ?>
                                            <th class="px-6 py-3 font-medium">Assigned By</th>
                                            <th class="px-6 py-3 font-medium">Status</th>
                                        <?php endif; ?>
                                        <th class="px-6 py-3 font-medium">Assigned To</th>
                                        <th class="px-6 py-3 font-medium">Date Assigned</th>
                                        <th class="px-6 py-3 font-medium text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if (empty($all_audits)): ?>
                                        <tr><td colspan="6" class="p-6 text-center text-gray-500">No audits found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($all_audits as $audit): ?>
                                            <tr>
                                                <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($audit['location_id']); ?></td>
                                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                                    <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($audit['assigned_by_name'] ?? 'System'); ?></td>
                                                    <td class="px-6 py-4">
                                                        <?php
                                                            $status_color = 'gray';
                                                            if ($audit['status'] === 'Assigned') $status_color = 'yellow';
                                                            if ($audit['status'] === 'In Progress') $status_color = 'blue';
                                                            if ($audit['status'] === 'Completed') $status_color = 'green';
                                                        ?>
                                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                                            <?php echo htmlspecialchars($audit['status']); ?>
                                                        </span>
                                                    </td>
                                                <?php endif; ?>
                                                <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($audit['assigned_to_name']); ?></td>
                                                <td class="px-6 py-4 text-gray-500"><?php echo date('M d, Y', strtotime($audit['audit_date'])); ?></td>
                                                <td class="px-6 py-4 text-right">
                                                    <?php if ($_SESSION['role'] === 'admin'): ?>
                                                        <?php if ($audit['status'] === 'In Progress'): ?>
                                                            <a href="audit_session.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-md bg-blue-100 px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm transition hover:bg-blue-200">View Progress</a>
                                                        <?php elseif ($audit['status'] === 'Completed'): ?>
                                                            <a href="audit_results.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-md bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 shadow-sm transition hover:bg-green-200">View Report</a>
                                                        <?php else: ?>
                                                            <span class="text-xs text-gray-400">Pending staff action</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($_SESSION['user_name'] === $audit['assigned_to_name'] && ($audit['status'] ?? 'Assigned') === 'Assigned'): ?>
                                                        <form action="start_audit.php" method="POST" class="inline">
                                                            <input type="hidden" name="audit_id" value="<?php echo $audit['id']; ?>">
                                                            <input type="hidden" name="location_id" value="<?php echo htmlspecialchars($audit['location_id']); ?>">
                                                            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-green-700"><i data-lucide="play" style="width:14px;height:14px"></i> Start Audit</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
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
    </script>
    <script src="loader/loader.js"></script>
</body>
</html>