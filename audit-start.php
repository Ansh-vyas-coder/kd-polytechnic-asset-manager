<?php
// This file is included in dashboard.php, so $conn and session are available.
// The variables $locations, $staff_users, $assigned_audits are also fetched in dashboard.php.

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    exit('Access Denied.'); // Only admins and staff can see the audit dashboard
}

// Initialize variables to prevent undefined warnings (these are populated by dashboard.php)
$locations = $locations ?? [];
$staff_users = $staff_users ?? [];
$assigned_audits = $assigned_audits ?? [];
?>

<div class="max-w-7xl mx-auto space-y-6">

    <!-- The Selector: Location Dropdown and View Status Button -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form> <!-- Default form action/method will be overridden by formaction/formmethod on buttons -->
            <input type="hidden" name="view" value="audit">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="w-full md:w-auto md:flex-1">
                    <label for="location_id_selector" class="block text-lg font-bold text-gray-900">Select Location</label>
                    <p class="text-sm text-gray-500 mt-1 mb-3">Choose a location to view its audit status or start a new audit.</p>
                    <select id="location_id_selector" name="location_id" required class="w-full md:max-w-md px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition">
                        <option value="">Select a location to audit</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo htmlspecialchars($location); ?>" <?php echo (isset($_REQUEST['location_id']) && $_REQUEST['location_id'] === $location) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-full md:w-auto flex flex-col sm:flex-row gap-3">
                    <button type="submit" formaction="audit_location_status.php" formmethod="GET" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-6 py-3 border border-transparent text-sm font-semibold rounded-lg text-white bg-blue-600 hover:bg-blue-700 transition-colors shadow-lg shadow-blue-500/30">
                        <i data-lucide="eye" style="width:18px;height:18px"></i>
                        View Status
                    </button>
                    <button type="submit" formaction="start_audit.php" formmethod="POST" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-6 py-3 border border-transparent text-sm font-semibold rounded-lg text-white bg-green-600 hover:bg-green-700 transition-colors shadow-lg shadow-green-500/30">
                        <i data-lucide="play-circle" style="width:18px;height:18px"></i>
                        Start New Audit
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($_SESSION['role'] === 'admin'): ?>
    <!-- NEW: Assign Audit Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-bold text-gray-900 mb-1">Assign an Audit</h2>
        <p class="text-sm text-gray-500 mb-4">Delegate an audit for a specific location to a staff member.</p>
        <form action="assign_audit.php" method="POST">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div class="md:col-span-1">
                    <label for="assign_location_id" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <select id="assign_location_id" name="location_id" required class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        <option value="">Select a location</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label for="assign_to_user_id" class="block text-sm font-medium text-gray-700 mb-1">Assign To</label>
                    <select id="assign_to_user_id" name="assign_to_user_id" required class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                        <option value="">Select a staff member</option>
                        <?php foreach ($staff_users as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-1">
                    <button type="submit" class="w-full inline-flex justify-center items-center gap-2 px-4 py-2 border border-transparent text-sm font-semibold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 transition-colors">
                        <i data-lucide="send" style="width:16px;height:16px"></i>
                        Assign Audit
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- NEW: Assigned Audits Section -->
    <?php if (!empty($assigned_audits)): ?>
    <?php
        $total_assigned = count($assigned_audits);
        $audits_to_show = ($total_assigned > 5) ? array_slice($assigned_audits, 0, 5) : $assigned_audits;
    ?>
    <div class="space-y-4">
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <h2 class="text-xl font-bold text-gray-800 tracking-tight">Delegated Audits</h2>
        <?php else: ?>
            <h2 class="text-xl font-bold text-gray-800 tracking-tight">Your Assigned Audits</h2>
        <?php endif; ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                        <th class="px-6 py-3 font-medium">Location</th>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <th class="px-6 py-3 font-medium">Assigned By</th>
                        <?php endif; ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <th class="px-6 py-3 font-medium">Status</th>
                        <?php endif; ?>
                        <th class="px-6 py-3 font-medium">Assigned To</th>
                        <th class="px-6 py-3 font-medium">Date Assigned</th>
                        <th class="px-6 py-3 font-medium text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($audits_to_show as $audit): ?>
                        <tr>
                            <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($audit['location_id']); ?></td>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <td class="px-6 py-4 text-gray-600"><?php echo htmlspecialchars($audit['assigned_by_name'] ?? 'System'); ?></td>
                            <?php endif; ?>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
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
                                    <?php if (isset($audit['status']) && $audit['status'] === 'In Progress'): ?>
                                        <a href="audit_session.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-md bg-blue-100 px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm transition hover:bg-blue-200">
                                            View Progress
                                        </a>
                                    <?php elseif (isset($audit['status']) && $audit['status'] === 'Completed'): ?>
                                        <a href="audit_results.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-md bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 shadow-sm transition hover:bg-green-200">
                                            View Report
                                        </a>
                                    <?php else: ?>
                                        <form action="remove_assigned_audit.php" method="POST" class="inline" onsubmit="return confirm('Remove this assigned audit? It will also disappear from the assigned staff member’s audit page.');">
                                            <input type="hidden" name="audit_id" value="<?php echo (int)$audit['id']; ?>">
                                            <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-md bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-200">
                                                <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                                                Remove Assignment
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif (isset($audit['status']) && $_SESSION['user_name'] === $audit['assigned_to_name'] && $audit['status'] === 'Assigned'): ?>
                                    <form action="start_audit.php" method="POST" class="inline">
                                        <input type="hidden" name="audit_id" value="<?php echo $audit['id']; ?>">
                                        <input type="hidden" name="location_id" value="<?php echo htmlspecialchars($audit['location_id']); ?>">
                                        <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-green-700">
                                            <i data-lucide="play" style="width:14px;height:14px"></i>
                                            Start Audit
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">No actions available</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($total_assigned > 5): ?>
                <div class="p-4 bg-gray-50 border-t border-gray-200 text-center">
                    <a href="all_delegated_audits.php" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                        View All <?php echo $total_assigned; ?> Audits
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
            <strong class="font-bold">Error:</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($_GET['message'] ?? ''); ?></span>
        </div>
    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
            <strong class="font-bold">Success:</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($_GET['message'] ?? ''); ?></span>
        </div>
    <?php endif; ?>
</div>
