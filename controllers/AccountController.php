<?php
// app/controllers/AccountController.php
namespace App\Controllers;

use Core\Controller;
use App\Models\CashAccount;

class AccountController extends Controller {
    private $accountModel;
    
    public function __construct() {
        $this->accountModel = new CashAccount();
    }
    
    public function index() {
        // Get all active accounts - THIS IS THE KEY FIX
        $accounts = $this->accountModel->getActiveAccounts();
        
        // Get balances summary
        $balances = $this->accountModel->getTotalBalance();
        
        // Pass both variables to the view
        $this->view('cash/accounts', [
            'accounts' => $accounts,
            'balances' => $balances
        ]);
    }
    
    public function create() {
        $this->view('cash/create_account');
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'account_name' => 'required|min:3',
            'account_type' => 'required'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('/cash/accounts/create');
            return;
        }
        
        $data['balance'] = $data['initial_balance'] ?? 0;
        $data['is_active'] = 1;
        
        $this->accountModel->create($data);
        
        $_SESSION['success'] = 'Account created successfully';
        $this->redirect('/cash/accounts');
    }
    
    public function view($id) {
        $account = $this->accountModel->getAccountWithTransactions($id);
        
        if (!$account) {
            $_SESSION['error'] = 'Account not found';
            $this->redirect('/cash/accounts');
            return;
        }
        
        $this->view('cash/view_account', ['account' => $account]);
    }
    
    public function edit($id) {
        $account = $this->accountModel->find($id);
        
        if (!$account) {
            $_SESSION['error'] = 'Account not found';
            $this->redirect('/cash/accounts');
            return;
        }
        
        $this->view('cash/edit_account', ['account' => $account]);
    }
    
    public function update($id) {
        $data = $this->sanitize($_POST);
        
        $this->accountModel->update($id, $data);
        
        $_SESSION['success'] = 'Account updated successfully';
        $this->redirect("/cash/accounts/view/{$id}");
    }
    
    public function delete($id) {
        $this->accountModel->delete($id);
        
        $this->json(['success' => true, 'message' => 'Account deleted']);
    }
}