<?php
// db-config.sample.php — Production Database Configuration
// Instructions: Copy this file to `db-config.php` and set your hosting database details.
// Note: `db-config.php` is ignored by Git or kept private to protect your credentials.

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_USER')) define('DB_USER', 'your_db_user');
if (!defined('DB_PASS')) define('DB_PASS', 'your_db_password');
if (!defined('DB_NAME')) define('DB_NAME', 'your_db_name');
