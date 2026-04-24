<?php
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/ExportHelper.php';

class ReportController {
    private $reportModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->reportModel = new Report();
    }
    
    // ==================== DASHBOARD ENDPOINTS ====================
    
    public function getDashboard() {
        AuthMiddleware::authenticate();
        
        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
        
        // Validate dates
        if (!Validator::validateDate($startDate) || !Validator::validateDate($endDate)) {
            Response::json(['success' => false, 'message' => 'Invalid date format'], 400);
        }
        
        if ($startDate > $endDate) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }
        
        $jobStats = $this->reportModel->getJobStatistics($startDate, $endDate);
        $financialStats = $this->reportModel->getFinancialSummary($startDate, $endDate);
        $invoiceStats = $this->reportModel->getInvoiceSummary($startDate, $endDate);
        $dailyTrends = $this->reportModel->getDailyTrends($startDate, $endDate);
        $categoryBreakdown = $this->reportModel->getCategoryBreakdown($startDate, $endDate);
        $topCustomers = $this->reportModel->getTopCustomers($startDate, $endDate, 5);
        $performanceMetrics = $this->reportModel->getPerformanceMetrics($startDate, $endDate);
        
        Response::json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'job_statistics' => $jobStats,
                'financial_summary' => $financialStats,
                'invoice_summary' => $invoiceStats,
                'daily_trends' => $dailyTrends,
                'category_breakdown' => $categoryBreakdown,
                'top_customers' => $topCustomers,
                'performance_metrics' => $performanceMetrics
            ]
        ]);
    }
    
    // ==================== FINANCIAL REPORTS ====================
    
    public function getProfitLoss() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        if (!Validator::validateDate($startDate) || !Validator::validateDate($endDate)) {
            Response::json(['success' => false, 'message' => 'Invalid date format'], 400);
        }
        
        $plStatement = $this->reportModel->getProfitLossStatement($startDate, $endDate);
        $summary = $this->reportModel->getFinancialSummary($startDate, $endDate);
        
        // Calculate totals
        $totalRevenue = 0;
        $totalExpenses = 0;
        $revenueItems = [];
        $expenseItems = [];
        
        foreach ($plStatement as $item) {
            if ($item['section'] == 'Revenue') {
                $totalRevenue += $item['amount'];
                $revenueItems[] = $item;
            } else {
                $totalExpenses += $item['amount'];
                $expenseItems[] = $item;
            }
        }
        
        Response::json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_expenses' => $totalExpenses,
                    'net_profit' => $totalRevenue - $totalExpenses,
                    'profit_margin' => $totalRevenue > 0 ? round(($totalRevenue - $totalExpenses) / $totalRevenue * 100, 2) : 0
                ],
                'revenue' => $revenueItems,
                'expenses' => $expenseItems
            ]
        ]);
    }
    
    public function getExpenses() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $expenses = $this->reportModel->getExpensesReport($startDate, $endDate);
        $summary = $this->reportModel->getFinancialSummary($startDate, $endDate);
        
        // Group by category
        $categorySummary = [];
        foreach ($expenses as $expense) {
            $cat = $expense['category'] ?? 'Uncategorized';
            if (!isset($categorySummary[$cat])) {
                $categorySummary[$cat] = 0;
            }
            $categorySummary[$cat] += $expense['amount'];
        }
        
        Response::json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_expenses' => $summary['total_expenses'],
                    'expense_count' => count($expenses)
                ],
                'category_summary' => $categorySummary,
                'transactions' => $expenses
            ]
        ]);
    }
    
    // ==================== INVENTORY REPORTS ====================
    
    public function getInventory() {
        AuthMiddleware::authenticate();
        
        $inventory = $this->reportModel->getInventoryReport();
        
        // Calculate summary
        $totalValue = 0;
        $lowStockCount = 0;
        $outOfStockCount = 0;
        
        foreach ($inventory as $item) {
            $totalValue += $item['stock_value'];
            if ($item['stock_status'] == 'Low Stock') $lowStockCount++;
            if ($item['current_stock'] == 0) $outOfStockCount++;
        }
        
        Response::json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_products' => count($inventory),
                    'total_value' => $totalValue,
                    'low_stock_items' => $lowStockCount,
                    'out_of_stock_items' => $outOfStockCount
                ],
                'items' => $inventory
            ]
        ]);
    }
    
    // ==================== PURCHASE REPORTS ====================
    
    public function getPurchases() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $purchases = $this->reportModel->getPurchasesReport($startDate, $endDate);
        
        // Calculate summary
        $totalPurchases = 0;
        $paidCount = 0;
        $pendingCount = 0;
        
        foreach ($purchases as $purchase) {
            $totalPurchases += $purchase['total_amount'];
            if ($purchase['payment_status'] == 'paid') {
                $paidCount++;
            } else {
                $pendingCount++;
            }
        }
        
        Response::json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_purchases' => $totalPurchases,
                    'total_orders' => count($purchases),
                    'paid_orders' => $paidCount,
                    'pending_orders' => $pendingCount
                ],
                'transactions' => $purchases
            ]
        ]);
    }
    
    // ==================== RECEIVABLES REPORT ====================
    
    public function getReceivables() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $receivables = $this->reportModel->getReceivablesReport($startDate, $endDate);
        
        // Calculate summary
        $totalReceivables = 0;
        $overdueCount = 0;
        $dueSoonCount = 0;
        
        foreach ($receivables as $item) {
            $totalReceivables += $item['balance_due'];
            if ($item['status_display'] == 'Overdue') $overdueCount++;
            if ($item['status_display'] == 'Due Soon') $dueSoonCount++;
        }
        
        Response::json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'summary' => [
                    'total_receivables' => $totalReceivables,
                    'total_invoices' => count($receivables),
                    'overdue_invoices' => $overdueCount,
                    'due_soon_invoices' => $dueSoonCount
                ],
                'invoices' => $receivables
            ]
        ]);
    }
    
    // ==================== ANALYTICS ENDPOINTS ====================
    
    public function getMonthlyTrends() {
        AuthMiddleware::authenticate();
        
        $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
        $monthlyData = $this->reportModel->getMonthlyComparison($year);
        
        Response::json([
            'success' => true,
            'data' => [
                'year' => $year,
                'months' => $monthlyData
            ]
        ]);
    }
    
    public function getTopCustomers() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        $customers = $this->reportModel->getTopCustomers($startDate, $endDate, $limit);
        
        Response::json([
            'success' => true,
            'data' => $customers
        ]);
    }
    
    public function getPerformanceMetrics() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $metrics = $this->reportModel->getPerformanceMetrics($startDate, $endDate);
        $jobStats = $this->reportModel->getJobStatistics($startDate, $endDate);
        
        Response::json([
            'success' => true,
            'data' => [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'completion' => [
                    'avg_completion_hours' => round($metrics['avg_completion_hours'] ?? 0, 1),
                    'completion_rate' => $jobStats['total_jobs'] > 0 
                        ? round(($jobStats['completed_jobs'] / $jobStats['total_jobs']) * 100, 1) 
                        : 0
                ],
                'delivery' => [
                    'on_time' => $metrics['on_time_delivery'] ?? 0,
                    'overdue' => $metrics['overdue_jobs'] ?? 0
                ]
            ]
        ]);
    }
    
    // ==================== EXPORT ENDPOINTS ====================
    
    public function exportReport() {
        AuthMiddleware::authenticate();
        
        $type = $_GET['type'] ?? 'dashboard';
        $format = $_GET['format'] ?? 'csv';
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $data = [];
        $filename = "report_{$type}_{$startDate}_{$endDate}";
        
        switch ($type) {
            case 'profit_loss':
                $data = $this->reportModel->getProfitLossStatement($startDate, $endDate);
                break;
            case 'expenses':
                $data = $this->reportModel->getExpensesReport($startDate, $endDate);
                break;
            case 'inventory':
                $data = $this->reportModel->getInventoryReport();
                $filename = "inventory_report";
                break;
            case 'purchases':
                $data = $this->reportModel->getPurchasesReport($startDate, $endDate);
                break;
            case 'receivables':
                $data = $this->reportModel->getReceivablesReport($startDate, $endDate);
                break;
            default:
                $data = [];
        }
        
        if ($format === 'csv') {
            ExportHelper::toCSV($data, $filename . '.csv');
        } elseif ($format === 'excel') {
            ExportHelper::toExcel($data, $filename . '.xls');
        } else {
            Response::json(['success' => false, 'message' => 'Unsupported format'], 400);
        }
    }
}
?>