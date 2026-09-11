<?php
/**
 * TrackerAssignmentModel - Manages tracker assignments to cases
 * 
 * TABLE: tracker_assignments
 * ==========================
 * Primary Key: assignment_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * assignment_id int             Primary key, auto-increment
 * tracker_id    int             Foreign key to trackers table
 * case_id       int             Foreign key to cases table
 * assigned_at   timestamp       When the tracker was assigned
 * removed_at    timestamp       When the tracker was removed
 */

class TrackerAssignmentModel
{
    public $pdo;
    public $assignmentID = null;
    public $assignmentArr = [];

    public function __construct(?int $assignmentId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($assignmentId !== null) {
            $this->assignmentID = $assignmentId;
            $this->getAssignment();
        }
    }

    private function getAssignment() {
        $assignment = $this->pdo->selectFirst("tracker_assignments", ["assignment_id" => $this->assignmentID]);
        if ($assignment) {
            $this->assignmentArr = typeSet($assignment, "tracker_assignments");
        }
    }

    public function getActiveAssignmentsByCase($caseId) {
        $sql = "SELECT ta.*, t.name as tracker_name, t.imei, tm.model_name
                FROM tracker_assignments ta
                JOIN trackers t ON ta.tracker_id = t.tracker_id
                LEFT JOIN tracker_model tm ON t.device_model = tm.model_id
                WHERE ta.case_id = :case_id AND ta.removed_at IS NULL";
        $results = $this->pdo->query($sql, [':case_id' => $caseId]);
        
        if (!$results) {
            return [];
        }
        
        foreach ($results as &$row) {
            $row = typeSet($row, "tracker_assignments");
        }
        return $results;
    }
    public function getActiveAssignmentByTracker($trackerId) {
        $sql = "SELECT ta.*, c.case_number, CONCAT(cl.first_name, ' ', cl.last_name) as client_name 
                FROM tracker_assignments ta
                JOIN cases c ON ta.case_id = c.case_id
                JOIN clients cl ON c.client_id = cl.client_id
                WHERE ta.tracker_id = :tracker_id AND ta.removed_at IS NULL
                LIMIT 1";
        $assignment = $this->pdo->queryFirst($sql, [':tracker_id' => $trackerId]);
        
        if ($assignment) {
            return typeSet($assignment, "tracker_assignments");
        }
        return null;
    }
}
