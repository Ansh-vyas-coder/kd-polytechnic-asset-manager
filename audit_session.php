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
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Invalid audit session."));
    exit();
}

// Fetch audit session details
$audit_stmt = $conn->prepare("SELECT location_id, status FROM audits WHERE id = ?");
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

// If the audit is already completed, redirect them
if ($audit_session['status'] === 'Completed') {
    header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("This audit has already been completed."));
    exit();
}

$location_id = $audit_session['location_id'];

// Fetch all assets that are expected to be in this location
$assets_stmt = $conn->prepare("SELECT id, asset_name, asset_no, category_id FROM assets WHERE location = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) ORDER BY asset_name ASC");
if (!$assets_stmt) {
    die("Database error preparing to fetch assets.");
}
$assets_stmt->bind_param("s", $location_id);
$assets_stmt->execute();
$assets_result = $assets_stmt->get_result();
$expected_assets = $assets_result->fetch_all(MYSQLI_ASSOC);
$assets_stmt->close();

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
                    <h1 class="text-lg font-semibold">Audit Session</h1>
                </div>
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="relative">
                        <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
                        </button>
                    </div>
                </div>
            </header>

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
                                <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-600">Show:</span>
                                        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 gap-1" id="filter-toggles">
                                            <button type="button" data-filter="all" class="filter-btn text-xs font-semibold px-3 py-1.5 rounded-md bg-blue-600 text-white transition-all">All</button>
                                            <button type="button" data-filter="pending" class="filter-btn text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all">Pending</button>
                                            <button type="button" data-filter="verified" class="filter-btn text-xs font-semibold px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-50 transition-all">Verified</button>
                                        </div>
                                    </div>
                                    <button type="button" id="checkAllBtn" class="text-sm font-medium text-blue-600 hover:text-blue-800">Mark All as Present</button>
                                </div>
                                <ul class="divide-y divide-gray-100">
                                    <?php if (empty($expected_assets)): ?>
                                        <li class="p-6 text-center text-gray-500">No assets are assigned to this location.</li>
                                    <?php else: ?>
                                        <?php foreach ($expected_assets as $asset): ?>
                                            <li class="p-4 sm:p-5">
                                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                                    <!-- Left side: Asset Info -->
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($asset['asset_name']); ?></p>
                                                        <p class="text-xs text-gray-500 mt-1 font-mono"><?php echo htmlspecialchars($asset['asset_no'] ?: 'No Asset No.'); ?></p>
                                                    </div>

                                                    <!-- Right side: Controls -->
                                                    <div class="flex items-center gap-x-4 gap-y-3 flex-wrap sm:flex-nowrap justify-end">
                                                        <!-- Checkbox for 'Present' -->
                                                        <label for="asset_present_<?php echo $asset['id']; ?>" class="flex items-center gap-2 cursor-pointer flex-shrink-0">
                                                            <input type="checkbox" 
                                                                   id="asset_present_<?php echo $asset['id']; ?>" 
                                                                   name="assets[<?php echo $asset['id']; ?>][status]" 
                                                                   value="Present" 
                                                                   class="asset-checkbox h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                            <span class="text-sm font-medium text-gray-700">Present</span>
                                                        </label>

                                                        <!-- Condition Dropdown -->
                                                        <div class="w-full sm:w-36 flex-shrink-0">
                                                            <select name="assets[<?php echo $asset['id']; ?>][condition]" class="w-full text-sm bg-gray-50 border-gray-200 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                                                <option value="Good" selected>Good</option>
                                                                <option value="Needs Repair">Needs Repair</option>
                                                                <option value="Broken">Broken</option>
                                                                <option value="Scrap">Scrap</option>
                                                            </select>
                                                        </div>

                                                        <!-- Note Input -->
                                                        <div class="w-full sm:w-48 flex-shrink-0">
                                                            <input type="text" 
                                                                   name="assets[<?php echo $asset['id']; ?>][note]" 
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
            if (misplacedAssetIdSet.has(asset.id.toString())) {
                feedbackDiv.textContent = 'This asset has already been added.';
                feedbackDiv.className = 'text-xs mt-2 h-4 text-amber-600';
                return;
            }

            misplacedAssetIdSet.add(asset.id.toString());
            misplacedAssets.push(asset);

            const itemHtml = `
                <div id="misplaced-item-${asset.id}" class="flex items-center justify-between gap-3 bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-sm text-blue-800 truncate">${asset.asset_name}</p>
                        <p class="text-xs text-blue-600 mt-1 font-mono">${asset.asset_no}</p>
                    </div>
                    <button type="button" class="remove-misplaced-btn p-1.5 rounded-full text-red-500 hover:bg-red-100" data-id="${asset.id}">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'misplaced_assets[]';
            hiddenInput.value = asset.id;
            hiddenInput.id = `misplaced-input-${asset.id}`;
            mainForm.appendChild(hiddenInput);

            lucide.createIcons();
            searchInput.value = '';
            feedbackDiv.textContent = `Added: ${asset.asset_name}`;
            feedbackDiv.className = 'text-xs mt-2 h-4 text-green-600';
            saveMisplacedState();
        }

        async function findAsset() {
            const assetNo = searchInput.value.trim();
            if (!assetNo) return;

            feedbackDiv.textContent = 'Searching...';
            feedbackDiv.className = 'text-xs mt-2 h-4 text-gray-500';

            try {
                const response = await fetch(`find_asset_for_audit.php?asset_no=${encodeURIComponent(assetNo)}&audit_id=${auditId}`);
                const data = await response.json();

                if (response.ok && data.id) {
                    addMisplacedItem(data);
                } else {
                    feedbackDiv.textContent = data.error || 'An unknown error occurred.';
                    feedbackDiv.className = 'text-xs mt-2 h-4 text-red-600';
                }
            } catch (error) {
                feedbackDiv.textContent = 'Failed to connect to the server.';
                feedbackDiv.className = 'text-xs mt-2 h-4 text-red-600';
            }
        }

        addBtn.addEventListener('click', findAsset);
        searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); findAsset(); } });

        container.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.remove-misplaced-btn');
            if (removeBtn) {
                const assetId = removeBtn.dataset.id;
                document.getElementById(`misplaced-item-${assetId}`).remove();
                document.getElementById(`misplaced-input-${assetId}`).remove();
                
                misplacedAssetIdSet.delete(assetId);
                misplacedAssets = misplacedAssets.filter(asset => asset.id.toString() !== assetId);
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
            const assetRows = document.querySelectorAll('ul.divide-y > li');
            const checkAllBtn = document.getElementById('checkAllBtn');

            function updateProgress() {
                if (!progressBar || !progressCounter) return;

                const checkedCount = document.querySelectorAll('.asset-checkbox:checked').length;
                const percentage = totalAssets > 0 ? (checkedCount / totalAssets) * 100 : 0;

                progressBar.style.width = `${percentage}%`;
                progressCounter.textContent = `${checkedCount} / ${totalAssets} Items Verified`;
            }

            function applyFilter(filter) {
                assetRows.forEach(row => {
                    const checkbox = row.querySelector('.asset-checkbox');
                    if (!checkbox) { // Handles the "No assets" message row
                        row.classList.toggle('hidden', filter !== 'all');
                        return;
                    }

                    const isChecked = checkbox.checked;

                    switch (filter) {
                        case 'pending':
                            row.classList.toggle('hidden', isChecked);
                            break;
                        case 'verified':
                            row.classList.toggle('hidden', !isChecked);
                            break;
                        case 'all':
                        default:
                            row.classList.remove('hidden');
                            break;
                    }
                });
            }

            allCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateProgress();
                    const activeFilter = filterToggles.querySelector('.bg-blue-600').dataset.filter;
                    applyFilter(activeFilter);
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

                    applyFilter(targetButton.dataset.filter);
                });
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
                    const activeFilter = filterToggles.querySelector('.bg-blue-600').dataset.filter;
                    applyFilter(activeFilter);
                });
            }

            // --- Initial Load from Storage ---
            loadStateFromStorage();
            updateProgress();
        });
    </script>
    <script src="loader/loader.js"></script>
</body>
</html>