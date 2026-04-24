<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ToolRequest.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['technician_id'])) {
    echo json_encode(['success' => false, 'message' => 'Technician ID required']);
    exit();
}

if (empty($input['quantity']) || $input['quantity'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid quantity required']);
    exit();
}

if (empty($input['expected_duration_days']) || $input['expected_duration_days'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid expected duration required']);
    exit();
}

if (empty($input['reason']) || strlen($input['reason']) < 10) {
    echo json_encode(['success' => false, 'message' => 'Please provide a detailed reason (minimum 10 characters)']);
    exit();
}

// Validate tool selection
if (empty($input['tool_id']) && empty($input['tool_name_requested'])) {
    echo json_encode(['success' => false, 'message' => 'Please select a tool or describe the tool needed']);
    exit();
}

try {
    $requestModel = new ToolRequest();
    
    $data = [
        'technician_id' => $input['technician_id'],
        'tool_id' => $input['tool_id'] ?? null,
        'tool_name_requested' => $input['tool_name_requested'] ?? null,
        'quantity' => $input['quantity'],
        'expected_duration_days' => $input['expected_duration_days'],
        'urgency' => $input['urgency'] ?? 'normal',
        'job_card_id' => $input['job_card_id'] ?? null,
        'reason' => $input['reason'],
        'instructions' => $input['instructions'] ?? null,
        'requested_by' => $_SESSION['user_id']
    ];
    
    $result = $requestModel->create($data);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Tool request submitted successfully',
            'request_number' => $data['request_number'] ?? null
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit request']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>