<?php
class PurchaseOrderCancellation extends Model
{
    protected $table = 'purchase_order_cancellation_requests';

    public function getAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['branch_id'])) {
            $where[] = 'po.branch_id = ?';
            $params[] = (int)$filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->db->fetchAll(
            "SELECT r.*, po.reference_no, po.branch_id, po.total_amount, po.user_id AS purchase_created_by,
                    po.created_at AS purchase_created_at,
                    b.name AS branch_name,
                    requester.full_name AS requested_by_name,
                    reviewer.full_name AS reviewed_by_name,
                    creator.full_name AS purchase_created_by_name
             FROM purchase_order_cancellation_requests r
             JOIN purchase_orders po ON po.id = r.purchase_order_id
             LEFT JOIN branches b ON b.id = po.branch_id
             LEFT JOIN users requester ON requester.id = r.requested_by
             LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
             LEFT JOIN users creator ON creator.id = po.user_id
             $whereSql
             ORDER BY CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END, r.requested_at DESC
             LIMIT 100",
            $params
        ) ?: [];
    }

    public function findRequestWithBranch(int $requestId): ?array
    {
        return $this->db->fetch(
            "SELECT r.id, r.requested_by, r.status,
                    po.user_id AS purchase_created_by, po.branch_id,
                    po.source_type, po.created_at AS purchase_created_at
             FROM purchase_order_cancellation_requests r
             JOIN purchase_orders po ON po.id = r.purchase_order_id
             WHERE r.id = ?",
            [$requestId]
        ) ?: null;
    }

    public function request(int $purchaseOrderId, string $reason, int $requesterId): array
    {
        $reason = substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new Exception('กรุณาระบุเหตุผลที่ขอยกเลิกใบรับซื้อ');
        }

        $this->db->beginTransaction();
        try {
            $po = $this->db->fetch(
                "SELECT id, reference_no, status, source_type, created_at
                 FROM purchase_orders WHERE id = ? FOR UPDATE",
                [$purchaseOrderId]
            );
            if (!$po) {
                throw new Exception('ไม่พบใบรับซื้อ');
            }
            if (($po['source_type'] ?? 'manual') !== 'manual') {
                throw new Exception('ใบรับซื้อจากการโอนสต็อกต้องแก้ไขด้วยใบโอนย้อนกลับ');
            }
            if (($po['status'] ?? '') === 'cancelled') {
                throw new Exception('ใบรับซื้อถูกยกเลิกไปแล้ว');
            }
            if (substr((string)$po['created_at'], 0, 10) !== date('Y-m-d')) {
                throw new Exception('ใบรับซื้อข้ามวันต้องใช้เอกสารปรับปรุงโดยผู้ดูแลระบบ');
            }

            $itemRows = $this->db->fetchAll(
                "SELECT consumed_qty
                 FROM purchase_order_items WHERE purchase_order_id = ? FOR UPDATE",
                [$purchaseOrderId]
            ) ?: [];
            $consumed = array_sum(array_map(static function ($row) {
                return (float)($row['consumed_qty'] ?? 0);
            }, $itemRows));
            if ($consumed > 0.000001) {
                throw new Exception('ไม่สามารถขอยกเลิกใบรับซื้อที่ถูกนำไปใช้ขายหรือโอนแล้ว');
            }

            $stmt = $this->db->prepare(
                "INSERT INTO purchase_order_cancellation_requests
                   (purchase_order_id, reason, requested_by)
                 VALUES (?, ?, ?)"
            );
            $this->db->execute($stmt, [$purchaseOrderId, $reason, $requesterId]);
            $requestId = (int)$this->db->lastInsertId();
            $this->db->commit();

            return [
                'id' => $requestId,
                'purchase_order_id' => $purchaseOrderId,
                'reference_no' => $po['reference_no'],
                'status' => 'pending',
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                throw new Exception('ใบรับซื้อนี้มีคำขอยกเลิกที่รอพิจารณาอยู่แล้ว');
            }
            throw $e;
        }
    }

    public function approve(int $requestId, int $approverId, ?string $reviewNote = null, bool $allowAdminSelfApproval = false): void
    {
        $this->db->beginTransaction();
        try {
            $request = $this->db->fetch(
                "SELECT r.*, po.user_id AS purchase_created_by, po.status AS purchase_status,
                        po.source_type, po.created_at AS purchase_created_at
                 FROM purchase_order_cancellation_requests r
                 JOIN purchase_orders po ON po.id = r.purchase_order_id
                 WHERE r.id = ? FOR UPDATE",
                [$requestId]
            );
            if (!$request) {
                throw new Exception('ไม่พบคำขอยกเลิกใบรับซื้อ');
            }
            if (($request['status'] ?? '') !== 'pending') {
                throw new Exception('คำขอนี้ถูกดำเนินการแล้ว');
            }
            if (!$allowAdminSelfApproval
                && ((int)$request['requested_by'] === $approverId
                    || (int)$request['purchase_created_by'] === $approverId)) {
                throw new Exception('ผู้สร้างรายการหรือผู้ส่งคำขอไม่สามารถอนุมัติรายการตัวเองได้');
            }
            if (($request['source_type'] ?? 'manual') !== 'manual') {
                throw new Exception('ใบรับซื้อจากการโอนสต็อกต้องแก้ไขด้วยใบโอนย้อนกลับ');
            }
            if (substr((string)$request['purchase_created_at'], 0, 10) !== date('Y-m-d')) {
                throw new Exception('ใบรับซื้อข้ามวันต้องใช้เอกสารปรับปรุงโดยผู้ดูแลระบบ');
            }

            (new PurchaseOrder())->cancelInCurrentTransaction((int)$request['purchase_order_id'], $approverId);
            $this->db->query(
                "UPDATE purchase_order_cancellation_requests
                 SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                 WHERE id = ?",
                [$approverId, $this->normalizeNote($reviewNote), $requestId]
            );
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reject(int $requestId, int $reviewerId, string $reviewNote): void
    {
        $reviewNote = substr(trim($reviewNote), 0, 500);
        if ($reviewNote === '') {
            throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        }

        $this->db->beginTransaction();
        try {
            $request = $this->db->fetch(
                "SELECT id, requested_by, status
                 FROM purchase_order_cancellation_requests WHERE id = ? FOR UPDATE",
                [$requestId]
            );
            if (!$request) {
                throw new Exception('ไม่พบคำขอยกเลิกใบรับซื้อ');
            }
            if (($request['status'] ?? '') !== 'pending') {
                throw new Exception('คำขอนี้ถูกดำเนินการแล้ว');
            }
            if ((int)$request['requested_by'] === $reviewerId) {
                throw new Exception('ผู้ส่งคำขอไม่สามารถพิจารณารายการตัวเองได้');
            }

            $this->db->query(
                "UPDATE purchase_order_cancellation_requests
                 SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                 WHERE id = ?",
                [$reviewerId, $reviewNote, $requestId]
            );
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string)$note);
        return $note === '' ? null : substr($note, 0, 500);
    }
}
