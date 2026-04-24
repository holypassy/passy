<?php
require_once __DIR__ . '/../controllers/JobCardController.php';

$controller = new JobCardController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$jobCardId = isset($_GET['job_card_id']) ? (int)$_GET['job_card_id'] : null;

switch ($action) {
    // Job Card endpoints
    case 'list':
        $controller->getAll();
        break;
    case 'get':
        if ($id) {
            $controller->getOne($id);
        } else {
            Response::json(['error' => 'Job card ID required'], 400);
        }
        break;
    case 'create':
        $controller->create();
        break;
    case 'update':
        if ($id) {
            $controller->update($id);
        } else {
            Response::json(['error' => 'Job card ID required'], 400);
        }
        break;
    case 'update-status':
        if ($id) {
            $controller->updateStatus($id);
        } else {
            Response::json(['error' => 'Job card ID required'], 400);
        }
        break;
    case 'delete':
        if ($id) {
            $controller->delete($id);
        } else {
            Response::json(['error' => 'Job card ID required'], 400);
        }
        break;
    
    // Job Item endpoints
    case 'items':
        if ($jobCardId) {
            $controller->getJobItems($jobCardId);
        } else {
            Response::json(['error' => 'Job card ID required'], 400);
        }
        break;
    case 'add-item':
        $controller->addJobItem();
        break;
    case 'update-item':
        if ($id) {
            $controller->updateJobItem($id);
        } else {
            Response::json(['error' => 'Item ID required'], 400);
        }
        break;
    case 'delete-item':
        if ($id) {
            $controller->deleteJobItem($id);
        } else {
            Response::json(['error' => 'Item ID required'], 400);
        }
        break;
    
    // Statistics endpoints
    case 'stats':
        $controller->getStatistics();
        break;
    case 'technician-jobs':
        if ($id) {
            $controller->getTechnicianJobs($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>