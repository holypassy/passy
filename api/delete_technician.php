<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Technician.php';
require_once __DIR__ . '/../models/ToolAssignment.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['technician_id'])) {
    echo json_encode(['success' => false, 'message' => 'Technician ID required']);
    exit();
}

try {
    $technicianModel = new Technician();
    $assignmentModel = new ToolAssignment();
    
    // Check for active assignments
    $activeAssignments = $assignmentModel->getByTechnicianId($input['technician_id']);
    if (!empty($activeAssignments)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete technician with active tool assignments. Please return all tools first.'
        ]);
        exit();
    }
    
    $result = $technicianModel->delete($input['technician_id']);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Technician deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete technician']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>