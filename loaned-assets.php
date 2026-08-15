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

function build_loaned_url(int $category_id, string $name_filter, string $loan_to_filter, string $loan_from, string $loan_to): string
{
    $query = [
        'view' => 'loaned-assets',
        'category' => $category_id,
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
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Loaned Assets</h1>
            <p class="text-sm text-slate-500">List of assets currently loaned out.</p>
        </div>
    </div>

    <form method="GET" action="dashboard.php" class="la-filter-bar no-print">
        <input type="hidden" name="view" value="loaned-assets">
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
        <a href="dashboard.php?view=loaned-assets&category=<?php echo (int)$selectedCategory; ?>" class="la-btn la-btn-secondary">
            Clear
        </a>
    </form>

    <div class="la-tabs no-print">
        <?php foreach ($category_names as $category_id => $category_name): ?>
            <a href="<?php echo htmlspecialchars(build_loaned_url($category_id, $name_filter, $loan_to_filter, $loan_from, $loan_to)); ?>"
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

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
