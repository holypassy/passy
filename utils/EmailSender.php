<?php
namespace Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailSender {
    private $mail;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        
        $this->mail->isSMTP();
        $this->mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $_ENV['SMTP_USER'] ?? '';
        $this->mail->Password = $_ENV['SMTP_PASS'] ?? '';
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port = $_ENV['SMTP_PORT'] ?? 587;
        
        $this->mail->setFrom($_ENV['SMTP_FROM'] ?? 'noreply@savantmotors.com', 'SAVANT MOTORS ERP');
    }
    
    public function sendWelcomeEmail($email, $name, $tempPassword) {
        try {
            $this->mail->addAddress($email);
            $this->mail->isHTML(true);
            $this->mail->Subject = 'Welcome to SAVANT MOTORS ERP';
            
            $body = "
                <html>
                <head><style>body{font-family:Arial,sans-serif;}</style></head>
                <body>
                    <h2>Welcome to SAVANT MOTORS, {$name}!</h2>
                    <p>Your account has been created successfully.</p>
                    <p><strong>Username:</strong> {$email}</p>
                    <p><strong>Temporary Password:</strong> {$tempPassword}</p>
                    <p>Please log in and change your password immediately.</p>
                    <br>
                    <p>Best regards,<br>SAVANT MOTORS Team</p>
                </body>
                </html>
            ";
            
            $this->mail->Body = $body;
            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email error: " . $this->mail->ErrorInfo);
            return false;
        }
    }
    
    public function sendPasswordReset($email, $name, $tempPassword) {
        try {
            $this->mail->addAddress($email);
            $this->mail->isHTML(true);
            $this->mail->Subject = 'Password Reset - SAVANT MOTORS ERP';
            
            $body = "
                <html>
                <body>
                    <h2>Password Reset for {$name}</h2>
                    <p>Your password has been reset.</p>
                    <p><strong>Temporary Password:</strong> {$tempPassword}</p>
                    <p>Please log in and change your password immediately.</p>
                </body>
                </html>
            ";
            
            $this->mail->Body = $body;
            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Password reset email error: " . $e->getMessage());
            return false;
        }
    }
}