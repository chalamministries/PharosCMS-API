<?php
/**
 * TrackerDataModel - Manages GPS tracker historical data
 * 
 * TABLE: tracker_data
 * ==================
 * Primary Key: id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * id            int             Primary key, auto-increment
 * tracker_id    int             Foreign key to trackers table
 * latitude      decimal(10,8)   Latitude coordinate
 * longitude     decimal(11,8)   Longitude coordinate
 * speed         decimal(5,2)    Speed of the tracker
 * altitude      decimal(7,2)    Altitude
 * heading       smallint        Heading/direction
 * recorded_at   timestamp       When the data was recorded
 * json          text            Raw data in JSON format
 * battery_level int             Battery level of the device
 */

class TrackerDataModel
{
    public $pdo;
    public $dataID = null;
    public $dataArr = [];

    public function __construct(?int $id = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($id !== null) {
            $this->dataID = $id;
            $this->getData();
        }
    }

    private function getData() {
        $data = $this->pdo->selectFirst("tracker_data", ["id" => $this->dataID]);
        if ($data) {
            $this->dataArr = typeSet($data, "tracker_data");
        }
    }

    public function getTrackerHistory($trackerId, $startDate, $endDate) {
        $sql = "SELECT * FROM tracker_data 
                WHERE tracker_id = :tracker_id 
                AND recorded_at BETWEEN :start_date AND :end_date 
                ORDER BY recorded_at ASC";
        $results = $this->pdo->query($sql, [
            ':tracker_id' => $trackerId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        if (!$results) {
            return [];
        }
        
        foreach ($results as &$row) {
            $row = typeSet($row, "tracker_data");
        }
        return $results;
    }

    public function getLatestData($trackerId) {
        $sql = "SELECT * FROM tracker_data 
                WHERE tracker_id = :tracker_id 
                ORDER BY recorded_at DESC LIMIT 1";
        $result = $this->pdo->queryFirst($sql, [':tracker_id' => $trackerId]);
        
        if ($result) {
            return typeSet($result, "tracker_data");
        }
        return null;
    }
}
