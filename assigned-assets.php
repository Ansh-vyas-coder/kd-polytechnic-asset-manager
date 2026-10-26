<?php
session_start();
require 'db.php';

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Security check: ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if ($_SESSION['role'] === 'staff') {
    header("Location: dashboard.php?view=my-assets");
    exit();
}

$current_page = 'assigned-assets';

$selected_faculty = isset($_GET['faculty']) ? trim($_GET['faculty']) : '';
$mark_seen = isset($_GET['mark_seen']) && $_GET['mark_seen'] === '1';

if ($_SESSION['role'] === 'admin' && $selected_faculty !== '' && $mark_seen) {
    $review_stmt = $conn->prepare("
        UPDATE asset_status_reports
        SET review_status = 'reviewed',
            reviewed_by_user_id = ?,
            reviewed_at = CURRENT_TIMESTAMP
        WHERE review_status = 'pending'
          AND reported_by_role = 'staff'
          AND reported_assigned_to = ?
    ");
    if ($review_stmt) {
        $reviewer_id = (int)$_SESSION['user_id'];
        $review_stmt->bind_param("is", $reviewer_id, $selected_faculty);
        $review_stmt->execute();
        $review_stmt->close();
    }

    $redirect_params = ['faculty' => $selected_faculty];
    header("Location: assigned-assets.php?" . http_build_query($redirect_params));
    exit();
}

// Fetch assigned assets
$sql_where_clauses = ["assigned_to IS NOT NULL", "assigned_to != ''", "retire_at IS NULL", "(transferred = 0 OR transferred IS NULL)"];
$sql_params = [];
$sql_types = "";

if ($_SESSION['role'] === 'staff') {
    $sql_where_clauses[] = "assigned_to = ?";
    $sql_types .= "s";
    $sql_params[] = $_SESSION['user_name'];
    $selected_faculty = $_SESSION['user_name'];
} elseif ($selected_faculty !== '') {
    $sql_where_clauses[] = "assigned_to = ?";
    $sql_types .= "s";
    $sql_params[] = $selected_faculty;
}

$items = [];
$sql = "SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, status_marked_by, status_marked_role, status_marked_at, updated_at, 'dept' AS source FROM assets WHERE " . implode(" AND ", $sql_where_clauses) . " ORDER BY assigned_to ASC, updated_at DESC";

if (!empty($sql_params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($sql_types, ...$sql_params);
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $items = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// Fetch borrowed assets that are assigned to someone and not yet returned
$borrowed_where = ["assigned_to IS NOT NULL", "assigned_to != ''", "(status IS NULL OR status <> 'Returned')"];
$borrowed_params = [];
$borrowed_types = "";

if ($_SESSION['role'] === 'staff') {
    $borrowed_where[] = "assigned_to = ?";
    $borrowed_types .= "s";
    $borrowed_params[] = $_SESSION['user_name'];
} elseif ($selected_faculty !== '') {
    $borrowed_where[] = "assigned_to = ?";
    $borrowed_types .= "s";
    $borrowed_params[] = $selected_faculty;
}

$borrowed_sql = "SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, NULL AS status_marked_by, NULL AS status_marked_role, NULL AS status_marked_at, updated_at, 'borrowed' AS source FROM borrowed_assets WHERE " . implode(" AND ", $borrowed_where) . " ORDER BY assigned_to ASC, updated_at DESC";

if (!empty($borrowed_params)) {
    $bstmt = $conn->prepare($borrowed_sql);
    $bstmt->bind_param($borrowed_types, ...$borrowed_params);
} else {
    $bstmt = $conn->prepare($borrowed_sql);
}
$bstmt->execute();
$bresult = $bstmt->get_result();

$borrowed_items = [];
if ($bresult) {
    $borrowed_items = $bresult->fetch_all(MYSQLI_ASSOC);
}
$bstmt->close();

$items = array_merge($items, $borrowed_items);

// Sort merged items by assigned_to (asc) then last updated (desc)
usort($items, function ($a, $b) {
    $cmp = strcmp($a['assigned_to'] ?? '', $b['assigned_to'] ?? '');
    if ($cmp !== 0) {
        return $cmp;
    }
    return strtotime($b['updated_at'] ?? '0') <=> strtotime($a['updated_at'] ?? '0');
});

// Faculty summary for admin filters
$faculty_summary = [];
$summary_sql = "
    SELECT assigned_to, SUM(total_assets) AS total_assets
    FROM (
        SELECT assigned_to, COUNT(*) AS total_assets
        FROM assets
        WHERE assigned_to IS NOT NULL
          AND assigned_to != ''
          AND retire_at IS NULL
          AND (transferred = 0 OR transferred IS NULL)
        GROUP BY assigned_to
        UNION ALL
        SELECT assigned_to, COUNT(*) AS total_assets
        FROM borrowed_assets
        WHERE assigned_to IS NOT NULL
          AND assigned_to != ''
          AND (status IS NULL OR status <> 'Returned')
        GROUP BY assigned_to
    ) AS combined
    GROUP BY assigned_to
    ORDER BY assigned_to ASC
";
$summary_result = $conn->query($summary_sql);
if ($summary_result) {
    while ($row = $summary_result->fetch_assoc()) {
        $faculty_summary[] = $row;
    }
}

// Pending staff status reports by faculty snapshot
$pending_reports_by_faculty = [];
$pending_reports_total = 0;

$pending_sql = "
    SELECT reported_assigned_to, COUNT(*) AS total_pending
    FROM asset_status_reports
    WHERE review_status = 'pending'
      AND reported_by_role = 'staff'
      AND reported_assigned_to IS NOT NULL
      AND reported_assigned_to != ''
    GROUP BY reported_assigned_to
";
$pending_result = $conn->query($pending_sql);
if ($pending_result) {
    while ($row = $pending_result->fetch_assoc()) {
        $pending_reports_by_faculty[$row['reported_assigned_to']] = (int)$row['total_pending'];
        $pending_reports_total += (int)$row['total_pending'];
    }
}

// Helper function to generate initials
if (!function_exists('getInitials')) {
    function getInitials($name)
    {
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        return substr($initials, 0, 2);
    }
}

// Categories for display
$categories = [
    1 => 'Expandable',
    2 => 'Consumables',
    3 => 'Deadstock',
    4 => 'Furniture'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Assigned Assets - KDP Asset Manager</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
  tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
</script>
<style>
  html, body { font-family: 'Inter', sans-serif; }
  .clickable-row:hover { background-color: #f9fafb; cursor: pointer; }
  .faculty-card {
    transition: transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease, background-color 150ms ease;
  }
  .faculty-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
  }
  .faculty-card-active {
    position: relative;
  }
  .faculty-dot {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: #ef4444;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.16);
  }
  .status-dot {
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: #ef4444;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.16);
  }
</style>
<link rel="stylesheet" href="loader/loader.css" />
  <link rel="stylesheet" href="notifications.css" />
</head>

<body class="h-screen bg-slate-50 text-slate-900 antialiased">
  <?php include 'loader/loader.html'; ?>

<div class="h-screen flex overflow-hidden">

  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>

  <!-- Overlay for mobile sidebar -->
  <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

  <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
    <!-- Header -->
        <?php include 'topbar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto flex flex-col">
      <main class="flex-1 bg-slate-50 p-4 lg:p-6">
        <div class="max-w-7xl mx-auto">
          <div class="flex items-center justify-between mb-6">
              <div>
                  <h1 class="text-2xl font-bold text-gray-900 tracking-tight">
                      <?php echo $_SESSION['role'] === 'staff' ? 'My Assigned Assets' : 'All Assigned Assets'; ?>
                  </h1>
                  <p class="text-sm text-gray-500 mt-1">Choose a faculty box to view the assets assigned to that person.</p>
              </div>
          </div>

          <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="mb-6">
              <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500">Faculty Boxes</h2>
                <?php if (!empty($selected_faculty)): ?>
                  <a href="assigned-assets.php" class="px-3 py-2 text-sm font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition">Clear Filter</a>
                <?php endif; ?>
              </div>
              <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <a href="assigned-assets.php"
                   class="faculty-card faculty-card-active flex items-center justify-between gap-4 rounded-xl border px-4 py-4 text-left <?php echo $selected_faculty === '' ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-100' : 'border-gray-200 bg-white hover:border-blue-200'; ?>">
                  <div>
                    <p class="text-sm font-semibold text-gray-900">All Faculties</p>
                    <p class="mt-1 text-xs text-gray-500">Show every assigned asset</p>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center rounded-full bg-white px-3 py-1 text-sm font-bold text-gray-700 border border-gray-200"><?php echo number_format(count($items)); ?></span>
                  </div>
                </a>
                <?php foreach ($faculty_summary as $faculty): ?>
                  <?php $is_active = $selected_faculty !== '' && strcasecmp($selected_faculty, $faculty['assigned_to']) === 0; ?>
                  <a href="assigned-assets.php?faculty=<?php echo urlencode($faculty['assigned_to']); ?>&mark_seen=1"
                     class="faculty-card faculty-card-active flex items-center justify-between gap-4 rounded-xl border px-4 py-4 text-left <?php echo $is_active ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-100' : 'border-gray-200 bg-white hover:border-blue-200'; ?>">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($faculty['assigned_to']); ?></p>
                      <p class="mt-1 text-xs text-gray-500">Click to open assets</p>
                    </div>
                    <?php if (!empty($pending_reports_by_faculty[$faculty['assigned_to']])): ?>
                      <span class="faculty-dot" aria-hidden="true"></span>
                    <?php endif; ?>
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center justify-center rounded-full bg-white px-3 py-1 text-sm font-bold text-blue-700 border border-blue-100"><?php echo number_format((int)$faculty['total_assets']); ?></span>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="mb-4 flex items-center justify-between flex-wrap gap-3">
              <div>
                  <p class="text-sm text-gray-500">
                    <?php if (!empty($selected_faculty)): ?>
                      Showing assets assigned to <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($selected_faculty); ?></span>
                    <?php else: ?>
                      Showing all assigned assets
                    <?php endif; ?>
                  </p>
              </div>
              <div class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">
                <?php echo number_format(count($items)); ?> records
              </div>
          </div>

          <!-- Assigned Assets Table -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="overflow-x-auto">
              <table class="w-full text-sm min-w-[720px]">
                <thead class="bg-gray-50">
                  <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3 font-medium">Asset Name</th>
                    <th class="px-6 py-3 font-medium">Asset ID</th>
                    <th class="px-6 py-3 font-medium">Category</th>
                    <th class="px-6 py-3 font-medium">Assigned To</th>
                    <th class="px-6 py-3 font-medium">Location</th>
                    <th class="px-6 py-3 font-medium">Last Updated</th>
                    <th class="px-6 py-3 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($items)): ?>
                      <tr>
                          <td colspan="7" class="text-center py-16 text-gray-500">
                              <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                  <i data-lucide="user-check" class="w-7 h-7 text-gray-400"></i>
                              </div>
                              <h3 class="font-semibold text-gray-800">No Assets Assigned</h3>
                              <p class="text-sm mt-1">There are no assets currently checked out.</p>
                          </td>
                      </tr>
                  <?php else: ?>
                  <?php foreach ($items as $item): ?>
                       <?php
                        $row_href = !empty($item['source']) && $item['source'] === 'borrowed'
                            ? 'dashboard.php?view=loaned-assets&section=borrowed'
                            : 'view-asset-details.php?category_id=' . $item['category_id'] . '&asset_name=' . urlencode($item['asset_name']);
                       ?>
                       <tr class="clickable-row" data-href="<?php echo htmlspecialchars($row_href); ?>">
                         <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                         <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></td>
                         <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                           <?php echo htmlspecialchars($categories[$item['category_id']] ?? 'Unknown'); ?>
                           <?php if (!empty($item['source']) && $item['source'] === 'borrowed'): ?>
                             <span class="ml-1 inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-[10px] font-semibold text-purple-700 border border-purple-100">Borrowed</span>
                           <?php endif; ?>
                         </td>
                         <td class="px-6 py-4 whitespace-nowrap text-gray-800 font-medium"><?php echo htmlspecialchars($item['assigned_to']); ?></td>
                         <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-gray-500">
                            <?php echo date('M d, Y', strtotime($item['updated_at'])); ?>
                            <?php if (($item['status_marked_role'] ?? '') === 'staff' && in_array($item['status'], ['Not Working', 'Missing'], true)): ?>
                             <div class="mt-1">
                               <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 border border-amber-100">
                                 Staff reported
                               </span>
                               <?php if (!empty($item['status_marked_by'])): ?>
                                 <div class="mt-1 text-[11px] text-gray-400">
                                   by <?php echo htmlspecialchars($item['status_marked_by']); ?>
                                 </div>
                               <?php endif; ?>
                             </div>
                           <?php endif; ?>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex items-center justify-end gap-3" onclick="event.stopPropagation()">
                              <?php if (!empty($item['asset_no'])): ?>
                              <a href="download-qr.php?asset_no=<?php echo urlencode($item['asset_no']); ?>"
                                 title="Download QR Label"
                                 class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700 transition">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                                QR
                              </a>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </main>
      <?php include 'footer.php'; ?>
    </div>
  </div>
</div>
<script>
  lucide.createIcons();

  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  const mainContent = document.getElementById('mainContent');
  const menuBtn = document.getElementById('menuBtn');
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenuDropdown = document.getElementById('userMenuDropdown');

  function toggleSidebar() {
      if (!sidebar) return;

      if (window.innerWidth < 1024) {
          // Mobile: Toggle the class that hides the sidebar and show/hide the overlay.
          sidebar.classList.toggle('-translate-x-full');
          if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
      } else {
          // Desktop: Toggle the responsive class that shows the sidebar and adjust main content margin.
          sidebar.classList.toggle('lg:translate-x-0');
          if (mainContent) mainContent.classList.toggle('lg:ml-64');
      }
  }

  if (menuBtn) menuBtn.addEventListener('click', toggleSidebar);
  if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);

  if (userMenuBtn && userMenuDropdown) {
      userMenuBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          userMenuDropdown.classList.toggle('hidden');
      });
      document.addEventListener('click', () => {
          userMenuDropdown.classList.add('hidden');
      });
  }

  document.addEventListener('DOMContentLoaded', function() {
      const rows = document.querySelectorAll('.clickable-row');
      rows.forEach(row => {
          row.addEventListener('click', () => {
              window.location.href = row.dataset.href;
          });
      });
  });
</script>
<script src="loader/loader.js"></script>
  <?php include 'page_scripts.php'; ?>
</body>
</html>


