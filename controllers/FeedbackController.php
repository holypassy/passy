<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Feedback;
use App\Models\Customer;
use Utils\EmailSender;

class FeedbackController extends Controller {
    private $feedbackModel;
    private $customerModel;
    private $emailSender;
    
    public function __construct() {
        $this->feedbackModel = new Feedback();
        $this->customerModel = new Customer();
        $this->emailSender = new EmailSender();
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'customer_id' => 'required|numeric',
            'rating' => 'required|numeric',
            'feedback_text' => 'required|min:3'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect("/customers/view/{$data['customer_id']}");
            return;
        }
        
        $data['feedback_date'] = date('Y-m-d');
        $this->feedbackModel->create($data);
        
        // If rating is low (1-2), send alert to admin
        if ($data['rating'] <= 2) {
            $this->sendLowRatingAlert($data['customer_id'], $data['rating'], $data['feedback_text']);
        }
        
        $_SESSION['success'] = 'Feedback recorded successfully!';
        $this->redirect("/customers/view/{$data['customer_id']}");
    }
    
    public function getRatingDistribution() {
        $distribution = $this->feedbackModel->getRatingDistribution();
        $avgRating = $this->feedbackModel->getAverageRating();
        
        $this->json([
            'success' => true,
            'data' => [
                'distribution' => $distribution,
                'average' => $avgRating
            ]
        ]);
    }
    
    public function getLowRatingFeedback() {
        $threshold = $_GET['threshold'] ?? 3;
        $feedback = $this->feedbackModel->getLowRatingFeedback($threshold);
        $this->json(['success' => true, 'data' => $feedback]);
    }
    
    private function sendLowRatingAlert($customerId, $rating, $feedback) {
        $customer = $this->customerModel->find($customerId);
        
        if ($customer) {
            $this->emailSender->sendLowRatingAlert($customer, $rating, $feedback);
        }
    }
}