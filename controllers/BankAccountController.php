<?php
require_once __DIR__ . '/../models/CashAccount.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class BankAccountController {
    private $accountModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->accountModel = new CashAccount();
    }
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $accounts = $this->accountModel->getAll();
        $totals = $this->accountModel->getTotals();
        
        Response::json([
            'success' => true,
            'data' => $accounts,
            'totals' => $totals
        ]);
    }
    
    public function getOne($id) {
        AuthMiddleware::authenticate();
        
        $account = $this->accountModel->findById($id);
        
        if (!$account) {
            Response::json(['success' => false, 'message' => 'Account not found'], 404);
        }
        
        Response::json(['success' => true, 'data' => $account]);
    }
    
    public function create() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'account_name' => 'required|min:3',
            'account_type' => 'required|in:cash,bank,mobile_money,petty_cash'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->accountModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Account created successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create account'], 500);
        }
    }
    
    public function update($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $account = $this->accountModel->findById($id);
        if (!$account) {
            Response::json(['success' => false, 'message' => 'Account not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->accountModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Account updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update account'], 500);
        }
    }
    
    public function updateBalance($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'balance' => 'required|numeric|min:0'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $account = $this->accountModel->findById($id);
        if (!$account) {
            Response::json(['success' => false, 'message' => 'Account not found'], 404);
        }
        
        $result = $this->accountModel->updateBalance($id, $input['balance']);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Balance updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update balance'], 500);
        }
    }
    
    public function delete($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $account = $this->accountModel->findById($id);
        if (!$account) {
            Response::json(['success' => false, 'message' => 'Account not found'], 404);
        }
        
        $result = $this->accountModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Account deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete account'], 500);
        }
    }
    
    public function getTotals() {
        AuthMiddleware::authenticate();
        
        $totals = $this->accountModel->getTotals();
        
        Response::json(['success' => true, 'data' => $totals]);
    }
}
?>