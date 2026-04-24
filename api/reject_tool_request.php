<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ToolRequest.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['request_id'])) {
    echo json_encode(['success' => false, 'message' => 'Request ID required']);
    exit();
}

try {
    $requestModel = new ToolRequest();
    $result = $requestModel->reject($input['request_id'], $_SESSION['user_id'], $input['reason'] ?? null);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Request rejected']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reject request']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>