<?php
require_once __DIR__ . '/../controllers/CustomerController.php';

$controller = new CustomerController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;

switch ($action) {
    // Customer endpoints
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Customer ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Customer ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Customer ID required'], 400);
        }
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    
    // Interaction endpoints
    case 'interactions':
        $controller->getInteractions($customerId);
        break;
    case 'create-interaction':
        $controller->createInteraction();
        break;
    
    // Communication endpoints
    case 'communications':
        $controller->getCommunications($customerId);
        break;
    case 'create-communication':
        $controller->createCommunication();
        break;
    case 'update-communication':
        if ($id) {
            $controller->updateCommunicationStatus($id);
        } else {
            Response::json(['error' => 'Communication ID required'], 400);
        }
        break;
    
    // Feedback endpoints
    case 'feedback':
        $controller->getFeedback($customerId);
        break;
    case 'create-feedback':
        $controller->createFeedback();
        break;
    
    // Loyalty endpoints
    case 'update-loyalty':
        if ($id) {
            $controller->updateLoyalty($id);
        } else {
            Response::json(['error' => 'Customer ID required'], 400);
        }
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>