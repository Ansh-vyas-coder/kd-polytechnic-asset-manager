<?php
// This sidebar expects a $current_page variable to be set before inclusion.
// It helps to highlight the active navigation link.
$current_page = $current_page ?? '';

// Also expects session_start() to have been called.
?>
<aside id="sidebar" class="w-64 flex flex-col fixed inset-y-0 left-0 z-40 transition-transform duration-300 ease-in-out border-r border-slate-700 rounded-tr-3xl rounded-br-3xl" style="background-color: #1e293b;">

    <!-- Branding -->
    <a href="dashboard.php" class="h-20 flex items-center gap-3 px-5 shrink-0">
        <img src="kdp_logo.jpeg" alt="College Logo" class="h-10 w-10 rounded-full object-cover ring-1 ring-white/75">
        <span class="font-bold text-lg tracking-tight text-white">KDP Asset Manager</span>
    </a>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-4 py-2 space-y-1.5">
        <a href="dashboard.php?view=dashboard" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'dashboard') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
            <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i>
            Dashboard
        </a>
        
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="dashboard.php?view=add-asset" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'add-asset') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
                <i data-lucide="plus-square" style="width:18px;height:18px"></i>
                Add Item(s)
            </a>
            <a href="dashboard.php?view=register" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'register') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
                <i data-lucide="book-open" style="width:18px;height:18px"></i>
                Virtual Register
            </a>
            <a href="dashboard.php?view=generate-report" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'generate-report') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
                <i data-lucide="file-spreadsheet" style="width:18px;height:18px"></i>
                Generate Report
            </a>
            <a href="manage-users.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'manage-users') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
                <i data-lucide="users" style="width:18px;height:18px"></i>
                Manage Users
            </a>
            <a href="assigned-assets.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'assigned-assets') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
                <i data-lucide="user-check" style="width:18px;height:18px"></i>
                Assigned Assets
            </a>
        <?php endif; ?>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff'): ?>
            <a href="dashboard.php?view=my-assets" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-medium transition-colors <?php echo ($current_page === 'my-assets') ? 'bg-slate-700 text-white font-semibold' : 'text-slate-300 hover:bg-slate-700 hover:text-white'; ?>">
                <i data-lucide="file-spreadsheet" style="width:18px;height:18px"></i>
                My Assigned Assets
            </a>
        <?php endif; ?>
    </nav>

</aside>