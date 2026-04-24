<?php
namespace App\Models;

use Core\Model;

class JournalEntry extends Model {
    protected $table = 'journals';
    protected $primaryKey = 'id';
    protected $fillable = [
        'journal_number', 'entry_date', 'description', 'reference', 'total_amount', 'created_by'
    ];
    
    public function generateJournalNumber() {
        $prefix = 'JN-' . date('Ymd');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM journals WHERE journal_number LIKE ?");
        $stmt->execute([$prefix . '%']);
        $count = $stmt->fetchColumn() + 1;
        return $prefix . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
    
    public function createWithLines($journalData, $lines) {
        try {
            $this->db->beginTransaction();
            
            $journalData['journal_number'] = $this->generateJournalNumber();
            $journalId = $this->create($journalData);
            
            $ledgerModel = new GeneralLedger();
            foreach ($lines as $line) {
                $line['reference_type'] = 'journal';
                $line['reference_id'] = $journalId;
                $ledgerModel->create($line);
            }
            
            $this->db->commit();
            return $journalId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getJournalWithLines($id) {
        $stmt = $this->db->prepare("
            SELECT * FROM journals WHERE id = ?
        ");
        $stmt->execute([$id]);
        $journal = $stmt->fetch();
        
        if ($journal) {
            $stmt2 = $this->db->prepare("
                SELECT 
                    gl.*,
                    ca.account_code,
                    ca.account_name
                FROM general_ledger gl
                LEFT JOIN chart_of_accounts ca ON gl.account_id = ca.id
                WHERE gl.reference_type = 'journal' AND gl.reference_id = ?
                ORDER BY gl.id ASC
            ");
            $stmt2->execute([$id]);
            $journal['lines'] = $stmt2->fetchAll();
        }
        
        return $journal;
    }
    
    public function getRecentJournals($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                j.*,
                u.full_name as created_by_name
            FROM journals j
            LEFT JOIN users u ON j.created_by = u.id
            ORDER BY j.entry_date DESC, j.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}