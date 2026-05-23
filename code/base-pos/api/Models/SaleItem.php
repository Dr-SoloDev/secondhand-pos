<?php
class SaleItem extends Model
{
    /**
     * @var string
     */
    protected $table = 'sale_items';

    /**
     * @param $saleId
     * @return mixed
     */
    public function getItemsBySaleId($saleId)
    {
        return $this->db->fetchAll(
            "SELECT si.*, p.name as product_name, p.sku
            FROM {$this->table} si
            JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ?
            ORDER BY si.id ASC",
            [$saleId]
        );
    }
}
