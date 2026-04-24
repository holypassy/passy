<?php
// api/invoices.php

require_once __DIR__ . '/../controllers/InvoiceController.php';

$controller = new InvoiceController();
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
                Response::json(['error' => 'Invoice ID required'], 400);
            }
            break;
        case 'create':
            $controller->create();
            break;
        case 'update':
            if ($id) {
                $controller->update($id);
            } else {
                Response::json(['error' => 'Invoice ID required'], 400);
            }
            break;
        case 'record-payment':
            if ($id) {
                $controller->recordPayment($id);
            } else {
                Response::json(['error' => 'Invoice ID required'], 400);
            }
            break;
        case 'delete':
            if ($id) {
                $controller->delete($id);
            } else {
                Response::json(['error' => 'Invoice ID required'], 400);
            }
            break;
        case 'stats':
            $controller->getStatistics();
            break;
        case 'generate-number':
            $controller->generateNumber();
            break;
        case 'quotations':
            $controller->getConvertibleQuotations();
            break;
        case 'convert':
            $controller->convertQuotation();
            break;
        default:
            Response::json(['error' => 'Endpoint not found'], 404);
    }
} catch (Exception $e) {
    Response::json(['error' => $e->getMessage()], 500);
}