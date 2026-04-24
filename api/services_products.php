<?php
require_once __DIR__ . '/../controllers/ServiceProductController.php';

$controller = new ServiceProductController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    // Service endpoints
    case 'services':
        $controller->getServices();
        break;
    case 'service':
        if ($id) {
            $controller->getService($id);
        } else {
            Response::json(['error' => 'Service ID required'], 400);
        }
        break;
    case 'create-service':
        $controller->createService();
        break;
    case 'update-service':
        if ($id) {
            $controller->updateService($id);
        } else {
            Response::json(['error' => 'Service ID required'], 400);
        }
        break;
    case 'delete-service':
        if ($id) {
            $controller->deleteService($id);
        } else {
            Response::json(['error' => 'Service ID required'], 400);
        }
        break;
    
    // Product endpoints
    case 'products':
        $controller->getProducts();
        break;
    case 'product':
        if ($id) {
            $controller->getProduct($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    case 'create-product':
        $controller->createProduct();
        break;
    case 'update-product':
        if ($id) {
            $controller->updateProduct($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    case 'update-stock':
        if ($id) {
            $controller->updateProductStock($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    case 'delete-product':
        if ($id) {
            $controller->deleteProduct($id);
        } else {
            Response::json(['error' => 'Product ID required'], 400);
        }
        break;
    
    // Combined endpoints
    case 'all':
        $controller->getAllItems();
        break;
    case 'categories':
        $controller->getCategories();
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>