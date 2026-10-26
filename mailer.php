<?php
// mailer.php — Email utility for K.D. Polytechnic Asset Manager

if (file_exists(__DIR__ . '/mail-config.php')) {
    require_once __DIR__ . '/mail-config.php';
} elseif (file_exists(__DIR__ . '/mail-config.sample.php')) {
    require_once __DIR__ . '/mail-config.sample.php';
}

// Fallback constants if not defined
if (!defined('MAIL_SMTP_ENABLED')) define('MAIL_SMTP_ENABLED', false);
if (!defined('MAIL_SMTP_HOST')) define('MAIL_SMTP_HOST', 'smtp.gmail.com');
if (!defined('MAIL_SMTP_PORT')) define('MAIL_SMTP_PORT', 587);
if (!defined('MAIL_SMTP_ENCRYPTION')) define('MAIL_SMTP_ENCRYPTION', 'tls');
if (!defined('MAIL_SMTP_USER')) define('MAIL_SMTP_USER', '');
if (!defined('MAIL_SMTP_PASS')) define('MAIL_SMTP_PASS', '');
if (!defined('MAIL_FROM_ADDRESS')) define('MAIL_FROM_ADDRESS', 'noreply@kdpolytechnic.ac.in');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'K.D. Polytechnic Asset Manager');

/**
 * Returns the dynamic base URL of the application.
 */
function get_app_base_url(): string {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $is_https ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');
    
    return $protocol . $host . $scriptDir;
}

/**
 * Sends an email using SMTP socket connection or falls back gracefully.
 */
function send_system_email(string $to_email, string $to_name, string $subject, string $html_body, string $plain_text = ''): array {
    $to_email = trim($to_email);
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid recipient email address.'];
    }

    if (empty($plain_text)) {
        $plain_text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html_body));
    }

    // Determine effective from email
    $effective_from = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '';
    if (MAIL_SMTP_ENABLED && !empty(MAIL_SMTP_USER) && filter_var(MAIL_SMTP_USER, FILTER_VALIDATE_EMAIL)) {
        if (stripos(MAIL_SMTP_HOST, 'gmail') !== false || stripos(MAIL_SMTP_HOST, 'yahoo') !== false || empty($effective_from)) {
            $effective_from = MAIL_SMTP_USER;
        }
    }
    if (empty($effective_from) || !filter_var($effective_from, FILTER_VALIDATE_EMAIL)) {
        $effective_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'kdpolytechnic.ac.in');
    }

    $boundary = "==Multipart_Boundary_x" . md5(microtime()) . "x";
    $from_name_encoded = "=?UTF-8?B?" . base64_encode(MAIL_FROM_NAME) . "?=";
    $to_name_encoded   = "=?UTF-8?B?" . base64_encode($to_name) . "?=";
    $subject_encoded   = "=?UTF-8?B?" . base64_encode($subject) . "?=";

    // Headers
    $headers = [];
    $headers[] = "From: {$from_name_encoded} <{$effective_from}>";
    $headers[] = "Reply-To: {$from_name_encoded} <{$effective_from}>";
    $headers[] = "To: {$to_name_encoded} <{$to_email}>";
    $headers[] = "Date: " . date('r');
    $headers[] = "Message-ID: <" . md5(uniqid(microtime(), true)) . "@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ">";
    $headers[] = "Subject: {$subject_encoded}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
    $headers[] = "X-Mailer: PHP/" . phpversion();

    // Body
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plain_text)) . "\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html_body)) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $smtp_result = null;

    // 1. Try Live SMTP if enabled
    if (MAIL_SMTP_ENABLED && !empty(MAIL_SMTP_HOST) && !empty(MAIL_SMTP_USER) && !empty(MAIL_SMTP_PASS)) {
        try {
            $smtp_result = send_via_smtp($to_email, $headers, $body, $effective_from);
            if ($smtp_result['success']) {
                log_email_record($to_email, $subject, 'SMTP (Delivered)', $html_body);
                return ['success' => true, 'transport' => 'smtp', 'message' => 'Email sent via SMTP successfully.'];
            }
            log_email_record($to_email, $subject, 'SMTP Failed: ' . ($smtp_result['error'] ?? 'Unknown'), $html_body);
        } catch (\Throwable $e) {
            $smtp_result = ['success' => false, 'error' => $e->getMessage()];
            log_email_record($to_email, $subject, 'SMTP Exception: ' . $e->getMessage(), $html_body);
        }
    }

    // 2. Try hosting built-in PHP mail()
    $raw_headers = implode("\r\n", [
        "From: {$from_name_encoded} <{$effective_from}>",
        "Reply-To: {$from_name_encoded} <{$effective_from}>",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
    ]);

    $sender_param = "-f" . $effective_from;
    $mail_sent = @mail($to_email, $subject_encoded, $body, $raw_headers, $sender_param);
    if (!$mail_sent) {
        $mail_sent = @mail($to_email, $subject_encoded, $body, $raw_headers);
    }
    if ($mail_sent) {
        log_email_record($to_email, $subject, 'PHP mail()', $html_body);
        return ['success' => true, 'transport' => 'mail', 'message' => 'Email sent via hosting PHP mail().'];
    }

    // 3. Fallback evaluation
    $host_header = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $is_localhost = in_array(strtolower($host_header), ['localhost', '127.0.0.1', '::1']) || strpos(strtolower($host_header), 'localhost:') === 0;

    if ($is_localhost) {
        // Localhost development fallback
        log_email_record($to_email, $subject, 'Simulated (Localhost Logged)', $html_body);
        return [
            'success' => true,
            'transport' => 'log',
            'message' => 'Email recorded to local log (Localhost development mode).'
        ];
    }

    // Live Hosting: Do not fake success! Return descriptive error
    $err_desc = !empty($smtp_result['error'])
        ? $smtp_result['error']
        : 'PHP mail() function is disabled or blocked on this hosting server, and Live SMTP is not configured. Please open Admin -> Email Settings to enter your SMTP details.';

    log_email_record($to_email, $subject, 'FAILED: ' . $err_desc, $html_body);
    return [
        'success' => false,
        'transport' => 'none',
        'error' => $err_desc
    ];
}

/**
 * Socket-level SMTP client with TLS/SSL support and automatic port fallback.
 */
function send_via_smtp(string $to_email, array $headers, string $body, string $from_address = ''): array {
    $host = MAIL_SMTP_HOST;
    $port = (int)MAIL_SMTP_PORT;
    $enc  = strtolower(MAIL_SMTP_ENCRYPTION);
    $user = MAIL_SMTP_USER;
    $pass = MAIL_SMTP_PASS;

    if (empty($from_address)) {
        $from_address = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : $user;
    }

    $connect_host = ($enc === 'ssl' || $port == 465) ? "ssl://{$host}" : "tcp://{$host}";
    $timeout = 10;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($connect_host . ":{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    
    // Automatic fallback to port 465 SSL if port 587 TLS fails to connect (common on shared hostings)
    if (!$socket && ($port == 587 || $enc === 'tls')) {
        $connect_host = "ssl://{$host}";
        $port = 465;
        $enc = 'ssl';
        $socket = @stream_socket_client($connect_host . ":{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    }

    if (!$socket) {
        return ['success' => false, 'error' => "Cannot connect to SMTP {$host}:{$port} - {$errstr} ({$errno}). Hosting firewall may be blocking outbound SMTP ports. Try Port 465 with SSL."];
    }

    stream_set_timeout($socket, $timeout);

    $read = function() use ($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    };

    $write = function(string $command) use ($socket) {
        fputs($socket, $command . "\r\n");
    };

    $expect = function(int $expected_code, string $command = '') use ($write, $read) {
        if ($command !== '') $write($command);
        $res = $read();
        $code = (int)substr($res, 0, 3);
        if ($code !== $expected_code) {
            throw new \Exception("SMTP Error on '{$command}': Expected {$expected_code}, got {$res}");
        }
        return $res;
    };

    try {
        $greeting = $read();
        if ((int)substr($greeting, 0, 3) !== 220) {
            throw new \Exception("Invalid SMTP greeting: {$greeting}");
        }

        $server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $expect(250, "EHLO {$server_name}");

        // Handle STARTTLS on port 587
        if ($enc === 'tls' || ($port == 587 && $enc !== 'ssl')) {
            $expect(220, "STARTTLS");
            $crypto_ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto_ok) {
                throw new \Exception("STARTTLS encryption handshake failed.");
            }
            $expect(250, "EHLO {$server_name}");
        }

        // Authenticate
        if (!empty($user) && !empty($pass)) {
            $expect(334, "AUTH LOGIN");
            $expect(334, base64_encode($user));
            $expect(235, base64_encode($pass));
        }

        // Sender & Recipient
        $expect(250, "MAIL FROM:<{$from_address}>");
        $expect(250, "RCPT TO:<{$to_email}>");

        // Send Data
        $expect(354, "DATA");
        $full_message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $write($full_message);
        $expect(250, ".");

        $write("QUIT");
        fclose($socket);
        return ['success' => true];
    } catch (\Throwable $e) {
        if (is_resource($socket)) {
            @fputs($socket, "QUIT\r\n");
            @fclose($socket);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function log_email_record(string $to_email, string $subject, string $status, string $content = ''): void {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $log_file = $dir . '/email_log.txt';
    $entry = "[" . date('Y-m-d H:i:s') . "] TO: {$to_email} | STATUS: {$status} | SUBJECT: {$subject}\n";
    @file_put_contents($log_file, $entry, FILE_APPEND);
}

/**
 * Sends the Email Verification message containing the activation button.
 */
function send_verification_email(string $full_name, string $email, string $role, string $verification_link): array {
    $subject = "Activate Your Account — K.D. Polytechnic Asset Manager";
    $role_badge = ucfirst($role);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #bfdbfe; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
  .card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; margin-bottom: 28px; }
  .card-item { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px; }
  .card-item:last-child { margin-bottom: 0; }
  .card-label { color: #64748b; font-weight: 600; }
  .card-value { color: #0f172a; font-weight: 700; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 700; background: #dbeafe; color: #1e40af; }
  .btn-container { text-align: center; margin: 32px 0; }
  .btn { display: inline-block; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 14px 36px; font-size: 15px; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(37,99,235,0.35); }
  .btn:hover { background: #1d4ed8; }
  .fallback { font-size: 12px; color: #64748b; line-height: 1.5; background: #f8fafc; padding: 12px; border-radius: 8px; word-break: break-all; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>K.D. Polytechnic, Patan</h1>
      <p>Department Asset & Inventory Management Portal</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}! 👋</div>
      <p class="intro">
        You have been registered on the <strong>K.D. Polytechnic Asset Manager</strong>. To activate your account and receive your login password, please verify your email address by clicking the button below.
      </p>

      <div class="card">
        <div class="card-item"><span class="card-label">Registered Name:</span> <span class="card-value">{$full_name}</span></div>
        <div class="card-item"><span class="card-label">Email Address:</span> <span class="card-value">{$email}</span></div>
        <div class="card-item"><span class="card-label">Assigned Role:</span> <span class="badge">{$role_badge}</span></div>
      </div>

      <div class="btn-container">
        <a href="{$verification_link}" class="btn" target="_blank">✓ Verify Email &amp; Get Password</a>
      </div>

      <p style="font-size: 13px; color: #64748b; text-align: center; margin-bottom: 20px;">
        ⏳ <em>This verification link is valid for 24 hours.</em>
      </p>

      <div class="fallback">
        <strong>Button not working?</strong> Copy and paste this URL into your browser:<br>
        <a href="{$verification_link}" style="color: #2563eb;">{$verification_link}</a>
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      If you did not request this account, you can safely ignore this email.
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($email, $full_name, $subject, $html);
}

/**
 * Sends the Login Password & Credentials email after successful verification.
 */
function send_password_credentials_email(string $full_name, string $email, string $role, string $plain_password, string $login_url): array {
    $subject = "Your Login Password — K.D. Polytechnic Asset Manager";
    $role_badge = ucfirst($role);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #059669 0%, #10b981 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #a7f3d0; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
  .cred-box { background-color: #f0fdf4; border: 2px dashed #86efac; border-radius: 14px; padding: 20px; margin-bottom: 28px; text-align: center; }
  .cred-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #15803d; letter-spacing: 0.05em; margin-bottom: 6px; }
  .pwd-display { font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: 0.15em; background: #ffffff; padding: 10px 18px; border-radius: 8px; border: 1px solid #bbf7d0; display: inline-block; margin-top: 4px; }
  .cred-details { margin-top: 14px; font-size: 13px; color: #374151; }
  .btn-container { text-align: center; margin: 32px 0 20px; }
  .btn { display: inline-block; background: #059669; color: #ffffff !important; text-decoration: none; padding: 14px 38px; font-size: 15px; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(5,150,105,0.35); }
  .btn:hover { background: #047857; }
  .tips { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; font-size: 12px; color: #92400e; line-height: 1.5; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Email Verified Successfully! 🎉</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}!</div>
      <p class="intro">
        Your email address has been verified and your account is now ready for use. Below are your login credentials:
      </p>

      <div class="cred-box">
        <div class="cred-label">Your Auto-Generated Password</div>
        <div class="pwd-display">{$plain_password}</div>
        <div class="cred-details">
          <strong>Email:</strong> {$email} &bull; <strong>Role:</strong> {$role_badge}
        </div>
      </div>

      <div class="btn-container">
        <a href="{$login_url}" class="btn" target="_blank">→ Login to Asset Manager</a>
      </div>

      <div class="tips">
        💡 <strong>Security Advice:</strong> For your security, please change your password after your first login via the Profile &rarr; Change Password section.
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      Please do not share your password with anyone.
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($email, $full_name, $subject, $html);
}

/**
 * Sends a 6-digit OTP for the FORGOT PASSWORD flow (from login page).
 */
function send_forgot_password_otp_email(string $full_name, string $email, string $otp): array {
    $subject = "Password Reset OTP — K.D. Polytechnic Asset Manager";
    $formatted_otp = trim($otp);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #7c3aed 0%, #a78bfa 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #ede9fe; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
  .otp-box { background-color: #f5f3ff; border: 2px dashed #c4b5fd; border-radius: 14px; padding: 22px; margin-bottom: 24px; text-align: center; }
  .otp-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #6d28d9; letter-spacing: 0.08em; margin-bottom: 8px; }
  .otp-display { font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; font-size: 36px; font-weight: 800; color: #5b21b6; letter-spacing: 0.3em; background: #ffffff; padding: 14px 32px; border-radius: 10px; border: 1px solid #c4b5fd; display: inline-block; }
  .otp-validity { margin-top: 10px; font-size: 12px; color: #64748b; }
  .steps-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; font-size: 13px; color: #475569; line-height: 1.7; margin-bottom: 20px; }
  .warning-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #991b1b; line-height: 1.5; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>🔑 Password Reset Request</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}!</div>
      <p class="intro">
        We received a request to reset the password for your K.D. Polytechnic Asset Manager account. Enter the OTP below on the login page to proceed.
      </p>

      <div class="otp-box">
        <div class="otp-label">Your Password Reset OTP</div>
        <div class="otp-display">{$formatted_otp}</div>
        <div class="otp-validity">⏱ Valid for <strong>10 minutes</strong> only. Do NOT share this with anyone.</div>
      </div>

      <div class="steps-box">
        <strong>Next Steps:</strong><br>
        1. Go back to the login page.<br>
        2. Enter the OTP code above in the verification field.<br>
        3. Set your new password.
      </div>

      <div class="warning-box">
        ⚠️ <strong>Didn't request this?</strong> If you did not request a password reset, you can safely ignore this email. Your password will remain unchanged. If you are concerned, contact the system administrator immediately.
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      Automated Security Notification &bull; Do not reply to this email
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($email, $full_name, $subject, $html);
}

/**
 * Sends notification to a staff member when an audit is assigned to them.
 */
/**
 * Sends a 6-digit OTP email for password change authorization.
 */
function send_password_otp_email(string $full_name, string $email, string $otp): array {
    $subject = "Your Password Change OTP — K.D. Polytechnic Asset Manager";
    $formatted_otp = trim($otp);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #bfdbfe; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
  .otp-box { background-color: #eff6ff; border: 2px dashed #93c5fd; border-radius: 14px; padding: 22px; margin-bottom: 24px; text-align: center; }
  .otp-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #1d4ed8; letter-spacing: 0.08em; margin-bottom: 8px; }
  .otp-display { font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; font-size: 32px; font-weight: 800; color: #1e40af; letter-spacing: 0.25em; background: #ffffff; padding: 12px 28px; border-radius: 10px; border: 1px solid #bfdbfe; display: inline-block; }
  .otp-validity { margin-top: 10px; font-size: 12px; color: #64748b; }
  .warning-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #991b1b; line-height: 1.5; margin-top: 20px; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Password Change Verification 🔐</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}!</div>
      <p class="intro">
        A request has been made to change your password in K.D. Polytechnic Asset Manager. Use the One-Time Password (OTP) below to authenticate your request:
      </p>

      <div class="otp-box">
        <div class="otp-label">Your One-Time Password (OTP)</div>
        <div class="otp-display">{$formatted_otp}</div>
        <div class="otp-validity">⏱ Valid for 10 minutes only. Do NOT share this OTP with anyone.</div>
      </div>

      <div class="warning-box">
        ⚠️ <strong>Security Notice:</strong> If you did not initiate this password change request, someone else may have entered your password. Please contact the administrator immediately or secure your account.
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      Automated Security Notification &bull; Do not reply to this email
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($email, $full_name, $subject, $html);
}

/**
 * Sends notification to a staff member when an audit is assigned to them.
 */
/**
 * Sends a confirmation email after password is successfully changed.
 */
function send_password_changed_confirmation_email(string $full_name, string $email): array {
    $subject = "Security Alert: Password Changed — K.D. Polytechnic Asset Manager";
    $date_time = date('d M Y, h:i A');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 20px; }
  .info-box { background-color: #f0fdfa; border: 1px solid #99f6e4; border-radius: 10px; padding: 16px 20px; margin-bottom: 24px; font-size: 13px; color: #115e59; }
  .warning-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #991b1b; line-height: 1.5; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Password Changed Successfully 🛡️</h1>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}!</div>
      <p class="intro">
        This is a confirmation that your login password for K.D. Polytechnic Asset Manager was changed successfully.
      </p>

      <div class="info-box">
        <strong>Date &amp; Time:</strong> {$date_time}<br>
        <strong>Account Email:</strong> {$email}
      </div>

      <div class="warning-box">
        ⚠️ If you did not make this change, please contact the system administrator immediately to freeze or recover your account.
      </div>
    </div>
    <div class="footer">
      K.D. Polytechnic, Patan &bull; Gujarat Technical Education
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($email, $full_name, $subject, $html);
}

/**
 * Sends notification to a staff member when an audit is assigned to them.
 */
/**
 * Sends a verification email to the user's NEW requested email address.
 */
function send_email_change_verification_email(string $full_name, string $new_email, string $verify_url, string $role, bool $is_solo_admin): array {
    $subject = "Confirm Your New Email Address — K.D. Polytechnic Asset Manager";
    
    if ($role === 'admin' && $is_solo_admin) {
        $next_step_text = "As the sole Administrator, once you verify this email address by clicking below, your account email will be <strong>updated immediately</strong>.";
    } elseif ($role === 'admin') {
        $next_step_text = "Once you verify this email by clicking below, a change approval request will be forwarded to your co-administrator(s) for final confirmation.";
    } else {
        $next_step_text = "Once you verify this email by clicking below, your change request will be forwarded to the Administrator for approval.";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #e0e7ff; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 20px; }
  .email-box { background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; font-size: 14px; color: #1e293b; text-align: center; }
  .btn-container { text-align: center; margin: 28px 0; }
  .btn { display: inline-block; background: #4f46e5; color: #ffffff !important; text-decoration: none; padding: 14px 36px; font-size: 15px; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(79,70,229,0.35); }
  .note-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #166534; line-height: 1.5; margin-bottom: 20px; }
  .link-fallback { font-size: 12px; color: #64748b; word-break: break-all; margin-top: 16px; line-height: 1.5; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Verify Your New Email Address ✉️</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}!</div>
      <p class="intro">
        You requested to update your email address in K.D. Polytechnic Asset Manager to:
      </p>

      <div class="email-box">
        <strong>New Email:</strong> <span style="color:#4338ca;font-weight:700;">{$new_email}</span>
      </div>

      <div class="note-box">
        ℹ️ {$next_step_text}
      </div>

      <div class="btn-container">
        <a href="{$verify_url}" class="btn" target="_blank">✓ Verify This Email Address</a>
      </div>

      <div class="link-fallback">
        If the button does not work, copy and paste this link in your browser:<br>
        <a href="{$verify_url}" style="color:#4f46e5;">{$verify_url}</a><br>
        <small style="color:#94a3b8;">Link expires in 24 hours.</small>
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      If you did not request this email change, you can safely ignore this email.
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($new_email, $full_name, $subject, $html);
}

/**
 * Sends notification when an email change request is approved or rejected by an admin.
 */
function send_email_change_status_email(string $full_name, string $email, string $status, string $new_email, string $reason = ''): array {
    $is_approved = ($status === 'approved');
    $subject = $is_approved 
        ? "Email Change Approved — K.D. Polytechnic Asset Manager" 
        : "Email Change Request Update — K.D. Polytechnic Asset Manager";

    $status_color = $is_approved ? "#059669" : "#dc2626";
    $status_title = $is_approved ? "Email Change Approved ✅" : "Email Change Request Rejected ❌";
    
    $reason_html = '';
    if (!$is_approved && !empty($reason)) {
        $reason_html = "<div style='margin-top:12px;padding:12px;background:#fef2f2;border-radius:8px;font-size:13px;color:#991b1b;'><strong>Reason:</strong> " . htmlspecialchars($reason) . "</div>";
    }

    $message_body = $is_approved
        ? "<p>Your email change request has been <strong>approved</strong> by the administrator. Your account email is now updated to <strong>{$new_email}</strong>. Please use this new email to log in going forward.</p>"
        : "<p>Your email change request to <strong>{$new_email}</strong> was reviewed and <strong>rejected</strong> by the administrator.</p>" . $reason_html;

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: {$status_color}; padding: 30px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 20px; font-weight: 800; }
  .body { padding: 30px 26px; }
  .greeting { font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 18px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>{$status_title}</h1>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$full_name}!</div>
      {$message_body}
    </div>
    <div class="footer">
      K.D. Polytechnic, Patan &bull; Asset Manager
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($email, $full_name, $subject, $html);
}

/**
 * Sends notification to a staff member when an audit is assigned to them.
 */
function send_audit_assigned_email(string $staff_name, string $staff_email, string $location_name, string $assigned_by_name, string $audit_url): array {
    $subject = "New Audit Assigned: {$location_name} — K.D. Polytechnic Asset Manager";
    $assigned_date = date('d M Y, h:i A');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #bfdbfe; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
  .audit-card { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
  .audit-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; }
  .audit-row:last-child { border-bottom: none; }
  .audit-label { color: #64748b; font-weight: 600; }
  .audit-val { color: #0f172a; font-weight: 700; text-align: right; }
  .badge { background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-block; }
  .btn-container { text-align: center; margin: 28px 0; }
  .btn { display: inline-block; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 14px 34px; font-size: 15px; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(37,99,235,0.35); }
  .notice-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #1e40af; line-height: 1.5; margin-bottom: 20px; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>📋 Physical Audit Assigned</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$staff_name}!</div>
      <p class="intro">
        You have been assigned a physical asset verification audit by the department administrator. Please review the assignment details below and complete the audit at your earliest convenience:
      </p>

      <div class="audit-card">
        <div class="audit-row">
          <span class="audit-label">Target Location:</span>
          <span class="audit-val">📍 {$location_name}</span>
        </div>
        <div class="audit-row">
          <span class="audit-label">Assigned By:</span>
          <span class="audit-val">👤 {$assigned_by_name}</span>
        </div>
        <div class="audit-row">
          <span class="audit-label">Date Assigned:</span>
          <span class="audit-val">📅 {$assigned_date}</span>
        </div>
        <div class="audit-row">
          <span class="audit-label">Audit Status:</span>
          <span class="audit-val"><span class="badge">Assigned / Pending</span></span>
        </div>
      </div>

      <div class="notice-box">
        ℹ️ <strong>Instructions:</strong> Visit the designated location, inspect the assets, verify their condition (Present, Missing, Damaged), and submit the audit report through the portal.
      </div>

      <div class="btn-container">
        <a href="{$audit_url}" class="btn" target="_blank">→ Open Audit in Portal</a>
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      Automated Notification &bull; K.D. Polytechnic Asset Management System
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($staff_email, $staff_name, $subject, $html);
}

/**
 * Sends notification to all administrators when a new user is added/registered.
 */
function send_admin_new_user_alert_email(string $admin_name, string $admin_email, string $new_user_name, string $new_user_email, string $new_user_role, string $created_by_name, string $manage_url): array {
    $subject = "New User Added: {$new_user_name} ({$new_user_role}) — K.D. Polytechnic Asset Manager";
    $date_time = date('d M Y, h:i A');
    $role_badge = ucfirst($new_user_role);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 30px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 20px; font-weight: 800; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #94a3b8; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 22px; }
  .user-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
  .user-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; }
  .user-row:last-child { border-bottom: none; }
  .user-label { color: #64748b; font-weight: 600; }
  .user-val { color: #0f172a; font-weight: 700; text-align: right; }
  .badge { background: #dbeafe; color: #1e40af; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; }
  .btn-container { text-align: center; margin: 26px 0; }
  .btn { display: inline-block; background: #0f172a; color: #ffffff !important; text-decoration: none; padding: 13px 32px; font-size: 14px; font-weight: 700; border-radius: 10px; }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 18px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>👤 New User Account Created</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$admin_name}!</div>
      <p class="intro">
        A new user account was registered in the <strong>K.D. Polytechnic Asset Management System</strong>. Below are the details for your reference:
      </p>

      <div class="user-box">
        <div class="user-row">
          <span class="user-label">User Full Name:</span>
          <span class="user-val">{$new_user_name}</span>
        </div>
        <div class="user-row">
          <span class="user-label">Email Address:</span>
          <span class="user-val">{$new_user_email}</span>
        </div>
        <div class="user-row">
          <span class="user-label">Assigned Role:</span>
          <span class="user-val"><span class="badge">{$role_badge}</span></span>
        </div>
        <div class="user-row">
          <span class="user-label">Created By:</span>
          <span class="user-val">{$created_by_name}</span>
        </div>
        <div class="user-row">
          <span class="user-label">Created At:</span>
          <span class="user-val">{$date_time}</span>
        </div>
        <div class="user-row">
          <span class="user-label">Verification Status:</span>
          <span class="user-val" style="color:#d97706;">Pending Email Verification ✉️</span>
        </div>
      </div>

      <div class="btn-container">
        <a href="{$manage_url}" class="btn" target="_blank">Manage Users in Admin Panel</a>
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      Administrative Security Alert &bull; K.D. Polytechnic Asset Manager
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($admin_email, $admin_name, $subject, $html);
}

/**
 * Sends a monthly asset status summary report email (Missing, Under Maintenance, Not Working, Retired) to an administrator.
 */
function send_monthly_asset_report_email(string $admin_name, string $admin_email, array $report_data, string $dashboard_url): array {
    $month_name = $report_data['month_name'] ?? date('F Y');
    $subject = "Monthly Asset Report: {$month_name} — K.D. Polytechnic Asset Manager";

    $total_assets       = (int)($report_data['total_assets'] ?? 0);
    $active_assets      = (int)($report_data['active_assets'] ?? 0);
    $missing_assets     = (int)($report_data['missing_assets'] ?? 0);
    $maintenance_assets = (int)($report_data['maintenance_assets'] ?? 0);
    $not_working_assets = (int)($report_data['not_working_assets'] ?? 0);
    $retired_assets     = (int)($report_data['retired_assets'] ?? 0);

    // Build rows for flagged items
    $flagged_rows_html = '';
    $flagged_items = $report_data['flagged_items'] ?? [];
    if (!empty($flagged_items)) {
        foreach ($flagged_items as $item) {
            $name     = htmlspecialchars($item['asset_name'] ?? 'Unnamed');
            $location = htmlspecialchars($item['location'] ?? 'Not Assigned');
            $status   = htmlspecialchars($item['status'] ?? 'Unknown');
            $item_no  = htmlspecialchars($item['item_no'] ?? '-');

            $status_color = '#dc2626'; // Red for Missing / Not Working
            $status_bg    = '#fef2f2';
            if (stripos($status, 'maintenance') !== false) {
                $status_color = '#d97706'; // Amber for Under Maintenance
                $status_bg    = '#fffbeb';
            } elseif (stripos($status, 'retired') !== false) {
                $status_color = '#475569'; // Slate for Retired
                $status_bg    = '#f1f5f9';
            }

            $flagged_rows_html .= "
            <tr style='border-bottom: 1px solid #e2e8f0;'>
              <td style='padding: 10px 12px; font-size: 13px; font-weight: 600; color: #0f172a;'>
                {$name} <div style='font-size: 11px; color: #64748b; font-weight: normal;'>Item #{$item_no}</div>
              </td>
              <td style='padding: 10px 12px; font-size: 13px; color: #475569;'>📍 {$location}</td>
              <td style='padding: 10px 12px; font-size: 12px; text-align: right;'>
                <span style='background: {$status_bg}; color: {$status_color}; padding: 4px 9px; border-radius: 6px; font-weight: 700; display: inline-block;'>
                  {$status}
                </span>
              </td>
            </tr>";
        }
    } else {
        $flagged_rows_html = "
        <tr>
          <td colspan='3' style='padding: 16px; text-align: center; color: #059669; font-weight: 600; font-size: 13px;'>
            ✅ All assets are currently active and accounted for! No missing or damaged items recorded.
          </td>
        </tr>";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .wrapper { max-width: 650px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
  .header { background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
  .header p { margin: 6px 0 0; font-size: 13px; color: #bae6fd; font-weight: 500; }
  .body { padding: 32px 28px; }
  .greeting { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
  .intro { font-size: 14px; line-height: 1.6; color: #475569; margin-bottom: 24px; }
  
  .stats-grid { display: table; width: 100%; margin-bottom: 28px; border-collapse: separate; border-spacing: 8px; }
  .stat-row { display: table-row; }
  .stat-card { display: table-cell; width: 33.3%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 12px; text-align: center; vertical-align: middle; }
  .stat-val { font-size: 22px; font-weight: 800; margin-bottom: 4px; }
  .stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
  
  .table-container { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 24px; }
  .table-title { background: #f8fafc; padding: 12px 16px; font-size: 13px; font-weight: 700; color: #0f172a; border-bottom: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; text-align: left; }
  th { background: #f1f5f9; padding: 8px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; }
  
  .btn-container { text-align: center; margin: 28px 0; }
  .btn { display: inline-block; background: #0284c7; color: #ffffff !important; text-decoration: none; padding: 14px 34px; font-size: 14px; font-weight: 700; border-radius: 10px; box-shadow: 0 4px 14px rgba(2,132,199,0.3); }
  .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>📊 Monthly Asset Status Report</h1>
      <p>K.D. Polytechnic, Patan &bull; Asset Manager</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$admin_name}!</div>
      <p class="intro">
        Here is the monthly asset inventory and condition summary for <strong>{$month_name}</strong>. Please review the current health and status of institutional assets below:
      </p>

      <!-- Stats Grid -->
      <table style="width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 24px;">
        <tr>
          <td style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; text-align: center; width: 33%;">
            <div style="font-size: 24px; font-weight: 800; color: #0f172a;">{$total_assets}</div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b;">Total Assets</div>
          </td>
          <td style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px; text-align: center; width: 33%;">
            <div style="font-size: 24px; font-weight: 800; color: #166534;">{$active_assets}</div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #15803d;">Active &amp; OK</div>
          </td>
          <td style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px; text-align: center; width: 33%;">
            <div style="font-size: 24px; font-weight: 800; color: #dc2626;">{$missing_assets}</div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #b91c1c;">Missing</div>
          </td>
        </tr>
        <tr>
          <td style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px; text-align: center; width: 33%;">
            <div style="font-size: 24px; font-weight: 800; color: #d97706;">{$maintenance_assets}</div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #b45309;">In Maintenance</div>
          </td>
          <td style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; padding: 14px; text-align: center; width: 33%;">
            <div style="font-size: 24px; font-weight: 800; color: #ea580c;">{$not_working_assets}</div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #c2410c;">Not Working</div>
          </td>
          <td style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 10px; padding: 14px; text-align: center; width: 33%;">
            <div style="font-size: 24px; font-weight: 800; color: #475569;">{$retired_assets}</div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b;">Retired / Scrap</div>
          </td>
        </tr>
      </table>

      <!-- Attention Items Table -->
      <div class="table-container">
        <div class="table-title">⚠️ Flagged Assets Requiring Attention ({$month_name})</div>
        <table>
          <thead>
            <tr>
              <th>Asset / Item</th>
              <th>Location</th>
              <th style="text-align: right;">Status</th>
            </tr>
          </thead>
          <tbody>
            {$flagged_rows_html}
          </tbody>
        </table>
      </div>

      <div class="btn-container">
        <a href="{$dashboard_url}" class="btn" target="_blank">→ View Full Asset Inventory</a>
      </div>
    </div>
    <div class="footer">
      Government Polytechnic Patan &bull; Gujarat Technical Education<br>
      Automated Monthly System Report &bull; K.D. Polytechnic Asset Manager
    </div>
  </div>
</body>
</html>
HTML;

    return send_system_email($admin_email, $admin_name, $subject, $html);
}

