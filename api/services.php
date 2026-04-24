<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../controllers/ServiceController.php';

$controller = new ServiceController();
$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['path']) ? explode('/', trim($_GET['path'], '/')) : [];

switch ($method) {
    case 'GET':
        if (isset($path[0]) && $path[0] === 'stats') {
            $controller->getStats();
        } elseif (isset($path[0])) {
            $controller->getById($path[0]);
        } else {
            $controller->getAll();
        }
        break;
        
    case 'POST':
        $controller->create();
        break;
        
    case 'PUT':
        if (isset($path[0])) {
            $controller->update($path[0]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
        }
        break;
        
    case 'DELETE':
        if (isset($path[0])) {
            $controller->delete($path[0]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}