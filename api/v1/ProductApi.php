<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../models/Inventory.php';

class ProductApi {
    private $productModel;
    
    public function __construct() {
        $db = Database::getInstance()->getConnection();
        $this->productModel = new Inventory($db);
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = explode('/', trim($_GET['path'] ?? '', '/'));
        $id = $path[0] ?? null;
        
        switch($method) {
            case 'GET':
                if (isset($_GET['low_stock'])) {
                    $this->getLowStockProducts();
                } elseif (isset($_GET['search'])) {
                    $this->searchProducts($_GET['search']);
                } elseif ($id) {
                    $this->getProduct($id);
                } else {
                    $this->getAllProducts();
                }
                break;
            case 'POST':
                $this->createProduct();
                break;
            case 'PUT':
                if ($id) {
                    $this->updateProduct($id);
                } else {
                    $this->json(['error' => 'Product ID required'], 400);
                }
                break;
            case 'DELETE':
                if ($id) {
                    $this->deleteProduct($id);
                } else {
                    $this->json(['error' => 'Product ID required'], 400);
                }
                break;
            case 'PATCH':
                if ($id && isset($_GET['update_stock'])) {
                    $this->updateStock($id);
                } else {
                    $this->json(['error' => 'Invalid PATCH request'], 400);
                }
                break;
            default:
                $this->json(['error' => 'Method not allowed'], 405);
        }
    }
    
    private function getAllProducts() {
        $products = $this->productModel->getAllActive();
        $this->json($products);
    }
    
    private function getProduct($id) {
        $product = $this->productModel->getById($id);
        if ($product) {
            $this->json($product);
        } else {
            $this->json(['error' => 'Product not found'], 404);
        }
    }
    
    private function createProduct() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $this->json(['error' => 'Invalid input'], 400);
            return;
        }
        
        // Check if product code exists
        $existing = $this->productModel->getByItemCode($input['item_code']);
        if ($existing) {
            $this->json(['error' => 'Product code already exists'], 409);
            return;
        }
        
        $result = $this->productModel->create($input);
        if ($result) {
            $this->json(['message' => 'Product created successfully', 'id' => $result], 201);
        } else {
            $this->json(['error' => 'Failed to create product'], 500);
        }
    }
    
    private function updateProduct($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $this->json(['error' => 'Invalid input'], 400);
            return;
        }
        
        $result = $this->productModel->update($id, $input);
        if ($result) {
            $this->json(['message' => 'Product updated successfully']);
        } else {
            $this->json(['error' => 'Failed to update product'], 500);
        }
    }
    
    private function deleteProduct($id) {
        $result = $this->productModel->delete($id);
        if ($result) {
            $this->json(['message' => 'Product deleted successfully']);
        } else {
            $this->json(['error' => 'Failed to delete product'], 500);
        }
    }
    
    private function getLowStockProducts() {
        $products = $this->productModel->getLowStockItems();
        $this->json($products);
    }
    
    private function searchProducts($keyword) {
        $products = $this->productModel->search($keyword);
        $this->json($products);
    }
    
    private function updateStock($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['quantity'])) {
            $this->json(['error' => 'Quantity required'], 400);
            return;
        }
        
        $result = $this->productModel->updateStock($id, $input['quantity']);
        if ($result) {
            $this->json(['message' => 'Stock updated successfully']);
        } else {
            $this->json(['error' => 'Failed to update stock'], 500);
        }
    }
    
    private function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }
}

// Handle the request
$api = new ProductApi();
$api->handleRequest();
?>