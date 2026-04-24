<?php
namespace Utils;

use Twilio\Rest\Client;

class SMSSender {
    private $client;
    private $fromNumber;
    
    public function __construct() {
        $accountSid = $_ENV['TWILIO_SID'] ?? '';
        $authToken = $_ENV['TWILIO_TOKEN'] ?? '';
        $this->fromNumber = $_ENV['TWILIO_FROM'] ?? '';
        
        if ($accountSid && $authToken) {
            $this->client = new Client($accountSid, $authToken);
        }
    }
    
    public function send($to, $message) {
        if (!$this->client) {
            error_log("SMS not sent: Twilio not configured");
            return false;
        }
        
        try {
            $this->client->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $message
            ]);
            return true;
        } catch (\Exception $e) {
            error_log("SMS sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendBulk($numbers, $message) {
        $results = [];
        foreach ($numbers as $number) {
            $results[$number] = $this->send($number, $message);
        }
        return $results;
    }
}