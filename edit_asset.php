<?php
require 'db.php';

// Extract and validate asset ID
$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($asset_id <= 0) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Invalid asset ID"));
    exit();
}

// Initialize variables
$asset = null;
$error_message = '';
$success_message = '';

// Fetch asset from database
$stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
if (!$stmt) {
    header("Location: dashboard.php?status=error&message=" . urlencode("Database error"));
    exit();
}

$stmt->bind_param("i", $asset_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: category_list.php?status=error&message=" . urlencode("Asset not found"));
    exit();
}

$asset = $result->fetch_assoc();
$stmt->close();

$is_embedded = isset($_GET['embed']) && $_GET['embed'] === '1';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract and validate form data
    $asset_name = isset($_POST['asset_name']) ? trim($_POST['asset_name']) : '';
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    $item_no = isset($_POST['item_no']) ? trim($_POST['item_no']) : '';
    $page_no = isset($_POST['page_no']) ? trim($_POST['page_no']) : '';
    $gem_order_no = isset($_POST['gem_order_no']) ? trim($_POST['gem_order_no']) : '';
    $gem_invoice_no = isset($_POST['gem_invoice_no']) ? trim($_POST['gem_invoice_no']) : '';
    $gpr_no = isset($_POST['gpr_no']) ? trim($_POST['gpr_no']) : '';
    $pr_page_no = isset($_POST['pr_page_no']) ? trim($_POST['pr_page_no']) : '';
    $gpr_item_no = isset($_POST['gpr_item_no']) ? trim($_POST['gpr_item_no']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $date_of_issue = isset($_POST['date_of_issue']) ? trim($_POST['date_of_issue']) : '';
    $cost = isset($_POST['cost']) ? (float)$_POST['cost'] : 0;
    $assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    // Validate required fields
    if (empty($asset_name)) {
        $error_message = "Asset name is required";
    } else {
        // Update the asset in database with prepared statement
        $update_stmt = $conn->prepare(
            "UPDATE assets SET 
                asset_name = ?, category_id = ?, quantity = ?, item_no = ?, 
                page_no = ?, gem_order_no = ?, gem_invoice_no = ?, gpr_no = ?, 
                pr_page_no = ?, gpr_item_no = ?, location = ?, date_of_issue = ?, 
                cost = ?, assigned_to = ?, remarks = ? 
            WHERE id = ?"
        );

        if (!$update_stmt) {
            $error_message = "Database error: " . $conn->error;
        } else {
            $update_stmt->bind_param(
                "siisssssssssdssi",
                $asset_name,
                $category_id,
                $quantity,
                $item_no,
                $page_no,
                $gem_order_no,
                $gem_invoice_no,
                $gpr_no,
                $pr_page_no,
                $gpr_item_no,
                $location,
                $date_of_issue,
                $cost,
                $assigned_to,
                $remarks,
                $asset_id
            );

            if ($update_stmt->execute()) {
                if ($is_embedded) {
                    echo json_encode(['status' => 'success', 'message' => 'Asset updated successfully']);
                    exit();
                }
                // Update successful - redirect back to asset details page
                header("Location: category_list.php?id=" . (int)$asset_id . "&status=success&message=" . urlencode("Asset updated successfully"));
                exit();
            } else {
                if ($is_embedded) {
                    echo json_encode(['status' => 'error', 'message' => 'Error updating asset: ' . $update_stmt->error]);
                    exit();
                }
                $error_message = "Error updating asset: " . $update_stmt->error;
            }
            $update_stmt->close();
        }
    }
}
?>

<?php if (!$is_embedded): ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Edit Asset - <?php echo htmlspecialchars($asset['asset_name']); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

            * {
                box-sizing: border-box;
            }

            html {
                scroll-behavior: smooth;
            }

            body {
                font-family: 'Inter', sans-serif;
                background-color: #f8fafc;
                overflow-x: hidden;
            }

            img,
            svg {
                max-width: 100%;
                height: auto;
                display: block;
            }

            button,
            input,
            select,
            textarea {
                font: inherit;
            }
        </style>
    </head>

    <body class="text-slate-800 flex flex-col min-h-screen">
    <?php endif; ?>

    <!-- Header Section -->
    <div class="bg-white border-b border-slate-200 px-4 sm:px-6 py-4 sm:py-5 flex flex-wrap justify-between items-start sm:items-center gap-3 sticky top-0 z-10 shadow-sm">
        <div class="min-w-0">
            <h1 class="text-lg sm:text-xl font-bold text-[#0f172a]">Edit Asset Details</h1>
            <p class="text-sm text-slate-500 mt-1 break-all">Updating information for <?php echo htmlspecialchars($asset['asset_name']); ?></p>
        </div>
        <button type="button" onclick="closeEditView()" class="text-slate-400 hover:text-slate-600 transition-colors flex-shrink-0" title="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <!-- Error Messaging -->
    <?php if ($error_message): ?>
        <div class="bg-red-50 border border-red-200 p-4 sm:p-6 mx-4 sm:mx-6 mt-4 rounded-lg">
            <p class="text-red-800 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <!-- Form Section -->
    <div class="flex-grow p-4 sm:p-6 overflow-y-auto">
        <form id="editAssetForm" method="POST" action="edit_asset.php?id=<?php echo (int)$asset_id; ?>&embed=1" class="max-w-3xl mx-auto space-y-6">
            <input type="hidden" name="asset_id" value="<?php echo (int)$asset_id; ?>">

            <!-- Row 1: Asset Name | Asset No. -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <label for="assetName" class="block text-sm font-medium text-slate-700 mb-1">Asset Name</label>
                    <input type="text" id="assetName" name="asset_name" value="<?php echo htmlspecialchars($asset['asset_name']); ?>" required
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="assetNo" class="block text-sm font-medium text-slate-700 mb-1">Asset No.</label>
                    <input type="text" id="assetNo" value="<?php echo htmlspecialchars($asset['asset_no'] ?? ''); ?>" disabled
                        class="w-full px-4 py-2 bg-slate-100 border border-slate-200 text-slate-500 rounded-lg cursor-not-allowed">
                </div>
            </div>

            <!-- Row 2: Category | Quantity -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <label for="category" class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                    <select id="category" name="category_id"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all bg-white">
                        <option value="1" <?php echo $asset['category_id'] == 1 ? 'selected' : ''; ?>>Expandable</option>
                        <option value="2" <?php echo $asset['category_id'] == 2 ? 'selected' : ''; ?>>Consumables</option>
                        <option value="3" <?php echo $asset['category_id'] == 3 ? 'selected' : ''; ?>>Furniture</option>
                        <option value="4" <?php echo $asset['category_id'] == 4 ? 'selected' : ''; ?>>Deadstock</option>
                    </select>
                </div>
                <div>
                    <label for="quantity" class="block text-sm font-medium text-slate-700 mb-1">Quantity</label>
                    <input type="number" id="quantity" name="quantity" value="<?php echo htmlspecialchars($asset['quantity']); ?>" min="0"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
            </div>

            <!-- Row 3: Page No. | Item No. -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <label for="pageNo" class="block text-sm font-medium text-slate-700 mb-1">Page No.</label>
                    <input type="text" id="pageNo" name="page_no" value="<?php echo htmlspecialchars($asset['page_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="itemNo" class="block text-sm font-medium text-slate-700 mb-1">Item No.</label>
                    <input type="text" id="itemNo" name="item_no" value="<?php echo htmlspecialchars($asset['item_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
            </div>

            <!-- Row 4: Gem Order No. | Gem Invoice No. -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <label for="gemOrderNo" class="block text-sm font-medium text-slate-700 mb-1">Gem Order No.</label>
                    <input type="text" id="gemOrderNo" name="gem_order_no" value="<?php echo htmlspecialchars($asset['gem_order_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="gemInvoiceNo" class="block text-sm font-medium text-slate-700 mb-1">Gem Invoice No.</label>
                    <input type="text" id="gemInvoiceNo" name="gem_invoice_no" value="<?php echo htmlspecialchars($asset['gem_invoice_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
            </div>

            <!-- Row 5: GPR No. | GPR Page No. | GPR Item No. -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 sm:gap-6">
                <div class="md:col-span-2">
                    <label for="gprNo" class="block text-sm font-medium text-slate-700 mb-1">GPR No.</label>
                    <input type="text" id="gprNo" name="gpr_no" value="<?php echo htmlspecialchars($asset['gpr_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="gprPageNo" class="block text-sm font-medium text-slate-700 mb-1">GPR Page No.</label>
                    <input type="text" id="gprPageNo" name="pr_page_no" value="<?php echo htmlspecialchars($asset['pr_page_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="gprItemNo" class="block text-sm font-medium text-slate-700 mb-1">GPR Item No.</label>
                    <input type="text" id="gprItemNo" name="gpr_item_no" value="<?php echo htmlspecialchars($asset['gpr_item_no']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
            </div>

            <!-- Row 6: Location | Date of Issue -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <label for="location" class="block text-sm font-medium text-slate-700 mb-1">Location</label>
                    <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($asset['location']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="dateIssue" class="block text-sm font-medium text-slate-700 mb-1">Date of Issue</label>
                    <input type="date" id="dateIssue" name="date_of_issue" value="<?php echo htmlspecialchars($asset['date_of_issue']); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
            </div>

            <!-- Row 7: Cost | Assign to Faculty -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <label for="cost" class="block text-sm font-medium text-slate-700 mb-1">Cost (₹)</label>
                    <input type="number" id="cost" name="cost" value="<?php echo htmlspecialchars($asset['cost']); ?>" step="0.01"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>
                <div>
                    <label for="assignedFaculty" class="block text-sm font-medium text-slate-700 mb-1">Assign to Faculty</label>
                    <select id="assignedFaculty" name="assigned_to"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all bg-white">
                        <option value="">Loading faculty...</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="remarks" class="block text-sm font-medium text-slate-700 mb-1">Remarks / Notes</label>
                <textarea id="remarks" name="remarks" rows="4"
                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all resize-none"><?php echo htmlspecialchars($asset['remarks']); ?></textarea>
            </div>
        </form>
    </div>

    <!-- Actions Section -->
    <div class="bg-white border-t border-slate-200 px-4 sm:px-6 py-4 flex flex-col-reverse sm:flex-row justify-end gap-3 sticky bottom-0 z-10">
        <button type="button" onclick="closeEditView()"
            class="px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors text-center w-full sm:w-auto">
            Cancel
        </button>
        <!-- Used 'form' attribute to natively submit without javascript hacks -->
        <button type="submit" form="editAssetForm"
            class="px-5 py-2.5 text-sm font-medium text-white bg-[#20347a] rounded-lg hover:bg-[#18275c] transition-colors flex items-center justify-center gap-2 w-full sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                <polyline points="7 3 7 8 15 8"></polyline>
            </svg>
            Save Changes
        </button>
    </div>

    <script>
        // Intelligent close behavior: handles standard navigation and modals safely
        function closeEditView() {
            if (typeof window.closeEditModal === 'function') {
                window.closeEditModal();
            } else {
                window.location.href = 'category_list.php?id=<?php echo (int)$asset_id; ?>';
            }
        }

        // Fetch faculty dropdown dynamically
        function initEditAssetForm() {
            const assignedToSelect = document.getElementById('assignedFaculty');
            const currentAssignedTo = <?php echo json_encode($asset['assigned_to'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            if (!assignedToSelect) return;

            fetch('get-faculty.php')
                .then(response => response.json())
                .then(data => {
                    assignedToSelect.innerHTML = '<option value="">Select faculty</option>';
                    data.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.full_name;
                        option.textContent = user.full_name;

                        if (user.full_name === currentAssignedTo) {
                            option.selected = true;
                        }

                        assignedToSelect.appendChild(option);
                    });
                })
                .catch(() => {
                    assignedToSelect.innerHTML = '<option value="">No faculty available</option>';
                });
            
            const form = document.getElementById('editAssetForm');
            if(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(form);
                    fetch(form.action, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            window.location.reload();
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while updating the asset.');
                    });
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initEditAssetForm);
        } else {
            initEditAssetForm();
        }
    </script>

    <?php if (!$is_embedded): ?>
    </body>

    </html>
<?php endif; ?>