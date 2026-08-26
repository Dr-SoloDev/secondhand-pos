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

    public function request(int $branchId, float $amount, string $sourceType,
        string $sourceName, string $reason, int $userId): array
    {
        $sourceName = substr(trim($sourceName), 0, 200);
        $reason = substr(trim($reason), 0, 500);
        if (!is_finite($amount) || $amount <= 0 || $sourceName === '' || $reason === '') {
            throw new Exception('กรุณาระบุยอดเงิน แหล่งที่มา และเหตุผลให้ครบ');
        }
        $cashSession = new CashSession();
        if ($cashSession->hasPositionModel($branchId)
            && !in_array($sourceType, ['reserve_transfer', 'owner_capital', 'drawer_to_reserve', 'drawer_to_owner'], true)) {
            throw new Exception('กรุณาระบุประเภทของรายการเงินให้ถูกต้อง');
        }
        if (!$cashSession->hasPositionModel($branchId)) $sourceType = null;
        $this->db->beginTransaction();
        try {
            $session = $cashSession->assertOpen($branchId);
            $stmt = $this->db->prepare(
                "INSERT INTO cash_deposit_requests
                   (branch_id,cash_session_id,amount,source_type,source_name,reason,requested_by)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $this->db->execute($stmt, [
                $branchId, (int)$session['id'], round($amount, 2), $sourceType, $sourceName, $reason, $userId,
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
            $cashSession = new CashSession();
            $sourceType = (string)($request['source_type'] ?? '');
            $amount = (float)$request['amount'];
            $excess = 0.0;
            if ($sourceType === 'owner_capital' && $cashSession->hasPositionModel((int)$request['branch_id'])) {
                $balances = $cashSession->getPositionBalances((int)$request['branch_id']);
                // โมเดลใหม่: เติมเงิน = ดึงจากเซฟ — เกินเซฟ = เพิ่มทุนใหม่ (รายรับเพิ่มทุน)
                $existing = (float)($balances['reserve_balance'] ?? 0);
                $excess = max(0.0, round($amount, 2) - round($existing, 2));
                // บันทึก excess ไว้ก่อน (สำหรับรายงาน)
                $this->db->query("UPDATE cash_deposit_requests SET excess_amount=? WHERE id=?", [round($excess, 2), (int)$id]);
            }
            if (in_array($sourceType, ['drawer_to_reserve', 'drawer_to_owner'], true)) {
                $cashSession->transferDrawerOut(
                    (int)$request['branch_id'], $amount,
                    $sourceType === 'drawer_to_reserve' ? 'business_reserve' : 'external',
                    'นำเงินออกจากลิ้นชัก: ' . $request['source_name'] . ': ' . $request['reason'],
                    $approverId, (int)$id, 'cash_deposit_request'
                );
            } else {
                $cashSession->fundDrawer(
                    (int)$request['branch_id'], $amount,
                    $sourceType, $id,
                    'นำเงินเข้าลิ้นชักจาก ' . $request['source_name'] . ': ' . $request['reason'], $approverId, $excess
                );
            }
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

    public function reject(int $id, int $reviewerId, string $reviewNote, bool $allowSelfApproval = false): void
    {
        $reviewNote = trim($reviewNote);
        if ($reviewNote === '') throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        if ($allowSelfApproval) {
            $stmt = $this->db->query(
                "UPDATE cash_deposit_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
                 WHERE id=? AND status='pending'",
                [$reviewerId, substr($reviewNote, 0, 500), $id]
            );
        } else {
            $stmt = $this->db->query(
                "UPDATE cash_deposit_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
                 WHERE id=? AND status='pending' AND requested_by<>?",
                [$reviewerId, substr($reviewNote, 0, 500), $id, $reviewerId]
            );
        }
        if (!$stmt->rowCount()) throw new Exception('ไม่สามารถปฏิเสธคำขอนี้ได้');
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string)$note);
        return $note === '' ? null : substr($note, 0, 500);
    }
}
