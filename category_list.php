<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

$assetName = 'Logitech Wireless Keyboard';
$assetItemNo = 'KDP/COMP/2026/EXP/P-19/-125/10/30';
$assetCategory = 'Expandable';
$assetCategoryType = 'Hardware Asset';
$assetLocation = 'Lab F004';
$assetQuantity = '5 units';
$assetCost = '₹1250.50';
$assetDateIssue = '2026-07-13';
$assetAssignedTo = 'Dr. John Doe';
$assetRemarks = 'Issued to main lab. Excellent condition.';
$assetStatus = 'Active';
$assetStatusTone = 'bg-[#dcfce7] text-[#166534]';
$assetStatusDot = 'bg-[#16a34a]';
$assetLastAudited = 'Today, 09:41 AM';
$assetMaintainedBy = 'CE Dept';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Details - <?php echo htmlspecialchars($assetName); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
          },
        },
      };
    </script>
    <style>
        html, body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover { background: #D1D5DB; }
    </style>
</head>
<body class="h-screen bg-gray-50 text-gray-900 antialiased">

    <div class="h-screen flex overflow-hidden">
        <aside id="sidebar" class="w-64 border-r border-gray-200 bg-white flex flex-col fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 lg:static transition-transform duration-200 ease-out">
            <div class="h-16 flex items-center gap-3 px-4 border-b border-gray-200 shrink-0">
                <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center shrink-0 p-1">
                    <img src="https://scontent.famd8-1.fna.fbcdn.net/v/t39.30808-6/482345949_1144079087415361_6640568596786112832_n.jpg?stp=dst-jpg_tt6&cstp=mx1379x1379&ctp=s1379x1379&_nc_cat=111&ccb=1-7&_nc_sid=6ee11a&_nc_ohc=dqzfFwO_7hEQ7kNvwHlS2Lc&_nc_oc=AdqNTZYLxmQKL2WaL9V7X7C6O9y9HIZNlpZiBqOTr3chZ-WT57nGAbpKFdbH0IayXk4&_nc_zt=23&_nc_ht=scontent.famd8-1.fna&_nc_gid=512jtex-NyXTQ9YEE2yRCg&_nc_ss=7b289&oh=00_AQCFoi8YrRQThI_Qg2e3SPWGXJTNIXX5tSQO7LOxr-Rw5w&oe=6A5E8523"
                         alt="KDP Logo" class="w-full h-full object-contain">
                </div>
                <span class="font-bold text-sm tracking-tight text-gray-900">Smart Asset Manager</span>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                    <i data-lucide="layout-dashboard" style="width:18px;height:18px"></i>
                    Dashboard
                </a>
                <a href="add-asset.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                    <i data-lucide="plus-square" style="width:18px;height:18px"></i>
                    Add Item(s)
                </a>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="manage-users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-gray-900 text-sm font-medium transition-colors">
                    <i data-lucide="users" style="width:18px;height:18px"></i>
                    Manage Users
                </a>
                <?php endif; ?>
            </nav>
        </aside>

        <div id="overlay" class="fixed inset-0 bg-gray-900/30 z-30 hidden"></div>

        <div class="flex-1 flex flex-col min-w-0">
            <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-4 lg:px-6 gap-4 shrink-0">
                <div class="flex items-center gap-2 flex-1 min-w-0">
                    <button id="menuBtn" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-gray-100 text-gray-500 shrink-0">
                        <i data-lucide="menu" style="width:20px;height:20px"></i>
                    </button>
                    <div class="relative w-full max-w-md">
                        <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="width:16px;height:16px"></i>
                        <input type="text" placeholder="Search assets, locations, categories..."
                            class="w-full pl-10 pr-4 py-2.5 rounded-full bg-gray-50 border border-gray-200 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300 transition" />
                    </div>
                </div>

                <div class="flex items-center gap-3 sm:gap-4 shrink-0">
                    <button class="relative p-2 rounded-lg hover:bg-gray-100 text-gray-500 transition-colors">
                        <i data-lucide="bell" style="width:19px;height:19px"></i>
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-rose-500 rounded-full ring-2 ring-white"></span>
                    </button>
                    <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>
                    <div class="relative">
                        <button id="userMenuBtn" class="flex items-center gap-2.5 group">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0"><?php echo getInitials($_SESSION['user_name']); ?></div>
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
                                <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 transition-colors">
                                    <i data-lucide="log-out" style="width:16px;height:16px"></i>
                                    Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6">
                <div class="max-w-5xl mx-auto">
                    <nav class="flex flex-wrap items-center text-sm text-slate-500 mb-6 gap-2">
                        <a href="dashboard.php" class="hover:text-slate-800">Dashboard</a>
                        <span>&gt;</span>
                        <a href="#" class="hover:text-slate-800">Expandable</a>
                        <span>&gt;</span>
                        <a href="#" class="hover:text-slate-800">Keyboards</a>
                        <span>&gt;</span>
                        <span class="text-slate-800 font-medium break-all"><?php echo htmlspecialchars($assetItemNo); ?></span>
                    </nav>

                    <!-- Original Asset Card Structure Unchanged (except max width adapted for Dashboard layout) -->
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm flex flex-col lg:flex-row overflow-hidden">
                        
                        <div class="w-full lg:w-2/3 p-5 sm:p-7 lg:p-8 min-w-0">
                            <div class="mb-6">
                                <div class="flex items-center gap-2 text-[#1e3271] font-medium text-sm mb-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
                                        <rect x="9" y="9" width="6" height="6"></rect>
                                        <line x1="9" y1="1" x2="9" y2="4"></line>
                                        <line x1="15" y1="1" x2="15" y2="4"></line>
                                        <line x1="9" y1="20" x2="9" y2="23"></line>
                                        <line x1="15" y1="20" x2="15" y2="23"></line>
                                        <line x1="20" y1="9" x2="23" y2="9"></line>
                                        <line x1="20" y1="14" x2="23" y2="14"></line>
                                        <line x1="1" y1="9" x2="4" y2="9"></line>
                                        <line x1="1" y1="14" x2="4" y2="14"></line>
                                    </svg>
                                    <?php echo htmlspecialchars($assetCategoryType); ?>
                                </div>
                                <h1 class="text-2xl sm:text-3xl font-bold text-[#0f172a] break-words"><?php echo htmlspecialchars($assetName); ?></h1>
                            </div>

                            <hr class="border-slate-100 mb-8">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-y-8 sm:gap-x-4 mb-10">
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Item No</p>
                                    <p class="font-semibold text-slate-900 break-all"><?php echo htmlspecialchars($assetItemNo); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Category</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($assetCategory); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Location</p>
                                    <p class="font-semibold text-slate-900 flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="text-slate-400 flex-shrink-0">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                        <?php echo htmlspecialchars($assetLocation); ?>
                                    </p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Quantity</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($assetQuantity); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Cost</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($assetCost); ?></p>
                                </div>
                                <div class="break-words">
                                    <p class="text-sm text-slate-500 mb-1">Date of Issue</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($assetDateIssue); ?></p>
                                </div>
                                <div class="break-words sm:col-span-2 lg:col-span-1">
                                    <p class="text-sm text-slate-500 mb-1">Assigned to Faculty</p>
                                    <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($assetAssignedTo); ?></p>
                                </div>
                            </div>

                            <div>
                                <p class="text-sm text-slate-500 mb-2">Remarks</p>
                                <div class="bg-slate-50 rounded-lg p-4 text-slate-700 text-sm border border-slate-100 break-words">
                                    <?php echo htmlspecialchars($assetRemarks); ?>
                                </div>
                            </div>
                        </div>

                        <div class="w-full lg:w-1/3 bg-[#fcfdfd] p-5 sm:p-7 lg:p-8 border-t lg:border-t-0 lg:border-l border-slate-100 flex flex-col min-w-0">

                            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Asset Status</h3>

                            <div class="mb-8">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-semibold <?php echo htmlspecialchars($assetStatusTone); ?>">
                                    <span class="w-2 h-2 rounded-full <?php echo htmlspecialchars($assetStatusDot); ?>"></span>
                                    Status: <?php echo htmlspecialchars($assetStatus); ?>
                                </span>
                            </div>

                            <div class="space-y-4 mb-auto">
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 text-sm">
                                    <span class="text-slate-500">Last audited:</span>
                                    <span class="text-slate-800 font-medium"><?php echo htmlspecialchars($assetLastAudited); ?></span>
                                </div>
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 text-sm">
                                    <span class="text-slate-500">Maintained by:</span>
                                    <span class="text-slate-800 font-medium"><?php echo htmlspecialchars($assetMaintainedBy); ?></span>
                                </div>
                            </div>

                            <div class="mt-8"></div>

                            <div class="space-y-3">
                                <!-- Changed from redirect to trigger openEditModal() -->
                                <button onclick="openEditModal()"
                                    class="w-full bg-[#20347a] hover:bg-[#18275c] text-white font-medium py-2.5 px-4 rounded-lg flex items-center justify-center gap-2 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                    Edit Asset
                                </button>

                                <button
                                    class="w-full bg-white border border-[#fecaca] text-[#dc2626] hover:bg-red-50 font-medium py-2.5 px-4 rounded-lg flex items-center justify-center gap-2 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                        </path>
                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                    </svg>
                                    Retire Item
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- Edit Asset Modal Container -->
    <div id="editModal" class="fixed inset-0 z-[100] hidden flex-col items-center justify-center p-4 sm:p-6">
        <!-- Dark Background Overlay -->
        <div id="editModalOverlay" class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity duration-300 opacity-0"></div>
        
        <!-- Modal Content Container -->
        <div id="editModalContent" class="relative w-full max-w-5xl h-full max-h-[90vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden transform scale-95 opacity-0 transition-all duration-300 z-10">
            
            <!-- Dynamic Content Body -->
            <div id="editModalBody" class="flex-1 flex flex-col overflow-hidden w-full h-full"></div>
            
            <!-- Loading State -->
            <div id="editModalLoader" class="absolute inset-0 bg-white flex flex-col items-center justify-center z-20">
                <svg class="animate-spin h-10 w-10 text-blue-600 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-slate-500 font-medium">Loading editor...</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        const menuBtn = document.getElementById('menuBtn');
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenuDropdown = document.getElementById('userMenuDropdown');

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        }

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        }

        menuBtn.addEventListener('click', openSidebar);
        overlay.addEventListener('click', closeSidebar);

        userMenuBtn.addEventListener('click', () => {
            userMenuDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', (event) => {
            if (!userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                userMenuDropdown.classList.add('hidden');
            }
        });

        const modal = document.getElementById('editModal');
        const editModalOverlay = document.getElementById('editModalOverlay');
        const modalContent = document.getElementById('editModalContent');
        const modalBody = document.getElementById('editModalBody');
        const loader = document.getElementById('editModalLoader');

        function openEditModal() {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
            document.documentElement.classList.add('overflow-hidden');
            
            // Trigger open animation
            setTimeout(() => {
                editModalOverlay.classList.remove('opacity-0');
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);

            loader.classList.remove('hidden');
            loader.classList.add('flex');
            modalBody.innerHTML = '';

            // Fetch form in embed mode
            fetch('edit_asset.php?embed=1')
                .then(res => {
                    if (!res.ok) throw new Error('Failed to load asset details');
                    return res.text();
                })
                .then(html => {
                    modalBody.innerHTML = html;
                    
                    // Re-execute any scripts injected via innerHTML
                    Array.from(modalBody.querySelectorAll("script")).forEach(oldScript => {
                        const newScript = document.createElement("script");
                        Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                        newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });
                    
                    loader.classList.add('hidden');
                    loader.classList.remove('flex');
                })
                .catch(err => {
                    modalBody.innerHTML = `<div class="p-10 flex flex-col items-center justify-center text-center h-full">
                        <div class="text-red-500 mb-3"><svg class="w-12 h-12 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                        <h3 class="text-lg font-semibold text-slate-800">Error Loading Editor</h3>
                        <p class="text-slate-500 mt-1">Unable to load the asset form. Please try again.</p>
                        <button onclick="closeEditModal()" class="mt-4 px-4 py-2 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700 transition">Close</button>
                    </div>`;
                    loader.classList.add('hidden');
                    loader.classList.remove('flex');
                });
        }

        function closeEditModal() {
            // Trigger close animation
            editModalOverlay.classList.add('opacity-0');
            modalContent.classList.remove('scale-100', 'opacity-100');
            modalContent.classList.add('scale-95', 'opacity-0');
            document.body.classList.remove('overflow-hidden');
            document.documentElement.classList.remove('overflow-hidden');

            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modalBody.innerHTML = '';
            }, 300); // Matches transition duration
        }

        window.closeEditModal = closeEditModal;

        // Close on overlay click
        editModalOverlay.addEventListener('click', closeEditModal);
        
        // Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeEditModal();
            }
        });
    </script>
</body>
</html>