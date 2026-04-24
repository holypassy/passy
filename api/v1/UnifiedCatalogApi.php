<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../config/database.php';
require_once '../../core/Model.php';
require_once '../../models/UnifiedCatalog.php';
require_once '../../models/Service.php';
require_once '../../models/Inventory.php';

class UnifiedCatalogApi {
    private $unifiedModel;
    private $serviceModel;
    private $productModel;
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->unifiedModel = new UnifiedCatalog($this->db);
        $this->serviceModel = new Service($this->db);
        $this->productModel = new Inventory($this->db);
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = explode('/', trim($_GET['path'] ?? '', '/'));
        $endpoint = $path[0] ?? '';
        $id = $path[1] ?? null;
        
        switch($method) {
            case 'GET':
                $this->handleGet($endpoint, $id);
                break;
            case 'POST':
                $this->handlePost($endpoint);
                break;
            case 'PUT':
                $this->handlePut($endpoint, $id);
                break;
            case 'DELETE':
                $this->handleDelete($endpoint, $id);
                break;
            default:
                $this->json(['error' => 'Method not allowed'], 405);
        }
    }
    
    private function handleGet($endpoint, $id) {
        switch($endpoint) {
            case '':
            case 'all':
                $this->getAllUnified();
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'search':
                $this->search();
                break;
            case 'categories':
                $this->getCategories();
                break;
            case 'report':
                $this->getReport();
                break;
            case 'widgets':
                $this->getWidgets();
                break;
            case 'export':
                $this->export();
                break;
            case 'item':
                if ($id) {
                    $this->getItem($id);
                } else {
                    $this->json(['error' => 'Item ID required'], 400);
                }
                break;
            default:
                $this->json(['error' => 'Invalid endpoint'], 404);
        }
    }
    
    private function handlePost($endpoint) {
        switch($endpoint) {
            case 'bulk-update':
                $this->bulkUpdate();
                break;
            case 'quick-add':
                $this->quickAdd();
                break;
            default:
                $this->json(['error' => 'Invalid endpoint'], 404);
        }
    }
    
    private function handlePut($endpoint, $id) {
        switch($endpoint) {
            case 'item':
                if ($id) {
                    $this->updateItem($id);
                } else {
                    $this->json(['error' => 'Item ID required'], 400);
                }
                break;
            default:
                $this->json(['error' => 'Invalid endpoint'], 404);
        }
    }
    
    private function handleDelete($endpoint, $id) {
        switch($endpoint) {
            case 'item':
                if ($id) {
                    $this->deleteItem($id);
                } else {
                    $this->json(['error' => 'Item ID required'], 400);
                }
                break;
            default:
                $this->json(['error' => 'Invalid endpoint'], 404);
        }
    }
    
    private function getAllUnified() {
        $filters = [
            'search' => $_GET['search'] ?? '',
            'category' => $_GET['category'] ?? 'all',
            'stock_status' => $_GET['stock_status'] ?? 'all',
            'type' => $_GET['type'] ?? 'all',
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => (int)($_GET['limit'] ?? 50)
        ];
        
        $items = $this->unifiedModel->getAllUnified($filters);
        $stats = $this->unifiedModel->getUnifiedStats();
        
        // Paginate results
        $offset = ($filters['page'] - 1) * $filters['limit'];
        $paginatedItems = array_slice($items, $offset, $filters['limit']);
        
        $this->json([
            'success' => true,
            'data' => $paginatedItems,
            'pagination' => [
                'current_page' => $filters['page'],
                'per_page' => $filters['limit'],
                'total' => count($items),
                'total_pages' => ceil(count($items) / $filters['limit'])
            ],
            'filters' => $filters,
            'stats' => $stats
        ]);
    }
    
    private function getStats() {
        $stats = $this->unifiedModel->getUnifiedStats();
        
        // Add additional metrics
        $stats['percentage_breakdown'] = [
            'services_percentage' => $stats['total_items'] > 0 ? 
                round(($stats['total_services'] / $stats['total_items']) * 100, 2) : 0,
            'products_percentage' => $stats['total_items'] > 0 ? 
                round(($stats['total_products'] / $stats['total_items']) * 100, 2) : 0
        ];
        
        $stats['value_breakdown'] = [
            'services_value_percentage' => ($stats['services_revenue'] + $stats['inventory_value']) > 0 ?
                round(($stats['services_revenue'] / ($stats['services_revenue'] + $stats['inventory_value'])) * 100, 2) : 0,
            'products_value_percentage' => ($stats['services_revenue'] + $stats['inventory_value']) > 0 ?
                round(($stats['inventory_value'] / ($stats['services_revenue'] + $stats['inventory_value'])) * 100, 2) : 0
        ];
        
        $this->json([
            'success' => true,
            'stats' => $stats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function search() {
        $keyword = $_GET['q'] ?? '';
        $type = $_GET['type'] ?? 'all';
        
        if (empty($keyword)) {
            $this->json(['error' => 'Search keyword is required'], 400);
            return;
        }
        
        if (strlen($keyword) < 2) {
            $this->json(['error' => 'Search keyword must be at least 2 characters'], 400);
            return;
        }
        
        $results = $this->unifiedModel->unifiedSearch($keyword, $type);
        
        $this->json([
            'success' => true,
            'results' => $results,
            'keyword' => $keyword,
            'total' => $results['total']
        ]);
    }
    
    private function getCategories() {
        $categories = $this->unifiedModel->getUnifiedCategories();
        
        $formattedCategories = [
            'all' => 'All Categories',
            'services' => 'Services Only',
            'products' => 'Products Only'
        ];
        
        foreach ($categories['services'] as $cat) {
            $formattedCategories["service_{$cat}"] = "Service: {$cat}";
        }
        
        foreach ($categories['products'] as $cat) {
            if (!empty($cat)) {
                $key = "product_" . strtolower(str_replace(' ', '_', $cat));
                $formattedCategories[$key] = "Product: {$cat}";
            }
        }
        
        $this->json([
            'success' => true,
            'categories' => $formattedCategories,
            'raw' => $categories
        ]);
    }
    
    private function getReport() {
        $serviceStats = $this->serviceModel->getStats();
        $productStats = $this->productModel->getStats();
        
        $report = [
            'summary' => [
                'total_items' => ($serviceStats['total'] ?? 0) + ($productStats['total_products'] ?? 0),
                'total_value' => ($serviceStats['total_revenue'] ?? 0) + ($productStats['total_inventory_value'] ?? 0),
                'active_items' => ($serviceStats['total'] ?? 0) + ($productStats['total_products'] ?? 0)
            ],
            'services' => [
                'count' => $serviceStats['total'] ?? 0,
                'value' => $serviceStats['total_revenue'] ?? 0,
                'breakdown' => [
                    'minor' => $serviceStats['minor_count'] ?? 0,
                    'major' => $serviceStats['major_count'] ?? 0,
                    'tracked' => $serviceStats['tracked_count'] ?? 0
                ]
            ],
            'products' => [
                'count' => $productStats['total_products'] ?? 0,
                'value' => $productStats['total_inventory_value'] ?? 0,
                'units' => $productStats['total_units'] ?? 0,
                'breakdown' => [
                    'low_stock' => $productStats['low_stock_count'] ?? 0,
                    'out_of_stock' => $productStats['out_of_stock_count'] ?? 0,
                    'healthy_stock' => ($productStats['total_products'] ?? 0) - 
                                       (($productStats['low_stock_count'] ?? 0) + ($productStats['out_of_stock_count'] ?? 0))
                ]
            ],
            'charts' => [
                'type_distribution' => [
                    'labels' => ['Services', 'Products'],
                    'data' => [$serviceStats['total'] ?? 0, $productStats['total_products'] ?? 0]
                ],
                'value_distribution' => [
                    'labels' => ['Services Revenue', 'Inventory Value'],
                    'data' => [$serviceStats['total_revenue'] ?? 0, $productStats['total_inventory_value'] ?? 0]
                ]
            ]
        ];
        
        $this->json([
            'success' => true,
            'report' => $report,
            'generated_at' => date('c')
        ]);
    }
    
    private function getWidgets() {
        $stats = $this->unifiedModel->getUnifiedStats();
        
        // Get recent items
        $recentServices = $this->serviceModel->getAllActive();
        $recentProducts = $this->productModel->getAllActive();
        
        $recentItems = array_merge($recentServices, $recentProducts);
        usort($recentItems, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        $recentItems = array_slice($recentItems, 0, 10);
        
        // Format recent items
        $formattedRecent = [];
        foreach ($recentItems as $item) {
            $formattedRecent[] = [
                'id' => $item['id'],
                'name' => $item['service_name'] ?? $item['product_name'],
                'type' => isset($item['service_name']) ? 'service' : 'product',
                'price' => $item['standard_price'] ?? $item['selling_price'],
                'created_at' => $item['created_at']
            ];
        }
        
        $widgets = [
            'quick_stats' => [
                ['label' => 'Total Items', 'value' => $stats['total_items'], 'icon' => 'fa-cubes', 'color' => 'primary'],
                ['label' => 'Services', 'value' => $stats['total_services'], 'icon' => 'fa-cogs', 'color' => 'info'],
                ['label' => 'Products', 'value' => $stats['total_products'], 'icon' => 'fa-cube', 'color' => 'success'],
                ['label' => 'Portfolio Value', 'value' => 'UGX ' . number_format($stats['services_revenue'] + $stats['inventory_value']), 'icon' => 'fa-chart-line', 'color' => 'warning']
            ],
            'alerts' => [
                'low_stock' => $this->productModel->getLowStockItems(),
                'tracked_services' => $this->serviceModel->getTrackedServices()
            ],
            'recent_items' => $formattedRecent,
            'trends' => [
                'services_vs_products' => [
                    'services' => $stats['total_services'],
                    'products' => $stats['total_products']
                ]
            ]
        ];
        
        $this->json([
            'success' => true,
            'widgets' => $widgets
        ]);
    }
    
    private function getItem($id) {
        // Try to find in services first
        $service = $this->serviceModel->getById($id);
        if ($service) {
            $this->json([
                'success' => true,
                'type' => 'service',
                'data' => $service
            ]);
            return;
        }
        
        // Try products
        $product = $this->productModel->getById($id);
        if ($product) {
            $this->json([
                'success' => true,
                'type' => 'product',
                'data' => $product
            ]);
            return;
        }
        
        $this->json(['error' => 'Item not found'], 404);
    }
    
    private function bulkUpdate() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['items']) || !isset($input['action'])) {
            $this->json(['error' => 'Items and action are required'], 400);
            return;
        }
        
        if (!is_array($input['items']) || empty($input['items'])) {
            $this->json(['error' => 'Items must be a non-empty array'], 400);
            return;
        }
        
        $result = ['success' => false, 'updated' => 0, 'errors' => []];
        
        foreach ($input['items'] as $item) {
            if (!isset($item['id']) || !isset($item['type'])) {
                $result['errors'][] = 'Invalid item format';
                continue;
            }
            
            try {
                switch($input['action']) {
                    case 'activate':
                        if ($item['type'] == 'service') {
                            $this->serviceModel->update($item['id'], ['is_active' => 1]);
                        } else {
                            $this->productModel->update($item['id'], ['is_active' => 1]);
                        }
                        $result['updated']++;
                        break;
                        
                    case 'deactivate':
                        if ($item['type'] == 'service') {
                            $this->serviceModel->delete($item['id']);
                        } else {
                            $this->productModel->delete($item['id']);
                        }
                        $result['updated']++;
                        break;
                        
                    case 'delete':
                        if ($item['type'] == 'service') {
                            $this->serviceModel->delete($item['id']);
                        } else {
                            $this->productModel->delete($item['id']);
                        }
                        $result['updated']++;
                        break;
                }
            } catch (Exception $e) {
                $result['errors'][] = "Failed to update item {$item['id']}: " . $e->getMessage();
            }
        }
        
        $result['success'] = true;
        $result['message'] = "{$result['updated']} items updated successfully";
        
        $this->json($result);
    }
    
    private function quickAdd() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['type']) || !isset($input['data'])) {
            $this->json(['error' => 'Type and data are required'], 400);
            return;
        }
        
        try {
            if ($input['type'] == 'service') {
                $id = $this->serviceModel->create($input['data']);
                $this->json([
                    'success' => true,
                    'message' => 'Service added successfully',
                    'id' => $id,
                    'type' => 'service'
                ], 201);
            } elseif ($input['type'] == 'product') {
                $id = $this->productModel->create($input['data']);
                $this->json([
                    'success' => true,
                    'message' => 'Product added successfully',
                    'id' => $id,
                    'type' => 'product'
                ], 201);
            } else {
                $this->json(['error' => 'Invalid type. Must be "service" or "product"'], 400);
            }
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    private function updateItem($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['type']) || !isset($input['data'])) {
            $this->json(['error' => 'Type and data are required'], 400);
            return;
        }
        
        try {
            if ($input['type'] == 'service') {
                $this->serviceModel->update($id, $input['data']);
                $this->json([
                    'success' => true,
                    'message' => 'Service updated successfully'
                ]);
            } elseif ($input['type'] == 'product') {
                $this->productModel->update($id, $input['data']);
                $this->json([
                    'success' => true,
                    'message' => 'Product updated successfully'
                ]);
            } else {
                $this->json(['error' => 'Invalid type'], 400);
            }
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    private function deleteItem($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? $_GET['type'] ?? null;
        
        if (!$type) {
            $this->json(['error' => 'Item type is required'], 400);
            return;
        }
        
        try {
            if ($type == 'service') {
                $this->serviceModel->delete($id);
            } elseif ($type == 'product') {
                $this->productModel->delete($id);
            } else {
                $this->json(['error' => 'Invalid type'], 400);
                return;
            }
            
            $this->json([
                'success' => true,
                'message' => 'Item deleted successfully'
            ]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }
    
    private function export() {
        $format = $_GET['format'] ?? 'csv';
        $filters = [
            'search' => $_GET['search'] ?? '',
            'category' => $_GET['category'] ?? 'all'
        ];
        
        $items = $this->unifiedModel->getAllUnified($filters);
        
        if ($format == 'json') {
            $this->json([
                'success' => true,
                'data' => $items,
                'exported_at' => date('c'),
                'total' => count($items)
            ]);
        } else {
            // CSV export
            $filename = 'unified_catalog_' . date('Y-m-d_His') . '.csv';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for Excel compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($output, ['Type', 'Name', 'Category', 'Price', 'Status', 'Details', 'Created At']);
            
            // Data
            foreach ($items as $item) {
                fputcsv($output, [
                    ucfirst($item['type']),
                    $item['name'],
                    $item['category'] ?? 'Uncategorized',
                    $item['price'],
                    $item['status_badge'],
                    is_array($item['details']) ? json_encode($item['details']) : $item['details'],
                    $item['created_at']
                ]);
            }
            
            fclose($output);
        }
        exit();
    }
    
    private function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
}

// Handle the request
$api = new UnifiedCatalogApi();
$api->handleRequest();
?>