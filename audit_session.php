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
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Invalid audit session."));
    exit();
}

// Fetch audit session details
$audit_stmt = $conn->prepare("SELECT location_id, status, audited_by_user_id FROM audits WHERE id = ?");
if (!$audit_stmt) {
    die("Database error preparing to fetch audit.");
}
$audit_stmt->bind_param("i", $audit_id);
$audit_stmt->execute();
$audit_result = $audit_stmt->get_result();
$audit_session = $audit_result->fetch_assoc();
$audit_stmt->close();

if (!$audit_session) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Audit session not found."));
    exit();
}

// Security check: Staff can only access their own audits
if ($_SESSION['role'] === 'staff' && $audit_session['audited_by_user_id'] != $_SESSION['user_id']) {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("You are not authorized to access this audit session."));
    exit();
}

// If the audit is already completed, redirect them
if ($audit_session['status'] === 'Completed') {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("This audit has already been completed."));
    exit();
}

$location_id = $audit_session['location_id'];

// Fetch owned assets and active borrowed assets separately, then show both in this room's audit.
$assets_stmt = $conn->prepare("SELECT id, asset_name, asset_no, category_id FROM assets WHERE location = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) ORDER BY asset_name ASC");
if (!$assets_stmt) {
    die("Database error preparing to fetch assets.");
}
$assets_stmt->bind_param("s", $location_id);
$assets_stmt->execute();
$assets_result = $assets_stmt->get_result();
$expected_assets = $assets_result->fetch_all(MYSQLI_ASSOC);
$assets_stmt->close();

foreach ($expected_assets as &$asset) {
    $asset['audit_key'] = 'asset_' . $asset['id'];
    $asset['asset_type'] = 'Owned';
}
unset($asset);

$borrowed_stmt = $conn->prepare("SELECT id, asset_name, asset_no, category_id, borrowed_from FROM borrowed_assets WHERE location = ? AND status = 'active' ORDER BY asset_name ASC");
if (!$borrowed_stmt) {
    die("Database error preparing to fetch borrowed assets.");
}
$borrowed_stmt->bind_param("s", $location_id);
$borrowed_stmt->execute();
$borrowed_assets = $borrowed_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$borrowed_stmt->close();

foreach ($borrowed_assets as &$asset) {
    $asset['audit_key'] = 'borrowed_' . $asset['id'];
    $asset['asset_type'] = 'Borrowed';
}
unset($asset);

$expected_assets = array_merge($expected_assets, $borrowed_assets);
usort($expected_assets, fn($left, $right) => strcasecmp($left['asset_name'], $right['asset_name']));

// Get unique asset names for the filter dropdown
$unique_asset_names = [];
if (!empty($expected_assets)) {
    $unique_asset_names = array_unique(array_column($expected_assets, 'asset_name'));
    sort($unique_asset_names);
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

$current_page = 'audit'; // for sidebar active state
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit Session: <?php echo htmlspecialchars($location_id); ?> - KDP Asset Manager</title>
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

            <!-- Progress Bar -->
            <div class="sticky top-16 bg-white/80 backdrop-blur-sm border-b border-gray-200 z-10 p-4">
                <div class="max-w-4xl mx-auto">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-semibold text-gray-800">Verification Progress</span>
                        <span id="progressCounter" class="text-sm font-bold text-blue-600">0 / <?php echo count($expected_assets); ?> Items Verified</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div id="progressBar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto flex flex-col">
                <main class="flex-1 bg-gray-50 p-4 lg:p-6">
                    <div class="max-w-4xl mx-auto">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Auditing: <?php echo htmlspecialchars($location_id); ?></h1>
                                <p class="text-sm text-gray-500 mt-1">Mark all assets that are physically present in this location.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-yellow-100 px-3 py-1 text-sm font-semibold text-yellow-800">
                                    <i data-lucide="loader" class="animate-spin" style="width:14px;height:14px"></i>
                                    In Progress
                                </span>
                                <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-sm font-semibold text-blue-800">
                                    <?php echo count($expected_assets); ?> Expected Assets
                                </span>
                            </div>
                        </div>

                        <form action="save_audit.php" method="POST">
                            <input type="hidden" name="audit_id" value="<?php echo $audit_id; ?>">

                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-6">
                                <h2 class="font-semibold text-gray-800 mb-3">Log Unexpected Item (Misplaced)</h2>
                                <div class="flex items-center gap-2">
                                    <div class="relative flex-grow">
                                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4"></i>
                                        <input type="text" id="misplacedAssetSearch" placeholder="Scan or type Asset No..." class="w-full pl-10 pr-4 py-2 text-sm rounded-lg bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                                    </div>
                                    <button type="button" id="addMisplacedBtn" class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">Add</button>
                                </div>
                                <div id="misplacedSearchFeedback" class="text-xs mt-2 h-4"></div>
                                <div id="misplacedItemsContainer" class="mt-4 space-y-2">
                                    <!-- Misplaced items will be added here -->
                                </div>
                            </div>

                            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                                <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                                    <div class="flex flex-col sm:flex-row items-center gap-4 w-full sm:w-auto">
                                        <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-600">Status:</span>
                                        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 gap-1" id="filter-toggles">
                                            <button type="button" data-filter="all" class="filter-btn text-xs font-semibold px-3 py-1.5 rounded-md bg-blue-600 text-white transition-all">All</button>
                                            <button type="button" data-filter="pending" class="filter-btn text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all">Pending</button>
                                            <button type="button" data-filter="verified" class="filter-btn text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all">Verified</button>
                                        </div>
                                    </div>
                                        <div class="flex items-center gap-2 w-full sm:w-auto">
                                            <span class="text-sm font-medium text-gray-600">Item:</span>
                                            <select id="assetNameFilter" class="w-full sm:w-auto text-sm bg-gray-50 border-gray-200 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                <option value="all">Show All Items</option>
                                                <?php foreach ($unique_asset_names as $name): ?>
                                                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button type="button" id="auditQrScanBtn"
                                            onclick="openAuditQrScanner()"
                                            title="Scan QR Code to verify asset"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100 transition">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                                            Scan QR
                                        </button>
                                        <button type="button" id="checkAllBtn" class="text-sm font-medium text-blue-600 hover:text-blue-800">Mark All as Present</button>
                                    </div>
                                </div>
                                <ul class="divide-y divide-gray-100">
                                    <li id="noFilterResults" class="p-6 text-center text-gray-500 hidden">No items match the current filters.</li>
                                    <?php if (empty($expected_assets)): ?>
                                        <li class="p-6 text-center text-gray-500">No owned or active borrowed assets are assigned to this location.</li>
                                    <?php else: ?>
                                        <?php foreach ($expected_assets as $asset): ?>
                                            <li class="p-4 sm:p-5" data-asset-name="<?php echo htmlspecialchars($asset['asset_name']); ?>" data-asset-no="<?php echo htmlspecialchars($asset['asset_no']); ?>">
                                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                                    <!-- Left side: Asset Info -->
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex items-center gap-2">
                                                            <p class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($asset['asset_name']); ?></p>
                                                            <?php if ($asset['asset_type'] === 'Borrowed'): ?>
                                                                <span class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800">Borrowed</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-xs text-gray-500 mt-1 font-mono"><?php echo htmlspecialchars($asset['asset_no'] ?: 'No Asset No.'); ?></p>
                                                    </div>

                                                    <!-- Right side: Controls -->
                                                    <div class="flex items-center gap-x-4 gap-y-3 flex-wrap sm:flex-nowrap justify-end">
                                                        <!-- Checkbox for 'Present' -->
                                                        <label for="asset_present_<?php echo $asset['id']; ?>" class="flex items-center gap-2 cursor-pointer flex-shrink-0">
                                                            <input type="checkbox" 
                                                                   id="asset_present_<?php echo $asset['audit_key']; ?>" 
                                                                   name="assets[<?php echo $asset['audit_key']; ?>][status]" 
                                                                   value="Present" 
                                                                   class="asset-checkbox h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                            <span class="text-sm font-medium text-gray-700">Present</span>
                                                        </label>

                                                        <!-- Condition Dropdown -->
                                                        <div class="w-full sm:w-36 flex-shrink-0">
                                                            <select name="assets[<?php echo $asset['audit_key']; ?>][condition]" class="w-full text-sm bg-gray-50 border-gray-200 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                                <option value="Good" selected>Good</option>
                                                                <option value="Needs Repair">Needs Repair</option>
                                                                <option value="Broken">Broken</option>
                                                                <option value="Scrap">Scrap</option>
                                                            </select>
                                                        </div>

                                                        <!-- Note Input -->
                                                        <div class="w-full sm:w-48 flex-shrink-0">
                                                            <input type="text" 
                                                                   name="assets[<?php echo $asset['audit_key']; ?>][note]" 
                                                                   placeholder="Add a note..." 
                                                                   class="w-full text-sm bg-gray-50 border-gray-200 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                        </div>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <div class="sticky bottom-0 mt-6 py-4 bg-gray-50/80 backdrop-blur-sm border-t border-gray-200">
                                <div class="max-w-4xl mx-auto flex justify-end">
                                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-500/30 transition hover:bg-blue-700">
                                        <i data-lucide="check-circle" style="width:18px;height:18px"></i>
                                        Complete Audit
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </main>
                <?php include 'footer.php'; ?>
            </div>
        </div>
    </div>

    <script>
        // --- Auto-Save and State Management ---
        const auditId = <?php echo $audit_id; ?>;
        const mainForm = document.querySelector('form[action="save_audit.php"]');
        let isSubmitting = false;

        // --- LocalStorage Keys ---
        const misplacedStorageKey = `audit_${auditId}_misplaced`;
        const getAssetStorageKey = (assetId) => `audit_${auditId}_asset_${assetId}`;

        // --- State Variables ---
        let misplacedAssets = []; // Array of asset objects
        let misplacedAssetIdSet = new Set(); // For quick duplicate checks

        // --- Save Functions ---
        function saveAssetState(assetId) {
            const presentCheckbox = document.getElementById(`asset_present_${assetId}`);
            const conditionSelect = document.querySelector(`select[name="assets[${assetId}][condition]"]`);
            const noteInput = document.querySelector(`input[name="assets[${assetId}][note]"]`);

            if (!presentCheckbox) return;

            const data = {
                present: presentCheckbox.checked,
                condition: conditionSelect ? conditionSelect.value : 'Good',
                note: noteInput ? noteInput.value : ''
            };

            localStorage.setItem(getAssetStorageKey(assetId), JSON.stringify(data));
            console.log(`Saved state for asset ${assetId}:`, data);
        }

        function saveMisplacedState() {
            localStorage.setItem(misplacedStorageKey, JSON.stringify(misplacedAssets));
        }

        // --- Load Function ---
        function loadStateFromStorage() {
            // Load expected assets state
            console.log('Loading state from storage for audit ID:', auditId);
            document.querySelectorAll('ul.divide-y > li').forEach(li => { // Iterate over list items, not just checkboxes
                const checkbox = li.querySelector('.asset-checkbox');
                const assetId = checkbox.id.replace('asset_present_', '');
                const key = getAssetStorageKey(assetId);
                const savedData = localStorage.getItem(key);

                if (savedData) {
                    try {
                        const data = JSON.parse(savedData);
                        const conditionSelect = document.querySelector(`select[name="assets[${assetId}][condition]"]`);
                        const noteInput = document.querySelector(`input[name="assets[${assetId}][note]"]`);

                        checkbox.checked = data.present || false;
                        if (conditionSelect) conditionSelect.value = data.condition || 'Good'; // Default to 'Good' if not saved
                        if (noteInput) noteInput.value = data.note || ''; // Default to empty string if not saved
                        console.log(`Restored asset ${assetId}: Present=${checkbox.checked}, Condition=${conditionSelect ? conditionSelect.value : 'N/A'}, Note=${noteInput ? noteInput.value : 'N/A'}`);

                    } catch (e) {
                        console.error(`Failed to parse localStorage data for asset ${assetId}:`, e);
                    }
                }
            });

            // Load misplaced assets state
            const savedMisplaced = localStorage.getItem(misplacedStorageKey);
            console.log('Checking localStorage for misplaced assets (Key: ' + misplacedStorageKey + '):', savedMisplaced);
            if (savedMisplaced) {
                try {
                    const savedAssets = JSON.parse(savedMisplaced);
                    if (Array.isArray(savedAssets)) {
                        savedAssets.forEach(asset => addMisplacedItem(asset));
                        console.log('Restored misplaced assets:', savedAssets);
                    }
                } catch (e) {
                    console.error('Failed to parse misplaced assets from localStorage:', e);
                }
            }
        }

        function clearAuditStorage() {
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(`audit_${auditId}_`)) {
                    keysToRemove.push(key);
                }
            }
            keysToRemove.forEach(key => localStorage.removeItem(key));
        }

        // --- Final Save on Page Unload ---
        // This captures navigation via link clicks, back/forward, or closing the tab.
        window.addEventListener('pagehide', (event) => {
            // If the form is being submitted, the 'submit' handler will clear storage.
            // We don't want to re-save the state in that case.
            if (!isSubmitting) {
                console.log('Page is unloading. Forcing final auto-save.');
                document.querySelectorAll('ul.divide-y > li .asset-checkbox').forEach(checkbox => {
                    const assetId = checkbox.id.replace('asset_present_', '');
                    saveAssetState(assetId);
                });
                saveMisplacedState();
            }
        });

        // --- Misplaced Item Logic (Modified for State Management) ---
        const searchInput = document.getElementById('misplacedAssetSearch');
        const addBtn = document.getElementById('addMisplacedBtn');
        const feedbackDiv = document.getElementById('misplacedSearchFeedback');
        const container = document.getElementById('misplacedItemsContainer');

        function addMisplacedItem(asset) {
            const isBorrowed = asset.source === 'borrowed';
            const compositeId = isBorrowed ? `borrowed_${asset.id}` : `asset_${asset.id}`;

            if (misplacedAssetIdSet.has(compositeId)) {
                feedbackDiv.textContent = 'This asset has already been added.';
                feedbackDiv.className = 'text-xs mt-2 h-4 text-amber-600';
                return false;
            }

            misplacedAssetIdSet.add(compositeId);
            misplacedAssets.push(asset);

            const itemHtml = `
                <div id="misplaced-item-${compositeId}" class="flex items-center justify-between gap-3 ${isBorrowed ? 'bg-purple-50 border-purple-200 text-purple-800' : 'bg-blue-50 border-blue-200 text-blue-800'} border rounded-lg p-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-sm truncate">${asset.asset_name} ${isBorrowed ? '<span class="inline-flex rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-semibold text-purple-800">Borrowed</span>' : ''}</p>
                        <p class="text-xs mt-1 font-mono">${asset.asset_no}</p>
                    </div>
                    <button type="button" class="remove-misplaced-btn p-1.5 rounded-full text-red-500 hover:bg-red-100" data-composite-id="${compositeId}">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = isBorrowed ? 'misplaced_borrowed_assets[]' : 'misplaced_assets[]';
            hiddenInput.value = asset.id;
            hiddenInput.id = `misplaced-input-${compositeId}`;
            mainForm.appendChild(hiddenInput);

            lucide.createIcons();
            searchInput.value = '';
            feedbackDiv.textContent = `Added: ${asset.asset_name}`;
            feedbackDiv.className = 'text-xs mt-2 h-4 text-green-600';
            saveMisplacedState();
            return true;
        }

        async function findAsset() {
            const assetNo = searchInput.value.trim();
            if (!assetNo) return { ok: false, error: 'Please scan or enter an asset number.' };

            feedbackDiv.textContent = 'Searching...';
            feedbackDiv.className = 'text-xs mt-2 h-4 text-gray-500';

            try {
                const response = await fetch(`find_asset_for_audit.php?asset_no=${encodeURIComponent(assetNo)}&audit_id=${auditId}`);
                const data = await response.json();

                if (response.ok && data.id) {
                    const wasAdded = addMisplacedItem(data);
                    if (wasAdded) {
                        return { ok: true, data };
                    }
                    return { ok: false, error: 'This asset has already been added.' };
                } else {
                    const errorMessage = data.error || 'An unknown error occurred.';
                    feedbackDiv.textContent = errorMessage;
                    feedbackDiv.className = 'text-xs mt-2 h-4 text-red-600';
                    return { ok: false, error: errorMessage };
                }
            } catch (error) {
                const errorMessage = 'Failed to connect to the server.';
                feedbackDiv.textContent = errorMessage;
                feedbackDiv.className = 'text-xs mt-2 h-4 text-red-600';
                return { ok: false, error: errorMessage };
            }
        }

        addBtn.addEventListener('click', findAsset);
        searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); findAsset(); } });

        container.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.remove-misplaced-btn');
            if (removeBtn) {
                const compositeId = removeBtn.dataset.compositeId || removeBtn.dataset.id;
                const el = document.getElementById(`misplaced-item-${compositeId}`);
                if (el) el.remove();
                const inputEl = document.getElementById(`misplaced-input-${compositeId}`);
                if (inputEl) inputEl.remove();
                
                misplacedAssetIdSet.delete(compositeId);
                misplacedAssets = misplacedAssets.filter(asset => {
                    const idToMatch = asset.source === 'borrowed' ? `borrowed_${asset.id}` : `asset_${asset.id}`;
                    return idToMatch !== compositeId;
                });
                saveMisplacedState();

                feedbackDiv.textContent = 'Item removed.';
                feedbackDiv.className = 'text-xs mt-2 h-4 text-gray-500';
            }
        });

        lucide.createIcons();

        document.addEventListener('DOMContentLoaded', () => {
            const progressBar = document.getElementById('progressBar');
            const progressCounter = document.getElementById('progressCounter');
            const allCheckboxes = document.querySelectorAll('.asset-checkbox');
            const totalAssets = allCheckboxes.length;
            const filterToggles = document.getElementById('filter-toggles');
            const assetNameFilterSelect = document.getElementById('assetNameFilter');
            const assetRows = document.querySelectorAll('ul.divide-y > li[data-asset-name]');
            const noFilterResultsRow = document.getElementById('noFilterResults');
            const checkAllBtn = document.getElementById('checkAllBtn');

            function updateProgress() {
                if (!progressBar || !progressCounter) return;
                const checkedCount = document.querySelectorAll('.asset-checkbox:checked').length;
                const percentage = totalAssets > 0 ? (checkedCount / totalAssets) * 100 : 0;
                progressBar.style.width = `${percentage}%`;
                progressCounter.textContent = `${checkedCount} / ${totalAssets} Items Verified`;
            }

            function applyFilter() {
                const statusFilter = filterToggles.querySelector('.bg-blue-600').dataset.filter;
                const nameFilter = assetNameFilterSelect.value;
                let visibleCount = 0;

                assetRows.forEach(row => {
                    const checkbox = row.querySelector('.asset-checkbox');
                    if (!checkbox) return;

                    const isChecked = checkbox.checked;
                    const assetName = row.dataset.assetName;

                    const nameMatch = (nameFilter === 'all' || assetName === nameFilter);

                    let statusMatch = false;
                    switch (statusFilter) {
                        case 'pending':
                            statusMatch = !isChecked;
                            break;
                        case 'verified':
                            statusMatch = isChecked;
                            break;
                        case 'all':
                        default:
                            statusMatch = true;
                            break;
                    }

                    const shouldBeVisible = nameMatch && statusMatch;
                    row.classList.toggle('hidden', !shouldBeVisible);
                    if (shouldBeVisible) {
                        visibleCount++;
                    }
                });

                if (noFilterResultsRow) {
                    noFilterResultsRow.classList.toggle('hidden', visibleCount > 0 || totalAssets === 0);
                }
            }

            allCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateProgress();
                    applyFilter();
                });
            });

            if (filterToggles) {
                filterToggles.addEventListener('click', (e) => {
                    const targetButton = e.target.closest('.filter-btn');
                    if (!targetButton) return;

                    filterToggles.querySelectorAll('.filter-btn').forEach(btn => {
                        btn.classList.remove('bg-blue-600', 'text-white');
                        btn.classList.add('text-gray-500', 'hover:bg-gray-50');
                    });
                    targetButton.classList.add('bg-blue-600', 'text-white');
                    targetButton.classList.remove('text-gray-500', 'hover:bg-gray-50');

                    applyFilter();
                });
            }

            if (assetNameFilterSelect) {
                assetNameFilterSelect.addEventListener('change', applyFilter);
            }

            if (checkAllBtn) {
                checkAllBtn.addEventListener('click', () => {
                    document.querySelectorAll('.asset-checkbox').forEach(checkbox => {
                        if (!checkbox.closest('li').classList.contains('hidden')) {
                            if (!checkbox.checked) {
                                checkbox.checked = true;
                                const assetId = checkbox.id.replace('asset_present_', '');
                                saveAssetState(assetId);
                            }
                            // Also save condition and note for "Mark All as Present"
                            const assetId = checkbox.id.replace('asset_present_', '');
                            saveAssetState(assetId);
                        }
                    });
                    updateProgress();
                    applyFilter();
                });
            }

            // --- Initial Load from Storage ---
            loadStateFromStorage();
            updateProgress();
            applyFilter();
        });
    </script>
    <script src="loader/html5-qrcode.min.js"></script>

    <!-- QR Scanner Modal for Audit -->
    <div id="auditQrScannerModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center bg-slate-900/70 backdrop-blur-sm p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    <h3 class="font-bold text-gray-900 text-base">Audit: Scan Asset QR</h3>
                </div>
                <button onclick="closeAuditQrScanner()" class="p-1.5 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="p-5">
                <div id="audit-qr-reader" class="rounded-xl overflow-hidden shadow-inner bg-black" style="width:100%"></div>
                <div id="audit-qr-scan-status" class="mt-3 text-center text-sm text-gray-500 font-medium">Scan an asset QR to check it in</div>
            </div>
        </div>
    </div>

    <script>
        let auditQrScanner = null;
        let lastScannedText = "";
        let scanThrottleTimeout = null;
        let scanAudioContext = null;

        function playScanBeep(kind = 'success') {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;

            if (!scanAudioContext) {
                scanAudioContext = new AudioContextClass();
            }

            if (scanAudioContext.state === 'suspended') {
                scanAudioContext.resume().catch(() => {});
            }

            // success = two ascending tones, misplaced = two-tone alert, already = two soft medium tones, notfound = low error tone
            const beepPattern = kind === 'misplaced'
                ? [{ frequency: 520, duration: 0.12 }, { frequency: 780, duration: 0.15 }]
                : kind === 'notfound'
                ? [{ frequency: 300, duration: 0.20 }]
                : kind === 'already'
                ? [{ frequency: 660, duration: 0.08 }, { frequency: 660, duration: 0.08 }]  // same-pitch double ping
                : [{ frequency: 880, duration: 0.10 }, { frequency: 1100, duration: 0.14 }]; // success: two ascending tones

            const peakGain = kind === 'misplaced' ? 0.45 : kind === 'notfound' ? 0.40 : kind === 'already' ? 0.30 : 0.55;

            let startAt = scanAudioContext.currentTime;
            beepPattern.forEach((tone, index) => {
                const oscillator = scanAudioContext.createOscillator();
                const gainNode = scanAudioContext.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = tone.frequency;

                gainNode.gain.setValueAtTime(0.0001, startAt);
                gainNode.gain.exponentialRampToValueAtTime(peakGain, startAt + 0.01);
                gainNode.gain.exponentialRampToValueAtTime(0.0001, startAt + tone.duration);

                oscillator.connect(gainNode);
                gainNode.connect(scanAudioContext.destination);
                oscillator.start(startAt);
                oscillator.stop(startAt + tone.duration + 0.02);

                startAt += tone.duration + 0.06;
            });
        }

        function showScanFeedback(statusEl, message, colorClass) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.className = `mt-3 text-center text-sm ${colorClass}`;
        }

        function playScanBeepSafely(kind = 'success') {
            try {
                playScanBeep(kind);
            } catch (error) {
                console.warn('Scan beep failed:', error);
            }
        }

        function openAuditQrScanner() {
            document.getElementById('auditQrScannerModal').classList.remove('hidden');
            document.getElementById('audit-qr-scan-status').textContent = 'Starting camera... Keep scanning after each beep.';
            document.getElementById('audit-qr-scan-status').className = 'mt-3 text-center text-sm text-gray-500 font-medium';
            lastScannedText = "";

            auditQrScanner = new Html5Qrcode("audit-qr-reader");
            auditQrScanner.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                function(decodedText) {
                    const cleanText = decodedText.trim();
                    if (cleanText === lastScannedText) {
                        return; // Prevent duplicate immediate scans
                    }
                    lastScannedText = cleanText;
                    
                    // Throttle reset of lastScannedText so user can scan it again after 3 seconds if needed
                    clearTimeout(scanThrottleTimeout);
                    scanThrottleTimeout = setTimeout(() => {
                        lastScannedText = "";
                    }, 3000);

                    handleAuditQrResult(cleanText);
                },
                function(err) { /* ignore */ }
            ).catch(function(err) {
                document.getElementById('audit-qr-scan-status').textContent = 'Camera error: ' + err;
                document.getElementById('audit-qr-scan-status').className = 'mt-3 text-center text-sm text-red-600 font-medium';
            });
        }

        function closeAuditQrScanner() {
            if (auditQrScanner) {
                auditQrScanner.stop().catch(() => {});
                auditQrScanner = null;
            }
            document.getElementById('auditQrScannerModal').classList.add('hidden');
        }

        async function handleAuditQrResult(assetNo) {
            if (!assetNo) return;
            const statusEl = document.getElementById('audit-qr-scan-status');
            
            // Try finding in expected assets list
            const targetLi = document.querySelector(`li[data-asset-no="${assetNo}"]`);
            
            if (targetLi) {
                const checkbox = targetLi.querySelector('.asset-checkbox');
                if (checkbox) {
                    const alreadyChecked = checkbox.checked;
                    checkbox.checked = true;
                    const assetId = checkbox.id.replace('asset_present_', '');
                    saveAssetState(assetId);

                    // Dispatch change event — this triggers the updateProgress() + applyFilter()
                    // listeners wired inside DOMContentLoaded (they live in that scope)
                    checkbox.dispatchEvent(new Event('change'));

                    // Visual feedback: green flash on the list item
                    targetLi.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    targetLi.classList.add('bg-emerald-50', 'transition-colors', 'duration-500');
                    setTimeout(() => {
                        targetLi.classList.remove('bg-emerald-50');
                    }, 1500);

                    if (alreadyChecked) {
                        // Already scanned — show reminder message
                        showScanFeedback(statusEl, `🔔 Already verified: ${assetNo}`, 'text-amber-500 font-bold text-base');
                        playScanBeepSafely('already');
                    } else {
                        // Fresh scan — show success message + beep
                        showScanFeedback(statusEl, `✅ Present: ${assetNo}`, 'text-emerald-600 font-bold text-base');
                        playScanBeepSafely('success');
                    }

                    // Auto-reset status after 2.5s
                    setTimeout(() => {
                        showScanFeedback(statusEl, 'Scan next asset QR...', 'text-gray-500 font-medium text-sm');
                    }, 2500);
                }
            } else {
                // Not in expected list: add to misplaced
                showScanFeedback(statusEl, `🔍 Checking: ${assetNo}...`, 'text-amber-600 font-medium text-sm');

                const mInput = document.getElementById('misplacedAssetSearch');
                if (mInput) {
                    mInput.value = assetNo;
                    const result = await findAsset(); // Calls existing findAsset JS function

                    if (result && result.ok) {
                        showScanFeedback(statusEl, `⚠️ Misplaced: ${assetNo}. Ready for next scan.`, 'text-blue-600 font-bold text-base');
                        playScanBeepSafely('misplaced');
                        setTimeout(() => {
                            showScanFeedback(statusEl, 'Scan next asset QR...', 'text-gray-500 font-medium text-sm');
                        }, 2500);
                    } else {
                        const errorMessage = (result && result.error) ? result.error : 'Could not mark misplaced asset.';
                        showScanFeedback(statusEl, `❌ ${errorMessage}`, 'text-red-600 font-medium text-sm');
                        playScanBeepSafely('notfound');
                    }
                }
            }
        }
    </script>
    <script src="loader/loader.js"></script>
  <?php include 'page_scripts.php'; ?>
</body>
</html>
