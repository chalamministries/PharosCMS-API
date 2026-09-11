<?php
/**
 * ObjectiveModel - Manages case objectives and their lifecycle
 * 
 * TABLE: objectives
 * =================
 * Primary Key: objective_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * objective_id                    int(11)         Primary key, auto-increment
 * case_id                         int(11)         Foreign key to cases table
 * title                           varchar(255)    Objective title/summary
 * description                     text            Detailed description of objective
 * priority                        enum            Values: 'urgent', 'high', 'medium', 'low', default: 'medium'
 * status                          enum            Values: 'draft', 'assigned', 'in_progress', 'completed', 'approved', default: 'draft'
 * estimated_hours                 decimal(5,2)    Estimated hours to complete
 * due_date                        date            Target completion date (nullable)
 * completed_date                  date            Actual completion date (nullable)
 * completed_by_investigator_id    int(11)         Investigator who marked as completed (nullable)
 * approved_by_user_id             int(11)         Case Manager who approved (nullable)
 * approved_at                     timestamp       When objective was approved (nullable)
 * display_order                   int(11)         Order to display objectives (for sorting)
 * created_at                      datetime        Auto-populated timestamp
 * updated_at                      datetime        Auto-updated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: cases (case_id) - CASCADE delete
 * - Belongs to: investigators (completed_by_investigator_id) - SET NULL on delete
 * - Belongs to: users (approved_by_user_id) - SET NULL on delete
 * - Has many: activities (objective_id)
 * 
 * STATUS FLOW:
 * ------------
 * draft → assigned → in_progress → completed → approved
 * 
 * NOTES:
 * ------
 * - Status automatically changes to 'in_progress' when first activity is created
 * - Investigator marks as 'completed' when work is done (sets completed_by_investigator_id)
 * - Case Manager marks as 'approved' after review (sets approved_by_user_id and approved_at)
 */
class ObjectiveModel
{
	// Fields to exclude from updates (auto-managed)
	private const UPDATE_EXCLUDED_FIELDS = [
		'objective_id',  // Primary key
		'created_at',    // Never updated
		'updated_at'     // Auto-updated
	];
	
	private $pdo;
	private $objectiveId = null;
	public $objectiveArr = [];
	
	public function __construct($objectiveId = null) {
		$this->pdo = $GLOBALS['pdo'];
		
		if ($objectiveId !== null) {
			if (!is_int($objectiveId)) {
				throw new InvalidArgumentException("Objective ID must be an integer");
			}
			
			if ($objectiveId <= 0) {
				throw new OutOfRangeException("Objective ID must be positive");
			}
			
			$this->objectiveId = $objectiveId;
			$this->getObjective();
			
			if (empty($this->objectiveArr)) {
				throw new OutOfBoundsException("Objective {$objectiveId} not found");
			}
		}
	}
	
	/**
	 * Load single objective with related data
	 */
	private function getObjective() {
		$sql = "
			SELECT 
				o.*,
				c.case_number,
				c.status as case_status,
				CONCAT(i.first_name, ' ', i.last_name) as completed_by_name,
				CONCAT(u.first_name, ' ', u.last_name) as approved_by_name
			FROM objectives o
			LEFT JOIN cases c ON o.case_id = c.case_id
			LEFT JOIN investigators i ON o.completed_by_investigator_id = i.investigator_id
			LEFT JOIN admins u ON o.approved_by_user_id = u.admin_id
			WHERE o.objective_id = :objective_id
		";
		
		$result = $this->pdo->queryFirst($sql, [":objective_id" => $this->objectiveId]);
		
		if (!empty($result)) {
			$this->objectiveArr = $result;
			
			// Use ActivityModel to get activities with full details (type, media, etc.)
			$am = initializeClass("ActivityModel");
			$this->objectiveArr['activities'] = $am->getActivitiesByObjective($this->objectiveId);
			
			// Calculate activity counts
			$this->objectiveArr['activity_count'] = count($this->objectiveArr['activities']);
			$this->objectiveArr['approved_activity_count'] = count(array_filter(
				$this->objectiveArr['activities'], 
				fn($a) => $a['status'] === 'approved'
			));
			
			// Calculate total hours logged for this objective
			$this->objectiveArr['hours_logged'] = array_reduce(
				$this->objectiveArr['activities'],
				fn($carry, $a) => $carry + ($a['status'] === 'approved' ? $a['duration_hours'] : 0),
				0
			);
		}
	}
	
	/**
	 * Get all objectives for a specific case
	 * 
	 * @param int $caseId
	 * @param bool $includeActivities Whether to include activities for each objective
	 * @return array
	 */
	public function getObjectivesByCase($caseId, $includeActivities = false) {
		$sql = "
			SELECT 
				o.*,
				CONCAT(i.first_name, ' ', i.last_name) as completed_by_name,
				CONCAT(admins.first_name, ' ', admins.last_name) as approved_by_name,
				COUNT(DISTINCT a.activity_id) as activity_count,
				COUNT(DISTINCT CASE WHEN a.status = 'approved' THEN a.activity_id END) as approved_activity_count,
				COALESCE(SUM(CASE WHEN (a.status = 'approved' OR a.status = 'submitted') THEN COALESCE(a.duration_hours, a.hours_spent) ELSE 0 END), 0) as hours_logged
			FROM objectives o
			LEFT JOIN activities a ON o.objective_id = a.objective_id
			LEFT JOIN investigators i ON o.completed_by_investigator_id = i.investigator_id
			LEFT JOIN admins ON o.approved_by_user_id = admins.admin_id
			WHERE o.case_id = :case_id
			GROUP BY o.objective_id, completed_by_name, approved_by_name, o.priority, o.created_at
			ORDER BY 
				FIELD(o.priority, 'urgent', 'high', 'medium', 'low'),
				o.created_at ASC
		";
		
		$objectives = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
		
		foreach ($objectives as &$objective) {
			// Calculate completion percentage if not manually set or at 0
			if (empty($objective['completion_percentage']) || $objective['completion_percentage'] == 0) {
				if (!empty($objective['estimated_hours']) && $objective['estimated_hours'] > 0) {
					$percentage = ((float)$objective['hours_logged'] / (float)$objective['estimated_hours']) * 100;
					$objective['completion_percentage'] = min(100, round($percentage));
				}
			}
			
			// For consistency with frontend expectations
			$objective['actual_hours'] = number_format((float)$objective['hours_logged'], 2);
			$objective['estimated_hours'] = number_format((float)$objective['estimated_hours'], 2);

			// Include full activity list with all details if requested
			if ($includeActivities) {
				$am = initializeClass("ActivityModel");
				// Use ActivityModel to get full activity details (type, media, etc.)
				$objective['activities'] = $am->getActivitiesByObjective($objective['objective_id']);
			}
		}
		
		return $objectives;
	}
	
	/**
	 * Get objectives by status
	 * 
	 * @param int $caseId
	 * @param string $status
	 * @return array
	 */
	public function getObjectivesByStatus($caseId, $status) {
		$validStatuses = ['draft', 'assigned', 'in_progress', 'completed', 'approved'];
		
		if (!in_array($status, $validStatuses)) {
			throw new DomainException(
				"Invalid status '{$status}'. Must be: " . implode(', ', $validStatuses)
			);
		}
		
		$sql = "
			SELECT 
				o.*,
				CONCAT(i.first_name, ' ', i.last_name) as completed_by_name,
				CONCAT(admins.first_name, ' ', admins.last_name) as approved_by_name,
				COUNT(DISTINCT a.activity_id) as activity_count,
				COALESCE(SUM(CASE WHEN (a.status = 'approved' OR a.status = 'submitted') THEN COALESCE(a.duration_hours, a.hours_spent) ELSE 0 END), 0) as hours_logged
			FROM objectives o
			LEFT JOIN activities a ON o.objective_id = a.objective_id
			LEFT JOIN investigators i ON o.completed_by_investigator_id = i.investigator_id
			LEFT JOIN admins ON o.approved_by_user_id = admins.admin_id
			WHERE o.case_id = :case_id AND o.status = :status
			GROUP BY o.objective_id, completed_by_name, approved_by_name, o.priority, o.created_at
			ORDER BY 
				FIELD(o.priority, 'urgent', 'high', 'medium', 'low'),
				o.created_at ASC
		";
		
		return $this->pdo->query($sql, [
			":case_id" => $caseId,
			":status" => $status
		]) ?: [];
	}
	
	/**
	 * Get incomplete objectives (draft, assigned, or in_progress)
	 * 
	 * @param int $caseId
	 * @return array
	 */
	public function getIncompleteObjectives($caseId) {
		$sql = "
			SELECT 
				o.*,
				COUNT(DISTINCT a.activity_id) as activity_count,
				COALESCE(SUM(CASE WHEN (a.status = 'approved' OR a.status = 'submitted') THEN COALESCE(a.duration_hours, a.hours_spent) ELSE 0 END), 0) as hours_logged
			FROM objectives o
			LEFT JOIN activities a ON o.objective_id = a.objective_id
			WHERE o.case_id = :case_id 
			  AND o.status IN ('draft', 'assigned', 'in_progress')
			GROUP BY o.objective_id, o.priority, o.created_at
			ORDER BY 
				FIELD(o.priority, 'urgent', 'high', 'medium', 'low'),
				o.created_at ASC
		";
		
		return $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
	}
	
	/**
	 * Get overdue objectives
	 * 
	 * @param int $caseId
	 * @return array
	 */
	public function getOverdueObjectives($caseId) {
		$sql = "
			SELECT 
				o.*,
				COUNT(DISTINCT a.activity_id) as activity_count,
				COALESCE(SUM(CASE WHEN (a.status = 'approved' OR a.status = 'submitted') THEN COALESCE(a.duration_hours, a.hours_spent) ELSE 0 END), 0) as hours_logged
			FROM objectives o
			LEFT JOIN activities a ON o.objective_id = a.objective_id
			WHERE o.case_id = :case_id 
			  AND o.status IN ('assigned', 'in_progress')
			  AND o.due_date IS NOT NULL
			  AND o.due_date < CURDATE()
			GROUP BY o.objective_id, o.due_date
			ORDER BY o.due_date ASC
		";
		
		return $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
	}
	
	/**
	 * Get objective counts by status for a case
	 * 
	 * @param int $caseId
	 * @return array [status => count]
	 */
	public function getObjectiveCounts($caseId) {
		$sql = "
			SELECT 
				status,
				COUNT(*) as count
			FROM objectives
			WHERE case_id = :case_id
			GROUP BY status
		";
		
		$results = $this->pdo->query($sql, [":case_id" => $caseId]) ?: [];
		
		// Convert to associative array
		$counts = [
			'draft' => 0,
			'assigned' => 0,
			'in_progress' => 0,
			'completed' => 0,
			'approved' => 0,
			'total' => 0
		];
		
		foreach ($results as $row) {
			$counts[$row['status']] = (int)$row['count'];
			$counts['total'] += (int)$row['count'];
		}
		
		return $counts;
	}
	
	/**
	 * Create new objective
	 * 
	 * @param array $objectiveData
	 * @return int objective_id
	 */
	public function createObjective($objectiveData) {
		$requiredFields = ['case_id', 'title', 'description'];
		foreach ($requiredFields as $field) {
			if (!isset($objectiveData[$field])) {
				throw new InvalidArgumentException("Missing required field: {$field}");
			}
		}
		
		// Filter to only valid table fields
		$objectiveData = filterTableFields(
			$objectiveData, 
			'objectives', 
			['objective_id', 'created_at', 'updated_at']
		);
		
		// Set defaults if not provided
		if (!isset($objectiveData['status'])) {
			$objectiveData['status'] = 'draft';
		}
		
		if (!isset($objectiveData['priority'])) {
			$objectiveData['priority'] = 'medium';
		}
		
		// Auto-set display_order if not provided
		if (!isset($objectiveData['display_order'])) {
			$sql = "SELECT COALESCE(MAX(display_order), 0) + 1 as next_order 
					FROM objectives 
					WHERE case_id = :case_id";
			$result = $this->pdo->queryFirst($sql, [':case_id' => $objectiveData['case_id']]);
			$objectiveData['display_order'] = $result['next_order'];
		}
		
		$objectiveId = $this->pdo->insert("objectives", $objectiveData);
		
		$this->objectiveId = $objectiveId;
		$this->getObjective();
		
		return $objectiveId;
	}
	
	/**
	 * Update objective
	 * 
	 * @return bool
	 */
	public function updateObjective() {
		if (empty($this->objectiveArr)) {
			throw new BadMethodCallException("Cannot update: objective not loaded");
		}
		
		if ($this->objectiveId == null || $this->objectiveId == 0) {
			throw new BadMethodCallException("Cannot update: invalid objective ID");
		}
		
		$objectiveData = filterTableFields(
			$this->objectiveArr, 
			'objectives', 
			self::UPDATE_EXCLUDED_FIELDS
		);
		
		if (empty($objectiveData)) {
			throw new BadMethodCallException("No valid fields to update");
		}
		
		$this->pdo->update("objectives", $objectiveData, array("objective_id" => $this->objectiveId));
		return true;
	}
	
	/**
	 * Mark objective as completed by investigator
	 * 
	 * @param int $investigatorId
	 * @return bool
	 */
	public function markCompleted($investigatorId) {
		if (empty($this->objectiveArr)) {
			throw new BadMethodCallException("Cannot mark completed: objective not loaded");
		}
		
		$updateData = [
			'status' => 'completed',
			'completed_date' => date('Y-m-d'),
			'completed_by_investigator_id' => $investigatorId
		];
		
		$this->pdo->update(
			"objectives", 
			$updateData,
			array("objective_id" => $this->objectiveId)
		);
		
		// Update local array
		$this->objectiveArr['status'] = 'completed';
		$this->objectiveArr['completed_date'] = $updateData['completed_date'];
		$this->objectiveArr['completed_by_investigator_id'] = $investigatorId;
		
		return true;
	}
	
	/**
	 * Approve objective (case manager action)
	 * 
	 * @param int $userId Case manager user_id
	 * @return bool
	 */
	public function approve($userId) {
		if (empty($this->objectiveArr)) {
			throw new BadMethodCallException("Cannot approve: objective not loaded");
		}
		
		// Must be completed before it can be approved
		if ($this->objectiveArr['status'] !== 'completed') {
			throw new DomainException("Cannot approve objective that is not completed");
		}
		
		$updateData = [
			'status' => 'approved',
			'approved_by_user_id' => $userId,
			'approved_at' => date('Y-m-d H:i:s')
		];
		
		$this->pdo->update(
			"objectives", 
			$updateData,
			array("objective_id" => $this->objectiveId)
		);
		
		// Update local array
		$this->objectiveArr['status'] = 'approved';
		$this->objectiveArr['approved_by_user_id'] = $userId;
		$this->objectiveArr['approved_at'] = $updateData['approved_at'];
		
		return true;
	}
	
	/**
	 * Change objective status
	 * 
	 * @param string $newStatus
	 * @param int|null $userId User making the change (optional)
	 * @return bool
	 */
	public function changeStatus($newStatus, $userId = null) {
		if (empty($this->objectiveArr)) {
			throw new BadMethodCallException("Cannot change status: objective not loaded");
		}
		
		$validStatuses = ['draft', 'assigned', 'in_progress', 'completed', 'approved'];
		
		if (!in_array($newStatus, $validStatuses)) {
			throw new DomainException(
				"Invalid status '{$newStatus}'. Must be: " . implode(', ', $validStatuses)
			);
		}
		
		$updateData = ['status' => $newStatus];
		
		// Auto-set completed_date when status changes to 'completed'
		if ($newStatus === 'completed' && empty($this->objectiveArr['completed_date'])) {
			$updateData['completed_date'] = date('Y-m-d');
			if ($userId) {
				$updateData['completed_by_investigator_id'] = $userId;
			}
		}
		
		// Auto-set approved fields when status changes to 'approved'
		if ($newStatus === 'approved') {
			if ($userId) {
				$updateData['approved_by_user_id'] = $userId;
			}
			$updateData['approved_at'] = date('Y-m-d H:i:s');
		}
		
		$this->pdo->update(
			"objectives", 
			$updateData,
			array("objective_id" => $this->objectiveId)
		);
		
		// Update local array
		foreach ($updateData as $key => $value) {
			$this->objectiveArr[$key] = $value;
		}
		
		return true;
	}
	
	/**
	 * Reorder objectives
	 * 
	 * @param array $orderedIds Array of objective_ids in desired order
	 * @return bool
	 */
	public function reorderObjectives($orderedIds) {
		if (empty($orderedIds)) {
			return false;
		}
		
		// Update display_order for each objective
		foreach ($orderedIds as $index => $objectiveId) {
			$this->pdo->update(
				"objectives",
				['display_order' => $index + 1],
				['objective_id' => $objectiveId]
			);
		}
		
		return true;
	}
	
	/**
	 * Delete objective
	 * WARNING: This will also delete associated activities due to CASCADE
	 *
	 * @return bool
	 */
	public function deleteObjective() {
		if (empty($this->objectiveArr)) {
			throw new BadMethodCallException("Cannot delete: objective not loaded");
		}

		// Check if there are ANY activities (user must delete activities first)
		if ($this->objectiveArr['activity_count'] > 0) {
			throw new DomainException(
				"Cannot delete objective with activities attached. " .
				"Please delete all activities first."
			);
		}

		$this->pdo->delete("objectives", ["objective_id" => $this->objectiveId]);

		$this->objectiveArr = [];
		$this->objectiveId = null;

		return true;
	}
	
	/**
	 * Check if objective is overdue
	 * 
	 * @return bool
	 */
	public function isOverdue() {
		if (empty($this->objectiveArr)) {
			return false;
		}
		
		// Not overdue if completed or no due date
		if (in_array($this->objectiveArr['status'], ['completed', 'approved'])) {
			return false;
		}
		
		if (empty($this->objectiveArr['due_date'])) {
			return false;
		}
		
		return strtotime($this->objectiveArr['due_date']) < strtotime('today');
	}
	
	/**
	 * Get completion percentage based on hours
	 * 
	 * @return int Percentage (0-100)
	 */
	public function getCompletionPercentage() {
		if (empty($this->objectiveArr)) {
			return 0;
		}
		
		if (empty($this->objectiveArr['estimated_hours']) || $this->objectiveArr['estimated_hours'] <= 0) {
			return 0;
		}
		
		$percentage = ($this->objectiveArr['hours_logged'] / $this->objectiveArr['estimated_hours']) * 100;
		
		return min(100, round($percentage));
	}
}