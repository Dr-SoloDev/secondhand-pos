<?php
class Logger
{
    private static $requestId;

    /**
     * @param $userId
     * @param $action
     * @param $description
     */
    public static function logActivity($userId, $action, $description = '', array $context = [])
    {
        $db = Database::getInstance();
        $actor = $context['actor'] ?? null;
        if (!$actor && $userId) {
            $actor = $db->fetch('SELECT id AS user_id, role, branch_id FROM users WHERE id = ?', [(int)$userId]);
        }

        $before = array_key_exists('before', $context) ? self::redact($context['before']) : null;
        $after = array_key_exists('after', $context) ? self::redact($context['after']) : null;
        $description = self::redactText((string)$description);
        $reason = isset($context['reason']) ? self::redactText((string)$context['reason']) : null;

        $data = [
            'user_id' => $userId,
            'actor_id' => $userId,
            'actor_role' => $actor['role'] ?? 'system',
            'actor_branch_id' => $actor['branch_id'] ?? null,
            'action' => $action,
            'module' => $context['module'] ?? self::inferModule($action),
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => isset($context['entity_id']) ? (string)$context['entity_id'] : null,
            'entity_branch_id' => isset($context['entity_branch_id']) ? (int)$context['entity_branch_id'] : null,
            'outcome' => $context['outcome'] ?? 'success',
            'reason' => $reason,
            'description' => $description,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'self_approved' => !empty($context['self_approved']) ? 1 : 0,
            'ip_address' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => self::redactText($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'),
            'request_id' => self::requestId(),
        ];

        try {
            $db->insert('activity_log', $data);
        } catch (Exception $e) {
            // Keep deployments operational during the short code-before-migration window.
            error_log('Structured audit insert failed; using legacy columns: ' . $e->getMessage());
            $db->insert('activity_log', [
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $data['ip_address'],
                'user_agent' => $data['user_agent'],
            ]);
        }
        return true;
    }

    public static function redact($value, $key = '')
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = self::redact($childValue, (string)$childKey);
            }
            return $redacted;
        }

        $normalizedKey = strtolower($key);
        if (preg_match('/password|passwd|token|jwt|secret|authorization|cookie/', $normalizedKey)) {
            return '[REDACTED]';
        }
        if (preg_match('/id_card_photo|photo_data|image_data/', $normalizedKey)) {
            return '[REDACTED]';
        }
        if (preg_match('/id_card|national_id|citizen_id/', $normalizedKey)) {
            return self::maskIdCard((string)$value);
        }
        return is_string($value) ? self::redactText($value) : $value;
    }

    private static function redactText($value)
    {
        $value = preg_replace_callback('/(?<!\d)\d{13}(?!\d)/', function ($match) {
            return self::maskIdCard($match[0]);
        }, (string)$value);
        return mb_substr($value, 0, 4000);
    }

    private static function maskIdCard($value)
    {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 4) return '[REDACTED]';
        return str_repeat('*', max(strlen($digits) - 4, 0)) . substr($digits, -4);
    }

    private static function requestId()
    {
        if (self::$requestId) return self::$requestId;
        $provided = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if ($provided && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $provided)) {
            self::$requestId = $provided;
        } else {
            self::$requestId = bin2hex(random_bytes(16));
        }
        return self::$requestId;
    }

    private static function inferModule($action)
    {
        $modules = [
            'seller' => 'sellers', 'purchase' => 'purchase_orders', 'catalog' => 'catalog',
            'transfer' => 'stock_transfers', 'sale_lot' => 'sale_lots', 'cash' => 'cash_sessions',
            'deposit' => 'cash_sessions', 'expense' => 'expenses', 'employee' => 'employees',
            'user' => 'users', 'setting' => 'settings', 'inventory' => 'inventory',
            'audit' => 'audit', 'login' => 'auth', 'logout' => 'auth', 'password' => 'auth',
        ];
        foreach ($modules as $needle => $module) {
            if (strpos($action, $needle) !== false) return $module;
        }
        return 'system';
    }

    /**
     * @param $userId
     * @param null $page
     * @param $limit
     */
    public static function getActivityLog($userId = null, $page = 1, $limit = 10)
    {
        $filters = $userId ? ['actor_id' => (int)$userId] : [];
        return (new AuditLog())->search($filters, $page, $limit);
    }
}
