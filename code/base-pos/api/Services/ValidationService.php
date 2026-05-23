<?php
class ValidationService
{
    /**
     * @var array
     */
    private $errors = [];

    /**
     * @param $data
     * @param $rules
     */
    public function validate($data, $rules)
    {
        $this->errors = [];

        foreach ($rules as $field => $fieldRules) {
            foreach ($fieldRules as $rule) {
                // Check if the rule has parameters
                if (strpos($rule, ':') !== false) {
                    list($ruleName, $ruleParam) = explode(':', $rule, 2);
                } else {
                    $ruleName = $rule;
                    $ruleParam = null;
                }

                // Skip validation if the field is not required and empty
                if ($ruleName !== 'required' && (!isset($data[$field]) || $data[$field] === '')) {
                    continue;
                }

                // Validate according to rule
                $method = 'validate'.ucfirst($ruleName);
                if (method_exists($this, $method)) {
                    $this->$method($field, $data[$field] ?? null, $ruleParam);
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * @return mixed
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return mixed
     */
    public function getFirstError()
    {
        foreach ($this->errors as $field => $errors) {
            if (!empty($errors)) {
                return $errors[0];
            }
        }
        return null;
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateRequired($field, $value, $param = null)
    {
        if (!isset($value) || $value === '') {
            $this->errors[$field][] = "The $field field is required.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateEmail($field, $value, $param = null)
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "The $field must be a valid email address.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateMin($field, $value, $param)
    {
        if (is_string($value) && mb_strlen($value) < $param) {
            $this->errors[$field][] = "The $field must be at least $param characters.";
        } elseif (is_numeric($value) && $value < $param) {
            $this->errors[$field][] = "The $field must be at least $param.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateMax($field, $value, $param)
    {
        if (is_string($value) && mb_strlen($value) > $param) {
            $this->errors[$field][] = "The $field may not be greater than $param characters.";
        } elseif (is_numeric($value) && $value > $param) {
            $this->errors[$field][] = "The $field may not be greater than $param.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateNumeric($field, $value, $param = null)
    {
        if (!is_numeric($value)) {
            $this->errors[$field][] = "The $field must be a number.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateInteger($field, $value, $param = null)
    {
        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            $this->errors[$field][] = "The $field must be an integer.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateFloat($field, $value, $param = null)
    {
        if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
            $this->errors[$field][] = "The $field must be a decimal number.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateAlpha($field, $value, $param = null)
    {
        if (!ctype_alpha($value)) {
            $this->errors[$field][] = "The $field may only contain letters.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateAlphaNum($field, $value, $param = null)
    {
        if (!ctype_alnum($value)) {
            $this->errors[$field][] = "The $field may only contain letters and numbers.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateUrl($field, $value, $param = null)
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[$field][] = "The $field must be a valid URL.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateDate($field, $value, $param = null)
    {
        if (!strtotime($value)) {
            $this->errors[$field][] = "The $field must be a valid date.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateIn($field, $value, $param)
    {
        $allowedValues = explode(',', $param);
        if (!in_array($value, $allowedValues)) {
            $this->errors[$field][] = "The selected $field is invalid.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateUnique($field, $value, $param)
    {
        list($table, $column, $exceptId) = array_pad(explode(',', $param), 3, null);

        $db = Database::getInstance();

        $query = "SELECT COUNT(*) FROM $table WHERE $column = ?";
        $params = [$value];

        if ($exceptId) {
            $query .= " AND id != ?";
            $params[] = $exceptId;
        }

        $count = $db->fetchColumn($query, $params);

        if ($count > 0) {
            $this->errors[$field][] = "The $field has already been taken.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateExists($field, $value, $param)
    {
        list($table, $column) = explode(',', $param);

        $db = Database::getInstance();

        $query = "SELECT COUNT(*) FROM $table WHERE $column = ?";
        $count = $db->fetchColumn($query, [$value]);

        if ($count == 0) {
            $this->errors[$field][] = "The selected $field is invalid.";
        }
    }

    /**
     * @param $field
     * @param $value
     * @param $param
     */
    private function validateConfirmed($field, $value, $param = null)
    {
        $confirmation = $field.'_confirmation';

        if (!isset($_POST[$confirmation]) || $value !== $_POST[$confirmation]) {
            $this->errors[$field][] = "The $field confirmation does not match.";
        }
    }
}
