<?php
require_once __DIR__ . '/../controllers/ToolInventoryController.php';

$controller = new ToolInventoryController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    case 'list':
        $controller->getInventory();
        break;
    case 'get':
        if ($id) {
            $controller->getTool($id);
        } else {
            Response::json(['error' => 'Tool ID required'], 400);
        }
        break;
    case 'add':
        $controller->addTool();
        break;
    case 'update':
        if ($id) {
            $controller->updateTool($id);
        } else {
            Response::json(['error' => 'Tool ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->deleteTool($id);
        } else {
            Response::json(['error' => 'Tool ID required'], 400);
        }
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    case 'categories':
        $controller->getCategories();
        break;
    case 'maintenance':
        $controller->getMaintenanceNeeded();
        break;
    case 'recent':
        $controller->getRecentTools();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>