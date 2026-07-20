<?php
$embedMode = isset($_GET['embed']) && $_GET['embed'] === '1';

$assetName = 'Logitech Wireless Keyboard';
$assetItemNo = 'KDP/COMP/2026/EXP/P-19/-125/10/30';
$assetCategoryValue = 'hardware';
$assetLocation = 'Lab F004';
$assetQuantity = '5';
$assetCost = '1250.50';
$assetDateIssue = '2026-07-13';
$assetStatusValue = 'active';
$assetRemarks = 'Issued to main lab. Excellent condition.';
$currentAssignedFaculty = 'Prof. A. Sharma';
?>
<?php if (!$embedMode) { ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Asset - <?php echo htmlspecialchars($assetItemNo); ?></title>
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
<?php } ?>

    <div class="bg-white border-b border-slate-200 px-4 sm:px-6 py-4 sm:py-5 flex flex-wrap justify-between items-start sm:items-center gap-3 sticky top-0 z-10 shadow-sm">
        <div class="min-w-0">
            <h1 class="text-lg sm:text-xl font-bold text-[#0f172a]">Edit Asset Details</h1>
            <p class="text-sm text-slate-500 mt-1 break-all">Updating information for <?php echo htmlspecialchars($assetItemNo); ?></p>
        </div>
        <button onclick="if (window.closeEditModal) { window.closeEditModal(); } else { window.location.href='category_list.php'; }"
            class="text-slate-400 hover:text-slate-600 transition-colors flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <div class="flex-grow p-4 sm:p-6 overflow-y-auto">
        <form id="editAssetForm" class="max-w-2xl mx-auto space-y-6">

            <div>
                <label for="assetName" class="block text-sm font-medium text-slate-700 mb-1">Asset Name</label>
                <input type="text" id="assetName" value="<?php echo htmlspecialchars($assetName); ?>"
                    class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">

                <div>
                    <label for="itemNo" class="block text-sm font-medium text-slate-700 mb-1">Item No
                        (Auto-generated)</label>
                    <input type="text" id="itemNo" value="<?php echo htmlspecialchars($assetItemNo); ?>" disabled
                        class="w-full px-4 py-2 bg-slate-100 border border-slate-200 text-slate-500 rounded-lg cursor-not-allowed">
                </div>

                <div>
                    <label for="category" class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                    <select id="category"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all bg-white">
                        <option value="hardware" <?php echo $assetCategoryValue === 'hardware' ? 'selected' : ''; ?>>Expandable</option>
                        <option value="consumables" <?php echo $assetCategoryValue === 'consumables' ? 'selected' : ''; ?>>Consumables</option>
                        <option value="furniture" <?php echo $assetCategoryValue === 'furniture' ? 'selected' : ''; ?>>Furniture</option>
                        <option value="software" <?php echo $assetCategoryValue === 'software' ? 'selected' : ''; ?>>Deadstock</option>
                    </select>
                </div>

                <div>
                    <label for="location" class="block text-sm font-medium text-slate-700 mb-1">Location</label>
                    <input type="text" id="location" value="<?php echo htmlspecialchars($assetLocation); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>

                <div>
                    <label for="quantity" class="block text-sm font-medium text-slate-700 mb-1">Quantity</label>
                    <input type="number" id="quantity" value="<?php echo htmlspecialchars($assetQuantity); ?>" min="0"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>

                <div>
                    <label for="cost" class="block text-sm font-medium text-slate-700 mb-1">Cost (₹)</label>
                    <input type="number" id="cost" value="<?php echo htmlspecialchars($assetCost); ?>" step="0.01"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>

                <div>
                    <label for="dateIssue" class="block text-sm font-medium text-slate-700 mb-1">Date of Issue</label>
                    <input type="date" id="dateIssue" value="<?php echo htmlspecialchars($assetDateIssue); ?>"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all">
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Asset Status</label>
                    <select id="status"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all bg-white">
                        <option value="active" <?php echo $assetStatusValue === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="maintenance" <?php echo $assetStatusValue === 'maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                        <option value="retired" <?php echo $assetStatusValue === 'retired' ? 'selected' : ''; ?>>Retired</option>
                    </select>
                </div>

                <div>
                    <label for="assignedFaculty" class="block text-sm font-medium text-slate-700 mb-1">Assigned to
                        Faculty</label>
                    <select id="assignedFaculty" name="assigned_to"
                        class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all bg-white">
                        <option value="">Loading faculty...</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="remarks" class="block text-sm font-medium text-slate-700 mb-1">Remarks / Notes</label>
                <textarea id="remarks" rows="4"
                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-[#1e3271] focus:border-[#1e3271] outline-none transition-all resize-none"><?php echo htmlspecialchars($assetRemarks); ?></textarea>
            </div>

        </form>
    </div>

    <div class="bg-white border-t border-slate-200 px-4 sm:px-6 py-4 flex flex-col-reverse sm:flex-row justify-end gap-3 sticky bottom-0">
        <button type="button" onclick="if (window.closeEditModal) { window.closeEditModal(); } else { window.location.href='category_list.php'; }"
            class="px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
            Cancel
        </button>
        <button type="button" onclick="saveAndClose()"
            class="px-5 py-2.5 text-sm font-medium text-white bg-[#20347a] rounded-lg hover:bg-[#18275c] transition-colors flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                <polyline points="7 3 7 8 15 8"></polyline>
            </svg>
            Save Changes
        </button>
    </div>

    <script>
        function initEditAssetForm() {
            const assignedToSelect = document.getElementById('assignedFaculty');
            const currentAssignedFaculty = <?php echo json_encode($currentAssignedFaculty, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            if (!assignedToSelect) {
                return;
            }

            fetch('get-faculty.php')
                .then(response => response.json())
                .then(data => {
                    assignedToSelect.innerHTML = '<option value="">Select faculty</option>';
                    data.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.full_name;
                        option.textContent = user.full_name;

                        if (user.full_name === currentAssignedFaculty) {
                            option.selected = true;
                        }

                        assignedToSelect.appendChild(option);
                    });
                })
                .catch(() => {
                    assignedToSelect.innerHTML = '<option value="">No faculty available</option>';
                });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initEditAssetForm);
        } else {
            initEditAssetForm();
        }

        function saveAndClose() {
            alert('Asset details updated successfully!');
            if (window.closeEditModal) {
                window.closeEditModal();
            } else if (window.opener && !window.opener.closed) {
                window.opener.location.reload();
                window.close();
            } else {
                window.close();
            }
        }
    </script>
</body>

</html>