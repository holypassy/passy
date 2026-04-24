<?php
require_once __DIR__ . '/../config/database.php';

class Inventory {
    private $conn;
    private $table = "inventory";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll($filters = []) {
        $query = "SELECT 
                    i.*,
                    s.supplier_name,
                    (i.quantity * i.cost_price) as stock_value,
                    (i.quantity * i.selling_price) as stock_value_selling,
                    CASE 
                        WHEN i.quantity <= 0 THEN 'Out of Stock'
                        WHEN i.quantity <= i.reorder_level THEN 'Low Stock'
                        ELSE 'In Stock'
                    END as stock_status,
                    CASE 
                        WHEN i.quantity <= 0 THEN 'danger'
                        WHEN i.quantity <= i.reorder_level THEN 'warning'
                        ELSE 'success'
                    END as status_color
                  FROM {$this->table} i
                  LEFT JOIN suppliers s ON i.supplier_id = s.id
                  WHERE i.is_active = 1";
        
        $params = [];
        
        if (!empty($filters['search'])) {
            $query .= " AND (i.product_name LIKE :search OR i.sku LIKE :search OR i.category LIKE :search)";
            $params[':search'] = "%{$filters['search']}%";
        }
        
        if (!empty($filters['category'])) {
            $query .= " AND i.category = :category";
            $params[':category'] = $filters['category'];
        }
        
        if (!empty($filters['stock_status'])) {
            if ($filters['stock_status'] == 'low') {
                $query .= " AND i.quantity <= i.reorder_level AND i.quantity > 0";
            } elseif ($filters['stock_status'] == 'out') {
                $query .= " AND i.quantity = 0";
            } elseif ($filters['stock_status'] == 'in') {
                $query .= " AND i.quantity > i.reorder_level";
            }
        }
        
        if (!empty($filters['supplier_id'])) {
            $query .= " AND i.supplier_id = :supplier_id";
            $params[':supplier_id'] = $filters['supplier_id'];
        }
        
        $query .= " ORDER BY i.product_name ASC";
        
        if (!empty($filters['limit'])) {
            $query .= " LIMIT :limit";
            $params[':limit'] = (int)$filters['limit'];
        }
        
        if (!empty($filters['offset'])) {
            $query .= " OFFSET :offset";
            $params[':offset'] = (int)$filters['offset'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function findById($id) {
        $query = "SELECT 
                    i.*,
                    s.supplier_name
                  FROM {$this->table} i
                  LEFT JOIN suppliers s ON i.supplier_id = s.id
                  WHERE i.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findBySku($sku) {
        $query = "SELECT * FROM {$this->table} WHERE sku = :sku";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        // Generate SKU if not provided
        if (empty($data['sku'])) {
            $data['sku'] = $this->generateSku($data['product_name']);
        }
        
        $query = "INSERT INTO {$this->table} 
                  (product_name, sku, category, supplier_id, cost_price, selling_price, 
                   quantity, reorder_level, unit, location, description, is_active) 
                  VALUES (:product_name, :sku, :category, :supplier_id, :cost_price, :selling_price, 
                          :quantity, :reorder_level, :unit, :location, :description, 1)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':product_name' => $data['product_name'],
            ':sku' => $data['sku'],
            ':category' => $data['category'] ?? null,
            ':supplier_id' => $data['supplier_id'] ?? null,
            ':cost_price' => $data['cost_price'] ?? 0,
            ':selling_price' => $data['selling_price'] ?? 0,
            ':quantity' => $data['quantity'] ?? 0,
            ':reorder_level' => $data['reorder_level'] ?? 0,
            ':unit' => $data['unit'] ?? 'piece',
            ':location' => $data['location'] ?? null,
            ':description' => $data['description'] ?? null
        ]);
    }
    
    public function update($id, $data) {
        $query = "UPDATE {$this->table} 
                  SET product_name = :product_name,
                      category = :category,
                      supplier_id = :supplier_id,
                      cost_price = :cost_price,
                      selling_price = :selling_price,
                      reorder_level = :reorder_level,
                      unit = :unit,
                      location = :location,
                      description = :description
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':product_name' => $data['product_name'],
            ':category' => $data['category'] ?? null,
            ':supplier_id' => $data['supplier_id'] ?? null,
            ':cost_price' => $data['cost_price'] ?? 0,
            ':selling_price' => $data['selling_price'] ?? 0,
            ':reorder_level' => $data['reorder_level'] ?? 0,
            ':unit' => $data['unit'] ?? 'piece',
            ':location' => $data['location'] ?? null,
            ':description' => $data['description'] ?? null
        ]);
    }
    
    public function updateStock($id, $quantity, $operation = 'add') {
        $operator = $operation === 'add' ? '+' : '-';
        $query = "UPDATE {$this->table} 
                  SET quantity = quantity {$operator} :quantity 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':quantity' => abs($quantity)
        ]);
    }
    
    public function delete($id) {
        $query = "UPDATE {$this->table} SET is_active = 0 WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function hardDelete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function getStatistics() {
        $query = "SELECT 
                    COUNT(*) as total_products,
                    SUM(quantity) as total_items,
                    SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END) as low_stock_count,
                    SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                    COALESCE(SUM(quantity * cost_price), 0) as total_value,
                    COALESCE(SUM(quantity * selling_price), 0) as total_selling_value,
                    COALESCE(AVG(selling_price - cost_price), 0) as avg_margin
                  FROM {$this->table}
                  WHERE is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function getLowStockItems() {
        $query = "SELECT 
                    i.*,
                    s.supplier_name
                  FROM {$this->table} i
                  LEFT JOIN suppliers s ON i.supplier_id = s.id
                  WHERE i.quantity <= i.reorder_level 
                    AND i.quantity > 0
                    AND i.is_active = 1
                  ORDER BY i.quantity ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getCategories() {
        $query = "SELECT DISTINCT category FROM {$this->table} 
                  WHERE category IS NOT NULL AND category != '' AND is_active = 1
                  ORDER BY category";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    private function generateSku($productName) {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $productName), 0, 5));
        $sku = $prefix . date('Ymd') . rand(100, 999);
        
        // Check if SKU exists
        $existing = $this->findBySku($sku);
        if ($existing) {
            $sku = $prefix . date('YmdHis') . rand(10, 99);
        }
        
        return $sku;
    }
}
?>