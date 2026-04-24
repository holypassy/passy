<?php
class UnifiedCatalog extends Model {
    protected $db;
    
    public function __construct($db) {
        parent::__construct($db);
        $this->db = $db;
    }
    
    /**
     * Get all services and products combined
     */
    public function getAllUnified($filters = []) {
        $unified = [];
        
        // Get services
        $services = $this->getServicesForUnified($filters);
        
        // Get products
        $products = $this->getProductsForUnified($filters);
        
        // Merge and sort by name
        $unified = array_merge($services, $products);
        usort($unified, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $unified;
    }
    
    /**
     * Get services formatted for unified view
     */
    private function getServicesForUnified($filters = []) {
        $sql = "SELECT 
                    id,
                    service_name as name,
                    'service' as type,
                    category,
                    standard_price as price,
                    NULL as item_code,
                    NULL as quantity,
                    NULL as reorder_level,
                    NULL as unit_of_measure,
                    estimated_duration as duration_info,
                    track_interval as has_tracking,
                    requires_parts,
                    is_active,
                    created_at,
                    description
                FROM services 
                WHERE is_active = 1";
        
        // Apply filters
        if (!empty($filters['search'])) {
            $sql .= " AND (service_name LIKE :search OR description LIKE :search)";
        }
        
        if (!empty($filters['category']) && $filters['category'] != 'all') {
            if (strpos($filters['category'], 'service_') === 0) {
                $cat = str_replace('service_', '', $filters['category']);
                $sql .= " AND category = :category";
            }
        }
        
        $sql .= " ORDER BY service_name";
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($filters['search'])) {
            $searchTerm = "%{$filters['search']}%";
            $stmt->bindParam(':search', $searchTerm);
        }
        
        if (!empty($filters['category']) && strpos($filters['category'], 'service_') === 0) {
            $cat = str_replace('service_', '', $filters['category']);
            $stmt->bindParam(':category', $cat);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add type-specific formatting
        foreach ($results as &$item) {
            $item['type_icon'] = 'fa-cogs';
            $item['type_color'] = '#3b82f6';
            $item['status_badge'] = $item['has_tracking'] ? 'Tracked Service' : 'Standard Service';
            $item['status_class'] = $item['has_tracking'] ? 'info' : 'secondary';
            $item['details'] = [
                'duration' => $item['duration_info'],
                'requires_parts' => $item['requires_parts']
            ];
        }
        
        return $results;
    }
    
    /**
     * Get products formatted for unified view
     */
    private function getProductsForUnified($filters = []) {
        $sql = "SELECT 
                    id,
                    product_name as name,
                    'product' as type,
                    category,
                    selling_price as price,
                    item_code,
                    quantity,
                    reorder_level,
                    unit_of_measure,
                    NULL as duration_info,
                    NULL as has_tracking,
                    NULL as requires_parts,
                    is_active,
                    created_at,
                    description
                FROM inventory 
                WHERE is_active = 1";
        
        // Apply filters
        if (!empty($filters['search'])) {
            $sql .= " AND (product_name LIKE :search OR item_code LIKE :search OR description LIKE :search)";
        }
        
        if (!empty($filters['category']) && strpos($filters['category'], 'product_') === 0) {
            $cat = str_replace('product_', '', $filters['category']);
            $cat = str_replace('_', ' ', $cat);
            $sql .= " AND category = :category";
        }
        
        if (!empty($filters['stock_status']) && $filters['stock_status'] != 'all') {
            switch($filters['stock_status']) {
                case 'in_stock':
                    $sql .= " AND quantity > reorder_level";
                    break;
                case 'low_stock':
                    $sql .= " AND quantity <= reorder_level AND quantity > 0";
                    break;
                case 'out_of_stock':
                    $sql .= " AND quantity <= 0";
                    break;
            }
        }
        
        $sql .= " ORDER BY product_name";
        
        $stmt = $this->db->prepare($sql);
        
        if (!empty($filters['search'])) {
            $searchTerm = "%{$filters['search']}%";
            $stmt->bindParam(':search', $searchTerm);
        }
        
        if (!empty($filters['category']) && strpos($filters['category'], 'product_') === 0) {
            $cat = str_replace('product_', '', $filters['category']);
            $cat = str_replace('_', ' ', $cat);
            $stmt->bindParam(':category', $cat);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add type-specific formatting
        foreach ($results as &$item) {
            $item['type_icon'] = 'fa-cube';
            $item['type_color'] = '#10b981';
            
            // Determine stock status
            if ($item['quantity'] <= 0) {
                $item['status_badge'] = 'Out of Stock';
                $item['status_class'] = 'danger';
            } elseif ($item['quantity'] <= $item['reorder_level']) {
                $item['status_badge'] = 'Low Stock';
                $item['status_class'] = 'warning';
            } else {
                $item['status_badge'] = 'In Stock';
                $item['status_class'] = 'success';
            }
            
            $item['details'] = [
                'stock' => $item['quantity'],
                'unit' => $item['unit_of_measure'],
                'reorder_level' => $item['reorder_level']
            ];
        }
        
        return $results;
    }
    
    /**
     * Get unified statistics
     */
    public function getUnifiedStats() {
        $stats = [];
        
        // Service stats
        $serviceStats = $this->getServiceStats();
        
        // Product stats
        $productStats = $this->getProductStats();
        
        $stats = [
            'total_items' => ($serviceStats['total'] ?? 0) + ($productStats['total'] ?? 0),
            'total_services' => $serviceStats['total'] ?? 0,
            'total_products' => $productStats['total'] ?? 0,
            'services_revenue' => $serviceStats['total_revenue'] ?? 0,
            'inventory_value' => $productStats['total_value'] ?? 0,
            'low_stock_count' => $productStats['low_stock'] ?? 0,
            'out_of_stock_count' => $productStats['out_of_stock'] ?? 0,
            'tracked_services' => $serviceStats['tracked'] ?? 0,
            'minor_services' => $serviceStats['minor'] ?? 0,
            'major_services' => $serviceStats['major'] ?? 0,
            'total_units' => $productStats['total_units'] ?? 0,
            'needs_attention' => ($productStats['low_stock'] ?? 0) + ($serviceStats['tracked'] ?? 0)
        ];
        
        return $stats;
    }
    
    /**
     * Get service statistics
     */
    private function getServiceStats() {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(standard_price) as total_revenue,
                    SUM(CASE WHEN track_interval = 1 THEN 1 ELSE 0 END) as tracked,
                    SUM(CASE WHEN category = 'Minor' THEN 1 ELSE 0 END) as minor,
                    SUM(CASE WHEN category = 'Major' THEN 1 ELSE 0 END) as major
                FROM services 
                WHERE is_active = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get product statistics
     */
    private function getProductStats() {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(quantity * selling_price) as total_value,
                    SUM(quantity) as total_units,
                    SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock
                FROM inventory 
                WHERE is_active = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Search across both services and products
     */
    public function unifiedSearch($keyword, $type = 'all') {
        $results = [
            'services' => [],
            'products' => [],
            'total' => 0
        ];
        
        if ($type == 'all' || $type == 'services') {
            $serviceSql = "SELECT 
                              id, service_name as name, 'service' as type, category, 
                              standard_price as price, NULL as stock, estimated_duration as extra
                          FROM services 
                          WHERE is_active = 1 
                          AND (service_name LIKE :keyword OR description LIKE :keyword)
                          LIMIT 20";
            
            $stmt = $this->db->prepare($serviceSql);
            $searchTerm = "%{$keyword}%";
            $stmt->bindParam(':keyword', $searchTerm);
            $stmt->execute();
            $results['services'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        if ($type == 'all' || $type == 'products') {
            $productSql = "SELECT 
                              id, product_name as name, 'product' as type, category, 
                              selling_price as price, quantity as stock, item_code as extra
                          FROM inventory 
                          WHERE is_active = 1 
                          AND (product_name LIKE :keyword OR item_code LIKE :keyword OR description LIKE :keyword)
                          LIMIT 20";
            
            $stmt = $this->db->prepare($productSql);
            $searchTerm = "%{$keyword}%";
            $stmt->bindParam(':keyword', $searchTerm);
            $stmt->execute();
            $results['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $results['total'] = count($results['services']) + count($results['products']);
        
        return $results;
    }
    
    /**
     * Get categories for filtering
     */
    public function getUnifiedCategories() {
        $categories = [
            'services' => [],
            'products' => []
        ];
        
        // Get service categories
        $serviceSql = "SELECT DISTINCT category FROM services WHERE is_active = 1";
        $stmt = $this->db->prepare($serviceSql);
        $stmt->execute();
        $categories['services'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get product categories
        $productSql = "SELECT DISTINCT category FROM inventory WHERE is_active = 1 AND category IS NOT NULL AND category != ''";
        $stmt = $this->db->prepare($productSql);
        $stmt->execute();
        $categories['products'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $categories;
    }
    
    /**
     * Export unified catalog to CSV
     */
    public function exportToCSV() {
        $data = $this->getAllUnified();
        
        $filename = 'unified_catalog_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Add headers
        fputcsv($output, ['Type', 'Name', 'Category', 'Price', 'Status', 'Details']);
        
        // Add data
        foreach ($data as $item) {
            fputcsv($output, [
                ucfirst($item['type']),
                $item['name'],
                $item['category'] ?? 'Uncategorized',
                $item['price'],
                $item['status_badge'],
                json_encode($item['details'])
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    /**
     * Get popular items (most used in invoices)
     */
    public function getPopularItems($limit = 10) {
        // This would require invoice tables
        // For now, return recent items
        return $this->getAllUnified(['limit' => $limit]);
    }
    
    /**
     * Bulk update status
     */
    public function bulkUpdateStatus($items, $status) {
        $updated = 0;
        $errors = [];
        
        foreach ($items as $item) {
            try {
                if ($item['type'] == 'service') {
                    $sql = "UPDATE services SET is_active = :status WHERE id = :id";
                } else {
                    $sql = "UPDATE inventory SET is_active = :status WHERE id = :id";
                }
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':status' => $status ? 1 : 0,
                    ':id' => $item['id']
                ]);
                
                $updated++;
            } catch (Exception $e) {
                $errors[] = "Failed to update item ID {$item['id']}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => true,
            'updated' => $updated,
            'errors' => $errors
        ];
    }
}