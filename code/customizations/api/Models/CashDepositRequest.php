<?php
class CashDepositRequest extends Model
{
    protected $table = 'cash_deposit_requests';

    public function getAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['branch_id'])) {
            $where[] = 'r.branch_id=?';
            $params[] = (int)$filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status=?';
            $params[] = $filters['status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return $this->db->fetchAll(
            "SELECT r.*, b.name AS branch_name, requester.full_name AS requested_by_name,
                    reviewer.full_name AS reviewed_by_name
             FROM cash_deposit_requests r
             JOIN branches b ON b.id=r.branch_id
             LEFT JOIN users requester ON requester.id=r.requested_by
             LEFT JOIN users reviewer ON reviewer.id=r.reviewed_by
             $whereSql ORDER BY CASE WHEN r.status='pending' THEN 0 ELSE 1 END, r.id DESC LIMIT 100",
            $params
        ) ?: [];
    }

    public function request(int $branchId, float $amount, string $sourceName, string $reason, int $userId): array
    {
        $sourceName = substr(trim($sourceName), 0, 200);
        $reason = substr(trim($reason), 0, 500);
        if (!is_finite($amount) || $amount <= 0 || $sourceName === '' || $reason === '') {
            throw new Exception('กรุณาระบุยอดเงิน แหล่งที่มา และเหตุผลให้ครบ');
        }
        $this->db->beginTransaction();
        try {
            $session = (new CashSession())->assertOpen($branchId);
            $stmt = $this->db->prepare(
                "INSERT INTO cash_deposit_requests
                   (branch_id,cash_session_id,amount,source_name,reason,requested_by)
                 VALUES (?,?,?,?,?,?)"
            );
            $this->db->execute($stmt, [
                $branchId, (int)$session['id'], round($amount, 2), $sourceName, $reason, $userId,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->db->commit();
            return ['id' => $id, 'status' => 'pending'];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function approve(int $id, int $approverId, ?string $reviewNote = null, bool $allowSelfApproval = false): void
    {
        $this->db->beginTransaction();
        try {
            $request = $this->db->fetch("SELECT * FROM cash_deposit_requests WHERE id=? FOR UPDATE", [$id]);
            if (!$request || $request['status'] !== 'pending') {
                throw new Exception('ไม่พบคำขอเติมเงินสดที่รออนุมัติ');
            }
            if (!$allowSelfApproval && (int)$request['requested_by'] === $approverId) {
                throw new Exception('ผู้ส่งคำขอไม่สามารถอนุมัติรายการตัวเองได้');
            }
            (new CashSession())->recordMovement(
                (int)$request['branch_id'], 'in', 'cash_deposit', (float)$request['amount'],
                'cash_deposit_request', $id,
                'เติมเงินสดจาก ' . $request['source_name'] . ': ' . $request['reason'], $approverId
            );
            $this->db->query(
                "UPDATE cash_deposit_requests SET status='approved', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?",
                [$approverId, $this->normalizeNote($reviewNote), $id]
            );
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reject(int $id, int $reviewerId, string $reviewNote): void
    {
        $reviewNote = trim($reviewNote);
        if ($reviewNote === '') throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        $stmt = $this->db->query(
            "UPDATE cash_deposit_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
             WHERE id=? AND status='pending' AND requested_by<>?",
            [$reviewerId, substr($reviewNote, 0, 500), $id, $reviewerId]
        );
        if (!$stmt->rowCount()) throw new Exception('ไม่สามารถปฏิเสธคำขอนี้ได้');
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string)$note);
        return $note === '' ? null : substr($note, 0, 500);
    }
}
