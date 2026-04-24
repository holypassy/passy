<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Purchase.php';
require_once '../../app/models/PurchaseItem.php';
require_once '../../utils/Auth.php';
require_once '../../utils/Validator.php';

use App\Models\Purchase;
use App\Models\PurchaseItem;
use Utils\Auth;
use Utils\Validator;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$purchaseModel = new Purchase();
$validator = new Validator();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            // Get single purchase
            $purchase = $purchaseModel->getWithItems($_GET['id']);
            if ($purchase) {
                echo json_encode($purchase);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Purchase not found']);
            }
        } else {
            // Get all purchases with filters
            $page = $_GET['page'] ?? 1;
            $filters = [
                'status' => $_GET['status'] ?? null,
                'supplier_id' => $_GET['supplier_id'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null
            ];
            $result = $purchaseModel->getPurchaseOrders($filters, $page, $_GET['per_page'] ?? 15);
            echo json_encode($result);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = $validator->validate($data, [
            'supplier_id' => 'required|numeric',
            'purchase_date' => 'required|date',
            'items' => 'required|array|min:1'
        ]);
        
        if ($validation->fails()) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $purchaseData = [
            'po_number' => $purchaseModel->generatePONumber(),
            'supplier_id' => $data['supplier_id'],
            'purchase_date' => $data['purchase_date'],
            'expected_delivery' => $data['expected_delivery'] ?? null,
            'status' => 'ordered',
            'subtotal' => $data['subtotal'],
            'discount_total' => $data['discount_total'] ?? 0,
            'tax_total' => $data['tax_total'] ?? 0,
            'shipping_cost' => $data['shipping_cost'] ?? 0,
            'total_amount' => $data['total_amount'],
            'payment_terms' => $data['payment_terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user['id']
        ];
        
        try {
            $purchaseId = $purchaseModel->createWithItems($purchaseData, $data['items']);
            http_response_code(201);
            echo json_encode(['id' => $purchaseId, 'message' => 'Purchase order created successfully']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Purchase ID required']);
            break;
        }
        
        $purchaseId = $_GET['id'];
        $purchase = $purchaseModel->find($purchaseId);
        
        if (!$purchase) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase not found']);
            break;
        }
        
        if ($data['action'] === 'receive') {
            $result = $purchaseModel->receivePurchase($purchaseId, $user['id'], $data['items']);
            echo json_encode(['message' => 'Purchase order received successfully']);
        } else {
            $result = $purchaseModel->update($purchaseId, $data);
            echo json_encode(['message' => 'Purchase order updated successfully']);
        }
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Purchase ID required']);
            break;
        }
        
        $result = $purchaseModel->delete($_GET['id']);
        echo json_encode(['message' => 'Purchase order deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}