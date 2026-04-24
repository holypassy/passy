<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Feedback.php';
require_once '../../utils/Auth.php';

use App\Models\Feedback;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$feedbackModel = new Feedback();

switch ($method) {
    case 'GET':
        if (isset($_GET['customer_id'])) {
            $feedback = $feedbackModel->getByCustomer($_GET['customer_id']);
            echo json_encode($feedback);
        } elseif (isset($_GET['stats'])) {
            $avgRating = $feedbackModel->getAverageRating();
            $distribution = $feedbackModel->getRatingDistribution();
            $byCategory = $feedbackModel->getFeedbackByCategory();
            
            echo json_encode([
                'average_rating' => $avgRating,
                'distribution' => $distribution,
                'by_category' => $byCategory
            ]);
        } elseif (isset($_GET['low_rating'])) {
            $threshold = $_GET['threshold'] ?? 3;
            $feedback = $feedbackModel->getLowRatingFeedback($threshold);
            echo json_encode($feedback);
        } else {
            $limit = $_GET['limit'] ?? 20;
            $feedback = $feedbackModel->getRecentFeedback($limit);
            echo json_encode($feedback);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'customer_id' => 'required|numeric',
            'rating' => 'required|numeric',
            'feedback_text' => 'required'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $data['feedback_date'] = date('Y-m-d');
        $feedbackId = $feedbackModel->create($data);
        
        http_response_code(201);
        echo json_encode(['id' => $feedbackId, 'message' => 'Feedback recorded successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}