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

function buildCategoryPageGroups($conn, $categoryId) {
    $groups = [];
    $sql = "SELECT DISTINCT page_no AS group_value FROM assets WHERE category_id = " . (int)$categoryId . " AND TRIM(COALESCE(page_no, '')) <> '' ORDER BY page_no ASC";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $groupValue = $row['group_value'];
            $recordsSql = "SELECT * FROM assets WHERE category_id = " . (int)$categoryId . " AND page_no = '" . $conn->real_escape_string($groupValue) . "' ORDER BY id ASC";
            $recordsResult = $conn->query($recordsSql);
            $records = [];
            if ($recordsResult) {
                while ($asset = $recordsResult->fetch_assoc()) {
                    $records[] = $asset;
                }
            }
            $groups[] = [
                'label' => $groupValue !== null && $groupValue !== '' ? $groupValue : 'No Page No',
                'records' => $records
            ];
        }
    }

    if (empty($groups)) {
        $groups[] = [
            'label' => 'No Page No',
            'records' => []
        ];
    }

    return $groups;
}

$registerCategories = [
    1 => buildCategoryPageGroups($conn, 1),
    2 => buildCategoryPageGroups($conn, 2),
    3 => buildCategoryPageGroups($conn, 3),
    4 => buildCategoryPageGroups($conn, 4)
];

$selectedCategory = isset($_GET['category']) && isset($category_names[(int)$_GET['category']]) ? (int)$_GET['category'] : 1;
?>

<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Virtual Register</h1>
            <p class="mt-1 text-sm text-gray-500">Choose a category and browse its numbered notebook pages.</p>
        </div>
        <div class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
            <i data-lucide="book-open" class="mr-2" style="width:16px;height:16px"></i>
            Category notebook view
        </div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap gap-2">
            <?php foreach ($category_names as $categoryId => $categoryName): ?>
                <a href="dashboard.php?view=register&category=<?php echo $categoryId; ?>"
                   class="rounded-full border px-4 py-2 text-sm font-semibold transition <?php echo $selectedCategory === $categoryId ? 'border-blue-600 bg-blue-600 text-white shadow-sm' : 'border-gray-200 bg-white text-gray-700 hover:border-blue-200 hover:bg-blue-50'; ?>">
                    <span class="block text-[10px] uppercase tracking-[0.25em] opacity-80">Notebook</span>
                    <span><?php echo htmlspecialchars($categoryName); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 rounded-2xl border border-gray-200 bg-slate-50 p-4">
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($category_names[$selectedCategory]); ?> Register</h2>
                    <p class="text-sm text-gray-500">This notebook page contains the page-wise entries for <?php echo strtolower($category_names[$selectedCategory]); ?>.</p>
                </div>
                <div class="rounded-full border border-blue-100 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-wide text-blue-700 shadow-sm">
                    Selected Category <?php echo $selectedCategory; ?>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <?php foreach ($registerCategories[$selectedCategory] as $index => $group): ?>
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">Notebook Page <?php echo $index + 1; ?></h3>
                                <p class="text-xs text-gray-500">Page No: <?php echo htmlspecialchars($group['label']); ?></p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                <?php echo count($group['records']); ?> item(s)
                            </span>
                        </div>
                        <div class="overflow-hidden rounded-xl border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-white text-left text-xs uppercase tracking-wide text-gray-400">
                                    <tr>
                                        <th class="px-4 py-3">Asset Name</th>
                                        <th class="px-4 py-3">Item No</th>
                                        <th class="px-4 py-3">Asset No</th>
                                        <th class="px-4 py-3">Location</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($group['records'] as $asset): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($asset['item_no']); ?></td>
                                            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($asset['asset_no']); ?></td>
                                            <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($asset['location']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($group['records'])): ?>
                                        <tr>
                                            <td colspan="4" class="px-4 py-4 text-center text-sm text-gray-500">No assets in this page group.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
