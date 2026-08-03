<?php
class AdjustmentDocument extends Model
{
    protected $table = 'adjustment_documents';

    public function getAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['branch_id'])) {
            $where[] = 'ad.branch_id = ?';
            $params[] = (int)$filters['branch_id'];
        }
        if (!empty($filters['adjustment_type'])) {
            $where[] = 'ad.adjustment_type = ?';
            $params[] = $filters['adjustment_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'ad.effective_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'ad.effective_date <= ?';
            $params[] = $filters['date_to'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $this->db->fetchAll(
            "SELECT ad.*, b.name AS branch_name, u.full_name AS created_by_name
             FROM adjustment_documents ad
             JOIN branches b ON b.id = ad.branch_id
             LEFT JOIN users u ON u.id = ad.created_by
             $whereSql
             ORDER BY ad.created_at DESC, ad.id DESC LIMIT 200",
            $params
        ) ?: [];
    }

    public function cancelHistoricalPurchaseOrder(string $target, string $reason, int $adminId): array
    {
        $reason = $this->requiredReason($reason);
        $this->db->beginTransaction();
        try {
            $po = $this->findPurchaseOrderForUpdate($target);
            if (!$po) {
                throw new Exception('ไม่พบใบรับซื้อที่ระบุ');
            }
            if (substr((string)$po['created_at'], 0, 10) >= date('Y-m-d')) {
                throw new Exception('ใบรับซื้อของวันนี้ต้องใช้ขั้นตอนคำขอยกเลิกและรอผู้มีอำนาจอนุมัติ');
            }
            if (($po['source_type'] ?? 'manual') !== 'manual') {
                throw new Exception('ใบรับซื้อจากการโอนสต็อกต้องแก้ไขด้วยใบโอนย้อนกลับ');
            }

            $document = $this->insertDocument([
                'branch_id' => (int)$po['branch_id'],
                'adjustment_type' => 'purchase_order_cancellation',
                'effective_date' => substr((string)$po['created_at'], 0, 10),
                'target_type' => 'purchase_order',
                'target_id' => (int)$po['id'],
                'amount_before' => (float)$po['total_amount'],
                'amount_after' => 0,
                'payment_method_before' => $po['payment_method'],
                'payment_method_after' => $po['payment_method'],
                'reason' => $reason,
                'details' => ['reference_no' => $po['reference_no'], 'previous_status' => $po['status']],
            ], $adminId);

            (new PurchaseOrder())->cancelInCurrentTransaction((int)$po['id'], $adminId);
            $this->db->commit();
            return $document;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function correctSaleLotRevenue(string $target, float $newAmount, string $newPaymentMethod,
        string $effectiveDate, string $reason, ?string $note, int $adminId): array
    {
        if (!is_finite($newAmount) || $newAmount < 0) {
            throw new Exception('ยอดรายรับใหม่ไม่ถูกต้อง');
        }
        $this->assertPaymentMethod($newPaymentMethod);
        $effectiveDate = $this->validDate($effectiveDate, false);
        $reason = $this->requiredReason($reason);

        $this->db->beginTransaction();
        try {
            $lot = $this->findSaleLotForUpdate($target);
            if (!$lot) {
                throw new Exception('ไม่พบ Sale Lot ที่ระบุ');
            }
            if ($lot['status'] !== 'confirmed' || $lot['actual_revenue'] === null) {
                throw new Exception('แก้รายรับได้เฉพาะ Lot ที่ยืนยันและบันทึกรายรับแล้ว');
            }
            $oldAmount = (float)$lot['actual_revenue'];
            $oldPaymentMethod = $lot['revenue_payment_method'] ?: 'bank_transfer';
            if (abs($oldAmount - $newAmount) < 0.005 && $oldPaymentMethod === $newPaymentMethod
                && (string)$lot['actual_revenue_date'] === $effectiveDate) {
                throw new Exception('ข้อมูลใหม่ไม่แตกต่างจากรายการเดิม');
            }

            $cashSession = new CashSession();
            $cashSession->assertOpen((int)$lot['branch_id']);
            $document = $this->insertDocument([
                'branch_id' => (int)$lot['branch_id'],
                'adjustment_type' => 'sale_lot_revenue_correction',
                'effective_date' => $effectiveDate,
                'target_type' => 'sale_lot',
                'target_id' => (int)$lot['id'],
                'amount_before' => $oldAmount,
                'amount_after' => $newAmount,
                'payment_method_before' => $oldPaymentMethod,
                'payment_method_after' => $newPaymentMethod,
                'reason' => $reason,
                'details' => [
                    'reference_no' => $lot['reference_no'],
                    'previous_date' => $lot['actual_revenue_date'],
                    'previous_note' => $lot['actual_revenue_note'],
                    'new_note' => $note,
                ],
            ], $adminId);

            $oldCash = $oldPaymentMethod === 'cash' ? $oldAmount : 0.0;
            $newCash = $newPaymentMethod === 'cash' ? $newAmount : 0.0;
            $cashDifference = round($newCash - $oldCash, 2);
            if (abs($cashDifference) > 0.009) {
                $direction = $cashDifference > 0 ? 'in' : 'out';
                $cashSession->recordMovement(
                    (int)$lot['branch_id'], $direction, 'sale_revenue_adjustment', abs($cashDifference),
                    'adjustment_document', (int)$document['id'],
                    'ปรับปรุงรายรับ LOT ' . $lot['reference_no'] . ' เอกสาร ' . $document['reference_no'],
                    $adminId
                );
            }

            $this->db->query(
                "UPDATE sale_lots
                 SET actual_revenue=?, revenue_payment_method=?, actual_revenue_date=?, actual_revenue_note=?, updated_at=NOW()
                 WHERE id=?",
                [round($newAmount, 2), $newPaymentMethod, $effectiveDate, $this->optionalNote($note), (int)$lot['id']]
            );
            $this->db->commit();
            return $document;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function createHistoricalExpense(array $data, int $adminId): array
    {
        $effectiveDate = $this->validDate((string)($data['expense_date'] ?? ''), true);
        $amount = (float)($data['amount'] ?? 0);
        if (!is_finite($amount) || $amount <= 0) {
            throw new Exception('จำนวนเงินรายจ่ายไม่ถูกต้อง');
        }
        $paymentMethod = (string)($data['payment_method'] ?? '');
        $this->assertPaymentMethod($paymentMethod);
        $beneficiary = trim((string)($data['beneficiary_name'] ?? ''));
        $category = trim((string)($data['category'] ?? ''));
        if (!$data['branch_id'] || $beneficiary === '' || $category === '') {
            throw new Exception('กรุณาระบุสาขา ประเภท และผู้รับเงินให้ครบ');
        }
        $reason = $this->requiredReason((string)($data['reason'] ?? ''));

        $this->db->beginTransaction();
        try {
            $cashSession = new CashSession();
            $cashSession->assertOpen((int)$data['branch_id']);
            $document = $this->insertDocument([
                'branch_id' => (int)$data['branch_id'],
                'adjustment_type' => 'historical_expense',
                'effective_date' => $effectiveDate,
                'target_type' => 'business_expense',
                'target_id' => null,
                'amount_before' => 0,
                'amount_after' => $amount,
                'payment_method_before' => null,
                'payment_method_after' => $paymentMethod,
                'reason' => $reason,
                'details' => ['category' => $category, 'beneficiary_name' => $beneficiary, 'note' => $data['note'] ?? null],
            ], $adminId);

            $stmt = $this->db->prepare(
                "INSERT INTO business_expenses
                   (branch_id,expense_date,category,amount,payment_method,beneficiary_name,note,status,
                    created_by,requested_by,approved_by,approved_at,review_note,adjustment_document_id)
                 VALUES (?,?,?,?,?,?,?,'approved',?,?,?,NOW(),?,?)"
            );
            $this->db->execute($stmt, [
                (int)$data['branch_id'], $effectiveDate, substr($category, 0, 50), round($amount, 2),
                $paymentMethod, substr($beneficiary, 0, 200), $this->optionalNote($data['note'] ?? null),
                $adminId, $adminId, $adminId, 'เอกสารปรับปรุง ' . $document['reference_no'] . ': ' . $reason,
                (int)$document['id'],
            ]);
            $expenseId = (int)$this->db->lastInsertId();
            $this->db->query(
                "UPDATE adjustment_documents SET target_id=? WHERE id=?",
                [$expenseId, (int)$document['id']]
            );

            if ($paymentMethod === 'cash') {
                $cashSession->recordMovement(
                    (int)$data['branch_id'], 'out', 'historical_expense_adjustment', $amount,
                    'adjustment_document', (int)$document['id'],
                    'รายจ่ายย้อนหลัง ' . $category . ' เอกสาร ' . $document['reference_no'], $adminId
                );
            }
            $this->db->commit();
            $document['target_id'] = $expenseId;
            return $document;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertDocument(array $data, int $adminId): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO adjustment_documents
               (reference_no,branch_id,adjustment_type,effective_date,target_type,target_id,
                amount_before,amount_after,payment_method_before,payment_method_after,reason,details_json,created_by)
             VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            $data['branch_id'], $data['adjustment_type'], $data['effective_date'], $data['target_type'], $data['target_id'],
            $data['amount_before'], $data['amount_after'], $data['payment_method_before'], $data['payment_method_after'],
            $data['reason'], json_encode($data['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId,
        ]);
        $id = (int)$this->db->lastInsertId();
        $referenceNo = 'ADJ-' . date('Ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        $this->db->query("UPDATE adjustment_documents SET reference_no=? WHERE id=?", [$referenceNo, $id]);
        return [
            'id' => $id,
            'reference_no' => $referenceNo,
            'branch_id' => (int)$data['branch_id'],
            'status' => 'posted',
        ];
    }

    private function findPurchaseOrderForUpdate(string $target): ?array
    {
        $sql = ctype_digit($target)
            ? "SELECT * FROM purchase_orders WHERE id=? FOR UPDATE"
            : "SELECT * FROM purchase_orders WHERE reference_no=? FOR UPDATE";
        return $this->db->fetch($sql, [ctype_digit($target) ? (int)$target : $target]) ?: null;
    }

    private function findSaleLotForUpdate(string $target): ?array
    {
        $sql = ctype_digit($target)
            ? "SELECT * FROM sale_lots WHERE id=? FOR UPDATE"
            : "SELECT * FROM sale_lots WHERE reference_no=? FOR UPDATE";
        return $this->db->fetch($sql, [ctype_digit($target) ? (int)$target : $target]) ?: null;
    }

    private function validDate(string $date, bool $mustBeHistorical): string
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            throw new Exception('วันที่เอกสารไม่ถูกต้อง');
        }
        if ($mustBeHistorical && $date >= date('Y-m-d')) {
            throw new Exception('รายจ่ายของวันนี้ต้องใช้ขั้นตอนคำขอรายจ่ายปกติ');
        }
        return $date;
    }

    private function assertPaymentMethod(string $method): void
    {
        if (!in_array($method, ['cash', 'bank_transfer'], true)) {
            throw new Exception('วิธีรับหรือจ่ายเงินไม่ถูกต้อง');
        }
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception('กรุณาระบุเหตุผลของเอกสารปรับปรุง');
        }
        return substr($reason, 0, 500);
    }

    private function optionalNote(?string $note): ?string
    {
        $note = trim((string)$note);
        return $note === '' ? null : substr($note, 0, 500);
    }
}
