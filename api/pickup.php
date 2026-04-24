<?php
require_once __DIR__ . '/../controllers/PickupController.php';

$controller = new PickupController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Reminder ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Reminder ID required'], 400);
        }
        break;
    case 'update-status':
        if ($id) {
            $controller->updateStatus($id);
        } else {
            Response::json(['error' => 'Reminder ID required'], 400);
        }
        break;
    case 'send':
        if ($id) {
            $controller->sendReminder($id);
        } else {
            Response::json(['error' => 'Reminder ID required'], 400);
        }
        break;
    case 'process-daily':
        $controller->processDailyReminders();
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Reminder ID required'], 400);
        }
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    case 'due':
        $controller->getDuePickups();
        break;
    case 'my-pickups':
        $controller->getMyPickups();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>