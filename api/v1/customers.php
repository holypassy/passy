<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Customer.php';
require_once '../../app/models/Loyalty.php';
require_once '../../utils/Auth.php';

use App\Models\Customer;
use App\Models\Loyalty;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$customerModel = new Customer();
$loyaltyModel = new Loyalty();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $customer = $customerModel->getWithDetails($_GET['id']);
            if ($customer) {
                echo json_encode($customer);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Customer not found']);
            }
        } elseif (isset($_GET['search'])) {
            $customers = $customerModel->search($_GET['search'], $_GET['limit'] ?? 20);
            echo json_encode($customers);
        } elseif (isset($_GET['tier'])) {
            $customers = $customerModel->getCustomersByTier($_GET['tier']);
            echo json_encode($customers);
        } else {
            $page = $_GET['page'] ?? 1;
            $perPage = $_GET['per_page'] ?? 15;
            $customers = $customerModel->paginate($page, $perPage, ['status' => 1]);
            echo json_encode($customers);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'full_name' => 'required|min:2',
            'telephone' => 'required'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $data['status'] = 1;
        $customerId = $customerModel->create($data);
        
        // Initialize loyalty
        $loyaltyModel->create([
            'customer_id' => $customerId,
            'joined_date' => date('Y-m-d')
        ]);
        
        http_response_code(201);
        echo json_encode(['id' => $customerId, 'message' => 'Customer created successfully']);
        break;
        
    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Customer ID required']);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $customerModel->update($_GET['id'], $data);
        
        // Update tier based on spending
        $loyaltyModel->updateTier($_GET['id']);
        
        echo json_encode(['message' => 'Customer updated successfully']);
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Customer ID required']);
            break;
        }
        
        $customerModel->softDelete($_GET['id']);
        echo json_encode(['message' => 'Customer deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}