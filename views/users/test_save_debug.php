<?php
// test_save_debug.php - Test if save_user.php is accessible
session_start();
header('Content-Type: application/json');

// Log that we received the request
error_log("Test endpoint was called");

echo json_encode([
    'success' => true,
    'message' => 'Test endpoint working',
    'post_data' => $_POST,
    'session' => $_SESSION
]);
?>