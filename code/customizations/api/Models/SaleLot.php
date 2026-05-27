<?php
class SaleLot extends Model
{
    protected $table = 'sale_lots';

    // ดึงรายการ Sale Lots ทั้งหมด รองรับกรองตาม branch, status และช่วงวันที่
    public function getAll($branch_id, $filters = [])
    {
        $where = ["sl.branch_id = ?"];
        $params = [$branch_id];

        if (!empty($filters['status'])) {
            $where[] = "sl.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(sl.sale_date) >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(sl.sale_date) <= ?";
            $params[] = $filters['date_to'];
        }
        $whereSql = implode(' AND ', $where);

        $items = $this->db->fetchAll(
            "SELECT sl.*,
                    b.name AS branch_name,
                    u.full_name AS created_by_name,
                    (sl.total_amount - sl.total_cost) AS profit
             FROM {$this->table} sl
             LEFT JOIN branches b ON sl.branch_id = b.id
             LEFT JOIN users u ON sl.created_by = u.id
             WHERE {$whereSql}
             ORDER BY sl.created_at DESC",
            $params
        );

        return $items;
    }

    // ดึง Sale Lot เดียวพร้อมรายการสินค้าและข้อมูลกำไร
    public function getById($id)
    {
        $lot = $this->db->fetch(
            "SELECT sl.*,
                    b.name AS branch_name,
                    u.full_name AS created_by_name,
                    (sl.total_amount - sl.total_cost) AS profit
             FROM {$this->table} sl
             LEFT JOIN branches b ON sl.branch_id = b.id
             LEFT JOIN users u ON sl.created_by = u.id
             WHERE sl.id = ?",
            [$id]
        );
        if (!$lot) return null;

        $lot['items'] = $this->db->fetchAll(
            "SELECT sli.*,
                    c.name AS category_name
             FROM sale_lot_items sli
             LEFT JOIN categories c ON sli.category_id = c.id
             WHERE sli.sale_lot_id = ?
             ORDER BY sli.id ASC",
            [$id]
        );

        // สรุปกำไรแยกรายหมวดหมู่
        $lot['profit_breakdown'] = [
            'total_amount' => (float)$lot['total_amount'],
            'total_cost'   => (float)$lot['total_cost'],
            'profit'       => (float)$lot['total_amount'] - (float)$lot['total_cost'],
            'margin_pct'   => $lot['total_amount'] > 0
                ? round((((float)$lot['total_amount'] - (float)$lot['total_cost']) / (float)$lot['total_amount']) * 100, 2)
                : 0,
        ];

        return $lot;
    }

    // สร้าง Sale Lot ใหม่พร้อมรายการสินค้า คำนวณ FIFO cost และบันทึก
    public function create($data)
    {
        // ตรวจสอบฟิลด์ที่จำเป็น
        $required = ['branch_id', 'buyer_name', 'sale_date', 'items'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("ต้องระบุ {$field}");
            }
        }
        if (!is_array($data['items']) || count($data['items']) === 0) {
            throw new Exception('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $this->db->beginTransaction();
        try {
            $branchId    = intval($data['branch_id']);
            $totalAmount = 0;
            $totalCost   = 0;

            // คำนวณ FIFO cost และราคารวมแต่ละรายการล่วงหน้า
            $preparedItems = [];
            foreach ($data['items'] as $item) {
                $qty       = (float)($item['quantity_kg'] ?? 0);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $catId     = intval($item['category_id']);

                if ($qty <= 0 || $catId <= 0) {
                    throw new Exception('รายการสินค้าต้องมี category_id และ quantity_kg ที่ถูกต้อง');
                }

                $subtotal  = $qty * $unitPrice;
                $fifoCost  = $this->calculateFifoCost($branchId, $catId, $qty);

                $totalAmount += $subtotal;
                $totalCost   += $fifoCost;

                $preparedItems[] = [
                    'category_id'  => $catId,
                    'quantity_kg'  => $qty,
                    'unit_price'   => $unitPrice,
                    'subtotal'     => $subtotal,
                    'fifo_cost'    => $fifoCost,
                ];
            }

            $referenceNo = $this->generateReferenceNo($branchId);

            $lotId = $this->insert([
                'reference_no' => $referenceNo,
                'branch_id'    => $branchId,
                'buyer_name'   => trim((string)$data['buyer_name']),
                'sale_date'    => $data['sale_date'],
                'total_amount' => $totalAmount,
                'total_cost'   => $totalCost,
                'status'       => $data['status'] ?? 'draft',
                'notes'        => isset($data['notes']) ? trim((string)$data['notes']) : null,
                'created_by'   => $data['created_by'] ?? null,
            ]);

            foreach ($preparedItems as $item) {
                $this->db->insert('sale_lot_items', [
                    'sale_lot_id'  => $lotId,
                    'category_id'  => $item['category_id'],
                    'quantity_kg'  => $item['quantity_kg'],
                    'unit_price'   => $item['unit_price'],
                    'subtotal'     => $item['subtotal'],
                    'fifo_cost'    => $item['fifo_cost'],
                ]);
            }

            $this->db->commit();
            return ['id' => $lotId, 'reference_no' => $referenceNo, 'total_amount' => $totalAmount, 'total_cost' => $totalCost];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // อัปเดต Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function update($id, $data)
    {
        $lot = $this->db->fetch(
            "SELECT id, status, branch_id FROM {$this->table} WHERE id = ?",
            [$id]
        );
        if (!$lot) {
            throw new Exception('ไม่พบ Sale Lot');
        }
        if ($lot['status'] !== 'draft') {
            throw new Exception('แก้ไขได้เฉพาะ Sale Lot ที่มีสถานะ draft เท่านั้น');
        }

        $this->db->beginTransaction();
        try {
            $branchId    = intval($lot['branch_id']);
            $totalAmount = 0;
            $totalCost   = 0;
            $status      = $data['status'] ?? 'draft';
            $isConfirming = ($status === 'confirmed');

            // ลบรายการเดิมก่อนบันทึกใหม่
            $this->db->query("DELETE FROM sale_lot_items WHERE sale_lot_id = ?", [$id]);

            $preparedItems = [];
            foreach ($data['items'] as $item) {
                $qty       = (float)($item['quantity_kg'] ?? 0);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $catId     = intval($item['category_id']);

                if ($qty <= 0 || $catId <= 0) {
                    throw new Exception('รายการสินค้าต้องมี category_id และ quantity_kg ที่ถูกต้อง');
                }

                $subtotal = $qty * $unitPrice;
                $fifoCost = $isConfirming ? $this->calculateFifoCost($branchId, $catId, $qty) : 0;

                $totalAmount += $subtotal;
                $totalCost   += $fifoCost;

                $preparedItems[] = [
                    'category_id' => $catId,
                    'quantity_kg' => $qty,
                    'unit_price'  => $unitPrice,
                    'subtotal'    => $subtotal,
                    'fifo_cost'   => $fifoCost,
                ];
            }

            $this->db->query(
                "UPDATE {$this->table}
                 SET buyer_name = ?, sale_date = ?, total_amount = ?, total_cost = ?, notes = ?, status = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    trim((string)($data['buyer_name'] ?? $lot['buyer_name'] ?? '')),
                    $data['sale_date'],
                    $totalAmount,
                    $totalCost,
                    isset($data['notes']) ? trim((string)$data['notes']) : null,
                    $status,
                    $id,
                ]
            );

            foreach ($preparedItems as $item) {
                $this->db->insert('sale_lot_items', [
                    'sale_lot_id' => $id,
                    'category_id' => $item['category_id'],
                    'quantity_kg' => $item['quantity_kg'],
                    'unit_price'  => $item['unit_price'],
                    'subtotal'    => $item['subtotal'],
                    'fifo_cost'   => $item['fifo_cost'],
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // เปลี่ยนสถานะ: draft->confirmed ตัดสต็อก, confirmed->cancelled คืนสต็อก
    public function updateStatus($id, $status)
    {
        $lot = $this->db->fetch(
            "SELECT id, status FROM {$this->table} WHERE id = ?",
            [$id]
        );
        if (!$lot) {
            throw new Exception('ไม่พบ Sale Lot');
        }

        $allowed = [
            'draft'     => ['confirmed'],
            'confirmed' => ['cancelled'],
        ];

        if (!isset($allowed[$lot['status']]) || !in_array($status, $allowed[$lot['status']])) {
            throw new Exception("ไม่สามารถเปลี่ยนสถานะจาก {$lot['status']} เป็น {$status} ได้");
        }

        $this->db->beginTransaction();
        try {
            $this->db->query(
                "UPDATE {$this->table} SET status = ?, updated_at = NOW() WHERE id = ?",
                [$status, $id]
            );

            if ($status === 'confirmed') {
                $this->deductStock($id);
            } elseif ($status === 'cancelled') {
                $this->restoreStock($id);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // คำนวณต้นทุน FIFO สำหรับหมวดหมู่และปริมาณที่ต้องการ
    public function calculateFifoCost($branch_id, $category_id, $quantity_kg)
    {
        // ดึง purchase_order_items ที่ยังมีสต็อกเหลือ เรียงตามวันเก่าสุดก่อน (FIFO)
        $rows = $this->db->fetchAll(
            "SELECT poi.id,
                    poi.quantity,
                    poi.unit_price,
                    poi.consumed_qty
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ?
               AND poi.category_id = ?
               AND po.status = 'completed'
               AND (poi.quantity - poi.consumed_qty) > 0
             ORDER BY po.created_at ASC",
            [$branch_id, $category_id]
        );

        $remaining = (float)$quantity_kg;
        $totalCost = 0.0;

        foreach ($rows as $row) {
            if ($remaining <= 0) break;

            $available = (float)$row['quantity'] - (float)$row['consumed_qty'];
            $take      = min($available, $remaining);
            $totalCost += $take * (float)$row['unit_price'];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new Exception("สต็อกหมวดหมู่ ID {$category_id} ไม่เพียงพอ (ขาด {$remaining} กก.)");
        }

        return $totalCost;
    }

    // ตัดสต็อกเมื่อยืนยัน Sale Lot โดยบันทึก consumed_qty ใน purchase_order_items
    public function deductStock($sale_lot_id)
    {
        $items = $this->db->fetchAll(
            "SELECT category_id, quantity_kg
             FROM sale_lot_items
             WHERE sale_lot_id = ?",
            [$sale_lot_id]
        );

        $lot = $this->db->fetch(
            "SELECT branch_id FROM {$this->table} WHERE id = ?",
            [$sale_lot_id]
        );

        foreach ($items as $item) {
            $remaining = (float)$item['quantity_kg'];

            // ดึง PO items ที่ยังมีสต็อกเหลือสำหรับหมวดหมู่นี้
            $rows = $this->db->fetchAll(
                "SELECT poi.id,
                        poi.quantity,
                        poi.consumed_qty
                 FROM purchase_order_items poi
                 INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
                 WHERE po.branch_id = ?
                   AND poi.category_id = ?
                   AND po.status = 'completed'
                   AND (poi.quantity - poi.consumed_qty) > 0
                 ORDER BY po.created_at ASC",
                [$lot['branch_id'], $item['category_id']]
            );

            foreach ($rows as $row) {
                if ($remaining <= 0) break;

                $available = (float)$row['quantity'] - (float)$row['consumed_qty'];
                $take      = min($available, $remaining);

                $this->db->query(
                    "UPDATE purchase_order_items
                     SET consumed_qty = consumed_qty + ?
                     WHERE id = ?",
                    [$take, $row['id']]
                );

                $remaining -= $take;
            }
        }
    }

    // คืนสต็อกเมื่อยกเลิก Sale Lot
    public function restoreStock($sale_lot_id)
    {
        $items = $this->db->fetchAll(
            "SELECT category_id, quantity_kg
             FROM sale_lot_items
             WHERE sale_lot_id = ?",
            [$sale_lot_id]
        );

        $lot = $this->db->fetch(
            "SELECT branch_id FROM {$this->table} WHERE id = ?",
            [$sale_lot_id]
        );

        foreach ($items as $item) {
            $toRestore = (float)$item['quantity_kg'];

            // คืนสต็อกย้อนกลับจากล็อตล่าสุดก่อน (LIFO สำหรับการคืน)
            $rows = $this->db->fetchAll(
                "SELECT poi.id,
                        poi.consumed_qty
                 FROM purchase_order_items poi
                 INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
                 WHERE po.branch_id = ?
                   AND poi.category_id = ?
                   AND po.status = 'completed'
                   AND poi.consumed_qty > 0
                 ORDER BY po.created_at DESC",
                [$lot['branch_id'], $item['category_id']]
            );

            foreach ($rows as $row) {
                if ($toRestore <= 0) break;

                $canRestore = min((float)$row['consumed_qty'], $toRestore);

                $this->db->query(
                     "UPDATE purchase_order_items
                      SET consumed_qty = consumed_qty - ?
                      WHERE id = ?",
                    [$canRestore, $row['id']]
                );

                $toRestore -= $canRestore;
            }
        }
    }

    // สร้างเลขอ้างอิง SO-YYYYMMDD-NNN เพิ่มขึ้นอัตโนมัติรายวันต่อสาขา
    // BUG-06 FIX: ใช้ MAX+1 แทน COUNT+1 เพื่อป้องกัน race condition
    public function generateReferenceNo($branch_id)
    {
        $prefix = 'SO' . date('Ymd');
        $last   = $this->db->fetchColumn(
            "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED))
             FROM {$this->table}
             WHERE reference_no LIKE ? AND branch_id = ?",
            [$prefix . '-%', $branch_id]
        );
        $next = ($last ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    // ลบ Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function delete($id)
    {
        $lot = $this->db->fetch(
            "SELECT id, status FROM {$this->table} WHERE id = ?",
            [$id]
        );
        if (!$lot) {
            throw new Exception('ไม่พบ Sale Lot');
        }
        if ($lot['status'] !== 'draft') {
            throw new Exception('ลบได้เฉพาะ Sale Lot ที่มีสถานะ draft เท่านั้น');
        }

        $this->db->beginTransaction();
        try {
            $this->db->query("DELETE FROM sale_lot_items WHERE sale_lot_id = ?", [$id]);
            $this->db->query("DELETE FROM {$this->table} WHERE id = ?", [$id]);
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
