<?php
namespace App\Models;

use Core\Model;
use PDO;

class Purchase extends Model {
    protected $table = 'purchases';
    protected $primaryKey = 'id';
    protected $fillable = [
        'po_number', 'supplier_id', 'purchase_date', 'expected_delivery',
        'status', 'subtotal', 'discount_total', 'tax_total', 'shipping_cost',
        'total_amount', 'payment_terms', 'supplier_invoice', 'notes',
        'created_by', 'received_by', 'received_date'
    ];
    
    public function getWithItems($id) {
        $stmt = $this->db->prepare("
            SELECT p.*, s.supplier_name, s.telephone, s.email, s.address,
                   u1.full_name as created_by_name,
                   u2.full_name as received_by_name
            FROM purchases p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN users u1 ON p.created_by = u1.id
            LEFT JOIN users u2 ON p.received_by = u2.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $purchase = $stmt->fetch();
        
        if ($purchase) {
            $purchase['items'] = $this->getItems($id);
        }
        
        return $purchase;
    }
    
    public function getItems($purchaseId) {
        $stmt = $this->db->prepare("
            SELECT pi.*, i.item_code, i.product_name, i.unit_of_measure,
                   i.category, i.current_stock
            FROM purchase_items pi
            LEFT JOIN inventory i ON pi.product_id = i.id
            WHERE pi.purchase_id = ?
        ");
        $stmt->execute([$purchaseId]);
        return $stmt->fetchAll();
    }
    
    public function createWithItems($purchaseData, $items) {
        try {
            $this->db->beginTransaction();
            
            // Create purchase
            $purchaseId = $this->create($purchaseData);
            
            // Create purchase items
            $itemModel = new PurchaseItem();
            foreach ($items as $item) {
                $item['purchase_id'] = $purchaseId;
                $itemModel->create($item);
            }
            
            $this->db->commit();
            return $purchaseId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function receivePurchase($id, $receivedBy, $items) {
        try {
            $this->db->beginTransaction();
            
            // Update purchase status
            $this->update($id, [
                'status' => 'received',
                'received_by' => $receivedBy,
                'received_date' => date('Y-m-d H:i:s')
            ]);
            
            // Update inventory quantities
            $productModel = new Product();
            foreach ($items as $item) {
                $productModel->updateStock($item['product_id'], $item['quantity'], 'add');
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getPurchaseOrders($filters = [], $page = 1, $perPage = 15) {
        $sql = "SELECT p.*, s.supplier_name, 
                       (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) as item_count
                FROM purchases p
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND p.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND p.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND p.purchase_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND p.purchase_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM purchases p WHERE 1=1";
        $countParams = [];
        
        if (!empty($filters['status'])) {
            $countSql .= " AND p.status = ?";
            $countParams[] = $filters['status'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $countSql .= " AND p.supplier_id = ?";
            $countParams[] = $filters['supplier_id'];
        }
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch()['total'];
        
        return [
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage)
        ];
    }
    
    public function getStatistics($days = 30) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_purchases,
                COALESCE(SUM(total_amount), 0) as total_spent,
                COALESCE(AVG(total_amount), 0) as avg_purchase,
                COUNT(CASE WHEN status = 'ordered' THEN 1 END) as pending_orders,
                COUNT(CASE WHEN status = 'received' THEN 1 END) as received_orders,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders
            FROM purchases
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        return $stmt->fetch();
    }
    
    public function generatePONumber() {
        return 'PO-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}