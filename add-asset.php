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

require_once 'notification_utils.php';

$suggested_page_nos = [];
for ($category_id = 1; $category_id <= 4; $category_id++) {
    $suggested_page_nos[$category_id] = 1;

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
    $unit = !empty($_POST['unit']) ? trim($_POST['unit']) : 'pcs';
    $item_number = (int)$_POST['item_no'];
    $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0.00;
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

    if ($page_no === null || $page_no === '') {
        header("Location: dashboard.php?view=add-asset&status=error&message=" . urlencode('Page No is required.'));
        exit();
    }

    // Generate a unique batch ID for this entire submission
    $batch_id = uniqid('batch_', true);

    $asset_numbers = [];
    $posted_asset_numbers = trim($_POST['asset_no'] ?? '');
    $asset_number_pattern = '/^KDP\/COMP\/\d{4}\/(EXP|CON|DST|FUR)\/P-[A-Za-z0-9.-]*\/I-\d+\/\d+\/\d+$/i';

    if ($posted_asset_numbers !== '') {
        $asset_numbers = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r|,/', $posted_asset_numbers)), function ($value) {
            return $value !== '';
        }));

        foreach ($asset_numbers as $asset_no_value) {
            if (!preg_match($asset_number_pattern, $asset_no_value)) {
                header("Location: dashboard.php?view=add-asset&status=error&message=" . urlencode('Asset No must be in the format KDP/COMP/YYYY/CATEGORY/P-PAGE/I-ITEM/SEQ/QTY.'));
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
                asset_name, category_id, quantity, unit, item_no, asset_no, cost, location, date_of_issue, assigned_to, remarks, batch_id,
                page_no, gem_order_no, gpr_no, pr_page_no, gpr_item_no, gem_invoice_no
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $row_quantity = 1;
        $stmt->bind_param(
            "sissisdsssssssssss",
            $asset_name,
            $category_id,
            $row_quantity,
            $unit,
            $row_item_number,
            $asset_no_value,
            $cost,
            $location,
            $date_of_issue,
            $assigned_to,
            $remarks,
            $batch_id,
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
            $error_message = $stmt->error;
            $stmt->close();
            // AJAX request: return JSON error
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => $error_message]);
                exit();
            }
            header("Location: dashboard.php?view=add-asset&status=error&message=" . urlencode($error_message));
            exit();
        }

        $stmt->close();
    }

    if ($inserted) {
        // --- START NOTIFICATION LOGIC ---
            if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
                $adder_name = htmlspecialchars($_SESSION['user_name']);
                $asset_name_str = htmlspecialchars($asset_name);
                $message = "{$adder_name} added a new asset: '{$asset_name_str}' (Qty: {$asset_count}).";
                $link = "view-assets.php?category_id=" . $category_id;
                create_admin_notification($conn, $message, $link, $_SESSION['user_id'] ?? null);
            }

            if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && $assigned_to !== null) {
                $faculty_user_id = get_user_id_by_name($conn, $assigned_to);
                if ($faculty_user_id !== null) {
                    $notified_user = htmlspecialchars($assigned_to);
                    $notif_message = "New asset '" . htmlspecialchars($asset_name) . "' assigned to you by " . htmlspecialchars($_SESSION['user_name']) . ".";
                    $notif_link = "view-batch-details.php?batch_id=" . urlencode($batch_id) . "&category_id=" . $category_id . "&asset_name=" . urlencode($asset_name);
                    create_notification($conn, $faculty_user_id, $notif_message, $notif_link);
                }
            }
            // --- END NOTIFICATION LOGIC ---

        // AJAX request: return JSON success (no redirect)
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true, 'category_id' => $category_id, 'asset_name' => $asset_name]);
            exit();
        }
        header("Location: view-assets.php?category_id=" . $category_id . "&status=asset_added");
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No asset numbers were generated.']);
            exit();
        }
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

                <!-- ===== OCR Feature Section ===== -->
                <div class="mb-6 rounded-xl border border-blue-100 bg-blue-50 p-5">
                    <h3 class="mb-1 text-lg font-bold text-blue-900">&#128444; Auto-fill from Register Photo (OCR)</h3>
                    <p class="mb-4 text-sm text-blue-700">Upload a photo of the physical register. Gemini AI will extract each row automatically so you can review and submit one by one.</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="file" id="ocrImage" accept="image/*" class="block text-sm text-slate-500 file:mr-3 file:rounded-md file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700">
                        <button type="button" id="ocrExtractBtn" class="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-blue-700 disabled:opacity-60">Extract Data</button>
                    </div>
                    <div id="ocrStatus" class="mt-3 hidden text-sm font-medium"></div>
                    <div id="ocrNavigation" class="mt-4 hidden items-center justify-between border-t border-blue-200 pt-3">
                        <div class="text-sm font-semibold text-blue-900">Row: <span id="ocrCurrentIndex">0</span> / <span id="ocrTotalRows">0</span></div>
                        <div class="space-x-2">
                            <button type="button" id="ocrPrevBtn" class="rounded border border-blue-300 px-3 py-1 text-sm font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-40 disabled:cursor-not-allowed">&#8592; Previous</button>
                            <button type="button" id="ocrNextBtn" class="rounded bg-blue-600 px-3 py-1 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed">Next &#8594;</button>
                        </div>
                    </div>
                </div>
                <!-- ===== End OCR Feature Section ===== -->

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
                                <input type="text" id="page_no" name="page_no" placeholder="Enter page no." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                <p class="mt-2 text-xs text-slate-500">Auto-suggests the next page for selected category. You can edit it.</p>
                            </div>

                            <div>
                                <label for="quantity" class="mb-2 block text-sm font-semibold text-slate-700">Quantity</label>
                                <div class="flex gap-2">
                                    <input type="number" id="quantity" name="quantity" min="1" value="1" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                    <select id="unit" name="unit" class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200 w-32">
                                        <option value="pcs">pcs</option>
                                        <option value="mtr">mtr</option>
                                        <option value="liter">liter</option>
                                        <option value="box">box</option>
                                        <option value="kg">kg</option>
                                    </select>
                                </div>
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
                                <input type="number" id="cost" name="cost" step="0.01" placeholder="₹ Amount (optional)" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
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
                            <textarea id="asset_no" name="asset_no" rows="4" placeholder="KDP/COMP/2026/EXP/P-12/I-10/1/3" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required></textarea>
                            <p class="mt-2 text-xs text-slate-500">Use the format KDP/COMP/YYYY/CATEGORY/P-PAGE/I-ITEM/SEQ/QTY.</p>
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
    const suggestedPageNos = <?php echo json_encode($suggested_page_nos); ?>;

    function getCategoryCode(value) {
        const categoryMap = {
            '1': 'EXP',
            '2': 'CON',
            '3': 'DST',
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
                const pageSuffix = pageNo ? `P-${pageNo}` : 'P-';
                generatedNumbers.push(`KDP/COMP/${year}/${categoryCode}/${pageSuffix}/I-${currentItemNo}/${index + 1}/${quantity}`);
            }
            assetNoInput.value = generatedNumbers.join('\n');
        } else {
            assetNoInput.value = '';
        }
    }

    function updateSuggestedPageNo() {
        const categoryValue = categoryInput.value;
        const suggestedPageNo = suggestedPageNos[categoryValue];

        if (suggestedPageNo) {
            document.getElementById('page_no').value = suggestedPageNo;
        } else {
            document.getElementById('page_no').value = '';
        }

        updateAssetNo();
    }

    function isValidAssetNumber(value) {
        const pattern = /^KDP\/COMP\/\d{4}\/(EXP|CON|DST|FUR)\/P-[A-Za-z0-9.-]*\/I-\d+\/\d+\/\d+$/i;
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
    categoryInput.addEventListener('change', updateSuggestedPageNo);

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
            document.getElementById('page_no'),
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
            alert('Asset No must be in the format KDP/COMP/YYYY/CATEGORY/P-PAGE/I-ITEM/SEQ/QTY.');
            return;
        }

        if (parseFloat(quantityInput.value) < 1) {
            event.preventDefault();
            alert('Quantity must be at least 1.');
            return;
        }

        // cost is optional — no validation needed
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
        })
        .catch(() => {
            assignedToSelect.innerHTML = '<option value="">Could not load faculty list</option>';
        });

    populateLocationOptions();
    toggleCustomLocationInput();
    updateAssetNo();

    // ===== OCR Logic =====
    let ocrRows = [];
    let ocrIndex = 0;

    const ocrImageInput  = document.getElementById('ocrImage');
    const ocrExtractBtn  = document.getElementById('ocrExtractBtn');
    const ocrStatus      = document.getElementById('ocrStatus');
    const ocrNavigation  = document.getElementById('ocrNavigation');
    const ocrCurrentSpan = document.getElementById('ocrCurrentIndex');
    const ocrTotalSpan   = document.getElementById('ocrTotalRows');
    const ocrPrevBtn     = document.getElementById('ocrPrevBtn');
    const ocrNextBtn     = document.getElementById('ocrNextBtn');

    ocrExtractBtn.addEventListener('click', async () => {
        if (!ocrImageInput.files[0]) { alert('Please select an image first.'); return; }
        ocrStatus.className = 'mt-3 text-sm font-medium text-blue-600';
        ocrStatus.textContent = 'Analysing image with Gemini AI... please wait.';
        ocrStatus.classList.remove('hidden');
        ocrExtractBtn.disabled = true;

        const reader = new FileReader();
        reader.onloadend = async () => {
            try {
                const res  = await fetch('gemini-ocr.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image: reader.result })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'API error');
                if (!Array.isArray(data) || data.length === 0) throw new Error('No rows found in the image.');

                ocrRows  = data;
                ocrIndex = 0;
                ocrStatus.className = 'mt-3 text-sm font-medium text-green-600';
                ocrStatus.textContent = `✓ Extracted ${ocrRows.length} row(s). Review and submit each one below.`;
                ocrTotalSpan.textContent = ocrRows.length;
                ocrNavigation.classList.remove('hidden');
                ocrNavigation.style.display = 'flex';
                loadOcrRow(0);
            } catch (e) {
                ocrStatus.className = 'mt-3 text-sm font-medium text-red-600';
                ocrStatus.textContent = 'Error: ' + e.message;
            } finally {
                ocrExtractBtn.disabled = false;
            }
        };
        reader.readAsDataURL(ocrImageInput.files[0]);
    });

    function loadOcrRow(idx) {
        const row = ocrRows[idx];
        if (!row) return;
        ocrCurrentSpan.textContent = idx + 1;
        ocrPrevBtn.disabled = idx === 0;
        ocrNextBtn.disabled = idx === ocrRows.length - 1;

        // Step 1: Set category first (triggers default page suggestion)
        if (row.category) {
            const cat = row.category.toLowerCase();
            const categoryMap = { 'consumables': '2', 'consumable': '2', 'expandable': '1', 'deadstock': '3', 'dead stock': '3', 'furniture': '4' };
            const catValue = categoryMap[cat] || '';
            if (catValue) {
                document.getElementById('category').value = catValue;
                updateSuggestedPageNo();
            }
        }

        // Step 2: Override all fields from OCR (page_no overrides the auto-suggestion)
        if (row.asset_name)    document.getElementById('asset_name').value = row.asset_name;
        if (row.quantity)      document.getElementById('quantity').value = String(row.quantity).replace(/[^0-9]/g, '') || '1';

        if (row.date_of_issue) {
            let rawDate = String(row.date_of_issue).trim();
            let formattedDate = '';
            let parts = rawDate.split(/[-\/]/);
            if (parts.length === 3) {
                let day = parts[0].padStart(2,'0'), month = parts[1].padStart(2,'0'), year = parts[2];
                if (year.length === 2) year = '20' + year;
                if (year.length === 4) formattedDate = `${year}-${month}-${day}`;
            }
            const target = /^\d{4}-\d{2}-\d{2}$/.test(formattedDate) ? formattedDate : (/^\d{4}-\d{2}-\d{2}$/.test(rawDate) ? rawDate : '');
            if (target) document.getElementById('date_of_issue').value = target;
        }

        if (row.remarks)       document.getElementById('remarks').value = row.remarks;
        if (row.pr_page_no)    document.getElementById('pr_page_no').value = row.pr_page_no;
        document.getElementById('gem_order_no').value = '';  // always blank

        // page_no from top-right corner — overrides auto-suggestion
        if (row.page_no)       document.getElementById('page_no').value = String(row.page_no).trim();

        if (row.item_no)       document.getElementById('item_no').value = String(row.item_no).replace(/[^0-9]/g, '');

        if (row.gem_invoice_no) {
            let billNo = String(row.gem_invoice_no).trim();
            let cleanBill = billNo.replace(/\b(?:dt|date)\b.*$/i, '').trim();
            cleanBill = cleanBill.replace(/^(?:Bill\s*No\.?\s*)/i, '').trim();
            document.getElementById('gem_invoice_no').value = cleanBill;
        }

        // Set unit selector
        const unitEl = document.getElementById('unit');
        if (unitEl) {
            const uv = row.unit ? String(row.unit).trim().toLowerCase() : 'pcs';
            const allowed = ['pcs','mtr','liter','box','kg'];
            if (allowed.includes(uv)) { unitEl.value = uv; }
            else if (uv.includes('meter')||uv.includes('mtr')) { unitEl.value = 'mtr'; }
            else if (uv.includes('litre')||uv.includes('liter')||uv.includes('ltr')) { unitEl.value = 'liter'; }
            else { unitEl.value = 'pcs'; }
        }

        // Cost = opening_balance / quantity
        if (row.opening_balance) {
            const openBal = parseFloat(String(row.opening_balance).replace(/[^0-9.]/g,''));
            const qty     = parseInt(String(row.quantity).replace(/[^0-9]/g,'')) || 1;
            if (!isNaN(openBal) && qty > 0) document.getElementById('cost').value = (openBal/qty).toFixed(2);
        }

        if (row.location) {
            const opts  = Array.from(document.getElementById('location').options);
            const match = opts.find(o => o.value.toLowerCase().includes(row.location.toLowerCase()));
            if (match) document.getElementById('location').value = match.value;
        }

        updateAssetNo();
        calculateTotal();
    }

    ocrPrevBtn.addEventListener('click', () => { if (ocrIndex > 0)                   loadOcrRow(--ocrIndex); });
    ocrNextBtn.addEventListener('click', () => { if (ocrIndex < ocrRows.length - 1)  loadOcrRow(++ocrIndex); });

    // ===== OCR AJAX Submit — prevent redirect when OCR rows are active =====
    document.getElementById('assetForm').addEventListener('submit', async function(e) {
        // Only intercept if OCR mode is active (rows extracted)
        if (ocrRows.length === 0) return; // normal submit
        e.preventDefault();

        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving...'; }

        try {
            const formData = new FormData(this);
            const res = await fetch('add-asset.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                // Show green toast
                showOcrToast(`✓ Row ${ocrIndex + 1} saved: ${data.asset_name}`, 'green');

                // Auto-advance to next row, or show completion
                if (ocrIndex < ocrRows.length - 1) {
                    loadOcrRow(++ocrIndex);
                } else {
                    showOcrToast('🎉 All rows saved! Redirecting...', 'green');
                    setTimeout(() => { window.location.href = 'view-assets.php?category_id=' + encodeURIComponent(document.getElementById('category').value) + '&status=asset_added'; }, 1500);
                }
            } else {
                showOcrToast('Error: ' + (data.message || 'Unknown error'), 'red');
            }
        } catch (err) {
            showOcrToast('Network error: ' + err.message, 'red');
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
        }
    });

    function showOcrToast(msg, color) {
        let toast = document.getElementById('ocrToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'ocrToast';
            toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:14px 22px;border-radius:10px;font-size:0.9rem;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,0.15);transition:opacity 0.4s;max-width:360px;';
            document.body.appendChild(toast);
        }
        toast.style.background  = color === 'green' ? '#16a34a' : '#dc2626';
        toast.style.color       = '#fff';
        toast.style.opacity     = '1';
        toast.textContent       = msg;
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 3000);
    }
    // ===== End OCR Logic =====
</script>
<?php if (!$embedMode) { ?>
</body>
</html>
<?php } ?>
