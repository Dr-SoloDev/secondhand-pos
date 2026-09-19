<?php
class Employee extends Model
{
    protected $table = 'employees';

    public function getAll($page = 1, $limit = 20, $filters = [])
    {
        $baseSql = "FROM employees e
                    JOIN branches b ON b.id = e.branch_id
                    WHERE 1=1";
        $whereClauses = [];
        $bindings = [];

        if (!empty($filters['branch_id'])) {
            $whereClauses[] = "e.branch_id = ?";
            $bindings[] = $filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $whereClauses[] = "e.status = ?";
            $bindings[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $whereClauses[] = "(e.full_name LIKE ? OR e.phone LIKE ? OR e.id_card LIKE ?)";
            $q = '%' . $filters['search'] . '%';
            $bindings[] = $q;
            $bindings[] = $q;
            $bindings[] = $q;
        }

        $whereSql = '';
        if (!empty($whereClauses)) {
            $whereSql = ' AND ' . implode(' AND ', $whereClauses);
        }

        // Count query (separate bindings to avoid LIMIT/OFFSET leaking in)
        $countSql = "SELECT COUNT(*) $baseSql $whereSql";
        $total = $this->db->fetchColumn($countSql, $bindings);

        // List query with pagination
        $listBindings = $bindings;
        $offset = ($page - 1) * $limit;
        $listBindings[] = intval($limit);
        $listBindings[] = intval($offset);

        $itemsSql = "SELECT e.*, b.name AS branch_name $baseSql $whereSql
                     ORDER BY e.created_at DESC LIMIT ? OFFSET ?";
        $items = $this->db->fetchAll($itemsSql, $listBindings) ?: [];

        return [
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
            ],
        ];
    }

    public function getById($id)
    {
        $sql = "SELECT e.*, b.name AS branch_name
                FROM employees e
                JOIN branches b ON b.id = e.branch_id
                WHERE e.id = ?";
        return $this->db->fetch($sql, [$id]);
    }

    public function create($data)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO employees (branch_id, full_name, position, id_card, phone, address,
             salary, daily_wage, social_security_number, social_security_rate,
             start_date, end_date, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $this->db->execute($stmt, [
            $data['branch_id'],
            $data['full_name'],
            $data['position'] ?? null,
            $data['id_card'] ?? null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            $data['salary'] ?? 0,
            $data['daily_wage'] ?? 0,
            $data['social_security_number'] ?? null,
            $data['social_security_rate'] ?? 5.00,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['status'] ?? 'active',
            $data['notes'] ?? null,
        ]);
        return $this->db->lastInsertId();
    }

    public function update($id, $data)
    {
        $fields = [];
        $bindings = [];
        $allowed = ['branch_id', 'full_name', 'position', 'id_card', 'phone', 'address',
                     'salary', 'daily_wage', 'social_security_number', 'social_security_rate',
                     'start_date', 'end_date', 'status', 'notes'];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $bindings[] = $data[$f];
            }
        }

        if (empty($fields)) return false;

        $bindings[] = $id;
        $sql = "UPDATE employees SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $this->db->execute($stmt, $bindings);
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM employees WHERE id = ?");
        return $this->db->execute($stmt, [$id]);
    }

    public function autoCreateSalaryExpense($employeeId, $salaryDate, $amount, $createdBy = null)
    {
        $emp = $this->getById($employeeId);
        if (!$emp) return false;

        $note = "เงินเดือน: {$emp['full_name']} ({$emp['position']}) - {$salaryDate}";
        $stmt = $this->db->prepare(
            "INSERT INTO business_expenses
               (branch_id, expense_date, category, amount, note, status, created_by, requested_by, approved_by, approved_at)
             VALUES (?, ?, 'salary', ?, ?, 'approved', ?, ?, ?, NOW())"
        );
        $this->db->execute($stmt, [
            $emp['branch_id'],
            $salaryDate,
            $amount,
            $note,
            $createdBy,
            $createdBy,
            $createdBy,
        ]);
        return $this->db->lastInsertId();
    }

    public function autoCreateSSOExpense($employeeId, $salaryDate, $ssoAmount, $createdBy = null)
    {
        $emp = $this->getById($employeeId);
        if (!$emp) return false;

        $note = "ประกันสังคม (นายจ้าง): {$emp['full_name']} - {$salaryDate}";
        $stmt = $this->db->prepare(
            "INSERT INTO business_expenses
               (branch_id, expense_date, category, amount, note, status, created_by, requested_by, approved_by, approved_at)
             VALUES (?, ?, 'social_security', ?, ?, 'approved', ?, ?, ?, NOW())"
        );
        $this->db->execute($stmt, [
            $emp['branch_id'],
            $salaryDate,
            $ssoAmount,
            $note,
            $createdBy,
            $createdBy,
            $createdBy,
        ]);
        return $this->db->lastInsertId();
    }
}
