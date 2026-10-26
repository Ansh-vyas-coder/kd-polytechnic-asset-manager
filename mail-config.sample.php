<?php
// mail-config.sample.php — Sample Email Configuration for Hosting
// Copy this file to `mail-config.php` if needed.
//
// DEFAULT MODE: DIRECT HOSTING SERVER (Bina kisi SMTP credentials ke)
// System hosting ke direct mail server (PHP mail() / Sendmail) se emails deliver karta hai.

if (!defined('MAIL_SMTP_ENABLED')) {
    define('MAIL_SMTP_ENABLED', false); // false = Hosting Mail Server; true = External SMTP
}

if (!defined('MAIL_FROM_ADDRESS')) {
    $host_domain = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if (strpos($host_domain, ':') !== false) {
        $host_domain = explode(':', $host_domain)[0];
    }
    $host_domain = preg_replace('/^www\./i', '', trim($host_domain));
    if (empty($host_domain) || in_array(strtolower($host_domain), ['localhost', '127.0.0.1', '::1'])) {
        $host_domain = 'kdpolytechnic.ac.in';
    }
    define('MAIL_FROM_ADDRESS', 'noreply@' . strtolower($host_domain));
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'K.D. Polytechnic Asset Manager');
}

// OPTIONAL: External SMTP Settings (Only active if MAIL_SMTP_ENABLED is true)
if (!defined('MAIL_SMTP_HOST')) {
    define('MAIL_SMTP_HOST', 'mail.yourdomain.com');
}
if (!defined('MAIL_SMTP_PORT')) {
    define('MAIL_SMTP_PORT', 587);
}
if (!defined('MAIL_SMTP_ENCRYPTION')) {
    define('MAIL_SMTP_ENCRYPTION', 'tls');
}
if (!defined('MAIL_SMTP_USER')) {
    define('MAIL_SMTP_USER', '');
}
if (!defined('MAIL_SMTP_PASS')) {
    define('MAIL_SMTP_PASS', '');
}
