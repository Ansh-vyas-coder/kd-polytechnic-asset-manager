<?php
session_start();
require 'db.php';
require 'vendor/autoload.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("HTTP/1.1 403 Forbidden"); // User not logged in or not a valid role
    exit("Access denied.");
}

// Get and validate the audit ID from the URL
$audit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($audit_id <= 0) {
    exit("Invalid audit ID.");
}

// --- Fetch main audit details ---
$audit_stmt = $conn->prepare(
    "SELECT a.id, a.location_id, a.audit_date, a.status, a.audited_by_user_id, u.full_name as audited_by
     FROM audits a
     JOIN users u ON a.audited_by_user_id = u.id
     WHERE a.id = ?"
);
if (!$audit_stmt) {
    die("Database error preparing to fetch audit details.");
}
$audit_stmt->bind_param("i", $audit_id);
$audit_stmt->execute();
$audit_result = $audit_stmt->get_result();
$audit_details = $audit_result->fetch_assoc();
$audit_stmt->close();

if (!$audit_details) {
    exit("Audit report not found.");
}

// Security check: Staff can only export their own audit results
if ($_SESSION['role'] === 'staff' && $audit_details['audited_by_user_id'] != $_SESSION['user_id']) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied. You can only export your own audit reports.");
}

// --- Fetch all audit items ---
$items_stmt = $conn->prepare(
    "SELECT
        ai.verification_status,
        ai.condition,
        ai.note,
        ai.expected_location_id,
        ai.scanned_location_id,
        COALESCE(a.asset_name, b.asset_name) AS asset_name,
        COALESCE(a.category_id, b.category_id) AS category_id,
        COALESCE(a.asset_no, b.asset_no) AS asset_no
    FROM audit_items ai
    LEFT JOIN assets a ON ai.asset_id = a.id AND ai.source = 'assets'
    LEFT JOIN borrowed_assets b ON ai.asset_id = b.id AND ai.source = 'borrowed'
    WHERE ai.audit_id = ?
    ORDER BY ai.verification_status, asset_name"
);
if (!$items_stmt) {
    die("Database error preparing to fetch audit items.");
}
$items_stmt->bind_param("i", $audit_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$all_items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

// --- Process items into categories ---
$present_items_by_category_and_name = [];
$missing_items_by_category_and_name = [];
$misplaced_items_by_category_and_name = [];

// Define categories for display
$categories_map = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];

foreach ($all_items as $item) {
    $category_id = $item['category_id'];
    $asset_name = $item['asset_name'];
    switch ($item['verification_status']) {
        case 'Present':
            if (!isset($present_items_by_category_and_name[$category_id])) $present_items_by_category_and_name[$category_id] = [];
            if (!isset($present_items_by_category_and_name[$category_id][$asset_name])) $present_items_by_category_and_name[$category_id][$asset_name] = [];
            $present_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
        case 'Missing':
            if (!isset($missing_items_by_category_and_name[$category_id])) $missing_items_by_category_and_name[$category_id] = [];
            if (!isset($missing_items_by_category_and_name[$category_id][$asset_name])) $missing_items_by_category_and_name[$category_id][$asset_name] = [];
            $missing_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
        case 'Misplaced':
            if (!isset($misplaced_items_by_category_and_name[$category_id])) $misplaced_items_by_category_and_name[$category_id] = [];
            if (!isset($misplaced_items_by_category_and_name[$category_id][$asset_name])) $misplaced_items_by_category_and_name[$category_id][$asset_name] = [];
            $misplaced_items_by_category_and_name[$category_id][$asset_name][] = $item;
            break;
    }
}
$present_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $present_items_by_category_and_name));
$missing_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $missing_items_by_category_and_name));
$misplaced_count = array_sum(array_map(fn($group) => array_sum(array_map('count', $group)), $misplaced_items_by_category_and_name));

// --- Start Generating HTML for PDF ---
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Audit Report #<?php echo $audit_id; ?></title>
    <style>
        body { font-family: 'dejavusans', sans-serif; font-size: 10pt; color: #333; }
        h1 { font-size: 20pt; text-align: center; color: #1E3A5F; margin-bottom: 20px; text-decoration: underline; }
        h2 { font-size: 14pt; color: #1E3A5F; border-bottom: 2px solid #E2E8F0; padding-bottom: 5px; margin-top: 25px; margin-bottom: 10px; }
        h3 { font-size: 12pt; margin-top: 15px; margin-bottom: 5px; border-left: 3px solid #ccc; padding-left: 8px; font-weight: bold; } /* Category heading */
        h4 { font-size: 11pt; margin-top: 10px; margin-bottom: 5px; border-left: 2px solid #eee; padding-left: 6px; font-weight: bold; } /* Asset Name heading */
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #F1F5F9; padding: 8px; text-align: left; font-weight: bold; border: 1px solid #E2E8F0; }
        td { border: 1px solid #E2E8F0; padding: 8px; vertical-align: top; }
        .header-table { margin-bottom: 20px; }
        .header-table td { border: none; padding: 3px 0; }
        .header-label { font-weight: bold; color: #475569; }
        .summary-box { text-align: center; padding: 15px; border-radius: 8px; }
        .summary-present { background-color: #F0FFF4; border: 1px solid #C6F6D5; }
        .summary-missing { background-color: #FFF5F5; border: 1px solid #FED7D7; }
        .summary-misplaced { background-color: #FFFBEB; border: 1px solid #FEEBC8; }
        .summary-title { font-size: 10pt; color: #4A5568; }
        .summary-count { font-size: 22pt; font-weight: bold; margin-top: 5px; }
        .present-count { color: #2F855A; }
        .missing-count { color: #C53030; }
        .misplaced-count { color: #B7791F; }
        .condition-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 8pt; font-weight: bold; }
        .condition-good { background-color: #dcfce7; color: #166534; }
        .condition-repair { background-color: #fef9c3; color: #a16207; }
        .condition-broken { background-color: #fee2e2; color: #b91c1c; }
        .condition-scrap { background-color: #e5e7eb; color: #4b5563; }
    </style>
</head>
<body>
    <h1>Audit Report #<?php echo htmlspecialchars($audit_details['id']); ?></h1>

    <table class="header-table">
        <tr>
            <td class="header-label">Location:</td>
            <td><?php echo htmlspecialchars($audit_details['location_id']); ?></td>
            <td class="header-label">Audited By:</td>
            <td><?php echo htmlspecialchars($audit_details['audited_by']); ?></td>
        </tr>
        <tr>
            <td class="header-label">Date:</td>
            <td><?php echo date('M d, Y h:i A', strtotime($audit_details['audit_date'])); ?></td>
            <td class="header-label">Status:</td>
            <td><?php echo htmlspecialchars($audit_details['status']); ?></td>
        </tr>
    </table>

    <table style="margin-bottom: 20px;">
        <tr>
            <td class="summary-box summary-present">
                <div class="summary-title">Present</div>
                <div class="summary-count present-count"><?php echo $present_count; ?></div>
            </td>
            <td class="summary-box summary-missing">
                <div class="summary-title">Missing</div>
                <div class="summary-count missing-count"><?php echo $missing_count; ?></div>
            </td>
            <td class="summary-box summary-misplaced">
                <div class="summary-title">Misplaced</div>
                <div class="summary-count misplaced-count"><?php echo $misplaced_count; ?></div>
            </td>
        </tr>
    </table>

    <?php if (!empty($present_items_by_category_and_name)): ?>
        <h2>Present Items</h2>
        <?php foreach ($present_items_by_category_and_name as $category_id => $asset_names_in_category): ?>
            <h3 style="border-left-color: #2F855A;"><?php echo htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'); ?></h3>
            <?php foreach ($asset_names_in_category as $asset_name => $items): ?>
                <h4 style="border-left-color: #81C784;"><?php echo htmlspecialchars($asset_name); ?> (<?php echo count($items); ?>)</h4>
                <table>
                    <thead><tr><th>Asset No.</th><th>Condition</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $condition_class = 'condition-good';
                            if ($item['condition'] === 'Needs Repair') $condition_class = 'condition-repair';
                            if ($item['condition'] === 'Broken') $condition_class = 'condition-broken';
                            if ($item['condition'] === 'Scrap') $condition_class = 'condition-scrap';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['asset_no']); ?></td>
                            <td><span class="condition-badge <?php echo $condition_class; ?>"><?php echo htmlspecialchars($item['condition'] ?: 'N/A'); ?></span></td>
                            <td><?php echo htmlspecialchars($item['note'] ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($missing_items_by_category_and_name)): ?>
        <h2>Missing Items</h2>
        <?php foreach ($missing_items_by_category_and_name as $category_id => $asset_names_in_category): ?>
            <h3 style="border-left-color: #C53030;"><?php echo htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'); ?></h3>
            <?php foreach ($asset_names_in_category as $asset_name => $items): ?>
                <h4 style="border-left-color: #EF9A9A;"><?php echo htmlspecialchars($asset_name); ?> (<?php echo count($items); ?>)</h4>
                <table>
                    <thead><tr><th>Asset No.</th><th>Expected At</th><th>Found At</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['asset_no']); ?></td>
                            <td><?php echo htmlspecialchars($item['expected_location_id']); ?></td>
                            <td><?php echo htmlspecialchars($item['scanned_location_id'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($item['note'] ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($misplaced_items_by_category_and_name)): ?>
        <h2>Misplaced Items</h2>
        <?php foreach ($misplaced_items_by_category_and_name as $category_id => $asset_names_in_category): ?>
            <h3 style="border-left-color: #B7791F;"><?php echo htmlspecialchars($categories_map[$category_id] ?? 'Unknown Category'); ?></h3>
            <?php foreach ($asset_names_in_category as $asset_name => $items): ?>
                <h4 style="border-left-color: #FFD54F;"><?php echo htmlspecialchars($asset_name); ?> (<?php echo count($items); ?>)</h4>
                <table>
                    <thead><tr><th>Asset No.</th><th>Expected Location</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['asset_no']); ?></td>
                            <td><?php echo htmlspecialchars($item['expected_location_id']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

// --- Instantiate and generate PDF ---
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 10,
    'margin_right' => 10,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'default_font' => 'dejavusans'
]);

$mpdf->SetHeader('K.D. Polytechnic Audit Report| |Page {PAGENO}');
$mpdf->SetFooter('Generated on: ' . date('d/m/Y h:i A'));

$mpdf->WriteHTML($html);

$filename = "Audit_Report_" . $audit_id . "_" . date('Y-m-d') . ".pdf";
$mpdf->Output($filename, 'I'); // 'I' for inline display, 'D' for download

exit();
?>