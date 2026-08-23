<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: login.html"); // User not logged in or not a valid role
    exit();
}

$location_id = isset($_GET['location_id']) ? trim($_GET['location_id']) : '';

if (empty($location_id)) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("No location specified."));
    exit();
}

// Fetch all completed audits for the specified location
$all_audits = [];
$user_id = $_SESSION['user_id'];

if ($_SESSION['role'] === 'admin') {
    $stmt = $conn->prepare("
        SELECT a.id, a.audit_date, u.full_name as audited_by
        FROM audits a JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.location_id = ? AND a.status = 'Completed'
        ORDER BY a.audit_date DESC
    ");
    $stmt->bind_param("s", $location_id);
} else { // Staff can only see their own audits
    $stmt = $conn->prepare("
        SELECT a.id, a.audit_date, u.full_name as audited_by
        FROM audits a JOIN users u ON a.audited_by_user_id = u.id
        WHERE a.location_id = ? AND a.status = 'Completed' AND a.audited_by_user_id = ?
        ORDER BY a.audit_date DESC
    ");
    $stmt->bind_param("si", $location_id, $user_id);
}
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $all_audits = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
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
    <title>Audit History for <?php echo htmlspecialchars($location_id); ?> - KDP Asset Manager</title>
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
                    <div class="max-w-5xl mx-auto">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Audit History for <?php echo htmlspecialchars($location_id); ?></h1>
                                <p class="text-sm text-gray-500 mt-1">Showing all completed audits for this location, from most recent to oldest.</p>
                            </div>
                            <a href="dashboard.php?view=audit&location_id=<?php echo urlencode($location_id); ?>" class="inline-flex items-center justify-center gap-2 rounded-lg bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-300">
                                <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
                                Back to Location View
                            </a>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                                        <th class="px-6 py-3 font-medium">Audit ID</th>
                                        <th class="px-6 py-3 font-medium">Date Completed</th>
                                        <th class="px-6 py-3 font-medium">Audited By</th>
                                        <th class="px-6 py-3 font-medium text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if (empty($all_audits)): ?>
                                        <tr><td colspan="4" class="p-6 text-center text-gray-500">No completed audits found for this location.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($all_audits as $audit): ?>
                                            <tr>
                                                <td class="px-6 py-4 font-mono text-xs text-gray-600">#<?php echo $audit['id']; ?></td>
                                                <td class="px-6 py-4 font-semibold text-gray-800"><?php echo date('M d, Y \a\t h:i A', strtotime($audit['audit_date'])); ?></td>
                                                <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($audit['audited_by']); ?></td>
                                                <td class="px-6 py-4 text-right">
                                                    <a href="audit_results.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                                                        <i data-lucide="file-text" style="width:14px;height:14px"></i>
                                                        View Report
                                                    </a>
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
  <?php include 'page_scripts.php'; ?>
</body>
</html>


