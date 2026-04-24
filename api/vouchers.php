<?php
require_once __DIR__ . '/../controllers/VoucherController.php';

$controller = new VoucherController();
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
            Response::json(['error' => 'Voucher ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Voucher ID required'], 400);
        }
        break;
    case 'post':
        if ($id) {
            $controller->post($id);
        } else {
            Response::json(['error' => 'Voucher ID required'], 400);
        }
        break;
    case 'cancel':
        if ($id) {
            $controller->cancel($id);
        } else {
            Response::json(['error' => 'Voucher ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Voucher ID required'], 400);
        }
        break;
    case 'stats':
        $controller->getStatistics();
        break;
    case 'generate-number':
        $controller->generateNumber();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>