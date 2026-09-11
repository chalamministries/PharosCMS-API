<?php
/**
 * ParticipantModel - Manages case participants (clients, subjects, persons involved)
 * 
 * TABLE: case_participants
 * ========================
 * Primary Key: participant_id (int, AUTO_INCREMENT)
 *
 * FIELDS:
 * -------
 * participant_id   int(11)         Primary key, auto-increment
 * case_id          int(11)         Foreign key to cases table
 * participant_type varchar(100)    Any slug: 'subject', 'claimant', 'attorney', 'witness', etc.
 * first_name       varchar(100)    Convenience column — mirrored from metadata.first_name
 * last_name        varchar(100)    Convenience column — mirrored from metadata.last_name
 * cell_phone       varchar(20)     Convenience column — mirrored from metadata.cell_phone
 * metadata         longtext JSON   All participant field data (source of truth)
 * created_at       timestamp       Auto-populated timestamp
 * updated_at       timestamp       Auto-updated timestamp
 *
 * NOTE: All field data lives in metadata JSON. first_name/last_name/cell_phone
 * are also mirrored to dedicated columns for indexed querying/display.
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id)
 * - Has many: case_vehicles (participant_id)
 */
 
class ParticipantModel
{
	public $pdo;
	public $participantID = null;
	public $participantArr = [];
	
	function __construct(?int $participantId = null, bool $full = false) {
		$this->pdo = $GLOBALS['pdo'];
		if($participantId !== null) {
			// The class STILL validates - defense in depth
			if(!is_int($participantId)) {
				throw new InvalidArgumentException("Participant ID must be an integer");
			}
			
			if($participantId <= 0) {
				throw new OutOfRangeException("Participant ID must be positive");
			}
			
			$this->participantID = $participantId;
			$this->getParticipant($full);
			
			// Check if participant exists
			if(empty($this->participantArr)) {
				throw new OutOfBoundsException("Participant {$participantId} not found");
			}
			
			return $this->caseArr;
		}
	}
	
	private function getParticipant(bool $full = false) {
		
		$this->participantArr = $this->pdo->selectFirst("case_participants", array("participant_id" => $this->caseID));
		if($full) {
			
		}
		
	}
	
	// public function updateParticipant() {
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
	public function getCaseParticipants($caseId) {
		if($caseId == null || $caseId == 0) {
			return false;
		}
		
		$participants = $this->pdo->select("case_participants", array("case_id" => $caseId));
		
		if(!$participants) {
			return array();
		}
		
		foreach($participants AS &$participant) {
			$participant = typeSet($participant, "case_participants");
			// Decode metadata JSON string to array so callers get a consistent object
			if (isset($participant['metadata']) && is_string($participant['metadata'])) {
				$participant['metadata'] = json_decode($participant['metadata'], true) ?: [];
			}
		}

		return $participants;
		
	}
}