<?php
class SMSService {
    private $provider;
    private $api_key;
    private $sender_id;
    
    public function __construct() {
        $this->provider = SMS_PROVIDER ?? 'local';
        $this->api_key = SMS_API_KEY ?? '';
        $this->sender_id = SMS_SENDER_ID ?? 'SAVANT';
    }
    
    public function send($phone, $message) {
        $phone = $this->formatPhoneNumber($phone);
        
        switch ($this->provider) {
            case 'africastalking':
                return $this->sendViaAfricaSTalking($phone, $message);
            case 'twilio':
                return $this->sendViaTwilio($phone, $message);
            case 'local':
            default:
                return $this->sendViaLocal($phone, $message);
        }
    }
    
    private function sendViaLocal($phone, $message) {
        try {
            error_log("SMS to {$phone}: {$message}");
            
            return [
                'success' => true,
                'provider' => 'local',
                'response' => 'SMS logged successfully',
                'to' => $phone
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function sendViaAfricaSTalking($phone, $message) {
        $username = AFRICASTALKING_USERNAME ?? '';
        $api_key = AFRICASTALKING_API_KEY ?? '';
        
        if (empty($username) || empty($api_key)) {
            return $this->sendViaLocal($phone, $message);
        }
        
        try {
            $url = "https://api.africastalking.com/version1/messaging";
            $data = [
                'username' => $username,
                'to' => $phone,
                'message' => $message,
                'from' => $this->sender_id
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'apiKey: ' . $api_key
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 201 || $httpCode == 200) {
                return [
                    'success' => true,
                    'provider' => 'africastalking',
                    'response' => $response,
                    'to' => $phone
                ];
            } else {
                return [
                    'success' => false,
                    'provider' => 'africastalking',
                    'error' => 'HTTP ' . $httpCode . ': ' . $response
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function sendViaTwilio($phone, $message) {
        $account_sid = TWILIO_ACCOUNT_SID ?? '';
        $auth_token = TWILIO_AUTH_TOKEN ?? '';
        $twilio_number = TWILIO_PHONE_NUMBER ?? '';
        
        if (empty($account_sid) || empty($auth_token)) {
            return $this->sendViaLocal($phone, $message);
        }
        
        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
            $data = [
                'To' => $phone,
                'From' => $twilio_number,
                'Body' => $message
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_USERPWD, "{$account_sid}:{$auth_token}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 201) {
                return [
                    'success' => true,
                    'provider' => 'twilio',
                    'response' => $response,
                    'to' => $phone
                ];
            } else {
                return [
                    'success' => false,
                    'provider' => 'twilio',
                    'error' => 'HTTP ' . $httpCode . ': ' . $response
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) == 9) {
            $phone = '256' . $phone;
        } elseif (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
            $phone = '256' . substr($phone, 1);
        }
        
        return $phone;
    }
}
?>