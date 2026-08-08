<?php
class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    public function getAll($filters = [])
    {
        $where = [];
        $params = [];
        if (!empty($filters['branch_id'])) {
            $where[] = "(st.from_branch_id = ? OR st.to_branch_id = ?)";
            $params[] = $filters['branch_id'];
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['from_branch_id'])) {
            $where[] = "st.from_branch_id = ?";
            $params[] = $filters['from_branch_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = "st.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['transfer_type'])) {
            $where[] = "st.transfer_type = ?";
            $params[] = $filters['transfer_type'];
        }
        if (!empty($filters['approval_status'])) {
            $where[] = "st.approval_status = ?";
            $params[] = $filters['approval_status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->db->fetchAll(
            "SELECT st.*,
                    fb.name AS from_branch_name, tb.name AS to_branch_name,
                    c.name AS category_name,
                    u1.full_name AS created_by_name, u2.full_name AS confirmed_by_name,
                    u3.full_name AS approved_by_name
             FROM stock_transfers st
             LEFT JOIN branches fb ON fb.id = st.from_branch_id
             LEFT JOIN branches tb ON tb.id = st.to_branch_id
             LEFT JOIN categories c ON c.id = st.category_id
             LEFT JOIN users u1 ON u1.id = st.created_by
             LEFT JOIN users u2 ON u2.id = st.confirmed_by
             LEFT JOIN users u3 ON u3.id = st.approved_by
             $whereSql ORDER BY st.created_at DESC LIMIT 50",
            $params
        ) ?: [];

        return $this->attachItems($rows);
    }

    public function findById($id)
    {
        $row = $this->db->fetch(
            "SELECT st.*,
                    fb.name AS from_branch_name, tb.name AS to_branch_name,
                    c.name AS category_name,
                    u1.full_name AS created_by_name, u2.full_name AS confirmed_by_name,
                    u3.full_name AS approved_by_name
             FROM stock_transfers st
             LEFT JOIN branches fb ON fb.id = st.from_branch_id
             LEFT JOIN branches tb ON tb.id = st.to_branch_id
             LEFT JOIN categories c ON c.id = st.category_id
             LEFT JOIN users u1 ON u1.id = st.created_by
             LEFT JOIN users u2 ON u2.id = st.confirmed_by
             LEFT JOIN users u3 ON u3.id = st.approved_by
             WHERE st.id = ?",
            [$id]
        );
        if (!$row) {
            return null;
        }
        $items = $this->fetchItemsForTransfers([(int)$row['id']]);
        $row['items'] = $items[(int)$row['id']] ?? $this->legacyItemsFromRow($row);
        return $row;
    }

    public function create($data, $userId)
    {
        $items = $this->normalizeCreateItems($data);
        if (empty($items)) {
            throw new Exception('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $this->db->beginTransaction();
        try {
            $result = $this->insertTransfer($data, $items, $userId);
            $this->db->commit();
            return $result;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertTransfer(array $data, array $items, int $userId): array
    {

        $transferType = ($data['transfer_type'] ?? 'normal') === 'reversal' ? 'reversal' : 'normal';
        $prefixCode = $transferType === 'reversal' ? 'RT' : 'ST';
        $today = date('Ymd');
        $lastRef = $this->db->fetchColumn(
            "SELECT reference_no FROM stock_transfers
             WHERE reference_no LIKE ? ORDER BY id DESC LIMIT 1",
            ["{$prefixCode}-{$today}-%"]
        );
        $seq = $lastRef ? (intval(substr($lastRef, -3)) + 1) : 1;
        $ref = $prefixCode . '-' . $today . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $summaryCategoryId = (int)$items[0]['category_id'];
        $summaryItemName = $items[0]['item_name'];
        $totalWeight = round(array_sum(array_map(static function ($item) {
            return (float)$item['weight_kg'];
        }, $items)), 3);

        $stmt = $this->db->prepare(
            "INSERT INTO stock_transfers
               (reference_no,from_branch_id,to_branch_id,category_id,item_name,weight_kg,
                note,transporter_name,vehicle_plate,created_by,transfer_type,reverses_transfer_id,
                reversal_reason,approval_status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            $ref,
            (int)$data['from_branch_id'],
            (int)$data['to_branch_id'],
            $summaryCategoryId,
            $summaryItemName,
            $totalWeight,
            $data['note'] ?? null,
            $data['transporter_name'] ?? null,
            $data['vehicle_plate'] ?? null,
            $userId,
            $transferType,
            $data['reverses_transfer_id'] ?? null,
            $data['reversal_reason'] ?? null,
            $transferType === 'reversal' ? 'pending' : 'not_required',
        ]);
        $transferId = (int)$this->db->lastInsertId();

        $lineNo = 1;
        foreach ($items as $item) {
            $stmt = $this->db->prepare(
                "INSERT INTO stock_transfer_items
                   (stock_transfer_id,source_transfer_item_id,line_no,category_id,item_name,weight_kg)
                 VALUES (?,?,?,?,?,?)"
            );
            $this->db->execute($stmt, [
                $transferId,
                $item['source_transfer_item_id'] ?? null,
                $lineNo++,
                (int)$item['category_id'],
                $item['item_name'],
                (float)$item['weight_kg'],
            ]);
        }

        return [
            'id' => $transferId,
            'reference_no' => $ref,
            'created_at' => date('Y-m-d H:i:s'),
            'items' => $items,
        ];
    }

    public function confirm($id, $userId, $data = [], bool $allowReversal = false, bool $allowSelfApproval = false)
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->fetch(
                "SELECT * FROM stock_transfers WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$st) {
                throw new Exception('ไม่พบใบโอน');
            }
            if ($st['status'] !== 'pending') {
                throw new Exception('ใบโอนนี้ดำเนินการแล้ว');
            }
            $isReversal = ($st['transfer_type'] ?? 'normal') === 'reversal';
            if ($allowReversal && !$isReversal) {
                throw new Exception('รายการนี้ไม่ใช่คำขอโอนย้อนกลับ');
            }
            if ($isReversal) {
                if (!$allowReversal) {
                    throw new Exception('ใบโอนย้อนกลับต้องอนุมัติโดยผู้ดูแลระบบ');
                }
                if (($st['approval_status'] ?? '') !== 'pending') {
                    throw new Exception('สถานะอนุมัติใบโอนย้อนกลับไม่ถูกต้อง');
                }
                if (!$allowSelfApproval && (int)$st['created_by'] === (int)$userId) {
                    throw new Exception('ผู้สร้างคำขอไม่สามารถอนุมัติรายการตัวเองได้');
                }
            }

            $transferItems = $this->loadTransferItems((int)$st['id'], $st);
            if (empty($transferItems)) {
                throw new Exception('ไม่พบรายการสินค้าในใบโอน');
            }

            if ($isReversal) {
                $this->validateReversalForApproval($st, $transferItems);
            }

            $requestedItems = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
            $receivedWeightFallback = isset($data['received_weight_kg']) && is_numeric($data['received_weight_kg'])
                ? (float)$data['received_weight_kg']
                : null;
            $receiveNoteFallback = isset($data['receive_note']) ? substr(trim((string)$data['receive_note']), 0, 500) : null;

            $normalizedConfirmItems = $this->normalizeConfirmItems($transferItems, $requestedItems, $receivedWeightFallback, $receiveNoteFallback);

            $transferSellerId = $this->getTransferSellerId((int)$st['to_branch_id']);
            $processed = [];
            $totalCost = 0.0;

            foreach ($normalizedConfirmItems as $item) {
                $fromBranch = (int)$st['from_branch_id'];
                $categoryId = (int)$item['category_id'];
                $itemName = trim((string)$item['item_name']);
                $receivedWeight = (float)$item['received_weight_kg'];
                if ($receivedWeight <= 0) {
                    throw new Exception('น้ำหนักรับต้องมากกว่า 0');
                }

                $lineBatches = [];
                if ($itemName !== '') {
                    $branchStockQty = (float)$this->db->fetchColumn(
                        "SELECT stock_kg
                         FROM branch_stock
                         WHERE branch_id = ? AND category_id = ? AND item_name = ?
                         FOR UPDATE",
                        [$fromBranch, $categoryId, $itemName]
                    );
                    if ($branchStockQty < $receivedWeight) {
                        throw new Exception(
                            "สต็อก {$itemName} ที่สาขาต้นทางไม่เพียงพอ (มี " .
                            number_format($branchStockQty, 2) . " กก. ต้องการ " .
                            number_format($receivedWeight, 2) . " กก.)"
                        );
                    }
                }

                $availableRows = $this->fetchAvailableSourceRows($fromBranch, $categoryId, $itemName !== '' ? $itemName : null);
                $totalAvail = array_sum(array_column($availableRows, 'avail'));
                if ($totalAvail < $receivedWeight) {
                    throw new Exception(
                        "สต็อกต้นทางไม่เพียงพอ (มี " . number_format($totalAvail, 2) .
                        " กก. ต้องการ " . number_format($receivedWeight, 2) . " กก.)"
                    );
                }

                $remaining = $receivedWeight;
                $lineCost = 0.0;
                foreach ($availableRows as $row) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min((float)$row['avail'], $remaining);
                    $stmt = $this->db->prepare(
                        "UPDATE purchase_order_items
                         SET consumed_qty = consumed_qty + ?
                         WHERE id = ?
                           AND consumed_qty + ? <= net_quantity"
                    );
                    $this->db->execute($stmt, [$take, $row['id'], $take]);
                    if (!$stmt->rowCount()) {
                        throw new Exception('สต็อกถูกใช้งานโดยรายการอื่นแล้ว กรุณาลองใหม่');
                    }

                    $lineCost += $take * (float)$row['unit_price'];
                    $remaining -= $take;

                    $sourceItemName = trim((string)($row['item_name'] ?? ''));
                    if ($sourceItemName === '') {
                        $sourceItemName = $itemName;
                    }
                    if ($sourceItemName === '') {
                        throw new Exception('ไม่พบชื่อสินค้าในสต็อกต้นทาง');
                    }
                    $batchKey = $sourceItemName;
                    if (!isset($lineBatches[$batchKey])) {
                        $lineBatches[$batchKey] = [
                            'item_name' => $sourceItemName,
                            'category_id' => $categoryId,
                            'qty' => 0.0,
                            'cost' => 0.0,
                        ];
                    }
                    $lineBatches[$batchKey]['qty'] += $take;
                    $lineBatches[$batchKey]['cost'] += $take * (float)$row['unit_price'];
                }

                $processed[] = [
                    'item' => $item,
                    'received_weight_kg' => $receivedWeight,
                    'line_cost' => $lineCost,
                    'batches' => array_values($lineBatches),
                ];
                $totalCost += $lineCost;
            }

            $today = date('Ymd');
            $lastSeq = $this->db->fetchColumn(
                "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED))
                 FROM purchase_orders
                 WHERE reference_no LIKE ? AND branch_id = ?",
                ["PO-B{$st['to_branch_id']}-{$today}-%", $st['to_branch_id']]
            ) ?: 0;
            $refNo = "PO-B{$st['to_branch_id']}-{$today}-" . str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);
            $totalItems = array_sum(array_map(static function ($entry) {
                return count($entry['batches']);
            }, $processed));

            $stmt = $this->db->prepare(
                "INSERT INTO purchase_orders
                   (reference_no, branch_id, seller_id, user_id,
                    total_items, total_amount, payment_method, payment_status, status, source_type, source_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, 'cash', 'paid', 'completed', 'stock_transfer', ?, ?)"
            );
            $this->db->execute($stmt, [
                $refNo,
                (int)$st['to_branch_id'],
                $transferSellerId,
                $userId,
                $totalItems,
                round($totalCost, 2),
                (int)$st['id'],
                "โอนสต็อกจากสาขา {$st['from_branch_id']} (ST: {$st['reference_no']})",
            ]);
            $poId = (int)$this->db->lastInsertId();

            $branchStock = new BranchStock();
            foreach ($processed as $entry) {
                foreach ($entry['batches'] as $batch) {
                    $itemName = trim((string)$batch['item_name']);
                    $receivedWeight = (float)$batch['qty'];
                    $lineCost = (float)$batch['cost'];
                    $unitPrice = $receivedWeight > 0 ? round($lineCost / $receivedWeight, 4) : 0;

                    $stmt = $this->db->prepare(
                        "INSERT INTO purchase_order_items
                           (purchase_order_id, item_name, category_id,
                            quantity, unit_price, total_price, consumed_qty, unit)
                         VALUES (?, ?, ?, ?, ?, ?, 0, 'กก.')"
                    );
                    $this->db->execute($stmt, [
                        $poId,
                        $itemName,
                        (int)$batch['category_id'],
                        $receivedWeight,
                        $unitPrice,
                        round($lineCost, 2),
                    ]);

                    if ($itemName !== '') {
                        $branchStock->deduct($st['from_branch_id'], $batch['category_id'], $itemName, $receivedWeight);
                        $branchStock->upsert($st['to_branch_id'], $batch['category_id'], $itemName, $receivedWeight, $unitPrice);
                    }
                }
            }

            $receivedTotal = round(array_sum(array_map(static function ($entry) {
                return (float)$entry['received_weight_kg'];
            }, $processed)), 3);

            $stmt = $this->db->prepare(
                "UPDATE stock_transfers
                 SET status='confirmed', confirmed_by=?, confirmed_at=NOW(),
                     received_weight_kg=?, receive_note=?,
                     approval_status = IF(transfer_type = 'reversal', 'approved', approval_status),
                     approved_by = IF(transfer_type = 'reversal', ?, approved_by),
                     approved_at = IF(transfer_type = 'reversal', NOW(), approved_at),
                     review_note = IF(transfer_type = 'reversal', ?, review_note)
                 WHERE id=?"
            );
            $this->db->execute($stmt, [
                $userId,
                $receivedTotal,
                $receiveNoteFallback,
                $userId,
                isset($data['review_note']) ? substr(trim((string)$data['review_note']), 0, 500) : null,
                $id,
            ]);

            $this->persistConfirmedItems((int)$st['id'], $normalizedConfirmItems);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel($id)
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->fetch(
                "SELECT id, status, transfer_type FROM stock_transfers WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$st) {
                throw new Exception('ไม่พบใบโอน');
            }
            if ($st['status'] !== 'pending') {
                throw new Exception('ยกเลิกได้เฉพาะใบโอนที่รอตรวจรับ');
            }
            if (($st['transfer_type'] ?? 'normal') === 'reversal') {
                throw new Exception('คำขอโอนย้อนกลับต้องให้ผู้ดูแลระบบพิจารณา');
            }

            $stmt = $this->db->prepare(
                "UPDATE stock_transfers SET status = 'cancelled' WHERE id = ?"
            );
            $this->db->execute($stmt, [$id]);
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function requestReversal($originalTransferId, array $requestedItems, string $reason, int $userId): array
    {
        if (trim($reason) === '') {
            throw new Exception('กรุณาระบุเหตุผลการโอนย้อนกลับ');
        }

        $this->db->beginTransaction();
        try {
            $original = $this->db->fetch(
                "SELECT * FROM stock_transfers WHERE id = ? FOR UPDATE",
                [$originalTransferId]
            );
            if (!$original || ($original['status'] ?? '') !== 'confirmed') {
                throw new Exception('สร้างใบโอนย้อนกลับได้เฉพาะใบโอนที่ยืนยันแล้ว');
            }
            if (($original['transfer_type'] ?? 'normal') === 'reversal') {
                throw new Exception('ไม่สามารถสร้างใบโอนย้อนกลับจากใบโอนย้อนกลับได้');
            }

            $rows = $this->db->fetchAll(
                "SELECT * FROM stock_transfer_items
                 WHERE stock_transfer_id = ? ORDER BY line_no, id FOR UPDATE",
                [$originalTransferId]
            ) ?: [];
            $originalItems = [];
            foreach ($rows as $row) {
                $originalItems[(int)$row['id']] = $row;
            }
            if (empty($originalItems)) {
                throw new Exception('ไม่พบรายการต้นฉบับสำหรับโอนย้อนกลับ');
            }
            if (empty($requestedItems)) {
                $requestedItems = array_values($originalItems);
            }

            $items = [];
            $seen = [];
            foreach ($requestedItems as $requested) {
                if (!is_array($requested)) {
                    throw new Exception('รายการโอนย้อนกลับไม่ถูกต้อง');
                }
                $sourceItemId = (int)($requested['source_transfer_item_id'] ?? $requested['id'] ?? 0);
                $source = $originalItems[$sourceItemId] ?? null;
                if (!$source || isset($seen[$sourceItemId])) {
                    throw new Exception('รายการโอนย้อนกลับไม่ถูกต้อง');
                }
                $seen[$sourceItemId] = true;

                $maximum = (float)($source['received_weight_kg'] ?? $source['weight_kg'] ?? 0);
                $weight = isset($requested['weight_kg']) ? (float)$requested['weight_kg'] : $maximum;
                if (!is_finite($weight) || $weight <= 0 || $weight - $maximum > 0.0001) {
                    throw new Exception('น้ำหนักโอนย้อนกลับเกินน้ำหนักที่รับจริง');
                }

                $alreadyRequested = (float)$this->db->fetchColumn(
                    "SELECT COALESCE(SUM(sti.weight_kg), 0)
                     FROM stock_transfer_items sti
                     JOIN stock_transfers st ON st.id = sti.stock_transfer_id
                     WHERE sti.source_transfer_item_id = ?
                       AND st.transfer_type = 'reversal'
                       AND st.approval_status IN ('pending','approved')",
                    [$sourceItemId]
                );
                if ($alreadyRequested + $weight - $maximum > 0.0001) {
                    throw new Exception('น้ำหนักรายการนี้ถูกขอโอนย้อนกลับครบแล้ว');
                }

                $items[] = [
                    'source_transfer_item_id' => $sourceItemId,
                    'category_id' => (int)$source['category_id'],
                    'item_name' => $source['item_name'],
                    'weight_kg' => round($weight, 3),
                ];
            }

            $result = $this->insertTransfer([
                'from_branch_id' => (int)$original['to_branch_id'],
                'to_branch_id' => (int)$original['from_branch_id'],
                'items' => $items,
                'transfer_type' => 'reversal',
                'reverses_transfer_id' => (int)$original['id'],
                'reversal_reason' => substr(trim($reason), 0, 500),
                'note' => 'โอนย้อนกลับจาก ' . $original['reference_no'],
            ], $items, $userId);
            $this->db->commit();
            return $result;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function approveReversal(int $id, int $userId, ?string $reviewNote = null, bool $allowSelfApproval = false): void
    {
        $this->confirm($id, $userId, [
            'review_note' => $reviewNote,
        ], true, $allowSelfApproval);
    }

    public function rejectReversal(int $id, int $adminId, string $reviewNote): void
    {
        if (trim($reviewNote) === '') {
            throw new Exception('กรุณาระบุเหตุผลที่ปฏิเสธ');
        }
        $this->db->beginTransaction();
        try {
            $transfer = $this->db->fetch(
                "SELECT id, created_by, status, transfer_type, approval_status
                 FROM stock_transfers WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$transfer || ($transfer['transfer_type'] ?? '') !== 'reversal') {
                throw new Exception('ไม่พบคำขอโอนย้อนกลับ');
            }
            if ((int)$transfer['created_by'] === $adminId) {
                throw new Exception('ผู้สร้างคำขอไม่สามารถอนุมัติรายการตัวเองได้');
            }
            if (($transfer['status'] ?? '') !== 'pending' || ($transfer['approval_status'] ?? '') !== 'pending') {
                throw new Exception('คำขอนี้ถูกดำเนินการแล้ว');
            }
            $this->db->query(
                "UPDATE stock_transfers
                 SET status = 'cancelled', approval_status = 'rejected', approved_by = ?, approved_at = NOW(), review_note = ?
                 WHERE id = ?",
                [$adminId, substr(trim($reviewNote), 0, 500), $id]
            );
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function normalizeCreateItems(array $data): array
    {
        $items = [];
        $seenItems = [];
        $inputItems = isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : [];

        if (!empty($inputItems)) {
            foreach ($inputItems as $index => $item) {
                if (!is_array($item)) {
                    throw new Exception('ข้อมูลรายการสินค้าไม่ถูกต้อง');
                }
                $categoryId = (int)($item['category_id'] ?? $data['category_id'] ?? 0);
                $itemName = trim((string)($item['item_name'] ?? ''));
                $weightKg = (float)($item['weight_kg'] ?? 0);
                if (!$categoryId || $itemName === '' || $weightKg <= 0 || !is_finite($weightKg)) {
                    throw new Exception('ข้อมูลรายการสินค้าไม่ครบ');
                }
                $itemKey = $categoryId . ':' . mb_strtolower($itemName, 'UTF-8');
                if (isset($seenItems[$itemKey])) {
                    throw new Exception("รายการ {$itemName} ซ้ำ กรุณารวมเป็นรายการเดียว");
                }
                $seenItems[$itemKey] = true;
                $items[] = [
                    'line_no' => $index + 1,
                    'source_transfer_item_id' => !empty($item['source_transfer_item_id']) ? (int)$item['source_transfer_item_id'] : null,
                    'category_id' => $categoryId,
                    'item_name' => substr($itemName, 0, 200),
                    'weight_kg' => round($weightKg, 3),
                ];
            }
            return $items;
        }

        $categoryId = (int)($data['category_id'] ?? 0);
        $itemName = trim((string)($data['item_name'] ?? ''));
        $weightKg = (float)($data['weight_kg'] ?? 0);
        if (!$categoryId || $itemName === '' || $weightKg <= 0 || !is_finite($weightKg)) {
            return [];
        }

        return [[
            'line_no' => 1,
            'source_transfer_item_id' => null,
            'category_id' => $categoryId,
            'item_name' => substr($itemName, 0, 200),
            'weight_kg' => round($weightKg, 3),
        ]];
    }

    private function attachItems(array $transfers): array
    {
        if (empty($transfers)) {
            return [];
        }

        $itemsByTransfer = $this->fetchItemsForTransfers(array_column($transfers, 'id'));
        foreach ($transfers as &$transfer) {
            $transferId = (int)$transfer['id'];
            $transfer['items'] = $itemsByTransfer[$transferId] ?? $this->legacyItemsFromRow($transfer);
        }
        unset($transfer);

        return $transfers;
    }

    private function fetchItemsForTransfers(array $transferIds): array
    {
        $transferIds = array_values(array_filter(array_map('intval', $transferIds)));
        if (empty($transferIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transferIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT sti.*, c.name AS category_name,
                    COALESCE((
                        SELECT SUM(reversal_item.weight_kg)
                        FROM stock_transfer_items reversal_item
                        JOIN stock_transfers reversal ON reversal.id = reversal_item.stock_transfer_id
                        WHERE reversal_item.source_transfer_item_id = sti.id
                          AND reversal.transfer_type = 'reversal'
                          AND reversal.approval_status IN ('pending', 'approved')
                    ), 0) AS reversal_reserved_weight
             FROM stock_transfer_items sti
             LEFT JOIN categories c ON c.id = sti.category_id
             WHERE sti.stock_transfer_id IN ($placeholders)
             ORDER BY sti.stock_transfer_id ASC, sti.line_no ASC, sti.id ASC",
            $transferIds
        ) ?: [];

        $itemsByTransfer = [];
        foreach ($rows as $row) {
            $transferId = (int)$row['stock_transfer_id'];
            if (!isset($itemsByTransfer[$transferId])) {
                $itemsByTransfer[$transferId] = [];
            }
            $itemsByTransfer[$transferId][] = [
                'id' => (int)$row['id'],
                'stock_transfer_id' => $transferId,
                'source_transfer_item_id' => $row['source_transfer_item_id'] !== null ? (int)$row['source_transfer_item_id'] : null,
                'line_no' => (int)$row['line_no'],
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['category_name'] ?? null,
                'item_name' => $row['item_name'],
                'weight_kg' => (float)$row['weight_kg'],
                'received_weight_kg' => $row['received_weight_kg'] !== null ? (float)$row['received_weight_kg'] : null,
                'receive_note' => $row['receive_note'],
                'reversal_reserved_weight' => (float)($row['reversal_reserved_weight'] ?? 0),
                'created_at' => $row['created_at'],
            ];
        }

        return $itemsByTransfer;
    }

    private function legacyItemsFromRow(array $row): array
    {
        return [[
            'id' => null,
            'stock_transfer_id' => (int)$row['id'],
            'source_transfer_item_id' => null,
            'line_no' => 1,
            'category_id' => (int)$row['category_id'],
            'category_name' => $row['category_name'] ?? null,
            'item_name' => $row['item_name'] ?? null,
            'weight_kg' => (float)$row['weight_kg'],
            'received_weight_kg' => isset($row['received_weight_kg']) && $row['received_weight_kg'] !== null ? (float)$row['received_weight_kg'] : null,
            'receive_note' => $row['receive_note'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ]];
    }

    private function loadTransferItems(int $transferId, array $transferRow): array
    {
        $items = $this->fetchItemsForTransfers([$transferId]);
        if (!empty($items[$transferId])) {
            return $items[$transferId];
        }
        return $this->legacyItemsFromRow($transferRow);
    }

    private function normalizeConfirmItems(array $transferItems, array $requestedItems, ?float $receivedWeightFallback, ?string $receiveNoteFallback): array
    {
        $normalized = [];
        $transferCount = count($transferItems);
        if ($receivedWeightFallback !== null) {
            if ($receivedWeightFallback <= 0 || !is_finite($receivedWeightFallback)) {
                throw new Exception('น้ำหนักรับต้องมากกว่า 0');
            }
            $receivedWeightFallback = round($receivedWeightFallback, 3);
        }

        if (empty($requestedItems)) {
            if ($transferCount > 1 && $receivedWeightFallback !== null) {
                $orderedTotal = round(array_sum(array_map(static function ($item) {
                    return (float)$item['weight_kg'];
                }, $transferItems)), 3);
                if (abs($receivedWeightFallback - $orderedTotal) > 0.001) {
                    throw new Exception('ใบโอนหลายรายการต้องระบุน้ำหนักรับแยกรายการ');
                }
                $receivedWeightFallback = null;
            }

            foreach ($transferItems as $transferItem) {
                $receivedWeight = $transferCount === 1 && $receivedWeightFallback !== null
                    ? $receivedWeightFallback
                    : round((float)$transferItem['weight_kg'], 3);
                $this->assertReceivedWeightWithinOrder($receivedWeight, (float)$transferItem['weight_kg']);
                $note = $receiveNoteFallback;
                if (abs($receivedWeight - (float)$transferItem['weight_kg']) > 0.001 && empty($note)) {
                    throw new Exception('น้ำหนักรับจริงไม่ตรง กรุณาระบุหมายเหตุ');
                }
                $normalized[] = [
                    'id' => $transferItem['id'],
                    'line_no' => $transferItem['line_no'],
                    'category_id' => $transferItem['category_id'],
                    'item_name' => $transferItem['item_name'],
                    'received_weight_kg' => $receivedWeight,
                    'receive_note' => $note,
                ];
            }

            return $normalized;
        }

        $byId = [];
        $byLine = [];
        $byItem = [];
        $requestEntries = [];
        foreach (array_values($requestedItems) as $index => $requestedItem) {
            if (!is_array($requestedItem)) {
                throw new Exception('ข้อมูลน้ำหนักรับไม่ถูกต้อง');
            }
            if (!isset($requestedItem['received_weight_kg']) || !is_numeric($requestedItem['received_weight_kg'])) {
                throw new Exception('กรุณาระบุน้ำหนักรับให้ครบทุกรายการ');
            }

            $receivedWeight = round((float)$requestedItem['received_weight_kg'], 3);
            if ($receivedWeight <= 0 || !is_finite($receivedWeight)) {
                throw new Exception('น้ำหนักรับต้องมากกว่า 0');
            }

            $entry = $requestedItem;
            $entry['_request_index'] = $index;
            $entry['_received_weight_kg'] = $receivedWeight;
            $requestEntries[] = $entry;

            $itemId = (int)($requestedItem['id'] ?? 0);
            if ($itemId) {
                if (isset($byId[$itemId])) {
                    throw new Exception('รายการตรวจรับซ้ำ');
                }
                $byId[$itemId] = $entry;
            }

            $lineNo = (int)($requestedItem['line_no'] ?? 0);
            if ($lineNo) {
                if (isset($byLine[$lineNo])) {
                    throw new Exception('รายการตรวจรับซ้ำ');
                }
                $byLine[$lineNo] = $entry;
            }

            $categoryId = (int)($requestedItem['category_id'] ?? 0);
            $itemName = trim((string)($requestedItem['item_name'] ?? ''));
            if ($categoryId && $itemName !== '') {
                $itemKey = $categoryId . ':' . mb_strtolower($itemName, 'UTF-8');
                if (isset($byItem[$itemKey])) {
                    throw new Exception('รายการตรวจรับซ้ำ');
                }
                $byItem[$itemKey] = $entry;
            }
        }

        $usedRequests = [];
        foreach ($transferItems as $transferItem) {
            $source = null;
            $itemId = (int)($transferItem['id'] ?? 0);
            $lineNo = (int)($transferItem['line_no'] ?? 0);
            $categoryId = (int)($transferItem['category_id'] ?? 0);
            $itemName = trim((string)($transferItem['item_name'] ?? ''));
            $itemKey = $categoryId && $itemName !== ''
                ? $categoryId . ':' . mb_strtolower($itemName, 'UTF-8')
                : null;

            if ($itemId && isset($byId[$itemId])) {
                $source = $byId[$itemId];
            } elseif ($lineNo && isset($byLine[$lineNo])) {
                $source = $byLine[$lineNo];
            } elseif ($itemKey !== null && isset($byItem[$itemKey])) {
                $source = $byItem[$itemKey];
            } elseif ($transferCount === 1 && count($requestEntries) === 1) {
                $source = $requestEntries[0];
            }

            if (!$source) {
                throw new Exception('กรุณาระบุน้ำหนักรับให้ครบทุกรายการ');
            }

            $requestIndex = (int)$source['_request_index'];
            if (isset($usedRequests[$requestIndex])) {
                throw new Exception('รายการตรวจรับซ้ำ');
            }
            $usedRequests[$requestIndex] = true;

            $receivedWeight = (float)$source['_received_weight_kg'];
            $this->assertReceivedWeightWithinOrder($receivedWeight, (float)$transferItem['weight_kg']);
            $note = $this->extractReceiveNote($source, $receiveNoteFallback);
            if (abs($receivedWeight - (float)$transferItem['weight_kg']) > 0.001 && empty($note)) {
                throw new Exception('น้ำหนักรับจริงไม่ตรง กรุณาระบุหมายเหตุ');
            }

            $normalized[] = [
                'id' => $transferItem['id'],
                'line_no' => $transferItem['line_no'],
                'category_id' => $transferItem['category_id'],
                'item_name' => $transferItem['item_name'],
                'received_weight_kg' => $receivedWeight,
                'receive_note' => $note,
            ];
        }

        if (count($usedRequests) !== count($requestEntries)) {
            throw new Exception('พบรายการตรวจรับที่ไม่อยู่ในใบโอน');
        }

        return $normalized;
    }

    private function assertReceivedWeightWithinOrder(float $receivedWeight, float $orderedWeight): void
    {
        if ($receivedWeight - $orderedWeight > 0.0001) {
            throw new Exception('น้ำหนักรับจริงเกินน้ำหนักในใบโอน');
        }
    }

    private function extractReceiveNote($source, ?string $fallback): ?string
    {
        if (is_array($source) && array_key_exists('receive_note', $source)) {
            $note = trim((string)$source['receive_note']);
            if ($note !== '') {
                return substr($note, 0, 500);
            }
        }
        return $fallback;
    }

    private function persistConfirmedItems(int $transferId, array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['id'])) {
                continue;
            }
            $stmt = $this->db->prepare(
                "UPDATE stock_transfer_items
                 SET received_weight_kg = ?, receive_note = ?
                 WHERE id = ? AND stock_transfer_id = ?"
            );
            $this->db->execute($stmt, [
                (float)$item['received_weight_kg'],
                $item['receive_note'] ?? null,
                (int)$item['id'],
                $transferId,
            ]);
        }
    }

    private function validateReversalForApproval(array $reversal, array $reversalItems): void
    {
        $originalId = (int)($reversal['reverses_transfer_id'] ?? 0);
        if (!$originalId) {
            throw new Exception('คำขอโอนย้อนกลับไม่มีเอกสารต้นฉบับ');
        }

        $original = $this->db->fetch(
            "SELECT id, from_branch_id, to_branch_id, status, transfer_type
             FROM stock_transfers WHERE id = ? FOR UPDATE",
            [$originalId]
        );
        if (!$original || ($original['status'] ?? '') !== 'confirmed' || ($original['transfer_type'] ?? 'normal') !== 'normal') {
            throw new Exception('เอกสารโอนต้นฉบับไม่อยู่ในสถานะที่ย้อนกลับได้');
        }
        if ((int)$reversal['from_branch_id'] !== (int)$original['to_branch_id']
            || (int)$reversal['to_branch_id'] !== (int)$original['from_branch_id']) {
            throw new Exception('ทิศทางของใบโอนย้อนกลับไม่ตรงกับเอกสารต้นฉบับ');
        }

        foreach ($reversalItems as $item) {
            $sourceItemId = (int)($item['source_transfer_item_id'] ?? 0);
            if (!$sourceItemId) {
                throw new Exception('รายการโอนย้อนกลับไม่มีรายการต้นฉบับ');
            }
            $source = $this->db->fetch(
                "SELECT id, stock_transfer_id, category_id, item_name, weight_kg, received_weight_kg
                 FROM stock_transfer_items WHERE id = ? FOR UPDATE",
                [$sourceItemId]
            );
            if (!$source || (int)$source['stock_transfer_id'] !== $originalId) {
                throw new Exception('รายการต้นฉบับของใบโอนย้อนกลับไม่ถูกต้อง');
            }
            if ((int)$item['category_id'] !== (int)$source['category_id']
                || trim((string)$item['item_name']) !== trim((string)$source['item_name'])) {
                throw new Exception('รายละเอียดสินค้าในใบโอนย้อนกลับไม่ตรงกับต้นฉบับ');
            }

            $maximum = (float)($source['received_weight_kg'] ?? $source['weight_kg'] ?? 0);
            $reserved = (float)$this->db->fetchColumn(
                "SELECT COALESCE(SUM(sti.weight_kg), 0)
                 FROM stock_transfer_items sti
                 JOIN stock_transfers st ON st.id = sti.stock_transfer_id
                 WHERE sti.source_transfer_item_id = ?
                   AND st.transfer_type = 'reversal'
                   AND st.approval_status IN ('pending','approved')",
                [$sourceItemId]
            );
            if ($maximum <= 0 || $reserved - $maximum > 0.0001) {
                throw new Exception('ยอดโอนย้อนกลับรวมเกินน้ำหนักที่รับจริง');
            }
        }
    }

    private function getTransferSellerId($branchId)
    {
        $sellerId = $this->db->fetchColumn(
            "SELECT id FROM sellers
             WHERE full_name = 'โอนสต็อกระหว่างสาขา'
               AND notes = 'system placeholder สำหรับ stock transfer'
             LIMIT 1"
        );
        if ($sellerId) {
            return (int)$sellerId;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO sellers (id_card, full_name, notes)
             VALUES (NULL, 'โอนสต็อกระหว่างสาขา', 'system placeholder สำหรับ stock transfer')"
        );
        $this->db->execute($stmt);
        return (int)$this->db->lastInsertId();
    }

    private function fetchAvailableSourceRows(int $fromBranch, int $categoryId, ?string $itemName = null): array
    {
        $availableSql =
            "SELECT poi.id, poi.item_name,
                    (poi.net_quantity - poi.consumed_qty) AS avail,
                    poi.unit_price
             FROM purchase_order_items poi
             JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ? AND poi.category_id = ?
               AND po.status = 'completed'
               AND (poi.net_quantity - poi.consumed_qty) > 0";
        $params = [$fromBranch, $categoryId];
        if ($itemName !== null && trim($itemName) !== '') {
            $availableSql .= " AND TRIM(poi.item_name) = ?";
            $params[] = trim($itemName);
        }
        $availableSql .= "
             ORDER BY po.created_at ASC, poi.id ASC FOR UPDATE";

        return $this->db->fetchAll($availableSql, $params) ?: [];
    }
}
