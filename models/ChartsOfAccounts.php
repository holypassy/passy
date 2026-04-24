<?php
namespace App\Models;

use Core\Model;

class ChartOfAccounts extends Model {
    protected $table = 'chart_of_accounts';
    protected $primaryKey = 'id';
    protected $fillable = [
        'account_code', 'account_name', 'account_type', 'normal_balance',
        'parent_id', 'is_active'
    ];
    
    public function getAccountsByType($type) {
        return $this->all(['account_type' => $type, 'is_active' => 1], 'account_code');
    }
    
    public function getIncomeAccounts() {
        return $this->getAccountsByType('revenue');
    }
    
    public function getExpenseAccounts() {
        return $this->getAccountsByType('expense');
    }
    
    public function getAssetAccounts() {
        return $this->getAccountsByType('asset');
    }
    
    public function getLiabilityAccounts() {
        return $this->getAccountsByType('liability');
    }
    
    public function getEquityAccounts() {
        return $this->getAccountsByType('equity');
    }
    
    public function getAccountWithBalance($id, $asOfDate = null) {
        $asOfDate = $asOfDate ?: date('Y-m-d');
        
        $stmt = $this->db->prepare("
            SELECT 
                ca.*,
                COALESCE(SUM(gl.debit_amount), 0) as total_debits,
                COALESCE(SUM(gl.credit_amount), 0) as total_credits,
                CASE 
                    WHEN ca.normal_balance = 'debit' THEN COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0)
                    ELSE COALESCE(SUM(gl.credit_amount), 0) - COALESCE(SUM(gl.debit_amount), 0)
                END as balance
            FROM chart_of_accounts ca
            LEFT JOIN general_ledger gl ON ca.id = gl.account_id AND gl.entry_date <= ?
            WHERE ca.id = ?
            GROUP BY ca.id
        ");
        $stmt->execute([$asOfDate, $id]);
        return $stmt->fetch();
    }
    
    public function getAccountHierarchy() {
        $stmt = $this->db->query("
            SELECT * FROM chart_of_accounts 
            WHERE is_active = 1 
            ORDER BY account_code
        ");
        $accounts = $stmt->fetchAll();
        
        $hierarchy = [];
        foreach ($accounts as $account) {
            if ($account['parent_id'] === null) {
                $hierarchy[] = $account;
            }
        }
        
        return $hierarchy;
    }
    
    public function generateAccountCode($type) {
        $prefix = [
            'asset' => '1',
            'liability' => '2',
            'equity' => '3',
            'revenue' => '4',
            'expense' => '5'
        ];
        
        $code = $prefix[$type] ?? '6';
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM chart_of_accounts 
            WHERE account_code LIKE ? AND account_type = ?
        ");
        $stmt->execute([$code . '%', $type]);
        $count = $stmt->fetch()['count'] + 1;
        
        return $code . str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}