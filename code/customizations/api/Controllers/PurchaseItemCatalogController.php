<?php
class PurchaseItemCatalogController extends Controller
{
    /**
     * GET /api/purchase-catalog — ดึงทั้งหมด
     */
    public function getCatalog()
    {
        $model = new PurchaseItemCatalog();
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
        Response::success('ดึงรายการสินค้าสำเร็จ', $model->getAll($includeInactive));
    }

    /**
     * GET /api/purchase-catalog/search?q=xxx — autocomplete
     */
    public function searchCatalog()
    {
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
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $model = new PurchaseItemCatalog();
        try {
            $id = $model->create($data);
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
        $this->requireAuth(['admin', 'manager']);
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
            $model->update($id, $data);
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
            $model->delete($id);
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
        $this->requireAuth(['admin', 'manager']);
        $data = json_decode(file_get_contents('php://input'), true);
        $catalogId = $data['catalog_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        if (!$catalogId || !$categoryId) {
            Response::error('กรุณาระบุ catalog_id และ category_id', 400);
        }

        $model = new PurchaseItemCatalog();
        try {
            $model->updateCategory($catalogId, $categoryId);
            Response::success('บันทึกหมวดหมู่สำเร็จ');
        } catch (Exception $e) {
            error_log('Update category failed: ' . $e->getMessage());
            Response::error('ไม่สามารถบันทึกหมวดหมู่ได้', 400);
        }
    }
}
