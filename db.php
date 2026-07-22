<?php
// db.php - Database Connection File

$host = "localhost";
$port = 3307;
$username = "root"; // Default XAMPP username
$password = "";     // Default XAMPP password (blank)
$database = "smart_asset_manager";

// Create the connection
$conn = new mysqli($host, $username, $password, $database, $port);
// Check if the connection works
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// If the code makes it down here, it means it connected successfully! 
?>