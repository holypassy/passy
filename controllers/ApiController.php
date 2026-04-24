<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Feedback;
use App\Models\Loyalty;

class ApiController extends Controller {
    private $customerModel;
    private $interactionModel;
    private $feedbackModel;
    private $loyaltyModel;
    
    public function __construct() {
        session_start();
        
        // Allow API access without full auth but require API key
        $this->customerModel = new Customer();
        $this->interactionModel = new Interaction();
        $this->feedbackModel = new Feedback();
        $this->loyaltyModel = new Loyalty();
    }
    
    public function getCustomers() {
        $filters = [
            'search' => $_GET['search'] ?? '',
            'tier' => $_GET['tier'] ?? '',
            'source' => $_GET['source'] ?? '',
            'limit' => $_GET['limit'] ?? 100
        ];
        
        $customers = $this->customerModel->getAllWithDetails($filters);
        $stats = $this->customerModel->getCustomerStats();
        
        $this->json([
            'success' => true,
            'data' => $customers,
            'stats' => $stats,
            'total' => count($customers)
        ]);
    }
    
    public function getCustomer($params) {
        $id = $params['id'] ?? 0;
        $customer = $this->customerModel->find($id);
        
        if (!$customer) {
            $this->json(['success' => false, 'error' => 'Customer not found'], 404);
            return;
        }
        
        $interactions = $this->interactionModel->getByCustomer($id);
        $feedback = $this->feedbackModel->getByCustomer($id);
        $loyalty = $this->loyaltyModel->find($id);
        
        $this->json([
            'success' => true,
            'data' => [
                'customer' => $customer,
                'interactions' => $interactions,
                'feedback' => $feedback,
                'loyalty' => $loyalty
            ]
        ]);
    }
    
    public function createCustomer() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['full_name'])) {
            $this->json(['success' => false, 'error' => 'Full name is required'], 400);
            return;
        }
        
        $data = [
            'full_name' => $input['full_name'],
            'telephone' => $input['telephone'] ?? null,
            'email' => $input['email'] ?? null,
            'address' => $input['address'] ?? null,
            'tax_id' => $input['tax_id'] ?? null,
            'credit_limit' => $input['credit_limit'] ?? 0,
            'preferred_contact' => $input['preferred_contact'] ?? 'phone',
            'customer_source' => $input['customer_source'] ?? null,
            'notes' => $input['notes'] ?? null,
            'assigned_sales_rep' => $input['assigned_sales_rep'] ?? null,
            'customer_tier' => $input['customer_tier'] ?? 'bronze'
        ];
        
        try {
            $customerId = $this->customerModel->create($data);
            $this->loyaltyModel->create(['customer_id' => $customerId]);
            
            $this->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'customer_id' => $customerId
            ], 201);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    public function updateCustomer($params) {
        $id = $params['id'] ?? 0;
        $input = json_decode(file_get_contents('php://input'), true);
        
        try {
            $this->customerModel->update($id, $input);
            $this->json([
                'success' => true,
                'message' => 'Customer updated successfully'
            ]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    public function deleteCustomer($params) {
        $id = $params['id'] ?? 0;
        
        try {
            $this->customerModel->update($id, ['status' => 0]);
            $this->json([
                'success' => true,
                'message' => 'Customer deactivated successfully'
            ]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    public function getDashboardStats() {
        $stats = $this->customerModel->getCustomerStats();
        $pendingFollowups = $this->customerModel->getPendingFollowups();
        $avgRating = $this->feedbackModel->getAverageRating();
        $topCustomers = $this->loyaltyModel->getTopCustomers(5);
        $ratingDistribution = $this->feedbackModel->getRatingDistribution();
        
        $this->json([
            'success' => true,
            'data' => [
                'customer_stats' => $stats,
                'pending_followups' => $pendingFollowups,
                'average_rating' => $avgRating,
                'top_customers' => $topCustomers,
                'rating_distribution' => $ratingDistribution
            ]
        ]);
    }
    
    public function addLoyaltyPoints() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $customerId = $input['customer_id'] ?? 0;
        $points = $input['points'] ?? 0;
        
        if (!$customerId || !$points) {
            $this->json(['success' => false, 'error' => 'Customer ID and points are required'], 400);
            return;
        }
        
        try {
            $this->customerModel->addLoyaltyPoints($customerId, $points);
            $this->json([
                'success' => true,
                'message' => "{$points} loyalty points added successfully"
            ]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    public function getInteractions($params) {
        $customerId = $params['customer_id'] ?? 0;
        
        if (!$customerId) {
            $this->json(['success' => false, 'error' => 'Customer ID required'], 400);
            return;
        }
        
        $interactions = $this->interactionModel->getByCustomer($customerId, 50);
        
        $this->json([
            'success' => true,
            'data' => $interactions,
            'total' => count($interactions)
        ]);
    }
    
    public function getUpcomingFollowups() {
        $days = $_GET['days'] ?? 7;
        $followups = $this->interactionModel->getUpcomingFollowups($days);
        
        $this->json([
            'success' => true,
            'data' => $followups,
            'days' => $days
        ]);
    }
}
?>