<?php
/**
 * ActivityTypeModel - Manages types of activities
 * 
 * TABLE: activity_type
 * ====================
 * Primary Key: activity_type_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * activity_type_id       int             Primary key, auto-increment
 * description            varchar(255)    Description of activity type
 * default_client_rate    decimal(10,2)   Default rate to bill client
 * default_billing_method enum            'hourly', 'flat_rate', 'equipment_fee', 'not_billable'
 * default_equipment_fee  decimal(10,2)   Default equipment fee
 * is_billable            tinyint(1)      Whether this activity is billable
 * requires_gps           tinyint(1)      Whether this activity requires GPS tracking
 * display_order          int             Sort order
 * icon                   varchar(255)    Icon representation
 */

class ActivityTypeModel
{
    public $pdo;
    public $activityTypeID = null;
    public $activityTypeArr = [];

    public function __construct(?int $activityTypeId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($activityTypeId !== null) {
            $this->activityTypeID = $activityTypeId;
            $this->getActivityType();
        }
    }

    private function getActivityType() {
        $type = $this->pdo->selectFirst("activity_type", ["activity_type_id" => $this->activityTypeID]);
        if ($type) {
            $this->activityTypeArr = typeSet($type, "activity_type");
        }
    }

    public function getAllActivityTypes() {
        $types = $this->pdo->select("activity_type", [], null, null, ['display_order' => 'ASC']);
        if (!$types) {
            return [];
        }
        foreach ($types as &$type) {
            $type = typeSet($type, "activity_type");
        }
        return $types;
    }
}
