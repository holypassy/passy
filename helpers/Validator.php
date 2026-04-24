<?php
class Validator {
    private static $rules = [];
    private static $errors = [];
    
    public static function validate($data, $rules) {
        self::$errors = [];
        
        foreach ($rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            
            foreach ($rules as $rule) {
                $result = self::applyRule($field, $value, $rule);
                if ($result !== true) {
                    self::$errors[$field][] = $result;
                }
            }
        }
        
        return empty(self::$errors) ? true : self::$errors;
    }
    
    private static function applyRule($field, $value, $rule) {
        if ($rule === 'required' && empty($value) && $value !== '0') {
            return "The {$field} field is required";
        }
        
        if (strpos($rule, 'min:') === 0) {
            $min = (int)substr($rule, 4);
            if (strlen($value) < $min) {
                return "The {$field} must be at least {$min} characters";
            }
        }
        
        if (strpos($rule, 'max:') === 0) {
            $max = (int)substr($rule, 4);
            if (strlen($value) > $max) {
                return "The {$field} must not exceed {$max} characters";
            }
        }
        
        if ($rule === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "The {$field} must be a valid email address";
        }
        
        if ($rule === 'date' && !empty($value) && !strtotime($value)) {
            return "The {$field} must be a valid date";
        }
        
        if ($rule === 'numeric' && !empty($value) && !is_numeric($value)) {
            return "The {$field} must be a number";
        }
        
        if (strpos($rule, 'min:') === 0 && $rule !== 'min') {
            $min = (int)substr($rule, 4);
            if (is_numeric($value) && $value < $min) {
                return "The {$field} must be at least {$min}";
            }
        }
        
        if (strpos($rule, 'in:') === 0) {
            $options = explode(',', substr($rule, 3));
            if (!in_array($value, $options)) {
                return "The {$field} must be one of: " . implode(', ', $options);
            }
        }
        
        return true;
    }
}
?>