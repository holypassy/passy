<?php
class EmailService {
    private $smtp_host;
    private $smtp_port;
    private $smtp_user;
    private $smtp_pass;
    private $from_email;
    private $from_name;
    
    public function __construct() {
        $this->smtp_host = SMTP_HOST ?? 'smtp.gmail.com';
        $this->smtp_port = SMTP_PORT ?? 587;
        $this->smtp_user = SMTP_USER ?? '';
        $this->smtp_pass = SMTP_PASS ?? '';
        $this->from_email = FROM_EMAIL ?? 'noreply@savantmotors.com';
        $this->from_name = FROM_NAME ?? 'SAVANT MOTORS';
    }
    
    public function send($to, $subject, $message, $isHtml = false) {
        try {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "Reply-To: {$this->from_email}\r\n";
            
            if ($isHtml) {
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            } else {
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            }
            
            $result = mail($to, $subject, $message, $headers);
            
            if ($result) {
                return [
                    'success' => true,
                    'response' => 'Email sent successfully',
                    'to' => $to
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to send email'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function sendHtml($to, $subject, $htmlContent) {
        return $this->send($to, $subject, $htmlContent, true);
    }
    
    public function sendTemplate($to, $template, $data) {
        $templates = [
            'service_reminder' => [
                'subject' => 'Service Reminder - SAVANT MOTORS',
                'html' => "
                    <html>
                    <body style='font-family: Arial, sans-serif;'>
                        <h2>Service Reminder</h2>
                        <p>Dear {$data['customer_name']},</p>
                        <p>This is a reminder that your vehicle <strong>{$data['vehicle_reg']}</strong> is due for service on <strong>{$data['service_date']}</strong>.</p>
                        <p>Please contact us to schedule your appointment.</p>
                        <hr>
                        <p>Thank you for choosing SAVANT MOTORS.</p>
                        <p><strong>SAVANT MOTORS UGANDA</strong><br>
                        Tel: +256 123 456 789<br>
                        Email: service@savantmotors.com</p>
                    </body>
                    </html>
                "
            ]
        ];
        
        $templateData = $templates[$template] ?? null;
        
        if (!$templateData) {
            return ['success' => false, 'error' => 'Template not found'];
        }
        
        $subject = $templateData['subject'];
        $html = str_replace(array_keys($data), array_values($data), $templateData['html']);
        
        return $this->sendHtml($to, $subject, $html);
    }
}
?>