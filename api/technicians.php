<?php
require_once __DIR__ . '/../controllers/TechnicianController.php';

$controller = new TechnicianController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$technicianId = isset($_GET['technician_id']) ? (int)$_GET['technician_id'] : null;

switch ($action) {
    // Technician endpoints
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    case 'block':
        if ($id) {
            $controller->block($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    case 'unblock':
        if ($id) {
            $controller->unblock($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    
    // Statistics endpoints
    case 'stats':
        $controller->getStatistics();
        break;
    case 'departments':
        $controller->getDepartments();
        break;
    case 'active':
        $controller->getActiveTechnicians();
        break;
    
    // Attendance endpoints
    case 'attendance':
        if ($technicianId) {
            $controller->getAttendance($technicianId);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    case 'record-attendance':
        $controller->recordAttendance();
        break;
    
    // Tool assignment endpoints
    case 'tools':
        if ($technicianId) {
            $controller->getToolAssignments($technicianId);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>