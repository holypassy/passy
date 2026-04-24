<?php
require_once __DIR__ . '/../controllers/NewJobController.php';

$controller = new NewJobController();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'form-data':
        $controller->getFormData();
        break;
    case 'create':
        $controller->createJob();
        break;
    case 'template':
        $controller->getJobTemplate();
        break;
    case 'add-customer':
        $controller->addCustomer();
        break;
    case 'inspection-items':
        $controller->getInspectionItems();
        break;
    case 'work-item-template':
        $controller->getWorkItemTemplate();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>