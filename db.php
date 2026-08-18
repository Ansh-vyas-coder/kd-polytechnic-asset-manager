<?php
// db.php - Database Connection File

$host = "localhost";
$port = 3306;
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

function get_admin_user_ids($db_conn) {
    $ids = [];
    $result = $db_conn->query("SELECT id FROM users WHERE role = 'admin'");
    if (!$result) {
        error_log("Failed to fetch admin users: (" . $db_conn->errno . ") " . $db_conn->error);
        return $ids;
    }

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $result->free();

    return $ids;
}

/**
 * Creates a notification for a specific user. Pass user_id 0 to notify all admins.
 */
function create_notification($db_conn, $user_id, $message, $link = null) {
    if ((int)$user_id === 0) {
        return create_admin_notification($db_conn, $message, $link);
    }

    $stmt = $db_conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: (" . $db_conn->errno . ") " . $db_conn->error);
        return false;
    }

    $user_id = (int)$user_id;
    $stmt->bind_param("iss", $user_id, $message, $link);
    $success = $stmt->execute();
    if (!$success) {
        error_log("Failed to create notification: (" . $stmt->errno . ") " . $stmt->error);
    }
    $stmt->close();

    return $success;
}

function create_admin_notification($db_conn, $message, $link = null, $exclude_user_id = null) {
    $admin_ids = get_admin_user_ids($db_conn);
    if (empty($admin_ids)) {
        return true;
    }

    $stmt = $db_conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: (" . $db_conn->errno . ") " . $db_conn->error);
        return false;
    }

    $all_successful = true;
    foreach ($admin_ids as $admin_id) {
        if ($exclude_user_id !== null && (int)$admin_id === (int)$exclude_user_id) {
            continue;
        }

        $stmt->bind_param("iss", $admin_id, $message, $link);
        if (!$stmt->execute()) {
            $all_successful = false;
            error_log("Failed to create notification for admin {$admin_id}: (" . $stmt->errno . ") " . $stmt->error);
        }
    }
    $stmt->close();

    return $all_successful;
}
?>
