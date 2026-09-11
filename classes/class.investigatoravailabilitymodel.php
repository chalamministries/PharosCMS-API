<?php
/**
 * InvestigatorAvailabilityModel - Manages investigator availability
 * 
 * TABLE: investigator_availability
 * ================================
 * Primary Key: availability_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * availability_id int             Primary key, auto-increment
 * investigator_id int             Foreign key to investigators table
 * date            date            The date for this availability record
 * status          enum            'available', 'unavailable', 'limited', 'assigned'
 * notes           varchar(255)    Additional notes
 * hours_available decimal(4,2)    Hours available on this date
 * hours_assigned  decimal(4,2)    Hours already assigned
 */

class InvestigatorAvailabilityModel
{
    public $pdo;
    public $availabilityID = null;
    public $availabilityArr = [];

    public function __construct(?int $availabilityId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($availabilityId !== null) {
            $this->availabilityID = $availabilityId;
            $this->getAvailability();
        }
    }

    private function getAvailability() {
        $availability = $this->pdo->selectFirst("investigator_availability", ["availability_id" => $this->availabilityID]);
        if ($availability) {
            $this->availabilityArr = typeSet($availability, "investigator_availability");
        }
    }

    public function getInvestigatorAvailability($investigatorId, $startDate, $endDate) {
        $sql = "SELECT * FROM investigator_availability 
                WHERE investigator_id = :investigator_id 
                AND date BETWEEN :start_date AND :end_date";
        $results = $this->pdo->query($sql, [
            ':investigator_id' => $investigatorId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        if (!$results) {
            return [];
        }
        
        foreach ($results as &$row) {
            $row = typeSet($row, "investigator_availability");
        }
        return $results;
    }
}
