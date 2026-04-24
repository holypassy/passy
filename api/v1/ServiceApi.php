<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../models/Service.php';

class ServiceApi {
    private $serviceModel;
    
    public function __construct() {
        $db = Database::getInstance()->getConnection();
        $this->serviceModel = new Service($db);
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = explode('/', trim($_GET['path'] ?? '', '/'));
        $id = $path[0] ?? null;
        
        switch($method) {
            case 'GET':
                if ($id) {
                    $this->getService($id);
                } else {
                    $this->getAllServices();
                }
                break;
            case 'POST':
                $this->createService();
                break;
            case 'PUT':
                if ($id) {
                    $this->updateService($id);
                } else {
                    $this->json(['error' => 'Service ID required'], 400);
                }
                break;
            case 'DELETE':
                if ($id) {
                    $this->deleteService($id);
                } else {
                    $this->json(['error' => 'Service ID required'], 400);
                }
                break;
            default:
                $this->json(['error' => 'Method not allowed'], 405);
        }
    }
    
    private function getAllServices() {
        $services = $this->serviceModel->getAllActive();
        $this->json($services);
    }
    
    private function getService($id) {
        $service = $this->serviceModel->getById($id);
        if ($service) {
            $this->json($service);
        } else {
            $this->json(['error' => 'Service not found'], 404);
        }
    }
    
    private function createService() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $this->json(['error' => 'Invalid input'], 400);
            return;
        }
        
        $result = $this->serviceModel->create($input);
        if ($result) {
            $this->json(['message' => 'Service created successfully', 'id' => $result], 201);
        } else {
            $this->json(['error' => 'Failed to create service'], 500);
        }
    }
    
    private function updateService($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $this->json(['error' => 'Invalid input'], 400);
            return;
        }
        
        $result = $this->serviceModel->update($id, $input);
        if ($result) {
            $this->json(['message' => 'Service updated successfully']);
        } else {
            $this->json(['error' => 'Failed to update service'], 500);
        }
    }
    
    private function deleteService($id) {
        $result = $this->serviceModel->delete($id);
        if ($result) {
            $this->json(['message' => 'Service deleted successfully']);
        } else {
            $this->json(['error' => 'Failed to delete service'], 500);
        }
    }
    
    private function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }
}

// Handle the request
$api = new ServiceApi();
$api->handleRequest();
?>