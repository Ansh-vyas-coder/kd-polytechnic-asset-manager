<?php
// profile_security_modal.php — Shared Security & Account Credentials Modal (Password & Email)
if (defined('PROFILE_SECURITY_MODAL_RENDERED')) {
    return;
}
define('PROFILE_SECURITY_MODAL_RENDERED', true);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure session email is populated
if (empty($_SESSION['user_email']) && isset($_SESSION['user_id']) && isset($conn)) {
    $em_stmt = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    if ($em_stmt) {
        $em_stmt->bind_param("i", $_SESSION['user_id']);
        $em_stmt->execute();
        $em_res = $em_stmt->get_result()->fetch_assoc();
        if ($em_res && !empty($em_res['email'])) {
            $_SESSION['user_email'] = $em_res['email'];
        }
        $em_stmt->close();
    }
}

$user_role = $_SESSION['role'] ?? 'staff';
$current_user_email = $_SESSION['user_email'] ?? '';
?>

<!-- Account Security & Credentials Modal -->
<div id="passwordModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden transition-opacity duration-200">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg transform transition-all overflow-hidden flex flex-col max-h-[92vh]">
    
    <!-- Modal Header with Tabs -->
    <div class="px-6 pt-5 pb-0 border-b border-gray-100 bg-gray-50/70 shrink-0">
      <div class="flex items-center justify-between pb-3">
        <div class="flex items-center gap-2.5">
          <div class="w-9 h-9 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <div>
            <h3 class="text-base font-bold text-gray-900 leading-tight">Account Security &amp; Credentials</h3>
            <p class="text-xs text-gray-500">Manage your login password and verified email</p>
          </div>
        </div>
        <button type="button" id="closeSecurityModalBtn" class="p-1.5 rounded-lg hover:bg-gray-200/70 text-gray-400 hover:text-gray-600 transition" aria-label="Close modal">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Navigation Tabs -->
      <div class="flex border-b border-gray-200 gap-6 mt-1">
        <button type="button" id="secTabPasswordBtn" class="pb-3 text-sm font-bold text-blue-600 border-b-2 border-blue-600 flex items-center gap-2 transition-all">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Change Password
        </button>
        <button type="button" id="secTabEmailBtn" class="pb-3 text-sm font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent flex items-center gap-2 transition-all">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          Change Email Address
        </button>
      </div>
    </div>

    <!-- Notification Alert Box -->
    <div class="px-6 pt-4 shrink-0">
      <div id="modal-notification" class="hidden text-sm p-3.5 rounded-xl font-medium transition-all"></div>
    </div>

    <!-- Modal Scrollable Body -->
    <div class="p-6 overflow-y-auto space-y-4 flex-1">

      <!-- ================= TAB 1: CHANGE PASSWORD ================= -->
      <div id="secTabPasswordContent">
        <form id="changePasswordForm" class="space-y-4">
          <input type="hidden" name="action" value="change_password">

          <!-- Current Password -->
          <div>
            <label for="sec_current_password" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">
              Current Password <span class="text-rose-500">*</span>
            </label>
            <input type="password" id="sec_current_password" name="current_password" required
                   placeholder="Enter your current password"
                   class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
          </div>

          <!-- OTP Request & Input Row -->
          <div class="bg-blue-50/60 border border-blue-200/70 rounded-xl p-3.5 space-y-2">
            <div class="flex items-center justify-between flex-wrap gap-2">
              <label for="sec_otp" class="text-xs font-bold text-blue-900 uppercase tracking-wider">
                Email OTP Verification <span class="text-rose-500">*</span>
              </label>
              <button type="button" id="btnRequestOtp"
                      class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-lg transition shadow-sm inline-flex items-center gap-1.5">
                <svg id="otpIcon" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                <span id="btnRequestOtpText">Send OTP to Email</span>
              </button>
            </div>
            
            <div class="relative">
              <input type="text" id="sec_otp" name="otp" maxlength="6" pattern="[0-9]{6}" required
                     placeholder="Enter 6-digit OTP from email"
                     class="w-full px-3.5 py-2.5 bg-white border border-blue-300 rounded-lg text-sm font-mono tracking-widest text-center text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 transition">
            </div>
            <p id="otpHint" class="text-xs text-blue-700 leading-relaxed">
              Click <strong>Send OTP</strong> after typing your current password. A 6-digit verification code will be sent to your registered email (valid for 10 mins).
            </p>
          </div>

          <!-- New Password -->
          <div>
            <label for="sec_new_password" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">
              New Password <span class="text-rose-500">*</span>
            </label>
            <input type="password" id="sec_new_password" name="new_password" required minlength="8"
                   placeholder="At least 8 characters"
                   class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            <span class="text-xs text-gray-400 mt-1 block">Must be at least 8 characters long.</span>
          </div>

          <!-- Confirm New Password -->
          <div>
            <label for="sec_confirm_password" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">
              Confirm New Password <span class="text-rose-500">*</span>
            </label>
            <input type="password" id="sec_confirm_password" name="confirm_password" required minlength="8"
                   placeholder="Repeat new password"
                   class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
          </div>

          <!-- Submit Button -->
          <div class="pt-2 flex justify-end gap-3">
            <button type="button" id="cancelModalBtn" class="px-4 py-2.5 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-100 transition">
              Cancel
            </button>
            <button type="submit" id="submitPasswordBtn" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold shadow-md shadow-blue-500/20 transition inline-flex items-center gap-2">
              <span id="submitBtnText">Update Password</span>
              <div id="submitSpinner" class="hidden animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></div>
            </button>
          </div>
        </form>
      </div>

      <!-- ================= TAB 2: CHANGE EMAIL ================= -->
      <div id="secTabEmailContent" class="hidden">
        <form id="changeEmailForm" class="space-y-4">
          <input type="hidden" name="action" value="request_email_change">

          <!-- Current Email (Read-Only) -->
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">
              Current Registered Email
            </label>
            <div class="px-3.5 py-2.5 bg-gray-100 border border-gray-200 rounded-xl text-sm text-gray-600 font-mono flex items-center justify-between">
              <span id="displayCurrentEmail"><?php echo htmlspecialchars($current_user_email); ?></span>
              <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-semibold font-sans">Active</span>
            </div>
          </div>

          <!-- New Email Address -->
          <div>
            <label for="sec_new_email" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">
              New Email Address <span class="text-rose-500">*</span>
            </label>
            <input type="email" id="sec_new_email" name="new_email" required
                   placeholder="Enter your new email address"
                   class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
          </div>

          <!-- Current Password (for security) -->
          <div>
            <label for="sec_email_cur_pwd" class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">
              Current Password <span class="text-rose-500">*</span>
            </label>
            <input type="password" id="sec_email_cur_pwd" name="current_password" required
                   placeholder="Enter your current password to authorize"
                   class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
          </div>

          <!-- Explainer Information Card -->
          <div class="bg-indigo-50/70 border border-indigo-200/80 rounded-xl p-3.5 text-xs text-indigo-900 leading-relaxed space-y-1.5">
            <div class="font-bold flex items-center gap-1.5 text-indigo-950">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
              How Email Change Verification Works
            </div>
            <p>
              1. A secure verification link will be emailed to your <strong>new email address</strong>.
            </p>
            <p>
              2. Open that email and click <strong>Verify</strong> within 24 hours.
            </p>
            <p>
              <?php if ($user_role === 'admin'): ?>
                3. <strong>Admin Routing:</strong> If you are the sole administrator, your email will update directly. If other administrators exist, an approval request will be sent to them.
              <?php else: ?>
                3. <strong>Staff Routing:</strong> After clicking verify, your request will be sent to the Administrator for approval before the change takes effect.
              <?php endif; ?>
            </p>
          </div>

          <!-- Submit Button -->
          <div class="pt-2 flex justify-end gap-3">
            <button type="button" id="cancelEmailModalBtn" class="px-4 py-2.5 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-100 transition">
              Cancel
            </button>
            <button type="submit" id="submitEmailBtn" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold shadow-md shadow-indigo-500/20 transition inline-flex items-center gap-2">
              <span id="submitEmailBtnText">Send Verification Link</span>
              <div id="submitEmailSpinner" class="hidden animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></div>
            </button>
          </div>
        </form>
      </div>

    </div>
  </div>
</div>

<script>
(function() {
  const modal = document.getElementById('passwordModal');
  const closeBtn = document.getElementById('closeSecurityModalBtn');
  const cancelBtn = document.getElementById('cancelModalBtn');
  const cancelEmailBtn = document.getElementById('cancelEmailModalBtn');
  const notif = document.getElementById('modal-notification');

  // Tabs
  const tabPwdBtn = document.getElementById('secTabPasswordBtn');
  const tabEmBtn = document.getElementById('secTabEmailBtn');
  const tabPwdContent = document.getElementById('secTabPasswordContent');
  const tabEmContent = document.getElementById('secTabEmailContent');

  // Forms
  const pwdForm = document.getElementById('changePasswordForm');
  const emForm = document.getElementById('changeEmailForm');

  // OTP elements
  const btnRequestOtp = document.getElementById('btnRequestOtp');
  const btnRequestOtpText = document.getElementById('btnRequestOtpText');
  const otpHint = document.getElementById('otpHint');
  let otpCountdown = 0;
  let otpTimer = null;

  function setNotif(msg, type) {
    if (!notif) return;
    if (!msg) {
      notif.classList.add('hidden');
      return;
    }
    notif.textContent = msg;
    notif.classList.remove('hidden', 'bg-emerald-50', 'text-emerald-800', 'border-emerald-200', 'bg-rose-50', 'text-rose-800', 'border-rose-200', 'bg-blue-50', 'text-blue-800', 'border-blue-200');
    if (type === 'success') {
      notif.className = 'text-sm p-3.5 rounded-xl font-medium border bg-emerald-50 text-emerald-800 border-emerald-200';
    } else if (type === 'info') {
      notif.className = 'text-sm p-3.5 rounded-xl font-medium border bg-blue-50 text-blue-800 border-blue-200';
    } else {
      notif.className = 'text-sm p-3.5 rounded-xl font-medium border bg-rose-50 text-rose-800 border-rose-200';
    }
  }

  function switchTab(tab) {
    setNotif('', '');
    if (tab === 'password') {
      tabPwdBtn.className = 'pb-3 text-sm font-bold text-blue-600 border-b-2 border-blue-600 flex items-center gap-2 transition-all';
      tabEmBtn.className = 'pb-3 text-sm font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent flex items-center gap-2 transition-all';
      tabPwdContent.classList.remove('hidden');
      tabEmContent.classList.add('hidden');
    } else {
      tabEmBtn.className = 'pb-3 text-sm font-bold text-indigo-600 border-b-2 border-indigo-600 flex items-center gap-2 transition-all';
      tabPwdBtn.className = 'pb-3 text-sm font-semibold text-gray-500 hover:text-gray-800 border-b-2 border-transparent flex items-center gap-2 transition-all';
      tabEmContent.classList.remove('hidden');
      tabPwdContent.classList.add('hidden');
    }
  }

  if (tabPwdBtn) tabPwdBtn.addEventListener('click', () => switchTab('password'));
  if (tabEmBtn) tabEmBtn.addEventListener('click', () => switchTab('email'));

  window.showPasswordModal = function() {
    if (!modal) return;
    modal.classList.remove('hidden');
    setNotif('', '');
    switchTab('password');
    if (pwdForm) pwdForm.reset();
    if (emForm) emForm.reset();
  };

  window.hidePasswordModal = function() {
    if (!modal) return;
    modal.classList.add('hidden');
    setNotif('', '');
  };

  window.openSecurityModal = window.showPasswordModal;
  window.closeSecurityModal = window.hidePasswordModal;

  // Bind trigger buttons across the page
  document.querySelectorAll('#changePasswordBtn, [data-open-modal="security"], .open-security-modal').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      const dropdown = document.getElementById('userMenuDropdown');
      if (dropdown) dropdown.classList.add('hidden');
      window.showPasswordModal();
    });
  });

  [closeBtn, cancelBtn, cancelEmailBtn].forEach(b => {
    if (b) b.addEventListener('click', window.hidePasswordModal);
  });

  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) window.hidePasswordModal();
    });
  }

  // Handle Send OTP
  if (btnRequestOtp) {
    btnRequestOtp.addEventListener('click', async function() {
      if (otpCountdown > 0) return;
      const curPwd = document.getElementById('sec_current_password').value.trim();
      if (!curPwd) {
        setNotif('Please enter your Current Password first so we can verify your identity.', 'error');
        document.getElementById('sec_current_password').focus();
        return;
      }

      btnRequestOtp.disabled = true;
      btnRequestOtpText.textContent = 'Sending OTP...';
      setNotif('Generating secure OTP and sending email...', 'info');

      try {
        const formData = new FormData();
        formData.append('action', 'send_otp');
        formData.append('current_password', curPwd);

        const res = await fetch('change-password.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
          setNotif(data.message, 'success');
          document.getElementById('sec_otp').focus();

          // Start 60 second timer
          otpCountdown = 60;
          btnRequestOtpText.textContent = `Resend in (${otpCountdown}s)`;
          otpTimer = setInterval(() => {
            otpCountdown--;
            if (otpCountdown <= 0) {
              clearInterval(otpTimer);
              btnRequestOtp.disabled = false;
              btnRequestOtpText.textContent = 'Resend OTP';
            } else {
              btnRequestOtpText.textContent = `Resend in (${otpCountdown}s)`;
            }
          }, 1000);
        } else {
          setNotif(data.message || 'Failed to send OTP.', 'error');
          btnRequestOtp.disabled = false;
          btnRequestOtpText.textContent = 'Send OTP to Email';
        }
      } catch (err) {
        setNotif('Network error while requesting OTP. Please try again.', 'error');
        btnRequestOtp.disabled = false;
        btnRequestOtpText.textContent = 'Send OTP to Email';
      }
    });
  }

  // Handle Password Submit
  if (pwdForm) {
    pwdForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      const submitBtn = document.getElementById('submitPasswordBtn');
      const submitText = document.getElementById('submitBtnText');
      const spinner = document.getElementById('submitSpinner');

      const curPwd = document.getElementById('sec_current_password').value;
      const otpVal = document.getElementById('sec_otp').value.trim();
      const newPwd = document.getElementById('sec_new_password').value;
      const confirmPwd = document.getElementById('sec_confirm_password').value;

      if (!otpVal || otpVal.length !== 6) {
        setNotif('Please enter the 6-digit OTP received in your email.', 'error');
        document.getElementById('sec_otp').focus();
        return;
      }

      if (newPwd !== confirmPwd) {
        setNotif('New password and confirmation do not match.', 'error');
        return;
      }

      if (newPwd.length < 8) {
        setNotif('New password must be at least 8 characters long.', 'error');
        return;
      }

      submitBtn.disabled = true;
      submitText.textContent = 'Updating...';
      if (spinner) spinner.classList.remove('hidden');

      try {
        const formData = new FormData(pwdForm);
        const res = await fetch('change-password.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
          setNotif(data.message, 'success');
          pwdForm.reset();
          if (otpTimer) clearInterval(otpTimer);
          otpCountdown = 0;
          if (btnRequestOtp) {
            btnRequestOtp.disabled = false;
            btnRequestOtpText.textContent = 'Send OTP to Email';
          }
          setTimeout(window.hidePasswordModal, 2000);
        } else {
          setNotif(data.message || 'Failed to update password.', 'error');
        }
      } catch (err) {
        setNotif('A network error occurred. Please try again.', 'error');
      } finally {
        submitBtn.disabled = false;
        submitText.textContent = 'Update Password';
        if (spinner) spinner.classList.add('hidden');
      }
    });
  }

  // Handle Email Change Submit
  if (emForm) {
    emForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      const submitBtn = document.getElementById('submitEmailBtn');
      const submitText = document.getElementById('submitEmailBtnText');
      const spinner = document.getElementById('submitEmailSpinner');

      const newEmail = document.getElementById('sec_new_email').value.trim();
      const curPwd = document.getElementById('sec_email_cur_pwd').value;

      if (!newEmail) {
        setNotif('Please enter your new email address.', 'error');
        return;
      }

      if (!curPwd) {
        setNotif('Please enter your current password to authorize this email change.', 'error');
        return;
      }

      submitBtn.disabled = true;
      submitText.textContent = 'Sending Verification...';
      if (spinner) spinner.classList.remove('hidden');

      try {
        const formData = new FormData(emForm);
        const res = await fetch('account-actions.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
          setNotif(data.message, 'success');
          emForm.reset();
        } else {
          setNotif(data.message || 'Failed to process email change request.', 'error');
        }
      } catch (err) {
        setNotif('A network error occurred while submitting email change request.', 'error');
      } finally {
        submitBtn.disabled = false;
        submitText.textContent = 'Send Verification Link';
        if (spinner) spinner.classList.add('hidden');
      }
    });
  }
})();
</script>
