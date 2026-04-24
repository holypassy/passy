<?php
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tool_requests table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `tool_requests` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `technician_id` INT NOT NULL,
            `number_plate` VARCHAR(50) NOT NULL,
            `expected_duration_days` INT NOT NULL DEFAULT 1,
            `urgency` ENUM('low', 'medium', 'high', 'emergency') DEFAULT 'medium',
            `reason` TEXT NOT NULL,
            `instructions` TEXT NULL,
            `requested_by` INT NULL,
            `status` ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`technician_id`) REFERENCES `technicians`(`id`),
            FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Create tool_request_items table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `tool_request_items` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `request_id` INT NOT NULL,
            `tool_id` INT NULL,
            `tool_name` VARCHAR(255) NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            `is_new_tool` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`request_id`) REFERENCES `tool_requests`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`tool_id`) REFERENCES `tools`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "Tables created successfully!";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>