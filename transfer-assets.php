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

$selectedCategory = isset($_GET['category']) && isset($category_names[(int)$_GET['category']])
    ? (int)$_GET['category']
    : 1;
$name_filter = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
$transfer_to_filter = isset($_GET['transfer_to']) ? trim((string)$_GET['transfer_to']) : '';
$transfer_from = isset($_GET['transfer_from']) ? trim((string)$_GET['transfer_from']) : '';
$transfer_to = isset($_GET['transfer_to_date']) ? trim((string)$_GET['transfer_to_date']) : '';
$transfer_assets = [];
$category_counts = array_fill_keys(array_keys($category_names), 0);
$total_transferred = 0;

$transfer_to_options = [];
$result = $conn->query("SELECT DISTINCT transfer_to FROM assets WHERE transferred = 1 AND transfer_to IS NOT NULL AND transfer_to <> '' ORDER BY transfer_to ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $transfer_to_options[] = $row['transfer_to'];
    }
}

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $bind_names = [];
    $bind_names[] = $types;
    foreach ($params as $key => &$value) {
        $bind_names[$key + 1] = &$value;
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

function fetch_transfer_assets(mysqli $conn, int $category_id, string $name_filter = '', string $transfer_to_filter = '', string $transfer_from = '', string $transfer_to = ''): array
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
            batch_id,
            transfer_to,
            transfer_date
        FROM assets
        WHERE category_id = ?
          AND transferred = 1
    ";

    $types = 'i';
    $params = [$category_id];

    if ($name_filter !== '') {
        $sql .= " AND asset_name LIKE ?";
        $types .= 's';
        $params[] = '%' . $name_filter . '%';
    }

    if ($transfer_to_filter !== '') {
        $sql .= " AND transfer_to LIKE ?";
        $types .= 's';
        $params[] = '%' . $transfer_to_filter . '%';
    }

    if ($transfer_from !== '') {
        $sql .= " AND transfer_date >= ?";
        $types .= 's';
        $params[] = $transfer_from;
    }

    if ($transfer_to !== '') {
        $sql .= " AND transfer_date <= ?";
        $types .= 's';
        $params[] = $transfer_to;
    }

    $sql .= " ORDER BY transfer_date DESC, asset_name ASC, item_no ASC, asset_no ASC";

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

function build_transfer_url(int $category_id, string $name_filter, string $transfer_to_filter, string $transfer_from, string $transfer_to): string
{
    $query = [
        'view' => 'transfer-assets',
        'category' => $category_id,
    ];

    if ($name_filter !== '') {
        $query['name'] = $name_filter;
    }
    if ($transfer_to_filter !== '') {
        $query['transfer_to'] = $transfer_to_filter;
    }
    if ($transfer_from !== '') {
        $query['transfer_from'] = $transfer_from;
    }
    if ($transfer_to !== '') {
        $query['transfer_to_date'] = $transfer_to;
    }

    return 'dashboard.php?' . http_build_query($query);
}

foreach ($category_names as $category_id => $category_name) {
    $assets = fetch_transfer_assets($conn, $category_id, $name_filter, $transfer_to_filter, $transfer_from, $transfer_to);
    $transfer_assets[$category_id] = $assets;
    $category_counts[$category_id] = count($assets);
    $total_transferred += $category_counts[$category_id];
}

$selected_category_name = $category_names[$selectedCategory];
$selected_assets = $transfer_assets[$selectedCategory] ?? [];
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
        grid-template-columns: 1.4fr 1fr 0.9fr 0.9fr auto auto;
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

    .wo-field input,
    .wo-field select {
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

    .wo-field select {
        appearance: auto;
    }

    .wo-field input:focus,
    .wo-field select:focus {
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
        grid-template-columns: 48px minmax(120px, 1fr) minmax(180px, 2fr) minmax(150px, 1.2fr) minmax(120px, 1fr);
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
        grid-template-columns: 48px minmax(120px, 1fr) minmax(180px, 2fr) minmax(150px, 1.2fr) minmax(120px, 1fr);
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
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Transfer Assets</h1>
            <p class="text-sm text-slate-500">List of assets that have been transferred.</p>
        </div>
    </div>

    <form method="GET" action="dashboard.php" class="wo-filter-bar no-print">
        <input type="hidden" name="view" value="transfer-assets">
        <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
        <div class="wo-field">
            <label for="name">Asset Name</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name_filter); ?>" placeholder="Search by asset name">
        </div>
        <div class="wo-field">
            <label for="transfer_to">Transfer To</label>
            <select id="transfer_to" name="transfer_to">
                <option value="">All</option>
                <?php foreach ($transfer_to_options as $option): ?>
                    <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $transfer_to_filter === $option ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wo-field">
            <label for="transfer_from">Transfer Date From</label>
            <input type="date" id="transfer_from" name="transfer_from" value="<?php echo htmlspecialchars($transfer_from); ?>">
        </div>
        <div class="wo-field">
            <label for="transfer_to_date">Transfer Date To</label>
            <input type="date" id="transfer_to_date" name="transfer_to_date" value="<?php echo htmlspecialchars($transfer_to); ?>">
        </div>
        <button type="submit" class="wo-btn wo-btn-primary">
            <i data-lucide="search" style="width:18px;height:18px"></i>
            Search
        </button>
        <a href="dashboard.php?view=transfer-assets&category=<?php echo (int)$selectedCategory; ?>" class="wo-btn wo-btn-secondary">
            Clear
        </a>
    </form>

    <div class="wo-tabs no-print">
        <?php foreach ($category_names as $category_id => $category_name): ?>
            <a href="<?php echo htmlspecialchars(build_transfer_url($category_id, $name_filter, $transfer_to_filter, $transfer_from, $transfer_to)); ?>"
               class="<?php echo $selectedCategory === $category_id ? 'active-tab' : ''; ?>">
                <?php echo htmlspecialchars($category_name); ?>
                <span class="wo-tab-count"><?php echo number_format($category_counts[$category_id]); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="wo-shell">
        <div class="wo-header-area">
            <div class="wo-title"><?php echo htmlspecialchars($selected_category_name); ?> Transfer List</div>
            <div class="wo-subtitle">Assets that have been transferred out.</div>
            <div class="wo-page-line">
                <div>Total Transferred: <strong><?php echo number_format($total_transferred); ?></strong></div>
                <div class="wo-sheet-meta">
                    <span class="wo-pill">Category: <?php echo htmlspecialchars($selected_category_name); ?></span>
                    <span class="wo-pill">Rows: <?php echo number_format(count($selected_assets)); ?></span>
                    <?php if ($name_filter !== ''): ?>
                        <span class="wo-pill">Name: <?php echo htmlspecialchars($name_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($transfer_to_filter !== ''): ?>
                        <span class="wo-pill">Transfer To: <?php echo htmlspecialchars($transfer_to_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($transfer_from !== '' || $transfer_to !== ''): ?>
                        <span class="wo-pill">
                            Date:
                            <?php echo htmlspecialchars($transfer_from !== '' ? date('d/m/Y', strtotime($transfer_from)) : 'Any'); ?>
                            -
                            <?php echo htmlspecialchars($transfer_to !== '' ? date('d/m/Y', strtotime($transfer_to)) : 'Any'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="wo-list-head">
            <div>Sr</div>
            <div>Asset No</div>
            <div>Asset Name</div>
            <div>Transfer To</div>
            <div>Transfer Date</div>
        </div>

        <div>
            <?php if (empty($selected_assets)): ?>
                <div class="wo-empty py-10 text-center text-slate-500">No transferred assets found in this category.</div>
            <?php else: ?>
                <?php $sr_no = 1; ?>
                <?php foreach ($selected_assets as $asset): ?>
                    <?php
                        $holder = $asset['assigned_to'] ?: ($asset['location'] ?: 'N/A');
                    ?>
                    <div class="wo-list-row">
                        <div class="wo-cell wo-sr"><?php echo $sr_no; ?></div>

                        <div class="wo-cell wo-mono">
                            <?php echo htmlspecialchars($asset['asset_no'] ?: 'N/A'); ?>
                        </div>

                        <div class="wo-cell">
                            <div class="wo-primary"><?php echo htmlspecialchars($asset['asset_name']); ?></div>
                            <div class="wo-secondary">Item No: <?php echo htmlspecialchars($asset['item_no'] ?: 'N/A'); ?></div>
                        </div>

                        <div class="wo-cell wo-location">
                            <?php echo htmlspecialchars($asset['transfer_to'] ?: 'N/A'); ?>
                        </div>

                        <div class="wo-cell wo-meta">
                            <?php echo $asset['transfer_date'] ? date('d/m/Y', strtotime($asset['transfer_date'])) : 'N/A'; ?>
                        </div>
                    </div>
                <?php $sr_no++; endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>