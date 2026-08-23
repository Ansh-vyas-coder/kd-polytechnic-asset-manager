<?php /* page_scripts.php - Common scripts included at bottom of every standalone page */ ?>

<!-- Change Password Modal (shared) -->
<div id="passwordModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-gray-900">Change Password</h3>
            <button id="closeModalBtn" class="p-1.5 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600">
                <i data-lucide="x" style="width:18px;height:18px"></i>
            </button>
        </div>
        <div id="modal-notification" class="hidden mb-4 p-3 rounded-lg text-sm font-medium"></div>
        <form id="changePasswordForm" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                <input type="password" name="current_password" required
                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="new_password" required
                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                <input type="password" name="confirm_password" required
                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-300" />
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="cancelModalBtn"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- QR Scanner Modal (shared) -->
<div id="qrScannerModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center bg-slate-900/70 backdrop-blur-sm p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white shadow-2xl overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <h3 class="font-bold text-gray-900 text-base">Scan Asset QR Code</h3>
            </div>
            <button onclick="closeQrScanner()" class="p-1.5 rounded-full hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="p-5">
            <div id="qr-reader" class="rounded-xl overflow-hidden" style="width:100%"></div>
            <div id="qr-scan-status" class="mt-3 text-center text-sm text-gray-500">Point camera at the asset QR code</div>
        </div>
    </div>
</div>

<script src="loader/loader.js"></script>
<script src="notifications.js"></script>
<script src="loader/html5-qrcode.min.js"></script>

<script>
// ─── Sidebar Toggle ───
(function() {
    const sidebar        = document.getElementById('sidebar');
    const mainContent    = document.getElementById('mainContent');
    const menuBtn        = document.getElementById('menuBtn');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        if (!sidebar) return;
        if (window.innerWidth < 1024) {
            sidebar.classList.toggle('-translate-x-full');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('hidden');
        } else {
            sidebar.classList.toggle('lg:translate-x-0');
            if (mainContent) mainContent.classList.toggle('lg:ml-64');
        }
    }
    if (menuBtn)        menuBtn.addEventListener('click', toggleSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
})();

// ─── User Menu Dropdown ───
(function() {
    const btn      = document.getElementById('userMenuBtn');
    const dropdown = document.getElementById('userMenuDropdown');
    if (!btn || !dropdown) return;
    btn.addEventListener('click', () => dropdown.classList.toggle('hidden'));
    document.addEventListener('click', (e) => {
        if (!btn.contains(e.target) && !dropdown.contains(e.target))
            dropdown.classList.add('hidden');
    });
})();

// ─── Change Password Modal ───
(function() {
    const changeBtn    = document.getElementById('changePasswordBtn');
    const modal        = document.getElementById('passwordModal');
    const closeBtn     = document.getElementById('closeModalBtn');
    const cancelBtn    = document.getElementById('cancelModalBtn');
    const form         = document.getElementById('changePasswordForm');
    const notif        = document.getElementById('modal-notification');
    const dropdown     = document.getElementById('userMenuDropdown');

    if (!changeBtn || !modal) return;

    changeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (dropdown) dropdown.classList.add('hidden');
        modal.classList.remove('hidden');
        if (form) form.reset();
        if (notif) notif.classList.add('hidden');
    });

    [closeBtn, cancelBtn].forEach(b => {
        if (b) b.addEventListener('click', () => modal.classList.add('hidden'));
    });
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = new FormData(form);
            const res  = await fetch('change-password.php', { method: 'POST', body: data });
            const json = await res.json();
            if (notif) {
                notif.textContent  = json.message || (json.success ? 'Password changed.' : 'Error.');
                notif.className    = 'mb-4 p-3 rounded-lg text-sm font-medium ' +
                                     (json.success ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800');
                notif.classList.remove('hidden');
                if (json.success) { form.reset(); setTimeout(() => modal.classList.add('hidden'), 1500); }
            }
        });
    }
})();

// ─── Global Search ───
(function() {
    const searchInput   = document.getElementById('searchInput');
    const searchButton  = document.getElementById('searchButton');
    const searchResults = document.getElementById('searchResults');
    const searchIcon    = document.getElementById('search-icon');
    const searchSpinner = document.getElementById('search-spinner');
    const searchContainer = document.getElementById('search-container');
    if (!searchInput) return;

    let searchTimeout = null;

    async function performSearch() {
        const query = searchInput.value.trim();
        if (!query) { searchResults.classList.add('hidden'); return; }
        if (searchIcon)  searchIcon.classList.add('hidden');
        if (searchSpinner) searchSpinner.classList.remove('hidden');

        try {
            const res  = await fetch('search.php?query=' + encodeURIComponent(query));
            const data = await res.json();
            if (data && data.length > 0) {
                searchResults.innerHTML = data.map(item => `
                    <a href="view-batch-details.php?category_id=${item.category_id}&asset_name=${encodeURIComponent(item.asset_name)}&batch_id=${encodeURIComponent(item.batch_id)}"
                        class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 border-b border-gray-50 last:border-0 transition-colors">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                            <i data-lucide="package" class="text-blue-500" style="width:14px;height:14px"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">${item.asset_name}</p>
                            <p class="text-xs text-gray-400 font-mono truncate">${item.asset_no || ''}</p>
                        </div>
                    </a>`).join('');
                lucide.createIcons();
                searchResults.classList.remove('hidden');
            } else {
                searchResults.innerHTML = '<p class="p-4 text-sm text-center text-gray-500">No results found.</p>';
                searchResults.classList.remove('hidden');
            }
        } catch (err) {
            searchResults.classList.add('hidden');
        } finally {
            if (searchIcon)   searchIcon.classList.remove('hidden');
            if (searchSpinner) searchSpinner.classList.add('hidden');
        }
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 300);
    });
    searchButton.addEventListener('click', performSearch);
    searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') performSearch(); });
    document.addEventListener('click', (e) => {
        if (searchContainer && !searchContainer.contains(e.target))
            searchResults.classList.add('hidden');
    });
})();

// ─── QR Scanner ───
let qrScanner = null;
function openQrScanner() {
    document.getElementById('qrScannerModal').classList.remove('hidden');
    const status = document.getElementById('qr-scan-status');
    status.textContent = 'Starting camera...';
    status.className   = 'mt-3 text-center text-sm text-gray-500';
    qrScanner = new Html5Qrcode("qr-reader");
    qrScanner.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 220, height: 220 } },
        function(decodedText) {
            closeQrScanner();
            handleQrResult(decodedText.trim());
        },
        function() {}
    ).catch(function(err) {
        status.textContent = 'Camera error: ' + err;
        status.className   = 'mt-3 text-center text-sm text-red-600';
    });
}
function closeQrScanner() {
    if (qrScanner) { qrScanner.stop().catch(() => {}); qrScanner = null; }
    document.getElementById('qrScannerModal').classList.add('hidden');
}
async function handleQrResult(assetNo) {
    if (!assetNo) return;
    const inp = document.getElementById('searchInput');
    if (inp) inp.value = assetNo;
    try {
        const res  = await fetch('scan-asset.php?asset_no=' + encodeURIComponent(assetNo));
        const data = await res.json();
        if (data.error) { alert('QR Scan: ' + data.error); }
        else if (data.source === 'borrowed') { window.location.href = 'dashboard.php?view=loaned-assets&section=borrowed'; }
        else { window.location.href = 'view-batch-details.php?category_id=' + data.category_id + '&asset_name=' + encodeURIComponent(data.asset_name) + '&batch_id=' + encodeURIComponent(data.batch_id); }
    } catch(e) { alert('Could not lookup asset. Please try again.'); }
}
</script>
