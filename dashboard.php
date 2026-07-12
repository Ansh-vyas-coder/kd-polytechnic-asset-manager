<?php
session_start();

// If they are not logged in, kick them back to the login page
if(!isset($_SESSION['user_id'])){
    header("Location: login.html");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
</head>
<body style="font-family: sans-serif; text-align: center; margin-top: 50px;">
    <h1>Welcome to the Secure Asset Manager!</h1>
    <h2>Hello, <?php echo $_SESSION['user_name']; ?></h2>
    <p>Your Role: <strong><?php echo $_SESSION['role']; ?></strong></p>
</body>
</html>