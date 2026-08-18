<?php
class BusinessExpense extends Model
{
    protected $table = 'business_expenses';

    public function findById($id)
    {
        $row = $this->db->fetch("SELECT * FROM business_expenses WHERE id=?", [$id]);
        return $row ?: null;
    }

    public function listByPeriod($branchId, $period, $year, $month = null)
    {
        $sql = "SELECT be.*, b.name AS branch_name,
                       requester.full_name AS requested_by_name,
                       approver.full_name AS approved_by_name
                FROM business_expenses be
                JOIN branches b ON b.id = be.branch_id
                LEFT JOIN users requester ON requester.id = be.requested_by
                LEFT JOIN users approver ON approver.id = be.approved_by
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
                FROM business_expenses WHERE status = 'approved'";
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

    public function createRequest(array $data, int $requesterId): int
    {
        if (($data['expense_date'] ?? '') !== date('Y-m-d')) {
            throw new Exception('รายจ่ายข้ามวันต้องบันทึกด้วยเอกสารปรับปรุงโดยผู้ดูแลระบบ');
        }
        $paymentMethod = $data['payment_method'] ?? '';
        if (!in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
            throw new Exception('วิธีจ่ายเงินไม่ถูกต้อง');
        }
        $beneficiary = trim((string)($data['beneficiary_name'] ?? ''));
        if ($beneficiary === '') {
            throw new Exception('กรุณาระบุชื่อผู้เบิกหรือผู้รับเงิน');
        }

        $this->db->beginTransaction();
        try {
            if ($paymentMethod === 'cash') {
                (new CashSession())->assertOpen((int)$data['branch_id']);
            }
            $stmt = $this->db->prepare(
                "INSERT INTO business_expenses
                   (branch_id,expense_date,category,amount,payment_method,beneficiary_name,note,status,created_by,requested_by)
                 VALUES (?,?,?,?,?,?,?,'pending',?,?)"
            );
            $this->db->execute($stmt, [
                (int)$data['branch_id'], $data['expense_date'], $data['category'], round((float)$data['amount'], 2),
                $paymentMethod, substr($beneficiary, 0, 200), $data['note'] ?? null, $requesterId, $requesterId,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function approve(int $id, int $approverId, string $approverRole, ?string $reviewNote = null, bool $allowSelfApproval = false): void
    {
        $this->db->beginTransaction();
        try {
            $expense = $this->db->fetch("SELECT * FROM business_expenses WHERE id=? FOR UPDATE", [$id]);
            if (!$expense || $expense['status'] !== 'pending') {
                throw new Exception('ไม่พบคำขอรายจ่ายที่รออนุมัติ');
            }
            if (!$allowSelfApproval && (int)$expense['requested_by'] === $approverId) {
                throw new Exception('ผู้ส่งคำขอไม่สามารถอนุมัติรายการตัวเองได้');
            }
            $amount = (float)$expense['amount'];
            $allowed = $amount <= 500
                ? in_array($approverRole, ['manager', 'super_manager', 'admin'], true)
                : ($amount <= 5000
                    ? in_array($approverRole, ['super_manager', 'admin'], true)
                    : $approverRole === 'admin');
            if (!$allowed) {
                throw new Exception('ระดับสิทธิ์ไม่เพียงพอสำหรับอนุมัติยอดนี้');
            }

            $cashSession = new CashSession();
            if ($expense['payment_method'] === 'cash') {
                $cashSession->assertOpen((int)$expense['branch_id']);
            }
            $this->db->query(
                "UPDATE business_expenses
                 SET status='approved', approved_by=?, approved_at=NOW(), review_note=? WHERE id=?",
                [$approverId, $this->normalizeNote($reviewNote), $id]
            );
            if ($expense['payment_method'] === 'cash') {
                $cashSession->recordMovement(
                    (int)$expense['branch_id'], 'out', 'business_expense', $amount,
                    'business_expense', $id,
                    'จ่ายเงินสด ' . $expense['category'] . ' ให้ ' . $expense['beneficiary_name'],
                    $approverId
                );
            } elseif ($expense['payment_method'] === 'bank_transfer') {
                $cashSession->recordBankMovement(
                    (int)$expense['branch_id'], 'out', $amount, 'bank_business_expense',
                    $id, 'จ่ายเงินโอน ' . $expense['category'] . ' ให้ ' . $expense['beneficiary_name'],
                    $approverId
                );
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reject(int $id, int $reviewerId, string $reviewNote): void
    {
        $reviewNote = trim($reviewNote);
        if ($reviewNote === '') {
            throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        }
        $stmt = $this->db->query(
            "UPDATE business_expenses
             SET status='rejected', approved_by=?, approved_at=NOW(), review_note=?
             WHERE id=? AND status='pending' AND requested_by<>?",
            [$reviewerId, substr($reviewNote, 0, 500), $id, $reviewerId]
        );
        if (!$stmt->rowCount()) {
            throw new Exception('ไม่สามารถปฏิเสธรายการนี้ หรือเป็นคำขอของผู้ใช้คนเดียวกัน');
        }
    }

    public function cancelRequest(int $id, int $requesterId): void
    {
        $stmt = $this->db->query(
            "UPDATE business_expenses SET status='cancelled'
             WHERE id=? AND status='pending' AND requested_by=?",
            [$id, $requesterId]
        );
        if (!$stmt->rowCount()) {
            throw new Exception('ยกเลิกได้เฉพาะคำขอที่ตนเองสร้างและยังไม่ถูกพิจารณา');
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string)$note);
        return $note === '' ? null : substr($note, 0, 500);
    }
}
