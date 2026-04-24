<?php
// api/quotations.php

require_once __DIR__ . '/../controllers/QuotationController.php';

$controller = new QuotationController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    switch ($action) {
        case 'list':
            $controller->getAll();
            break;
        case 'get':
            if ($id) {
                $controller->getOne($id);
            } else {
                Response::json(['error' => 'Quotation ID required'], 400);
            }
            break;
        case 'create':
            $controller->create();
            break;
        case 'update':
            if ($id) {
                $controller->update($id);
            } else {
                Response::json(['error' => 'Quotation ID required'], 400);
            }
            break;
        case 'update-status':
            if ($id) {
                $controller->updateStatus($id);
            } else {
                Response::json(['error' => 'Quotation ID required'], 400);
            }
            break;
        case 'delete':
            if ($id) {
                $controller->delete($id);
            } else {
                Response::json(['error' => 'Quotation ID required'], 400);
            }
            break;
        case 'stats':
            $controller->getStatistics();
            break;
        case 'generate-number':
            $controller->generateNumber();
            break;
        case 'convertible':
            // New action for quotations that can be converted (used by invoice conversion list)
            $controller->getConvertible();
            break;
        default:
            Response::json(['error' => 'Endpoint not found'], 404);
    }
} catch (Exception $e) {
    Response::json(['error' => $e->getMessage()], 500);
}