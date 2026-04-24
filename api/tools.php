<?php
require_once __DIR__ . '/../controllers/ToolController.php';

$controller = new ToolController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    // Tool endpoints
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Tool ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Tool ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Tool ID required'], 400);
        }
        break;
    
    // Assignment endpoints
    case 'assign':
        $controller->assignTool();
        break;
    case 'return':
        $controller->returnTool();
        break;
    case 'active-assignments':
        $controller->getActiveAssignments();
        break;
    
    // Maintenance endpoints
    case 'schedule-maintenance':
        $controller->scheduleMaintenance();
        break;
    case 'record-maintenance':
        $controller->recordMaintenance();
        break;
    case 'maintenance-records':
        $controller->getMaintenanceRecords();
        break;
    
    // Statistics endpoints
    case 'stats':
        $controller->getStatistics();
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>