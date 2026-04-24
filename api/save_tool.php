<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Tool.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['tool_name'])) {
    echo json_encode(['success' => false, 'message' => 'Tool name is required']);
    exit();
}

try {
    $toolModel = new Tool();
    
    // Generate tool code if not provided
    if (empty($input['tool_code'])) {
        $input['tool_code'] = $toolModel->generateToolCode($input['tool_name']);
    }
    
    $result = $toolModel->create($input);
    
    if ($result) {
        $toolId = $toolModel->conn->lastInsertId();
        $tool = $toolModel->findById($toolId);
        $stats = $toolModel->getStatistics();
        
        echo json_encode([
            'success' => true,
            'message' => 'Tool added successfully',
            'tool' => $tool,
            'statistics' => $stats
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add tool']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>