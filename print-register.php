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
    1 => 'Departmental Stores Expandable Register',
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

$register_title = $category_register_titles[$selectedCategory];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Register - <?php echo htmlspecialchars($category_names[$selectedCategory]); ?></title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #fff;
            color: #000;
            margin: 0;
            padding: 0;
        }
        
        .print-btn-container {
            padding: 15px;
            background: #f3f4f6;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }
        .print-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
        }
        .print-btn:hover {
            background: #1d4ed8;
        }

        .reg-sheet {
            background: #fff;
            padding: 20px;
            page-break-after: always;
        }
        
        .reg-sheet:last-child {
            page-break-after: avoid;
        }

        .reg-header-area {
            text-align: center;
            margin-bottom: 10px;
        }
        .reg-header-area .reg-big-title {
            font-size: 20px;
            font-weight: 700;
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .reg-header-area .reg-institute-name {
            font-size: 14px;
            font-weight: 600;
            margin-top: 2px;
        }
        .reg-header-area .reg-page-line {
            display: flex;
            justify-content: flex-end;
            font-size: 13px;
            font-style: italic;
            margin-top: 4px;
        }

        .reg-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .reg-table th, .reg-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: top;
            line-height: 1.3;
        }
        .reg-table thead tr th {
            font-weight: 700;
            text-align: center;
            vertical-align: middle;
        }
        .reg-table tbody tr {
            height: 30px;
        }
        
        .reg-asset-name {
            font-weight: 700;
            font-size: 11px;
        }
        .reg-asset-no {
            font-size: 10px;
            font-family: monospace;
            white-space: pre-line;
        }
        .reg-item-label {
            font-weight: 700;
        }

        @media print {
            .print-btn-container {
                display: none !important;
            }
            body {
                background: white;
            }
            .reg-sheet {
                padding: 0;
            }
        }
    </style>
</head>
<body>

    <div class="print-btn-container">
        <button onclick="window.print()" class="print-btn">Print / Save as PDF</button>
        <span style="margin-left: 15px; font-size: 14px; color: #555;">Choose <strong>"Save as PDF"</strong> in the destination to download.</span>
    </div>

    <?php foreach ($groups as $group): ?>
    <div class="reg-sheet">
        <div class="reg-header-area">
            <div class="reg-big-title"><?php echo htmlspecialchars($register_title); ?></div>
            <div class="reg-institute-name">Name of the Institute &nbsp; K.D. Polytechnic, Patan</div>
            <div class="reg-page-line">
                Date: ____/____/_______ &nbsp;&nbsp;&nbsp; Page No.: <strong><?php echo htmlspecialchars($group['label']); ?></strong>
            </div>
        </div>

        <table class="reg-table">
            <thead>
                <tr>
                    <th style="width:3%">Sr<br>No</th>
                    <th style="width:8%">Page No and<br>Date of G.P.R<br>entry</th>
                    <th style="width:8%">GEM Ord &amp;<br>Invoice No</th>
                    <th style="width:4%">Quantity<br>Received</th>
                    <th style="width:5%">Cost</th>
                    <th style="width:3%">Initial of Head<br>of<br>Dept/Office</th>
                    <th style="width:3%">Signature of<br>Receiver</th>
                    <th style="width:8%">Asset No</th>
                    <th style="width:15%">Name of<br>Section / Asset Details</th>
                    <th style="width:4%">Quantity<br>Issued</th>
                    <th style="width:6%">Date of<br>Issue</th>
                    <th style="width:3%">Signature of<br>Receiver</th>
                    <th style="width:5%">Initial of<br>Store or Clerk</th>
                    <th style="width:3%">Initial Head of<br>Department /<br>Office</th>
                    <th style="width:23%">Remarks</th>
                </tr>
                <tr>
                    <?php for ($i = 1; $i <= 15; $i++): ?>
                        <th><?php echo $i; ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $sr_no = 1;
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
                    $all_asset_nos = array_filter(array_map('trim', explode("\n", $asset['asset_no'] ?? '')));
                ?>
                <tr>
                    <td style="text-align:center; font-weight:700;">
                        <div class="reg-item-label"><?php echo (int)$asset['item_no']; ?></div>
                    </td>
                    <td style="white-space:pre-line;"><?php echo htmlspecialchars($gpr_ref); ?></td>
                    <td style="white-space:pre-line;"><?php echo htmlspecialchars($gem_ref); ?></td>
                    <td style="text-align:center; font-weight:700;"><?php echo $total_items; ?></td>
                    <td style="text-align:right;">₹<?php echo number_format((float)$asset['cost'], 2); ?></td>
                    <td></td>
                    <td></td>
                    <td class="reg-asset-no"><?php echo htmlspecialchars(implode("\n", $all_asset_nos)); ?></td>
                    <td>
                        <div class="reg-asset-name"><?php echo htmlspecialchars($asset['asset_name']); ?></div>
                        <?php if ($total_items > 1): ?>
                            <div style="font-size:9px; color:#333; font-weight:bold;">(<?php echo $total_items; ?> Nos.)</div>
                        <?php endif; ?>
                        <?php if ($section): ?>
                            <div style="font-size:9px; color:#555; margin-top:1px;"><?php echo htmlspecialchars($section); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center; font-weight:700;"><?php echo $total_items; ?></td>
                    <td style="text-align:center;"><?php echo htmlspecialchars($asset['date_of_issue']); ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="white-space:pre-wrap; word-break:break-word; vertical-align:top;"><?php echo htmlspecialchars($asset['remarks'] ?? ''); ?></td>
                </tr>
                <?php
                    $sr_no++;
                endforeach;

                $filled = count($group['records']);
                $min_rows = 15;
                for ($i = $filled; $i < $min_rows; $i++):
                ?>
                <tr>
                    <td></td><td></td><td></td><td></td><td></td>
                    <td></td><td></td><td></td><td></td><td></td>
                    <td></td><td></td><td></td><td></td><td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
