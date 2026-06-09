<?php
class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    public function getAll($filters = [])
    {
        $where = [];
        $params = [];
        if (!empty($filters['from_branch_id'])) {
            $where[] = "st.from_branch_id = ?";
            $params[] = $filters['from_branch_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = "st.status = ?";
            $params[] = $filters['status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return $this->db->fetchAll(
            "SELECT st.*,
                    fb.name AS from_branch_name, tb.name AS to_branch_name,
                    c.name AS category_name,
                    u1.full_name AS created_by_name, u2.full_name AS confirmed_by_name
             FROM stock_transfers st
             LEFT JOIN branches fb ON fb.id = st.from_branch_id
             LEFT JOIN branches tb ON tb.id = st.to_branch_id
             LEFT JOIN categories c ON c.id = st.category_id
             LEFT JOIN users u1 ON u1.id = st.created_by
             LEFT JOIN users u2 ON u2.id = st.confirmed_by
             $whereSql ORDER BY st.created_at DESC LIMIT 50",
            $params
        ) ?: [];
    }

    public function create($data, $userId)
    {
        // Sequential reference_no (ST-YYYYMMDD-NNN) — ป้องกัน collision จาก mt_rand
        $today = date('Ymd');
        $lastRef = $this->db->fetchColumn(
            "SELECT reference_no FROM stock_transfers
             WHERE reference_no LIKE ? ORDER BY id DESC LIMIT 1",
            ["ST-{$today}-%"]
        );
        $seq = $lastRef ? (intval(substr($lastRef, -3)) + 1) : 1;
        $ref = 'ST-' . $today . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare(
            "INSERT INTO stock_transfers (reference_no,from_branch_id,to_branch_id,category_id,weight_kg,note,created_by)
             VALUES (?,?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            $ref, $data['from_branch_id'], $data['to_branch_id'],
            $data['category_id'], $data['weight_kg'], $data['note'] ?? null, $userId
        ]);
        return ['id' => intval($this->db->lastInsertId()), 'reference_no' => $ref];
    }

    private function getTransferSellerId($branchId)
    {
        $idCard = 'TRANSFER0000';
        $sellerId = $this->db->fetchColumn(
            "SELECT id FROM sellers WHERE id_card = ? LIMIT 1",
            [$idCard]
        );
        if ($sellerId) return (int)$sellerId;

        $stmt = $this->db->prepare(
            "INSERT INTO sellers (id_card, full_name, notes, branch_id)
             VALUES (?, 'โอนสต็อกระหว่างสาขา', 'system placeholder สำหรับ stock transfer', ?)"
        );
        $this->db->execute($stmt, [$idCard, $branchId]);
        return (int)$this->db->lastInsertId();
    }

    public function confirm($id, $userId)
    {
        $st = $this->db->fetch("SELECT * FROM stock_transfers WHERE id = ? AND status = 'pending'", [$id]);
        if (!$st) throw new Exception('ไม่พบใบโอนหรือดำเนินการแล้ว');

        $fromBranch  = (int)$st['from_branch_id'];
        $toBranch    = (int)$st['to_branch_id'];
        $categoryId  = (int)$st['category_id'];
        $weightNeeded = (float)$st['weight_kg'];

        // ── 1. เช็คสต็อกจริงจาก PO items ต้นทาง (ไม่ใช่ global stock_kg) ──
        $availableRows = $this->db->fetchAll(
            "SELECT poi.id, (poi.quantity - poi.consumed_qty) AS avail, poi.unit_price
             FROM purchase_order_items poi
             JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ? AND poi.category_id = ?
               AND po.status = 'completed'
               AND (poi.quantity - poi.consumed_qty) > 0
             ORDER BY po.created_at ASC
             FOR UPDATE",
            [$fromBranch, $categoryId]
        );

        $totalAvail = array_sum(array_column($availableRows, 'avail'));
        if ($totalAvail < $weightNeeded) {
            throw new Exception(
                "สต็อกต้นทางไม่เพียงพอ (มี " . number_format($totalAvail, 2) .
                " กก. ต้องการ " . number_format($weightNeeded, 2) . " กก.)"
            );
        }

        $this->db->beginTransaction();
        try {
            // ── 2. หัก consumed_qty จาก PO items ต้นทาง (FIFO) ──
            //        พร้อมคำนวณ weighted avg cost ของที่โอน
            $remaining   = $weightNeeded;
            $totalCost   = 0.0;
            foreach ($availableRows as $row) {
                if ($remaining <= 0) break;
                $take = min((float)$row['avail'], $remaining);

                $stmt = $this->db->prepare(
                    "UPDATE purchase_order_items
                     SET consumed_qty = consumed_qty + ?
                     WHERE id = ? AND (quantity - consumed_qty) >= ?"
                );
                $this->db->execute($stmt, [$take, $row['id'], $take]);

                $totalCost += $take * (float)$row['unit_price'];
                $remaining -= $take;
            }

            $avgUnitPrice = $weightNeeded > 0 ? round($totalCost / $weightNeeded, 4) : 0;

            $transferSellerId = $this->getTransferSellerId($toBranch);

            // ── 3. สร้าง "transfer PO" ในสาขาปลายทาง ──
            //        ให้ FIFO ของปลายทางเดินต่อได้ตามปกติ
            $today    = date('Ymd');
            $lastSeq  = $this->db->fetchColumn(
                "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED))
                 FROM purchase_orders
                 WHERE reference_no LIKE ? AND branch_id = ?",
                ["PO-B{$toBranch}-{$today}-%", $toBranch]
            ) ?: 0;
            $refNo = "PO-B{$toBranch}-{$today}-" . str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);

            $poId = $this->db->fetchColumn(
                "SELECT id FROM purchase_orders WHERE reference_no = ?", [$refNo]
            );
            if (!$poId) {
                $stmt = $this->db->prepare(
                    "INSERT INTO purchase_orders
                       (reference_no, branch_id, seller_id, user_id,
                        total_items, total_amount, payment_method, payment_status, status, notes)
                     VALUES (?, ?, ?, ?, 1, ?, 'cash', 'paid', 'completed', ?)"
                );
                $this->db->execute($stmt, [
                    $refNo, $toBranch, $transferSellerId, $userId,
                    round($totalCost, 2),
                    "โอนสต็อกจากสาขา {$fromBranch} (ST: {$st['reference_no']})",
                ]);
                $poId = (int)$this->db->lastInsertId();
            }

            $stmt = $this->db->prepare(
                "INSERT INTO purchase_order_items
                   (purchase_order_id, item_name, category_id,
                    quantity, unit_price, total_price, consumed_qty, unit)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 'กก.')"
            );
            $this->db->execute($stmt, [
                $poId,
                "โอนสต็อก (ST: {$st['reference_no']})",
                $categoryId,
                $weightNeeded,
                $avgUnitPrice,
                round($totalCost, 2),
            ]);

            // ── 4. อัปเดตสถานะ transfer ──
            $stmt = $this->db->prepare(
                "UPDATE stock_transfers
                 SET status='confirmed', confirmed_by=?, confirmed_at=NOW()
                 WHERE id=?"
            );
            $this->db->execute($stmt, [$userId, $id]);

            // ── 5. stock_kg global ไม่เปลี่ยน (ของยังอยู่ในระบบ แค่ย้ายสาขา) ──

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel($id)
    {
        $stmt = $this->db->prepare("UPDATE stock_transfers SET status='cancelled' WHERE id=? AND status='pending'");
        $this->db->execute($stmt, [$id]);
    }
}
