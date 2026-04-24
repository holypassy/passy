<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}
$user_role = $_SESSION['role'] ?? 'user';
$user_full_name = $_SESSION['full_name'] ?? 'User';
// ... (include CSS, sidebar, top bar, etc.)
?>
<!DOCTYPE html>
<html>
<head>
    <!-- common head content -->
</head>
<body>
    <div class="sidebar">
        <!-- same sidebar as before -->
    </div>
    <div class="main-content">
        <div class="top-bar">
            <!-- welcome and user info -->
        </div>
        <!-- PAGE CONTENT WILL BE INSERTED HERE -->