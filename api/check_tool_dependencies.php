<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ToolAssignment.php';
require_once __DIR__ . '/../models/ToolMaintenance.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$toolId = isset($_GET['tool_id']) ? (int)$_GET['tool_id'] : 0;

if (!$toolId) {
    echo json_encode(['success' => false, 'message' => 'Tool ID required']);
    exit();
}

try {
    $assignmentModel = new ToolAssignment();
    $maintenanceModel = new ToolMaintenance();
    
    $activeAssignments = $assignmentModel->getActiveAssignments($toolId);
    $totalAssignments = $assignmentModel->getByToolId($toolId);
    $maintenanceRecords = $maintenanceModel->getByToolId($toolId);
    
    echo json_encode([
        'success' => true,
        'has_active_assignments' => !empty($activeAssignments),
        'active_assignments' => $activeAssignments,
        'total_assignments' => count($totalAssignments),
        'maintenance_records' => count($maintenanceRecords)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>