<?php
require_once __DIR__ . '/../controllers/UserController.php';

$controller = new UserController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    // User management endpoints
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'User ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'User ID required'], 400);
        }
        break;
    case 'update-status':
        if ($id) {
            $controller->updateStatus($id);
        } else {
            Response::json(['error' => 'User ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'User ID required'], 400);
        }
        break;
    
    // Profile endpoints
    case 'profile':
        $controller->getProfile();
        break;
    case 'update-profile':
        $controller->updateProfile();
        break;
    
    // Password reset endpoints
    case 'forgot-password':
        $controller->forgotPassword();
        break;
    case 'reset-password':
        $controller->resetPassword();
        break;
    
    // Statistics endpoints
    case 'stats':
        $controller->getStatistics();
        break;
    case 'roles':
        $controller->getRoles();
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>