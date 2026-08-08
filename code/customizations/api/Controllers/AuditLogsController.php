<?php
class AuditLogsController extends Controller
{
    public function index()
    {
        $this->requireAuth(['admin']);
        $pagination = $this->getPaginationParams(25);
        $filters = $this->filtersFromRequest();

        Logger::logActivity($this->user['user_id'], 'view_audit_log', 'Viewed audit log', [
            'actor' => $this->user,
            'module' => 'audit',
            'entity_type' => 'audit_log',
            'after' => ['filters' => $filters],
        ]);

        $model = new AuditLog();
        Response::success('Audit logs retrieved', $model->search(
            $filters,
            $pagination['page'],
            $pagination['limit']
        ));
    }

    public function export()
    {
        $this->requireAuth(['admin']);
        $filters = $this->filtersFromRequest();
        $model = new AuditLog();
        $result = $model->search($filters, 1, 10000, true);

        Logger::logActivity($this->user['user_id'], 'export_audit_log', 'Exported audit log', [
            'actor' => $this->user,
            'module' => 'audit',
            'entity_type' => 'audit_log',
            'after' => ['filters' => $filters, 'row_count' => count($result['logs'])],
        ]);

        $rows = [[
            'id', 'created_at', 'actor', 'actor_role', 'actor_branch_id', 'module', 'action',
            'entity_type', 'entity_id', 'entity_branch_id', 'outcome', 'reason',
            'description', 'self_approved', 'ip_address', 'request_id',
        ]];
        foreach ($result['logs'] as $log) {
            $rows[] = [
                $log['id'], $log['created_at'], $log['username'] ?: $log['actor_id'],
                $log['actor_role'], $log['actor_branch_id'], $log['module'], $log['action'],
                $log['entity_type'], $log['entity_id'], $log['entity_branch_id'],
                $log['outcome'], $log['reason'], $log['description'],
                $log['self_approved'] ? '1' : '0', $log['ip_address'], $log['request_id'],
            ];
        }
        Response::csv($rows, 'audit-log-' . date('Ymd-His') . '.csv');
    }

    private function filtersFromRequest()
    {
        $filters = [];
        $roles = ['cashier', 'manager', 'super_manager', 'admin', 'system'];
        $outcomes = ['success', 'denied', 'failure'];

        if (isset($_GET['role']) && $_GET['role'] !== '') {
            if (!in_array($_GET['role'], $roles, true)) Response::error('Invalid role filter', 400);
            $filters['actor_role'] = $_GET['role'];
        }
        if (isset($_GET['outcome']) && $_GET['outcome'] !== '') {
            if (!in_array($_GET['outcome'], $outcomes, true)) Response::error('Invalid outcome filter', 400);
            $filters['outcome'] = $_GET['outcome'];
        }
        foreach (['branch_id' => 'actor_branch_id', 'user_id' => 'actor_id'] as $input => $field) {
            if (!isset($_GET[$input]) || $_GET[$input] === '') continue;
            if (!ctype_digit((string)$_GET[$input]) || (int)$_GET[$input] < 1) Response::error('Invalid numeric filter', 400);
            $filters[$field] = (int)$_GET[$input];
        }
        foreach (['module', 'action', 'entity', 'keyword'] as $field) {
            if (!isset($_GET[$field]) || trim((string)$_GET[$field]) === '') continue;
            $filters[$field] = $this->sanitizeInput((string)$_GET[$field], 100);
        }
        if (isset($_GET['start_datetime']) && $_GET['start_datetime'] !== '') {
            $filters['start_datetime'] = $this->normalizeDateTime($_GET['start_datetime'], false);
        }
        if (isset($_GET['end_datetime']) && $_GET['end_datetime'] !== '') {
            $filters['end_datetime'] = $this->normalizeDateTime($_GET['end_datetime'], true);
        }
        if (!empty($filters['start_datetime']) && !empty($filters['end_datetime'])
            && $filters['start_datetime'] > $filters['end_datetime']) {
            Response::error('Start datetime must not be after end datetime', 400);
        }
        return $filters;
    }

    private function normalizeDateTime($value, $endOfDay)
    {
        $value = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        } else {
            $value = str_replace('T', ' ', $value);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) $value .= ':00';
        }
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) Response::error('Invalid datetime filter', 400);
        return $value;
    }
}
