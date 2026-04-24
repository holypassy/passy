<?php
namespace App\Models;

use Core\Model;

class GeneralLedger extends Model {
    protected $table = 'general_ledger';
    protected $primaryKey = 'id';
    protected $fillable = [
        'entry_date', 'account_id', 'description', 'debit_amount',
        'credit_amount', 'reference_type', 'reference_id', 'created_by'
    ];
    
    public function postEntry($data) {
        return $this->create($data);
    }
    
    public function postDoubleEntry($debitEntry, $creditEntry) {
        try {
            $this->db->beginTransaction();
            
            $debitId = $this->create($debitEntry);
            $creditId = $this->create($creditEntry);
            
            $this->db->commit();
            return ['debit_id' => $debitId, 'credit_id' => $creditId];
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getTrialBalance($month = null, $year = null) {
        $month = $month ?: date('m');
        $year = $year ?: date('Y');
        
        $stmt = $this->db->prepare("
            SELECT 
                ca.account_code,
                ca.account_name,
                ca.account_type,
                COALESCE(SUM(gl.debit_amount), 0) as total_debit,
                COALESCE(SUM(gl.credit_amount), 0) as total_credit,
                CASE 
                    WHEN ca.normal_balance = 'debit' THEN COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0)
                    ELSE COALESCE(SUM(gl.credit_amount), 0) - COALESCE(SUM(gl.debit_amount), 0)
                END as balance
            FROM chart_of_accounts ca
            LEFT JOIN general_ledger gl ON ca.id = gl.account_id 
                AND MONTH(gl.entry_date) = ? 
                AND YEAR(gl.entry_date) = ?
            WHERE ca.is_active = 1
            GROUP BY ca.id
            ORDER BY ca.account_code
        ");
        $stmt->execute([$month, $year]);
        $result = $stmt->fetchAll();
       return $result ?: []; // Always return an array
        } catch (PDOException $e) {
            error_log("Trial Balance Error: " . $e->getMessage());
           return []; // Return empty array on error
        }
    }
    
    public function getAccountStatement($accountId, $startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                gl.*,
                ca.account_code,
                ca.account_name,
                u.full_name as created_by_name
            FROM general_ledger gl
            LEFT JOIN chart_of_accounts ca ON gl.account_id = ca.id
            LEFT JOIN users u ON gl.created_by = u.id
            WHERE gl.account_id = ? 
                AND gl.entry_date BETWEEN ? AND ?
            ORDER BY gl.entry_date ASC, gl.created_at ASC
        ");
        $stmt->execute([$accountId, $startDate, $endDate]);
        $transactions = $stmt->fetchAll();
        
        // Calculate running balance
        $balance = 0;
        foreach ($transactions as &$trans) {
            $balance += $trans['debit_amount'] - $trans['credit_amount'];
            $trans['running_balance'] = $balance;
        }
        
        return $transactions;
    }
    
    public function getMonthlySummary($year = null) {
        $year = $year ?: date('Y');
        
        $stmt = $this->db->prepare("
            SELECT 
                MONTH(entry_date) as month,
                COALESCE(SUM(debit_amount), 0) as total_debits,
                COALESCE(SUM(credit_amount), 0) as total_credits
            FROM general_ledger
            WHERE YEAR(entry_date) = ?
            GROUP BY MONTH(entry_date)
            ORDER BY month ASC
        ");
        $stmt->execute([$year]);
        return $stmt->fetchAll();
    }
    
    public function getAccountBalances($asOfDate = null) {
        $asOfDate = $asOfDate ?: date('Y-m-d');
        
        $stmt = $this->db->prepare("
            SELECT 
                ca.id,
                ca.account_code,
                ca.account_name,
                ca.account_type,
                ca.normal_balance,
                COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0) as balance
            FROM chart_of_accounts ca
            LEFT JOIN general_ledger gl ON ca.id = gl.account_id AND gl.entry_date <= ?
            WHERE ca.is_active = 1
            GROUP BY ca.id
            ORDER BY ca.account_code
        ");
        $stmt->execute([$asOfDate]);
        $balances = $stmt->fetchAll();
        
        // Adjust balance based on normal balance
        foreach ($balances as &$balance) {
            if ($balance['normal_balance'] == 'credit') {
                $balance['balance'] = -$balance['balance'];
            }
        }
        
        return $balances;
    }
    
    public function getIncomeStatement($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT 
                ca.account_code,
                ca.account_name,
                COALESCE(SUM(gl.credit_amount), 0) - COALESCE(SUM(gl.debit_amount), 0) as amount
            FROM chart_of_accounts ca
            LEFT JOIN general_ledger gl ON ca.id = gl.account_id 
                AND gl.entry_date BETWEEN ? AND ?
            WHERE ca.account_type IN ('revenue', 'expense')
                AND ca.is_active = 1
            GROUP BY ca.id
            ORDER BY ca.account_type, ca.account_code
        ");
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll();
    }
    
    public function getBalanceSheet($asOfDate) {
        $stmt = $this->db->prepare("
            SELECT 
                ca.account_type,
                ca.account_code,
                ca.account_name,
                COALESCE(SUM(gl.debit_amount), 0) - COALESCE(SUM(gl.credit_amount), 0) as balance
            FROM chart_of_accounts ca
            LEFT JOIN general_ledger gl ON ca.id = gl.account_id AND gl.entry_date <= ?
            WHERE ca.account_type IN ('asset', 'liability', 'equity')
                AND ca.is_active = 1
            GROUP BY ca.id
            ORDER BY FIELD(ca.account_type, 'asset', 'liability', 'equity'), ca.account_code
        ");
        $stmt->execute([$asOfDate]);
        return $stmt->fetchAll();
    }
}