<?php
/**
 * InvestigatorModel - Manages investigator/user accounts
 * 
 * TABLE: investigators
 * ====================
 * Primary Key: investigator_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * investigator_id     int(11)         Primary key, auto-increment
 * first_name          varchar(100)    First name
 * last_name           varchar(100)    Last name
 * email               varchar(255)    Email address (unique)
 * phone_number        varchar(20)     Phone number (nullable)
 * license_number      varchar(50)     PI license number (unique)
 * specializations     text            Comma-separated or JSON list (nullable)
 * hourly_rate         decimal(10,2)   Default hourly rate (nullable)
 * password_hash       varchar(255)    Hashed password
 * status              enum            Values: 'active', 'inactive', 'on_leave', default: 'active'
 * created_at          datetime        Auto-populated timestamp
 * updated_at          datetime        Auto-updated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Has many: cases (investigator_id)
 * - Has many: case_assignments
 * - Has many: reports
 * - Has many: time_entries
 * - Has many: media
 */
 
class InvestigatorModel
{
	public $pdo;
	public $investigatorID = null;
	public $investigatorArr = [];
	
	function __construct(?int $investigatorId = null, bool $includeWorkload = false) {
		$this->pdo = $GLOBALS['pdo'];
		if($investigatorId !== null) {
			// The class STILL validates - defense in depth
			if(!is_int($investigatorId)) {
				throw new InvalidArgumentException("Investigator ID must be an integer");
			}
			
			if($investigatorId <= 0) {
				throw new OutOfRangeException("Investigator ID must be positive");
			}
			
			$this->investigatorID = $investigatorId;
			$this->getInvestigator($includeWorkload);
			
			// Check if investigator exists
			if(empty($this->investigatorArr)) {
				throw new OutOfBoundsException("Investigator {$investigatorId} not found");
			}
			
			return $this->investigatorArr;
		}
	}
	
	private function getInvestigator(bool $includeWorkload = false) {
		
		$investigator = $this->pdo->selectFirst("investigators", array("investigator_id" => $this->investigatorID));
		if (!$investigator) {
			return;
		}
		$this->investigatorArr = typeSet($investigator, "investigators");
		unset($this->investigatorArr['password_hash']);

		if ($includeWorkload) {
			$workloadQuery = "
				SELECT
					COALESCE(iw.active_cases, 0) as active_cases,
					COALESCE(iw.total_hours_logged, 0) as total_hours,
					COALESCE(iw.pending_activities, 0) as pending_activities,
					COALESCE(iw.submitted_activities, 0) as submitted_activities
				FROM investigators i
				LEFT JOIN investigator_workload iw ON i.investigator_id = iw.investigator_id
				WHERE i.investigator_id = :investigator_id
			";
			$workload = $this->pdo->queryFirst($workloadQuery, [':investigator_id' => $this->investigatorID]);

			$casesQuery = "SELECT COUNT(DISTINCT case_id) as total_cases_assigned
						   FROM case_assignments
						   WHERE investigator_id = :investigator_id";
			$casesResult = $this->pdo->queryFirst($casesQuery, [':investigator_id' => $this->investigatorID]);

			$this->investigatorArr['workload'] = [
				'active_cases' => $workload ? (int)$workload['active_cases'] : 0,
				'total_hours' => $workload ? (float)$workload['total_hours'] : 0.0,
				'pending_activities' => $workload ? (int)$workload['pending_activities'] : 0,
				'submitted_activities' => $workload ? (int)$workload['submitted_activities'] : 0,
				'total_cases_assigned' => $casesResult ? (int)$casesResult['total_cases_assigned'] : 0
			];
		}
	}
	
	// public function updateInvestigator() {
	// 	if(empty($this->caseArr)) {
	// 		throw new BadMethodCallException("Cannot update: case not loaded");
	// 	} else {
	// 		if($this->caseID == null || $this->caseID == 0) {
	// 			throw new BadMethodCallException("Cannot update: invalid case ID");
	// 		} else {
	// 			$this->pdo->update("cases", $this->caseArr, array("case_id" => $this->caseID));
	// 			return true;
	// 		}
	// 	}
	// }

	/************************/
	/*   Helper Functions   */
	/************************/
	public function getCaseInvestigators($caseId) {
		if($caseId == null || $caseId == 0) {
			return false;
		}
		
		$investigators = $this->pdo->select("investigators", array("case_id" => $caseId));
		
		if(!$investigators) {
			return array();
		}
		
		foreach($investigators AS &$investigator) {
			$investigator = typeSet($investigator, "investigators");
		}
		
		return $investigators;
	}

	public function getAllInvestigators() {
		$sql = "
			SELECT
				i.*,
				COALESCE(iw.active_cases, 0) as active_cases,
				COALESCE(iw.total_hours_logged, 0) as total_hours,
				COALESCE(iw.pending_activities, 0) as pending_activities,
				COALESCE(iw.submitted_activities, 0) as submitted_activities,
				(
					SELECT COUNT(*)
					FROM case_assignments ca
					JOIN cases c ON ca.case_id = c.case_id
					WHERE ca.investigator_id = i.investigator_id
					AND ca.unassigned_at IS NULL
					AND c.status = 'assigned'
				) as count_assigned,
				(
					SELECT COUNT(*)
					FROM case_assignments ca
					JOIN cases c ON ca.case_id = c.case_id
					WHERE ca.investigator_id = i.investigator_id
					AND ca.unassigned_at IS NULL
					AND c.status = 'in_progress'
				) as count_inprogress,
				CASE
					WHEN COALESCE(iw.total_hours_logged, 0) >= 40 THEN 100
					ELSE ROUND((COALESCE(iw.total_hours_logged, 0) / 40.0) * 100)
				END as capacity
			FROM investigators i
			LEFT JOIN investigator_workload iw ON i.investigator_id = iw.investigator_id
			ORDER BY
				CASE i.status
					WHEN 'active' THEN 1
					WHEN 'on_leave' THEN 2
					ELSE 3
				END,
				capacity ASC,
				i.last_name ASC
		";

		$results = $this->pdo->query($sql);
		if (!$results) {
			return [];
		}

		foreach ($results as &$row) {
			$row = typeSet($row, "investigators");
			unset($row['password_hash']);
		}
		return $results;
	}
}