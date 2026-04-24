<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../helpers/Validator.php';

class ProductController extends Controller {
    private $productModel;
    
    public function __construct() {
        parent::__construct();
        $this->productModel = new Product();
    }
    
    public function getAll() {
        $products = $this->productModel->findAll();
        $this->jsonResponse($products);
    }
    
    public function getById($id) {
        $product = $this->productModel->findById($id);
        if ($product) {
            $this->jsonResponse($product);
        } else {
            $this->jsonResponse(['error' => 'Product not found'], 404);
        }
    }
    
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }
        
        $validation = Validator::validateProduct($_POST);
        
        if ($validation['valid']) {
            $data = [
                'item_code' => $this->sanitizeInput($_POST['item_code']),
                'product_name' => $this->sanitizeInput($_POST['product_name']),
                'category' => $this->sanitizeInput($_POST['category'] ?? 'General'),
                'unit_of_measure' => $this->sanitizeInput($_POST['unit_of_measure'] ?? 'piece'),
                'unit_cost' => floatval($_POST['unit_cost'] ?? 0),
                'selling_price' => floatval($_POST['selling_price']),
                'opening_stock' => intval($_POST['opening_stock'] ?? 0),
                'reorder_level' => intval($_POST['reorder_level'] ?? 5),
                'description' => $this->sanitizeInput($_POST['description'] ?? '')
            ];
            
            if ($this->productModel->create($data)) {
                $this->jsonResponse(['success' => true, 'message' => 'Product created successfully'], 201);
            } else {
                $this->jsonResponse(['error' => 'Failed to create product'], 500);
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
        
        $validation = Validator::validateProduct($putData);
        
        if ($validation['valid']) {
            if ($this->productModel->update($id, $putData)) {
                $this->jsonResponse(['success' => true, 'message' => 'Product updated successfully']);
            } else {
                $this->jsonResponse(['error' => 'Failed to update product'], 500);
            }
        } else {
            $this->jsonResponse(['error' => 'Validation failed', 'details' => $validation['errors']], 400);
        }
    }
    
    public function delete($id) {
        if ($this->productModel->delete($id)) {
            $this->jsonResponse(['success' => true, 'message' => 'Product deleted successfully']);
        } else {
            $this->jsonResponse(['error' => 'Failed to delete product'], 500);
        }
    }
    
    public function getInventoryStats() {
        $stats = $this->productModel->getInventoryStats();
        $lowStock = $this->productModel->getLowStockProducts();
        
        $this->jsonResponse([
            'inventory' => $stats,
            'low_stock_count' => count($lowStock),
            'low_stock_items' => $lowStock
        ]);
    }
    
    public function updateStock($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }
        
        $quantity = intval($_POST['quantity'] ?? 0);
        
        if ($this->productModel->updateStock($id, $quantity)) {
            $this->jsonResponse(['success' => true, 'message' => 'Stock updated successfully']);
        } else {
            $this->jsonResponse(['error' => 'Failed to update stock'], 500);
        }
    }
}