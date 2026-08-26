<?php
/**
 * BranchStock Model — Per-branch per-item stock tracking
 *
 * Source of Truth สำหรับสต็อกทั้งหมดในระบบ (ตาม ADD-001)
 * แทน categories.stock_kg เดิมที่เป็น global aggregate
 *
 * @package ScrapPOS
 * @author  SoloCorp OS — Engineering (ช่างฟูล)
 * @see     ADD-001: Branch Stock Redesign
 */
class BranchStock extends Model
{
    protected $table = 'branch_stock';

    /**
     * UPSERT stock ใน branch_stock
     * - ถ้ามี record อยู่แล้ว: stock_kg += ? และ recalculate unit_price แบบ weighted average
     * - ถ้ายังไม่มี: INSERT ใหม่
     *
     * ใช้เมื่อ:
     *   - PO Create (เพิ่ม stock)
     *   - Stock Transfer destination (รับ stock)
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @param float  $stockKg   จำนวน kg ที่เพิ่ม
     * @param float  $unitPrice ราคาต่อหน่วยของ lot ที่เพิ่ม
     */
    public function upsert($branchId, $categoryId, $itemName, $stockKg, $unitPrice, ?int $catalogId = null): void
    {
        if (!$catalogId) {
            $catalogId = $this->resolveCatalogId($categoryId, $itemName);
        }
        if ($catalogId) {
            $stockId = (int)$this->db->fetchColumn(
                "SELECT id FROM branch_stock
                 WHERE branch_id = ? AND category_id = ? AND catalog_id = ?
                 FOR UPDATE",
                [(int)$branchId, (int)$categoryId, $catalogId]
            );

            if ($stockId) {
                $stmt = $this->db->prepare(
                    "UPDATE branch_stock
                     SET item_name = ?,
                         unit_price = ROUND(
                             ((stock_kg * unit_price) + (? * ?))
                             / GREATEST((stock_kg + ?), 0.001),
                             4
                         ),
                         stock_kg = stock_kg + ?
                     WHERE id = ?"
                );
                $this->db->execute($stmt, [
                    trim($itemName),
                    (float)$stockKg,
                    (float)$unitPrice,
                    (float)$stockKg,
                    (float)$stockKg,
                    $stockId,
                ]);
                return;
            }

            // A legacy misspelling can still occupy the table's name-based unique key.
            // Re-key it once so all future mutations use the catalog identity.
            $legacyStockId = (int)$this->db->fetchColumn(
                "SELECT id FROM branch_stock
                 WHERE branch_id = ?
                   AND category_id = ?
                   AND catalog_id IS NULL
                   AND CONVERT(TRIM(item_name) USING utf8mb4) = CONVERT(? USING utf8mb4)
                 LIMIT 1
                 FOR UPDATE",
                [(int)$branchId, (int)$categoryId, trim($itemName)]
            );
            if ($legacyStockId) {
                $stmt = $this->db->prepare(
                    "UPDATE branch_stock
                     SET catalog_id = ?,
                         item_name = ?,
                         unit_price = ROUND(
                             ((stock_kg * unit_price) + (? * ?))
                             / GREATEST((stock_kg + ?), 0.001),
                             4
                         ),
                         stock_kg = stock_kg + ?
                     WHERE id = ?"
                );
                $this->db->execute($stmt, [
                    $catalogId,
                    trim($itemName),
                    (float)$stockKg,
                    (float)$unitPrice,
                    (float)$stockKg,
                    (float)$stockKg,
                    $legacyStockId,
                ]);
                return;
            }

            $sql = "INSERT INTO branch_stock
                        (branch_id, category_id, catalog_id, item_name, stock_kg, unit_price)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $params = [(int)$branchId, (int)$categoryId, $catalogId, trim($itemName), (float)$stockKg, (float)$unitPrice];
        } else {
            $sql = "INSERT INTO branch_stock
                        (branch_id, category_id, item_name, stock_kg, unit_price)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        stock_kg   = stock_kg + VALUES(stock_kg),
                        unit_price = ROUND(
                            ((stock_kg * unit_price) + (VALUES(stock_kg) * VALUES(unit_price)))
                            / GREATEST((stock_kg + VALUES(stock_kg)), 0.001),
                            4
                        ),
                        last_updated = NOW()";
            $params = [(int)$branchId, (int)$categoryId, trim($itemName), (float)$stockKg, (float)$unitPrice];
        }

        $stmt = $this->db->prepare($sql);
        $this->db->execute($stmt, [
            ...$params,
        ]);
    }

    /**
     * Deduct (หัก) stock จาก branch_stock
     * - stock_kg = GREATEST(0, stock_kg - qty) — ไม่ติดลบ
     *
     * ใช้เมื่อ:
     *   - SaleLot Confirm (ขายของ)
     *   - PO Cancel (ยกเลิกใบรับซื้อ)
     *   - Stock Transfer origin (โอนออก)
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @param float  $qty
     */
    public function deduct($branchId, $categoryId, $itemName, $qty, ?int $catalogId = null): void
    {
        $this->db->query(
            "UPDATE branch_stock
             SET stock_kg = GREATEST(0, stock_kg - ?),
                 last_updated = NOW()
             WHERE branch_id = ?
               AND category_id = ?
               AND {$this->identityCondition()}",
            $this->mutateParams($qty, $branchId, $categoryId, $itemName, $catalogId)
        );
    }

    /**
     * Restore (คืน) stock เข้า branch_stock
     * - stock_kg = stock_kg + qty
     *
     * ใช้เมื่อ:
     *   - SaleLot Cancel (ยกเลิกการขาย — คืน stock)
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @param float  $qty
     */
    public function restore($branchId, $categoryId, $itemName, $qty, ?int $catalogId = null): void
    {
        $this->db->query(
            "UPDATE branch_stock
             SET stock_kg = stock_kg + ?,
                 last_updated = NOW()
             WHERE branch_id = ?
               AND category_id = ?
               AND {$this->identityCondition()}",
            $this->mutateParams($qty, $branchId, $categoryId, $itemName, $catalogId)
        );
    }

    private function mutateParams(float $qty, $branchId, $categoryId, string $itemName, ?int $catalogId): array
    {
        return [(float)$qty, (int)$branchId, (int)$categoryId, $this->identityValue($catalogId, $itemName)];
    }

    private function identityCondition(): string
    {
        return "(CASE WHEN catalog_id IS NULL THEN CONVERT(item_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ELSE CONVERT(CAST(catalog_id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci END) = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    }

    private function identityValue(?int $catalogId, string $itemName)
    {
        return $catalogId ?: trim($itemName);
    }

    private function resolveCatalogId($categoryId, string $itemName): ?int
    {
        if (!$categoryId || trim($itemName) === '') return null;

        $exact = (int)$this->db->fetchColumn(
            "SELECT id FROM purchase_item_catalog
             WHERE category_id = ?
               AND CONVERT(TRIM(CONVERT(name USING utf8mb4)) USING utf8mb4) =
                   CONVERT(?) USING utf8mb4
             LIMIT 1",
            [(int)$categoryId, trim($itemName)]
        );
        if ($exact) return $exact;

        return (int)$this->db->fetchColumn(
            "SELECT alias.catalog_id FROM catalog_item_aliases alias
             JOIN purchase_item_catalog pic ON pic.id = alias.catalog_id
             WHERE pic.category_id = ?
               AND CONVERT(TRIM(alias.alias_name) USING utf8mb4) =
                   CONVERT(?) USING utf8mb4
             LIMIT 1",
            [(int)$categoryId, trim($itemName)]
        ) ?: null;
    }

    /**
     * ดึง stock ของ item ใด item หนึ่ง
     *
     * @param int    $branchId
     * @param int    $categoryId
     * @param string $itemName
     * @return array|null
     */
    public function getStock($branchId, $categoryId, $itemName): ?array
    {
        $catalogId = $this->resolveCatalogId($categoryId, $itemName);
        if ($catalogId) {
            $result = $this->db->fetch(
                "SELECT *
                 FROM branch_stock
                 WHERE branch_id = ?
                   AND category_id = ?
                   AND catalog_id = ?",
                [
                    (int)$branchId,
                    (int)$categoryId,
                    $catalogId,
                ]
            );
            if ($result) return $result;
        }

        $result = $this->db->fetch(
            "SELECT *
             FROM branch_stock
             WHERE branch_id = ?
               AND category_id = ?
               AND item_name = ?",
            [
                (int)$branchId,
                (int)$categoryId,
                trim($itemName),
            ]
        );
        return $result ?: null;
    }

    /**
     * ดึง stock ทั้งหมดของสาขาที่กำหนด (ที่มี stock_kg > 0)
     *
     * @param int $branchId
     * @return array
     */
    public function getByBranch($branchId): array
    {
        return $this->db->fetchAll(
            "SELECT bs.*, c.name AS category_name
             FROM branch_stock bs
             LEFT JOIN categories c ON bs.category_id = c.id
             WHERE bs.branch_id = ?
               AND bs.stock_kg > 0
             ORDER BY c.name ASC, bs.item_name ASC",
            [(int)$branchId]
        );
    }
}
