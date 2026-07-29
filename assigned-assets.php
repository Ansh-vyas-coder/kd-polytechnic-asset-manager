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
$sql_where_clauses = ["assigned_to IS NOT NULL", "assigned_to != ''", "retire_at IS NULL"];
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
$sql = "SELECT id, asset_no, asset_name, category_id, location, assigned_to, status, status_marked_by, status_marked_role, status_marked_at, updated_at FROM assets WHERE " . implode(" AND ", $sql_where_clauses) . " ORDER BY assigned_to ASC, updated_at DESC";

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

// Faculty summary for admin filters
$faculty_summary = [];
$summary_sql = "
    SELECT assigned_to, COUNT(*) AS total_assets
    FROM assets
    WHERE assigned_to IS NOT NULL
      AND assigned_to != ''
      AND retire_at IS NULL
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
    <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                <i data-lucide="menu" style="width:20px;height:20px"></i>
            </button>
        </div>
        <div class="flex items-center gap-3 sm:gap-4 shrink-0">
            <div class="relative">
                <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
                    <div class="hidden sm:block text-left leading-tight">
                        <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-xs text-gray-400"><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?> - Computer Dept.</p>
                    </div>
                    <i data-lucide="chevron-down" class="hidden sm:block text-gray-400 group-hover:text-gray-600 transition-colors" style="width:16px;height:16px"></i>
                </button>
                <div id="userMenuDropdown" class="absolute top-full right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 hidden z-10">
                    <div class="p-3 border-b border-gray-100">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                        <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($_SESSION['user_email']); ?></p>
                    </div>
                    <div class="p-1.5">
                        <a href="#" id="changePasswordBtn" class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                            <i data-lucide="key" style="width:16px;height:16px"></i> Change Password
                        </a>
                        <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors mt-1">
                            <i data-lucide="log-out" style="width:16px;height:16px"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

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
                    <th class="px-6 py-3 font-medium text-right">Last Updated</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php if (empty($items)): ?>
                      <tr>
                          <td colspan="6" class="text-center py-16 text-gray-500">
                              <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                  <i data-lucide="user-check" class="w-7 h-7 text-gray-400"></i>
                              </div>
                              <h3 class="font-semibold text-gray-800">No Assets Assigned</h3>
                              <p class="text-sm mt-1">There are no assets currently checked out.</p>
                          </td>
                      </tr>
                  <?php else: ?>
                  <?php foreach ($items as $item): ?>
                      <tr class="clickable-row" data-href="view-asset-details.php?category_id=<?php echo $item['category_id']; ?>&asset_name=<?php echo urlencode($item['asset_name']); ?>">
                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900"><?php echo htmlspecialchars($item['asset_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['asset_no'] ?: 'N/A'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($categories[$item['category_id']] ?? 'Unknown'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-800 font-medium"><?php echo htmlspecialchars($item['assigned_to']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500 text-right">
                          <?php echo date('M d, Y', strtotime($item['updated_at'])); ?>
                          <?php if (($item['status_marked_role'] ?? '') === 'staff' && in_array($item['status'], ['Not Working', 'Missing'], true)): ?>
                            <div class="mt-1">
                              <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 border border-amber-100">
                                Staff reported
                              </span>
                              <?php if (!empty($item['status_marked_by'])): ?>
                                <div class="mt-1 text-[11px] text-gray-400 text-right">
                                  by <?php echo htmlspecialchars($item['status_marked_by']); ?>
                                </div>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
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

<!-- Change Password Modal -->
<div id="passwordModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all" id="passwordModalContent">
    <form id="changePasswordForm" method="POST" action="change-password.php">
      <div class="p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-bold text-gray-900">Change Your Password</h3>
          <button type="button" id="closeModalBtn" class="p-1 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <div id="modal-notification" class="hidden text-sm mb-4"></div>

        <div class="space-y-4">
          <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
            <input type="password" name="current_password" id="current_password" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>

          <div>
            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
            <input type="password" name="new_password" id="new_password" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-gray-500 mt-1">Must be at least 8 characters long.</p>
          </div>

          <div>
            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirm_password" required class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
          </div>
        </div>
      </div>
      <div class="bg-gray-50 px-6 py-4 rounded-b-xl flex items-center justify-end gap-3">
        <button type="button" id="cancelModalBtn" class="inline-flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" id="submitPasswordBtn" class="inline-flex justify-center items-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
          <span id="submitBtnText">Update Password</span>
          <div id="submitSpinner" class="spinner hidden" style="border-top-color: #fff; border-right-color: transparent; width: 16px; height: 16px; margin-left: 8px;"></div>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  lucide.createIcons();

  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenuDropdown = document.getElementById('userMenuDropdown');
  const sidebar = document.getElementById('sidebar');
  const mainContent = document.getElementById('mainContent');
  const menuBtn = document.getElementById('menuBtn');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

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

  userMenuBtn.addEventListener('click', () => userMenuDropdown.classList.toggle('hidden'));
  document.addEventListener('click', (event) => {
    if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
      userMenuDropdown.classList.add('hidden');
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
      const rows = document.querySelectorAll('.clickable-row');
      rows.forEach(row => {
          row.addEventListener('click', () => {
              window.location.href = row.dataset.href;
          });
      });
  });

  // --- Change Password Modal Logic ---
  const changePasswordBtn = document.getElementById('changePasswordBtn');
  const passwordModal = document.getElementById('passwordModal');
  const closeModalBtn = document.getElementById('closeModalBtn');
  const cancelModalBtn = document.getElementById('cancelModalBtn');
  const changePasswordForm = document.getElementById('changePasswordForm');

  function showPasswordModal() {
    passwordModal.classList.remove('hidden');
    changePasswordForm.reset();
    document.getElementById('modal-notification').classList.add('hidden');
  }

  function hidePasswordModal() {
    passwordModal.classList.add('hidden');
  }

  if (changePasswordBtn) {
      changePasswordBtn.addEventListener('click', (e) => {
        e.preventDefault();
        userMenuDropdown.classList.add('hidden'); // Close dropdown first
        showPasswordModal();
      });
  }

  if (closeModalBtn) closeModalBtn.addEventListener('click', hidePasswordModal);
  if (cancelModalBtn) cancelModalBtn.addEventListener('click', hidePasswordModal);
  if (passwordModal) {
      passwordModal.addEventListener('click', (e) => {
        if (e.target === passwordModal) {
          hidePasswordModal();
        }
      });
  }

  if (changePasswordForm) {
      changePasswordForm.addEventListener('submit', function(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('submitPasswordBtn');
        const btnText = document.getElementById('submitBtnText');
        const spinner = document.getElementById('submitSpinner');
        const modalNotification = document.getElementById('modal-notification');

        submitBtn.disabled = true;
        btnText.textContent = 'Updating...';
        spinner.classList.remove('hidden');
        modalNotification.classList.add('hidden');

        const formData = new FormData(changePasswordForm);

        fetch('change-password.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              modalNotification.className = 'bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm';
              modalNotification.textContent = data.message;
              modalNotification.classList.remove('hidden');
              setTimeout(hidePasswordModal, 2000);
            } else {
              modalNotification.className = 'bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm';
              modalNotification.textContent = data.message || 'An error occurred.';
              modalNotification.classList.remove('hidden');
            }
          })
          .finally(() => {
            submitBtn.disabled = false;
            btnText.textContent = 'Update Password';
            spinner.classList.add('hidden');
          });
      });
  }
</script>
<script src="loader/loader.js"></script>
</body>
</html>
