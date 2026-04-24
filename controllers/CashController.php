<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\CashTransaction;
use App\Models\CashAccount;
use App\Models\Category;
use Utils\CurrencyFormatter;
use Utils\ExcelExporter;
use Utils\PDFGenerator;

class CashController extends Controller {
    private $transactionModel;
    private $accountModel;
    
    public function __construct() {
        $this->transactionModel = new CashTransaction();
        $this->accountModel = new CashAccount();
    }
    
    public function index() {
        $filters = [
            'account_id' => $_GET['account'] ?? null,
            'transaction_type' => $_GET['type'] ?? null,
            'date_from' => $_GET['from'] ?? null,
            'date_to' => $_GET['to'] ?? null,
            'category' => $_GET['category'] ?? null
        ];
        
        $page = $_GET['page'] ?? 1;
        $transactions = $this->transactionModel->getTransactions($filters, $page, 20);
        $accounts = $this->accountModel->getActiveAccounts();
        $summary = $this->transactionModel->getSummary(
            date('Y-m-01'),
            date('Y-m-t')
        );
        $weeklyData = $this->transactionModel->getWeeklyTrend(7);
        $monthlyData = $this->transactionModel->getMonthlyTrend(6);
        $categoryData = $this->transactionModel->getByCategory(
            date('Y-m-01'),
            date('Y-m-t')
        );
        
        $this->view('cash/index', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'summary' => $summary,
            'weeklyData' => $weeklyData,
            'monthlyData' => $monthlyData,
            'categoryData' => $categoryData,
            'filters' => $filters
        ]);
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'transaction_date' => 'required|date',
            'transaction_type' => 'required',
            'account_id' => 'required|numeric',
            'amount' => 'required|numeric'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('/cash');
            return;
        }
        
        $data['created_by'] = $this->getCurrentUser();
        $data['status'] = 'approved';
        
        $transactionId = $this->transactionModel->createTransaction($data);
        
        // Update account balance
        $this->accountModel->updateBalance(
            $data['account_id'],
            $data['amount'],
            $data['transaction_type']
        );
        
        $_SESSION['success'] = 'Transaction recorded successfully';
        $this->redirect('/cash');
    }
    
    public function view($id) {
        $transaction = $this->transactionModel->getWithDetails($id);
        
        if (!$transaction) {
            $_SESSION['error'] = 'Transaction not found';
            $this->redirect('/cash');
            return;
        }
        
        $this->view('cash/view', ['transaction' => $transaction]);
    }
    
    public function delete($id) {
        $transaction = $this->transactionModel->find($id);
        
        if (!$transaction) {
            $this->json(['error' => 'Transaction not found'], 404);
            return;
        }
        
        // Reverse account balance
        $reverseType = $transaction['transaction_type'] === 'income' ? 'expense' : 'income';
        $this->accountModel->updateBalance(
            $transaction['account_id'],
            $transaction['amount'],
            $reverseType
        );
        
        $this->transactionModel->delete($id);
        
        $this->json(['success' => true, 'message' => 'Transaction deleted']);
    }
    
    public function export() {
        $format = $_GET['format'] ?? 'csv';
        $filters = [
            'account_id' => $_GET['account'] ?? null,
            'transaction_type' => $_GET['type'] ?? null,
            'date_from' => $_GET['from'] ?? null,
            'date_to' => $_GET['to'] ?? null
        ];
        
        $transactions = $this->transactionModel->getTransactions($filters, 1, 10000);
        
        $exporter = new ExcelExporter();
        
        if ($format === 'csv') {
            $exporter->exportTransactions($transactions['data']);
        } elseif ($format === 'pdf') {
            $pdf = new PDFGenerator();
            $pdf->generateTransactionReport($transactions['data'], $filters);
        }
    }
    
    public function report() {
        $type = $_GET['type'] ?? 'summary';
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        
        $transactions = $this->transactionModel->getTransactions([
            'date_from' => $startDate,
            'date_to' => $endDate
        ], 1, 10000);
        
        $summary = $this->transactionModel->getSummary($startDate, $endDate);
        $categoryData = $this->transactionModel->getByCategory($startDate, $endDate);
        
        $this->view('cash/reports', [
            'transactions' => $transactions['data'],
            'summary' => $summary,
            'categoryData' => $categoryData,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reportType' => $type
        ]);
    }
}