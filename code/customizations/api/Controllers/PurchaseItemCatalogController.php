<?php
class PurchaseItemCatalogController extends Controller
{
    /**
     * GET /api/purchase-catalog — ดึงทั้งหมด
     */
    public function getCatalog()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $model = new PurchaseItemCatalog();
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
        Response::success('ดึงรายการสินค้าสำเร็จ', $model->getAll($includeInactive));
    }

    /**
     * GET /api/purchase-catalog/search?q=xxx — autocomplete
     */
    public function searchCatalog()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            Response::success('ok', []);
            return;
        }
        $model = new PurchaseItemCatalog();
        Response::success('ok', $model->search($q));
    }

    /**
     * GET /api/purchase-catalog/item?id=X
     */
    public function getItem()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $id = $_GET['id'] ?? null;
        if (!$id) {
            Response::error('กรุณาระบุ ID', 400);
        }
        $model = new PurchaseItemCatalog();
        $row = $model->getById($id);
        if (!$row) {
            Response::error('ไม่พบรายการ', 404);
        }
        Response::success('ok', $row);
    }

    /**
     * POST /api/purchase-catalog
     */
    public function createItem()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        $data = $this->getRequestData();
        $model = new PurchaseItemCatalog();
        try {
            $id = $model->create($data);
            Logger::logActivity($this->user['user_id'] ?? null, 'create_catalog_item', "Created catalog item ID: {$id}", [
                'actor' => $this->user,
                'module' => 'catalog',
                'entity_type' => 'catalog_item',
                'entity_id' => $id,
                'after' => $model->getById($id),
            ]);
            Response::success('เพิ่มรายการสำเร็จ', ['id' => $id]);
        } catch (Exception $e) {
            error_log('Catalog create failed: ' . $e->getMessage());
            Response::error('ไม่สามารถเพิ่มรายการได้', 400);
        }
    }

    /**
     * PUT /api/purchase-catalog/item
     */
    public function updateItem($id = null)
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        if (!$id) {
            $data = $this->getRequestData();
            $id = $data['id'] ?? null;
        } else {
            $data = $this->getRequestData();
        }
        if (!$id) {
            Response::error('กรุณาระบุ ID', 400);
        }
        $model = new PurchaseItemCatalog();
        try {
            $before = $model->getById($id);
            $model->update($id, $data);
            Logger::logActivity($this->user['user_id'] ?? null, 'update_catalog_item', "Updated catalog item ID: {$id}", [
                'actor' => $this->user,
                'module' => 'catalog',
                'entity_type' => 'catalog_item',
                'entity_id' => $id,
                'before' => $before,
                'after' => $model->getById($id),
            ]);
            Response::success('แก้ไขสำเร็จ');
        } catch (Exception $e) {
            error_log('Catalog update failed: ' . $e->getMessage());
            Response::error('ไม่สามารถแก้ไขรายการได้', 400);
        }
    }

    /**
     * DELETE /api/purchase-catalog/item?id=X
     */
    public function deleteItem($id = null)
    {
        $this->requireAuth(['admin']);
        if (!$id) {
            Response::error('กรุณาระบุ ID', 400);
        }
        $model = new PurchaseItemCatalog();
        try {
            $before = $model->getById($id);
            $model->delete($id);
            Logger::logActivity($this->user['user_id'] ?? null, 'delete_catalog_item', "Deleted catalog item ID: {$id}", [
                'actor' => $this->user,
                'module' => 'catalog',
                'entity_type' => 'catalog_item',
                'entity_id' => $id,
                'before' => $before,
            ]);
            Response::success('ลบรายการสำเร็จ');
        } catch (Exception $e) {
            error_log('Catalog delete failed: ' . $e->getMessage());
            Response::error('ไม่สามารถลบรายการได้', 400);
        }
    }

    /**
     * POST /api/purchase-catalog/update-category
     * บันทึก category_id ลง catalog เพื่อให้ครั้งต่อไป auto-select ได้
     */
    public function updateCategory()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        $data = json_decode(file_get_contents('php://input'), true);
        $catalogId = $data['catalog_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        if (!$catalogId || !$categoryId) {
            Response::error('กรุณาระบุ catalog_id และ category_id', 400);
        }

        $model = new PurchaseItemCatalog();
        try {
            $before = $model->getById($catalogId);
            $model->updateCategory($catalogId, $categoryId);
            Logger::logActivity($this->user['user_id'] ?? null, 'update_catalog_category', "Updated catalog category ID: {$catalogId}", [
                'actor' => $this->user,
                'module' => 'catalog',
                'entity_type' => 'catalog_item',
                'entity_id' => $catalogId,
                'before' => $before,
                'after' => $model->getById($catalogId),
            ]);
            Response::success('บันทึกหมวดหมู่สำเร็จ');
        } catch (Exception $e) {
            error_log('Update category failed: ' . $e->getMessage());
            Response::error('ไม่สามารถบันทึกหมวดหมู่ได้', 400);
        }
    }

    // GET /api/purchase-catalog/price-board — ดึงราคาทุกรายการแยกตามหมวด สำหรับพิมพ์บอร์ด
    public function getPriceBoard()
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT pic.id, pic.code, pic.name, pic.tier_prices, pic.default_unit,
                    c.name AS category_name
             FROM purchase_item_catalog pic
             LEFT JOIN categories c ON c.id = pic.category_id
             WHERE pic.tier_prices IS NOT NULL
               AND JSON_LENGTH(pic.tier_prices) > 0
             ORDER BY c.name, pic.name"
        );
        foreach ($rows as &$row) {
            $row['tier_prices'] = json_decode($row['tier_prices'] ?? '[]', true);
        }
        Response::success('สำเร็จ', ['items' => $rows]);
    }
}
