<?php
/**
 * VehicleModel - Manages vehicles associated with cases and participants
 * 
 * TABLE: case_vehicles
 * ====================
 * Primary Key: vehicle_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * vehicle_id          int(11)         Primary key, auto-increment
 * case_id             int(11)         Foreign key to cases table
 * participant_id      int(11)         Foreign key to case_participants table (nullable)
 * make                varchar(50)     Vehicle make (nullable)
 * model               varchar(50)     Vehicle model (nullable)
 * color               varchar(50)     Vehicle color (nullable)
 * tag_number          varchar(20)     License plate/tag number (nullable)
 * decals_markings     text            Description of decals/markings (nullable)
 * created_at          timestamp       Auto-populated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id) - CASCADE delete
 * - Belongs to: case_participants (participant_id) - SET NULL on delete
 */
 
class VehicleModel
{
	public $pdo;
	public $vehicleID = null;
	public $vehicleArr = [];
	
	function __construct(?int $vehicleId = null) {
		$this->pdo = $GLOBALS['pdo'];
		if($vehicleId !== null) {
			// The class STILL validates - defense in depth
			if(!is_int($vehicleId)) {
				throw new InvalidArgumentException("Vehicle ID must be an integer");
			}
			
			if($vehicleId <= 0) {
				throw new OutOfRangeException("Vehicle ID must be positive");
			}
			
			$this->vehicleID = $vehicleId;
			$this->getVehicle();
			
			// Check if vehicle exists
			if(empty($this->vehicleArr)) {
				throw new OutOfBoundsException("Vehicle {$vehicleId} not found");
			}
			
			return $this->caseArr;
		}
	}
	
	private function getVehicle() {
		
		$this->vehicleArr = $this->pdo->selectFirst("case_vehicles", array("vehicle_id" => $this->vehicleID));

		
	}
	
	// public function updateVehicle() {
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
	public function getCaseVehicles($caseId) {
		if($caseId == null || $caseId == 0) {
			return false;
		}
		
		$vehicles = $this->pdo->select("case_vehicles", array("case_id" => $caseId));
		
		if(!$vehicles) {
			return array();
		}
		
		foreach($vehicles AS &$vehicle) {
			$vehicle = typeSet($vehicle, "case_vehicles");
		}
		
		return $vehicles;
	}
	
	public function getParticipantVehicles($participantId) {
		if($participantId == null || $participantId == 0) {
			return array();
		}
		
		$vehicles = $this->pdo->select("case_vehicles", array("participant_id" => $participantId));
		
		if(!$vehicles) {
			return array();
		}
		
		foreach($vehicles AS &$vehicle) {
			$vehicle = typeSet($vehicle, "case_vehicles");
		}

		return $vehicles;
	}
}