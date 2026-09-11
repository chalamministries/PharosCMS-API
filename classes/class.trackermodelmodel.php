<?php
/**
 * TrackerModelModel - Manages GPS tracker models
 * 
 * TABLE: tracker_model
 * ===================
 * Primary Key: model_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * model_id   int             Primary key, auto-increment
 * brand_id   int             Foreign key to tracker_brand table
 * model_name varchar(255)    Name of the model
 * battery_field varchar(255) Name of the battery field
 */

class TrackerModelModel
{
    public $pdo;
    public $modelID = null;
    public $modelArr = [];

    public function __construct(?int $modelId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($modelId !== null) {
            $this->modelID = $modelId;
            $this->getModel();
        }
    }

    private function getModel() {
        $model = $this->pdo->selectFirst("tracker_model", ["model_id" => $this->modelID]);
        if ($model) {
            $this->modelArr = typeSet($model, "tracker_model");
        }
    }

    public function getModelsByBrand($brandId) {
        $models = $this->pdo->select("tracker_model", ["brand_id" => $brandId], null, null, ['model_name' => 'ASC']);
        if (!$models) {
            return [];
        }
        foreach ($models as &$model) {
            $model = typeSet($model, "tracker_model");
        }
        return $models;
    }

    public function getAllModels() {
        $sql = "SELECT tm.*, tb.brand_name 
                FROM tracker_model tm
                JOIN tracker_brand tb ON tm.brand_id = tb.brand_id
                ORDER BY tb.brand_name ASC, tm.model_name ASC";
        $models = $this->pdo->query($sql);
        if (!$models) {
            return [];
        }
        foreach ($models as &$model) {
            $model = typeSet($model, "tracker_model");
        }
        return $models;
    }
}
