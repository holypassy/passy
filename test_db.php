<?php
$host = 'localhost';
$dbname = 'savant_motors_pos';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Database Connection Successful!</h2>";
    
    // Check users table
    $stmt = $conn->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Users table exists<br>";
        
        $stmt = $conn->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        echo "📊 Total users: " . $result['count'] . "<br>";
        
        // List users
        $stmt = $conn->query("SELECT id, username, full_name, role FROM users");
        echo "<h3>Users in database:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";
        while ($row = $stmt->fetch()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['username']}</td>";
            echo "<td>{$row['full_name']}</td>";
            echo "<td>{$row['role']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ Users table does not exist!<br>";
        echo "Please run the SQL script to create tables.";
    }
    
} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage();
}
?>