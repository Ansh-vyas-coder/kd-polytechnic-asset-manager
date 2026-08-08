<?php
// This file is included in dashboard.php, so $conn and session are available.
// The $locations variable is also fetched in dashboard.php.

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Access Denied.');
}
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
</div>