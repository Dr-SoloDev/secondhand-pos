<?php
class BranchesController extends Controller
{
    public function getBranches()
    {
        $this->requireAuth();
        $model = new Branch();
        $branches = $model->getAll();
        // แนบ print server (เครื่องพิมพ์ความร้อน) ของแต่ละสาขา
        if ($branches) {
            $settingModel = new BranchSetting();
            foreach ($branches as &$b) {
                $s = $settingModel->getByBranch(
                    (int)$b['id'],
                    ['print_server_host', 'print_server_port']
                );
                $b['print_server_host'] = $s['print_server_host'] ?? '';
                $b['print_server_port'] = $s['print_server_port'] ?? '';
            }
            unset($b);
        }
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

    /**
     * GET /api/branches/branch/print-server?id=X
     * อ่านค่า Print Server (เครื่องพิมพ์ความร้อน) ของสาขา
     */
    public function getBranchPrintServer($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('Branch ID is required', 400);

        $model = new Branch();
        if (!$model->findById($id)) {
            Response::error('Branch not found', 404);
        }

        $settings = (new BranchSetting())->getByBranch(
            (int)$id,
            ['print_server_host', 'print_server_port']
        );

        Response::success('Print server settings retrieved', [
            'branch_id' => (int)$id,
            'print_server_host' => $settings['print_server_host'] ?? '',
            'print_server_port' => $settings['print_server_port'] ?? '',
        ]);
    }

    /**
     * PUT /api/branches/branch/print-server?id=X
     * Body: { "print_server_host": "192.168.1.50", "print_server_port": "9120" }
     * ตั้งค่า Print Server (เครื่องพิมพ์ความร้อน USB) ของสาขา
     * — แต่ละสาขามี Windows PC + เครื่องพิมพ์ของตัวเอง
     */
    public function updateBranchPrintServer($id)
    {
        $this->requireAuth(['admin']);
        if (!$id) Response::error('Branch ID is required', 400);

        $model = new Branch();
        if (!$model->findById($id)) {
            Response::error('Branch not found', 404);
        }

        $data = $this->getRequestData();
        $host = trim((string)($data['print_server_host'] ?? ''));
        $port = trim((string)($data['print_server_port'] ?? ''));

        // port ต้องเป็นตัวเลข 1-65535 ถ้าระบุ
        if ($port !== '') {
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                Response::error('print_server_port ต้องเป็นเลข 1-65535', 422);
            }
        }

        $settings = [];
        if ($host !== '') {
            $settings['print_server_host'] = $host;
        }
        if ($port !== '') {
            $settings['print_server_port'] = $port;
        }

        try {
            (new BranchSetting())->updateForBranch((int)$id, $settings);
            Logger::logActivity(
                $this->user['user_id'],
                'update_branch_print_server',
                "Updated print server for branch ID: {$id} → {$host}" . ($port !== '' ? ":{$port}" : '')
            );
            Response::success('Print server settings updated', $settings);
        } catch (Exception $e) {
            error_log('Branch print server update failed: ' . $e->getMessage());
            Response::error('Failed to update print server settings', 500);
        }
    }
}
