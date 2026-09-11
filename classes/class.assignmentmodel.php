<?php
/**
 * AssignmentModel - Manages investigator assignments to cases
 * 
 * TABLE: case_assignments
 * =======================
 * Primary Key: assignment_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * assignment_id           int(11)         Primary key, auto-increment
 * case_id                 int(11)         Foreign key to cases table
 * investigator_id         int(11)         Foreign key to investigators table
 * role                    enum            Values: 'lead', 'support', default: 'support'
 * assigned_at             datetime        Auto-populated timestamp, default: current_timestamp()
 * unassigned_at           datetime        Date/time investigator was unassigned (nullable)
 * assigned_hourly_rate    decimal(10,2)   Investigator rate for this specific case (nullable)
 * 
 * UNIQUE CONSTRAINT: (case_id, investigator_id) - prevents duplicate assignments
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id) - CASCADE delete
 * - Belongs to: investigators (investigator_id)
 * 
 * BUSINESS RULES:
 * ---------------
 * - Each investigator can only be assigned to a case once (enforced by unique constraint)
 * - To reassign an investigator, set unassigned_at on old record and create new assignment
 * - assigned_hourly_rate overrides the investigator's default rate for this case
 * - Only active assignments have unassigned_at = NULL
 */
class AssignmentModel
{
	private $pdo;
	private $assignmentId = null;
	private $assignmentArr = [];
	
	public function __construct($assignmentId = null) {
		$this->pdo = $GLOBALS['pdo'];
		
		if ($assignmentId !== null) {
			if (!is_int($assignmentId)) {
				throw new InvalidArgumentException("Assignment ID must be an integer");
			}
			
			if ($assignmentId <= 0) {
				throw new OutOfRangeException("Assignment ID must be positive");
			}
			
			$this->assignmentId = $assignmentId;
			$this->getAssignment();
			
			if (empty($this->assignmentArr)) {
				throw new OutOfBoundsException("Assignment {$assignmentId} not found");
			}
		}
	}
	
	private function getAssignment() {
		$this->assignmentArr = $this->pdo->selectFirst("case_assignments", array("assignment_id" => $this->assignmentId));
	}
	
	/**
	 * Get all active assignments for a case
	 * Includes investigator details for display
	 * @param int $caseId
	 * @return array
	 */
	public function getActiveAssignmentsByCase($caseId) {
		$sql = "SELECT
					ca.*,
					i.first_name,
					i.last_name,
					CONCAT(i.first_name, ' ', i.last_name) AS investigator_name,
					i.email AS investigator_email,
					i.hourly_rate AS default_hourly_rate
				FROM case_assignments ca
				JOIN investigators i ON ca.investigator_id = i.investigator_id
				WHERE ca.case_id = :case_id AND ca.unassigned_at IS NULL
				ORDER BY ca.role DESC, ca.assigned_at ASC";  // 'lead' before 'support'

		return $this->pdo->query($sql, array(":case_id" => $caseId));
	}
	
	/**
	 * Get active assignments for an investigator
	 * @param int $investigatorId
	 * @return array
	 */
	public function getActiveAssignmentsByInvestigator($investigatorId) {
		$sql = "SELECT * FROM case_assignments 
				WHERE investigator_id = :investigator_id AND unassigned_at IS NULL 
				ORDER BY assigned_at DESC";
		
		return $this->pdo->query($sql, array(":investigtor_id" => $investigatorId));
	}
	
	/**
	 * Unassign an investigator from a case
	 * @return bool
	 */
	public function unassign() {
		if (empty($this->assignmentArr)) {
			throw new BadMethodCallException("Cannot unassign: assignment not loaded");
		}
		
		$this->assignmentArr['unassigned_at'] = date('Y-m-d H:i:s');
		
		$result = $this->pdo->update(
			"case_assignments", 
			array("unassigned_at" => $this->assignmentArr['unassigned_at']),
			array("assignment_id" => $this->assignmentId)
		);
		
		return $result;
	}
	
	/**
	 * Check if investigator is already assigned to case
	 * @param int $caseId
	 * @param int $investigatorId
	 * @return bool
	 */
	public function isInvestigatorAssigned($caseId, $investigatorId) {
		$sql = "SELECT COUNT(*) as count FROM case_assignments 
				WHERE case_id = ? AND investigator_id = ? AND unassigned_at IS NULL";
		
		$result = $this->pdo->query($sql, [$caseId, $investigatorId]);
		
		return $result[0]['count'] > 0;
	}
}