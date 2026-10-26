<?php
// mail-config.sample.php — Email Configuration for Hosting
// Instructions: Copy this file to `mail-config.php` if you want custom SMTP.
//
// AAPKE PAAS 3 OPTIONS HAIN:
//
// OPTION 1: HOSTING KA APNA EMAIL (cPanel / Webmail) — [RECOMMENDED FOR COLLEGE DOMAIN]
// Agar aapne hosting par email banaya hai (jaise admin@yourdomain.com):
//   MAIL_SMTP_ENABLED    = true
//   MAIL_SMTP_HOST       = 'mail.yourdomain.com' (ya hosting ka mail server hostname)
//   MAIL_SMTP_PORT       = 465 (SSL) ya 587 (TLS)
//   MAIL_SMTP_ENCRYPTION = 'ssl' (port 465 ke liye) ya 'tls' (port 587 ke liye)
//   MAIL_SMTP_USER       = 'admin@yourdomain.com'
//   MAIL_SMTP_PASS       = 'your_cpanel_email_password'
//
// OPTION 2: GMAIL YA GOOGLE WORKSPACE SMTP
// Agar aap Gmail use karna chahte hain:
//   MAIL_SMTP_ENABLED    = true
//   MAIL_SMTP_HOST       = 'smtp.gmail.com'
//   MAIL_SMTP_PORT       = 587
//   MAIL_SMTP_ENCRYPTION = 'tls'
//   MAIL_SMTP_USER       = 'yourcollege@gmail.com'
//   MAIL_SMTP_PASS       = '16_char_google_app_password'
//
// OPTION 3: DEFAULT HOSTING SERVER (Bina kisi password ke)
// Agar aapko koi SMTP setup nahi karna hai, toh MAIL_SMTP_ENABLED ko false rakhein.
// System automatically hosting ke built-in PHP mail() function se email bhej dega!

if (!defined('MAIL_SMTP_ENABLED')) {
    define('MAIL_SMTP_ENABLED', false); // true = SMTP se bhejna; false = hosting ke built-in mail() se bhejna
}
if (!defined('MAIL_SMTP_HOST')) {
    define('MAIL_SMTP_HOST', 'mail.yourdomain.com');
}
if (!defined('MAIL_SMTP_PORT')) {
    define('MAIL_SMTP_PORT', 587); // 587 for TLS, 465 for SSL, 25 for plain
}
if (!defined('MAIL_SMTP_ENCRYPTION')) {
    define('MAIL_SMTP_ENCRYPTION', 'tls'); // 'tls', 'ssl', or 'none'
}
if (!defined('MAIL_SMTP_USER')) {
    define('MAIL_SMTP_USER', 'noreply@yourdomain.com');
}
if (!defined('MAIL_SMTP_PASS')) {
    define('MAIL_SMTP_PASS', 'your_email_password');
}
if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', 'noreply@kdpolytechnic.ac.in');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'K.D. Polytechnic Asset Manager');
}
