<?php
// api/cash.php
header('Content-Type: application/json');
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function sendError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function sendSuccess($data = null, $message = '') {
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message]);
    exit;
}

try {
    switch ($action) {
        case 'accounts':
            $stmt = $conn->query("SELECT id, account_name, account_type, balance FROM cash_accounts WHERE is_active = 1");
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$accounts) {
                $conn->exec("
                    INSERT INTO cash_accounts (account_name, account_type, balance, is_active)
                    VALUES 
                        ('Main Cash', 'cash', 0, 1),
                        ('Stanbic Bank', 'bank', 0, 1),
                        ('MTN Mobile Money', 'mobile_money', 0, 1)
                ");
                $stmt = $conn->query("SELECT * FROM cash_accounts WHERE is_active = 1");
                $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $total_cash = 0;
            $total_bank = 0;
            $total_balance = 0;
            foreach ($accounts as $acc) {
                if ($acc['account_type'] === 'cash') $total_cash += $acc['balance'];
                if ($acc['account_type'] === 'bank') $total_bank += $acc['balance'];
                $total_balance += $acc['balance'];
            }
            $summary = [
                'total_balance' => $total_balance,
                'total_cash'    => $total_cash,
                'total_bank'    => $total_bank,
            ];
            sendSuccess(['accounts' => $accounts, 'summary' => $summary]);
            break;

        case 'transactions':
            $params = [];
            $sql = "SELECT t.*, a.account_name, 
                           COALESCE(u.full_name, 'System') as created_by_name
                    FROM cash_transactions t
                    LEFT JOIN cash_accounts a ON t.account_id = a.id
                    LEFT JOIN users u ON t.created_by = u.id
                    WHERE 1=1";
            if (!empty($_GET['account_id'])) {
                $sql .= " AND t.account_id = ?";
                $params[] = $_GET['account_id'];
            }
            if (!empty($_GET['type'])) {
                $sql .= " AND t.transaction_type = ?";
                $params[] = $_GET['type'];
            }
            if (!empty($_GET['from_date'])) {
                $sql .= " AND t.transaction_date >= ?";
                $params[] = $_GET['from_date'];
            }
            if (!empty($_GET['to_date'])) {
                $sql .= " AND t.transaction_date <= ?";
                $params[] = $_GET['to_date'];
            }
            $sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC LIMIT 200";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_income = 0;
            $total_expenses = 0;
            foreach ($transactions as $t) {
                if ($t['transaction_type'] === 'income') $total_income += $t['amount'];
                else $total_expenses += $t['amount'];
            }
            $summary = [
                'total_income'   => $total_income,
                'total_expenses' => $total_expenses,
                'net_cash_flow'  => $total_income - $total_expenses,
            ];
            sendSuccess(['transactions' => $transactions, 'summary' => $summary]);
            break;

        case 'create-transaction':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) sendError('Invalid input');
            $required = ['transaction_type', 'transaction_date', 'category', 'account_id', 'amount'];
            foreach ($required as $field) {
                if (empty($input[$field])) sendError("Missing field: $field");
            }
            $userId = $_SESSION['user_id'] ?? 1;
            $conn->beginTransaction();
            $stmt = $conn->prepare("
                INSERT INTO cash_transactions
                (transaction_type, transaction_date, category, account_id, amount, reference_no, description, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW())
            ");
            $stmt->execute([
                $input['transaction_type'],
                $input['transaction_date'],
                $input['category'],
                $input['account_id'],
                $input['amount'],
                $input['reference_no'] ?? null,
                $input['description'] ?? null,
                $userId
            ]);
            $sign = ($input['transaction_type'] === 'income') ? '+' : '-';
            $stmt = $conn->prepare("UPDATE cash_accounts SET balance = balance $sign ? WHERE id = ?");
            $stmt->execute([$input['amount'], $input['account_id']]);
            $conn->commit();
            sendSuccess(null, 'Transaction saved');
            break;

        case 'trend':
            $period = isset($_GET['period']) ? (int)$_GET['period'] : 7;
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime("-$period days"));
            $stmt = $conn->prepare("
                SELECT DATE(transaction_date) as date,
                       SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as income,
                       SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as expense
                FROM cash_transactions
                WHERE transaction_date BETWEEN ? AND ? AND status = 'approved'
                GROUP BY DATE(transaction_date)
                ORDER BY date ASC
            ");
            $stmt->execute([$startDate, $endDate]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $data = [];
            $current = new DateTime($startDate);
            $end = new DateTime($endDate);
            $interval = new DateInterval('P1D');
            while ($current <= $end) {
                $dateStr = $current->format('Y-m-d');
                $found = null;
                foreach ($results as $row) {
                    if ($row['date'] === $dateStr) {
                        $found = $row;
                        break;
                    }
                }
                $data[] = [
                    'date'    => $dateStr,
                    'income'  => $found ? (float)$found['income'] : 0,
                    'expense' => $found ? (float)$found['expense'] : 0,
                ];
                $current->add($interval);
            }
            sendSuccess($data);
            break;

        default:
            sendError('Unknown action', 400);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}