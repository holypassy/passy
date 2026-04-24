<?php
// In LedgerController.php - Make sure trialBalance is always an array

public function trialBalance() {
    $month = $_GET['month'] ?? date('m');
    $year = $_GET['year'] ?? date('Y');
    
    // Ensure month and year are valid
    $month = (int)$month;
    $year = (int)$year;
    if ($month < 1 || $month > 12) $month = date('m');
    if ($year < 2000 || $year > 2100) $year = date('Y');
    
    // Get trial balance - ensure it's always an array
    $trialBalance = $this->ledgerModel->getTrialBalance($month, $year);
    if (!$trialBalance) $trialBalance = [];
    
    // Pass to view
    $this->view('ledger/trial_balance', [
        'trialBalance' => $trialBalance,
        'month' => $month,
        'year' => $year
    ]);
}