<?php
// This file is designed to be included by dashboard.php, so we assume session_start() and db.php are already handled.
if (!defined('IS_EMBEDDED')) { // If accessed directly, establish a context.
    session_start();
    require 'db.php';
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.html");
        exit();
    }
}

$suggested_item_no = 1;
$latest_item_stmt = $conn->prepare("SELECT item_no FROM assets ORDER BY id DESC LIMIT 20");
if ($latest_item_stmt) {
    $latest_item_stmt->execute();
    $latest_item_stmt->bind_result($latest_item_value);

    while ($latest_item_stmt->fetch()) {
        if (is_numeric($latest_item_value)) {
            $suggested_item_no = (int)$latest_item_value + 1;
            break;
        }

        if (preg_match('/I-(\d+)/', $latest_item_value, $matches)) {
            $suggested_item_no = (int)$matches[1] + 1;
            break;
        }
    }

    $latest_item_stmt->close();
}

// --- START: ACTION HANDLER FOR ADDING AN ASSET ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add_asset') {
    
    // Sanitize and retrieve POST data from the form
    $asset_name = trim($_POST['asset_name']);
    $category_id = (int)$_POST['category_id'];
    $quantity = (int)$_POST['quantity'];
    $item_number = (int)$_POST['item_no'];
    $cost = (float)$_POST['cost'];
    $location = trim($_POST['location']);
    $date_of_issue = $_POST['date_of_issue'];
    $assigned_to = !empty($_POST['assigned_to']) ? trim($_POST['assigned_to']) : null;
    $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    $page_no = !empty($_POST['page_no']) ? trim($_POST['page_no']) : null;
    $gem_order_no = !empty($_POST['gem_order_no']) ? trim($_POST['gem_order_no']) : null;
    $gpr_no = !empty($_POST['gpr_no']) ? trim($_POST['gpr_no']) : null;
    $pr_page_no = !empty($_POST['pr_page_no']) ? trim($_POST['pr_page_no']) : null;
    $gpr_item_no = !empty($_POST['gpr_item_no']) ? trim($_POST['gpr_item_no']) : null;
    $gem_invoice_no = !empty($_POST['gem_invoice_no']) ? trim($_POST['gem_invoice_no']) : null;

    $asset_numbers = [];
    $posted_asset_numbers = trim($_POST['asset_no'] ?? '');
    $asset_number_pattern = '/^KDP\/COMP\/\d{4}\/(EXP|CONS|DS|FUR)\/p-[A-Za-z0-9.-]*\/I-\d+\/\d+\/\d+$/';

    if ($posted_asset_numbers !== '') {
        $asset_numbers = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r|,/', $posted_asset_numbers)), function ($value) {
            return $value !== '';
        }));

        foreach ($asset_numbers as $asset_no_value) {
            if (!preg_match($asset_number_pattern, $asset_no_value)) {
                header("Location: dashboard.php?view=add-asset&status=error&message=" . urlencode('Asset No must be in the format KDP/COMP/YYYY/CATEGORY/p-PAGE/I-ITEM/SEQ/QTY.'));
                exit();
            }
        }
    }

    $asset_count = count($asset_numbers) > 0 ? count($asset_numbers) : max(1, $quantity);

    $inserted = false;
    for ($index = 0; $index < $asset_count; $index++) {
        $row_item_number = $item_number + $index;
        $asset_no_value = $asset_numbers[$index] ?? $posted_asset_numbers;

        $stmt = $conn->prepare(
            "INSERT INTO assets (
                asset_name, category_id, quantity, item_no, asset_no, cost, location, date_of_issue, assigned_to, remarks,
                page_no, gem_order_no, gpr_no, pr_page_no, gpr_item_no, gem_invoice_no
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $row_quantity = 1;
        $stmt->bind_param(
            "siiisdssssssssss",
            $asset_name,
            $category_id,
            $row_quantity,
            $row_item_number,
            $asset_no_value,
            $cost,
            $location,
            $date_of_issue,
            $assigned_to,
            $remarks,
            $page_no,
            $gem_order_no,
            $gpr_no,
            $pr_page_no,
            $gpr_item_no,
            $gem_invoice_no
        );

        if ($stmt->execute()) {
            $inserted = true;
        } else {
            $error_message = urlencode($stmt->error);
            $stmt->close();
            header("Location: dashboard.php?view=add-asset&status=error&message=" . $error_message);
            exit();
        }

        $stmt->close();
    }

    if ($inserted) {
        header("Location: view-assets.php?category_id=" . $category_id . "&status=asset_added");
    } else {
        header("Location: dashboard.php?view=add-asset&status=error&message=" . urlencode('No asset numbers were generated.'));
    }
    exit();
}
$embedMode = defined('IS_EMBEDDED');
if (!$embedMode) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Asset - K.D. Polytechnic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
<?php
}
?>
    <div class="min-h-screen bg-slate-100 px-4 py-6 sm:px-6 lg:px-8">
        <div class="w-full max-w-5xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 lg:p-10">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Add New Asset</h1>
                <p class="mt-1 text-sm text-slate-500">Add a new equipment record into the official departmental ledger.</p>
            </div>

            <div>

                <form id="assetForm" class="grid gap-6 lg:grid-cols-2" action="add-asset.php" method="post">
                    <input type="hidden" name="action" value="add_asset">
                    <div class="space-y-5 lg:col-span-2">
                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="asset_name" class="mb-2 block text-sm font-semibold text-slate-700">Asset Name</label>
                                <input type="text" id="asset_name" name="asset_name" placeholder="Keyboard" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="category" class="mb-2 block text-sm font-semibold text-slate-700">Category</label>
                                <select id="category" name="category_id" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                    <option value="">Select category</option>
                                    <option value="1">Expandable</option>
                                    <option value="2">Consumables</option>
                                    <option value="3">Deadstock</option>
                                    <option value="4">Furniture</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="page_no" class="mb-2 block text-sm font-semibold text-slate-700">Page No.</label>
                                <input type="text" id="page_no" name="page_no" placeholder="Enter page no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            </div>

                            <div>
                                <label for="quantity" class="mb-2 block text-sm font-semibold text-slate-700">Quantity</label>
                                <input type="number" id="quantity" name="quantity" min="1" value="1" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="gem_order_no" class="mb-2 block text-sm font-semibold text-slate-700">Gem Order No.</label>
                                <input type="text" id="gem_order_no" name="gem_order_no" placeholder="Enter GEM order no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            </div>

                            <div>
                                <label for="gem_invoice_no" class="mb-2 block text-sm font-semibold text-slate-700">Gem Invoice No.</label>
                                <input type="text" id="gem_invoice_no" name="gem_invoice_no" placeholder="Enter GEM invoice no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="gpr_no" class="mb-2 block text-sm font-semibold text-slate-700">GPR No.</label>
                                <input type="text" id="gpr_no" name="gpr_no" placeholder="Enter GPR no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="pr_page_no" class="mb-2 block text-sm font-semibold text-slate-700">GPR Page No.</label>
                                    <input type="text" id="pr_page_no" name="pr_page_no" placeholder="Enter GPR page no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                </div>

                                <div>
                                    <label for="gpr_item_no" class="mb-2 block text-sm font-semibold text-slate-700">GPR Item No.</label>
                                    <input type="text" id="gpr_item_no" name="gpr_item_no" placeholder="Enter GPR item no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="item_no" class="mb-2 block text-sm font-semibold text-slate-700">Item No</label>
                                <input type="number" id="item_no" name="item_no" min="1" value="<?php echo (int)$suggested_item_no; ?>" placeholder="Enter item no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="location" class="mb-2 block text-sm font-semibold text-slate-700">Location</label>
                                <select id="location" name="location" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                    <option value="">Select location</option>
                                </select>
                                <div id="custom_location_wrapper" class="mt-3 hidden">
                                    <label for="custom_location" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Add new location</label>
                                    <div class="flex gap-2">
                                        <input type="text" id="custom_location" placeholder="Enter new lab name" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                        <button type="button" id="add_location_btn" class="rounded-lg border border-blue-600 bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700">Add</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="date_of_issue" class="mb-2 block text-sm font-semibold text-slate-700">Date of Issue</label>
                                <input type="date" id="date_of_issue" name="date_of_issue" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="assigned_to" class="mb-2 block text-sm font-semibold text-slate-700">Assign to Faculty</label>
                                <select id="assigned_to" name="assigned_to" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                    <option value="">Loading faculty...</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="cost" class="mb-2 block text-sm font-semibold text-slate-700">Amount per Item</label>
                                <input type="number" id="cost" name="cost" step="0.01" placeholder="₹ Amount" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="total_amount" class="mb-2 block text-sm font-semibold text-slate-700">Total Amount</label>
                                <input type="text" id="total_amount" name="total_amount" readonly placeholder="Auto-calculated" class="w-full rounded-lg border border-slate-300 bg-gray-100 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none">
                            </div>
                        </div>

                        <div>
                            <label for="remarks" class="mb-2 block text-sm font-semibold text-slate-700">Remarks</label>
                            <textarea id="remarks" name="remarks" rows="3" placeholder="Enter any specific condition or notes..." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200"></textarea>
                        </div>

                        <div>
                            <label for="asset_no" class="mb-2 block text-sm font-semibold text-slate-700">Asset No</label>
                            <textarea id="asset_no" name="asset_no" rows="4" placeholder="KDP/COMP/2026/EXP/p-12/I-10/1/3" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required></textarea>
                            <p class="mt-2 text-xs text-slate-500">Use the format KDP/COMP/YYYY/CATEGORY/p-PAGE/I-ITEM/SEQ/QTY.</p>
                        </div>
                    </div>

                    <div class="lg:col-span-2 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="dashboard.php?view=dashboard" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                            Save Asset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<script>
    const assetForm = document.getElementById('assetForm');
    const quantityInput = document.getElementById('quantity');
    const costInput = document.getElementById('cost');
    const totalAmountInput = document.getElementById('total_amount');
    const categoryInput = document.getElementById('category');
    const dateInput = document.getElementById('date_of_issue');
    const itemNoInput = document.getElementById('item_no');
    const assetNoInput = document.getElementById('asset_no');
    const locationSelect = document.getElementById('location');
    const customLocationInput = document.getElementById('custom_location');
    const customLocationWrapper = document.getElementById('custom_location_wrapper');
    const addLocationButton = document.getElementById('add_location_btn');
    const assignedToSelect = document.getElementById('assigned_to');
    const locationStorageKey = 'kd_polytechnic_saved_locations';

    function getCategoryCode(value) {
        const categoryMap = {
            '1': 'EXP',
            '2': 'CONS',
            '3': 'DS',
            '4': 'FUR'
        };
        return categoryMap[value] || '';
    }

    function updateAssetNo() {
        const categoryValue = categoryInput.value;
        const categoryCode = getCategoryCode(categoryValue);
        const year = new Date().getFullYear();
        const pageNo = document.getElementById('page_no').value.trim();
        const quantity = parseInt(quantityInput.value, 10) || 1;
        const startItemNo = parseInt(itemNoInput.value, 10) || 1;

        if (categoryCode) {
            const generatedNumbers = [];
            for (let index = 0; index < quantity; index++) {
                const currentItemNo = startItemNo + index;
                const pageSuffix = pageNo ? `p-${pageNo}` : 'p-';
                generatedNumbers.push(`KDP/COMP/${year}/${categoryCode}/${pageSuffix}/I-${currentItemNo}/${index + 1}/${quantity}`);
            }
            assetNoInput.value = generatedNumbers.join('\n');
        } else {
            assetNoInput.value = '';
        }
    }

    function isValidAssetNumber(value) {
        const pattern = /^KDP\/COMP\/\d{4}\/(EXP|CONS|DS|FUR)\/p-[A-Za-z0-9.-]*\/I-\d+\/\d+\/\d+$/;
        const entries = value.split(/\r?\n|,/).map(entry => entry.trim()).filter(Boolean);

        if (entries.length === 0) {
            return false;
        }

        return entries.every(entry => pattern.test(entry));
    }

    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const cost = parseFloat(costInput.value) || 0;
        const total = quantity * cost;
        totalAmountInput.value = total > 0 ? total.toFixed(2) : '';
    }

    function getSavedLocations() {
        try {
            return JSON.parse(localStorage.getItem(locationStorageKey)) || [];
        } catch (error) {
            return [];
        }
    }

    function populateLocationOptions() {
        const defaults = ['F004', 'Lab 1', 'Lab 2'];
        const savedLocations = getSavedLocations();
        const allLocations = [...new Set([...defaults, ...savedLocations])];
        const currentValue = locationSelect.value;

        locationSelect.innerHTML = '';

        const selectOption = document.createElement('option');
        selectOption.value = '';
        selectOption.textContent = 'Select location';
        locationSelect.appendChild(selectOption);

        allLocations.forEach(location => {
            const option = document.createElement('option');
            option.value = location;
            option.textContent = location;
            locationSelect.appendChild(option);
        });

        const otherOption = document.createElement('option');
        otherOption.value = '__other__';
        otherOption.textContent = 'Other';
        locationSelect.appendChild(otherOption);

        if (currentValue && Array.from(locationSelect.options).some(option => option.value === currentValue)) {
            locationSelect.value = currentValue;
        } else if (currentValue === '__other__') {
            locationSelect.value = '__other__';
        }
    }

    function toggleCustomLocationInput() {
        const showCustomInput = locationSelect.value === '__other__';
        customLocationWrapper.classList.toggle('hidden', !showCustomInput);
        if (!showCustomInput) {
            customLocationInput.value = '';
        }
    }

    function addCustomLocation() {
        const newLocation = customLocationInput.value.trim();
        if (!newLocation) {
            alert('Please enter a new location name.');
            return;
        }

        const savedLocations = getSavedLocations();
        if (!savedLocations.includes(newLocation)) {
            savedLocations.push(newLocation);
            localStorage.setItem(locationStorageKey, JSON.stringify(savedLocations));
        }

        populateLocationOptions();
        locationSelect.value = newLocation;
        customLocationInput.value = '';
        customLocationWrapper.classList.add('hidden');
    }

    [quantityInput, costInput, categoryInput, itemNoInput].forEach(input => {
        input.addEventListener('input', () => {
            calculateTotal();
            updateAssetNo();
        });
        input.addEventListener('change', () => {
            calculateTotal();
            updateAssetNo();
        });
    });

    document.getElementById('page_no').addEventListener('input', updateAssetNo);
    categoryInput.addEventListener('change', updateAssetNo);

    locationSelect.addEventListener('change', toggleCustomLocationInput);
    addLocationButton.addEventListener('click', addCustomLocation);
    customLocationInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addCustomLocation();
        }
    });

    assetForm.addEventListener('submit', function (event) {
        const selectedLocation = locationSelect.value.trim();
        const locationFilled = selectedLocation !== '' && selectedLocation !== '__other__';
        const facultyFilled = assignedToSelect.value.trim() !== '';

        if (!locationFilled && !facultyFilled) {
            event.preventDefault();
            alert('Please fill at least one of the following: Location or Assign to Faculty.');
            return;
        }

        if (selectedLocation === '__other__') {
            event.preventDefault();
            alert('Please add a new location name or choose an existing one.');
            return;
        }

        const requiredFields = [
            document.getElementById('asset_name'),
            categoryInput,
            quantityInput,
            itemNoInput,
            assetNoInput,
            costInput,
            dateInput
        ];

        for (const field of requiredFields) {
            if (!field.value || field.value.trim() === '') {
                event.preventDefault();
                alert('Please fill all required fields in the correct format.');
                return;
            }
        }

        if (!isValidAssetNumber(assetNoInput.value.trim())) {
            event.preventDefault();
            alert('Asset No must be in the format KDP/COMP/YYYY/CATEGORY/p-PAGE/I-ITEM/SEQ/QTY.');
            return;
        }

        if (parseFloat(quantityInput.value) < 1) {
            event.preventDefault();
            alert('Quantity must be at least 1.');
            return;
        }

        if (parseFloat(costInput.value) < 0) {
            event.preventDefault();
            alert('Amount per Item cannot be negative.');
            return;
        }
    });

    fetch('get-faculty.php')
        .then(response => response.json())
        .then(data => {
            assignedToSelect.innerHTML = '<option value="">Select faculty</option>';
            data.forEach(user => {
                const option = document.createElement('option');
                option.value = user.full_name;
                option.textContent = user.full_name;
                assignedToSelect.appendChild(option);
            });
        })
        .catch(() => {
            assignedToSelect.innerHTML = '<option value="">No faculty available</option>';
        });

    populateLocationOptions();
    toggleCustomLocationInput();
    updateAssetNo();
</script>
<?php if (!$embedMode) { ?>
</body>
</html>
<?php } ?>
