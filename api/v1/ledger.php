<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/GeneralLedger.php';
require_once '../../app/models/ChartOfAccounts.php';
require_once '../../utils/Auth.php';

use App\Models\GeneralLedger;
use App\Models\ChartOfAccounts;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$ledgerModel = new GeneralLedger();
$accountModel = new ChartOfAccounts();

switch ($method) {
    case 'GET':
        if (isset($_GET['trial_balance'])) {
            $month = $_GET['month'] ?? date('m');
            $year = $_GET['year'] ?? date('Y');
            $trialBalance = $ledgerModel->getTrialBalance($month, $year);
            echo json_encode($trialBalance);
        } elseif (isset($_GET['income_statement'])) {
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $incomeStatement = $ledgerModel->getIncomeStatement($startDate, $endDate);
            echo json_encode($incomeStatement);
        } elseif (isset($_GET['balance_sheet'])) {
            $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
            $balanceSheet = $ledgerModel->getBalanceSheet($asOfDate);
            echo json_encode($balanceSheet);
        } elseif (isset($_GET['account_id'])) {
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $statement = $ledgerModel->getAccountStatement($_GET['account_id'], $startDate, $endDate);
            echo json_encode($statement);
        } else {
            $page = $_GET['page'] ?? 1;
            $entries = $ledgerModel->paginate($page, $_GET['per_page'] ?? 20);
            echo json_encode($entries);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'entry_date' => 'required',
            'debit_account' => 'required|numeric',
            'credit_account' => 'required|numeric',
            'amount' => 'required|numeric'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $amount = floatval($data['amount']);
        
        $debitEntry = [
            'entry_date' => $data['entry_date'],
            'account_id' => $data['debit_account'],
            'description' => $data['description'] ?? '',
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'reference_type' => 'journal',
            'created_by' => $user['id']
        ];
        
        $creditEntry = [
            'entry_date' => $data['entry_date'],
            'account_id' => $data['credit_account'],
            'description' => $data['description'] ?? '',
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'reference_type' => 'journal',
            'created_by' => $user['id']
        ];
        
        try {
            $result = $ledgerModel->postDoubleEntry($debitEntry, $creditEntry);
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Entry posted', 'ids' => $result]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}