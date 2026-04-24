<?php
require_once __DIR__ . '/../controllers/BankAccountController.php';

$controller = new BankAccountController();
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
            Response::json(['error' => 'Account ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Account ID required'], 400);
        }
        break;
    case 'update-balance':
        if ($id) {
            $controller->updateBalance($id);
        } else {
            Response::json(['error' => 'Account ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Account ID required'], 400);
        }
        break;
    case 'totals':
        $controller->getTotals();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>