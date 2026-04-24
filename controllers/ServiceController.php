<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../helpers/Validator.php';

class ServiceController extends Controller {
    private $serviceModel;
    
    public function __construct() {
        parent::__construct();
        $this->serviceModel = new Service();
    }
    
    public function getAll() {
        $services = $this->serviceModel->findAll();
        $this->jsonResponse($services);
    }
    
    public function getById($id) {
        $service = $this->serviceModel->findById($id);
        if ($service) {
            $this->jsonResponse($service);
        } else {
            $this->jsonResponse(['error' => 'Service not found'], 404);
        }
    }
    
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }
        
        $validation = Validator::validateService($_POST);
        
        if ($validation['valid']) {
            $data = [
                'service_name' => $this->sanitizeInput($_POST['service_name']),
                'category' => $this->sanitizeInput($_POST['category']),
                'standard_price' => floatval($_POST['standard_price']),
                'estimated_duration' => $this->sanitizeInput($_POST['estimated_duration'] ?? ''),
                'track_interval' => isset($_POST['track_interval']) ? 1 : 0,
                'service_interval' => $_POST['service_interval'] ?? 6,
                'interval_unit' => $_POST['interval_unit'] ?? 'months',
                'requires_parts' => isset($_POST['requires_parts']) ? 1 : 0,
                'description' => $this->sanitizeInput($_POST['description'] ?? '')
            ];
            
            if ($this->serviceModel->create($data)) {
                $this->jsonResponse(['success' => true, 'message' => 'Service created successfully'], 201);
            } else {
                $this->jsonResponse(['error' => 'Failed to create service'], 500);
            }
        } else {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $validation['errors']], 400);
        }
    }
    
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }
        
        parse_str(file_get_contents("php://input"), $putData);
        
        $validation = Validator::validateService($putData);
        
        if ($validation['valid']) {
            if ($this->serviceModel->update($id, $putData)) {
                $this->jsonResponse(['success' => true, 'message' => 'Service updated successfully']);
            } else {
                $this->jsonResponse(['error' => 'Failed to update service'], 500);
            }
        } else {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $validation['errors']], 400);
        }
    }
    
    public function delete($id) {
        if ($this->serviceModel->delete($id)) {
            $this->jsonResponse(['success' => true, 'message' => 'Service deleted successfully']);
        } else {
            $this->jsonResponse(['error' => 'Failed to delete service'], 500);
        }
    }
    
    public function getStats() {
        $stats = $this->serviceModel->getRevenueStats();
        $this->jsonResponse($stats);
    }
}