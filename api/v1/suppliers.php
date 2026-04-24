<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Supplier.php';
require_once '../../utils/Auth.php';

use App\Models\Supplier;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$supplierModel = new Supplier();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $supplier = $supplierModel->find($_GET['id']);
            if ($supplier) {
                $supplier['total_spent'] = $supplierModel->getTotalSpent($supplier['id']);
                $supplier['purchase_history'] = $supplierModel->getPurchaseHistory($supplier['id']);
                echo json_encode($supplier);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Supplier not found']);
            }
        } else {
            $page = $_GET['page'] ?? 1;
            $suppliers = $supplierModel->paginate($page, $_GET['per_page'] ?? 15);
            echo json_encode($suppliers);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $supplierId = $supplierModel->create($data);
        http_response_code(201);
        echo json_encode(['id' => $supplierId, 'message' => 'Supplier created successfully']);
        break;
        
    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Supplier ID required']);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $supplierModel->update($_GET['id'], $data);
        echo json_encode(['message' => 'Supplier updated successfully']);
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Supplier ID required']);
            break;
        }
        
        $supplierModel->delete($_GET['id']);
        echo json_encode(['message' => 'Supplier deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}