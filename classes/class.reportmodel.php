<?php
/**
 * ReportModel - Manages investigator reports
 * 
 * TABLE: reports
 * ==============
 * Primary Key: report_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * report_id               int(11)         Primary key, auto-increment
 * case_id                 int(11)         Foreign key to cases table
 * investigator_id         int(11)         Foreign key to investigators table
 * report_date             datetime        Date of report, default: current_timestamp
 * title                   varchar(255)    Report title (nullable)
 * content                 longtext        Full report content
 * client_facing_summary   text            Gemini-generated summary for clients (nullable)
 * status                  enum            Values: 'draft', 'submitted', 'approved', 'rejected', default: 'submitted'
 * admin_notes             text            Internal admin notes (nullable)
 * created_at              datetime        Auto-populated timestamp
 * updated_at              datetime        Auto-updated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id) - CASCADE delete
 * - Belongs to: investigators (investigator_id)
 * - Has many: media (report_id)
 * - Has many: time_entries (report_id)
 */
class ReportModel
{
    // Fields to exclude from updates (auto-managed)
    private const UPDATE_EXCLUDED_FIELDS = [
        'report_id',    // Primary key
        'created_at',   // Never updated
        'updated_at'    // Auto-updated
    ];
    
    private $pdo;
    private $reportId = null;
    private $reportArr = [];
    
    /**
     * Load a report with optional related data
     * 
     * @param int|null $reportId The report ID to load
     * 
     * @throws InvalidArgumentException If report ID is not an integer
     * @throws OutOfRangeException If report ID is not positive
     * @throws OutOfBoundsException If report is not found
     */
    public function __construct($reportId = null) {
        $this->pdo = $GLOBALS['pdo'];
        
        if ($reportId !== null) {
            if (!is_int($reportId)) {
                throw new InvalidArgumentException("Report ID must be an integer");
            }
            
            if ($reportId <= 0) {
                throw new OutOfRangeException("Report ID must be positive");
            }
            
            $this->reportId = $reportId;
            $this->getReport();
            
            if (empty($this->reportArr)) {
                throw new OutOfBoundsException("Report {$reportId} not found");
            }
        }
    }
    
    /**
     * Load the report record with related information
     */
    private function getReport() {
        $sql = "
            SELECT 
                r.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                i.email as investigator_email,
                c.case_number,
                c.case_title
            FROM reports r
            LEFT JOIN investigators i ON r.investigator_id = i.investigator_id
            LEFT JOIN cases c ON r.case_id = c.case_id
            WHERE r.report_id = :report_id
        ";
        
        $result = $this->pdo->query($sql, [":report_id" => $this->reportId]);
        
        if (!empty($result)) {
            $this->reportArr = $result[0];
            
            // Attach media
            $this->reportArr['media'] = $this->getReportMedia();
            $this->reportArr['media_count'] = count($this->reportArr['media']);
        }
    }
    
    /**
     * Get media for this report
     * 
     * @return array
     */
    private function getReportMedia() {
        if (empty($this->reportId)) {
            return [];
        }
        
        $mm = initializeClass("MediaModel");
        return $mm->getMediaByReport($this->reportId);
    }
    
    /**
     * Get all reports for a specific case with media
     * 
     * @param int $caseId
     * @return array
     */
    public function getReportsByCase($caseId) {
        $sql = "
            SELECT 
                r.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                i.email as investigator_email
            FROM reports r
            LEFT JOIN investigators i ON r.investigator_id = i.investigator_id
            WHERE r.case_id = :case_id
            ORDER BY r.report_date DESC
        ";
        
        $reports = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
        
        // Attach activities (with media) to each report
        $am = initializeClass("ActivityModel");
        foreach ($reports as &$report) {
            $report['activities'] = $am->getActivitiesByReport($report['report_id']);
            $report['activity_count'] = count($report['activities']);
            
            // Count total media across all activities
            $totalMedia = 0;
            foreach ($report['activities'] as $activity) {
                $totalMedia += $activity['media_count'];
            }
            $report['total_media'] = $totalMedia;
        }
        
        return $reports;
    }
    
    /**
     * Get reports by investigator
     * 
     * @param int $investigatorId
     * @param string|null $status Filter by status (optional)
     * @return array
     */
    public function getReportsByInvestigator($investigatorId, $status = null) {
        $sql = "
            SELECT 
                r.*,
                c.case_number,
                c.case_title
            FROM reports r
            LEFT JOIN cases c ON r.case_id = c.case_id
            WHERE r.investigator_id = :investigator_id
        ";
        
        $params = [":investigator_id" => $investigatorId];
        
        if ($status !== null) {
            $sql .= " AND r.status = :status";
            $params[":status"] = $status;
        }
        
        $sql .= " ORDER BY r.report_date DESC";
        
        $reports = $this->pdo->query($sql, $params) ?: [];
        
        // Attach media counts
        foreach ($reports as &$report) {
            $mediaCountSql = "SELECT COUNT(*) as count FROM media WHERE report_id = :report_id";
            $countResult = $this->pdo->query($mediaCountSql, [":report_id" => $report['report_id']]);
            $report['media_count'] = (int)($countResult[0]['count'] ?? 0);
        }
        
        return $reports;
    }
    
    /**
     * Get reports by status
     * 
     * @param string $status Values: 'draft', 'submitted', 'approved', 'rejected'
     * @return array
     */
    public function getReportsByStatus($status) {
        $sql = "
            SELECT 
                r.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                c.case_number,
                c.case_title
            FROM reports r
            LEFT JOIN investigators i ON r.investigator_id = i.investigator_id
            LEFT JOIN cases c ON r.case_id = c.case_id
            WHERE r.status = :status
            ORDER BY r.report_date DESC
        ";
        
        return $this->pdo->query($sql, [":status" => $status]) ?: [];
    }
    
    /**
     * Update report
     * 
     * @return bool
     */
    public function updateReport() {
        if (empty($this->reportArr)) {
            throw new BadMethodCallException("Cannot update: report not loaded");
        }
        
        if ($this->reportId == null || $this->reportId == 0) {
            throw new BadMethodCallException("Cannot update: invalid report ID");
        }
        
        // Get only valid report table fields, excluding auto-managed fields
        $reportData = filterTableFields(
            $this->reportArr, 
            'reports', 
            self::UPDATE_EXCLUDED_FIELDS
        );
        
        if (empty($reportData)) {
            throw new BadMethodCallException("No valid fields to update");
        }
        
        $this->pdo->update("reports", $reportData, array("report_id" => $this->reportId));
        return true;
    }
    
    /**
     * Create new report
     * 
     * @param array $reportData Array of report data to insert
     * @return int The newly created report_id
     * 
     * @throws InvalidArgumentException If required fields are missing
     */
    public function createReport($reportData) {
        // Validate required fields
        $requiredFields = ['case_id', 'investigator_id', 'content'];
        foreach ($requiredFields as $field) {
            if (!isset($reportData[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        // Filter to only valid table fields
        $reportData = filterTableFields($reportData, 'reports', ['report_id', 'created_at', 'updated_at']);
        
        // Set defaults
        if (!isset($reportData['status'])) {
            $reportData['status'] = 'submitted';
        }
        
        if (!isset($reportData['report_date'])) {
            $reportData['report_date'] = date('Y-m-d H:i:s');
        }
        
        $reportId = $this->pdo->insert("reports", $reportData);
        
        // Load the newly created report
        $this->reportId = $reportId;
        $this->getReport();
        
        return $reportId;
    }
    
    /**
     * Delete report (cascades to media and time_entries)
     * 
     * @return bool
     */
    public function deleteReport() {
        if (empty($this->reportArr)) {
            throw new BadMethodCallException("Cannot delete: report not loaded");
        }
        
        if ($this->reportId == null || $this->reportId == 0) {
            throw new BadMethodCallException("Cannot delete: invalid report ID");
        }
        
        $this->pdo->delete("reports", array("report_id" => $this->reportId));
        
        // Clear the loaded data
        $this->reportArr = [];
        $this->reportId = null;
        
        return true;
    }
    
    /**
     * Change report status
     * 
     * @param string $newStatus Values: 'draft', 'submitted', 'approved', 'rejected'
     * @return bool
     */
    public function changeStatus($newStatus) {
        if (empty($this->reportArr)) {
            throw new BadMethodCallException("Cannot change status: report not loaded");
        }
        
        $validStatuses = ['draft', 'submitted', 'approved', 'rejected'];
        
        if (!in_array($newStatus, $validStatuses)) {
            throw new DomainException(
                "Invalid status '{$newStatus}'. Must be: " . implode(', ', $validStatuses)
            );
        }
        
        $this->pdo->update(
            "reports", 
            array("status" => $newStatus),
            array("report_id" => $this->reportId)
        );
        
        $this->reportArr['status'] = $newStatus;
        
        return true;
    }
    
    /**
     * Get report counts for a case
     * 
     * @param int $caseId
     * @return array Counts broken down by status
     */
    public function getReportCounts($caseId) {
        $sql = "
            SELECT 
                COUNT(*) as total_reports,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_reports,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_reports,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_reports,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_reports
            FROM reports
            WHERE case_id = :case_id
        ";
        
        $result = $this->pdo->query($sql, [":case_id" => $caseId]);
        
        if (empty($result)) {
            return [
                'total_reports' => 0,
                'draft_reports' => 0,
                'submitted_reports' => 0,
                'approved_reports' => 0,
                'rejected_reports' => 0
            ];
        }
        
        return [
            'total_reports' => (int)$result[0]['total_reports'],
            'draft_reports' => (int)$result[0]['draft_reports'],
            'submitted_reports' => (int)$result[0]['submitted_reports'],
            'approved_reports' => (int)$result[0]['approved_reports'],
            'rejected_reports' => (int)$result[0]['rejected_reports']
        ];
    }
    
    /**
     * Get time entries associated with this report
     * 
     * @return array
     */
    public function getTimeEntries() {
        if (empty($this->reportId)) {
            return [];
        }
        
        $sql = "
            SELECT 
                te.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name
            FROM time_entries te
            LEFT JOIN investigators i ON te.investigator_id = i.investigator_id
            WHERE te.report_id = :report_id
            ORDER BY te.date_of_activity DESC
        ";
        
        return $this->pdo->query($sql, [":report_id" => $this->reportId]) ?: [];
    }
}