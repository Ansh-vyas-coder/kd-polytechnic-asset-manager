<?php
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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --primary-dark: #0f172a;
            --primary-blue: #1e3271;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            overflow-x: hidden;
        }

        img,
        svg {
            max-width: 100%;
            height: auto;
            display: block;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }
    </style>
</head>

<body class="min-h-screen text-slate-800 p-4 sm:p-6 lg:p-8">

    <div class="max-w-6xl mx-auto">

        <nav class="flex flex-wrap items-center text-sm text-slate-500 mb-6 gap-2">
            <a href="dashboard.php" class="hover:text-slate-800">Dashboard</a>
            <span>&gt;</span>
            <a href="#" class="hover:text-slate-800">Expandable</a>
            <span>&gt;</span>
            <a href="#" class="hover:text-slate-800">Keyboards</a>
            <span>&gt;</span>
            <span class="text-slate-800 font-medium break-all"><?php echo htmlspecialchars($assetItemNo); ?></span>
        </nav>

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
                    <button onclick="window.location.href='edit-asset.php'"
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

</body>

</html>