<?php
if (!defined('IS_EMBEDDED')) {
    session_start();
    require 'db.php';
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.html");
        exit();
    }
}

require_once 'notification_utils.php';

$old_data = $_SESSION['add_borrowed_asset_old_data'] ?? [];
unset($_SESSION['add_borrowed_asset_old_data']);

$suggested_page_nos = [];
$suggested_item_nos = [];
for ($category_id = 1; $category_id <= 4; $category_id++) {
    $suggested_page_nos[$category_id] = 1;
    $suggested_item_nos[$category_id] = 1;

    $page_stmt = $conn->prepare("
        SELECT page_no
        FROM assets
        WHERE category_id = ?
          AND TRIM(COALESCE(page_no, '')) <> ''
          AND page_no REGEXP '^[0-9]+$'
        ORDER BY CAST(page_no AS UNSIGNED) DESC
        LIMIT 1
    ");

    if ($page_stmt) {
        $page_stmt->bind_param("i", $category_id);
        $page_stmt->execute();
        $page_stmt->bind_result($latest_page_no);

        if ($page_stmt->fetch() && is_numeric($latest_page_no)) {
            $suggested_page_nos[$category_id] = (int)$latest_page_no + 1;
        }

        $page_stmt->close();
    }

    $item_stmt = $conn->prepare("
        SELECT MAX(item_no)
        FROM assets
        WHERE category_id = ?
    ");

    if ($item_stmt) {
        $item_stmt->bind_param("i", $category_id);
        $item_stmt->execute();
        $item_stmt->bind_result($latest_item_no);

        if ($item_stmt->fetch() && $latest_item_no !== null && is_numeric($latest_item_no)) {
            $suggested_item_nos[$category_id] = (int)$latest_item_no + 1;
        }

        $item_stmt->close();
    }
}

$departments = [];
$dept_stmt = $conn->prepare("SELECT DISTINCT borrowed_from FROM borrowed_assets WHERE TRIM(COALESCE(borrowed_from, '')) <> '' ORDER BY borrowed_from ASC");
if ($dept_stmt) {
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    while ($dept_row = $dept_result->fetch_assoc()) {
        $departments[] = $dept_row['borrowed_from'];
    }
    $dept_stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add_borrowed_asset') {
    $_SESSION['add_borrowed_asset_old_data'] = $_POST;

    $asset_name = trim($_POST['asset_name']);
    $category_id = (int)$_POST['category_id'];
    $quantity = (int)$_POST['quantity'];
    $unit = !empty($_POST['unit']) ? trim($_POST['unit']) : 'pcs';
    $item_number = (int)$_POST['item_no'];
    $location = trim($_POST['location']);
    $borrow_date = $_POST['borrow_date'];
    $return_date = !empty($_POST['return_date']) ? $_POST['return_date'] : null;
    $date_of_issue = $borrow_date;
    $assigned_to = !empty($_POST['assigned_to']) ? trim($_POST['assigned_to']) : null;
    $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
    $page_no = !empty($_POST['page_no']) ? trim($_POST['page_no']) : null;
    $borrowed_from = !empty($_POST['borrowed_from']) ? trim($_POST['borrowed_from']) : null;

    if ($page_no === null || $page_no === '') {
        header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode('Page No is required.'));
        exit();
    }

    if (empty($borrow_date)) {
        header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode('Borrow Date is required.'));
        exit();
    }

    if (empty($borrowed_from)) {
        header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode('Department Name (Borrow From) is required.'));
        exit();
    }

    $check_item_stmt = $conn->prepare("SELECT id, asset_name FROM assets WHERE category_id = ? AND item_no = ? LIMIT 1");
    if ($check_item_stmt) {
        $check_item_stmt->bind_param("ii", $category_id, $item_number);
        $check_item_stmt->execute();
        $check_item_result = $check_item_stmt->get_result();
        if ($existing_row = $check_item_result->fetch_assoc()) {
            $check_item_stmt->close();
            $category_name_str = match($category_id) {
                1 => 'Expandable',
                2 => 'Consumables',
                3 => 'Deadstock',
                4 => 'Furniture',
                default => 'selected'
            };
            $err_msg = "Item No. {$item_number} is already used in {$category_name_str} category (Asset: '{$existing_row['asset_name']}'). Please use a unique Item No.";
            header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode($err_msg));
            exit();
        }
        $check_item_stmt->close();
    }

    $batch_id = uniqid('batch_', true);

    $asset_numbers = [];
    $posted_asset_numbers = trim($_POST['asset_no'] ?? '');
    $asset_number_pattern = '/^KDP\/[A-Z0-9]+\/\d{4}\/(EXP|CON|DST|FUR)\/P-[A-Za-z0-9.-]*\/I-\d+\/\d+\/\d+$/i';

    if ($posted_asset_numbers !== '') {
        $asset_numbers = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r|,/', $posted_asset_numbers)), function ($value) {
            return $value !== '';
        }));

        foreach ($asset_numbers as $asset_no_value) {
            if (!preg_match($asset_number_pattern, $asset_no_value)) {
                header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode('Asset No must be in the format KDP/DEPTNAME/YYYY/CATEGORY/P-PAGE/I-ITEM/SEQ/QTY.'));
                exit();
            }
        }
    }

    $asset_count = count($asset_numbers) > 0 ? count($asset_numbers) : max(1, $quantity);

    $inserted = false;
    for ($index = 0; $index < $asset_count; $index++) {
        $row_item_number = $item_number;
        $asset_no_value = $asset_numbers[$index] ?? $posted_asset_numbers;

        $stmt = $conn->prepare(
            "INSERT INTO borrowed_assets (
                asset_name, category_id, quantity, unit, item_no, asset_no, page_no, location, date_of_issue, assigned_to, remarks,
                borrowed_from, borrow_date, return_date, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );

        $row_quantity = 1;
        $stmt->bind_param(
            "sississsssssss",
            $asset_name,
            $category_id,
            $row_quantity,
            $unit,
            $row_item_number,
            $asset_no_value,
            $page_no,
            $location,
            $date_of_issue,
            $assigned_to,
            $remarks,
            $borrowed_from,
            $borrow_date,
            $return_date
        );

        if ($stmt->execute()) {
            $inserted = true;
        } else {
            $error_message = $stmt->error;
            $stmt->close();
            header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode($error_message));
            exit();
        }

        $stmt->close();
    }

    if ($inserted) {
        $notif_link = "dashboard.php?view=loaned-assets";
        $borrowed_total = max(1, $asset_count);
        $asset_name_list = [$asset_name];
        if ($borrowed_total > 1) {
            $asset_name_list = array_values(array_unique(array_fill(0, $borrowed_total, $asset_name)));
        }
        $asset_names_label = implode(', ', array_map(function ($name) {
            return "'" . htmlspecialchars((string)$name) . "'";
        }, $asset_name_list));

        $admin_message = $borrowed_total === 1
            ? "New borrowed asset {$asset_names_label} was added by " . htmlspecialchars($_SESSION['user_name'] ?? 'System') . "."
            : "{$borrowed_total} borrowed assets ({$asset_names_label}) were added by " . htmlspecialchars($_SESSION['user_name'] ?? 'System') . ".";
        create_admin_notification($conn, $admin_message, $notif_link, $_SESSION['user_id'] ?? null);

        if (isset($_SESSION['role']) && $assigned_to !== null) {
            $faculty_user_id = get_user_id_by_name($conn, $assigned_to);
            if ($faculty_user_id !== null) {
                $faculty_message = $borrowed_total === 1
                    ? "New borrowed asset {$asset_names_label} assigned to you by " . htmlspecialchars($_SESSION['user_name']) . "."
                    : "{$borrowed_total} borrowed assets ({$asset_names_label}) assigned to you by " . htmlspecialchars($_SESSION['user_name']) . ".";
                create_notification($conn, $faculty_user_id, $faculty_message, $notif_link);
            }
        }

        unset($_SESSION['add_borrowed_asset_old_data']);
        header("Location: dashboard.php?view=loaned-assets&status=borrowed_asset_added");
    } else {
        header("Location: dashboard.php?view=loaned-assets&status=error&message=" . urlencode('No borrowed assets were added.'));
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
    <title>Add Borrowed Asset - K.D. Polytechnic</title>
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
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Add Borrowed Asset</h1>
                <p class="mt-1 text-sm text-slate-500">Record an asset borrowed from another department.</p>
            </div>

            <div>
                <?php if (isset($_GET['status']) && $_GET['status'] === 'error' && !empty($_GET['message'])): ?>
                    <div class="mb-5 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-red-600" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <span><?php echo htmlspecialchars($_GET['message']); ?></span>
                    </div>
                <?php endif; ?>

                <form id="borrowedAssetForm" class="grid gap-6 lg:grid-cols-2" action="add-borrowed-asset.php" method="post">
                    <input type="hidden" name="action" value="add_borrowed_asset">
                    <div class="space-y-5 lg:col-span-2">
                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="asset_name" class="mb-2 block text-sm font-semibold text-slate-700">Asset Name</label>
                                <input type="text" id="asset_name" name="asset_name" placeholder="Keyboard" value="<?php echo htmlspecialchars($old_data['asset_name'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="category" class="mb-2 block text-sm font-semibold text-slate-700">Category</label>
                                <select id="category" name="category_id" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                    <option value="">Select category</option>
                                    <option value="1" <?php echo (($old_data['category_id'] ?? '') == '1') ? 'selected' : ''; ?>>Expandable</option>
                                    <option value="2" <?php echo (($old_data['category_id'] ?? '') == '2') ? 'selected' : ''; ?>>Consumables</option>
                                    <option value="3" <?php echo (($old_data['category_id'] ?? '') == '3') ? 'selected' : ''; ?>>Deadstock</option>
                                    <option value="4" <?php echo (($old_data['category_id'] ?? '') == '4') ? 'selected' : ''; ?>>Furniture</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="page_no" class="mb-2 block text-sm font-semibold text-slate-700">Page No.</label>
                                <input type="text" id="page_no" name="page_no" placeholder="Enter page no." value="<?php echo htmlspecialchars($old_data['page_no'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="quantity" class="mb-2 block text-sm font-semibold text-slate-700">Quantity</label>
                                <div class="flex gap-2">
                                    <input type="number" id="quantity" name="quantity" min="1" value="<?php echo htmlspecialchars($old_data['quantity'] ?? '1'); ?>" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                    <select id="unit" name="unit" class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200 w-32">
                                        <option value="pcs" <?php echo (($old_data['unit'] ?? '') == 'pcs') ? 'selected' : ''; ?>>pcs</option>
                                        <option value="mtr" <?php echo (($old_data['unit'] ?? '') == 'mtr') ? 'selected' : ''; ?>>mtr</option>
                                        <option value="liter" <?php echo (($old_data['unit'] ?? '') == 'liter') ? 'selected' : ''; ?>>liter</option>
                                        <option value="box" <?php echo (($old_data['unit'] ?? '') == 'box') ? 'selected' : ''; ?>>box</option>
                                        <option value="kg" <?php echo (($old_data['unit'] ?? '') == 'kg') ? 'selected' : ''; ?>>kg</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="item_no" class="mb-2 block text-sm font-semibold text-slate-700">Item No</label>
                                <input type="number" id="item_no" name="item_no" min="1" value="<?php echo htmlspecialchars($old_data['item_no'] ?? ''); ?>" placeholder="Auto-assigned on category select" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                <p class="mt-2 text-xs text-slate-500">Enter a unique Item No for selected category.</p>
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
                                <label for="borrow_date" class="mb-2 block text-sm font-semibold text-slate-700">Borrow Date</label>
                                <input type="date" id="borrow_date" name="borrow_date" value="<?php echo htmlspecialchars($old_data['borrow_date'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="return_date" class="mb-2 block text-sm font-semibold text-slate-700">Return Date <span class="text-xs font-normal text-slate-400"></span></label>
                                <input type="date" id="return_date" name="return_date" value="<?php echo htmlspecialchars($old_data['return_date'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="borrowed_from" class="mb-2 block text-sm font-semibold text-slate-700">Department Name (Borrow From)</label>
                                <input type="text" id="borrowed_from" name="borrowed_from" list="borrowed_from_list" placeholder="e.g. MECH" value="<?php echo htmlspecialchars($old_data['borrowed_from'] ?? ''); ?>" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                <datalist id="borrowed_from_list">
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <!-- <p class="mt-2 text-xs text-slate-500">Choose a previously used department or type a new one.</p> -->
                            </div>

                            <div>
                                <label for="assigned_to" class="mb-2 block text-sm font-semibold text-slate-700">Assign to Faculty</label>
                                <select id="assigned_to" name="assigned_to" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                    <option value="">Loading faculty...</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="remarks" class="mb-2 block text-sm font-semibold text-slate-700">Remarks</label>
                            <textarea id="remarks" name="remarks" rows="3" placeholder="Enter any specific condition or notes..." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200"><?php echo htmlspecialchars($old_data['remarks'] ?? ''); ?></textarea>
                        </div>

                        <div>
                            <label for="asset_no" class="mb-2 block text-sm font-semibold text-slate-700">Asset No</label>
                            <textarea id="asset_no" name="asset_no" rows="4" placeholder="KDP/DEPTNAME/2026/EXP/P-12/I-10/1/3" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required><?php echo htmlspecialchars($old_data['asset_no'] ?? ''); ?></textarea>
                            <p class="mt-2 text-xs text-slate-500">Use the format KDP/DEPTNAME/YYYY/CATEGORY/P-PAGE/I-ITEM/SEQ/QTY.</p>
                        </div>
                    </div>

                    <div class="lg:col-span-2 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="dashboard.php?view=loaned-assets" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                            Save Borrowed Asset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<script>
    const borrowedAssetForm = document.getElementById('borrowedAssetForm');
    const quantityInput = document.getElementById('quantity');
    const categoryInput = document.getElementById('category');
    const itemNoInput = document.getElementById('item_no');
    const assetNoInput = document.getElementById('asset_no');
    const locationSelect = document.getElementById('location');
    const customLocationInput = document.getElementById('custom_location');
    const customLocationWrapper = document.getElementById('custom_location_wrapper');
    const addLocationButton = document.getElementById('add_location_btn');
    const assignedToSelect = document.getElementById('assigned_to');
    const borrowDateInput = document.getElementById('borrow_date');
    const returnDateInput = document.getElementById('return_date');
    const borrowedFromInput = document.getElementById('borrowed_from');
    const locationStorageKey = 'kd_polytechnic_saved_locations';
    const oldLocation = <?php echo json_encode($old_data['location'] ?? ''); ?>;
    const oldAssignedTo = <?php echo json_encode($old_data['assigned_to'] ?? ''); ?>;

    function getCategoryCode(value) {
        const categoryMap = {
            '1': 'EXP',
            '2': 'CON',
            '3': 'DST',
            '4': 'FUR'
        };
        return categoryMap[value] || '';
    }

    function getDeptCode(value) {
        if (!value) return 'DEPT';
        return value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 10) || 'DEPT';
    }

    function updateAssetNo() {
        const categoryValue = categoryInput.value;
        const categoryCode = getCategoryCode(categoryValue);
        const deptCode = getDeptCode(borrowedFromInput.value);
        const year = new Date().getFullYear();
        const pageNo = document.getElementById('page_no').value.trim();
        const quantity = parseInt(quantityInput.value, 10) || 1;
        const startItemNo = parseInt(itemNoInput.value, 10) || 1;

        if (categoryCode && deptCode) {
            const generatedNumbers = [];
            for (let index = 0; index < quantity; index++) {
                const pageSuffix = pageNo ? `P-${pageNo}` : 'P-';
                generatedNumbers.push(`KDP/${deptCode}/${year}/${categoryCode}/${pageSuffix}/I-${startItemNo}/${index + 1}/${quantity}`);
            }
            assetNoInput.value = generatedNumbers.join('\n');
        } else {
            assetNoInput.value = '';
        }
    }

    function isValidAssetNumber(value) {
        const pattern = /^KDP\/[A-Z0-9]+\/\d{4}\/(EXP|CON|DST|FUR)\/P-[A-Za-z0-9.-]*\/I-\d+\/\d+\/\d+$/i;
        const entries = value.split(/\r?\n|,/).map(entry => entry.trim()).filter(Boolean);

        if (entries.length === 0) {
            return false;
        }

        return entries.every(entry => pattern.test(entry));
    }

    function getSavedLocations() {
        try {
            return JSON.parse(localStorage.getItem(locationStorageKey)) || [];
        } catch (error) {
            return [];
        }
    }

    function populateLocationOptions() {
        const savedLocations = getSavedLocations();
        const currentValue = locationSelect.value;

        let optionsHTML = `
            <option value="">Select location</option>
            
            <optgroup label="Ground Floor">
                <option value="F001 - STAFF ROOM">F001 - STAFF ROOM</option>
                <option value="F002 - HOD OFFICE">F002 - HOD OFFICE</option>
                <option value="F003 - CLASS ROOM - 1">F003 - CLASS ROOM - 1</option>
                <option value="F004 - CLASS ROOM - 2">F004 - CLASS ROOM - 2</option>
                <option value="F005 - TRAINING AND PLACEMENT ROOM">F005 - TRAINING AND PLACEMENT ROOM</option>
                <option value="F006 - SERVER ROOM">F006 - SERVER ROOM</option>
                <option value="F007 - BASIC PROGRAMMING LAB">F007 - BASIC PROGRAMMING LAB</option>
                <option value="F008 - ELECTRIC ROOM">F008 - ELECTRIC ROOM</option>
                <option value="F009 - DRINKING WATER, TOILET">F009 - DRINKING WATER, TOILET</option>
                <option value="F010 - ADVANCE PROGRAMMING LAB">F010 - ADVANCE PROGRAMMING LAB</option>
                <option value="F011 - DATABASE PROGRAMMING LAB">F011 - DATABASE PROGRAMMING LAB</option>
                <option value="F012 - WEB DEVELOPMENT LAB">F012 - WEB DEVELOPMENT LAB</option>
            </optgroup>

            <optgroup label="First Floor">
                <option value="F101 - DEPARTMENT LIBRARY">F101 - DEPARTMENT LIBRARY</option>
                <option value="F102 - COMPUTER NETWORK LAB">F102 - COMPUTER NETWORK LAB</option>
                <option value="F103 - COMPUTER MAINTENANCE LAB">F103 - COMPUTER MAINTENANCE LAB</option>
                <option value="F104 - BASIC ELECTRONICS LAB">F104 - BASIC ELECTRONICS LAB</option>
                <option value="F105 - ELECTRIC ROOM">F105 - ELECTRIC ROOM</option>
                <option value="F106 - DRINKING WATER, TOILET">F106 - DRINKING WATER, TOILET</option>
                <option value="F107 - SEMINAR HALL">F107 - SEMINAR HALL</option>
                <option value="F108 - ADVANCE WEB DEVELOPMENT LAB">F108 - ADVANCE WEB DEVELOPMENT LAB</option>
                <option value="F109 - CONFERENCE ROOM">F109 - CONFERENCE ROOM</option>
                <option value="F110 - STAFF ROOM">F110 - STAFF ROOM</option>
                <option value="F111 - CLASS ROOM - 3">F111 - CLASS ROOM - 3</option>
                <option value="F112 - CLASS ROOM - 4">F112 - CLASS ROOM - 4</option>
            </optgroup>
        `;

        if (savedLocations.length > 0) {
            optionsHTML += `<optgroup label="Custom Locations">`;
            savedLocations.forEach(location => {
                optionsHTML += `<option value="${location}">${location}</option>`;
            });
            optionsHTML += `</optgroup>`;
        }

        optionsHTML += `<option value="__other__">Other</option>`;
        
        locationSelect.innerHTML = optionsHTML;

        const valueToSelect = currentValue || oldLocation;
        if (valueToSelect && Array.from(locationSelect.options).some(option => option.value === valueToSelect)) {
            locationSelect.value = valueToSelect;
        } else if (valueToSelect === '__other__') {
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

    [quantityInput, categoryInput, itemNoInput].forEach(input => {
        input.addEventListener('input', () => {
            updateAssetNo();
        });
        input.addEventListener('change', () => {
            updateAssetNo();
        });
    });

    document.getElementById('page_no').addEventListener('input', updateAssetNo);
    borrowedFromInput.addEventListener('input', updateAssetNo);

    locationSelect.addEventListener('change', toggleCustomLocationInput);
    addLocationButton.addEventListener('click', addCustomLocation);
    customLocationInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addCustomLocation();
        }
    });

    borrowedAssetForm.addEventListener('submit', function (event) {
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
            document.getElementById('page_no'),
            quantityInput,
            itemNoInput,
            assetNoInput,
            borrowDateInput,
            document.getElementById('borrowed_from')
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
            alert('Asset No must be in the format KDP/DEPTNAME/YYYY/CATEGORY/P-PAGE/I-ITEM/SEQ/QTY.');
            return;
        }

        if (parseFloat(quantityInput.value) < 1) {
            event.preventDefault();
            alert('Quantity must be at least 1.');
            return;
        }

        if (borrowDateInput.value && returnDateInput.value && borrowDateInput.value > returnDateInput.value) {
            event.preventDefault();
            alert('Borrow Date cannot be after Return Date.');
            return;
        }
    });

    fetch('get-faculty.php')
        .then(response => response.json())
        .then(allNames => {
            assignedToSelect.innerHTML = '<option value="">Select faculty</option>';

            allNames.forEach(name => {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                assignedToSelect.appendChild(option);
            });

            if (oldAssignedTo) {
                assignedToSelect.value = oldAssignedTo;
            }
        })
        .catch(() => {
            assignedToSelect.innerHTML = '<option value="">Could not load faculty list</option>';
        });

    populateLocationOptions();
    toggleCustomLocationInput();
    updateAssetNo();
<?php if (!$embedMode): ?>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
<?php endif; ?>
</script>
<?php if (!$embedMode) { ?>
</body>
</html>
<?php } ?>