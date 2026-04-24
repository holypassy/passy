<?php
session_start();
require_once '../config/database.php';

class PurchaseController {
    private $conn;
    
    public function __construct() {
        try {
            $this->conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ../views/purchases/index.php');
            exit();
        }
        
        $data = $_POST;
        $items = json_decode($data['items'], true);
        
        // Debug: Log received data
        error_log("Creating purchase order: " . print_r($data, true));
        error_log("Items: " . print_r($items, true));
        
        try {
            $this->conn->beginTransaction();
            
            // Check if purchases table has the required columns
            $purchaseColumns = $this->conn->query("SHOW COLUMNS FROM purchases")->fetchAll(PDO::FETCH_COLUMN);
            
            // Build insert query dynamically based on available columns
            $insertFields = ['po_number', 'supplier_id', 'purchase_date', 'status', 'total_amount', 'created_by'];
            $insertValues = [
                $data['po_number'],
                $data['supplier_id'],
                $data['purchase_date'],
                'ordered',
                $data['total_amount'],
                $_SESSION['user_id'] ?? 1
            ];
            
            // Add optional fields if they exist in the form and table
            if (isset($data['expected_delivery']) && in_array('expected_delivery', $purchaseColumns)) {
                $insertFields[] = 'expected_delivery';
                $insertValues[] = $data['expected_delivery'];
            }
            if (isset($data['subtotal']) && in_array('subtotal', $purchaseColumns)) {
                $insertFields[] = 'subtotal';
                $insertValues[] = $data['subtotal'];
            }
            if (isset($data['discount_total']) && in_array('discount_total', $purchaseColumns)) {
                $insertFields[] = 'discount_total';
                $insertValues[] = $data['discount_total'];
            }
            if (isset($data['tax_total']) && in_array('tax_total', $purchaseColumns)) {
                $insertFields[] = 'tax_total';
                $insertValues[] = $data['tax_total'];
            }
            if (isset($data['shipping_cost']) && in_array('shipping_cost', $purchaseColumns)) {
                $insertFields[] = 'shipping_cost';
                $insertValues[] = $data['shipping_cost'];
            }
            if (isset($data['payment_terms']) && in_array('payment_terms', $purchaseColumns)) {
                $insertFields[] = 'payment_terms';
                $insertValues[] = $data['payment_terms'];
            }
            if (isset($data['supplier_invoice']) && in_array('supplier_invoice', $purchaseColumns)) {
                $insertFields[] = 'supplier_invoice';
                $insertValues[] = $data['supplier_invoice'];
            }
            if (isset($data['notes']) && in_array('notes', $purchaseColumns)) {
                $insertFields[] = 'notes';
                $insertValues[] = $data['notes'];
            }
            
            $placeholders = '?' . str_repeat(',?', count($insertFields) - 1);
            $sql = "INSERT INTO purchases (" . implode(',', $insertFields) . ") VALUES ($placeholders)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($insertValues);
            $purchase_id = $this->conn->lastInsertId();
            
            // Insert purchase items
            $itemStmt = $this->conn->prepare("
                INSERT INTO purchase_items (purchase_id, product_id, item_code, product_name, 
                                            quantity, unit_price, discount, total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($items as $item) {
                $total = ($item['price'] * $item['quantity']) - $item['discount'];
                $itemStmt->execute([
                    $purchase_id,
                    $item['product_id'],
                    $item['code'],
                    $item['name'],
                    $item['quantity'],
                    $item['price'],
                    $item['discount'],
                    $total
                ]);
            }
            
            $this->conn->commit();
            $_SESSION['success'] = 'Purchase order created successfully!';
            header("Location: ../views/purchases/view.php?id=$purchase_id");
            
        } catch(Exception $e) {
            $this->conn->rollback();
            error_log("Purchase creation error: " . $e->getMessage());
            $_SESSION['error'] = 'Failed to create purchase order: ' . $e->getMessage();
            header('Location: ../views/purchases/create.php');
        }
    }
}

// Handle action
if (isset($_GET['action'])) {
    $controller = new PurchaseController();
    
    switch($_GET['action']) {
        case 'store':
            $controller->store();
            break;
    }
}
?>