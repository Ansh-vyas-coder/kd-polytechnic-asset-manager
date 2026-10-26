<?php
// index.php — Entry point: route to dashboard if already logged in, otherwise to login page
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
} else {
    header("Location: login.html");
    exit();
}
