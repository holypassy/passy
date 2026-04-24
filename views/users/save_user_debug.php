<?php
// save_user_debug.php - Debug version to test saving
session_start();
header('Content-Type: application/json');

// Log all POST data for debugging
error_log("POST data: " . print_r($_POST, true));

// Simple test response
echo json_encode([
    'success' => true,
    'message' => 'Debug: Form received successfully',
    'post_data' => $_POST
]);
exit();
?>