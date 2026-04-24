<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get the logged-in user ID from session
    $requested_by = $_SESSION['user_id'] ?? null;
    
    if (!$requested_by && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $username = $_SESSION['username'] ?? null;
        if ($username) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $requested_by = $user['id'];
                $_SESSION['user_id'] = $requested_by;
            }
        }
    }
    
    if (!$requested_by) {
        $stmt = $conn->query("SELECT id FROM users LIMIT 1");
        $defaultUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($defaultUser) {
            $requested_by = $defaultUser['id'];
        }
    }
    
    // Generate unique request number
    $prefix = 'TR';
    $year = date('Y');
    $month = date('m');
    
    $stmt = $conn->prepare("
        SELECT request_number FROM tool_requests 
        WHERE request_number LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $pattern = $prefix . $year . $month . '%';
    $stmt->execute([$pattern]);
    $lastRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastRequest) {
        $lastNumber = intval(substr($lastRequest['request_number'], -4));
        $sequence = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $sequence = '0001';
    }
    
    $request_number = $prefix . $year . $month . $sequence;
    
    // Get form data
    $technician_id = $_POST['technician_id'];
    $number_plate = $_POST['number_plate'];
    $expected_duration_days = $_POST['expected_duration_days'];
    $urgency = $_POST['urgency'];
    $reason = $_POST['reason'];
    $instructions = $_POST['instructions'] ?? '';
    $tools = json_decode($_POST['tools'], true);
    
    // Validate
    if (!$technician_id || !$number_plate || !$tools || count($tools) == 0) {
        throw new Exception('Please fill all required fields');
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    // Insert request
    $stmt = $conn->prepare("
        INSERT INTO tool_requests (request_number, technician_id, number_plate, expected_duration_days, urgency, reason, instructions, requested_by, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$request_number, $technician_id, $number_plate, $expected_duration_days, $urgency, $reason, $instructions, $requested_by]);
    $request_id = $conn->lastInsertId();
    
    // Insert request items
    $stmt = $conn->prepare("
        INSERT INTO tool_request_items (request_id, tool_id, tool_name, quantity, is_new_tool) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($tools as $tool) {
        $tool_id = ($tool['type'] === 'existing') ? $tool['tool_id'] : null;
        $tool_name = ($tool['type'] === 'new') ? $tool['tool_name'] : null;
        $is_new_tool = ($tool['type'] === 'new') ? 1 : 0;
        
        $stmt->execute([$request_id, $tool_id, $tool_name, $tool['quantity'], $is_new_tool]);
    }
    
    $conn->commit();
    
    $_SESSION['success'] = 'Tool request #' . $request_number . ' created successfully!';
    
    // Redirect to the tool requests index page
    header('Location: index.php');
    exit();
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    $_SESSION['error'] = $e->getMessage();
    header('Location: create.php');
    exit();
}
?>