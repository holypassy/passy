<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Customer.php';
require_once __DIR__ . '/../models/CustomerInteraction.php';
require_once __DIR__ . '/../models/CustomerCommunication.php';
require_once __DIR__ . '/../models/CustomerFeedback.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['error' => 'Customer ID required']);
    exit();
}

try {
    $customerModel = new Customer();
    $interactionModel = new CustomerInteraction();
    $communicationModel = new CustomerCommunication();
    $feedbackModel = new CustomerFeedback();
    
    $customer = $customerModel->findById($id);
    
    if (!$customer) {
        echo json_encode(['error' => 'Customer not found']);
        exit();
    }
    
    $interactions = $interactionModel->getByCustomerId($id, 5);
    $communications = $communicationModel->getByCustomerId($id, 5);
    $feedback = $feedbackModel->getByCustomerId($id, 5);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'customer' => $customer,
            'recent_interactions' => $interactions,
            'recent_communications' => $communications,
            'recent_feedback' => $feedback
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>