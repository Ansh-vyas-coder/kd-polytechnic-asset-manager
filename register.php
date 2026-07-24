<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

$category_names = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

$category_register_titles = [
    1 => 'Departmental Stores Expandable Register',
    2 => 'Departmental Stores Consumables Register',
    3 => 'Departmental Stores Deadstock Register',
    4 => 'Departmental Stores Furniture Register',
];

// Fetch distinct pages for the selected category
$selectedCategory = isset($_GET['category']) && isset($category_names[(int)$_GET['category']]) ? (int)$_GET['category'] : 1;

// Fetch all pages grouped by category for dynamic dropdowns in the export modal
$export_pages = [];
for ($c_id = 1; $c_id <= 4; $c_id++) {
    $export_pages[$c_id] = [];
    $p_sql = "SELECT DISTINCT page_no FROM assets WHERE category_id = $c_id AND TRIM(COALESCE(page_no, '')) <> '' ORDER BY CAST(page_no AS UNSIGNED) ASC, page_no ASC";
    $p_res = $conn->query($p_sql);
    if ($p_res) {
        while ($p_row = $p_res->fetch_assoc()) {
            $export_pages[$c_id][] = $p_row['page_no'];
        }
    }
}

$groups = [];
$sql = "SELECT DISTINCT page_no AS group_value FROM assets WHERE category_id = " . (int)$selectedCategory . " AND TRIM(COALESCE(page_no, '')) <> '' ORDER BY CAST(page_no AS UNSIGNED) ASC, page_no ASC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $groupValue = $row['group_value'];

        // Group by batch_id — each batch = one physical register line
        $recordsSql = "
            SELECT
                MIN(id) as id,
                asset_name,
                category_id,
                SUM(quantity) as quantity,
                MIN(item_no) as item_no,
                GROUP_CONCAT(asset_no ORDER BY item_no ASC SEPARATOR '\n') as asset_no,
                cost,
                location,
                date_of_issue,
                assigned_to,
                remarks,
                batch_id,
                page_no,
                gem_order_no,
                gpr_no,
                pr_page_no,
                gpr_item_no,
                gem_invoice_no,
                COUNT(*) as total_items
            FROM assets
            WHERE category_id = " . (int)$selectedCategory . "
              AND page_no = '" . $conn->real_escape_string($groupValue) . "'
            GROUP BY batch_id
            ORDER BY MIN(item_no) ASC
        ";
        $recordsResult = $conn->query($recordsSql);
        $records = [];
        if ($recordsResult) {
            while ($asset = $recordsResult->fetch_assoc()) {
                $records[] = $asset;
            }
        }
        $groups[] = [
            'label'   => $groupValue !== null && $groupValue !== '' ? $groupValue : 'No Page No',
            'records' => $records
        ];
    }
}

if (empty($groups)) {
    $groups[] = ['label' => '-', 'records' => []];
}

$total_pages = count($groups);
$page_index  = isset($_GET['page_index']) ? (int)$_GET['page_index'] : 0;
if ($page_index < 0) $page_index = 0;
if ($page_index >= $total_pages) $page_index = max(0, $total_pages - 1);

$current_group = $groups[$page_index];
$register_title = $category_register_titles[$selectedCategory];
?>

<style>
    /* ---- Register Paper ---- */
    .reg-wrap {
        font-family: 'Times New Roman', Times, serif;
        background: #fff;
        color: #111;
    }

    /* Category tab bar */
    .reg-tabs { display: flex; gap: 0; flex-wrap: wrap; border-bottom: 2px solid #555; }
    .reg-tabs a {
        padding: 7px 18px;
        font-size: 0.82rem;
        font-weight: 600;
        border: 1px solid #aaa;
        border-bottom: none;
        border-radius: 6px 6px 0 0;
        background: #f0ede6;
        color: #444;
        text-decoration: none;
        margin-right: 3px;
        transition: background 0.15s;
    }
    .reg-tabs a.active-tab {
        background: #fff;
        color: #000;
        border-bottom: 2px solid #fff;
        margin-bottom: -2px;
    }

    /* Pagination */
    .reg-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #2c2c2c;
        color: #fff;
        padding: 8px 16px;
        font-size: 0.82rem;
        font-weight: 600;
        border-radius: 0 0 6px 6px;
        letter-spacing: 0.04em;
    }
    .reg-pagination a {
        color: #fff;
        text-decoration: none;
        padding: 4px 14px;
        background: #444;
        border-radius: 4px;
        transition: background 0.15s;
    }
    .reg-pagination a:hover { background: #666; }
    .reg-pagination a.disabled { opacity: 0.35; pointer-events: none; }

    /* The physical register sheet */
    .reg-sheet {
        background: #fffef7;
        border: 1px solid #bbb;
        border-radius: 0 0 6px 6px;
        overflow-x: auto;
        padding: 0 0 20px 0;
        /* Ruled lines */
        background-image: linear-gradient(#e8e0c8 1px, transparent 1px);
        background-size: 100% 32px;
        background-position: 0 88px;
    }

    /* Header area above the table */
    .reg-header-area {
        text-align: center;
        padding: 14px 10px 4px 10px;
        background: #fffef7;
    }
    .reg-header-area .reg-big-title {
        font-size: 1.25rem;
        font-weight: 700;
        text-decoration: underline;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }
    .reg-header-area .reg-institute-name {
        font-size: 0.92rem;
        font-weight: 600;
        margin-top: 2px;
    }
    .reg-header-area .reg-page-line {
        display: flex;
        justify-content: flex-end;
        padding-right: 20px;
        font-size: 0.85rem;
        font-style: italic;
        margin-top: 4px;
    }

    /* Main 15-column table */
    .reg-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1400px;
        font-size: 0.78rem;
        table-layout: fixed;   /* columns honour their defined widths */
    }
    .reg-table th, .reg-table td {
        border: 1px solid #888;
        padding: 3px 5px;
        vertical-align: top;
        line-height: 1.4;
        word-break: break-word;      /* long words wrap instead of expanding the column */
        overflow-wrap: break-word;
        white-space: normal;         /* never force single-line */
    }
    /* Header row 1 — column titles */
    .reg-table thead tr:first-child th {
        background: #fffef7;
        font-weight: 700;
        font-size: 0.74rem;
        text-align: center;
        vertical-align: middle;
        height: 52px;
        border-bottom: 1px solid #888;
    }
    /* Header row 2 — column numbers */
    .reg-table thead tr:last-child th {
        background: #fffef7;
        font-weight: 700;
        text-align: center;
        height: 22px;
        font-size: 0.78rem;
        border-top: none;
    }
    /* Data rows */
    .reg-table tbody tr {
        height: 32px;
    }
    .reg-table tbody tr:nth-child(even) td {
        background: rgba(255,255,220,0.18);
    }
    .reg-table td { vertical-align: middle; }

    /* Asset name block inside the wide cell */
    .reg-asset-name {
        font-weight: 700;
        font-size: 0.82rem;
    }
    .reg-asset-no {
        font-size: 0.72rem;
        color: #333;
        font-family: 'Courier New', monospace;
        margin-top: 1px;
    }
    .reg-item-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #222;
    }

    /* Empty signature/initial cells */
    td.sig-cell { background: #fafaf5; }

    /* Print styles */
    @media print {
        .reg-tabs, .reg-pagination, .no-print { display: none !important; }
        .reg-sheet {
            border: none;
            background-image: linear-gradient(#ccc 1px, transparent 1px);
            background-size: 100% 32px;
        }
        .reg-table { min-width: 100%; font-size: 0.7rem; }
    }
</style>

<div class="reg-wrap space-y-0 pb-10">

    <!-- Page heading -->
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between mb-3 no-print">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Virtual Register</h1>
            <p class="text-sm text-slate-500">Exact replica of the physical departmental register.</p>
        </div>
        <div class="flex gap-2">
            <button onclick="openExportModal()" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition no-print">
                📥 Export / Download
            </button>
        </div>
    </div>

    <!-- Category Tabs -->
    <div class="reg-tabs no-print">
        <?php foreach ($category_names as $catId => $catName): ?>
            <a href="dashboard.php?view=register&category=<?php echo $catId; ?>"
               class="<?php echo $selectedCategory === $catId ? 'active-tab' : ''; ?>">
                <?php echo htmlspecialchars($catName); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Pagination Top -->
    <div class="reg-pagination no-print">
        <a href="dashboard.php?view=register&category=<?php echo $selectedCategory; ?>&page_index=<?php echo max(0, $page_index - 1); ?>"
           class="<?php echo $page_index == 0 ? 'disabled' : ''; ?>">
            ← Previous Page
        </a>
        <span>
            Register Page <?php echo $page_index + 1; ?> of <?php echo max(1, $total_pages); ?>
            &nbsp;|&nbsp; Physical Page No: <strong><?php echo htmlspecialchars($current_group['label']); ?></strong>
        </span>
        <a href="dashboard.php?view=register&category=<?php echo $selectedCategory; ?>&page_index=<?php echo min($total_pages - 1, $page_index + 1); ?>"
           class="<?php echo $page_index == $total_pages - 1 ? 'disabled' : ''; ?>">
            Next Page →
        </a>
    </div>

    <!-- The Register Sheet -->
    <div class="reg-sheet">

        <!-- Header Area -->
        <div class="reg-header-area">
            <div class="reg-big-title"><?php echo htmlspecialchars($register_title); ?></div>
            <div class="reg-institute-name">Name of the Institute &nbsp; K.D. Polytechnic, Patan</div>
            <div class="reg-page-line">
                Date: ____/____/_______ &nbsp;&nbsp;&nbsp; Page No.: <strong><?php echo htmlspecialchars($current_group['label']); ?></strong>
            </div>
        </div>

        <!-- 15-Column Table -->
        <table class="reg-table">
            <thead>
                <tr>
                    <th rowspan="1" style="width:3%">Sr<br>No</th>
                    <th style="width:7%">Page No and<br>Date of G.P.R<br>entry</th>
                    <th style="width:7%">GEM Ord &amp;<br>Invoice No</th>
                    <th style="width:4%">Quantity<br>Received</th>
                    <th style="width:5%">Cost</th>
                    <th style="width:5%">Initial of Head<br>of<br>Dept/Office</th>
                    <th style="width:5%">Signature of<br>Receiver</th>
                    <th style="width:7%">Asset No</th>
                    <th style="width:14%">Name of<br>Section / Asset Details</th>
                    <th style="width:4%">Quantity<br>Issued</th>
                    <th style="width:6%">Date of<br>Issue</th>
                    <th style="width:5%">Signature of<br>Receiver</th>
                    <th style="width:5%">Initial of<br>Store or Clerk</th>
                    <th style="width:5%">Initial Head of<br>Department /<br>Office</th>
                    <th style="width:18%">Remarks</th>
                </tr>
                <tr>
                    <?php for ($i = 1; $i <= 15; $i++): ?>
                        <th><?php echo $i; ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
                         <?php
                $sr_no = 1;
                foreach ($current_group['records'] as $asset):
                    // Build GPR reference string
                    $gpr_ref = '';
                    if (!empty($asset['pr_page_no']))  $gpr_ref .= 'Pg: ' . $asset['pr_page_no'];
                    if (!empty($asset['gpr_item_no'])) $gpr_ref .= ($gpr_ref ? ' / ' : '') . 'Item: ' . $asset['gpr_item_no'];
                    if (!empty($asset['gpr_no']))       $gpr_ref .= ($gpr_ref ? "\n" : '') . $asset['gpr_no'];

                    // Build GEM reference string
                    $gem_ref = '';
                    if (!empty($asset['gem_order_no']))   $gem_ref .= $asset['gem_order_no'];
                    if (!empty($asset['gem_invoice_no'])) $gem_ref .= ($gem_ref ? "\n" : '') . 'Inv: ' . $asset['gem_invoice_no'];

                    // Location / Section
                    $section = $asset['location'] ?: ($asset['assigned_to'] ?: '');

                    // Remarks
                    $remarks = $asset['remarks'] ?: '';

                    // Total items in batch
                    $total_items = (int)($asset['total_items'] ?? 1);

                    // All asset numbers for this batch (one per line)
                    $all_asset_nos = array_filter(array_map('trim', explode("\n", $asset['asset_no'] ?? '')));
                ?>
                <tr>
                    <!-- Col 1: Item No -->
                    <td style="text-align:center; font-weight:700; vertical-align:top;">
                        <div class="reg-item-label">Item No:<br><?php echo (int)$asset['item_no']; ?></div>
                    </td>

                    <!-- Col 2: G.P.R Page / Date entry -->
                    <td style="white-space:pre-line; vertical-align:top;"><?php echo htmlspecialchars($gpr_ref); ?></td>

                    <!-- Col 3: Indent No (GEM Order) -->
                    <td style="white-space:pre-line; vertical-align:top;"><?php echo htmlspecialchars($gem_ref); ?></td>

                    <!-- Col 4: Quantity Received -->
                    <td style="text-align:center; font-weight:700; vertical-align:top;">
                        <?php echo $total_items; ?>
                    </td>

                    <!-- Col 5: Cost -->
                    <td style="text-align:right; vertical-align:top;">₹<?php echo number_format((float)$asset['cost'], 2); ?></td>

                    <!-- Col 6: Initial of Head of Dept -->
                    <td class="sig-cell"></td>

                    <!-- Col 7: Signature of Receiver -->
                    <td class="sig-cell"></td>

                    <!-- Col 8: All Asset Numbers for the batch -->
                    <td style="vertical-align:top; font-size:0.68rem; font-family:'Courier New',monospace; word-break:break-all; white-space:pre-line;">
                        <?php echo htmlspecialchars(implode("\n", $all_asset_nos)); ?>
                    </td>

                    <!-- Col 9: Asset Name + Section -->
                    <td style="vertical-align:top;">
                        <div class="reg-asset-name"><?php echo htmlspecialchars($asset['asset_name']); ?></div>
                        <?php if ($total_items > 1): ?>
                            <div style="font-size:0.72rem; color:#1d4ed8; font-weight:600;">(<?php echo $total_items; ?> Nos.)</div>
                        <?php endif; ?>
                        <?php if ($section): ?>
                            <div style="font-size:0.72rem; color:#555; margin-top:2px;"><?php echo htmlspecialchars($section); ?></div>
                        <?php endif; ?>
                    </td>

                    <!-- Col 10: Quantity Issued -->
                    <td style="text-align:center; font-weight:700; vertical-align:top;">
                        <?php echo $total_items; ?>
                    </td>

                    <!-- Col 11: Date of Issue -->
                    <td style="text-align:center; font-size:0.75rem; vertical-align:top;">
                        <?php echo htmlspecialchars($asset['date_of_issue']); ?>
                    </td>

                    <!-- Col 12: Signature of Receiver -->
                    <td class="sig-cell"></td>

                    <!-- Col 13: Initial of Store Clerk -->
                    <td class="sig-cell"></td>

                    <!-- Col 14: Initial Head of Dept/Office -->
                    <td class="sig-cell"></td>

                    <!-- Col 15: Remarks -->
                    <td style="font-size:0.75rem; vertical-align:top; position:relative;" class="group">
                        <!-- View mode -->
                        <div id="remarks-view-<?php echo $asset['id']; ?>" class="flex items-start justify-between min-h-[30px]">
                            <span id="remarks-text-<?php echo $asset['id']; ?>"
                                  class="block flex-1 pr-6"
                                  style="word-break:break-word; white-space:pre-wrap;"
                            ><?php echo htmlspecialchars($remarks ?: '-'); ?></span>
                            <button onclick="startEditRemarks(<?php echo $asset['id']; ?>)" class="opacity-0 group-hover:opacity-100 text-blue-600 hover:text-blue-800 transition p-1 rounded hover:bg-slate-100 no-print absolute right-1 top-1" title="Edit Remarks">
                                ✏️
                            </button>
                        </div>
                        <!-- Edit mode -->
                        <div id="remarks-edit-<?php echo $asset['id']; ?>" class="hidden flex-col gap-2 mt-1 no-print">
                            <textarea id="remarks-input-<?php echo $asset['id']; ?>" rows="3" class="w-full text-xs p-1 border border-blue-400 rounded outline-none focus:ring-1 focus:ring-blue-400 bg-white" style="line-height: 1.35;"><?php echo htmlspecialchars($remarks); ?></textarea>
                            <div class="flex gap-1 justify-end">
                                <button onclick="cancelEditRemarks(<?php echo $asset['id']; ?>)" class="px-2 py-0.5 text-[10px] font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded">Cancel</button>
                                <button onclick="saveRemarks(<?php echo $asset['id']; ?>, '<?php echo htmlspecialchars($asset['batch_id']); ?>')" class="px-2 py-0.5 text-[10px] font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded">Save</button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
                    $sr_no++;
                endforeach;

                // Empty filler rows for physical book feel (at least 15 rows total)
                $filled = count($current_group['records']);
                $min_rows = 15;
                for ($i = $filled; $i < $min_rows; $i++):
                ?>
                <tr>
                    <td></td><td></td><td></td><td></td><td></td>
                    <td class="sig-cell"></td><td class="sig-cell"></td>
                    <td></td><td></td><td></td><td></td>
                    <td class="sig-cell"></td><td class="sig-cell"></td><td class="sig-cell"></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Bottom -->
    <div class="reg-pagination no-print" style="border-radius:6px 6px 0 0; margin-top:4px;">
        <a href="dashboard.php?view=register&category=<?php echo $selectedCategory; ?>&page_index=<?php echo max(0, $page_index - 1); ?>"
           class="<?php echo $page_index == 0 ? 'disabled' : ''; ?>">
            ← Previous Page
        </a>
        <span>Page <?php echo $page_index + 1; ?> of <?php echo max(1, $total_pages); ?></span>
        <a href="dashboard.php?view=register&category=<?php echo $selectedCategory; ?>&page_index=<?php echo min($total_pages - 1, $page_index + 1); ?>"
           class="<?php echo $page_index == $total_pages - 1 ? 'disabled' : ''; ?>">
            Next Page →
        </a>
    </div>

</div>

<!-- Export Modal Backdrop -->
<div id="exportModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm hidden transition-all duration-300 no-print">
    <!-- Modal Container -->
    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-100 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="exportModalContainer">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-4 text-white flex justify-between items-center">
            <h3 class="font-bold text-lg">Export Asset Register</h3>
            <button onclick="closeExportModal()" class="text-white hover:text-blue-100 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <!-- Form Content -->
        <div class="p-6 space-y-4">
            <!-- Category -->
            <div>
                <label for="export_category" class="block text-sm font-semibold text-slate-700 mb-1">Select Register Category</label>
                <select id="export_category" onchange="updateExportPagesDropdown()" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100">
                    <?php foreach ($category_names as $catId => $catName): ?>
                        <option value="<?php echo $catId; ?>" <?php echo $selectedCategory === $catId ? 'selected' : ''; ?>><?php echo htmlspecialchars($catName); ?> Register</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Page Selection -->
            <div>
                <label for="export_page_no" class="block text-sm font-semibold text-slate-700 mb-1">Select Page Number</label>
                <select id="export_page_no" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100">
                    <!-- Options populated via JS -->
                </select>
            </div>

            <!-- Format -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Export Format</label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center justify-center p-3 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 cursor-pointer text-slate-700 font-semibold text-sm transition">
                        <input type="radio" name="export_format" value="excel" checked class="mr-2 text-blue-600 focus:ring-blue-500">
                        📊 Excel Format
                    </label>
                    <label class="flex items-center justify-center p-3 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 cursor-pointer text-slate-700 font-semibold text-sm transition">
                        <input type="radio" name="export_format" value="pdf" class="mr-2 text-blue-600 focus:ring-blue-500">
                        📄 PDF Format
                    </label>
                </div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
            <button onclick="closeExportModal()" class="px-4 py-2 rounded-lg border border-slate-300 text-sm font-semibold text-slate-700 bg-white hover:bg-slate-50 transition">Cancel</button>
            <button onclick="triggerExport()" class="px-5 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-sm font-semibold text-white shadow-md transition">Export Now</button>
        </div>
    </div>
</div>

<script>
    const categoryPagesMap = <?php echo json_encode($export_pages); ?>;

    function openExportModal() {
        const modal = document.getElementById('exportModal');
        const container = document.getElementById('exportModalContainer');
        modal.classList.remove('hidden');
        setTimeout(() => {
            container.classList.remove('scale-95', 'opacity-0');
            container.classList.add('scale-100', 'opacity-100');
        }, 10);
        updateExportPagesDropdown();
    }

    function closeExportModal() {
        const modal = document.getElementById('exportModal');
        const container = document.getElementById('exportModalContainer');
        container.classList.remove('scale-100', 'opacity-100');
        container.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }

    function updateExportPagesDropdown() {
        const categoryId = document.getElementById('export_category').value;
        const pageDropdown = document.getElementById('export_page_no');
        const pages = categoryPagesMap[categoryId] || [];

        // Clear existing options
        pageDropdown.innerHTML = '';

        // Add 'All Pages'
        const allOption = document.createElement('option');
        allOption.value = 'all';
        allOption.textContent = 'All Pages';
        pageDropdown.appendChild(allOption);

        // Add individual pages
        pages.forEach(page => {
            const opt = document.createElement('option');
            opt.value = page;
            opt.textContent = `Page ${page}`;
            pageDropdown.appendChild(opt);
        });
    }

    function triggerExport() {
        const categoryId = document.getElementById('export_category').value;
        const pageNo = document.getElementById('export_page_no').value;
        const format = document.querySelector('input[name="export_format"]:checked').value;

        closeExportModal();

        if (format === 'excel') {
            // Excel Export (.xls) styled exactly like the register screen
            window.location.href = `download-register.php?category=${categoryId}&page_no=${pageNo}`;
        } else {
            // PDF Export (Print layout page)
            window.open(`print-register.php?category=${categoryId}&page_no=${pageNo}`, '_blank');
        }
    }
    function toggleRemarks(btn, id) {
        const span = document.getElementById(`remarks-text-${id}`);
        const isExpanded = span.style.webkitLineClamp === 'unset';
        if (isExpanded) {
            span.style.webkitLineClamp = '4';
            span.style.maxHeight = '4.8em';
            span.style.overflow = 'hidden';
            btn.textContent = 'show more';
        } else {
            span.style.webkitLineClamp = 'unset';
            span.style.maxHeight = 'none';
            span.style.overflow = 'visible';
            btn.textContent = 'show less';
        }
    }
    function startEditRemarks(id) {

        document.getElementById(`remarks-view-${id}`).classList.add('hidden');
        document.getElementById(`remarks-edit-${id}`).classList.remove('hidden');
        document.getElementById(`remarks-input-${id}`).focus();
    }

    function cancelEditRemarks(id) {
        document.getElementById(`remarks-view-${id}`).classList.remove('hidden');
        document.getElementById(`remarks-edit-${id}`).classList.add('hidden');
    }

    function saveRemarks(id, batchId) {
        const input = document.getElementById(`remarks-input-${id}`);
        const newRemarks = input.value.trim();
        
        // Find save button to display feedback
        const saveBtn = input.nextElementSibling.querySelector('button:last-child');
        const originalText = saveBtn.textContent;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        const formData = new FormData();
        formData.append('id', id);
        formData.append('batch_id', batchId);
        formData.append('remarks', newRemarks);

        fetch('update-remarks.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update remarks text view in UI
                document.getElementById(`remarks-text-${id}`).textContent = newRemarks || '-';
                
                // Show visual success confirmation briefly
                saveBtn.textContent = 'Saved!';
                setTimeout(() => {
                    cancelEditRemarks(id);
                    saveBtn.disabled = false;
                    saveBtn.textContent = originalText;
                }, 500);
            } else {
                alert('Error: ' + data.message);
                saveBtn.disabled = false;
                saveBtn.textContent = originalText;
            }
        })
        .catch(err => {
            alert('Failed to connect to the server.');
            saveBtn.disabled = false;
            saveBtn.textContent = originalText;
        });
    }
</script>
