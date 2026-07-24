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

    if ($_SESSION['role'] === 'staff') {
        $stmt_assets = $conn->prepare("SELECT DISTINCT asset_name FROM assets WHERE category_id = ? AND assigned_to = ? ORDER BY asset_name ASC");
        $stmt_assets->bind_param("is", $category_id, $_SESSION['user_name']);
    } else {
        $stmt_assets = $conn->prepare("SELECT DISTINCT asset_name FROM assets WHERE category_id = ? ORDER BY asset_name ASC");
        $stmt_assets->bind_param("i", $category_id);
    }
    $stmt_assets->execute();
    $result_assets = $stmt_assets->get_result();
    while ($row = $result_assets->fetch_assoc()) { $response['asset_names'][] = $row['asset_name']; }
    $stmt_assets->close();

    // Staff do not need locations as it's mutually exclusive and hidden
    if ($_SESSION['role'] !== 'staff') {
        $stmt_locations = $conn->prepare("SELECT DISTINCT location FROM assets WHERE category_id = ? AND location IS NOT NULL AND location != '' ORDER BY location ASC");
        $stmt_locations->bind_param("i", $category_id);
        $stmt_locations->execute();
        $result_locations = $stmt_locations->get_result();
        while ($row = $result_locations->fetch_assoc()) { $response['locations'][] = $row['location']; }
        $stmt_locations->close();
    }

    echo json_encode($response);
    exit();
}

// --- START: AJAX handler for fetching dynamic report preview ---
if (isset($_GET['fetch_preview']) && $_GET['fetch_preview'] === 'true') {
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

    $category_id = isset($_GET['category_id']) ? $_GET['category_id'] : 'all';
    $asset_name  = isset($_GET['asset_name'])  ? $_GET['asset_name']  : 'all';
    $location    = isset($_GET['location'])    ? $_GET['location']    : 'all';
    $assigned_to = isset($_GET['assigned_to']) ? $_GET['assigned_to'] : 'all';
    $start_date  = isset($_GET['start_date'])  ? $_GET['start_date']  : '';
    $end_date    = isset($_GET['end_date'])    ? $_GET['end_date']    : '';

    if ($_SESSION['role'] === 'staff') {
        $assigned_to = $_SESSION['user_name'];
        $location = 'all'; // mutual exclusivity
    }

    $sql = "SELECT * FROM assets";
    $where_clauses = [];
    $params = [];
    $types  = '';

    if ($category_id !== 'all' && is_numeric($category_id)) {
        $where_clauses[] = "category_id = ?";
        $types .= 'i';
        $params[] = (int)$category_id;
    }

    if ($asset_name !== 'all' && $asset_name !== '') {
        $where_clauses[] = "asset_name = ?";
        $types .= 's';
        $params[] = $asset_name;
    }

    if ($location !== 'all' && $location !== '') {
        $where_clauses[] = "location = ?";
        $types .= 's';
        $params[] = $location;
    }

    if ($assigned_to !== 'all' && $assigned_to !== '') {
        $where_clauses[] = "assigned_to = ?";
        $types .= 's';
        $params[] = $assigned_to;
    }

    if (!empty($start_date)) {
        $where_clauses[] = "date_of_issue >= ?";
        $types .= 's';
        $params[] = $start_date;
    }

    if (!empty($end_date)) {
        $where_clauses[] = "date_of_issue <= ?";
        $types .= 's';
        $params[] = $end_date;
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }

    $sql .= " ORDER BY date_of_issue DESC, id DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $records = [];
    $categories = [1 => 'Expandable', 2 => 'Consumables', 3 => 'Deadstock', 4 => 'Furniture'];
    while ($row = $result->fetch_assoc()) {
        $row['category_name'] = $categories[$row['category_id']] ?? 'Unknown';
        $records[] = $row;
    }

    $stmt->close();
    $conn->close();

    echo json_encode($records);
    exit();
}
// --- END: AJAX handlers ---

if (!defined('IS_EMBEDDED')) {
    session_start();
    require 'db.php';
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.html");
        exit();
    }
}

// Pre-load faculty list for dropdown
$faculty_list = [];
$fac_stmt = $conn->prepare("SELECT id, full_name FROM users WHERE role = 'staff' ORDER BY full_name ASC");
if ($fac_stmt) {
    $fac_stmt->execute();
    $fac_result = $fac_stmt->get_result();
    while ($fac_row = $fac_result->fetch_assoc()) {
        $faculty_list[] = $fac_row;
    }
    $fac_stmt->close();
}
?>

<style>
    .filter-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem 1.5rem; }
    .filter-section-title {
        font-size: 0.7rem; font-weight: 700; letter-spacing: 0.08em;
        text-transform: uppercase; color: #6b7280;
        margin-bottom: 0.75rem; display: flex; align-items: center; gap: 6px;
    }
    .filter-section-title::before {
        content: ''; display: inline-block;
        width: 3px; height: 14px; background: #3b82f6; border-radius: 2px;
    }
    .preview-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
    .preview-table th {
        background: #f1f5f9; border: 1px solid #e2e8f0;
        padding: 7px 10px; text-align: left; font-weight: 700; color: #374151; white-space: nowrap;
    }
    .preview-table td { border: 1px solid #e2e8f0; padding: 6px 10px; color: #374151; }
    .preview-table tbody tr:hover { background: #f8fafc; }
    .badge-count {
        display: inline-flex; align-items: center; justify-content: center;
        background: #eff6ff; color: #2563eb;
        font-size: 0.72rem; font-weight: 700;
        padding: 2px 10px; border-radius: 20px; border: 1px solid #bfdbfe;
    }
    .rpt-select {
        width: 100%; border-radius: 8px; border: 1px solid #d1d5db;
        background: #f9fafb; padding: 10px 12px;
        font-size: 0.875rem; color: #111827; outline: none;
        transition: border-color .15s, box-shadow .15s;
    }
    .rpt-select:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
    .rpt-select:disabled { background: #f3f4f6; opacity: 0.55; cursor: not-allowed; }
    .rpt-input {
        width: 100%; border-radius: 8px; border: 1px solid #d1d5db;
        background: #f9fafb; padding: 10px 12px;
        font-size: 0.875rem; color: #111827; outline: none;
        transition: border-color .15s, box-shadow .15s;
    }
    .rpt-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
</style>

<div class="w-full max-w-7xl mx-auto space-y-5">

    <!-- Page Header -->
    <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
            <?php echo ($_SESSION['role'] === 'staff') ? 'My Assigned Assets' : 'Generate Asset Report'; ?>
        </h1>
        <p class="text-sm text-gray-500 mt-1">
            <?php echo ($_SESSION['role'] === 'staff') ? 'Filter and preview assets assigned to you, then download as Excel or print as PDF.' : 'Filter assets, preview matching records, then download as Excel or print as PDF.'; ?>
        </p>
    </div>

    <!-- Filters Form -->
    <form id="reportForm" action="export.php" method="POST" class="space-y-4">

        <!-- Section 1: Asset Filters -->
        <div class="filter-card">
            <div class="filter-section-title">Asset Filters</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

                <!-- Category -->
                <div>
                    <label for="report_category" class="block text-sm font-semibold text-gray-700 mb-1">Category</label>
                    <select id="report_category" name="category_id" class="rpt-select">
                        <option value="all">All Categories</option>
                        <option value="1">Expandable</option>
                        <option value="2">Consumables</option>
                        <option value="3">Deadstock</option>
                        <option value="4">Furniture</option>
                    </select>
                </div>

                <!-- Asset Name -->
                <div>
                    <label for="report_asset_name" class="block text-sm font-semibold text-gray-700 mb-1">Asset Name</label>
                    <select id="report_asset_name" name="asset_name" disabled class="rpt-select">
                        <option value="all">All Assets</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Select a category first.</p>
                </div>

                <!-- Date Range -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Date Range (Date of Issue)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" id="start_date" name="start_date" class="rpt-input">
                        <input type="date" id="end_date"   name="end_date"   class="rpt-input">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Leave blank to include all dates.</p>
                </div>

            </div>
        </div>

        <?php if ($_SESSION['role'] !== 'staff'): ?>
        <!-- Section 2: Location & Faculty (mutually exclusive) -->
        <div class="filter-card">
            <div class="filter-section-title">
                Location &amp; Faculty
                <span class="text-gray-400 text-[10px] font-normal normal-case tracking-normal ml-1">
                    — Select only one (they are mutually exclusive)
                </span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- Location -->
                <div id="location_wrapper">
                    <label for="report_location" class="block text-sm font-semibold text-gray-700 mb-1">Location</label>
                    <select id="report_location" name="location" disabled class="rpt-select">
                        <option value="all">All Locations</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1" id="location_hint">Select a category to populate locations.</p>
                </div>

                <!-- Faculty (Assigned To) -->
                <div id="faculty_wrapper">
                    <label for="report_faculty" class="block text-sm font-semibold text-gray-700 mb-1">Faculty (Assigned To)</label>
                    <select id="report_faculty" name="assigned_to" class="rpt-select">
                        <option value="all">All Faculty</option>
                        <?php foreach ($faculty_list as $fac): ?>
                            <option value="<?php echo htmlspecialchars($fac['full_name']); ?>">
                                <?php echo htmlspecialchars($fac['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-1" id="faculty_hint">Selecting a faculty disables the Location filter.</p>
                </div>

            </div>
        </div>
        <?php else: ?>
        <input type="hidden" id="report_faculty" name="assigned_to" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>">
        <input type="hidden" id="report_location" name="location" value="all">
        <?php endif; ?>

        <!-- Section 3: Columns to Include -->
        <div class="filter-card">
            <div class="flex items-center justify-between mb-3">
                <div class="filter-section-title" style="margin-bottom:0">Columns to Include in Report</div>
                <div class="flex items-center gap-3">
                    <button type="button" id="selectAllCols"   class="text-xs font-semibold text-blue-600 hover:underline">Select All</button>
                    <button type="button" id="deselectAllCols" class="text-xs font-semibold text-blue-600 hover:underline">Deselect All</button>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                <?php
                $columns = [
                    'asset_name'     => 'Asset Name',
                    'category_id'    => 'Category',
                    'item_no'        => 'Item No',
                    'asset_no'       => 'Asset No',
                    'quantity'       => 'Quantity',
                    'cost'           => 'Cost',
                    'location'       => 'Location',
                    'date_of_issue'  => 'Date of Issue',
                    'assigned_to'    => 'Assigned To',
                    'remarks'        => 'Remarks',
                    'page_no'        => 'Page No',
                    'gem_order_no'   => 'GeM Order No',
                    'gem_invoice_no' => 'GeM Invoice No',
                    'gpr_no'         => 'GPR No',
                    'pr_page_no'     => 'GPR Page No',
                    'gpr_item_no'    => 'GPR Item No',
                ];
                foreach ($columns as $key => $label): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer hover:text-blue-700 transition select-none">
                        <input type="checkbox" name="columns[]" value="<?php echo $key; ?>"
                               data-col="<?php echo $key; ?>" checked
                               class="col-checkbox h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <?php echo $label; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center gap-3 justify-between">
            <button type="button" id="previewBtn"
                class="inline-flex items-center gap-2 rounded-lg bg-slate-700 px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-slate-800">
                🔍 Preview Records
            </button>
            <div class="flex gap-3 flex-wrap">
                <button type="button" id="printBtn"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    🖨️ Print / Save as PDF
                </button>
                <button type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                    📥 Download Excel
                </button>
            </div>
        </div>

    </form>

    <!-- Live Preview Section -->
    <div id="previewSection" class="hidden">
        <div class="filter-card space-y-3">
            <div class="flex items-center justify-between">
                <div class="filter-section-title" style="margin-bottom:0">Report Preview</div>
                <span id="recordCount" class="badge-count">0 records</span>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200" style="max-height:480px; overflow-y:auto;">
                <div id="previewTableContainer"></div>
            </div>
        </div>
    </div>

    <!-- Hidden print data container -->
    <div id="printArea" style="display:none;">
        <h2 style="text-align:center; font-family:'Times New Roman',serif; text-decoration:underline; font-size:16pt; margin:0 0 4px;">
            K.D. Polytechnic — Asset Report
        </h2>
        <p style="text-align:center; font-family:'Times New Roman',serif; font-size:11pt; margin:0 0 12px; color:#555;">
            Generated: <?php echo date('d/m/Y H:i'); ?>
        </p>
        <div id="printTableContainer"></div>
    </div>

</div>

<script>
document.getElementById('end_date').valueAsDate = new Date();

const categorySelect  = document.getElementById('report_category');
const assetNameSelect = document.getElementById('report_asset_name');
const locationSelect  = document.getElementById('report_location');
const facultySelect   = document.getElementById('report_faculty');
const startDate       = document.getElementById('start_date');
const endDate         = document.getElementById('end_date');
const allCheckboxes   = document.querySelectorAll('.col-checkbox');

const CATEGORIES = {1:'Expandable', 2:'Consumables', 3:'Deadstock', 4:'Furniture'};

const COL_LABELS = {
    asset_name:'Asset Name', category_id:'Category', item_no:'Item No', asset_no:'Asset No',
    quantity:'Quantity', cost:'Cost', location:'Location', date_of_issue:'Date of Issue',
    assigned_to:'Assigned To', remarks:'Remarks', page_no:'Page No',
    gem_order_no:'GeM Order No', gem_invoice_no:'GeM Invoice No',
    gpr_no:'GPR No', pr_page_no:'GPR Page No', gpr_item_no:'GPR Item No'
};

// ── Helpers ──
function isSelect(el) { return el && el.tagName === 'SELECT'; }
function enableSelect(el) {
    if (!el) return;
    el.disabled = false;
    el.style.opacity = '';
    el.style.cursor  = '';
}
function disableSelect(el) {
    if (!el) return;
    el.disabled  = true;
    el.style.opacity = '0.5';
    el.style.cursor  = 'not-allowed';
}
function resetSelect(el, defaultText) {
    if (!isSelect(el)) return;
    el.innerHTML = `<option value="all">${defaultText}</option>`;
    disableSelect(el);
}

// ── MUTUAL EXCLUSIVITY: Location ↔ Faculty (only applies when both are <select> elements) ──
if (isSelect(locationSelect)) {
    locationSelect.addEventListener('change', function () {
        if (this.value !== 'all') {
            disableSelect(facultySelect);
            if (isSelect(facultySelect)) facultySelect.value = 'all';
            const fh = document.getElementById('faculty_hint');
            if (fh) fh.textContent = '⚠️ Disabled: a Location is already selected.';
        } else {
            enableSelect(facultySelect);
            const fh = document.getElementById('faculty_hint');
            if (fh) fh.textContent = 'Selecting a faculty disables the Location filter.';
        }
        schedulePreview();
    });
}

if (isSelect(facultySelect)) {
    facultySelect.addEventListener('change', function () {
        if (this.value !== 'all') {
            disableSelect(locationSelect);
            if (isSelect(locationSelect)) locationSelect.value = 'all';
            const lh = document.getElementById('location_hint');
            if (lh) lh.textContent = '⚠️ Disabled: a Faculty is already selected.';
        } else {
            if (isSelect(locationSelect)) {
                // only re-enable if category was selected
                if (categorySelect.value !== 'all') enableSelect(locationSelect);
            }
            const lh = document.getElementById('location_hint');
            if (lh) lh.textContent = '';
        }
        schedulePreview();
    });
}

// ── CATEGORY change → fetch asset names + locations ──
categorySelect.addEventListener('change', function () {
    const categoryId = this.value;

    resetSelect(assetNameSelect, 'All Assets');
    if (isSelect(locationSelect)) resetSelect(locationSelect, 'All Locations');
    if (isSelect(facultySelect)) {
        enableSelect(facultySelect);
        facultySelect.value = 'all';
        const fh = document.getElementById('faculty_hint');
        if (fh) fh.textContent = 'Selecting a faculty disables the Location filter.';
    }

    if (categoryId && categoryId !== 'all') {
        assetNameSelect.innerHTML = '<option value="">Loading…</option>';
        enableSelect(assetNameSelect);
        if (isSelect(locationSelect)) {
            locationSelect.innerHTML = '<option value="">Loading…</option>';
            enableSelect(locationSelect);
            const lh = document.getElementById('location_hint');
            if (lh) lh.textContent = '';
        }

        fetch(`generate-report.php?fetch_filters=true&category_id=${categoryId}`)
            .then(r => r.json())
            .then(data => {
                assetNameSelect.innerHTML = '<option value="all">All Assets</option>';
                (data.asset_names || []).forEach(n => assetNameSelect.add(new Option(n, n)));

                if (isSelect(locationSelect)) {
                    locationSelect.innerHTML = '<option value="all">All Locations</option>';
                    (data.locations || []).forEach(n => locationSelect.add(new Option(n, n)));
                }
            })
            .catch(() => {
                resetSelect(assetNameSelect, 'Error loading');
                if (isSelect(locationSelect)) resetSelect(locationSelect, 'Error loading');
            });
    } else {
        const lh = document.getElementById('location_hint');
        if (lh) lh.textContent = 'Select a category to populate locations.';
    }
    schedulePreview();
});

// ── Other filter / column changes ──
assetNameSelect.addEventListener('change', schedulePreview);
startDate.addEventListener('change', schedulePreview);
endDate.addEventListener('change', schedulePreview);
allCheckboxes.forEach(cb => cb.addEventListener('change', schedulePreview));

// ── Select All / Deselect All ──
document.getElementById('selectAllCols').addEventListener('click', () => {
    allCheckboxes.forEach(cb => cb.checked = true);
    schedulePreview();
});
document.getElementById('deselectAllCols').addEventListener('click', () => {
    allCheckboxes.forEach(cb => cb.checked = false);
    schedulePreview();
});

// ── Build URL params ──
function buildParams() {
    const p = new URLSearchParams();
    p.set('fetch_preview', 'true');
    p.set('category_id', categorySelect.value);
    p.set('asset_name',  assetNameSelect.value);
    // For staff, locationSelect/facultySelect are hidden inputs — read their value directly
    p.set('location',    (isSelect(locationSelect) && locationSelect.disabled) ? 'all' : (locationSelect ? locationSelect.value : 'all'));
    p.set('assigned_to', (isSelect(facultySelect)  && facultySelect.disabled)  ? 'all' : (facultySelect  ? facultySelect.value  : 'all'));
    p.set('start_date',  startDate.value);
    p.set('end_date',    endDate.value);
    return p;
}

function getSelectedCols() {
    return Array.from(allCheckboxes).filter(cb => cb.checked).map(cb => cb.dataset.col);
}

// ── Render table ──
function renderTable(records, cols, container) {
    if (cols.length === 0) {
        container.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:24px 0;font-size:.85rem;">No columns selected.</p>';
        return;
    }
    if (!records || records.length === 0) {
        container.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:24px 0;font-size:.85rem;">No records match the selected filters.</p>';
        return;
    }

    let html = '<table class="preview-table"><thead><tr><th>#</th>';
    cols.forEach(col => { html += `<th>${COL_LABELS[col] || col}</th>`; });
    html += '</tr></thead><tbody>';

    records.forEach((row, idx) => {
        html += '<tr>';
        html += `<td style="text-align:center;color:#9ca3af;min-width:36px;">${idx + 1}</td>`;
        cols.forEach(col => {
            let val = row[col] ?? '';
            if (col === 'category_id') val = CATEGORIES[val] || val;
            if (col === 'cost' && val !== '') val = '₹' + parseFloat(val).toLocaleString('en-IN', {minimumFractionDigits:2});
            html += `<td>${String(val).replace(/\n/g,'<br>')}</td>`;
        });
        html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
}

// ── Debounced preview fetch ──
let previewTimer;
function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(doPreview, 450);
}

function doPreview() {
    const cols      = getSelectedCols();
    const section   = document.getElementById('previewSection');
    const container = document.getElementById('previewTableContainer');
    const badge     = document.getElementById('recordCount');

    section.classList.remove('hidden');
    container.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:24px 0;font-size:.85rem;">Loading preview…</p>';

    fetch('generate-report.php?' + buildParams())
        .then(r => r.json())
        .then(records => {
            badge.textContent = `${records.length} record${records.length !== 1 ? 's' : ''}`;
            renderTable(records, cols, container);
            renderTable(records, cols, document.getElementById('printTableContainer'));
        })
        .catch(err => {
            console.error(err);
            container.innerHTML = '<p style="text-align:center;color:#ef4444;padding:24px 0;font-size:.85rem;">Error loading preview.</p>';
        });
}

// ── Print / PDF button ──
document.getElementById('printBtn').addEventListener('click', function () {
    const printContent = document.getElementById('printArea').innerHTML;
    const w = window.open('', '_blank');
    w.document.write(`<!DOCTYPE html><html><head><title>Asset Report — K.D. Polytechnic</title>
    <style>
        body { font-family: 'Times New Roman', serif; padding: 24px; color: #000; }
        table { width: 100%; border-collapse: collapse; font-size: 10pt; margin-top: 10px; }
        th { background: #e8edf2; border: 1px solid #aaa; padding: 6px 8px; text-align: left; font-weight: 700; }
        td { border: 1px solid #bbb; padding: 5px 8px; }
        tr:nth-child(even) td { background: #f8f9fb; }
        @media print { body { padding: 0; } }
    </style></head><body>${printContent}</body></html>`);
    w.document.close();
    setTimeout(() => w.print(), 300);
});

// ── Preview button manual trigger ──
document.getElementById('previewBtn').addEventListener('click', doPreview);

// ── Run on page load ──
schedulePreview();
</script>