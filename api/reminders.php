<?php
require_once __DIR__ . '/../controllers/ReminderController.php';

$controller = new ReminderController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    case 'list':
        $controller->getAll();
        break;
    case 'pending':
        $controller->getPending();
        break;
    case 'today':
        $controller->getToday();
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
    case 'create-from-service':
        $controller->createFromService();
        break;
    case 'send':
        if ($id) {
            $controller->sendNow($id);
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
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>