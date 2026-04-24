<?php
// app/models/CashAccount.php
namespace App\Models;

use Core\Model;

class CashAccount extends Model {
    protected $table = 'cash_accounts';
    protected $primaryKey = 'id';
    protected $fillable = [
        'account_name', 'account_type', 'account_number', 'balance',
        'currency', 'is_active'
    ];
    
    public function getActiveAccounts() {
        return $this->all(['is_active' => 1], 'account_name');
    }
    
    public function getTotalBalance() {
        $stmt = $this->db->query("
            SELECT 
                COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
                COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
                COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
                COALESCE(SUM(balance), 0) as total_balance
            FROM cash_accounts
            WHERE is_active = 1
        ");
        $result = $stmt->fetch();
        
        // Ensure all keys exist
        return [
            'total_cash' => $result['total_cash'] ?? 0,
            'total_bank' => $result['total_bank'] ?? 0,
            'total_mobile' => $result['total_mobile'] ?? 0,
            'total_balance' => $result['total_balance'] ?? 0
        ];
    }
    
    public function updateBalance($accountId, $amount, $type) {
        $sign = $type === 'income' ? '+' : '-';
        $stmt = $this->db->prepare("UPDATE cash_accounts SET balance = balance $sign ? WHERE id = ?");
        return $stmt->execute([$amount, $accountId]);
    }
    
    public function getAccountWithTransactions($id, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                (SELECT COUNT(*) FROM cash_transactions WHERE account_id = a.id) as transaction_count
            FROM cash_accounts a
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $account = $stmt->fetch();
        
        if ($account) {
            $stmt2 = $this->db->prepare("
                SELECT * FROM cash_transactions 
                WHERE account_id = ? 
                ORDER BY transaction_date DESC 
                LIMIT ?
            ");
            $stmt2->execute([$id, $limit]);
            $account['transactions'] = $stmt2->fetchAll();
        }
        
        return $account;
    }
}