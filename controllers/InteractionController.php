<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Interaction;
use App\Models\Customer;

class InteractionController extends Controller {
    private $interactionModel;
    private $customerModel;
    
    public function __construct() {
        $this->interactionModel = new Interaction();
        $this->customerModel = new Customer();
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'customer_id' => 'required|numeric',
            'interaction_date' => 'required|date',
            'interaction_type' => 'required',
            'summary' => 'required|min:3'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect("/customers/view/{$data['customer_id']}");
            return;
        }
        
        $data['created_by'] = $this->getCurrentUser();
        $this->interactionModel->create($data);
        
        // Update customer's last contact date
        $this->customerModel->update($data['customer_id'], [
            'last_contact_date' => date('Y-m-d H:i:s'),
            'next_follow_up_date' => $data['follow_up_date'] ?? null
        ]);
        
        $_SESSION['success'] = 'Interaction recorded successfully!';
        $this->redirect("/customers/view/{$data['customer_id']}");
    }
    
    public function getPendingFollowups() {
        $followups = $this->interactionModel->getPendingFollowups();
        $this->json(['success' => true, 'data' => $followups]);
    }
    
    public function getStats() {
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $stats = $this->interactionModel->getInteractionStats($startDate, $endDate);
        $this->json(['success' => true, 'data' => $stats]);
    }
}