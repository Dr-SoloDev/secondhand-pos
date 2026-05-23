<?php
class Category extends Model
{
    /**
     * @var string
     */
    protected $table = 'categories';

    /**
     * @return mixed
     */
    public function getActiveCategories()
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }
}
