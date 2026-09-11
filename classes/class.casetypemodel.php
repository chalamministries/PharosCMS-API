<?php
/**
 * CaseTypeModel - Manages case type definitions
 *
 * TABLE: case_type
 * ================
 * Primary Key: case_type_id (int unsigned, AUTO_INCREMENT)
 *
 * FIELDS:
 * -------
 * case_type_id       int(11) unsigned    Primary key, auto-increment
 * short_code         text                Short code identifier (e.g., 'DV', 'INF', 'WC') (nullable)
 * description        text                Full description of case type (nullable)
 * participant_schema JSON                Schema defining participant sections for this case type (nullable)
 *
 * RELATIONSHIPS:
 * --------------
 * - Has many: cases (case_type)
 */
class CaseTypeModel
{
    private $pdo;
	function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }

    public function getCaseTypes() {
        $types = $this->pdo->select("case_type");
        foreach ($types as &$type) {
            if (isset($type['participant_schema']) && $type['participant_schema'] !== null) {
                $type['participant_schema'] = json_decode($type['participant_schema'], true);
            }
        }
        return $types;
    }

    public function createCaseType($short_code, $description, $participant_schema = null) {
        $data = ["short_code" => $short_code, "description" => $description];
        if ($participant_schema !== null) {
            $data['participant_schema'] = json_encode($participant_schema);
        }
        $type = $this->pdo->insert("case_type", $data);
        return $type;
    }

    public function updateCaseType($case_type_id, $short_code, $description, $participant_schema = null) {
        $data = ["short_code" => $short_code, "description" => $description];
        if ($participant_schema !== null) {
            $data['participant_schema'] = json_encode($participant_schema);
        }
        $this->pdo->update("case_type", $data, array("case_type_id" => $case_type_id));
        return true;
    }

    public function deleteCaseType($case_type_id) {
        // Check if case type is used in any cases
        $exists = $this->pdo->selectFirst("cases", array("case_type" => $case_type_id));
        if(!$exists) {
            $this->pdo->delete("case_type", array("case_type_id" => $case_type_id));
            return true;
        } else {
            return false;
        }

    }
}