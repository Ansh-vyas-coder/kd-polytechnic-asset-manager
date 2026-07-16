<?php
session_start(); // Access the existing session.

// Unset all of the session variables.
$_SESSION = array();

// Destroy the session.
session_destroy();

// Redirect to the login page.
header("location: login.html");
exit;