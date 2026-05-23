<?php
class ItemConditionsController extends Controller
{
    public function getConditions()
    {
        $model = new ItemCondition();
        Response::success('Item conditions retrieved', $model->getActive());
    }
}
