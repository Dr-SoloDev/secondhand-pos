<?php
class BranchesController extends Controller
{
    public function getBranches()
    {
        $model = new Branch();
        $branches = $model->getAll();
        Response::success('Branches retrieved', $branches);
    }

    public function getActiveBranches()
    {
        $model = new Branch();
        Response::success('Active branches retrieved', $model->getActive());
    }

    public function getBranchSummary()
    {
        $model = new Branch();
        Response::success('Branch summary retrieved', $model->getSummary());
    }

    public function getBranch($id)
    {
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
            Response::error('Failed to create branch: ' . $e->getMessage());
        }
    }

    public function updateBranch($id)
    {
        $this->requireAuth(['admin']);
        if (!$id) Response::error('Branch ID is required', 400);
        $data = $this->getRequestData();
        $data = $this->sanitizeInput($data);
        $model = new Branch();
        try {
            $model->update($id, $data);
            Logger::logActivity($this->user['user_id'], 'update_branch', "Updated branch ID: {$id}");
            Response::success('Branch updated');
        } catch (Exception $e) {
            Response::error('Failed to update branch: ' . $e->getMessage());
        }
    }
}
