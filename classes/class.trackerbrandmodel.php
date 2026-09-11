<?php
/**
 * TrackerBrandModel - Manages GPS tracker brands
 * 
 * TABLE: tracker_brand
 * ===================
 * Primary Key: brand_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * brand_id   int             Primary key, auto-increment
 * brand_name varchar(255)    Name of the brand
 */

class TrackerBrandModel
{
    public $pdo;
    public $brandID = null;
    public $brandArr = [];

    public function __construct(?int $brandId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($brandId !== null) {
            $this->brandID = $brandId;
            $this->getBrand();
        }
    }

    private function getBrand() {
        $brand = $this->pdo->selectFirst("tracker_brand", ["brand_id" => $this->brandID]);
        if ($brand) {
            $this->brandArr = typeSet($brand, "tracker_brand");
        }
    }

    public function getAllBrands() {
        $brands = $this->pdo->select("tracker_brand", [], null, null, ['brand_name' => 'ASC']);
        if (!$brands) {
            return [];
        }
        foreach ($brands as &$brand) {
            $brand = typeSet($brand, "tracker_brand");
        }
        return $brands;
    }
}
