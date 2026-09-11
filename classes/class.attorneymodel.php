<?php
/**
 * AttorneyModel - Manages attorneys associated with cases
 * 
 * TABLE: case_attorneys
 * =====================
 * Primary Key: attorney_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * attorney_id     int(11)         Primary key, auto-increment
 * case_id         int(11)         Foreign key to cases table
 * name            varchar(255)    Attorney full name
 * phone           varchar(20)     Phone number (nullable)
 * address         varchar(500)    Full address (nullable)
 * email           varchar(255)    Email address (nullable)
 * created_at      timestamp       Auto-populated timestamp
 * updated_at      timestamp       Auto-updated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id) - CASCADE delete
 */
 
class AttorneyModel
{
	public $pdo;
	public $attorneyID = null;
	public $attorneyArr = [];
	
	function __construct(?int $attorneyId = null) {
		$this->pdo = $GLOBALS['pdo'];
		if($attorneyId !== null) {
			// The class STILL validates - defense in depth
			if(!is_int($attorneyId)) {
				throw new InvalidArgumentException("Attorney ID must be an integer");
			}
			
			if($attorneyId <= 0) {
				throw new OutOfRangeException("Attorney ID must be positive");
			}
			
			$this->attorneyID = $attorneyId;
			$this->getAttorney();
			
			// Check if attorney exists
			if(empty($this->attorneyArr)) {
				throw new OutOfBoundsException("Attorney {$attorneyId} not found");
			}
			
			return $this->caseArr;
		}
	}
	
	private function getAttorney() {
		
		$this->attorneyArr = $this->pdo->selectFirst("case_attorneys", array("attorney_id" => $this->attorneyID));

		
	}
	
	// public function updateAttorney() {
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
	public function getCaseAttorney($caseId) {
		if($caseId == null || $caseId == 0) {
			return false;
		}
		
		$attorney = $this->pdo->selectFirst("case_attorneys", array("case_id" => $caseId));
		
		if (!$attorney) {
			return array();
		}
		
		return typeSet($attorney, "case_attorneys");

	}
}