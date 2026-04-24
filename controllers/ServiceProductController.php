<?php
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class ServiceProductController {
    private $serviceModel;
    private $productModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->serviceModel = new Service();
        $this->productModel = new Product();
    }
    
    // ==================== SERVICE ENDPOINTS ====================
    
    public function getServices() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? null,
            'category' => $_GET['category'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $services = $this->serviceModel->getAll($filters);
        $stats = $this->serviceModel->getStatistics();
        $categories = $this->serviceModel->getCategories();
        
        Response::json([
            'success' => true,
            'data' => [
                'services' => $services,
                'statistics' => $stats,
                'categories' => $categories
            ],
            'filters' => $filters
        ]);
    }
    
    public function getService($id) {
        AuthMiddleware::authenticate();
        
        $service = $this->serviceModel->findById($id);
        
        if (!$service) {
            Response::json(['success' => false, 'message' => 'Service not found'], 404);
        }
        
        Response::json(['success' => true, 'data' => $service]);
    }
    
    public function createService() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'service_name' => 'required|min:3',
            'standard_price' => 'required|numeric|min:0'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->serviceModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Service created successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create service'], 500);
        }
    }
    
    public function updateService($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $service = $this->serviceModel->findById($id);
        if (!$service) {
            Response::json(['success' => false, 'message' => 'Service not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->serviceModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Service updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update service'], 500);
        }
    }
    
    public function deleteService($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $service = $this->serviceModel->findById($id);
        if (!$service) {
            Response::json(['success' => false, 'message' => 'Service not found'], 404);
        }
        
        $result = $this->serviceModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Service deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete service'], 500);
        }
    }
    
    // ==================== PRODUCT ENDPOINTS ====================
    
    public function getProducts() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? null,
            'category' => $_GET['category'] ?? null,
            'low_stock' => isset($_GET['low_stock']) ? (bool)$_GET['low_stock'] : null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $products = $this->productModel->getAll($filters);
        $stats = $this->productModel->getStatistics();
        $categories = $this->productModel->getCategories();
        $lowStock = $this->productModel->getLowStock();
        
        Response::json([
            'success' => true,
            'data' => [
                'products' => $products,
                'statistics' => $stats,
                'categories' => $categories,
                'low_stock' => $lowStock
            ],
            'filters' => $filters
        ]);
    }
    
    public function getProduct($id) {
        AuthMiddleware::authenticate();
        
        $product = $this->productModel->findById($id);
        
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        Response::json(['success' => true, 'data' => $product]);
    }
    
    public function createProduct() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'item_name' => 'required|min:3',
            'selling_price' => 'required|numeric|min:0'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->productModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Product created successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create product'], 500);
        }
    }
    
    public function updateProduct($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $product = $this->productModel->findById($id);
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->productModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Product updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update product'], 500);
        }
    }
    
    public function updateProductStock($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'quantity' => 'required|numeric',
            'operation' => 'required|in:add,subtract'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $product = $this->productModel->findById($id);
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        if ($input['operation'] === 'subtract' && $product['current_stock'] < $input['quantity']) {
            Response::json(['success' => false, 'message' => 'Insufficient stock'], 400);
        }
        
        $result = $this->productModel->updateStock($id, $input['quantity'], $input['operation']);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Stock updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update stock'], 500);
        }
    }
    
    public function deleteProduct($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $product = $this->productModel->findById($id);
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $result = $this->productModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Product deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete product'], 500);
        }
    }
    
    // ==================== COMBINED ENDPOINTS ====================
    
    public function getAllItems() {
        AuthMiddleware::authenticate();
        
        $services = $this->serviceModel->getAll(['limit' => 100]);
        $products = $this->productModel->getAll(['limit' => 100]);
        
        Response::json([
            'success' => true,
            'data' => [
                'services' => $services,
                'products' => $products,
                'counts' => [
                    'services' => count($services),
                    'products' => count($products),
                    'total' => count($services) + count($products)
                ]
            ]
        ]);
    }
    
    public function getCategories() {
        AuthMiddleware::authenticate();
        
        $serviceCategories = $this->serviceModel->getCategories();
        $productCategories = $this->productModel->getCategories();
        
        Response::json([
            'success' => true,
            'data' => [
                'service_categories' => $serviceCategories,
                'product_categories' => $productCategories
            ]
        ]);
    }
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $serviceStats = $this->serviceModel->getStatistics();
        $productStats = $this->productModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => [
                'services' => $serviceStats,
                'products' => $productStats
            ]
        ]);
    }
}
?>