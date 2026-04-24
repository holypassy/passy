<?php
require_once __DIR__ . '/../controllers/DashboardController.php';

$controller = new DashboardController();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'stats':
        $controller->getStats();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
}
?>