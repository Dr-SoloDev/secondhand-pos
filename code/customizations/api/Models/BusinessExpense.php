<?php
class BusinessExpense extends Model
{
    protected $table = 'business_expenses';

    public function listByPeriod($branchId, $period, $year, $month = null)
    {
        $sql = "SELECT be.*, b.name AS branch_name
                FROM business_expenses be
                JOIN branches b ON b.id = be.branch_id
                WHERE 1=1";
        $bindings = [];

        if ($branchId) {
            $sql .= " AND be.branch_id = ?";
            $bindings[] = $branchId;
        }
        if ($period === 'month' && $month) {
            $sql .= " AND YEAR(be.expense_date) = ? AND MONTH(be.expense_date) = ?";
            $bindings[] = $year;
            $bindings[] = $month;
        } else {
            $sql .= " AND YEAR(be.expense_date) = ?";
            $bindings[] = $year;
        }

        $sql .= " ORDER BY be.expense_date DESC, be.id DESC";
        return $this->db->fetchAll($sql, $bindings) ?: [];
    }

    public function sumByPeriod($branchId, $period, $year, $month = null)
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total
                FROM business_expenses WHERE 1=1";
        $bindings = [];

        if ($branchId) {
            $sql .= " AND branch_id = ?";
            $bindings[] = $branchId;
        }
        if ($period === 'month' && $month) {
            $sql .= " AND YEAR(expense_date) = ? AND MONTH(expense_date) = ?";
            $bindings[] = $year;
            $bindings[] = $month;
        } else {
            $sql .= " AND YEAR(expense_date) = ?";
            $bindings[] = $year;
        }

        $row = $this->db->fetch($sql, $bindings);
        return $row['total'] ?? 0;
    }

    public function create($data)
    {
        $stmt = $this->db->prepare(
            "INSERT INTO business_expenses (branch_id, expense_date, category, amount, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $this->db->execute($stmt, [
            $data['branch_id'],
            $data['expense_date'],
            $data['category'],
            $data['amount'],
            $data['note'] ?? null,
            $data['created_by'] ?? null,
        ]);
        return $this->db->lastInsertId();
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM business_expenses WHERE id = ?");
        $this->db->execute($stmt, [$id]);
    }
}
