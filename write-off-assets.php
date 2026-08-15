<?php
if (!defined('IS_EMBEDDED')) {
    session_start();
    require 'db.php';
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Action Handler: Mark an asset as written-off (retired)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_writeoff') {
    $asset_id = (int)($_POST['asset_id'] ?? 0);
    $item_no_post = (int)($_POST['item_no'] ?? 0);
    $category_id = (int)($_POST['category'] ?? 1);
    $tab = $_POST['tab'] ?? 'candidates';

    $writeoff_reason = trim($_POST['writeoff_reason'] ?? '');

    if ($item_no_post > 0) {
        $cutoff_date = date('Y-m-d', strtotime('-5 years'));
        if ($writeoff_reason !== '') {
            $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW(), status = 'Retired', remarks = ? WHERE item_no = ? AND category_id = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) AND date_of_issue <= ?");
            $stmt->bind_param("siis", $writeoff_reason, $item_no_post, $category_id, $cutoff_date);
        } else {
            $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW(), status = 'Retired' WHERE item_no = ? AND category_id = ? AND retire_at IS NULL AND (transferred = 0 OR transferred IS NULL) AND date_of_issue <= ?");
            $stmt->bind_param("iis", $item_no_post, $category_id, $cutoff_date);
        }
        if ($stmt->execute()) {
            $stmt->close();
            $redirect_url = "dashboard.php?view=write-off-assets&tab=" . urlencode($tab) . "&category=" . $category_id . "&status=writeoff_success";
            echo "<script>window.location.href=" . json_encode($redirect_url) . ";</script>";
            exit();
        }
        $stmt->close();
    } elseif ($asset_id > 0) {
        if ($writeoff_reason !== '') {
            $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW(), status = 'Retired', remarks = ? WHERE id = ? AND retire_at IS NULL");
            $stmt->bind_param("si", $writeoff_reason, $asset_id);
        } else {
            $stmt = $conn->prepare("UPDATE assets SET retire_at = NOW(), status = 'Retired' WHERE id = ? AND retire_at IS NULL");
            $stmt->bind_param("i", $asset_id);
        }
        if ($stmt->execute()) {
            $stmt->close();
            $redirect_url = "dashboard.php?view=write-off-assets&tab=" . urlencode($tab) . "&category=" . $category_id . "&status=writeoff_success";
            echo "<script>window.location.href=" . json_encode($redirect_url) . ";</script>";
            exit();
        }
        $stmt->close();
    }
    $redirect_url = "dashboard.php?view=write-off-assets&tab=" . urlencode($tab) . "&category=" . $category_id . "&status=writeoff_error";
    echo "<script>window.location.href=" . json_encode($redirect_url) . ";</script>";
    exit();
}

// Action Handler: Bulk write-off selected asset IDs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_writeoff_bulk') {
    $asset_ids_raw = $_POST['asset_ids'] ?? [];
    $asset_ids     = array_filter(array_map('intval', (array)$asset_ids_raw));
    $category_id   = (int)($_POST['category'] ?? 1);
    $tab           = $_POST['tab'] ?? 'candidates';
    $writeoff_reason = trim($_POST['writeoff_reason'] ?? '');

    if (!empty($asset_ids)) {
        $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
        $types = str_repeat('i', count($asset_ids));
        if ($writeoff_reason !== '') {
            $sql = "UPDATE assets SET retire_at = NOW(), status = 'Retired', remarks = ? WHERE id IN ($placeholders) AND retire_at IS NULL";
            $stmt = $conn->prepare($sql);
            $bind_params = array_merge([$writeoff_reason], $asset_ids);
            $bind_types  = 's' . $types;
            $refs = [$bind_types];
            foreach ($bind_params as &$v) { $refs[] = &$v; }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        } else {
            $sql = "UPDATE assets SET retire_at = NOW(), status = 'Retired' WHERE id IN ($placeholders) AND retire_at IS NULL";
            $stmt = $conn->prepare($sql);
            $refs = [$types];
            foreach ($asset_ids as &$v) { $refs[] = &$v; }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        if ($stmt->execute()) {
            $stmt->close();
            $redirect_url = "dashboard.php?view=write-off-assets&tab=" . urlencode($tab) . "&category=" . $category_id . "&status=writeoff_success";
            echo "<script>window.location.href=" . json_encode($redirect_url) . ";</script>";
            exit();
        }
        $stmt->close();
    }
    $redirect_url = "dashboard.php?view=write-off-assets&tab=" . urlencode($tab) . "&category=" . $category_id . "&status=writeoff_error";
    echo "<script>window.location.href=" . json_encode($redirect_url) . ";</script>";
    exit();
}

$category_names = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

$category_colors = [
    1 => 'blue',
    2 => 'purple',
    3 => 'amber',
    4 => 'emerald'
];

$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'candidates';
$selectedCategory = isset($_GET['category']) && isset($category_names[(int)$_GET['category']])
    ? (int)$_GET['category']
    : 1;

$name_filter = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
$issue_from  = isset($_GET['issue_from']) ? trim((string)$_GET['issue_from']) : '';
$issue_to    = isset($_GET['issue_to']) ? trim((string)$_GET['issue_to']) : '';
$cutoff_date = date('Y-m-d', strtotime('-5 years'));

$write_off_assets = [];
$category_counts = array_fill_keys(array_keys($category_names), 0);
$total_candidates = 0;

$status_alert = isset($_GET['status']) ? trim($_GET['status']) : '';

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $bind_names = [];
    $bind_names[] = $types;
    foreach ($params as $key => &$value) {
        $bind_names[$key + 1] = &$value;
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

// Fetch candidate assets (5+ years old, active)
function fetch_write_off_assets(mysqli $conn, int $category_id, string $cutoff_date, string $name_filter = '', string $issue_from = '', string $issue_to = ''): array
{
    $sql = "
        SELECT
            id,
            asset_no,
            asset_name,
            category_id,
            item_no,
            page_no,
            location,
            assigned_to,
            status,
            date_of_issue,
            cost,
            remarks,
            batch_id
        FROM assets
        WHERE category_id = ?
          AND retire_at IS NULL
          AND (transferred = 0 OR transferred IS NULL)
          AND date_of_issue <= ?
    ";

    $types = 'is';
    $params = [$category_id, $cutoff_date];

    if ($name_filter !== '') {
        $sql .= " AND asset_name LIKE ?";
        $types .= 's';
        $params[] = '%' . $name_filter . '%';
    }

    if ($issue_from !== '') {
        $sql .= " AND date_of_issue >= ?";
        $types .= 's';
        $params[] = $issue_from;
    }

    if ($issue_to !== '') {
        $sql .= " AND date_of_issue <= ?";
        $types .= 's';
        $params[] = $issue_to;
    }

    $sql .= " ORDER BY date_of_issue ASC, asset_name ASC, item_no ASC, asset_no ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    if (!bind_params_dynamic($stmt, $types, $params)) {
        $stmt->close();
        return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $assets = [];
    if ($result) {
        while ($asset = $result->fetch_assoc()) {
            $assets[] = $asset;
        }
    }

    $stmt->close();
    return $assets;
}

// Fetch written-off assets (retire_at IS NOT NULL)
function fetch_write_off_history_assets(mysqli $conn, int $category_id, string $name_filter = '', string $issue_from = '', string $issue_to = ''): array
{
    $sql = "
        SELECT
            id,
            asset_no,
            asset_name,
            category_id,
            item_no,
            page_no,
            location,
            assigned_to,
            status,
            date_of_issue,
            retire_at,
            cost,
            remarks,
            batch_id
        FROM assets
        WHERE category_id = ?
          AND retire_at IS NOT NULL
    ";

    $types = 'i';
    $params = [$category_id];

    if ($name_filter !== '') {
        $sql .= " AND asset_name LIKE ?";
        $types .= 's';
        $params[] = '%' . $name_filter . '%';
    }

    if ($issue_from !== '') {
        $sql .= " AND date_of_issue >= ?";
        $types .= 's';
        $params[] = $issue_from;
    }

    if ($issue_to !== '') {
        $sql .= " AND date_of_issue <= ?";
        $types .= 's';
        $params[] = $issue_to;
    }

    $sql .= " ORDER BY retire_at DESC, date_of_issue ASC, asset_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    if (!bind_params_dynamic($stmt, $types, $params)) {
        $stmt->close();
        return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $assets = [];
    if ($result) {
        while ($asset = $result->fetch_assoc()) {
            $assets[] = $asset;
        }
    }

    $stmt->close();
    return $assets;
}

function build_writeoff_url(int $category_id, string $tab, string $name_filter, string $issue_from, string $issue_to): string
{
    $query = [
        'view' => 'write-off-assets',
        'tab'  => $tab,
        'category' => $category_id,
    ];

    if ($name_filter !== '') {
        $query['name'] = $name_filter;
    }
    if ($issue_from !== '') {
        $query['issue_from'] = $issue_from;
    }
    if ($issue_to !== '') {
        $query['issue_to'] = $issue_to;
    }

    return 'dashboard.php?' . http_build_query($query);
}

// Fetch all counts for active tab
foreach ($category_names as $category_id => $category_name) {
    if ($active_tab === 'history') {
        $assets = fetch_write_off_history_assets($conn, $category_id, $name_filter, $issue_from, $issue_to);
    } else {
        $assets = fetch_write_off_assets($conn, $category_id, $cutoff_date, $name_filter, $issue_from, $issue_to);
    }
    $write_off_assets[$category_id] = $assets;
    $category_counts[$category_id] = count($assets);
    $total_candidates += $category_counts[$category_id];
}

$selected_category_name = $category_names[$selectedCategory];
$selected_assets = $write_off_assets[$selectedCategory] ?? [];
?>

<style>
    .wo-wrap {
        font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
        color: #0f172a;
    }

    .wo-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 12px;
    }

    .wo-tabs a {
        padding: 10px 16px;
        font-size: 0.82rem;
        font-weight: 600;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        background: #ffffff;
        color: #475569;
        text-decoration: none;
        transition: all 0.15s ease;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .wo-tabs a.active-tab {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.18);
    }

    .wo-tabs a:hover:not(.active-tab) {
        background: #f8fafc;
        border-color: #cbd5e1;
    }

    .wo-shell {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }

    .wo-header-area {
        padding: 18px 24px;
        background: linear-gradient(to right, #f8fafc, #ffffff);
        border-bottom: 1px solid #e2e8f0;
    }

    .wo-title {
        font-size: 1.15rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.01em;
    }

    .wo-subtitle {
        font-size: 0.8rem;
        color: #64748b;
        margin-top: 2px;
    }

    .wo-page-line {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed #cbd5e1;
        font-size: 0.8rem;
        color: #475569;
        flex-wrap: wrap;
        gap: 8px;
    }

    .wo-sheet-meta {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .wo-pill {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        padding: 3px 10px;
        font-size: 0.72rem;
        font-weight: 600;
    }

    .wo-filter-bar {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        padding: 14px 18px;
        margin-bottom: 16px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
        box-shadow: 0 2px 4px rgba(15, 23, 42, 0.03);
    }

    .wo-field {
        display: flex;
        flex-direction: column;
        gap: 4px;
        flex: 1;
        min-width: 160px;
    }

    .wo-field label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #475569;
    }

    .wo-field input {
        border: 1px solid #cbd5e1;
        border-radius: 0.5rem;
        padding: 8px 12px;
        font-size: 0.82rem;
        outline: none;
        transition: border 0.15s;
    }

    .wo-field input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .wo-btn {
        padding: 8px 16px;
        border-radius: 0.5rem;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s;
        border: none;
        text-decoration: none;
    }

    .wo-btn-primary { background: #2563eb; color: #fff; }
    .wo-btn-primary:hover { background: #1d4ed8; }

    .wo-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .wo-btn-secondary:hover { background: #e2e8f0; }

    .wo-btn-danger { background: #dc2626; color: #fff; }
    .wo-btn-danger:hover { background: #b91c1c; }

    .wo-list-head {
        display: grid;
        grid-template-columns: 50px 2.2fr 1.3fr 1.3fr 1fr 130px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 10px 16px;
        font-size: 0.72rem;
        font-weight: 800;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .wo-list-row {
        display: grid;
        grid-template-columns: 50px 2.2fr 1.3fr 1.3fr 1fr 130px;
        padding: 12px 16px;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        font-size: 0.82rem;
        transition: background 0.15s;
    }

    .wo-list-row:hover { background: #f8fafc; }
    .wo-list-row:last-child { border-bottom: none; }

    .wo-mono { font-family: ui-monospace, monospace; font-size: 0.78rem; font-weight: 700; color: #2563eb; }
    .wo-primary { font-weight: 700; color: #0f172a; }
    .wo-secondary { font-size: 0.75rem; color: #64748b; }
    .wo-meta { font-weight: 600; color: #334155; }

    .wo-status {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 700;
    }
    .wo-status-active { background: #dcfce7; color: #15803d; }
    .wo-status-maintenance { background: #fef3c7; color: #b45309; }
    .wo-status-retired { background: #fee2e2; color: #b91c1c; }

    .wo-subnav {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
    }

    .wo-subnav-btn {
        padding: 10px 18px;
        border-radius: 0.75rem;
        font-size: 0.85rem;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.15s ease;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #64748b;
    }

    .wo-subnav-btn.active {
        background: #0f172a;
        color: #ffffff;
        border-color: #0f172a;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    }
</style>

<div class="wo-wrap space-y-3 pb-10">

    <!-- Top Page Header -->
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between mb-2 no-print">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Write-off Assets</h1>
            <p class="text-sm text-slate-500">Manage 5+ year old candidates and archived written-off assets.</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button type="button" onclick="openWriteOffExportModal()" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition no-print">
                <i data-lucide="file-spreadsheet" style="width:18px;height:18px"></i>
                Download as Excel
            </button>
        </div>
    </div>

    <!-- Alert Toast Messages -->
    <?php if ($status_alert === 'writeoff_success'): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 flex items-center gap-2 shadow-sm">
            <span>✅</span> Asset marked as written-off (retired) successfully!
        </div>
    <?php elseif ($status_alert === 'writeoff_error'): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 flex items-center gap-2 shadow-sm">
            <span>⚠️</span> Failed to update asset status. Please try again.
        </div>
    <?php endif; ?>

    <!-- Sub Navigation Tabs (Candidates vs History) -->
    <div class="wo-subnav no-print">
        <a href="<?php echo htmlspecialchars(build_writeoff_url($selectedCategory, 'candidates', $name_filter, $issue_from, $issue_to)); ?>"
           class="wo-subnav-btn <?php echo $active_tab === 'candidates' ? 'active' : ''; ?>">
            <span>📋 Write-Off Candidates (5+ Yrs Old)</span>
        </a>
        <a href="<?php echo htmlspecialchars(build_writeoff_url($selectedCategory, 'history', $name_filter, $issue_from, $issue_to)); ?>"
           class="wo-subnav-btn <?php echo $active_tab === 'history' ? 'active' : ''; ?>">
            <span>📜 Written-Off Assets Archive</span>
        </a>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="dashboard.php" class="wo-filter-bar no-print">
        <input type="hidden" name="view" value="write-off-assets">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
        <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
        <div class="wo-field">
            <label for="name">Asset Name</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name_filter); ?>" placeholder="Search by asset name">
        </div>
        <div class="wo-field">
            <label for="issue_from">Issue Date From</label>
            <input type="date" id="issue_from" name="issue_from" value="<?php echo htmlspecialchars($issue_from); ?>">
        </div>
        <div class="wo-field">
            <label for="issue_to">Issue Date To</label>
            <input type="date" id="issue_to" name="issue_to" value="<?php echo htmlspecialchars($issue_to); ?>">
        </div>
        <button type="submit" class="wo-btn wo-btn-primary">
            <i data-lucide="search" style="width:18px;height:18px"></i>
            Search
        </button>
        <a href="dashboard.php?view=write-off-assets&tab=<?php echo htmlspecialchars($active_tab); ?>&category=<?php echo (int)$selectedCategory; ?>" class="wo-btn wo-btn-secondary">
            Clear
        </a>
    </form>

    <!-- Category Tabs -->
    <div class="wo-tabs no-print">
        <?php foreach ($category_names as $category_id => $category_name): ?>
            <a href="<?php echo htmlspecialchars(build_writeoff_url($category_id, $active_tab, $name_filter, $issue_from, $issue_to)); ?>"
               class="<?php echo $selectedCategory === $category_id ? 'active-tab' : ''; ?>">
                <?php echo htmlspecialchars($category_name); ?>
                <span class="wo-tab-count"><?php echo number_format($category_counts[$category_id]); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Main List Table Shell -->
    <div class="wo-shell">
        <div class="wo-header-area">
            <div class="wo-title">
                <?php echo htmlspecialchars($selected_category_name); ?>
                <?php echo $active_tab === 'history' ? 'Written-Off Archive' : 'Write-Off Candidates List'; ?>
            </div>
            <div class="wo-subtitle">
                <?php echo $active_tab === 'history' ? 'Assets that have been marked as retired / written off.' : 'Active assets older than 5 years ready for write-off.'; ?>
            </div>
            <div class="wo-page-line">
                <div>Cutoff Date: <strong><?php echo date('M d, Y', strtotime($cutoff_date)); ?></strong></div>
                <div class="wo-sheet-meta">
                    <span class="wo-pill">Total Items: <?php echo number_format($total_candidates); ?></span>
                    <span class="wo-pill">Category: <?php echo htmlspecialchars($selected_category_name); ?></span>
                    <span class="wo-pill">Rows: <?php echo number_format(count($selected_assets)); ?></span>
                    <?php if ($name_filter !== ''): ?>
                        <span class="wo-pill">Name: <?php echo htmlspecialchars($name_filter); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="wo-list-head">
            <div>Sr</div>
            <div>Item No / Asset Name</div>
            <div>Eligible Qty / Total Cost</div>
            <div><?php echo $active_tab === 'history' ? 'Written-Off Date Range' : 'Locations / Faculty'; ?></div>
            <div>Status</div>
            <div class="text-right">Action</div>
        </div>

        <div>
            <?php if (empty($selected_assets)): ?>
                <div class="wo-empty py-12 text-center text-slate-500">
                    <?php echo $active_tab === 'history' ? 'No written-off assets found in this category.' : 'No 5+ years old write-off candidate assets found in this category.'; ?>
                </div>
            <?php else:
                // Group by item_no
                $grouped = [];
                foreach ($selected_assets as $asset) {
                    $item_no = $asset['item_no'] ?: 'Uncategorized';
                    if (!isset($grouped[$item_no])) {
                        $grouped[$item_no] = [
                            'item_no' => $item_no,
                            'asset_name' => $asset['asset_name'],
                            'page_no' => $asset['page_no'],
                            'date_of_issue_min' => $asset['date_of_issue'],
                            'date_of_issue_max' => $asset['date_of_issue'],
                            'total_cost' => 0,
                            'status' => $asset['status'] ?: ($active_tab === 'history' ? 'Retired' : 'Active'),
                            'locations' => [],
                            'items' => []
                        ];
                    }
                    $grouped[$item_no]['items'][] = $asset;
                    $grouped[$item_no]['total_cost'] += (float)$asset['cost'];
                    $loc = $asset['location'] ?: ($asset['assigned_to'] ?: 'N/A');
                    if (!in_array($loc, $grouped[$item_no]['locations'])) {
                        $grouped[$item_no]['locations'][] = $loc;
                    }
                    if (strtotime($asset['date_of_issue']) < strtotime($grouped[$item_no]['date_of_issue_min'])) {
                        $grouped[$item_no]['date_of_issue_min'] = $asset['date_of_issue'];
                    }
                    if (strtotime($asset['date_of_issue']) > strtotime($grouped[$item_no]['date_of_issue_max'])) {
                        $grouped[$item_no]['date_of_issue_max'] = $asset['date_of_issue'];
                    }
                }

                $sr_no = 1;
                foreach ($grouped as $item_no => $group):
                    $items_count = count($group['items']);
                    $holder_summary = implode(', ', array_slice($group['locations'], 0, 2));
                    if (count($group['locations']) > 2) {
                        $holder_summary .= ' (+' . (count($group['locations']) - 2) . ' more)';
                    }
                    $status = $group['status'];
                    $statusClass = 'wo-status-active';
                    if (stripos($status, 'maintenance') !== false) {
                        $statusClass = 'wo-status-maintenance';
                    } elseif (stripos($status, 'retire') !== false || stripos($status, 'write') !== false || $active_tab === 'history') {
                        $statusClass = 'wo-status-retired';
                    }
            ?>
                    <div class="wo-list-row" style="background: #ffffff; border-bottom: 1px solid #e2e8f0;">
                        <div class="wo-cell wo-sr"><?php echo $sr_no; ?></div>

                        <div class="wo-cell">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-50 text-blue-700 text-xs font-bold border border-blue-100"><?php echo htmlspecialchars($item_no); ?></span>
                                <div class="wo-primary capitalize"><?php echo htmlspecialchars($group['asset_name']); ?></div>
                            </div>
                            <div class="wo-secondary mt-1">Page No: <?php echo htmlspecialchars($group['page_no'] ?: 'N/A'); ?> | Date Range: <?php echo date('d/m/Y', strtotime($group['date_of_issue_min'])); ?> - <?php echo date('d/m/Y', strtotime($group['date_of_issue_max'])); ?></div>
                        </div>

                        <div class="wo-cell">
                            <div class="wo-meta text-slate-800 font-bold"><?php echo $items_count; ?> Asset<?php echo $items_count !== 1 ? 's' : ''; ?></div>
                            <div class="wo-secondary">Total Cost: &#8377;<?php echo number_format($group['total_cost'], 2); ?></div>
                        </div>

                        <div class="wo-cell">
                            <div class="wo-meta text-slate-600"><?php echo htmlspecialchars($holder_summary ?: 'N/A'); ?></div>
                        </div>

                        <div class="wo-cell">
                            <span class="wo-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                        </div>

                        <div class="wo-cell text-right flex flex-col sm:flex-row items-end sm:items-center justify-end gap-1.5">
                            <button type="button" id="btn-toggle-<?php echo $item_no; ?>" onclick="toggleGroupDetails('<?php echo $item_no; ?>')" class="wo-btn wo-btn-secondary text-xs px-2.5 py-1.5 rounded-lg shadow-sm">
                                🔽 View Detail
                            </button>
                            <?php if ($active_tab === 'candidates'): ?>
                                <button type="button" onclick="openConfirmWriteOffModal(0, '<?php echo htmlspecialchars(addslashes($group['asset_name'])); ?>', '', '<?php echo htmlspecialchars($item_no); ?>')" class="wo-btn wo-btn-danger text-xs px-2.5 py-1.5 rounded-lg shadow-sm">
                                    🗑️ Write Off All
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Accordion Sub-rows with Checkboxes -->
                    <div id="details-item-<?php echo $item_no; ?>" class="hidden bg-slate-50 border-l-4 border-blue-500 shadow-inner no-print" style="margin-left: 20px; margin-right: 20px; border-radius: 0 0 8px 8px; overflow: hidden;">

                        <?php if ($active_tab === 'candidates'): ?>
                        <!-- Bulk action header -->
                        <div class="flex items-center justify-between px-5 py-3 bg-slate-100 border-b border-slate-200">
                            <label class="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" id="selectAll-<?php echo $item_no; ?>" onchange="toggleSelectAll('<?php echo $item_no; ?>', this.checked)" class="w-4 h-4 rounded border-slate-400 accent-red-600 cursor-pointer">
                                <span class="text-xs font-bold text-slate-600 uppercase tracking-wide">Select All (<?php echo $items_count; ?> assets)</span>
                            </label>
                            <button type="button" id="bulkWriteOffBtn-<?php echo $item_no; ?>" onclick="openBulkWriteOffModal('<?php echo $item_no; ?>', '<?php echo htmlspecialchars(addslashes($group['asset_name'])); ?>')" class="hidden wo-btn wo-btn-danger text-xs px-3 py-1.5 rounded-lg" style="padding: 6px 14px;">
                                🗑️ Write Off Selected (<span id="bulkCount-<?php echo $item_no; ?>">0</span>)
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="px-5 py-3 bg-slate-100 border-b border-slate-200">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Asset Details — Item No: <?php echo htmlspecialchars($item_no); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="px-5 py-3 space-y-2">
                            <?php foreach ($group['items'] as $sub_idx => $sub_asset):
                                $sub_issue_time = strtotime($sub_asset['date_of_issue']);
                                $sub_age = $sub_issue_time ? date_diff(date_create($sub_asset['date_of_issue']), date_create('today'))->y : 0;
                                $sub_holder = $sub_asset['location'] ?: ($sub_asset['assigned_to'] ?: 'N/A');
                                $sub_retire_time = !empty($sub_asset['retire_at']) ? strtotime($sub_asset['retire_at']) : null;
                                $sub_retire_fmt = $sub_retire_time ? date('d/m/Y H:i', $sub_retire_time) : 'N/A';
                            ?>
                                <div class="flex items-center gap-3 bg-white border border-slate-200 rounded-lg p-3 transition hover:border-blue-300">
                                    <?php if ($active_tab === 'candidates'): ?>
                                    <input type="checkbox"
                                        class="asset-cb-<?php echo $item_no; ?> w-4 h-4 rounded border-slate-300 accent-red-600 cursor-pointer shrink-0"
                                        value="<?php echo (int)$sub_asset['id']; ?>"
                                        onchange="updateBulkBtn('<?php echo $item_no; ?>')">
                                    <?php else: ?>
                                    <span class="w-4 shrink-0"></span>
                                    <?php endif; ?>

                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 flex-1 text-xs">
                                        <span class="font-mono text-blue-600 font-bold"><?php echo htmlspecialchars($sub_asset['asset_no'] ?: 'N/A'); ?></span>
                                        <span class="text-slate-500">Age: <strong class="text-slate-700"><?php echo $sub_age; ?> yrs</strong></span>
                                        <span class="text-slate-500">Cost: <strong class="text-slate-700">&#8377;<?php echo number_format((float)$sub_asset['cost'], 2); ?></strong></span>
                                        <span class="text-slate-500">Holder: <strong class="text-slate-700"><?php echo htmlspecialchars($sub_holder); ?></strong></span>
                                        <?php if ($active_tab === 'history'): ?>
                                            <span class="text-red-600">Retired: <strong><?php echo htmlspecialchars($sub_retire_fmt); ?></strong></span>
                                        <?php endif; ?>
                                        <?php if (!empty($sub_asset['remarks'])): ?>
                                            <span class="text-slate-400 italic">"<?php echo htmlspecialchars($sub_asset['remarks']); ?>"</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
            <?php $sr_no++; endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Popup for Confirming Asset Write Off -->
<div id="confirmWriteOffModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm no-print">
    <div id="confirmWriteOffModalContainer" class="w-full max-w-md scale-95 transform rounded-2xl bg-white p-6 shadow-xl transition-all duration-200 opacity-0">
        <div class="flex items-center gap-3 border-b border-slate-100 pb-4 mb-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-600 font-bold text-lg shrink-0">
                🗑️
            </div>
            <div>
                <h3 class="text-lg font-bold text-slate-900">Confirm Asset Write-off</h3>
                <p class="text-xs text-slate-500">Remove asset from active inventory</p>
            </div>
        </div>

        <form method="POST" action="dashboard.php?view=write-off-assets">
            <input type="hidden" name="action" value="mark_writeoff">
            <input type="hidden" id="writeoff_modal_asset_id" name="asset_id" value="">
            <input type="hidden" id="writeoff_modal_item_no" name="item_no" value="">
            <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">

            <p class="text-sm text-slate-700 mb-3 leading-relaxed">
                Are you sure you want to write off asset <strong id="writeoff_modal_asset_name" class="text-slate-900"></strong> <span id="writeoff_modal_asset_no_span">(<span id="writeoff_modal_asset_no" class="font-mono text-slate-600"></span>)</span>?
            </p>

            <div class="mb-4">
                <label for="writeoff_reason" class="block text-xs font-bold text-slate-700 mb-1">Write-Off Reason / Remarks</label>
                <textarea id="writeoff_reason" name="writeoff_reason" rows="2" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-100" placeholder="Enter reason for write-off (e.g. Damaged beyond repair, Obsolete equipment)..."></textarea>
                <p class="text-[11px] text-slate-500 mt-1">This reason will overwrite the asset's remarks field.</p>
            </div>

            <div class="mb-4">
                <span class="text-xs text-amber-700 bg-amber-50 p-2.5 rounded-lg border border-amber-200 block">
                    ⚠️ This asset will be marked as <strong>Retired</strong> and moved to the Written-Off Assets Archive.
                </span>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeConfirmWriteOffModal()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-md hover:bg-red-700 transition">
                    Yes, Write Off Asset
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Bulk Write-off Selected Assets -->
<div id="bulkWriteOffModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm no-print">
    <div id="bulkWriteOffModalContainer" class="w-full max-w-md scale-95 transform rounded-2xl bg-white p-6 shadow-xl transition-all duration-200 opacity-0">
        <div class="flex items-center gap-3 border-b border-slate-100 pb-4 mb-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-600 font-bold text-lg shrink-0">
                🗑️
            </div>
            <div>
                <h3 class="text-lg font-bold text-slate-900">Write Off Selected Assets</h3>
                <p id="bulkWriteOffSubtitle" class="text-xs text-slate-500">Multiple assets will be marked as Retired</p>
            </div>
        </div>

        <form method="POST" action="dashboard.php?view=write-off-assets" id="bulkWriteOffForm">
            <input type="hidden" name="action" value="mark_writeoff_bulk">
            <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
            <div id="bulkAssetIdsContainer"></div>

            <p class="text-sm text-slate-700 mb-3 leading-relaxed">
                You are about to write off <strong id="bulkWriteOffCount" class="text-red-600">0</strong> selected asset(s) under Item No: <strong id="bulkWriteOffItemLabel" class="text-slate-900"></strong>.
            </p>

            <div class="mb-4">
                <label for="bulk_writeoff_reason" class="block text-xs font-bold text-slate-700 mb-1">Write-Off Reason / Remarks <span class="text-slate-400 font-normal">(optional)</span></label>
                <textarea id="bulk_writeoff_reason" name="writeoff_reason" rows="2" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-100" placeholder="e.g. Damaged beyond repair, Obsolete equipment..."></textarea>
                <p class="text-[11px] text-slate-500 mt-1">This reason will overwrite the remarks field for all selected assets.</p>
            </div>

            <div class="mb-4">
                <span class="text-xs text-amber-700 bg-amber-50 p-2.5 rounded-lg border border-amber-200 block">
                    ⚠️ Selected assets will be marked as <strong>Retired</strong> and moved to the Written-Off Archive.
                </span>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeBulkWriteOffModal()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2 text-sm font-semibold text-white shadow-md hover:bg-red-700 transition">
                    Yes, Write Off Selected
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Popup for Excel Export -->
<div id="writeOffExportModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm no-print">
    <div id="writeOffExportModalContainer" class="w-full max-w-md scale-95 transform rounded-2xl bg-white p-6 shadow-xl transition-all duration-200 opacity-0">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 font-bold text-lg">
                    📊
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Download Excel Report</h3>
                    <p class="text-xs text-slate-500">Configure columns for the spreadsheet</p>
                </div>
            </div>
            <button onclick="closeWriteOffExportModal()" type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition">
                ✕
            </button>
        </div>

        <!-- Export Form -->
        <form method="GET" action="export-write-off.php">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($name_filter); ?>">
            <input type="hidden" name="issue_from" value="<?php echo htmlspecialchars($issue_from); ?>">
            <input type="hidden" name="issue_to" value="<?php echo htmlspecialchars($issue_to); ?>">

                <div class="border-t border-slate-100 pt-2 space-y-2">
                    <!-- Checkbox 1: Expandable -->
                    <label class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 cursor-pointer transition text-sm text-slate-700 font-semibold">
                        <input type="checkbox" name="categories[]" value="1" checked onchange="updateWriteOffSelectAllState()" class="writeoff-cat-checkbox h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Expandable Register</span>
                    </label>

                    <!-- Checkbox 2: Consumables -->
                    <label class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 cursor-pointer transition text-sm text-slate-700 font-semibold">
                        <input type="checkbox" name="categories[]" value="2" checked onchange="updateWriteOffSelectAllState()" class="writeoff-cat-checkbox h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Consumables Register</span>
                    </label>

                    <!-- Checkbox 3: Deadstock -->
                    <label class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 cursor-pointer transition text-sm text-slate-700 font-semibold">
                        <input type="checkbox" name="categories[]" value="3" checked onchange="updateWriteOffSelectAllState()" class="writeoff-cat-checkbox h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Deadstock Register</span>
                    </label>

                    <!-- Checkbox 4: Furniture -->
                    <label class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 cursor-pointer transition text-sm text-slate-700 font-semibold">
                        <input type="checkbox" name="categories[]" value="4" checked onchange="updateWriteOffSelectAllState()" class="writeoff-cat-checkbox h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span>Furniture Register</span>
                    </label>
                </div>
            </div>

            <!-- Error alert -->
            <div id="writeoffExportError" class="hidden mb-4 rounded-lg bg-red-50 p-2.5 text-xs text-red-600 font-semibold border border-red-200">
                ⚠️ Please select at least one category to download.
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeWriteOffExportModal()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-md hover:bg-emerald-700 transition">
                    📥 Download Excel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openConfirmWriteOffModal(id, name, assetNo, itemNo = '') {
        document.getElementById('writeoff_modal_asset_id').value = id;
        document.getElementById('writeoff_modal_item_no').value = itemNo;
        if (itemNo) {
            document.getElementById('writeoff_modal_asset_name').textContent = "ALL eligible assets under Item No: " + itemNo + " (" + name + ")";
            document.getElementById('writeoff_modal_asset_no_span').style.display = 'none';
        } else {
            document.getElementById('writeoff_modal_asset_name').textContent = name;
            document.getElementById('writeoff_modal_asset_no_span').style.display = 'inline';
            document.getElementById('writeoff_modal_asset_no').textContent = assetNo || 'N/A';
        }
        const reasonInput = document.getElementById('writeoff_reason');
        if (reasonInput) reasonInput.value = '';

        const modal = document.getElementById('confirmWriteOffModal');
        const container = document.getElementById('confirmWriteOffModalContainer');
        if (!modal || !container) return;
        modal.classList.remove('hidden');
        setTimeout(() => {
            container.classList.remove('scale-95', 'opacity-0');
            container.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeConfirmWriteOffModal() {
        const modal = document.getElementById('confirmWriteOffModal');
        const container = document.getElementById('confirmWriteOffModalContainer');
        if (!modal || !container) return;
        container.classList.remove('scale-100', 'opacity-100');
        container.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 200);
    }

    function openWriteOffExportModal() {
        const modal = document.getElementById('writeOffExportModal');
        const container = document.getElementById('writeOffExportModalContainer');
        if (!modal || !container) return;
        modal.classList.remove('hidden');
        setTimeout(() => {
            container.classList.remove('scale-95', 'opacity-0');
            container.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeWriteOffExportModal() {
        const modal = document.getElementById('writeOffExportModal');
        const container = document.getElementById('writeOffExportModalContainer');
        if (!modal || !container) return;
        container.classList.remove('scale-100', 'opacity-100');
        container.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 200);
    }

    function toggleAllWriteOffCategories(selectAllCb) {
        const checkboxes = document.querySelectorAll('.writeoff-cat-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = selectAllCb.checked;
        });
        const err = document.getElementById('writeoffExportError');
        if (err) err.classList.add('hidden');
    }

    function updateWriteOffSelectAllState() {
        const checkboxes = Array.from(document.querySelectorAll('.writeoff-cat-checkbox'));
        const selectAllCb = document.getElementById('writeoff_select_all');
        const allChecked = checkboxes.every(cb => cb.checked);
        if (selectAllCb) {
            selectAllCb.checked = allChecked;
        }
        const err = document.getElementById('writeoffExportError');
        if (err) err.classList.add('hidden');
    }

    function validateWriteOffExportForm(e) {
        const checkboxes = Array.from(document.querySelectorAll('.writeoff-cat-checkbox'));
        const anyChecked = checkboxes.some(cb => cb.checked);
        if (!anyChecked) {
            e.preventDefault();
            const err = document.getElementById('writeoffExportError');
            if (err) err.classList.remove('hidden');
            return false;
        }
        closeWriteOffExportModal();
        return true;
    }

    // ── Toggle accordion detail panel ──
    function toggleGroupDetails(itemNo) {
        const panel = document.getElementById('details-item-' + itemNo);
        const btn   = document.getElementById('btn-toggle-' + itemNo);
        if (!panel) return;
        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            if (btn) btn.innerHTML = '🔼 Hide';
        } else {
            panel.classList.add('hidden');
            if (btn) btn.innerHTML = '🔽 View Detail';
        }
    }

    // ── Checkbox: toggle all checkboxes in a group ──
    function toggleSelectAll(itemNo, checked) {
        document.querySelectorAll('.asset-cb-' + itemNo).forEach(cb => {
            cb.checked = checked;
        });
        updateBulkBtn(itemNo);
    }

    // ── Checkbox: update the bulk button count & visibility ──
    function updateBulkBtn(itemNo) {
        const cbs = document.querySelectorAll('.asset-cb-' + itemNo);
        const checked = Array.from(cbs).filter(cb => cb.checked);
        const btn  = document.getElementById('bulkWriteOffBtn-' + itemNo);
        const span = document.getElementById('bulkCount-' + itemNo);
        const allCb = document.getElementById('selectAll-' + itemNo);

        if (span)  span.textContent = checked.length;
        if (btn)   btn.classList.toggle('hidden', checked.length === 0);
        if (allCb) allCb.indeterminate = (checked.length > 0 && checked.length < cbs.length);
        if (allCb && checked.length === cbs.length) allCb.checked = true;
        if (allCb && checked.length === 0) { allCb.checked = false; allCb.indeterminate = false; }
    }

    // ── Open bulk write-off modal ──
    function openBulkWriteOffModal(itemNo, assetName) {
        const cbs = Array.from(document.querySelectorAll('.asset-cb-' + itemNo)).filter(cb => cb.checked);
        if (cbs.length === 0) return;

        // Populate hidden inputs
        const container = document.getElementById('bulkAssetIdsContainer');
        container.innerHTML = '';
        cbs.forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'asset_ids[]';
            inp.value = cb.value;
            container.appendChild(inp);
        });

        document.getElementById('bulkWriteOffCount').textContent = cbs.length;
        document.getElementById('bulkWriteOffItemLabel').textContent = itemNo + ' (' + assetName + ')';
        const reasonEl = document.getElementById('bulk_writeoff_reason');
        if (reasonEl) reasonEl.value = '';

        const modal = document.getElementById('bulkWriteOffModal');
        const mc    = document.getElementById('bulkWriteOffModalContainer');
        modal.classList.remove('hidden');
        setTimeout(() => {
            mc.classList.remove('scale-95', 'opacity-0');
            mc.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    // ── Close bulk write-off modal ──
    function closeBulkWriteOffModal() {
        const modal = document.getElementById('bulkWriteOffModal');
        const mc    = document.getElementById('bulkWriteOffModalContainer');
        mc.classList.remove('scale-100', 'opacity-100');
        mc.classList.add('scale-95', 'opacity-0');
        setTimeout(() => modal.classList.add('hidden'), 200);
    }
</script>
