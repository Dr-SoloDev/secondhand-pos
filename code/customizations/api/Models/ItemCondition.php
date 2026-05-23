<?php
class ItemCondition extends Model
{
    protected $table = 'item_conditions';

    public function getActive()
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY sort_order ASC"
        );
    }
}
