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

$scan_completed = isset($_GET['scan']) && $_GET['scan'] === '1';
$selectedCategory = isset($_GET['category']) && isset($category_names[(int)$_GET['category']])
    ? (int)$_GET['category']
    : 1;
$name_filter = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
$issue_from = isset($_GET['issue_from']) ? trim((string)$_GET['issue_from']) : '';
$issue_to = isset($_GET['issue_to']) ? trim((string)$_GET['issue_to']) : '';
$cutoff_date = date('Y-m-d', strtotime('-5 years'));
$write_off_assets = [];
$category_counts = array_fill_keys(array_keys($category_names), 0);
$total_candidates = 0;

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $bind_names = [];
    $bind_names[] = $types;
    foreach ($params as $key => &$value) {
        $bind_names[$key + 1] = &$value;
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

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
    if (!$stmt) {
        return [];
    }

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

function build_writeoff_url(int $category_id, bool $scan_completed, string $name_filter, string $issue_from, string $issue_to): string
{
    $query = [
        'view' => 'write-off-assets',
        'category' => $category_id,
    ];

    if ($scan_completed) {
        $query['scan'] = '1';
    }
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

if ($scan_completed) {
    foreach ($category_names as $category_id => $category_name) {
        $assets = fetch_write_off_assets($conn, $category_id, $cutoff_date, $name_filter, $issue_from, $issue_to);
        $write_off_assets[$category_id] = $assets;
        $category_counts[$category_id] = count($assets);
        $total_candidates += $category_counts[$category_id];
    }
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
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
    }

    .wo-header-area {
        padding: 22px 22px 18px 22px;
        background:
            radial-gradient(circle at top right, rgba(59, 130, 246, 0.10), transparent 28%),
            linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-bottom: 1px solid #e2e8f0;
    }

    .wo-title {
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .wo-subtitle {
        font-size: 0.9rem;
        color: #64748b;
        margin-top: 4px;
    }

    .wo-page-line {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        margin-top: 14px;
        flex-wrap: wrap;
    }

    .wo-sheet-meta {
        display: inline-flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .wo-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 9999px;
        padding: 0.3rem 0.75rem;
        font-size: 0.76rem;
        font-weight: 700;
        color: #334155;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .wo-filter-bar {
        display: grid;
        grid-template-columns: 1.4fr 0.9fr 0.9fr auto auto;
        gap: 12px;
        align-items: end;
        margin-bottom: 14px;
    }

    .wo-field label {
        display: block;
        margin-bottom: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .wo-field input {
        width: 100%;
        border: 1px solid #cbd5e1;
        background: #fff;
        border-radius: 0.75rem;
        padding: 0.72rem 0.9rem;
        font-size: 0.9rem;
        color: #0f172a;
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .wo-field input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
    }

    .wo-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        border: 1px solid transparent;
        border-radius: 0.75rem;
        padding: 0.72rem 1rem;
        font-size: 0.9rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.15s ease;
        white-space: nowrap;
    }

    .wo-btn-primary {
        background: #2563eb;
        color: #fff;
        box-shadow: 0 8px 18px rgba(37, 99, 235, 0.18);
    }

    .wo-btn-primary:hover {
        background: #1d4ed8;
    }

    .wo-btn-secondary {
        background: #fff;
        color: #475569;
        border-color: #cbd5e1;
    }

    .wo-btn-secondary:hover {
        background: #f8fafc;
    }

    .wo-list-head {
        display: grid;
        grid-template-columns: 48px minmax(220px, 2fr) minmax(150px, 1fr) minmax(200px, 1.2fr) 94px;
        gap: 16px;
        align-items: center;
        padding: 14px 22px;
        border-bottom: 1px solid #e2e8f0;
        background: #ffffff;
        color: #94a3b8;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .wo-list-row {
        display: grid;
        grid-template-columns: 48px minmax(220px, 2fr) minmax(150px, 1fr) minmax(200px, 1.2fr) 94px;
        gap: 16px;
        align-items: center;
        padding: 18px 22px;
        border-bottom: 1px solid #eef2f7;
        transition: background-color 150ms ease;
    }

    .wo-list-row:hover {
        background: #f8fafc;
    }

    .wo-cell {
        min-width: 0;
    }

    .wo-sr {
        font-size: 0.8rem;
        font-weight: 700;
        color: #475569;
    }

    .wo-primary {
        font-size: 0.86rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.35;
    }

    .wo-secondary {
        margin-top: 3px;
        font-size: 0.76rem;
        color: #64748b;
        line-height: 1.3;
    }

    .wo-meta {
        font-size: 0.82rem;
        color: #334155;
        line-height: 1.35;
    }

    .wo-mono {
        font-family: 'Courier New', monospace;
        font-size: 0.74rem;
        color: #334155;
        word-break: break-word;
    }

    .wo-location {
        font-size: 0.82rem;
        color: #334155;
        line-height: 1.35;
    }

    .wo-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        padding: 0.34rem 0.8rem;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1;
        border: 1px solid transparent;
    }

    .wo-status-active { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .wo-status-maintenance { background: #fef9c3; color: #a16207; border-color: #fde68a; }
    .wo-status-retired { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }

    .wo-empty {
        padding: 28px 22px;
        text-align: center;
        color: #64748b;
    }

    .wo-tab-count {
        display: inline-flex;
        min-width: 1.4rem;
        height: 1.4rem;
        align-items: center;
        justify-content: center;
        margin-left: 0.45rem;
        border-radius: 9999px;
        background: rgba(255, 255, 255, 0.18);
        color: inherit;
        font-size: 0.72rem;
        font-weight: 700;
    }

    @media print {
        .no-print { display: none !important; }
        .wo-list-head,
        .wo-list-row {
            break-inside: avoid;
        }
    }
</style>

<div class="wo-wrap space-y-3 pb-10">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between mb-3 no-print">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Write-off Assets</h1>
            <p class="text-sm text-slate-500">Clean category-wise list view for write-off candidates.</p>
        </div>
        <form method="GET" action="dashboard.php" class="shrink-0">
            <input type="hidden" name="view" value="write-off-assets">
            <input type="hidden" name="scan" value="1">
            <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($name_filter); ?>">
            <input type="hidden" name="issue_from" value="<?php echo htmlspecialchars($issue_from); ?>">
            <input type="hidden" name="issue_to" value="<?php echo htmlspecialchars($issue_to); ?>">
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition no-print">
                <i data-lucide="scan-search" style="width:18px;height:18px"></i>
                Scan Assets
            </button>
        </form>
    </div>

    <?php if (!$scan_completed): ?>
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-white text-blue-600 shadow-sm">
                <i data-lucide="scan-line" style="width:26px;height:26px"></i>
            </div>
            <h2 class="mt-4 text-lg font-semibold text-slate-900">Scan not started</h2>
            <p class="mt-1 text-sm text-slate-500">Click Scan Assets to show category-wise write-off candidates issued on or before <?php echo date('M d, Y', strtotime($cutoff_date)); ?>.</p>
        </div>
    <?php else: ?>
        <form method="GET" action="dashboard.php" class="wo-filter-bar no-print">
            <input type="hidden" name="view" value="write-off-assets">
            <input type="hidden" name="scan" value="1">
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
            <a href="dashboard.php?view=write-off-assets&scan=1&category=<?php echo (int)$selectedCategory; ?>" class="wo-btn wo-btn-secondary">
                Clear
            </a>
        </form>

        <div class="wo-tabs no-print">
            <?php foreach ($category_names as $category_id => $category_name): ?>
                <a href="<?php echo htmlspecialchars(build_writeoff_url($category_id, true, $name_filter, $issue_from, $issue_to)); ?>"
                   class="<?php echo $selectedCategory === $category_id ? 'active-tab' : ''; ?>">
                    <?php echo htmlspecialchars($category_name); ?>
                    <span class="wo-tab-count"><?php echo number_format($category_counts[$category_id]); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="wo-shell">
            <div class="wo-header-area">
                <div class="wo-title"><?php echo htmlspecialchars($selected_category_name); ?> Write-Off List</div>
                <div class="wo-subtitle">Candidates older than 5 years, shown row by row.</div>
            <div class="wo-page-line">
                <div>Cutoff Date: <strong><?php echo date('M d, Y', strtotime($cutoff_date)); ?></strong></div>
                <div class="wo-sheet-meta">
                    <span class="wo-pill">Total: <?php echo number_format($total_candidates); ?></span>
                    <span class="wo-pill">Category: <?php echo htmlspecialchars($selected_category_name); ?></span>
                        <span class="wo-pill">Rows: <?php echo number_format(count($selected_assets)); ?></span>
                        <?php if ($name_filter !== ''): ?>
                            <span class="wo-pill">Name: <?php echo htmlspecialchars($name_filter); ?></span>
                        <?php endif; ?>
                        <?php if ($issue_from !== '' || $issue_to !== ''): ?>
                            <span class="wo-pill">
                                Date:
                                <?php echo htmlspecialchars($issue_from !== '' ? date('d/m/Y', strtotime($issue_from)) : 'Any'); ?>
                                -
                                <?php echo htmlspecialchars($issue_to !== '' ? date('d/m/Y', strtotime($issue_to)) : 'Any'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="wo-list-head">
                <div>Sr</div>
                <div>Asset No / Asset Name</div>
                <div>Issue / Age</div>
                <div>Location / Faculty</div>
                <div>Status</div>
            </div>

            <div>
                <?php if (empty($selected_assets)): ?>
                    <div class="wo-empty py-10 text-center text-slate-500">No 5+ years old active assets found in this category register.</div>
                <?php else: ?>
                    <?php $sr_no = 1; ?>
                    <?php foreach ($selected_assets as $asset): ?>
                        <?php
                            $issue_time = strtotime($asset['date_of_issue']);
                            $age_years = $issue_time ? date_diff(date_create($asset['date_of_issue']), date_create('today'))->y : 0;
                            $holder = $asset['location'] ?: ($asset['assigned_to'] ?: 'N/A');
                            $status = trim($asset['status'] ?: 'Active');
                            $statusClass = 'wo-status-active';
                            if (stripos($status, 'maintenance') !== false) {
                                $statusClass = 'wo-status-maintenance';
                            } elseif (stripos($status, 'retire') !== false || stripos($status, 'write') !== false) {
                                $statusClass = 'wo-status-retired';
                            }
                        ?>
                        <div class="wo-list-row">
                            <div class="wo-cell wo-sr"><?php echo $sr_no; ?></div>

                            <div class="wo-cell">
                                <div class="wo-mono"><?php echo htmlspecialchars($asset['asset_no'] ?: 'N/A'); ?></div>
                                <div class="wo-primary mt-1"><?php echo htmlspecialchars($asset['asset_name']); ?></div>
                                <div class="wo-secondary">Item No: <?php echo htmlspecialchars($asset['item_no'] ?: 'N/A'); ?> | Page No: <?php echo htmlspecialchars($asset['page_no'] ?: 'N/A'); ?></div>
                            </div>

                            <div class="wo-cell">
                                <div class="wo-meta"><?php echo $issue_time ? date('d/m/Y', $issue_time) : 'N/A'; ?></div>
                                <div class="wo-secondary">Age: <strong class="text-slate-700"><?php echo (int)$age_years; ?> yrs</strong></div>
                                <div class="wo-secondary">Cost: <strong class="text-slate-700">&#8377;<?php echo number_format((float)$asset['cost'], 2); ?></strong></div>
                            </div>

                            <div class="wo-cell wo-location">
                                <?php echo htmlspecialchars($holder); ?>
                            </div>

                            <div class="wo-cell" style="display:flex; justify-content:flex-start;">
                                <span class="wo-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                            </div>
                        </div>
                    <?php $sr_no++; endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
