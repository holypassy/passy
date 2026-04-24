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
    $result = $requestModel->approve($input['request_id'], $_SESSION['user_id']);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Request approved successfully',
            'tool_id' => $result['tool_id'] ?? null
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>