<?php
// This file is designed to be included by dashboard.php, so we assume session_start() and db.php are already handled.

// --- START: AJAX handler for fetching dynamic filter options ---
if (isset($_GET['fetch_filters']) && $_GET['fetch_filters'] === 'true') {
    if (!isset($_SESSION)) {
        session_start();
    }
    require_once 'db.php';
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Authentication required.']);
        exit();
    }

    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    if ($category_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid category ID.']);
        exit();
    }

    $response = ['asset_names' => [], 'locations' => []];

    $stmt_assets = $conn->prepare("SELECT DISTINCT asset_name FROM assets WHERE category_id = ? ORDER BY asset_name ASC");
    $stmt_assets->bind_param("i", $category_id);
    $stmt_assets->execute();
    $result_assets = $stmt_assets->get_result();
    while ($row = $result_assets->fetch_assoc()) { $response['asset_names'][] = $row['asset_name']; }
    $stmt_assets->close();

    $stmt_locations = $conn->prepare("SELECT DISTINCT location FROM assets WHERE category_id = ? AND location IS NOT NULL AND location != '' ORDER BY location ASC");
    $stmt_locations->bind_param("i", $category_id);
    $stmt_locations->execute();
    $result_locations = $stmt_locations->get_result();
    while ($row = $result_locations->fetch_assoc()) { $response['locations'][] = $row['location']; }
    $stmt_locations->close();

    echo json_encode($response);
    exit(); // Stop execution after sending JSON data
}
// --- END: AJAX handler ---

if (!defined('IS_EMBEDDED')) {
    session_start();
    require 'db.php';
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.html");
        exit();
    }
}
?>

<div class="w-full max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Generate Asset Report</h1>
        <p class="text-sm text-gray-500 mt-1">Export asset data to an Excel (CSV) file based on your criteria.</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 lg:p-8">
        <form action="export.php" method="POST" class="space-y-6">

            <div>
                <label for="report_category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                <select id="report_category" name="category_id" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                    <option value="all">All Categories</option>
                    <option value="1">Expandable</option>
                    <option value="2">Consumables</option>
                    <option value="3">Deadstock</option>
                    <option value="4">Furniture</option>
                </select>
                <p class="text-xs text-gray-500 mt-2">Select which asset category to include in the report.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label for="report_asset_name" class="block text-sm font-medium text-gray-700 mb-2">Asset Name</label>
                    <select id="report_asset_name" name="asset_name" disabled class="w-full rounded-lg border border-gray-300 bg-gray-100 px-4 py-3 text-sm text-gray-900 shadow-sm outline-none transition disabled:opacity-70 disabled:cursor-not-allowed">
                        <option value="all">All Assets</option>
                    </select>
                </div>
                <div>
                    <label for="report_location" class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                    <select id="report_location" name="location" disabled class="w-full rounded-lg border border-gray-300 bg-gray-100 px-4 py-3 text-sm text-gray-900 shadow-sm outline-none transition disabled:opacity-70 disabled:cursor-not-allowed">
                        <option value="all">All Locations</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                </div>
            </div>
            <p class="text-xs text-gray-500 -mt-4">Select a date range for the assets' "Date of Issue". Leave blank to include all dates.</p>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-sm font-medium text-gray-700">Columns to Include</h4>
                    <div class="flex items-center gap-3">
                        <button type="button" id="selectAllCols" class="text-xs font-medium text-blue-600 hover:underline">Select All</button>
                        <button type="button" id="deselectAllCols" class="text-xs font-medium text-blue-600 hover:underline">Deselect All</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    <?php
                    $columns = [
                        'asset_name' => 'Asset Name', 'category_id' => 'Category', 'item_no' => 'Item No', 'asset_no' => 'Asset No',
                        'quantity' => 'Quantity', 'cost' => 'Cost', 'location' => 'Location', 'date_of_issue' => 'Date of Issue',
                        'assigned_to' => 'Assigned To', 'remarks' => 'Remarks', 'page_no' => 'Page No', 'gem_order_no' => 'GeM Order No',
                        'gem_invoice_no' => 'GeM Invoice No', 'gpr_no' => 'GPR No', 'pr_page_no' => 'GPR Page No', 'gpr_item_no' => 'GPR Item No'
                    ];
                    foreach ($columns as $key => $label) :
                    ?>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="columns[]" value="<?php echo $key; ?>" checked class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <?php echo $label; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pt-4 flex justify-end">
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                    <i data-lucide="download" style="width:16px;height:16px"></i>
                    Download Report
                </button>
            </div>
        </form>
    </div>
</div>
<script>
    // Set default end date to today
    document.getElementById('end_date').valueAsDate = new Date();

    const categorySelect = document.getElementById('report_category');
    const assetNameSelect = document.getElementById('report_asset_name');
    const locationSelect = document.getElementById('report_location');

    function resetSelect(selectElement, defaultText) {
        selectElement.innerHTML = `<option value="all">${defaultText}</option>`;
        selectElement.disabled = true;
        selectElement.classList.add('bg-gray-100', 'disabled:opacity-70', 'disabled:cursor-not-allowed');
    }

    categorySelect.addEventListener('change', function() {
        const categoryId = this.value;

        // Reset dependent dropdowns
        resetSelect(assetNameSelect, 'All Assets');
        resetSelect(locationSelect, 'All Locations');

        if (categoryId && categoryId !== 'all') {
            assetNameSelect.innerHTML = '<option value="">Loading assets...</option>';
            locationSelect.innerHTML = '<option value="">Loading locations...</option>';
            assetNameSelect.disabled = false;
            locationSelect.disabled = false;

            fetch(`generate-report.php?fetch_filters=true&category_id=${categoryId}`)
                .then(response => response.json())
                .then(data => {
                    // Populate Asset Names
                    assetNameSelect.innerHTML = '<option value="all">All Assets</option>';
                    if (data.asset_names && data.asset_names.length > 0) {
                        data.asset_names.forEach(name => {
                            const option = new Option(name, name);
                            assetNameSelect.add(option);
                        });
                    }

                    // Populate Locations
                    locationSelect.innerHTML = '<option value="all">All Locations</option>';
                    if (data.locations && data.locations.length > 0) {
                        data.locations.forEach(name => {
                            const option = new Option(name, name);
                            locationSelect.add(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching filters:', error);
                    resetSelect(assetNameSelect, 'Error loading assets');
                    resetSelect(locationSelect, 'Error loading locations');
                });
        }
    });

    // Column selection helpers
    const allCheckboxes = document.querySelectorAll('input[name="columns[]"]');
    document.getElementById('selectAllCols').addEventListener('click', () => {
        allCheckboxes.forEach(cb => cb.checked = true);
    });
    document.getElementById('deselectAllCols').addEventListener('click', () => {
        allCheckboxes.forEach(cb => cb.checked = false);
    });
</script>