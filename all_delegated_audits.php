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
$current_user_id = (int)$_SESSION['user_id'];

if ($_SESSION['role'] === 'admin') {
    $page_title = 'All Delegated Audits';
    $audits_stmt = $conn->prepare("
        SELECT a.id, a.location_id, a.audit_date, a.status, u.full_name as assigned_to_name, assigner.full_name as assigned_by_name
        FROM audits a
        JOIN users u ON a.audited_by_user_id = u.id
        LEFT JOIN users assigner ON a.assigned_by_user_id = assigner.id
        WHERE a.assigned_by_user_id IS NOT NULL OR a.audited_by_user_id = ?
        ORDER BY FIELD(a.status, 'Assigned', 'In Progress', 'Completed'), a.audit_date DESC
    ");
    $audits_stmt->bind_param("i", $current_user_id);
    $audits_stmt->execute();
    $audits_result = $audits_stmt->get_result();
    if ($audits_result) {
        $all_audits = $audits_result->fetch_all(MYSQLI_ASSOC);
    }
    $audits_stmt->close();
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
                    <div class="max-w-7xl mx-auto">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900 tracking-tight"><?php echo htmlspecialchars($page_title); ?></h1>
                                <p class="text-sm text-gray-500 mt-1">Showing all relevant audits, from most recent to oldest.</p>
                            </div>
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
                            <!-- Desktop Table View -->
                            <div class="hidden md:block overflow-x-auto">
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
                                                        <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($audit['assigned_by_name'] ?? 'Self'); ?></td>
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
                                                                <form action="remove_assigned_audit.php" method="POST" class="inline" onsubmit="return confirm('Remove this assigned audit? It will also disappear from the assigned staff member’s audit page.');">
                                                                    <input type="hidden" name="audit_id" value="<?php echo (int)$audit['id']; ?>">
                                                                    <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-md bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-200">
                                                                        <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                                                                        Remove Assignment
                                                                    </button>
                                                                </form>
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

                            <!-- Mobile Cards View -->
                            <div class="block md:hidden divide-y divide-gray-100">
                                <?php if (empty($all_audits)): ?>
                                    <div class="p-6 text-center text-gray-500">No audits found.</div>
                                <?php else: ?>
                                    <?php foreach ($all_audits as $audit): ?>
                                        <div class="p-4 space-y-3">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-bold text-gray-900 text-base"><?php echo htmlspecialchars($audit['location_id']); ?></span>
                                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                                    <?php
                                                        $status_color = 'gray';
                                                        if ($audit['status'] === 'Assigned') $status_color = 'yellow';
                                                        if ($audit['status'] === 'In Progress') $status_color = 'blue';
                                                        if ($audit['status'] === 'Completed') $status_color = 'green';
                                                    ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                                        <?php echo htmlspecialchars($audit['status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="grid grid-cols-2 gap-2 text-xs">
                                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                                    <div>
                                                        <span class="text-gray-400 block font-medium">Assigned By:</span>
                                                        <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($audit['assigned_by_name'] ?? 'Self'); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <span class="text-gray-400 block font-medium">Assigned To:</span>
                                                    <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($audit['assigned_to_name']); ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-400 block font-medium">Date Assigned:</span>
                                                    <span class="font-semibold text-gray-700"><?php echo date('M d, Y', strtotime($audit['audit_date'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="pt-2 border-t border-gray-50 flex items-center justify-end">
                                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                                    <?php if ($audit['status'] === 'In Progress'): ?>
                                                        <a href="audit_session.php?id=<?php echo $audit['id']; ?>" class="w-full inline-flex items-center justify-center gap-2 rounded-md bg-blue-100 px-3 py-2 text-xs font-semibold text-blue-700 shadow-sm transition hover:bg-blue-200">View Progress</a>
                                                    <?php elseif ($audit['status'] === 'Completed'): ?>
                                                        <a href="audit_results.php?id=<?php echo $audit['id']; ?>" class="w-full inline-flex items-center justify-center gap-2 rounded-md bg-green-100 px-3 py-2 text-xs font-semibold text-green-700 shadow-sm transition hover:bg-green-200">View Report</a>
                                                    <?php else: ?>
                                                        <form action="remove_assigned_audit.php" method="POST" class="w-full" onsubmit="return confirm('Remove this assigned audit? It will also disappear from the assigned staff member’s audit page.');">
                                                            <input type="hidden" name="audit_id" value="<?php echo (int)$audit['id']; ?>">
                                                            <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 rounded-md bg-red-100 px-3 py-2 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-200">
                                                                <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                                                                Remove Assignment
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php elseif ($_SESSION['user_name'] === $audit['assigned_to_name'] && ($audit['status'] ?? 'Assigned') === 'Assigned'): ?>
                                                    <form action="start_audit.php" method="POST" class="w-full">
                                                        <input type="hidden" name="audit_id" value="<?php echo $audit['id']; ?>">
                                                        <input type="hidden" name="location_id" value="<?php echo htmlspecialchars($audit['location_id']); ?>">
                                                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-md bg-green-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-green-700"><i data-lucide="play" style="width:14px;height:14px"></i> Start Audit</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
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
  <?php include 'page_scripts.php'; ?>
</body>
</html>
