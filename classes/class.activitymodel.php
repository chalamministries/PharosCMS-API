<?php
/**
 * ActivityModel - Manages investigator activities (formerly time_entries)
 * 
 * TABLE: activities
 * =================
 * Primary Key: activity_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * activity_id             int(11)         Primary key, auto-increment
 * case_id                 int(11)         Foreign key to cases table
 * objective_id            int(11)         Foreign key to objectives table (nullable)
 * investigator_id         int(11)         Foreign key to investigators table
 * activity_type           int(11)         Foreign key to activity_type table
 * uuid                    text            Unique Identifier to tie media to activity
 * report_id               int(11)         Foreign key to reports table (nullable) - set by AI when report generated
 * date_of_activity        date            Date work was performed
 * duration_hours          decimal(5,2)    Hours worked
 * activity_description    text            Description of work performed
 * status                  enum            Values: 'draft', 'submitted', 'approved', 'declined', 'needs_revision', default: 'draft'
 * admin_notes             text            Internal admin notes (nullable)
 * created_at              datetime        Auto-populated timestamp
 * updated_at              datetime        Auto-updated timestamp
 * 
 * TRIGGERS:
 * ---------
 * - After INSERT/UPDATE: Updates cases.hours_used when status = 'approved'
 * - After UPDATE: Updates cases.total_cost_billed based on approved hours × hourly rate
 * - After INSERT: Updates objective status from 'assigned' to 'in_progress' if first activity
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id) - CASCADE delete
 * - Belongs to: objectives (objective_id) - SET NULL on delete
 * - Belongs to: investigators (investigator_id)
 * - Belongs to: activity_type (activity_type)
 * - Belongs to: reports (report_id) - SET NULL on delete
 * - Has many: media (activity_id)
 */
class ActivityModel
{
    // Fields to exclude from updates (auto-managed)
    private const UPDATE_EXCLUDED_FIELDS = [
        'activity_id',  // Primary key
        'created_at',   // Never updated
        'updated_at'    // Auto-updated
    ];
    
    private $pdo;
    private $activityId = null;
    public $activityArr = [];
    
    public function __construct($activityId = null) {
        $this->pdo = $GLOBALS['pdo'];
        
        if ($activityId !== null) {
            if (!is_int($activityId)) {
                throw new InvalidArgumentException("Activity ID must be an integer");
            }
            
            if ($activityId <= 0) {
                throw new OutOfRangeException("Activity ID must be positive");
            }
            
            $this->activityId = $activityId;
            $this->getActivity();
            
            if (empty($this->activityArr)) {
                throw new OutOfBoundsException("Activity {$activityId} not found");
            }
        }
    }
    
    /**
     * Load single activity with related data
     */
    private function getActivity() {
        $sql = "
            SELECT 
                a.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                o.objective_title as objective_title,
                o.status as objective_status
            FROM activities a
            LEFT JOIN investigators i ON a.investigator_id = i.investigator_id
            LEFT JOIN cases c ON a.case_id = c.case_id
            LEFT JOIN reports r ON a.report_id = r.report_id
            LEFT JOIN objectives o ON a.objective_id = o.objective_id
            WHERE a.activity_id = :activity_id
        ";
        
        $result = $this->pdo->queryFirst($sql, [":activity_id" => $this->activityId]);
        //$types = $this->getActivityTypes();
        if (!empty($result)) {
            $this->activityArr = $result;
//            $type = searchArray($types, "activity_type_id", $this->activityArr['activity_type']);
//            $this->activityArr['activity_type'] = $type;
            // Attach media
            $this->activityArr['media'] = $this->getActivityMedia($this->activityArr['activity_id']);
            
            // Attach time entries
            $this->activityArr['time_entries'] = $this->pdo->query(
                "SELECT * FROM time_entries WHERE activity_id = :activity_id ORDER BY entry_time ASC",
                [":activity_id" => $this->activityId]
            ) ?: [];
           
            $this->activityArr['media_count'] = array_reduce($this->activityArr['media'], function($carry, $group) {
                return $carry + count($group['files']);
            }, 0);
        }
    }
    
    /**
     * Get media for an activity
     * 
     * @param int $activityId
     * @return array
     */
    private function getActivityMedia($activityId) {
        if (empty($activityId)) {
            return [];
        }
        
        $mm = initializeClass("MediaModel");
        return $mm->getMediaByActivity($activityId);
    }
    
    /**
     * Get all activities for a specific case
     * 
     * @param int $caseId
     * @param bool $includeMedia Whether to include media for each activity
     * @return array
     */
    public function getActivitiesByCase($caseId, $includeMedia = false) {
        $sql = "
            SELECT 
                a.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                o.objective_title as objective_title,
                o.status as objective_status,
                COUNT(m.media_id) AS media_count
            FROM activities a
            LEFT JOIN investigators i ON a.investigator_id = i.investigator_id
            LEFT JOIN objectives o ON a.objective_id = o.objective_id
            LEFT JOIN media m ON a.activity_id = m.activity_id
            WHERE a.case_id = :case_id
            GROUP BY a.activity_id
            ORDER BY a.date_of_activity DESC, a.created_at DESC
        ";
        
        $activities = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
        
        $types = $this->getActivityTypes();
        
        foreach ($activities as &$activity) {
            // Add activity type info
            //$type = searchArray($types, "activity_type_id", $activity['activity_type']);
            //$activity['activity_type'] = $type;
            
            // Only include full media if requested
            if ($includeMedia) {
                $mm = initializeClass("MediaModel");
                $activity['media'] = $mm->getMediaByActivity($activity['activity_id']);
            }
        }
        
        return $activities;
    }
    
    /**
     * Get activities for a specific objective
     * 
     * @param int $objectiveId
     * @return array
     */
    public function getActivitiesByObjective($objectiveId) {
        $sql = "
            SELECT 
                a.*,
                CONCAT(ad.first_name, ' ' , ad.last_name) AS approved_by_name,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                COUNT(m.media_id) AS media_count
            FROM activities a
            LEFT JOIN investigators i ON a.investigator_id = i.investigator_id
            LEFT JOIN media m ON a.activity_id = m.activity_id
            LEFT JOIN admins ad ON a.approved_by = ad.admin_id
            WHERE a.objective_id = :objective_id
            GROUP BY a.activity_id
            ORDER BY a.date_of_activity DESC, a.created_at DESC
        ";
        
        $activities = $this->pdo->query($sql, [":objective_id" => $objectiveId]) ?: [];
        
        //$types = $this->getActivityTypes();
        
        foreach ($activities as &$activity) {
            // Add activity type info
            //$type = searchArray($types, "activity_type_id", $activity['activity_type']);
            //$activity['activity_type'] = $type;
            // Attach media
            $activity['media'] = $this->getActivityMedia($activity['activity_id']);
            
            $activity['media_count'] = array_reduce($activity['media'], function($carry, $group) {
                return $carry + count($group['files']);
            }, 0);
        }
        
        return $activities;
    }
    
    /**
     * Get activities for a specific report
     * 
     * @param int $reportId
     * @return array
     */
    public function getActivitiesByReport($reportId) {
        $sql = "
            SELECT 
                a.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                o.objective_title as objective_title
            FROM activities a
            LEFT JOIN investigators i ON a.investigator_id = i.investigator_id
            LEFT JOIN objectives o ON a.objective_id = o.objective_id
            WHERE a.report_id = :report_id
            ORDER BY a.date_of_activity ASC
        ";
        
        $activities = $this->pdo->query($sql, [":report_id" => $reportId]) ?: [];
        
        $types = $this->getActivityTypes();
        
        // Attach media to each activity
        $mm = initializeClass("MediaModel");
        foreach ($activities as &$activity) {
            // Add activity type info
            //$type = searchArray($types, "activity_type_id", $activity['activity_type']);
            //$activity['activity_type'] = $type;
            
            $activity['media'] = $mm->getMediaByActivity($activity['activity_id']);
            $activity['media_count'] = array_reduce($activity['media'], function($carry, $group) {
                return $carry + count($group['files']);
            }, 0);
        }
        
        return $activities;
    }
    
    /**
     * Get unassigned activities (not yet included in a report)
     * 
     * @param int $caseId
     * @return array
     */
    public function getUnassignedActivities($caseId) {
        $sql = "
            SELECT 
                a.*,
                CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
                o.objective_title as objective_title
            FROM activities a
            LEFT JOIN investigators i ON a.investigator_id = i.investigator_id
            LEFT JOIN objectives o ON a.objective_id = o.objective_id
            WHERE a.case_id = :case_id AND a.report_id IS NULL AND a.status = 'submitted'
            ORDER BY a.date_of_activity ASC
        ";
        
        $activities = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
        
        $types = $this->getActivityTypes();
        
        // Attach media
        $mm = initializeClass("MediaModel");
        foreach ($activities as &$activity) {
            // Add activity type info
            //$type = searchArray($types, "activity_type_id", $activity['activity_type']);
            //$activity['activity_type'] = $type;
            
            $activity['media'] = $mm->getMediaByActivity($activity['activity_id']);
            $activity['media_count'] = array_reduce($activity['media'], function($carry, $group) {
                return $carry + count($group['files']);
            }, 0);
        }
        
        return $activities;
    }
    
    /**
     * Assign activities to a report (used by AI cron job)
     * 
     * @param array $activityIds Array of activity IDs
     * @param int $reportId The report to assign them to
     * @return bool
     */
    public function assignToReport($activityIds, $reportId) {
        if (empty($activityIds)) {
            return false;
        }
        
        // Build named placeholders: :id0, :id1, :id2, etc.
        $placeholders = [];
        $params = [':report_id' => $reportId];
        
        foreach ($activityIds as $index => $activityId) {
            $placeholder = ":id{$index}";
            $placeholders[] = $placeholder;
            $params[$placeholder] = $activityId;
        }
        
        $placeholderString = implode(',', $placeholders);
        $sql = "UPDATE activities SET report_id = :report_id WHERE activity_id IN ({$placeholderString})";
      
        $this->pdo->query($sql, $params);
        
        return true;
    }
    
    /**
     * Update activity
     * 
     * @return bool
     */
    public function updateActivity() {
        if (empty($this->activityArr)) {
            throw new BadMethodCallException("Cannot update: activity not loaded");
        }
        
        if ($this->activityId == null || $this->activityId == 0) {
            throw new BadMethodCallException("Cannot update: invalid activity ID");
        }
        
        $activityData = filterTableFields(
            $this->activityArr, 
            'activities', 
            self::UPDATE_EXCLUDED_FIELDS
        );
        
        if (empty($activityData)) {
            throw new BadMethodCallException("No valid fields to update");
        }
        
        $this->pdo->update("activities", $activityData, array("activity_id" => $this->activityId));
        return true;
    }
    
    /**
     * Create new activity
     * 
     * @param array $activityData
     * @return int activity_id
     */
    public function createActivity($activityData) {
        $requiredFields = ['case_id', 'investigator_id', 'date_of_activity', 'duration_hours', 'activity_description'];
        foreach ($requiredFields as $field) {
            if (!isset($activityData[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        // Validate objective_id if provided
        if (isset($activityData['objective_id']) && !empty($activityData['objective_id'])) {
            // Verify objective belongs to this case
            $sql = "SELECT case_id FROM objectives WHERE objective_id = :objective_id";
            $result = $this->pdo->queryFirst($sql, [':objective_id' => $activityData['objective_id']]);
            
            if (empty($result)) {
                throw new InvalidArgumentException("Objective not found");
            }
            
            if ($result['case_id'] != $activityData['case_id']) {
                throw new DomainException("Objective does not belong to this case");
            }
        }
        
        $activityData = filterTableFields($activityData, 'activities', ['activity_id', 'created_at', 'updated_at']);
        
        if (!isset($activityData['status'])) {
            $activityData['status'] = 'draft';
        }
        
        $activityId = $this->pdo->insert("activities", $activityData);
        
        // Update objective status through ObjectiveModel
        if (!empty($activityData['objective_id'])) {
            $om = initializeClass("ObjectiveModel", $activityData['objective_id']);
            $om->handleActivityCreated();
        }
        
        $this->activityId = $activityId;
        $this->getActivity();
        
        return $activityId;
    }
    
    /**
     * Update objective status to 'in_progress' when first activity is created
     * 
     * @param int $objectiveId
     */
    private function updateObjectiveStatus($objectiveId) {
        // Check current status
        $sql = "SELECT status FROM objectives WHERE objective_id = :objective_id";
        $result = $this->pdo->queryFirst($sql, [':objective_id' => $objectiveId]);
        
        // Only change from 'assigned' to 'in_progress'
        if ($result && $result['status'] === 'assigned') {
            $this->pdo->update(
                'objectives',
                ['status' => 'in_progress'],
                ['objective_id' => $objectiveId]
            );
        }
    }
    
    /**
     * Change activity status
     * 
     * @param string $newStatus
     * @return bool
     */
    public function changeStatus($newStatus) {
        if (empty($this->activityArr)) {
            throw new BadMethodCallException("Cannot change status: activity not loaded");
        }
        
        $validStatuses = ['draft', 'submitted', 'approved', 'declined', 'needs_revision'];
        
        if (!in_array($newStatus, $validStatuses)) {
            throw new DomainException(
                "Invalid status '{$newStatus}'. Must be: " . implode(', ', $validStatuses)
            );
        }
        
        $this->pdo->update(
            "activities", 
            array("status" => $newStatus),
            array("activity_id" => $this->activityId)
        );
        
        $this->activityArr['status'] = $newStatus;
        
        return true;
    }
    
    /**
     * Get activity types from lookup table
     * 
     * @return array
     */
    public function getActivityTypes() {
        return $this->pdo->select("activity_type");
    }
    
    /**
     * Delete activity
     * WARNING: This will also delete associated media due to CASCADE
     * 
     * @return bool
     */
    public function deleteActivity() {
        if (empty($this->activityArr)) {
            throw new BadMethodCallException("Cannot delete: activity not loaded");
        }
        
        // Check if activity is approved
        if ($this->activityArr['status'] === 'approved') {
            throw new DomainException(
                "Cannot delete approved activity. Change status first."
            );
        }
        
        $this->pdo->delete("activities", ["activity_id" => $this->activityId]);
        
        $this->activityArr = [];
        $this->activityId = null;
        
        return true;
    }
}