<?php
/**
 * Retrieves a user's ID based on their full name.
 *
 * @param mysqli $conn The database connection object.
 * @param string $name The full name of the user.
 * @return int|null The user ID if found, otherwise null.
 */
function get_user_id_by_name($conn, $name) {
    if (empty($name)) {
        return null;
    }
    
    $sql = "SELECT id FROM users WHERE full_name = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("s", $name);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return (int)$row['id'];
        }
    }
    
    $stmt->close();
    return null;
}
?>
