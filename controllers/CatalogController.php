<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../models/Product.php';

class CatalogController extends Controller {
    private $serviceModel;
    private $productModel;
    
    public function __construct() {
        parent::__construct();
        $this->serviceModel = new Service();
        $this->productModel = new Product();
    }
    
    public function index() {
        $services = $this->serviceModel->findAll();
        $products = $this->productModel->findAll();
        
        $this->view('catalog/index', [
            'services' => $services,
            'products' => $products
        ]);
    }
    
    public function getUnifiedData() {
        $services = $this->serviceModel->findAll();
        $products = $this->productModel->findAll();
        
        $this->jsonResponse([
            'services' => $services,
            'products' => $products,
            'stats' => [
                'total_services' => count($services),
                'total_products' => count($products),
                'services_revenue' => array_sum(array_column($services, 'standard_price')),
                'inventory_value' => array_sum(array_map(function($p) {
                    return ($p['unit_cost'] ?? 0) * ($p['quantity'] ?? 0);
                }, $products))
            ]
        ]);
    }
    
    public function exportCatalog() {
        $services = $this->serviceModel->findAll();
        $products = $this->productModel->findAll();
        
        $data = [
            'services' => $services,
            'products' => $products,
            'export_date' => date('Y-m-d H:i:s')
        ];
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="catalog_export_' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}