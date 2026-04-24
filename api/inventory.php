<?php
require_once __DIR__ . '/../controllers/InventoryController.php';

$controller = new InventoryController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    // Product endpoints
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    case 'adjust-stock':
        if ($id) {
            $controller->adjustStock($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    
    // Supplier endpoints
    case 'suppliers':
        $controller->getSuppliers();
        break;
    case 'create-supplier':
        $controller->createSupplier();
        break;
    
    // Transaction endpoints
    case 'transactions':
        $controller->getTransactions();
        break;
    
    // Report endpoints
    case 'low-stock':
        $controller->getLowStock();
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>