<?php
// This file is included in dashboard.php, so $conn and session are available.
// The $locations variable is also fetched in dashboard.php.

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Access Denied.');
}
?>

<div class="max-w-4xl mx-auto">

    <?php if (!empty($ongoing_audits)): ?>
    <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-900 tracking-tight mb-4">Ongoing Audits</h2>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <ul class="divide-y divide-gray-100">
                <?php foreach ($ongoing_audits as $audit): ?>
                    <li class="p-4 sm:p-5">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <p class="font-semibold text-gray-800">Location: <?php echo htmlspecialchars($audit['location_id']); ?></p>
                                <p class="text-xs text-gray-500 mt-1">
                                    Started on <?php echo date('M d, Y \a\t h:i A', strtotime($audit['audit_date'])); ?> by <?php echo htmlspecialchars($audit['full_name']); ?>
                                </p>
                            </div>
                            <a href="audit_session.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-lg bg-yellow-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-yellow-600">
                                <i data-lucide="play" style="width:16px;height:16px"></i>
                                Resume Session
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($completed_audits)): ?>
    <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-900 tracking-tight mb-4">Recent Completed Audits</h2>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <ul class="divide-y divide-gray-100">
                <?php foreach ($completed_audits as $audit): ?>
                    <li class="p-4 sm:p-5">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <p class="font-semibold text-gray-800">Location: <?php echo htmlspecialchars($audit['location_id']); ?></p>
                                <p class="text-xs text-gray-500 mt-1">
                                    Completed on <?php echo date('M d, Y', strtotime($audit['audit_date'])); ?> by <?php echo htmlspecialchars($audit['full_name']); ?>
                                </p>
                            </div>
                            <a href="audit_results.php?id=<?php echo $audit['id']; ?>" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                                <i data-lucide="file-text" style="width:16px;height:16px"></i>
                                View Report
                            </a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Start New Audit</h1>
            <p class="text-sm text-gray-500 mt-1">Select a location to begin an asset verification session.</p>
        </div>
    </div>

    <?php if (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
            <strong class="font-bold">Error:</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($_GET['message']); ?></span>
        </div>
    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
            <strong class="font-bold">Success:</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($_GET['message']); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form action="start_audit.php" method="POST">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                <div class="md:col-span-2">
                    <label for="location_id" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                    <select id="location_id" name="location_id" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition">
                        <option value="">Select a location to audit</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="w-full inline-flex justify-center items-center gap-2 px-5 py-3 border border-transparent text-sm font-semibold rounded-lg text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        <i data-lucide="play-circle" style="width:18px;height:18px"></i>
                        Start Audit Session
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>