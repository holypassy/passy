<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/CashTransaction.php';
require_once '../../app/models/CashAccount.php';
require_once '../../utils/Auth.php';

use App\Models\CashTransaction;
use App\Models\CashAccount;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$transactionModel = new CashTransaction();
$accountModel = new CashAccount();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $transaction = $transactionModel->getWithDetails($_GET['id']);
            if ($transaction) {
                echo json_encode($transaction);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Transaction not found']);
            }
        } elseif (isset($_GET['summary'])) {
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $summary = $transactionModel->getSummary($startDate, $endDate);
            echo json_encode($summary);
        } elseif (isset($_GET['trend'])) {
            $days = $_GET['days'] ?? 7;
            $trend = $transactionModel->getWeeklyTrend($days);
            echo json_encode($trend);
        } else {
            $page = $_GET['page'] ?? 1;
            $filters = [
                'account_id' => $_GET['account'] ?? null,
                'transaction_type' => $_GET['type'] ?? null,
                'date_from' => $_GET['from'] ?? null,
                'date_to' => $_GET['to'] ?? null
            ];
            $transactions = $transactionModel->getTransactions($filters, $page, $_GET['per_page'] ?? 20);
            echo json_encode($transactions);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'transaction_date' => 'required',
            'transaction_type' => 'required',
            'account_id' => 'required|numeric',
            'amount' => 'required|numeric'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $data['created_by'] = $user['id'];
        $data['status'] = 'approved';
        
        $transactionId = $transactionModel->createTransaction($data);
        
        // Update account balance
        $accountModel->updateBalance(
            $data['account_id'],
            $data['amount'],
            $data['transaction_type']
        );
        
        http_response_code(201);
        echo json_encode(['id' => $transactionId, 'message' => 'Transaction created successfully']);
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Transaction ID required']);
            break;
        }
        
        $transaction = $transactionModel->find($_GET['id']);
        if ($transaction) {
            $reverseType = $transaction['transaction_type'] === 'income' ? 'expense' : 'income';
            $accountModel->updateBalance(
                $transaction['account_id'],
                $transaction['amount'],
                $reverseType
            );
            $transactionModel->delete($_GET['id']);
        }
        
        echo json_encode(['message' => 'Transaction deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}