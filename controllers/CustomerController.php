<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Feedback;
use App\Models\Loyalty;
use Utils\CSVExporter;
use Utils\ReportGenerator;

class CustomerController extends Controller {
    private $customerModel;
    private $interactionModel;
    private $feedbackModel;
    private $loyaltyModel;
    
    public function __construct() {
        $this->customerModel = new Customer();
        $this->interactionModel = new Interaction();
        $this->feedbackModel = new Feedback();
        $this->loyaltyModel = new Loyalty();
    }
    
    public function index() {
        $page = $_GET['page'] ?? 1;
        $search = $_GET['search'] ?? '';
        $tier = $_GET['tier'] ?? '';
        $source = $_GET['source'] ?? '';
        
        $conditions = ['status' => 1];
        if ($tier) $conditions['customer_tier'] = $tier;
        if ($source) $conditions['customer_source'] = $source;
        
        if ($search) {
            $customers = $this->customerModel->search($search);
        } else {
            $customers = $this->customerModel->paginate($page, 15, $conditions);
        }
        
        $stats = $this->customerModel->getStatistics();
        $salesReps = $this->getSalesReps();
        
        $this->view('customers/index', [
            'customers' => $customers,
            'stats' => $stats,
            'sales_reps' => $salesReps,
            'search' => $search,
            'tier' => $tier,
            'source' => $source
        ]);
    }
    
    public function create() {
        $salesReps = $this->getSalesReps();
        $this->view('customers/create', ['sales_reps' => $salesReps]);
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'full_name' => 'required|min:2|max:100',
            'telephone' => 'required',
            'email' => 'email'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('/customers/create');
            return;
        }
        
        $data['status'] = 1;
        $customerId = $this->customerModel->create($data);
        
        // Initialize loyalty record
        $this->loyaltyModel->create([
            'customer_id' => $customerId,
            'joined_date' => date('Y-m-d')
        ]);
        
        $_SESSION['success'] = 'Customer added successfully!';
        $this->redirect("/customers/view/{$customerId}");
    }
    
    public function view($id) {
        $customer = $this->customerModel->getWithDetails($id);
        
        if (!$customer) {
            $_SESSION['error'] = 'Customer not found';
            $this->redirect('/customers');
            return;
        }
        
        $interactions = $this->interactionModel->getByCustomer($id);
        $feedback = $this->feedbackModel->getByCustomer($id);
        $loyalty = $this->loyaltyModel->getByCustomer($id);
        
        $this->view('customers/view', [
            'customer' => $customer,
            'interactions' => $interactions,
            'feedback' => $feedback,
            'loyalty' => $loyalty
        ]);
    }
    
    public function edit($id) {
        $customer = $this->customerModel->find($id);
        
        if (!$customer) {
            $_SESSION['error'] = 'Customer not found';
            $this->redirect('/customers');
            return;
        }
        
        $salesReps = $this->getSalesReps();
        $this->view('customers/edit', [
            'customer' => $customer,
            'sales_reps' => $salesReps
        ]);
    }
    
    public function update($id) {
        $data = $this->sanitize($_POST);
        
        $this->customerModel->update($id, $data);
        
        // Update tier based on spending
        $this->loyaltyModel->updateTier($id);
        
        $_SESSION['success'] = 'Customer updated successfully!';
        $this->redirect("/customers/view/{$id}");
    }
    
    public function delete($id) {
        $this->customerModel->softDelete($id);
        $_SESSION['success'] = 'Customer deleted successfully!';
        $this->redirect('/customers');
    }
    
    public function export() {
        $format = $_GET['format'] ?? 'csv';
        $customers = $this->customerModel->all(['status' => 1], 'full_name');
        
        $exporter = new CSVExporter();
        
        if ($format === 'csv') {
            $exporter->exportCustomers($customers);
        } else {
            $exporter->exportCustomersExcel($customers);
        }
    }
    
    public function report() {
        $type = $_GET['type'] ?? 'summary';
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-t');
        
        $reportGenerator = new ReportGenerator();
        
        switch($type) {
            case 'summary':
                $data = $this->customerModel->getStatistics();
                $reportGenerator->generateCustomerSummary($data, $startDate, $endDate);
                break;
            case 'detailed':
                $customers = $this->customerModel->all(['status' => 1], 'full_name');
                $reportGenerator->generateCustomerDetailed($customers);
                break;
            case 'loyalty':
                $topCustomers = $this->loyaltyModel->getTopLoyaltyCustomers(50);
                $reportGenerator->generateLoyaltyReport($topCustomers);
                break;
        }
    }
    
    private function getSalesReps() {
        try {
            $db = \Core\Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'manager') AND is_active = 1");
            return $stmt->fetchAll();
        } catch(\PDOException $e) {
            return [];
        }
    }
}