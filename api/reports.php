<?php
require_once __DIR__ . '/../controllers/ReportController.php';

$controller = new ReportController();
$action = $_GET['action'] ?? '';

switch ($action) {
    // Dashboard endpoints
    case 'dashboard':
        $controller->getDashboard();
        break;
    
    // Financial reports
    case 'profit-loss':
        $controller->getProfitLoss();
        break;
    case 'expenses':
        $controller->getExpenses();
        break;
    
    // Inventory reports
    case 'inventory':
        $controller->getInventory();
        break;
    
    // Purchase reports
    case 'purchases':
        $controller->getPurchases();
        break;
    
    // Receivables reports
    case 'receivables':
        $controller->getReceivables();
        break;
    
    // Analytics endpoints
    case 'monthly-trends':
        $controller->getMonthlyTrends();
        break;
    case 'top-customers':
        $controller->getTopCustomers();
        break;
    case 'performance':
        $controller->getPerformanceMetrics();
        break;
    
    // Export endpoints
    case 'export':
        $controller->exportReport();
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>