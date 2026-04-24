<?php
require_once __DIR__ . '/../config/database.php';

class Product {
    private $conn;
    private $table_name = "products";
    
    public $id;
    public $item_code;
    public $product_name;
    public $category;
    public $unit_of_measure;
    public $unit_cost;
    public $selling_price;
    public $opening_stock;
    public $quantity;
    public $reorder_level;
    public $min_stock;
    public $barcode;
    public $supplier_sku;
    public $warehouse_location;
    public $bin_location;
    public $brand;
    public $warranty_period;
    public $description;
    public $notes;
    public $is_active;
    public $track_inventory;
    public $is_featured;
    public $allow_backorder;
    public $tax_rate;
    public $tax_inclusive;
    public $weight;
    public $length;
    public $width;
    public $height;
    public $is_draft;
    public $created_at;
    public $updated_at;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // Create product
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET item_code = :item_code,
                      product_name = :product_name,
                      category = :category,
                      unit_of_measure = :unit_of_measure,
                      unit_cost = :unit_cost,
                      selling_price = :selling_price,
                      opening_stock = :opening_stock,
                      quantity = :quantity,
                      reorder_level = :reorder_level,
                      min_stock = :min_stock,
                      barcode = :barcode,
                      supplier_sku = :supplier_sku,
                      warehouse_location = :warehouse_location,
                      bin_location = :bin_location,
                      brand = :brand,
                      warranty_period = :warranty_period,
                      description = :description,
                      notes = :notes,
                      is_active = :is_active,
                      track_inventory = :track_inventory,
                      is_featured = :is_featured,
                      allow_backorder = :allow_backorder,
                      tax_rate = :tax_rate,
                      tax_inclusive = :tax_inclusive,
                      weight = :weight,
                      length = :length,
                      width = :width,
                      height = :height,
                      is_draft = :is_draft,
                      created_at = NOW()";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize inputs
        $this->item_code = htmlspecialchars(strip_tags($this->item_code));
        $this->product_name = htmlspecialchars(strip_tags($this->product_name));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_of_measure = htmlspecialchars(strip_tags($this->unit_of_measure));
        $this->unit_cost = floatval($this->unit_cost);
        $this->selling_price = floatval($this->selling_price);
        $this->opening_stock = intval($this->opening_stock);
        $this->quantity = intval($this->quantity);
        $this->reorder_level = intval($this->reorder_level);
        $this->min_stock = intval($this->min_stock);
        $this->barcode = htmlspecialchars(strip_tags($this->barcode));
        $this->supplier_sku = htmlspecialchars(strip_tags($this->supplier_sku));
        $this->warehouse_location = htmlspecialchars(strip_tags($this->warehouse_location));
        $this->bin_location = htmlspecialchars(strip_tags($this->bin_location));
        $this->brand = htmlspecialchars(strip_tags($this->brand));
        $this->warranty_period = intval($this->warranty_period);
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->notes = htmlspecialchars(strip_tags($this->notes));
        $this->is_active = $this->is_active ? 1 : 0;
        $this->track_inventory = $this->track_inventory ? 1 : 0;
        $this->is_featured = $this->is_featured ? 1 : 0;
        $this->allow_backorder = $this->allow_backorder ? 1 : 0;
        $this->tax_rate = floatval($this->tax_rate);
        $this->tax_inclusive = intval($this->tax_inclusive);
        $this->weight = floatval($this->weight);
        $this->length = floatval($this->length);
        $this->width = floatval($this->width);
        $this->height = floatval($this->height);
        $this->is_draft = $this->is_draft ? 1 : 0;
        
        // Bind parameters
        $stmt->bindParam(":item_code", $this->item_code);
        $stmt->bindParam(":product_name", $this->product_name);
        $stmt->bindParam(":category", $this->category);
        $stmt->bindParam(":unit_of_measure", $this->unit_of_measure);
        $stmt->bindParam(":unit_cost", $this->unit_cost);
        $stmt->bindParam(":selling_price", $this->selling_price);
        $stmt->bindParam(":opening_stock", $this->opening_stock);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":reorder_level", $this->reorder_level);
        $stmt->bindParam(":min_stock", $this->min_stock);
        $stmt->bindParam(":barcode", $this->barcode);
        $stmt->bindParam(":supplier_sku", $this->supplier_sku);
        $stmt->bindParam(":warehouse_location", $this->warehouse_location);
        $stmt->bindParam(":bin_location", $this->bin_location);
        $stmt->bindParam(":brand", $this->brand);
        $stmt->bindParam(":warranty_period", $this->warranty_period);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":notes", $this->notes);
        $stmt->bindParam(":is_active", $this->is_active);
        $stmt->bindParam(":track_inventory", $this->track_inventory);
        $stmt->bindParam(":is_featured", $this->is_featured);
        $stmt->bindParam(":allow_backorder", $this->allow_backorder);
        $stmt->bindParam(":tax_rate", $this->tax_rate);
        $stmt->bindParam(":tax_inclusive", $this->tax_inclusive);
        $stmt->bindParam(":weight", $this->weight);
        $stmt->bindParam(":length", $this->length);
        $stmt->bindParam(":width", $this->width);
        $stmt->bindParam(":height", $this->height);
        $stmt->bindParam(":is_draft", $this->is_draft);
        
        if($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }
    
    // Update product
    public function update() {
        $query = "UPDATE " . $this->table_name . "
                  SET item_code = :item_code,
                      product_name = :product_name,
                      category = :category,
                      unit_of_measure = :unit_of_measure,
                      unit_cost = :unit_cost,
                      selling_price = :selling_price,
                      reorder_level = :reorder_level,
                      min_stock = :min_stock,
                      barcode = :barcode,
                      supplier_sku = :supplier_sku,
                      warehouse_location = :warehouse_location,
                      bin_location = :bin_location,
                      brand = :brand,
                      warranty_period = :warranty_period,
                      description = :description,
                      notes = :notes,
                      is_active = :is_active,
                      track_inventory = :track_inventory,
                      is_featured = :is_featured,
                      allow_backorder = :allow_backorder,
                      tax_rate = :tax_rate,
                      tax_inclusive = :tax_inclusive,
                      weight = :weight,
                      length = :length,
                      width = :width,
                      height = :height,
                      updated_at = NOW()
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize inputs
        $this->id = intval($this->id);
        $this->item_code = htmlspecialchars(strip_tags($this->item_code));
        $this->product_name = htmlspecialchars(strip_tags($this->product_name));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_of_measure = htmlspecialchars(strip_tags($this->unit_of_measure));
        $this->unit_cost = floatval($this->unit_cost);
        $this->selling_price = floatval($this->selling_price);
        $this->reorder_level = intval($this->reorder_level);
        $this->min_stock = intval($this->min_stock);
        $this->barcode = htmlspecialchars(strip_tags($this->barcode));
        $this->supplier_sku = htmlspecialchars(strip_tags($this->supplier_sku));
        $this->warehouse_location = htmlspecialchars(strip_tags($this->warehouse_location));
        $this->bin_location = htmlspecialchars(strip_tags($this->bin_location));
        $this->brand = htmlspecialchars(strip_tags($this->brand));
        $this->warranty_period = intval($this->warranty_period);
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->notes = htmlspecialchars(strip_tags($this->notes));
        $this->is_active = $this->is_active ? 1 : 0;
        $this->track_inventory = $this->track_inventory ? 1 : 0;
        $this->is_featured = $this->is_featured ? 1 : 0;
        $this->allow_backorder = $this->allow_backorder ? 1 : 0;
        $this->tax_rate = floatval($this->tax_rate);
        $this->tax_inclusive = intval($this->tax_inclusive);
        $this->weight = floatval($this->weight);
        $this->length = floatval($this->length);
        $this->width = floatval($this->width);
        $this->height = floatval($this->height);
        
        // Bind parameters
        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":item_code", $this->item_code);
        $stmt->bindParam(":product_name", $this->product_name);
        $stmt->bindParam(":category", $this->category);
        $stmt->bindParam(":unit_of_measure", $this->unit_of_measure);
        $stmt->bindParam(":unit_cost", $this->unit_cost);
        $stmt->bindParam(":selling_price", $this->selling_price);
        $stmt->bindParam(":reorder_level", $this->reorder_level);
        $stmt->bindParam(":min_stock", $this->min_stock);
        $stmt->bindParam(":barcode", $this->barcode);
        $stmt->bindParam(":supplier_sku", $this->supplier_sku);
        $stmt->bindParam(":warehouse_location", $this->warehouse_location);
        $stmt->bindParam(":bin_location", $this->bin_location);
        $stmt->bindParam(":brand", $this->brand);
        $stmt->bindParam(":warranty_period", $this->warranty_period);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":notes", $this->notes);
        $stmt->bindParam(":is_active", $this->is_active);
        $stmt->bindParam(":track_inventory", $this->track_inventory);
        $stmt->bindParam(":is_featured", $this->is_featured);
        $stmt->bindParam(":allow_backorder", $this->allow_backorder);
        $stmt->bindParam(":tax_rate", $this->tax_rate);
        $stmt->bindParam(":tax_inclusive", $this->tax_inclusive);
        $stmt->bindParam(":weight", $this->weight);
        $stmt->bindParam(":length", $this->length);
        $stmt->bindParam(":width", $this->width);
        $stmt->bindParam(":height", $this->height);
        
        if($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Update stock quantity
    public function updateStock($product_id, $quantity_change) {
        $query = "UPDATE " . $this->table_name . "
                  SET quantity = quantity + :quantity_change,
                      updated_at = NOW()
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":quantity_change", $quantity_change);
        $stmt->bindParam(":id", $product_id);
        
        if($stmt->execute()) {
            // Log stock transaction
            $this->logStockTransaction($product_id, $quantity_change);
            return true;
        }
        return false;
    }
    
    // Log stock transaction
    private function logStockTransaction($product_id, $quantity_change) {
        $query = "INSERT INTO stock_transactions 
                  (product_id, transaction_type, quantity, created_at)
                  VALUES (:product_id, :transaction_type, :quantity, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $transaction_type = $quantity_change > 0 ? 'IN' : 'OUT';
        $stmt->bindParam(":product_id", $product_id);
        $stmt->bindParam(":transaction_type", $transaction_type);
        $stmt->bindParam(":quantity", abs($quantity_change));
        $stmt->execute();
    }
    
    // Read single product
    public function readOne() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $this->id);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if($row) {
            $this->item_code = $row['item_code'];
            $this->product_name = $row['product_name'];
            $this->category = $row['category'];
            $this->unit_of_measure = $row['unit_of_measure'];
            $this->unit_cost = $row['unit_cost'];
            $this->selling_price = $row['selling_price'];
            $this->opening_stock = $row['opening_stock'];
            $this->quantity = $row['quantity'];
            $this->reorder_level = $row['reorder_level'];
            $this->min_stock = $row['min_stock'];
            $this->barcode = $row['barcode'];
            $this->supplier_sku = $row['supplier_sku'];
            $this->warehouse_location = $row['warehouse_location'];
            $this->bin_location = $row['bin_location'];
            $this->brand = $row['brand'];
            $this->warranty_period = $row['warranty_period'];
            $this->description = $row['description'];
            $this->notes = $row['notes'];
            $this->is_active = $row['is_active'];
            $this->track_inventory = $row['track_inventory'];
            $this->is_featured = $row['is_featured'];
            $this->allow_backorder = $row['allow_backorder'];
            $this->tax_rate = $row['tax_rate'];
            $this->tax_inclusive = $row['tax_inclusive'];
            $this->weight = $row['weight'];
            $this->length = $row['length'];
            $this->width = $row['width'];
            $this->height = $row['height'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            return true;
        }
        return false;
    }
    
    // Read all products
    public function readAll() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE is_draft = 0 ORDER BY id DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get products with pagination
    public function readPaginated($limit, $offset, $search = '', $category = '') {
        $query = "SELECT * FROM " . $this->table_name . " WHERE is_draft = 0";
        
        if(!empty($search)) {
            $query .= " AND (product_name LIKE :search OR item_code LIKE :search OR barcode LIKE :search)";
        }
        
        if(!empty($category)) {
            $query .= " AND category = :category";
        }
        
        $query .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        if(!empty($search)) {
            $searchTerm = "%{$search}%";
            $stmt->bindParam(":search", $searchTerm);
        }
        
        if(!empty($category)) {
            $stmt->bindParam(":category", $category);
        }
        
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get total count
    public function getTotalCount($search = '', $category = '') {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " WHERE is_draft = 0";
        
        if(!empty($search)) {
            $query .= " AND (product_name LIKE :search OR item_code LIKE :search OR barcode LIKE :search)";
        }
        
        if(!empty($category)) {
            $query .= " AND category = :category";
        }
        
        $stmt = $this->conn->prepare($query);
        
        if(!empty($search)) {
            $searchTerm = "%{$search}%";
            $stmt->bindParam(":search", $searchTerm);
        }
        
        if(!empty($category)) {
            $stmt->bindParam(":category", $category);
        }
        
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }
    
    // Delete product
    public function delete() {
        // First delete product images
        $this->deleteProductImages();
        
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $this->id);
        
        if($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Get low stock products
    public function getLowStockProducts() {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE quantity <= reorder_level 
                  AND quantity > 0 
                  AND is_active = 1 
                  ORDER BY quantity ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get inventory statistics
    public function getInventoryStats() {
        $query = "SELECT 
                    COUNT(*) as total_products,
                    SUM(quantity) as total_units,
                    SUM(unit_cost * quantity) as total_cost_value,
                    SUM(selling_price * quantity) as total_sell_value,
                    COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock_count,
                    COUNT(CASE WHEN quantity = 0 AND is_active = 1 THEN 1 END) as out_of_stock_count
                  FROM " . $this->table_name . " 
                  WHERE is_draft = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Save product as draft
    public function saveDraft() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET product_name = :product_name,
                      item_code = :item_code,
                      selling_price = :selling_price,
                      is_draft = 1,
                      created_at = NOW()";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":product_name", $this->product_name);
        $stmt->bindParam(":item_code", $this->item_code);
        $stmt->bindParam(":selling_price", $this->selling_price);
        
        if($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }
    
    // Delete product images
    private function deleteProductImages() {
        $query = "DELETE FROM product_images WHERE product_id = :product_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":product_id", $this->id);
        $stmt->execute();
    }
    
    // Get product by barcode
    public function getByBarcode($barcode) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE barcode = :barcode LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":barcode", $barcode);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get products by category
    public function getByCategory($category) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE category = :category AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":category", $category);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>