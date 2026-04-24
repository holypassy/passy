<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../controllers/AuthController.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$controller = new AuthController($db);
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $controller->login();
        break;
    case 'logout':
        $controller->logout();
        break;
    case 'check':
        $controller->checkAuth();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
}
?>