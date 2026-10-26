<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}

$message = '';
$message_type = ''; // 'success' or 'error'
$config_file = __DIR__ . '/mail-config.php';

// Handle AJAX Test Email
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'test_email') {
    header('Content-Type: application/json');
    $test_recipient = trim($_POST['test_email'] ?? '');
    if (!filter_var($test_recipient, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid recipient email address for testing.']);
        exit();
    }

    $subject = "Test Email — K.D. Polytechnic Asset Manager";
    $test_html = "
    <div style='font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:550px;margin:0 auto;padding:25px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;'>
        <div style='background:#1e3a8a;color:#ffffff;padding:20px;text-align:center;border-radius:8px;margin-bottom:20px;'>
            <h2 style='margin:0;font-size:20px;'>K.D. Polytechnic Asset Manager</h2>
            <p style='margin:5px 0 0;font-size:13px;color:#bfdbfe;'>SMTP Configuration Verification</p>
        </div>
        <p style='color:#1e293b;font-size:15px;line-height:1.6;'>
            Congratulations! If you are reading this email, your <strong>live email delivery configuration is working 100% correctly</strong>.
        </p>
        <div style='background:#f0fdf4;border:1px solid #bbf7d0;padding:14px;border-radius:8px;color:#166534;font-size:13px;margin:20px 0;'>
            ✓ Delivery Method: <strong>" . (MAIL_SMTP_ENABLED ? 'Live SMTP (' . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT . ')' : 'Hosting Server PHP mail()') . "</strong><br>
            ✓ Sent At: <strong>" . date('d M Y, h:i:s A') . "</strong><br>
            ✓ Server Host: <strong>" . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') . "</strong>
        </div>
        <p style='color:#64748b;font-size:12px;text-align:center;'>
            Government Polytechnic Patan &bull; Gujarat Technical Education
        </p>
    </div>";

    $result = send_system_email($test_recipient, 'Administrator', $subject, $test_html);

    if ($result['success'] && ($result['transport'] ?? '') !== 'log') {
        echo json_encode([
            'success' => true,
            'message' => 'Test email was successfully sent to ' . htmlspecialchars($test_recipient) . ' via ' . strtoupper($result['transport'] ?? 'SMTP') . '!'
        ]);
    } elseif ($result['success'] && ($result['transport'] ?? '') === 'log') {
        echo json_encode([
            'success' => false,
            'message' => 'Email was NOT sent over the internet — it was logged locally to logs/email_log.txt because Live SMTP is turned OFF. Please enable SMTP below and provide credentials.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Email delivery failed: ' . ($result['error'] ?? 'Unknown connection error. Check your SMTP host, port, username and password.')
        ]);
    }
    exit();
}

// Handle Save Configuration
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $smtp_enabled    = isset($_POST['smtp_enabled']) && $_POST['smtp_enabled'] === '1';
    $smtp_host       = trim($_POST['smtp_host'] ?? 'smtp.gmail.com');
    $smtp_port       = (int)($_POST['smtp_port'] ?? 587);
    $smtp_encryption = strtolower(trim($_POST['smtp_encryption'] ?? 'tls'));
    $smtp_user       = trim($_POST['smtp_user'] ?? '');
    $smtp_pass       = trim($_POST['smtp_pass'] ?? '');
    $from_address    = trim($_POST['from_address'] ?? '');
    $from_name       = trim($_POST['from_name'] ?? 'K.D. Polytechnic Asset Manager');

    // If password was left blank and file exists, preserve existing password
    if (empty($smtp_pass) && defined('MAIL_SMTP_PASS') && MAIL_SMTP_PASS !== '') {
        $smtp_pass = MAIL_SMTP_PASS;
    }

    if ($smtp_enabled && empty($smtp_host)) {
        $message = "Please specify an SMTP Host (e.g. smtp.gmail.com).";
        $message_type = "error";
    } elseif ($smtp_enabled && empty($smtp_user)) {
        $message = "Please provide your SMTP Username or Email address.";
        $message_type = "error";
    } else {
        $safe_enabled = $smtp_enabled ? 'true' : 'false';
        $safe_host    = addslashes($smtp_host);
        $safe_port    = $smtp_port > 0 ? $smtp_port : 587;
        $safe_enc     = in_array($smtp_encryption, ['tls', 'ssl', 'none']) ? $smtp_encryption : 'tls';
        $safe_user    = addslashes($smtp_user);
        $safe_pass    = addslashes($smtp_pass);
        $safe_from_a  = addslashes($from_address);
        $safe_from_n  = addslashes($from_name);

        $config_content = "<?php\n" .
            "// mail-config.php — Production Email Configuration\n" .
            "// Generated automatically via Admin Email Settings on " . date('Y-m-d H:i:s') . "\n\n" .
            "define('MAIL_SMTP_ENABLED', {$safe_enabled});\n" .
            "define('MAIL_SMTP_HOST', '{$safe_host}');\n" .
            "define('MAIL_SMTP_PORT', {$safe_port});\n" .
            "define('MAIL_SMTP_ENCRYPTION', '{$safe_enc}');\n" .
            "define('MAIL_SMTP_USER', '{$safe_user}');\n" .
            "define('MAIL_SMTP_PASS', '{$safe_pass}');\n" .
            "define('MAIL_FROM_ADDRESS', '{$safe_from_a}');\n" .
            "define('MAIL_FROM_NAME', '{$safe_from_n}');\n";

        if (@file_put_contents($config_file, $config_content) !== false) {
            $message = "Email settings saved successfully! You can now send a test email to verify.";
            $message_type = "success";
        } else {
            $message = "Unable to write to mail-config.php. Please ensure directory write permissions on your hosting server.";
            $message_type = "error";
        }
    }
}

// Read current active values
$cur_enabled    = defined('MAIL_SMTP_ENABLED') ? MAIL_SMTP_ENABLED : false;
$cur_host       = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : 'smtp.gmail.com';
$cur_port       = defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : 587;
$cur_enc        = defined('MAIL_SMTP_ENCRYPTION') ? MAIL_SMTP_ENCRYPTION : 'tls';
$cur_user       = defined('MAIL_SMTP_USER') ? MAIL_SMTP_USER : '';
$cur_pass       = defined('MAIL_SMTP_PASS') ? MAIL_SMTP_PASS : '';
$cur_from_addr  = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@kdpolytechnic.ac.in';
$cur_from_name  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'K.D. Polytechnic Asset Manager';

$current_page = 'email-settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Email Settings &amp; Delivery - KDP Asset Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
  </script>
  <style>
    html, body { font-family: 'Inter', sans-serif; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 9999px; }
    ::-webkit-scrollbar-thumb:hover { background: #D1D5DB; }
  </style>
  <link rel="stylesheet" href="loader/loader.css" />
  <link rel="stylesheet" href="notifications.css" />
</head>
<body class="h-screen bg-slate-50 text-slate-900 antialiased">
  <?php include 'loader/loader.html'; ?>
<div class="h-screen flex overflow-hidden">
  <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>
  <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

  <div id="mainContent" class="flex-1 flex flex-col min-w-0 lg:ml-64 transition-all duration-300 ease-in-out h-screen overflow-hidden">
    <!-- Header -->
    <?php include 'topbar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto flex flex-col">
      <main class="flex-1 bg-slate-50 p-4 lg:p-6">
        <div class="max-w-4xl mx-auto space-y-6">

          <!-- Header Title -->
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Email Delivery Settings</h1>
              <p class="text-sm text-gray-500 mt-1">Configure live email credentials so OTPs, password reset, and activation emails reach user inboxes reliably.</p>
            </div>
            <div>
              <?php if ($cur_enabled && !empty($cur_user) && !empty($cur_pass)): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                  <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                  Live SMTP Active
                </span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                  <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                  SMTP Disabled (Hosting Mail)
                </span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Alert Message -->
          <?php if (!empty($message)): ?>
            <div class="rounded-xl p-4 text-sm font-medium <?php echo $message_type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-rose-50 border border-rose-200 text-rose-800'; ?>">
              <?php echo htmlspecialchars($message); ?>
            </div>
          <?php endif; ?>

          <!-- Test Mail Card -->
          <div class="bg-gradient-to-r from-blue-900 to-indigo-900 rounded-2xl p-6 text-white shadow-md">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
              <div class="space-y-1">
                <h3 class="text-lg font-bold flex items-center gap-2">
                  <i data-lucide="send" class="w-5 h-5 text-blue-300"></i> Test Live Email Delivery
                </h3>
                <p class="text-xs text-blue-200 leading-relaxed max-w-lg">
                  Send a live test message to verify that your email server can successfully reach inboxes without bouncing or being blocked by hosting firewalls.
                </p>
              </div>
              <div class="w-full md:w-auto flex flex-col sm:flex-row gap-2.5">
                <input type="email" id="testEmailInput" placeholder="recipient@example.com" value="<?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>"
                  class="px-3.5 py-2.5 rounded-lg text-sm bg-white/10 border border-white/20 text-white placeholder-blue-200 focus:outline-none focus:ring-2 focus:ring-white/40 focus:bg-white/20 transition min-w-[240px]">
                <button type="button" id="sendTestBtn" onclick="sendTestEmail()"
                  class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold bg-white text-blue-900 hover:bg-blue-50 transition shadow-sm shrink-0">
                  <i data-lucide="mail-check" class="w-4 h-4"></i>
                  <span id="testBtnText">Send Test Email</span>
                </button>
              </div>
            </div>
            <!-- Test Result Feedback -->
            <div id="testResultBox" class="hidden mt-4 p-3.5 rounded-lg text-xs font-medium"></div>
          </div>

          <!-- Settings Form -->
          <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form action="email-settings.php" method="POST" id="emailSettingsForm" class="space-y-6">
              <input type="hidden" name="action" value="save_config">

              <!-- Quick Provider Selection Buttons -->
              <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2.5">Quick Provider Presets</label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                  <button type="button" onclick="setPreset('gmail')" class="p-3 border border-gray-200 rounded-xl hover:border-blue-500 hover:bg-blue-50/50 text-left transition group">
                    <div class="font-bold text-sm text-gray-900 group-hover:text-blue-600 flex items-center gap-1.5">
                      <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span> Gmail
                    </div>
                    <div class="text-[11px] text-gray-400 mt-0.5">Google Workspace</div>
                  </button>

                  <button type="button" onclick="setPreset('cpanel')" class="p-3 border border-gray-200 rounded-xl hover:border-blue-500 hover:bg-blue-50/50 text-left transition group">
                    <div class="font-bold text-sm text-gray-900 group-hover:text-blue-600 flex items-center gap-1.5">
                      <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> cPanel / Webmail
                    </div>
                    <div class="text-[11px] text-gray-400 mt-0.5">Hosting Domain Mail</div>
                  </button>

                  <button type="button" onclick="setPreset('outlook')" class="p-3 border border-gray-200 rounded-xl hover:border-blue-500 hover:bg-blue-50/50 text-left transition group">
                    <div class="font-bold text-sm text-gray-900 group-hover:text-blue-600 flex items-center gap-1.5">
                      <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span> Outlook
                    </div>
                    <div class="text-[11px] text-gray-400 mt-0.5">Office 365</div>
                  </button>

                  <button type="button" onclick="setPreset('disabled')" class="p-3 border border-gray-200 rounded-xl hover:border-gray-400 hover:bg-gray-50 text-left transition group">
                    <div class="font-bold text-sm text-gray-600 group-hover:text-gray-900 flex items-center gap-1.5">
                      <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span> PHP mail()
                    </div>
                    <div class="text-[11px] text-gray-400 mt-0.5">Hosting Default</div>
                  </button>
                </div>
              </div>

              <!-- Enable SMTP Toggle -->
              <div class="pt-2 border-t border-gray-100 flex items-center justify-between">
                <div>
                  <h4 class="text-sm font-bold text-gray-900">Enable Live SMTP Delivery</h4>
                  <p class="text-xs text-gray-500 mt-0.5">Sends emails directly via secure SMTP socket connection (Guarantees inbox delivery).</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" name="smtp_enabled" id="smtp_enabled" value="1" <?php echo $cur_enabled ? 'checked' : ''; ?> class="sr-only peer" onchange="toggleSmtpFields()">
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>

              <!-- SMTP Detail Fields -->
              <div id="smtpFieldsContainer" class="space-y-4 pt-2">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">SMTP Server Host</label>
                    <input type="text" name="smtp_host" id="smtp_host" value="<?php echo htmlspecialchars($cur_host); ?>" placeholder="smtp.gmail.com"
                      class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Port</label>
                    <input type="number" name="smtp_port" id="smtp_port" value="<?php echo (int)$cur_port; ?>" placeholder="587"
                      class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Encryption Protocol</label>
                    <select name="smtp_encryption" id="smtp_encryption" class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                      <option value="tls" <?php echo $cur_enc === 'tls' ? 'selected' : ''; ?>>TLS (Recommended for Port 587)</option>
                      <option value="ssl" <?php echo $cur_enc === 'ssl' ? 'selected' : ''; ?>>SSL (Recommended for Port 465)</option>
                      <option value="none" <?php echo $cur_enc === 'none' ? 'selected' : ''; ?>>None / Plain (Port 25)</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">SMTP Username / Email</label>
                    <input type="text" name="smtp_user" id="smtp_user" value="<?php echo htmlspecialchars($cur_user); ?>" placeholder="yourcollege@gmail.com"
                      class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">SMTP Password / App Password</label>
                  <input type="password" name="smtp_pass" id="smtp_pass" placeholder="<?php echo !empty($cur_pass) ? '•••••••••••••••• (Leave blank to keep current)' : 'Enter 16-character App Password'; ?>"
                    class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                  <p class="text-[11px] text-gray-400 mt-1">
                    💡 For Gmail accounts with 2-Step Verification, generate a 16-character App Password at:
                    <a href="https://myaccount.google.com/apppasswords" target="_blank" class="text-blue-600 hover:underline">Google App Passwords</a>.
                  </p>
                </div>
              </div>

              <!-- Sender Info -->
              <div class="pt-4 border-t border-gray-100 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">From Email Address</label>
                  <input type="email" name="from_address" id="from_address" value="<?php echo htmlspecialchars($cur_from_addr); ?>" placeholder="noreply@kdpolytechnic.ac.in"
                    class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                  <p class="text-[11px] text-gray-400 mt-1">Sender address displayed in user mailboxes.</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1">From Sender Name</label>
                  <input type="text" name="from_name" id="from_name" value="<?php echo htmlspecialchars($cur_from_name); ?>" placeholder="K.D. Polytechnic Asset Manager"
                    class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500">
                </div>
              </div>

              <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 transition shadow-sm">
                  <i data-lucide="save" class="w-4 h-4"></i>
                  Save Email Settings
                </button>
              </div>
            </form>
          </div>

        </div>
      </main>
      <?php include 'footer.php'; ?>
    </div>
  </div>
</div>

<script>
  lucide.createIcons();

  function toggleSmtpFields() {
    const isEnabled = document.getElementById('smtp_enabled').checked;
    const container = document.getElementById('smtpFieldsContainer');
    if (isEnabled) {
      container.classList.remove('opacity-40', 'pointer-events-none');
    } else {
      container.classList.add('opacity-40', 'pointer-events-none');
    }
  }

  function setPreset(provider) {
    const enabled = document.getElementById('smtp_enabled');
    const host    = document.getElementById('smtp_host');
    const port    = document.getElementById('smtp_port');
    const enc     = document.getElementById('smtp_encryption');

    if (provider === 'gmail') {
      enabled.checked = true;
      host.value = 'smtp.gmail.com';
      port.value = 587;
      enc.value  = 'tls';
    } else if (provider === 'cpanel') {
      enabled.checked = true;
      const domain = window.location.hostname.replace('www.', '');
      host.value = 'mail.' + (domain || 'yourdomain.com');
      port.value = 465;
      enc.value  = 'ssl';
    } else if (provider === 'outlook') {
      enabled.checked = true;
      host.value = 'smtp.office365.com';
      port.value = 587;
      enc.value  = 'tls';
    } else if (provider === 'disabled') {
      enabled.checked = false;
    }
    toggleSmtpFields();
  }

  async function sendTestEmail() {
    const email = document.getElementById('testEmailInput').value.trim();
    const btn = document.getElementById('sendTestBtn');
    const btnText = document.getElementById('testBtnText');
    const resBox = document.getElementById('testResultBox');

    if (!email) {
      alert('Please enter an email address to receive the test message.');
      return;
    }

    btn.disabled = true;
    btnText.textContent = 'Sending Test...';
    resBox.classList.add('hidden');

    try {
      const fd = new FormData();
      fd.append('action', 'test_email');
      fd.append('test_email', email);

      const res = await fetch('email-settings.php', { method: 'POST', body: fd });
      const data = await res.json();

      resBox.classList.remove('hidden', 'bg-emerald-500/20', 'text-emerald-200', 'border-emerald-400', 'bg-rose-500/20', 'text-rose-200', 'border-rose-400');
      if (data.success) {
        resBox.className = 'mt-4 p-3.5 rounded-lg text-xs font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/40';
        resBox.innerHTML = '✓ ' + data.message;
      } else {
        resBox.className = 'mt-4 p-3.5 rounded-lg text-xs font-semibold bg-rose-500/20 text-rose-200 border border-rose-400/40';
        resBox.innerHTML = '✗ ' + data.message;
      }
    } catch (err) {
      resBox.className = 'mt-4 p-3.5 rounded-lg text-xs font-semibold bg-rose-500/20 text-rose-200 border border-rose-400/40';
      resBox.innerHTML = '✗ Request failed. Please check network connection.';
      resBox.classList.remove('hidden');
    }

    btn.disabled = false;
    btnText.textContent = 'Send Test Email';
  }

  document.addEventListener('DOMContentLoaded', toggleSmtpFields);
</script>
<script src="loader/loader.js"></script>
</body>
</html>
