<?php
require_once __DIR__ . '/../controllers/ToolRequestController.php';

$controller = new ToolRequestController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    case 'list':
        $controller->getAll();
        break;
    case 'pending':
        $controller->getPending();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Request ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'approve':
        if ($id) {
            $controller->approve($id);
        } else {
            Response::json(['error' => 'Request ID required'], 400);
        }
        break;
    case 'reject':
        if ($id) {
            $controller->reject($id);
        } else {
            Response::json(['error' => 'Request ID required'], 400);
        }
        break;
    case 'cancel':
        if ($id) {
            $controller->cancel($id);
        } else {
            Response::json(['error' => 'Request ID required'], 400);
        }
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    case 'my-requests':
        $controller->getMyRequests();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>