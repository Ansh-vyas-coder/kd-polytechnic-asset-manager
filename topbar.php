<?php
/**
 * topbar.php - Shared top navigation bar
 * Include after sidebar.php inside the mainContent wrapper.
 * Requires: session_start(), $conn (db), $_SESSION user_name/role
 * Optional vars: $topbar_back_url, $topbar_back_label
 */
if (!function_exists('getInitials')) {
    function getInitials($name) {
        $words = explode(' ', trim($name));
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[count($words) - 1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }
}
?>
<header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-3 shrink-0 sticky top-0 z-20">

    <!-- Left: Hamburger + Search -->
    <div class="flex items-center gap-2 flex-1 min-w-0">
        <button id="menuBtn" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0" aria-label="Toggle sidebar">
            <i data-lucide="menu" style="width:20px;height:20px"></i>
        </button>

        <?php if (!empty($topbar_back_url)): ?>
        <a href="<?php echo htmlspecialchars($topbar_back_url); ?>"
           class="hidden sm:flex items-center gap-1.5 text-sm text-gray-500 hover:text-blue-600 transition-colors shrink-0">
            <i data-lucide="arrow-left" style="width:15px;height:15px"></i>
            <span><?php echo htmlspecialchars($topbar_back_label ?? 'Back'); ?></span>
        </a>
        <div class="hidden sm:block w-px h-5 bg-gray-200 mx-1 shrink-0"></div>
        <?php endif; ?>

        <!-- Global Search Bar -->
        <div id="search-container" class="relative w-full flex items-center">
            <div class="absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none">
                <i id="search-icon" data-lucide="search" class="text-gray-400" style="width:16px;height:16px"></i>
                <div id="search-spinner" class="hidden animate-spin rounded-full h-4 w-4 border-b-2 border-gray-900"></div>
            </div>
            <input type="text" id="searchInput"
                   placeholder="Search assets, locations, categories..."
                   class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-r-0 border-gray-200 rounded-l-full text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300 transition"
                   autocomplete="off" />
            <button id="qrScanBtn" title="Scan QR Code"
                    class="px-3 py-2.5 bg-gray-50 border border-x-0 border-gray-200 text-gray-500 hover:bg-blue-50 hover:text-blue-600 transition-colors"
                    onclick="openQrScanner()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            </button>
            <button id="searchButton" class="px-4 py-2.5 bg-blue-600 text-white rounded-r-full hover:bg-blue-700 transition-colors text-sm font-semibold shrink-0">
                Search
            </button>
            <div id="searchResults" class="absolute top-full mt-2 w-full bg-white rounded-lg shadow-xl border border-gray-100 hidden z-20 overflow-hidden"></div>
        </div>
    </div>

    <!-- Right: Notification + User Menu -->
    <div class="flex items-center gap-3 sm:gap-4 shrink-0">

        <!-- Notification Bell -->
        <div id="notification-wrapper" class="notification-wrapper">
            <button type="button" class="notification-bell" aria-label="Notifications">
                <i data-lucide="bell" style="width:19px;height:19px"></i>
                <span class="notification-badge"></span>
            </button>
            <div class="notification-panel">
                <div class="notification-panel-header">
                    <h4>Notifications</h4>
                    <div class="notification-panel-actions">
                        <button type="button" id="mark-all-read">Mark all as read</button>
                        <button type="button" id="clear-all" title="Clear notifications">
                            <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                        </button>
                    </div>
                </div>
                <ul class="notification-list">
                    <li class="notification-item empty">Loading...</li>
                </ul>
            </div>
        </div>

        <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>

        <!-- User Menu -->
        <div class="relative">
            <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0">
                    <?php echo getInitials($_SESSION['user_name']); ?>
                </div>
                <div class="hidden sm:block text-left leading-tight">
                    <p class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <p class="text-xs text-gray-400"><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?></p>
                </div>
                <i data-lucide="chevron-down" class="hidden sm:block text-gray-400 group-hover:text-gray-600 transition-colors" style="width:16px;height:16px"></i>
            </button>
            <div id="userMenuDropdown" class="absolute top-full right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-100 hidden z-50">
                <div class="p-3 border-b border-gray-100">
                    <p class="text-sm font-semibold text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <p class="text-xs text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?></p>
                </div>
                <div class="p-1.5">
                    <button id="changePasswordBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                        <i data-lucide="key-round" style="width:16px;height:16px"></i>
                        Change Password
                    </button>
                    <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                        <i data-lucide="log-out" style="width:16px;height:16px"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>
