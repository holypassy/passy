<?php
require_once __DIR__ . '/../models/JobCard.php';
require_once __DIR__ . '/../models/JobInspection.php';
require_once __DIR__ . '/../models/JobWorkItem.php';
require_once __DIR__ . '/../models/Customer.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/JobCardHelper.php';

class NewJobController {
    private $jobCardModel;
    private $inspectionModel;
    private $workItemModel;
    private $customerModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->jobCardModel = new JobCard();
        $this->inspectionModel = new JobInspection();
        $this->workItemModel = new JobWorkItem();
        $this->customerModel = new Customer();
    }
    
    public function getFormData() {
        AuthMiddleware::authenticate();
        
        $data = [
            'job_number' => $this->jobCardModel->generateJobNumber(),
            'customers' => $this->customerModel->getAll(['limit' => 100]),
            'inspection_items' => $this->jobCardModel->getInspectionItems(),
            'inspection_statuses' => $this->jobCardModel->getInspectionStatusOptions(),
            'fuel_levels' => $this->jobCardModel->getFuelLevelOptions(),
            'priorities' => $this->jobCardModel->getPriorityOptions(),
            'user' => AuthMiddleware::getCurrentUser()
        ];
        
        Response::json(['success' => true, 'data' => $data]);
    }
    
    public function createJob() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'customer_id' => 'required|numeric',
            'vehicle_reg' => 'required|min:3'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $session = AuthMiddleware::getCurrentUser();
        
        // Prepare job card data
        $jobData = [
            'job_number' => $input['job_number'] ?? $this->jobCardModel->generateJobNumber(),
            'customer_id' => $input['customer_id'],
            'vehicle_reg' => $input['vehicle_reg'],
            'vehicle_make' => $input['vehicle_make'] ?? null,
            'vehicle_model' => $input['vehicle_model'] ?? null,
            'vehicle_year' => $input['vehicle_year'] ?? null,
            'odometer_reading' => $input['odometer_reading'] ?? null,
            'fuel_level' => $input['fuel_level'] ?? null,
            'date_received' => $input['date_received'] ?? date('Y-m-d'),
            'status' => 'pending',
            'priority' => $input['priority'] ?? 'normal',
            'notes' => $input['notes'] ?? null,
            'inspection_data' => json_encode($input['inspections'] ?? []),
            'work_items' => json_encode($input['work_items'] ?? []),
            'created_by' => $session['id']
        ];
        
        $this->jobCardModel->conn->beginTransaction();
        
        try {
            // Create job card
            $result = $this->jobCardModel->create($jobData);
            
            if (!$result) {
                throw new Exception("Failed to create job card");
            }
            
            $jobCardId = $this->jobCardModel->conn->lastInsertId();
            
            // Save inspections
            if (!empty($input['inspections'])) {
                foreach ($input['inspections'] as $inspection) {
                    $this->inspectionModel->create([
                        'job_card_id' => $jobCardId,
                        'section' => $inspection['section'],
                        'item_name' => $inspection['item_name'],
                        'status' => $inspection['status'],
                        'notes' => $inspection['notes'] ?? null
                    ]);
                }
            }
            
            // Save work items
            if (!empty($input['work_items'])) {
                foreach ($input['work_items'] as $item) {
                    $this->workItemModel->create([
                        'job_card_id' => $jobCardId,
                        'part_number' => $item['part_number'] ?? null,
                        'description' => $item['description'],
                        'notes' => $item['notes'] ?? null
                    ]);
                }
            }
            
            $this->jobCardModel->conn->commit();
            
            Response::json([
                'success' => true,
                'message' => 'Job card created successfully',
                'job_id' => $jobCardId,
                'job_number' => $jobData['job_number']
            ]);
            
        } catch (Exception $e) {
            $this->jobCardModel->conn->rollback();
            Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function getJobTemplate() {
        AuthMiddleware::authenticate();
        
        $template = [
            'job_number' => $this->jobCardModel->generateJobNumber(),
            'customer' => null,
            'vehicle' => [
                'registration' => '',
                'make' => '',
                'model' => '',
                'year' => '',
                'odometer' => '',
                'fuel_level' => ''
            ],
            'inspections' => [
                'basic' => [],
                'front' => [],
                'rear' => [],
                'left' => [],
                'right' => [],
                'top' => []
            ],
            'work_items' => [['part_number' => '', 'description' => '']],
            'notes' => '',
            'brought_by' => '',
            'terms_accepted' => false
        ];
        
        Response::json(['success' => true, 'data' => $template]);
    }
    
    public function addCustomer() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'full_name' => 'required|min:3'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->customerModel->create($input);
        
        if ($result) {
            $newId = $this->customerModel->conn->lastInsertId();
            Response::json([
                'success' => true,
                'message' => 'Customer added successfully',
                'id' => $newId,
                'name' => $input['full_name'],
                'phone' => $input['telephone'] ?? '',
                'email' => $input['email'] ?? ''
            ]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to add customer'], 500);
        }
    }
    
    public function getInspectionItems() {
        AuthMiddleware::authenticate();
        
        $items = $this->jobCardModel->getInspectionItems();
        $statuses = $this->jobCardModel->getInspectionStatusOptions();
        
        Response::json([
            'success' => true,
            'data' => [
                'items' => $items,
                'statuses' => $statuses
            ]
        ]);
    }
    
    public function getWorkItemTemplate() {
        AuthMiddleware::authenticate();
        
        Response::json([
            'success' => true,
            'data' => [
                'part_number' => '',
                'description' => '',
                'notes' => ''
            ]
        ]);
    }
}
?>