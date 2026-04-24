<?php
// check_tools.php - Debug script to check if tools exist
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

echo "<h1>Tools Database Check</h1>";

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check total count
    $stmt = $conn->query("SELECT COUNT(*) as total FROM tools");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total tools in database: <strong>" . $total['total'] . "</strong></p>";
    
    // Get all tools
    $stmt = $conn->query("SELECT id, tool_code, tool_name, status, created_at FROM tools ORDER BY id DESC");
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tools)) {
        echo "<p style='color:red'>No tools found in database!</p>";
        echo "<p>Please add a tool first using add_tool.php</p>";
    } else {
        echo "<h2>Tools List:</h2>";
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
        echo "<tr style='background:#f0f0f0'><th>ID</th><th>Tool Code</th><th>Tool Name</th><th>Status</th><th>Created At</th></tr>";
        foreach ($tools as $tool) {
            echo "<tr>";
            echo "<td>" . $tool['id'] . "</td>";
            echo "<td>" . htmlspecialchars($tool['tool_code']) . "</td>";
            echo "<td>" . htmlspecialchars($tool['tool_name']) . "</td>";
            echo "<td>" . htmlspecialchars($tool['status']) . "</td>";
            echo "<td>" . htmlspecialchars($tool['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check table structure
    echo "<h2>Table Structure:</h2>";
    $columns = $conn->query("DESCRIBE tools")->fetchAll();
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . $col['Field'] . "</td>";
        echo "<td>" . $col['Type'] . "</td>";
        echo "<td>" . $col['Null'] . "</td>";
        echo "<td>" . $col['Key'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch(PDOException $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>