<?php
/**
 * TrackerSmsLogModel - Manages SMS command logs sent to trackers
 * 
 * TABLE: tracker_sms_log
 * =====================
 * Primary Key: log_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * log_id              int             Primary key, auto-increment
 * tracker_id          int             Foreign key to trackers table
 * sim_carrier         int             Foreign key to sim_carrier table
 * device_id           varchar(255)    ICCID sent to carrier API
 * message_sent        varchar(500)    The SMS command text
 * external_message_id varchar(255)    messageID returned from carrier API
 * status              enum            'pending', 'sent', 'delivered', 'failed'
 * response            text            Response from tracker/carrier
 * response_at         timestamp       When the response was received
 * sent_at             timestamp       When the SMS was sent
 */

class TrackerSmsLogModel
{
    public $pdo;
    public $logID = null;
    public $logArr = [];

    public function __construct(?int $logId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($logId !== null) {
            $this->logID = $logId;
            $this->getLog();
        }
    }

    private function getLog() {
        $log = $this->pdo->selectFirst("tracker_sms_log", ["log_id" => $this->logID]);
        if ($log) {
            $this->logArr = typeSet($log, "tracker_sms_log");
        }
    }

    public function getLogsByTracker($trackerId) {
        $logs = $this->pdo->select("tracker_sms_log", ["tracker_id" => $trackerId], null, null, ['sent_at' => 'DESC']);
        if (!$logs) {
            return [];
        }
        foreach ($logs as &$row) {
            $row = typeSet($row, "tracker_sms_log");
        }
        return $logs;
    }
}
