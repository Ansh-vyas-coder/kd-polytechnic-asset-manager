<?php
session_start();
require 'db.php';

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
    1 => 'Departmental Stores Expendable Register',
    2 => 'Departmental Stores Consumables Register',
    3 => 'Departmental Stores Deadstock Register',
    4 => 'Departmental Stores Furniture Register',
];

$selectedCategory = isset($_GET['category']) && isset($category_names[(int)$_GET['category']]) ? (int)$_GET['category'] : 1;
$pageNo = isset($_GET['page_no']) ? trim($_GET['page_no']) : 'all';

$groups = [];

// Determine which pages to fetch
if ($pageNo !== 'all') {
    $pages_sql = "SELECT DISTINCT page_no AS group_value FROM assets WHERE category_id = " . (int)$selectedCategory . " AND page_no = '" . $conn->real_escape_string($pageNo) . "'";
} else {
    $pages_sql = "SELECT DISTINCT page_no AS group_value FROM assets WHERE category_id = " . (int)$selectedCategory . " AND TRIM(COALESCE(page_no, '')) <> '' ORDER BY CAST(page_no AS UNSIGNED) ASC, page_no ASC";
}

$pages_result = $conn->query($pages_sql);

if ($pages_result) {
    while ($row = $pages_result->fetch_assoc()) {
        $groupValue = $row['group_value'];
        
        $recordsSql = "
            SELECT
                MIN(id) as id,
                asset_name,
                category_id,
                SUM(quantity) as quantity,
                MIN(item_no) as item_no,
                GROUP_CONCAT(asset_no ORDER BY item_no ASC SEPARATOR '; ') as asset_no,
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
            WHERE category_id = " . (int)$selectedCategory . " AND page_no = '" . $conn->real_escape_string($groupValue) . "'
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

$category_label = str_replace(' ', '_', $category_names[$selectedCategory]);
$filename = "Register_" . $category_label . "_Page_" . $pageNo . "_" . date('Y-m-d') . ".xls";

// Set Headers to force Excel Download
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Output XML/HTML format that Microsoft Excel opens styled perfectly
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-type" content="text/html;charset=utf-8" />
<style>
    body {
        font-family: 'Times New Roman', Times, serif;
    }
    table {
        border-collapse: collapse;
    }
    th {
        border: 1px solid #000000;
        background-color: #f7f5e8;
        font-weight: bold;
        font-size: 10pt;
        text-align: center;
        vertical-align: middle;
    }
    td {
        border: 1px solid #000000;
        padding: 5px;
        font-size: 10pt;
        vertical-align: top;
    }
    .header-title {
        font-size: 16pt;
        font-weight: bold;
        text-align: center;
        text-decoration: underline;
        text-transform: uppercase;
    }
    .header-subtitle {
        font-size: 12pt;
        font-weight: bold;
        text-align: center;
    }
    .header-page-no {
        font-size: 11pt;
        font-weight: bold;
        text-align: right;
    }
    .col-num {
        font-weight: bold;
        text-align: center;
        background-color: #e8e8e8;
    }
    .item-no-cell {
        text-align: center;
        font-weight: bold;
        vertical-align: middle;
    }
    .qty-cell {
        text-align: center;
        font-weight: bold;
        vertical-align: middle;
    }
    .cost-cell {
        text-align: right;
        vertical-align: middle;
    }
    .asset-no-cell {
        font-family: 'Courier New', monospace;
        font-size: 9pt;
        white-space: pre-wrap;
    }
    .asset-name {
        font-weight: bold;
    }
    .sig-cell {
        background-color: #fafaf5;
    }
    .page-break {
        page-break-after: always;
    }
</style>
</head>
<body>

<?php 
$total_groups = count($groups);
foreach ($groups as $g_idx => $group): 
    $register_title = $category_register_titles[$selectedCategory];
?>
<table style="width: 100%;">
    <!-- Top Titles matching the physical page -->
    <tr>
        <td colspan="15" class="header-title" style="border: none;"><?php echo htmlspecialchars($register_title); ?></td>
    </tr>
    <tr>
        <td colspan="15" class="header-subtitle" style="border: none;">Name of the Institute &nbsp; K.D. Polytechnic, Patan</td>
    </tr>
    <tr>
        <td colspan="15" class="header-page-no" style="border: none;">Date: <?php echo date('d/m/Y'); ?> &nbsp;&nbsp;&nbsp; Page No: <b><?php echo htmlspecialchars($group['label']); ?></b></td>
    </tr>
    <tr>
        <td colspan="15" style="border: none; height: 10px;"></td>
    </tr>

    <!-- Main Table Headers -->
    <thead>
        <tr>
            <th style="width: 50px;">Sr<br>No</th>
            <th style="width: 120px;">Page No and<br>Date of G.P.R<br>entry</th>
            <th style="width: 120px;">GEM Ord &amp;<br>Invoice No</th>
            <th style="width: 60px;">Quantity<br>Received</th>
            <th style="width: 80px;">Cost</th>
            <th style="width: 80px;">Initial of Head<br>of<br>Dept/Office</th>
            <th style="width: 80px;">Signature of<br>Receiver</th>
            <th style="width: 250px;">Asset No</th>
            <th style="width: 250px;">Name of<br>Section / Asset Details</th>
            <th style="width: 60px;">Quantity<br>Issued</th>
            <th style="width: 120px;">Date of<br>Issue</th>
            <th style="width: 80px;">Signature of<br>Receiver</th>
            <th style="width: 80px;">Initial of<br>Store or Clerk</th>
            <th style="width: 80px;">Initial Head of<br>Department /<br>Office</th>
            <th style="width: 200px;">Remarks</th>
        </tr>
        <tr>
            <?php for ($i = 1; $i <= 15; $i++): ?>
                <th class="col-num"><?php echo $i; ?></th>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
        <?php
        foreach ($group['records'] as $asset):
            $gpr_ref = '';
            if (!empty($asset['pr_page_no']))  $gpr_ref .= 'Pg: ' . $asset['pr_page_no'];
            if (!empty($asset['gpr_item_no'])) $gpr_ref .= ($gpr_ref ? ' / ' : '') . 'Item: ' . $asset['gpr_item_no'];
            if (!empty($asset['gpr_no']))       $gpr_ref .= ($gpr_ref ? "\n" : '') . $asset['gpr_no'];

            $gem_ref = '';
            if (!empty($asset['gem_order_no']))   $gem_ref .= $asset['gem_order_no'];
            if (!empty($asset['gem_invoice_no'])) $gem_ref .= ($gem_ref ? "\n" : '') . 'Inv: ' . $asset['gem_invoice_no'];

            $section = $asset['location'] ?: ($asset['assigned_to'] ?: '');
            $total_items = (int)($asset['total_items'] ?? 1);
            $formatted_asset_nos = $asset['asset_no'];
        ?>
        <tr>
            <td class="item-no-cell">Item No:<br><?php echo (int)$asset['item_no']; ?></td>
            <td style="white-space: pre-wrap;"><?php echo htmlspecialchars($gpr_ref); ?></td>
            <td style="white-space: pre-wrap;"><?php echo htmlspecialchars($gem_ref); ?></td>
            <td class="qty-cell"><?php echo $total_items; ?></td>
            <td class="cost-cell">₹<?php echo number_format((float)$asset['cost'], 2); ?></td>
            <td class="sig-cell"></td>
            <td class="sig-cell"></td>
            <td class="asset-no-cell" style="vnd.ms-excel.numberformat:@;"><?php echo htmlspecialchars($formatted_asset_nos); ?></td>
            <td>
                <span class="asset-name"><?php echo htmlspecialchars($asset['asset_name']); ?></span>
                <?php if ($total_items > 1): ?>
                    <div style="font-size: 8.5pt; color: #1d4ed8; font-weight: bold;">(<?php echo $total_items; ?> Nos.)</div>
                <?php endif; ?>
                <?php if ($section): ?>
                    <div style="font-size: 8.5pt; color: #555;"><?php echo htmlspecialchars($section); ?></div>
                <?php endif; ?>
            </td>
            <td class="qty-cell"><?php echo $total_items; ?></td>
            <td style="vnd.ms-excel.numberformat:@; text-align: center; vertical-align: middle;"><?php echo htmlspecialchars($asset['date_of_issue']); ?></td>
            <td class="sig-cell"></td>
            <td class="sig-cell"></td>
            <td class="sig-cell"></td>
            <td style="font-size: 9pt; white-space: pre-wrap;"><?php echo htmlspecialchars($asset['remarks'] ?? ''); ?></td>
        </tr>
        <?php
        endforeach;

        // Visual filler rows (at least 15 rows total per register page)
        $filled = count($group['records']);
        $min_rows = 15;
        for ($i = $filled; $i < $min_rows; $i++):
        ?>
        <tr>
            <td>&nbsp;</td><td></td><td></td><td></td><td></td>
            <td class="sig-cell"></td><td class="sig-cell"></td>
            <td></td><td></td><td></td><td></td>
            <td class="sig-cell"></td><td class="sig-cell"></td><td class="sig-cell"></td>
            <td></td>
        </tr>
        <?php endfor; ?>
    </tbody>
</table>

<?php if ($g_idx < $total_groups - 1): ?>
    <!-- Excel Page Break indicator -->
    <br style="page-break-before: always;" />
    <div style="height: 40px;"></div>
<?php endif; ?>

<?php endforeach; ?>

</body>
</html>
<?php exit(); ?>
