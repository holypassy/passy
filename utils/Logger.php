<?php
class Logger {
    private $logFile;
    
    public function __construct($filename = 'app.log') {
        $this->logFile = __DIR__ . '/../logs/' . $filename;
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0777, true);
        }
    }
    
    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log('WARNING', $message, $context);
    }
    
    private function log($level, $message, $context) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        file_put_contents($this->logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
    }
}