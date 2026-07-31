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
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->db->fetchAll(
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

        return $this->attachItems($rows);
    }

    public function findById($id)
    {
        $row = $this->db->fetch(
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

        $today = date('Ymd');
        $lastRef = $this->db->fetchColumn(
            "SELECT reference_no FROM stock_transfers
             WHERE reference_no LIKE ? ORDER BY id DESC LIMIT 1",
            ["ST-{$today}-%"]
        );
        $seq = $lastRef ? (intval(substr($lastRef, -3)) + 1) : 1;
        $ref = 'ST-' . $today . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $summaryCategoryId = (int)$items[0]['category_id'];
        $summaryItemName = $items[0]['item_name'];
        $totalWeight = round(array_sum(array_map(static function ($item) {
            return (float)$item['weight_kg'];
        }, $items)), 3);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO stock_transfers
                   (reference_no,from_branch_id,to_branch_id,category_id,item_name,weight_kg,
                    note,transporter_name,vehicle_plate,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
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
            ]);
            $transferId = (int)$this->db->lastInsertId();

            $lineNo = 1;
            foreach ($items as $item) {
                $stmt = $this->db->prepare(
                    "INSERT INTO stock_transfer_items
                       (stock_transfer_id,line_no,category_id,item_name,weight_kg)
                     VALUES (?,?,?,?,?)"
                );
                $this->db->execute($stmt, [
                    $transferId,
                    $lineNo++,
                    (int)$item['category_id'],
                    $item['item_name'],
                    (float)$item['weight_kg'],
                ]);
            }

            $this->db->commit();
            return [
                'id' => $transferId,
                'reference_no' => $ref,
                'created_at' => date('Y-m-d H:i:s'),
                'items' => $items,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function confirm($id, $userId, $data = [])
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

            $transferItems = $this->loadTransferItems((int)$st['id'], $st);
            if (empty($transferItems)) {
                throw new Exception('ไม่พบรายการสินค้าในใบโอน');
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
                           AND consumed_qty + ? <= quantity - COALESCE(weight_deduction, 0)"
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
                    total_items, total_amount, payment_method, payment_status, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, 'cash', 'paid', 'completed', ?)"
            );
            $this->db->execute($stmt, [
                $refNo,
                (int)$st['to_branch_id'],
                $transferSellerId,
                $userId,
                $totalItems,
                round($totalCost, 2),
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
                     received_weight_kg=?, receive_note=?
                 WHERE id=?"
            );
            $this->db->execute($stmt, [
                $userId,
                $receivedTotal,
                $receiveNoteFallback,
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
                "SELECT id, status FROM stock_transfers WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$st) {
                throw new Exception('ไม่พบใบโอน');
            }
            if ($st['status'] !== 'pending') {
                throw new Exception('ยกเลิกได้เฉพาะใบโอนที่รอตรวจรับ');
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
            "SELECT sti.*, c.name AS category_name
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
                'line_no' => (int)$row['line_no'],
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['category_name'] ?? null,
                'item_name' => $row['item_name'],
                'weight_kg' => (float)$row['weight_kg'],
                'received_weight_kg' => $row['received_weight_kg'] !== null ? (float)$row['received_weight_kg'] : null,
                'receive_note' => $row['receive_note'],
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

    private function getTransferSellerId($branchId)
    {
        $idCard = 'TRANSFER0000';
        $sellerId = $this->db->fetchColumn(
            "SELECT id FROM sellers WHERE id_card = ? LIMIT 1",
            [$idCard]
        );
        if ($sellerId) {
            return (int)$sellerId;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO sellers (id_card, full_name, notes)
             VALUES (?, 'โอนสต็อกระหว่างสาขา', 'system placeholder สำหรับ stock transfer')"
        );
        $this->db->execute($stmt, [$idCard]);
        return (int)$this->db->lastInsertId();
    }

    private function fetchAvailableSourceRows(int $fromBranch, int $categoryId, ?string $itemName = null): array
    {
        $availableSql =
            "SELECT poi.id, poi.item_name,
                    (poi.quantity - COALESCE(poi.weight_deduction, 0) - poi.consumed_qty) AS avail,
                    poi.unit_price
             FROM purchase_order_items poi
             JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ? AND poi.category_id = ?
               AND po.status = 'completed'
               AND (poi.quantity - COALESCE(poi.weight_deduction, 0) - poi.consumed_qty) > 0";
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
