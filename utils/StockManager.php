<?php
namespace Utils;

use Core\Database;
use App\Models\Product;

class StockManager {
    private $db;
    private $productModel;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->productModel = new Product();
    }
    
    public function updateStockOnReceive($purchaseId) {
        $stmt = $this->db->prepare("
            SELECT product_id, quantity 
            FROM purchase_items 
            WHERE purchase_id = ?
        ");
        $stmt->execute([$purchaseId]);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            $this->productModel->updateStock($item['product_id'], $item['quantity'], 'add');
        }
        
        return true;
    }
    
    public function checkLowStock() {
        return $this->productModel->getLowStockProducts();
    }
    
    public function getStockReport() {
        $stmt = $this->db->prepare("
            SELECT 
                category,
                COUNT(*) as product_count,
                SUM(quantity) as total_quantity,
                SUM(quantity * unit_cost) as total_value,
                SUM(quantity * selling_price) as potential_revenue
            FROM inventory
            WHERE is_active = 1
            GROUP BY category
            ORDER BY category
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getStockMovements($productId = null, $days = 30) {
        $sql = "
            SELECT 
                'purchase' as type,
                p.purchase_date as date,
                pi.quantity,
                pi.unit_price,
                pi.total as amount,
                po.po_number as reference
            FROM purchase_items pi
            JOIN purchases po ON pi.purchase_id = po.id
            WHERE po.status = 'received'
        ";
        
        if ($productId) {
            $sql .= " AND pi.product_id = :product_id";
        }
        
        $sql .= " AND po.received_date >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  UNION ALL
                  SELECT 
                      'sale' as type,
                      s.sale_date as date,
                      si.quantity,
                      si.unit_price,
                      si.total as amount,
                      s.invoice_number as reference
                  FROM sale_items si
                  JOIN sales s ON si.sale_id = s.id
                  WHERE s.status = 'completed'";
        
        if ($productId) {
            $sql .= " AND si.product_id = :product_id";
        }
        
        $sql .= " AND s.sale_date >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  ORDER BY date DESC";
        
        $stmt = $this->db->prepare($sql);
        
        if ($productId) {
            $stmt->bindParam(':product_id', $productId);
        }
        $stmt->bindParam(':days', $days);
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
}