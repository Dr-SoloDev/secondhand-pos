<?php
class BranchesController extends Controller
{
    public function getBranches()
    {
        $this->requireAuth();
        $model = new Branch();
        $branches = $model->getAll();
        Response::success('Branches retrieved', $branches);
    }

    public function getActiveBranches()
    {
        $this->requireAuth();
        $model = new Branch();
        Response::success('Active branches retrieved', $model->getActive());
    }

    public function getBranchSummary()
    {
        $this->requireAuth();
        $role = $this->user['role'] ?? '';
        $branchId = null;
        if (in_array($role, ['admin', 'super_manager'], true)) {
            if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
                $branchId = intval($_GET['branch_id']);
            }
        } else {
            $branchId = intval($this->user['branch_id'] ?? 0);
            if (!$branchId) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
        }
        $model = new Branch();
        Response::success('Branch summary retrieved', $model->getSummary($branchId));
    }

    public function getBranch($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('Branch ID is required', 400);
        $model = new Branch();
        $branch = $model->findById($id);
        if (!$branch) Response::error('Branch not found', 404);
        Response::success('Branch retrieved', $branch);
    }

    public function createBranch()
    {
        $this->requireAuth(['admin']);
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['code', 'name']);
        $data = $this->sanitizeInput($data);

        $model = new Branch();
        try {
            $id = $model->create($data);
            Logger::logActivity($this->user['user_id'], 'create_branch', "Created branch: {$data['name']}");
            Response::success('Branch created', ['id' => $id]);
        } catch (Exception $e) {
            error_log('Branch create failed: ' . $e->getMessage());
            Response::error('Failed to create branch', 500);
        }
    }

    public function updateBranch($id)
    {
        $this->requireAuth(['admin']);
        if (!$id) Response::error('Branch ID is required', 400);
        $data = $this->getRequestData();
        $data = $this->sanitizeInput($data);
        $data = array_intersect_key($data, array_flip([
            'code', 'name', 'address', 'phone', 'manager_name', 'status', 'cost_method'
        ]));
        if (isset($data['status']) && !in_array($data['status'], ['active', 'inactive'], true)) {
            Response::error('Invalid branch status', 422);
        }
        if (isset($data['cost_method']) && !in_array($data['cost_method'], ['fifo', 'weighted'], true)) {
            Response::error('Invalid cost method', 422);
        }
        $model = new Branch();
        try {
            $model->update($id, $data);
            Logger::logActivity($this->user['user_id'], 'update_branch', "Updated branch ID: {$id}");
            Response::success('Branch updated');
        } catch (Exception $e) {
            error_log('Branch update failed: ' . $e->getMessage());
            Response::error('Failed to update branch', 500);
        }
    }
}
