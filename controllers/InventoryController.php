<?php
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/InventoryTransaction.php';
require_once __DIR__ . '/../models/Supplier.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class InventoryController {
    private $inventoryModel;
    private $transactionModel;
    private $supplierModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->inventoryModel = new Inventory();
        $this->transactionModel = new InventoryTransaction();
        $this->supplierModel = new Supplier();
    }
    
    // ==================== PRODUCT ENDPOINTS ====================
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? null,
            'category' => $_GET['category'] ?? null,
            'stock_status' => $_GET['stock_status'] ?? null,
            'supplier_id' => $_GET['supplier_id'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $products = $this->inventoryModel->getAll($filters);
        $stats = $this->inventoryModel->getStatistics();
        $categories = $this->inventoryModel->getCategories();
        
        Response::json([
            'success' => true,
            'data' => $products,
            'statistics' => $stats,
            'categories' => $categories,
            'filters' => $filters
        ]);
    }
    
    public function getOne($id) {
        AuthMiddleware::authenticate();
        
        $product = $this->inventoryModel->findById($id);
        
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $transactions = $this->transactionModel->getByProductId($id);
        $product['transactions'] = $transactions;
        
        Response::json(['success' => true, 'data' => $product]);
    }
    
    public function create() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'product_name' => 'required|min:3'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->inventoryModel->create($input);
        
        if ($result) {
            $productId = $this->inventoryModel->conn->lastInsertId();
            $session = AuthMiddleware::getCurrentUser();
            
            // Record initial stock transaction if quantity > 0
            if (!empty($input['quantity']) && $input['quantity'] > 0) {
                $this->transactionModel->create([
                    'product_id' => $productId,
                    'transaction_type' => 'initial_stock',
                    'quantity' => $input['quantity'],
                    'unit_price' => $input['cost_price'] ?? 0,
                    'total_amount' => ($input['cost_price'] ?? 0) * $input['quantity'],
                    'notes' => 'Initial stock entry',
                    'created_by' => $session['id']
                ]);
            }
            
            Response::json([
                'success' => true, 
                'message' => 'Product created successfully',
                'id' => $productId,
                'sku' => $input['sku'] ?? null
            ]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create product'], 500);
        }
    }
    
    public function update($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $product = $this->inventoryModel->findById($id);
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->inventoryModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Product updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update product'], 500);
        }
    }
    
    public function adjustStock($id) {
        AuthMiddleware::requireRole(['admin', 'manager', 'technician']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'quantity' => 'required|numeric|min:0.01',
            'adjustment_type' => 'required|in:add,remove'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $product = $this->inventoryModel->findById($id);
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $quantity = $input['quantity'];
        $operation = $input['adjustment_type'];
        
        // Check if removing more than available
        if ($operation === 'remove' && $quantity > $product['quantity']) {
            Response::json([
                'success' => false, 
                'message' => 'Cannot remove more than available stock',
                'available' => $product['quantity']
            ], 400);
        }
        
        $this->inventoryModel->conn->beginTransaction();
        
        try {
            // Update stock
            $result = $this->inventoryModel->updateStock($id, $quantity, $operation);
            
            if (!$result) {
                throw new Exception("Failed to update stock");
            }
            
            // Record transaction
            $session = AuthMiddleware::getCurrentUser();
            $transactionType = $operation === 'add' ? 'stock_in' : 'stock_out';
            
            $this->transactionModel->create([
                'product_id' => $id,
                'transaction_type' => $transactionType,
                'quantity' => $operation === 'add' ? $quantity : -$quantity,
                'unit_price' => $product['cost_price'],
                'total_amount' => $product['cost_price'] * $quantity,
                'notes' => $input['notes'] ?? ($operation === 'add' ? 'Stock addition' : 'Stock removal'),
                'created_by' => $session['id']
            ]);
            
            $this->inventoryModel->conn->commit();
            
            // Get updated product
            $updatedProduct = $this->inventoryModel->findById($id);
            
            Response::json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => [
                    'product_id' => $id,
                    'previous_quantity' => $product['quantity'],
                    'new_quantity' => $updatedProduct['quantity'],
                    'adjustment' => $operation === 'add' ? "+{$quantity}" : "-{$quantity}"
                ]
            ]);
            
        } catch (Exception $e) {
            $this->inventoryModel->conn->rollback();
            Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function delete($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $product = $this->inventoryModel->findById($id);
        if (!$product) {
            Response::json(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $result = $this->inventoryModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Product deactivated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to deactivate product'], 500);
        }
    }
    
    // ==================== SUPPLIER ENDPOINTS ====================
    
    public function getSuppliers() {
        AuthMiddleware::authenticate();
        
        $suppliers = $this->supplierModel->getAll();
        
        Response::json(['success' => true, 'data' => $suppliers]);
    }
    
    public function createSupplier() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'supplier_name' => 'required|min:3'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->supplierModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Supplier created successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create supplier'], 500);
        }
    }
    
    // ==================== TRANSACTION ENDPOINTS ====================
    
    public function getTransactions() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
        
        $transactions = $this->transactionModel->getMovements($startDate, $endDate, $productId);
        $summary = $this->transactionModel->getSummary($startDate, $endDate);
        
        Response::json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'summary' => $summary,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ]
        ]);
    }
    
    // ==================== REPORT ENDPOINTS ====================
    
    public function getLowStock() {
        AuthMiddleware::authenticate();
        
        $lowStock = $this->inventoryModel->getLowStockItems();
        
        Response::json([
            'success' => true,
            'data' => $lowStock,
            'count' => count($lowStock)
        ]);
    }
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $stats = $this->inventoryModel->getStatistics();
        $categories = $this->inventoryModel->getCategories();
        
        Response::json([
            'success' => true,
            'data' => [
                'statistics' => $stats,
                'categories' => $categories
            ]
        ]);
    }
}
?>