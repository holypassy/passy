<?php
namespace Core;

class Validation {
    private $errors = [];
    private $data = [];
    
    public function validate($data, $rules) {
        $this->data = $data;
        $this->errors = [];
        
        foreach ($rules as $field => $ruleSet) {
            $rules = explode('|', $ruleSet);
            
            foreach ($rules as $rule) {
                $this->applyRule($field, $rule);
            }
        }
        
        return empty($this->errors);
    }
    
    private function applyRule($field, $rule) {
        $value = $this->data[$field] ?? null;
        
        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    $this->errors[$field][] = "The {$field} field is required";
                }
                break;
                
            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field][] = "The {$field} must be a valid email address";
                }
                break;
                
            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->errors[$field][] = "The {$field} must be numeric";
                }
                break;
                
            default:
                if (strpos($rule, 'min:') === 0) {
                    $min = explode(':', $rule)[1];
                    if (!empty($value) && strlen($value) < $min) {
                        $this->errors[$field][] = "The {$field} must be at least {$min} characters";
                    }
                }
                
                if (strpos($rule, 'max:') === 0) {
                    $max = explode(':', $rule)[1];
                    if (!empty($value) && strlen($value) > $max) {
                        $this->errors[$field][] = "The {$field} must not exceed {$max} characters";
                    }
                }
                break;
        }
    }
    
    public function errors() {
        return $this->errors;
    }
}