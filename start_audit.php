<?php
session_start();
require 'db.php';

// Security check: ensure user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Redirect to login or show an error
    header("Location: login.html");
    exit();
}

// Check if the request is a POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate that location_id is provided
    if (!isset($_POST['location_id']) || empty(trim($_POST['location_id']))) {
        // Handle error: location not provided
        // Assuming the form is on a page like dashboard.php?view=audit
        header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Please select a location to start an audit."));
        exit();
    }

    $location_id = trim($_POST['location_id']);
    $audited_by_user_id = $_SESSION['user_id'];

    // Check if an audit for this location is already in progress
    $check_stmt = $conn->prepare("SELECT id FROM audits WHERE location_id = ? AND status = 'In Progress' LIMIT 1");
    $check_stmt->bind_param("s", $location_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $existing_audit = $check_result->fetch_assoc();
        $check_stmt->close();
        // An audit is already in progress for this location, so redirect to it
        header("Location: audit_session.php?id=" . $existing_audit['id']);
        exit();
    }
    $check_stmt->close();

    // Create a new audit record in the 'audits' table
    $stmt = $conn->prepare("INSERT INTO audits (location_id, audited_by_user_id, status) VALUES (?, ?, 'In Progress')");
    
    if (!$stmt) {
        // Handle statement preparation error
        header("Location: dashboard.php?view=audit&status=error&message=" . urlencode("Database error: " . $conn->error));
        exit();
    }

    $stmt->bind_param("si", $location_id, $audited_by_user_id);

    if ($stmt->execute()) {
        // Get the ID of the newly created audit
        $audit_id = $conn->insert_id;
        $stmt->close();

        // Redirect to the audit session page
        header("Location: audit_session.php?id=" . $audit_id);
        exit();
    } else {
        // Handle execution error
        $error_message = urlencode($stmt->error);
        $stmt->close();
        header("Location: dashboard.php?view=audit&status=error&message=" . $error_message);
        exit();
    }
} else {
    // If not a POST request, redirect to the dashboard or an appropriate page
    header("Location: dashboard.php");
    exit();
}
?>