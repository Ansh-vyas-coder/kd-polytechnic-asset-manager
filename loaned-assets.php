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
$loan_to_filter = isset($_GET['loan_to']) ? trim((string)$_GET['loan_to']) : '';
$loan_from = isset($_GET['loan_from']) ? trim((string)$_GET['loan_from']) : '';
$loan_to = isset($_GET['loan_to_date']) ? trim((string)$_GET['loan_to_date']) : '';
$borrow_from = isset($_GET['borrow_from']) ? trim((string)$_GET['borrow_from']) : '';
$borrow_to = isset($_GET['borrow_to']) ? trim((string)$_GET['borrow_to']) : '';
$borrowed_name_filter = isset($_GET['b_name']) ? trim((string)$_GET['b_name']) : '';
$borrowed_from_filter = isset($_GET['b_borrowed_from']) ? trim((string)$_GET['b_borrowed_from']) : '';
$location_filter = isset($_GET['b_location']) ? trim((string)$_GET['b_location']) : '';
$assigned_to_filter = isset($_GET['b_assigned_to']) ? trim((string)$_GET['b_assigned_to']) : '';
$loaned_assets = [];
$category_counts = array_fill_keys(array_keys($category_names), 0);
$total_loaned = 0;

$loan_to_options = [];
$result = $conn->query("SELECT DISTINCT loan_to FROM assets WHERE status = 'Loaned' AND loan_to IS NOT NULL AND loan_to <> '' ORDER BY loan_to ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $loan_to_options[] = $row['loan_to'];
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

function fetch_loaned_assets(mysqli $conn, int $category_id, string $name_filter = '', string $loan_to_filter = '', string $loan_from = '', string $loan_to = ''): array
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
            loan_to,
            loan_date,
            return_date
        FROM assets
        WHERE category_id = ?
          AND status = 'Loaned'
    ";

    $types = 'i';
    $params = [$category_id];

    if ($name_filter !== '') {
        $sql .= " AND asset_name LIKE ?";
        $types .= 's';
        $params[] = '%' . $name_filter . '%';
    }

    if ($loan_to_filter !== '') {
        $sql .= " AND loan_to = ?";
        $types .= 's';
        $params[] = $loan_to_filter;
    }

    if ($loan_from !== '') {
        $sql .= " AND loan_date >= ?";
        $types .= 's';
        $params[] = $loan_from;
    }

    if ($loan_to !== '') {
        $sql .= " AND loan_date <= ?";
        $types .= 's';
        $params[] = $loan_to;
    }

    $sql .= " ORDER BY loan_date DESC, asset_name ASC, item_no ASC, asset_no ASC";

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

function build_borrowed_url(int $category_id, string $name_filter, string $borrowed_from_filter, string $location_filter, string $assigned_to_filter, string $section = 'borrowed'): string
{
    $query = [
        'view' => 'loaned-assets',
        'category' => $category_id,
        'section' => $section,
    ];

    if ($name_filter !== '') {
        $query['b_name'] = $name_filter;
    }
    if ($borrowed_from_filter !== '') {
        $query['b_borrowed_from'] = $borrowed_from_filter;
    }
    if ($location_filter !== '') {
        $query['b_location'] = $location_filter;
    }
    if ($assigned_to_filter !== '') {
        $query['b_assigned_to'] = $assigned_to_filter;
    }

    return 'dashboard.php?' . http_build_query($query);
}

function build_loaned_url(int $category_id, string $name_filter, string $loan_to_filter, string $loan_from, string $loan_to, string $section = 'loaned'): string
{
    $query = [
        'view' => 'loaned-assets',
        'category' => $category_id,
        'section' => $section,
    ];

    if ($name_filter !== '') {
        $query['name'] = $name_filter;
    }
    if ($loan_to_filter !== '') {
        $query['loan_to'] = $loan_to_filter;
    }
    if ($loan_from !== '') {
        $query['loan_from'] = $loan_from;
    }
    if ($loan_to !== '') {
        $query['loan_to_date'] = $loan_to;
    }

    return 'dashboard.php?' . http_build_query($query);
}

foreach ($category_names as $category_id => $category_name) {
    $assets = fetch_loaned_assets($conn, $category_id, $name_filter, $loan_to_filter, $loan_from, $loan_to);
    $loaned_assets[$category_id] = $assets;
    $category_counts[$category_id] = count($assets);
    $total_loaned += $category_counts[$category_id];
}

$selected_category_name = $category_names[$selectedCategory];
$selected_assets = $loaned_assets[$selectedCategory] ?? [];

$borrowed_assets = [];
$borrowed_category_counts = array_fill_keys(array_keys($category_names), 0);
$total_borrowed = 0;

$borrowed_from_options = [];
$borrowed_result = $conn->query("SELECT DISTINCT borrowed_from FROM borrowed_assets WHERE borrowed_from IS NOT NULL AND borrowed_from <> '' ORDER BY borrowed_from ASC");
if ($borrowed_result) {
    while ($row = $borrowed_result->fetch_assoc()) {
        $borrowed_from_options[] = $row['borrowed_from'];
    }
}

function fetch_borrowed_assets(mysqli $conn, int $category_id, string $name_filter = '', string $borrowed_from_filter = '', string $location_filter = '', string $assigned_to_filter = ''): array
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
            remarks,
            borrowed_from,
            borrow_date,
            return_date
                FROM borrowed_assets
                WHERE category_id = ?
                    AND (status IS NULL OR status <> 'Returned')
    ";

    $types = 'i';
    $params = [$category_id];

    if ($name_filter !== '') {
        $sql .= " AND asset_name LIKE ?";
        $types .= 's';
        $params[] = '%' . $name_filter . '%';
    }

    if ($borrowed_from_filter !== '') {
        $sql .= " AND borrowed_from = ?";
        $types .= 's';
        $params[] = $borrowed_from_filter;
    }

    if ($location_filter !== '') {
        $sql .= " AND location = ?";
        $types .= 's';
        $params[] = $location_filter;
    }

    if ($assigned_to_filter !== '') {
        $sql .= " AND assigned_to = ?";
        $types .= 's';
        $params[] = $assigned_to_filter;
    }

    $sql .= " ORDER BY borrow_date DESC, asset_name ASC, item_no ASC, asset_no ASC";

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

foreach ($category_names as $category_id => $category_name) {
    $b_assets = fetch_borrowed_assets($conn, $category_id, $borrowed_name_filter, $borrowed_from_filter, $location_filter, $assigned_to_filter);
    $borrowed_assets[$category_id] = $b_assets;
    $borrowed_category_counts[$category_id] = count($b_assets);
    $total_borrowed += $borrowed_category_counts[$category_id];
}

$selected_borrowed_assets = $borrowed_assets[$selectedCategory] ?? [];

$active_section = isset($_GET['section']) && in_array($_GET['section'], ['loaned', 'borrowed'], true)
    ? $_GET['section']
    : 'loaned';
?>

<style>
    .la-wrap {
        font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
        color: #0f172a;
    }

    .la-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 12px;
    }

    .la-tabs a {
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

    .la-tabs a.active-tab {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
        box-shadow: 0 8px 20px rgba(37, 99, 235, 0.18);
    }

    .la-tabs a:hover:not(.active-tab) {
        background: #f8fafc;
        border-color: #cbd5e1;
    }

    .la-shell {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
    }

    .la-header-area {
        padding: 22px 22px 18px 22px;
        background:
            radial-gradient(circle at top right, rgba(37, 99, 235, 0.10), transparent 28%),
            linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-bottom: 1px solid #e2e8f0;
    }

    .la-title {
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .la-subtitle {
        font-size: 0.9rem;
        color: #64748b;
        margin-top: 4px;
    }

    .la-page-line {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        margin-top: 14px;
        flex-wrap: wrap;
    }

    .la-sheet-meta {
        display: inline-flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .la-pill {
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

    .la-filter-bar {
        display: grid;
        grid-template-columns: 1.4fr 1fr 0.9fr 0.9fr auto auto;
        gap: 12px;
        align-items: end;
        margin-bottom: 14px;
    }

    .la-field label {
        display: block;
        margin-bottom: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .la-field input,
    .la-field select {
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

    .la-field select {
        appearance: auto;
    }

    .la-field input:focus,
    .la-field select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
    }

    .la-btn {
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

    .la-btn-primary {
        background: #2563eb;
        color: #fff;
        box-shadow: 0 8px 18px rgba(37, 99, 235, 0.18);
    }

    .la-btn-primary:hover {
        background: #1d4ed8;
    }

    .la-btn-secondary {
        background: #fff;
        color: #475569;
        border-color: #cbd5e1;
    }

    .la-btn-secondary:hover {
        background: #f8fafc;
    }

    .la-btn-success {
        background: #16a34a;
        color: #fff;
        box-shadow: 0 8px 18px rgba(22, 163, 74, 0.18);
    }

    .la-btn-success:hover {
        background: #15803d;
    }

    .la-list-head {
        display: grid;
        grid-template-columns: 48px minmax(120px, 1fr) minmax(180px, 2fr) minmax(150px, 1.2fr) minmax(150px, 1.2fr) minmax(120px, 1fr) minmax(120px, 1fr) minmax(100px, 0.8fr);
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

    .la-list-row {
        display: grid;
        grid-template-columns: 48px minmax(120px, 1fr) minmax(180px, 2fr) minmax(150px, 1.2fr) minmax(150px, 1.2fr) minmax(120px, 1fr) minmax(120px, 1fr) minmax(100px, 0.8fr);
        gap: 16px;
        align-items: center;
        padding: 18px 22px;
        border-bottom: 1px solid #eef2f7;
        transition: background-color 150ms ease;
    }

    .la-list-row:hover {
        background: #f8fafc;
    }

    .la-cell {
        min-width: 0;
    }

    .la-sr {
        font-size: 0.8rem;
        font-weight: 700;
        color: #475569;
    }

    .la-primary {
        font-size: 0.86rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.35;
    }

    .la-secondary {
        margin-top: 3px;
        font-size: 0.76rem;
        color: #64748b;
        line-height: 1.3;
    }

    .la-meta {
        font-size: 0.82rem;
        color: #334155;
        line-height: 1.35;
    }

    .la-mono {
        font-family: 'Courier New', monospace;
        font-size: 0.74rem;
        color: #334155;
        word-break: break-word;
    }

    .la-location {
        font-size: 0.82rem;
        color: #334155;
        line-height: 1.35;
    }

    .la-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        padding: 0.34rem 0.8rem;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1;
        border: 1px solid transparent;
        background: #fef3c7;
        color: #a16207;
        border-color: #fde68a;
    }

    .la-empty {
        padding: 28px 22px;
        text-align: center;
        color: #64748b;
    }

    .la-tab-count {
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
        .la-list-head,
        .la-list-row {
            break-inside: avoid;
        }
    }
</style>

<div class="la-wrap space-y-3 pb-10">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between mb-3 no-print">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Asset Loans & Borrows</h1>
            <p class="text-sm text-slate-500">Manage loaned and borrowed assets.</p>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php?view=loaned-assets&section=loaned&category=<?php echo (int)$selectedCategory; ?>" class="la-btn <?php echo $active_section === 'loaned' ? 'la-btn-primary' : 'la-btn-secondary'; ?>">
                Loaned Assets
            </a>
            <a href="dashboard.php?view=loaned-assets&section=borrowed&category=<?php echo (int)$selectedCategory; ?>" class="la-btn <?php echo $active_section === 'borrowed' ? 'la-btn-primary' : 'la-btn-secondary'; ?>">
                Borrowed Assets
            </a>
        </div>
    </div>

    <div id="loaned-section" <?php echo $active_section !== 'loaned' ? 'style="display:none;"' : ''; ?>>
        <form method="GET" action="dashboard.php" class="la-filter-bar no-print">
            <input type="hidden" name="view" value="loaned-assets">
            <input type="hidden" name="section" value="loaned">
            <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
            <div class="la-field">
                <label for="name">Asset Name</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name_filter); ?>" placeholder="Search by asset name">
            </div>
            <div class="la-field">
                <label for="loan_to">Loan To</label>
                <select id="loan_to" name="loan_to">
                    <option value="">All</option>
                    <?php foreach ($loan_to_options as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $loan_to_filter === $option ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="la-field">
                <label for="loan_from">Loan Date From</label>
                <input type="date" id="loan_from" name="loan_from" value="<?php echo htmlspecialchars($loan_from); ?>">
            </div>
            <div class="la-field">
                <label for="loan_to_date">Loan Date To</label>
                <input type="date" id="loan_to_date" name="loan_to_date" value="<?php echo htmlspecialchars($loan_to); ?>">
            </div>
            <button type="submit" class="la-btn la-btn-primary">
                <i data-lucide="search" style="width:18px;height:18px"></i>
                Search
            </button>
            <a href="dashboard.php?view=loaned-assets&section=loaned&category=<?php echo (int)$selectedCategory; ?>" class="la-btn la-btn-secondary">
                Clear
            </a>
        </form>

    <div class="la-tabs no-print">
        <?php foreach ($category_names as $category_id => $category_name): ?>
            <a href="<?php echo htmlspecialchars(build_loaned_url($category_id, $name_filter, $loan_to_filter, $loan_from, $loan_to, $active_section)); ?>"
               class="<?php echo $selectedCategory === $category_id ? 'active-tab' : ''; ?>">
                <?php echo htmlspecialchars($category_name); ?>
                <span class="la-tab-count"><?php echo number_format($category_counts[$category_id]); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="la-shell">
        <div class="la-header-area">
            <div class="la-title"><?php echo htmlspecialchars($selected_category_name); ?> Loaned List</div>
            <div class="la-subtitle">Assets that have been loaned out.</div>
            <div class="la-page-line">
                <div>Total Loaned: <strong><?php echo number_format($total_loaned); ?></strong></div>
                <div class="la-sheet-meta">
                    <span class="la-pill">Category: <?php echo htmlspecialchars($selected_category_name); ?></span>
                    <span class="la-pill">Rows: <?php echo number_format(count($selected_assets)); ?></span>
                    <?php if ($name_filter !== ''): ?>
                        <span class="la-pill">Name: <?php echo htmlspecialchars($name_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($loan_to_filter !== ''): ?>
                        <span class="la-pill">Loan To: <?php echo htmlspecialchars($loan_to_filter); ?></span>
                    <?php endif; ?>
                    <?php if ($loan_from !== '' || $loan_to !== ''): ?>
                        <span class="la-pill">
                            Date:
                            <?php echo htmlspecialchars($loan_from !== '' ? date('d/m/Y', strtotime($loan_from)) : 'Any'); ?>
                            -
                            <?php echo htmlspecialchars($loan_to !== '' ? date('d/m/Y', strtotime($loan_to)) : 'Any'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="la-list-head" style="display: grid; grid-template-columns: 50px 80px 2fr 1.5fr 2fr 120px; align-items: center; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 16px; font-size: 0.72rem; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
            <div>Sr</div>
            <div>Item No</div>
            <div>Asset Name &amp; Loaned Qty</div>
            <div>Loaned To</div>
            <div>Loan/Return Dates</div>
            <div class="text-right">Action</div>
        </div>

        <div>
            <?php if (empty($selected_assets)): ?>
                <div class="la-empty py-10 text-center text-slate-500">No loaned assets found in this category.</div>
            <?php else:
                // Group by item_no
                $grouped = [];
                foreach ($selected_assets as $asset) {
                    $item_no = $asset['item_no'] ?: 'Uncategorized';
                    if (!isset($grouped[$item_no])) {
                        $grouped[$item_no] = [
                            'item_no' => $item_no,
                            'asset_name' => $asset['asset_name'],
                            'loan_recipients' => [],
                            'loan_date_min' => $asset['loan_date'],
                            'loan_date_max' => $asset['loan_date'],
                            'return_date_min' => $asset['return_date'],
                            'return_date_max' => $asset['return_date'],
                            'items' => []
                        ];
                    }
                    $grouped[$item_no]['items'][] = $asset;
                    $recipient = $asset['loan_to'] ?: 'N/A';
                    if (!in_array($recipient, $grouped[$item_no]['loan_recipients'])) {
                        $grouped[$item_no]['loan_recipients'][] = $recipient;
                    }
                    if ($asset['loan_date']) {
                        if (!$grouped[$item_no]['loan_date_min'] || strtotime($asset['loan_date']) < strtotime($grouped[$item_no]['loan_date_min'])) {
                            $grouped[$item_no]['loan_date_min'] = $asset['loan_date'];
                        }
                        if (!$grouped[$item_no]['loan_date_max'] || strtotime($asset['loan_date']) > strtotime($grouped[$item_no]['loan_date_max'])) {
                            $grouped[$item_no]['loan_date_max'] = $asset['loan_date'];
                        }
                    }
                    if ($asset['return_date']) {
                        if (!$grouped[$item_no]['return_date_min'] || strtotime($asset['return_date']) < strtotime($grouped[$item_no]['return_date_min'])) {
                            $grouped[$item_no]['return_date_min'] = $asset['return_date'];
                        }
                        if (!$grouped[$item_no]['return_date_max'] || strtotime($asset['return_date']) > strtotime($grouped[$item_no]['return_date_max'])) {
                            $grouped[$item_no]['return_date_max'] = $asset['return_date'];
                        }
                    }
                }

                $sr_no = 1;
                foreach ($grouped as $item_no => $group):
                    $items_count = count($group['items']);
                    $recipients_summary = implode(', ', array_slice($group['loan_recipients'], 0, 2));
                    if (count($group['loan_recipients']) > 2) {
                        $recipients_summary .= ' (+' . (count($group['loan_recipients']) - 2) . ' more)';
                    }
                    $loan_dates = $group['loan_date_min'] ? date('d/m/Y', strtotime($group['loan_date_min'])) : 'N/A';
                    if ($group['loan_date_min'] !== $group['loan_date_max']) {
                        $loan_dates .= ' - ' . date('d/m/Y', strtotime($group['loan_date_max']));
                    }
            ?>
                    <div class="la-list-row" style="display: grid; grid-template-columns: 50px 80px 2fr 1.5fr 2fr 120px; align-items: center; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; background: #ffffff; font-size: 0.82rem;">
                        <div class="la-cell la-sr"><?php echo $sr_no; ?></div>

                        <div class="la-cell">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-700 text-xs font-bold border border-blue-100"><?php echo htmlspecialchars($item_no); ?></span>
                        </div>

                        <div class="la-cell">
                            <div class="la-primary capitalize"><?php echo htmlspecialchars($group['asset_name']); ?></div>
                            <div class="la-secondary font-bold text-blue-600"><?php echo $items_count; ?> unit<?php echo $items_count !== 1 ? 's' : ''; ?> on loan</div>
                        </div>

                        <div class="la-cell la-location font-semibold">
                            <?php echo htmlspecialchars($recipients_summary ?: 'N/A'); ?>
                        </div>

                        <div class="la-cell la-meta text-xs text-slate-500">
                            <div>Loaned: <?php echo $loan_dates; ?></div>
                        </div>

                        <div class="la-cell text-right">
                            <button type="button" id="btn-toggle-loan-<?php echo $item_no; ?>" onclick="toggleLoanDetails('<?php echo $item_no; ?>')" class="la-btn la-btn-secondary text-xs px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 transition" style="padding: 6px 10px; font-weight: 600; color: #475569; background: #ffffff;">
                                🔽 Details
                            </button>
                        </div>
                    </div>

                    <!-- Accordion Sub-rows -->
                    <div id="details-loan-<?php echo $item_no; ?>" class="hidden bg-slate-50 border-l-4 border-blue-500 px-6 py-4 space-y-3 shadow-inner no-print" style="margin-left: 20px; margin-right: 20px; border-radius: 0 0 8px 8px;">
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Loaned Asset Details for Item No: <?php echo htmlspecialchars($item_no); ?></h4>
                        <div class="space-y-2">
                            <?php foreach ($group['items'] as $sub_asset):
                                $sub_loan_date = $sub_asset['loan_date'] ? date('d/m/Y', strtotime($sub_asset['loan_date'])) : 'N/A';
                                $sub_return_date = $sub_asset['return_date'] ? date('d/m/Y', strtotime($sub_asset['return_date'])) : 'N/A';
                                $sub_cost = (float)$sub_asset['cost'];
                            ?>
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between bg-white border border-slate-200 rounded-lg p-3 gap-2">
                                    <div class="flex flex-wrap items-center gap-4 text-xs">
                                        <div>Asset No: <span class="font-mono text-blue-600 font-bold"><?php echo htmlspecialchars($sub_asset['asset_no'] ?: 'N/A'); ?></span></div>
                                        <div>Loaned To: <span class="font-bold text-slate-700"><?php echo htmlspecialchars($sub_asset['loan_to'] ?: 'N/A'); ?></span></div>
                                        <div>Loan Date: <span class="font-bold text-slate-700"><?php echo $sub_loan_date; ?></span></div>
                                        <div>Expected Return: <span class="font-bold text-slate-700"><?php echo $sub_return_date; ?></span></div>
                                        <div>Cost: <span class="font-bold text-slate-700">&#8377;<?php echo number_format($sub_cost, 2); ?></span></div>
                                        <?php if (!empty($sub_asset['remarks'])): ?>
                                            <div class="text-slate-400 italic">Remarks: "<?php echo htmlspecialchars($sub_asset['remarks']); ?>"</div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <form method="POST" action="return-asset.php" class="return-asset-form" data-asset-id="<?php echo (int)$sub_asset['id']; ?>" data-asset-name="<?php echo htmlspecialchars($sub_asset['asset_name']); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int)$sub_asset['id']; ?>">
                                            <button type="submit" class="la-btn la-btn-success text-xs font-semibold px-2.5 py-1.5" style="padding: 6px 12px; border-radius: 6px;">
                                                Return
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
            <?php $sr_no++; endforeach; ?>
            <?php endif; ?>
        </div>

        <script>
            function toggleLoanDetails(itemNo) {
                const el = document.getElementById('details-loan-' + itemNo);
                const btn = document.getElementById('btn-toggle-loan-' + itemNo);
                if (el.classList.contains('hidden')) {
                    el.classList.remove('hidden');
                    btn.innerHTML = '🔼 Hide';
                } else {
                    el.classList.add('hidden');
                    btn.innerHTML = '🔽 Details';
                }
            }
        </script>

    </div>

    </div>

    <div id="borrowed-section" <?php echo $active_section !== 'borrowed' ? 'style="display:none;"' : ''; ?>>
        <div class="mt-8">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between mb-3 no-print">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Borrowed Assets</h1>
                    <p class="text-sm text-slate-500">List of assets currently borrowed from other departments.</p>
                </div>
                <a href="dashboard.php?view=add-borrowed-asset" class="la-btn la-btn-primary no-print">
                    <i data-lucide="plus" style="width:18px;height:18px"></i>
                    Add Borrowed Asset
                </a>
            </div>

            <form method="GET" action="dashboard.php" class="la-filter-bar no-print">
                <input type="hidden" name="view" value="loaned-assets">
                <input type="hidden" name="section" value="borrowed">
                <input type="hidden" name="category" value="<?php echo (int)$selectedCategory; ?>">
                <div class="la-field">
                    <label for="b_name">Asset Name</label>
                    <input type="text" id="b_name" name="b_name" value="<?php echo htmlspecialchars($borrowed_name_filter); ?>" placeholder="Search by asset name">
                </div>
                <div class="la-field">
                    <label for="b_borrowed_from">Borrowed From</label>
                    <select id="b_borrowed_from" name="b_borrowed_from">
                        <option value="">All</option>
                        <?php foreach ($borrowed_from_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo (isset($borrowed_from_filter) && $borrowed_from_filter === $option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="la-field">
                    <label for="b_location">Location</label>
                    <select id="b_location" name="b_location">
                        <option value="">All</option>
                        <?php
                        $borrowed_locations = [];
                        $loc_result = $conn->query("SELECT DISTINCT location FROM borrowed_assets WHERE location IS NOT NULL AND location <> '' ORDER BY location ASC");
                        if ($loc_result) {
                            while ($row = $loc_result->fetch_assoc()) {
                                $borrowed_locations[] = $row['location'];
                            }
                        }
                        foreach ($borrowed_locations as $loc_option): ?>
                            <option value="<?php echo htmlspecialchars($loc_option); ?>" <?php echo (isset($location_filter) && $location_filter === $loc_option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="la-field">
                    <label for="b_assigned_to">Assigned To Faculty</label>
                    <select id="b_assigned_to" name="b_assigned_to">
                        <option value="">All</option>
                        <?php
                        $borrowed_assigned = [];
                        $assigned_result = $conn->query("SELECT DISTINCT assigned_to FROM borrowed_assets WHERE assigned_to IS NOT NULL AND assigned_to <> '' ORDER BY assigned_to ASC");
                        if ($assigned_result) {
                            while ($row = $assigned_result->fetch_assoc()) {
                                $borrowed_assigned[] = $row['assigned_to'];
                            }
                        }
                        foreach ($borrowed_assigned as $assigned_option): ?>
                            <option value="<?php echo htmlspecialchars($assigned_option); ?>" <?php echo (isset($assigned_to_filter) && $assigned_to_filter === $assigned_option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($assigned_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="la-btn la-btn-primary">
                    <i data-lucide="search" style="width:18px;height:18px"></i>
                    Search
                </button>
                <a href="dashboard.php?view=loaned-assets&section=borrowed&category=<?php echo (int)$selectedCategory; ?>" class="la-btn la-btn-secondary">
                    Clear
                </a>
            </form>

        <div class="la-tabs no-print">
            <?php foreach ($category_names as $category_id => $category_name): ?>
                <a href="<?php echo htmlspecialchars(build_borrowed_url($category_id, $borrowed_name_filter, $borrowed_from_filter, $location_filter, $assigned_to_filter, 'borrowed')); ?>"
                   class="<?php echo $selectedCategory === $category_id ? 'active-tab' : ''; ?>">
                    <?php echo htmlspecialchars($category_name); ?>
                    <span class="la-tab-count"><?php echo number_format($borrowed_category_counts[$category_id]); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="la-shell">
            <div class="la-header-area">
                <div class="la-title"><?php echo htmlspecialchars($selected_category_name); ?> Borrowed List</div>
                <div class="la-subtitle">Assets borrowed from other departments.</div>
                <div class="la-page-line">
                    <div>Total Borrowed: <strong><?php echo number_format($total_borrowed); ?></strong></div>
                    <div class="la-sheet-meta">
                        <span class="la-pill">Category: <?php echo htmlspecialchars($selected_category_name); ?></span>
                        <span class="la-pill">Rows: <?php echo number_format(count($selected_borrowed_assets)); ?></span>
                    </div>
                </div>
            </div>
            <div class="la-list-head" style="display: grid; grid-template-columns: 50px 80px 2fr 1.5fr 1.5fr 1.5fr 100px; align-items: center; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 16px; font-size: 0.72rem; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.05em;">
                <div>Sr</div>
                <div>Item No</div>
                <div>Asset Name</div>
                <div>Asset No</div>
                <div>Borrow From</div>
                <div>Borrow / Return Dates</div>
                <div class="text-right">Action</div>
            </div>

            <div>
                <?php if (empty($selected_borrowed_assets)): ?>
                    <div class="la-empty py-10 text-center text-slate-500">No borrowed assets found in this category.</div>
                <?php else: ?>
                    <?php
                    $b_grouped = [];
                    foreach ($selected_borrowed_assets as $asset) {
                        $item_no = $asset['item_no'] ?: 'Uncategorized';
                        if (!isset($b_grouped[$item_no])) {
                            $b_grouped[$item_no] = [
                                'item_no' => $item_no,
                                'asset_name' => $asset['asset_name'],
                                'borrow_from' => $asset['borrowed_from'],
                                'borrow_date_min' => $asset['borrow_date'],
                                'borrow_date_max' => $asset['borrow_date'],
                                'return_date_min' => $asset['return_date'],
                                'return_date_max' => $asset['return_date'],
                                'items' => []
                            ];
                        }
                        $b_grouped[$item_no]['items'][] = $asset;
                        if ($asset['borrow_date']) {
                            if (!$b_grouped[$item_no]['borrow_date_min'] || strtotime($asset['borrow_date']) < strtotime($b_grouped[$item_no]['borrow_date_min'])) {
                                $b_grouped[$item_no]['borrow_date_min'] = $asset['borrow_date'];
                            }
                            if (!$b_grouped[$item_no]['borrow_date_max'] || strtotime($asset['borrow_date']) > strtotime($b_grouped[$item_no]['borrow_date_max'])) {
                                $b_grouped[$item_no]['borrow_date_max'] = $asset['borrow_date'];
                            }
                        }
                        if ($asset['return_date']) {
                            if (!$b_grouped[$item_no]['return_date_min'] || strtotime($asset['return_date']) < strtotime($b_grouped[$item_no]['return_date_min'])) {
                                $b_grouped[$item_no]['return_date_min'] = $asset['return_date'];
                            }
                            if (!$b_grouped[$item_no]['return_date_max'] || strtotime($asset['return_date']) > strtotime($b_grouped[$item_no]['return_date_max'])) {
                                $b_grouped[$item_no]['return_date_max'] = $asset['return_date'];
                            }
                        }
                    }

                    $b_sr_no = 1;
                    foreach ($b_grouped as $item_no => $group):
                        $b_items_count = count($group['items']);
                        $borrow_dates = $group['borrow_date_min'] ? date('d/m/Y', strtotime($group['borrow_date_min'])) : 'N/A';
                        if ($group['borrow_date_min'] !== $group['borrow_date_max']) {
                            $borrow_dates .= ' - ' . date('d/m/Y', strtotime($group['borrow_date_max']));
                        }
                        $return_dates = $group['return_date_min'] ? date('d/m/Y', strtotime($group['return_date_min'])) : 'N/A';
                        if ($group['return_date_min'] !== $group['return_date_max']) {
                            $return_dates .= ' - ' . date('d/m/Y', strtotime($group['return_date_max']));
                        }
                ?>
                        <div class="la-list-row" style="display: grid; grid-template-columns: 50px 80px 2fr 1.5fr 1.5fr 1.5fr 100px; align-items: center; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; background: #ffffff; font-size: 0.82rem;">
                            <div class="la-cell la-sr"><?php echo $b_sr_no; ?></div>

                            <div class="la-cell">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-50 text-amber-700 text-xs font-bold border border-amber-100"><?php echo htmlspecialchars($item_no); ?></span>
                            </div>

                            <div class="la-cell">
                                <div class="la-primary capitalize"><?php echo htmlspecialchars($group['asset_name']); ?></div>
                                <div class="la-secondary font-bold text-amber-600"><?php echo $b_items_count; ?> unit<?php echo $b_items_count !== 1 ? 's' : ''; ?> borrowed</div>
                            </div>

                            <div class="la-cell la-mono text-xs">
                                <?php echo htmlspecialchars($group['items'][0]['asset_no'] ?: 'N/A'); ?>
                            </div>

                            <div class="la-cell la-location font-semibold">
                                <?php echo htmlspecialchars($group['borrow_from'] ?: 'N/A'); ?>
                            </div>

                            <div class="la-cell la-meta text-xs text-slate-500">
                                <div>Borrowed: <?php echo $borrow_dates; ?></div>
                                <div>Return: <?php echo $return_dates; ?></div>
                            </div>

                            <div class="la-cell text-right">
                                <button type="button" id="btn-toggle-borrow-<?php echo $item_no; ?>" onclick="toggleBorrowDetails('<?php echo $item_no; ?>')" class="la-btn la-btn-secondary text-xs px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 transition" style="padding: 6px 10px; font-weight: 600; color: #475569; background: #ffffff;">
                                    Details
                                </button>
                            </div>
                        </div>

                        <div id="details-borrow-<?php echo $item_no; ?>" class="hidden bg-slate-50 border-l-4 border-amber-500 px-6 py-4 space-y-3 shadow-inner no-print" style="margin-left: 20px; margin-right: 20px; border-radius: 0 0 8px 8px;">
                            <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Borrowed Asset Details for Item No: <?php echo htmlspecialchars($item_no); ?></h4>
                            <div class="space-y-2">
                                <?php foreach ($group['items'] as $sub_asset):
                                    $sub_status = htmlspecialchars($sub_asset['status'] ?: 'N/A');
                                ?>
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between bg-white border border-slate-200 rounded-lg p-3 gap-2">
                                        <div class="flex items-center gap-3">
                                            <input type="checkbox" class="borrowed-asset-checkbox h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" value="<?php echo (int)$sub_asset['id']; ?>" onchange="updateBulkActionBar()">
                                            <div class="flex flex-wrap items-center gap-4 text-xs">
                                                <div>Asset No: <span class="font-mono text-amber-600 font-bold"><?php echo htmlspecialchars($sub_asset['asset_no'] ?: 'N/A'); ?></span></div>
                                                <div>Page No: <span class="font-bold text-slate-700"><?php echo htmlspecialchars($sub_asset['page_no'] ?: 'N/A'); ?></span></div>
                                                <div>Location: <span class="font-bold text-slate-700"><?php echo htmlspecialchars($sub_asset['location'] ?: 'N/A'); ?></span></div>
                                                <div>Assign to Faculty: <span class="font-bold text-slate-700"><?php echo htmlspecialchars($sub_asset['assigned_to'] ?: 'N/A'); ?></span></div>
                                                <div>Status: <span class="font-bold text-slate-700"><?php echo $sub_status; ?></span></div>
                                                <?php if (!empty($sub_asset['remarks'])): ?>
                                                    <div class="text-slate-400 italic">Remarks: "<?php echo htmlspecialchars($sub_asset['remarks']); ?>"</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <form method="POST" action="return-borrowed-asset.php" class="return-borrowed-form" data-asset-id="<?php echo (int)$sub_asset['id']; ?>" data-asset-name="<?php echo htmlspecialchars($sub_asset['asset_name']); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$sub_asset['id']; ?>">
                                                <button type="submit" class="la-btn la-btn-success text-xs font-semibold px-2.5 py-1.5" style="padding: 6px 12px; border-radius: 6px;">
                                                    Return
                                                </button>
                                            </form>
                                            <button type="button" onclick="openEditModal(<?php echo (int)$sub_asset['id']; ?>, '<?php echo htmlspecialchars($sub_asset['location'] ?: ''); ?>', '<?php echo htmlspecialchars($sub_asset['assigned_to'] ?: ''); ?>', '<?php echo htmlspecialchars($sub_asset['status'] ?: 'active'); ?>', '<?php echo htmlspecialchars($sub_asset['remarks'] ?: ''); ?>')" class="la-btn la-btn-secondary text-xs font-semibold px-2.5 py-1.5" style="padding: 6px 12px; border-radius: 6px;">
                                                Edit
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                <?php $b_sr_no++; endforeach; ?>
                <?php endif; ?>
            </div>

            <script>
                function toggleBorrowDetails(itemNo) {
                    const el = document.getElementById('details-borrow-' + itemNo);
                    const btn = document.getElementById('btn-toggle-borrow-' + itemNo);
                    if (el.classList.contains('hidden')) {
                        el.classList.remove('hidden');
                        btn.innerHTML = 'Hide';
                    } else {
                        el.classList.add('hidden');
                        btn.innerHTML = 'Details';
                    }
                }
            </script>

        </div>
    </div>

    <div id="bulk-action-bar" class="hidden fixed bottom-6 left-1/2 z-50 -translate-x-1/2">
        <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-3 shadow-xl">
            <span class="text-sm font-semibold text-slate-700">Selected: <span id="selected-count">0</span></span>
            <button type="button" onclick="openBulkEditModal()" class="la-btn la-btn-secondary text-xs font-semibold px-3 py-2" style="border-radius: 8px;">Edit</button>
            <button type="button" onclick="bulkReturn()" class="la-btn la-btn-success text-xs font-semibold px-3 py-2" style="border-radius: 8px;">Return</button>
            <button type="button" onclick="clearSelection()" class="la-btn la-btn-secondary text-xs font-semibold px-3 py-2" style="border-radius: 8px;">Cancel</button>
        </div>
    </div>

    </div>

</div>

<div id="edit-borrowed-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">
        <div class="mb-6">
            <h2 class="text-xl font-bold tracking-tight text-slate-900">Edit Borrowed Asset</h2>
            <p class="mt-1 text-sm text-slate-500">Update location, assigned faculty, status, and remarks.</p>
        </div>
        <form id="editBorrowedForm" class="grid gap-5">
            <input type="hidden" name="id" id="edit-asset-id">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="edit-location" class="mb-2 block text-sm font-semibold text-slate-700">Location</label>
                    <select id="edit-location" name="location" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                        <option value="">Select location</option>
                    </select>
                    <div id="edit-custom_location_wrapper" class="mt-3 hidden">
                        <label for="edit-custom_location" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Add new location</label>
                        <div class="flex gap-2">
                            <input type="text" id="edit-custom_location" placeholder="Enter new lab name" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            <button type="button" id="edit-add_location_btn" class="rounded-lg border border-blue-600 bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700">Add</button>
                        </div>
                    </div>
                </div>
                <div>
                    <label for="edit-assigned_to" class="mb-2 block text-sm font-semibold text-slate-700">Assign to Faculty</label>
                    <select id="edit-assigned_to" name="assigned_to" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                        <option value="">Loading faculty...</option>
                    </select>
                </div>
            </div>
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="edit-status" class="mb-2 block text-sm font-semibold text-slate-700">Status</label>
                    <select id="edit-status" name="status" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                        <option value="active">Active</option>
                        <option value="Not Working">Not Working</option>
                        <option value="Under Maintenance">Under Maintenance</option>
                        <option value="Missing">Missing</option>
                    </select>
                </div>
                <div>
                    <label for="edit-remarks" class="mb-2 block text-sm font-semibold text-slate-700">Remarks</label>
                    <textarea id="edit-remarks" name="remarks" rows="3" placeholder="Enter any specific condition or notes..." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200"></textarea>
                </div>
            </div>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" onclick="closeEditModal()" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.return-asset-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const assetId = this.dataset.assetId;
            const assetName = this.dataset.assetName;
            if (!confirm('Return asset "' + assetName + '"? This will mark it as Active and remove it from the loaned list.')) {
                return;
            }
            const formData = new FormData(this);
            fetch('return-asset.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(data.message || 'Asset returned successfully!');
                    window.location.reload();
                } else {
                    alert('Error returning asset: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An unexpected error occurred. Please check the console for details.');
            });
        });
    });

    document.querySelectorAll('.return-borrowed-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const assetId = this.dataset.assetId;
            const assetName = this.dataset.assetName;
            if (!confirm('Return borrowed asset "' + assetName + '"? This will mark it as Returned and remove it from the borrowed list.')) {
                return;
            }
            const formData = new FormData(this);
            fetch('return-borrowed-asset.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(data.message || 'Borrowed asset returned successfully!');
                    window.location.reload();
                } else {
                    alert('Error returning borrowed asset: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An unexpected error occurred. Please check the console for details.');
            });
        });
    });

    const editModal = document.getElementById('edit-borrowed-modal');
    const editForm = document.getElementById('editBorrowedForm');
    const editLocationSelect = document.getElementById('edit-location');
    const editCustomLocationWrapper = document.getElementById('edit-custom_location_wrapper');
    const editCustomLocationInput = document.getElementById('edit-custom_location');
    const editAddLocationButton = document.getElementById('edit-add_location_btn');
    const editAssignedToSelect = document.getElementById('edit-assigned_to');
    const editStatusSelect = document.getElementById('edit-status');
    const editRemarksInput = document.getElementById('edit-remarks');
    const locationStorageKey = 'kd_polytechnic_saved_locations';

    function getSavedLocations() {
        try {
            return JSON.parse(localStorage.getItem(locationStorageKey)) || [];
        } catch (error) {
            return [];
        }
    }

    function populateEditLocationOptions() {
        const savedLocations = getSavedLocations();
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
        editLocationSelect.innerHTML = optionsHTML;
    }

    function toggleEditCustomLocationInput() {
        const showCustomInput = editLocationSelect.value === '__other__';
        editCustomLocationWrapper.classList.toggle('hidden', !showCustomInput);
        if (!showCustomInput) {
            editCustomLocationInput.value = '';
        }
    }

    function addEditCustomLocation() {
        const newLocation = editCustomLocationInput.value.trim();
        if (!newLocation) {
            alert('Please enter a new location name.');
            return;
        }
        const savedLocations = getSavedLocations();
        if (!savedLocations.includes(newLocation)) {
            savedLocations.push(newLocation);
            localStorage.setItem(locationStorageKey, JSON.stringify(savedLocations));
        }
        populateEditLocationOptions();
        editLocationSelect.value = newLocation;
        editCustomLocationInput.value = '';
        editCustomLocationWrapper.classList.add('hidden');
    }

    function openEditModal(id, location, assignedTo, status, remarks) {
        document.getElementById('edit-asset-id').value = id;
        editRemarksInput.value = remarks;
        editStatusSelect.value = status || 'active';
        populateEditLocationOptions();
        if (location && Array.from(editLocationSelect.options).some(option => option.value === location)) {
            editLocationSelect.value = location;
        } else if (location) {
            editLocationSelect.value = '__other__';
            editCustomLocationInput.value = location;
            editCustomLocationWrapper.classList.remove('hidden');
        } else {
            editLocationSelect.value = '';
            editCustomLocationWrapper.classList.add('hidden');
        }
        editModal.classList.remove('hidden');
        if (!editAssignedToSelect.dataset.loaded) {
            fetch('get-faculty.php')
                .then(response => response.json())
                .then(allNames => {
                    editAssignedToSelect.innerHTML = '<option value="">Select faculty</option>';
                    allNames.forEach(name => {
                        const option = document.createElement('option');
                        option.value = name;
                        option.textContent = name;
                        editAssignedToSelect.appendChild(option);
                    });
                    editAssignedToSelect.dataset.loaded = 'true';
                    if (assignedTo) {
                        editAssignedToSelect.value = assignedTo;
                    }
                })
                .catch(() => {
                    editAssignedToSelect.innerHTML = '<option value="">Could not load faculty list</option>';
                });
        } else {
            if (assignedTo) {
                editAssignedToSelect.value = assignedTo;
            }
        }
    }

    function closeEditModal() {
        editModal.classList.add('hidden');
    }

    editLocationSelect.addEventListener('change', toggleEditCustomLocationInput);
    editAddLocationButton.addEventListener('click', addEditCustomLocation);
    editCustomLocationInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addEditCustomLocation();
        }
    });

    editForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(editForm);
        const selectedLocation = editLocationSelect.value.trim();
        if (selectedLocation === '__other__') {
            alert('Please add a new location name or choose an existing one.');
            return;
        }
        fetch('update-borrowed-asset.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message || 'Borrowed asset updated successfully!');
                closeEditModal();
                window.location.reload();
            } else {
                alert('Error updating borrowed asset: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An unexpected error occurred. Please check the console for details.');
        });
    });

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<div id="bulk-edit-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8">
        <div class="mb-6">
            <h2 class="text-xl font-bold tracking-tight text-slate-900">Bulk Edit Borrowed Assets</h2>
            <p class="mt-1 text-sm text-slate-500">Update selected assets. Leave fields unchanged if not applicable.</p>
        </div>
        <form id="bulkEditForm" class="grid gap-5">
            <input type="hidden" name="ids" id="bulk-edit-ids">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="bulk-edit-location" class="mb-2 block text-sm font-semibold text-slate-700">Location</label>
                    <select id="bulk-edit-location" name="location" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                        <option value="">Keep unchanged</option>
                    </select>
                    <div id="bulk-edit-custom_location_wrapper" class="mt-3 hidden">
                        <label for="bulk-edit-custom_location" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Add new location</label>
                        <div class="flex gap-2">
                            <input type="text" id="bulk-edit-custom_location" placeholder="Enter new lab name" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                            <button type="button" id="bulk-edit-add_location_btn" class="rounded-lg border border-blue-600 bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700">Add</button>
                        </div>
                    </div>
                </div>
                <div>
                    <label for="bulk-edit-assigned_to" class="mb-2 block text-sm font-semibold text-slate-700">Assign to Faculty</label>
                    <select id="bulk-edit-assigned_to" name="assigned_to" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                        <option value="">Keep unchanged</option>
                    </select>
                </div>
            </div>
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="bulk-edit-status" class="mb-2 block text-sm font-semibold text-slate-700">Status</label>
                    <select id="bulk-edit-status" name="status" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                        <option value="">Keep unchanged</option>
                        <option value="active">Active</option>
                        <option value="Not Working">Not Working</option>
                        <option value="Under Maintenance">Under Maintenance</option>
                        <option value="Missing">Missing</option>
                    </select>
                </div>
                <div>
                    <label for="bulk-edit-remarks" class="mb-2 block text-sm font-semibold text-slate-700">Remarks</label>
                    <textarea id="bulk-edit-remarks" name="remarks" rows="3" placeholder="Leave empty to keep unchanged..." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200"></textarea>
                </div>
            </div>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" onclick="closeBulkEditModal()" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function getSelectedBorrowedIds() {
        const checkboxes = document.querySelectorAll('.borrowed-asset-checkbox:checked');
        return Array.from(checkboxes).map(cb => cb.value);
    }

    function updateBulkActionBar() {
        const selectedIds = getSelectedBorrowedIds();
        const bulkActionBar = document.getElementById('bulk-action-bar');
        const selectedCount = document.getElementById('selected-count');
        selectedCount.textContent = selectedIds.length;
        if (selectedIds.length > 0) {
            bulkActionBar.classList.remove('hidden');
        } else {
            bulkActionBar.classList.add('hidden');
        }
    }

    function clearSelection() {
        document.querySelectorAll('.borrowed-asset-checkbox:checked').forEach(cb => cb.checked = false);
        updateBulkActionBar();
    }

    function bulkReturn() {
        const selectedIds = getSelectedBorrowedIds();
        if (selectedIds.length === 0) {
            alert('Please select at least one asset to return.');
            return;
        }
        if (!confirm('Return ' + selectedIds.length + ' borrowed asset(s)? This will mark them as Returned.')) {
            return;
        }
        const formData = new FormData();
        selectedIds.forEach(id => formData.append('ids[]', id));
        fetch('return-multiple-borrowed-assets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message || 'Borrowed assets returned successfully!');
                window.location.reload();
            } else {
                alert('Error returning borrowed assets: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An unexpected error occurred. Please check the console for details.');
        });
    }

    const bulkEditModal = document.getElementById('bulk-edit-modal');
    const bulkEditForm = document.getElementById('bulkEditForm');
    const bulkEditLocationSelect = document.getElementById('bulk-edit-location');
    const bulkEditCustomLocationWrapper = document.getElementById('bulk-edit-custom_location_wrapper');
    const bulkEditCustomLocationInput = document.getElementById('bulk-edit-custom_location');
    const bulkEditAddLocationButton = document.getElementById('bulk-edit-add_location_btn');
    const bulkEditAssignedToSelect = document.getElementById('bulk-edit-assigned_to');
    const bulkEditStatusSelect = document.getElementById('bulk-edit-status');
    const bulkEditRemarksInput = document.getElementById('bulk-edit-remarks');
    const bulkEditIdsInput = document.getElementById('bulk-edit-ids');

    function getSavedLocations() {
        try {
            return JSON.parse(localStorage.getItem('kd_polytechnic_saved_locations')) || [];
        } catch (error) {
            return [];
        }
    }

    function populateBulkEditLocationOptions() {
        const savedLocations = getSavedLocations();
        let optionsHTML = `
            <option value="">Keep unchanged</option>
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
        bulkEditLocationSelect.innerHTML = optionsHTML;
    }

    function toggleBulkEditCustomLocationInput() {
        const showCustomInput = bulkEditLocationSelect.value === '__other__';
        bulkEditCustomLocationWrapper.classList.toggle('hidden', !showCustomInput);
        if (!showCustomInput) {
            bulkEditCustomLocationInput.value = '';
        }
    }

    function addBulkEditCustomLocation() {
        const newLocation = bulkEditCustomLocationInput.value.trim();
        if (!newLocation) {
            alert('Please enter a new location name.');
            return;
        }
        const savedLocations = getSavedLocations();
        if (!savedLocations.includes(newLocation)) {
            savedLocations.push(newLocation);
            localStorage.setItem('kd_polytechnic_saved_locations', JSON.stringify(savedLocations));
        }
        populateBulkEditLocationOptions();
        bulkEditLocationSelect.value = newLocation;
        bulkEditCustomLocationInput.value = '';
        bulkEditCustomLocationWrapper.classList.add('hidden');
    }

    function openBulkEditModal() {
        const selectedIds = getSelectedBorrowedIds();
        if (selectedIds.length === 0) {
            alert('Please select at least one asset to edit.');
            return;
        }
        bulkEditIdsInput.value = selectedIds.join(',');
        bulkEditRemarksInput.value = '';
        bulkEditStatusSelect.value = '';
        populateBulkEditLocationOptions();
        bulkEditLocationSelect.value = '';
        bulkEditCustomLocationWrapper.classList.add('hidden');
        bulkEditModal.classList.remove('hidden');
        if (!bulkEditAssignedToSelect.dataset.loaded) {
            fetch('get-faculty.php')
                .then(response => response.json())
                .then(allNames => {
                    bulkEditAssignedToSelect.innerHTML = '<option value="">Keep unchanged</option>';
                    allNames.forEach(name => {
                        const option = document.createElement('option');
                        option.value = name;
                        option.textContent = name;
                        bulkEditAssignedToSelect.appendChild(option);
                    });
                    bulkEditAssignedToSelect.dataset.loaded = 'true';
                })
                .catch(() => {
                    bulkEditAssignedToSelect.innerHTML = '<option value="">Could not load faculty list</option>';
                });
        }
    }

    function closeBulkEditModal() {
        bulkEditModal.classList.add('hidden');
    }

    bulkEditLocationSelect.addEventListener('change', toggleBulkEditCustomLocationInput);
    bulkEditAddLocationButton.addEventListener('click', addBulkEditCustomLocation);
    bulkEditCustomLocationInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addBulkEditCustomLocation();
        }
    });

    bulkEditForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(bulkEditForm);
        const selectedLocation = bulkEditLocationSelect.value.trim();
        if (selectedLocation === '__other__') {
            alert('Please add a new location name or choose an existing one.');
            return;
        }
        fetch('update-multiple-borrowed-assets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message || 'Borrowed assets updated successfully!');
                closeBulkEditModal();
                clearSelection();
                window.location.reload();
            } else {
                alert('Error updating borrowed assets: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An unexpected error occurred. Please check the console for details.');
        });
    });

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
