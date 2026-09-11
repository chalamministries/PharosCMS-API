<?php
/**
 * CaseModel - Manages case records and related data
 * 
 * TABLE: cases
 * ============
 * Primary Key: case_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * case_id                      int(11)         Primary key, auto-increment
 * client_id                    int(11)         Foreign key to clients table
 * investigator_id              int(11)         Foreign key to investigators table (nullable)
 * case_number                  varchar(50)     Unique case identifier
 * case_title                   varchar(255)    Brief title/description of case
 * case_type                    int(11)         Foreign key to case_type table
 * status                       enum            Values: 'new', 'assigned', 'in_progress', 'on_hold', 'closed_pending_bill', 'closed_billed'
 * description                  text            Detailed case description
 * objectives                   text            Case objectives (nullable)
 * relationship_to_subject      enum            Values: 'spouse', 'divorced', 'boyfriend_girlfriend', 'engaged', 'living_together', 'separate' (nullable)
 * location                     text            City/State information (nullable)
 * has_legal_proceedings        tinyint(1)      Boolean: 0 or 1
 * legal_proceedings_description text           Description of legal proceedings (nullable)
 * initial_hours_allotted       decimal(10,2)   Default: 12.00
 * hours_used                   decimal(10,2)   Default: 0.00 (auto-calculated by triggers)
 * total_cost_billed            decimal(12,2)   Default: 0.00 (auto-calculated by triggers)
 * ai_synopsis                  text            Gemini-generated summary (nullable)
 * budget_amount                decimal(10,2)   Budget for case (nullable)
 * client_hourly_rate           decimal(10,2)   Client billing rate override (nullable, default: $175 system default)
 * client_report_template       text            Gemini-generated client report (nullable)
 * start_date                   date            Case start date
 * end_date                     date            Case end date (nullable)
 * admin_notes                  text            Internal admin notes (nullable)
 * created_at                   datetime        Auto-populated timestamp
 * updated_at                   datetime        Auto-updated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Belongs to: clients (client_id)
 * - Belongs to: investigators (investigator_id)
 * - Belongs to: case_type (case_type)
 * - Has many: case_participants
 * - Has many: case_vehicles
 * - Has many: case_assignments
 * - Has many: reports
 * - Has many: time_entries
 * - Has many: media
 */
 
class CaseModel
{
	
	private const UPDATE_EXCLUDED_FIELDS = [
		'case_id',             // Primary key
		'hours_used',          // Trigger-managed
		'total_cost_billed',   // Trigger-managed
		'created_at',          // Never updated
		'updated_at'           // Auto-updated
	];
	
	public $pdo;
	public $caseID = null;
	public $caseArr = [];
	
	/**
	 * Load a case with optional related data
	 * 
	 * @param int|null $caseId The case ID to load
	 * @param array|bool $include Array of sections to include, or true for all
	 * 
	 * Available options:
	 * - 'participants'    : All case participants
	 * - 'vehicles'        : Vehicles (requires 'participants')
	 * - 'assignments'     : Investigator assignments
	 * - 'investigators'   : Unique investigators
	 * - 'reports'         : Investigation reports
	 * - 'time_summary'    : Time tracking summary
	 * - 'media'           : Media files
	 * - '*' or true       : Load everything
	 * 
	 * @example initializeClass("CaseModel", 123) // Base case data only
	 * @example initializeClass("CaseModel", 123, ['participants', 'vehicles']) // Specific sections
	 * @example initializeClass("CaseModel", 123, ['*']) // Everything
	 * @example initializeClass("CaseModel", 123, true) // Everything (backward compatible)
	 * 
	 * @throws InvalidArgumentException If case ID is not an integer
	 * @throws OutOfRangeException If case ID is not positive
	 * @throws OutOfBoundsException If case is not found
	 */
	
	function __construct(?int $caseId = null, $include = [], $user = null) {
		$this->pdo = $GLOBALS['pdo'];
		if($caseId !== null) {
			
			if(!is_int($caseId)) {
				throw new InvalidArgumentException("Case ID must be an integer");
			}
			
			if($caseId <= 0) {
				throw new OutOfRangeException("Case ID must be positive");
			}
			
			$this->caseID = $caseId;
			$this->getCase($include, $user);
			
			// Check if case exists
			if(empty($this->caseArr)) {
				throw new OutOfBoundsException("Case {$caseId} not found");
			}
			
			return $this->caseArr;
		}
	}
	
	public function getCaseChecksum($caseId) {
		return $this->pdo->selectFirst("case_checksums", array("case_id" => $caseId));
	}
	
	private function getCase($include = [], $user = null) {
		// Always load base case data

		$sql = "
			SELECT
				c.*,
				-- Current investigator info - only if there's an active assignment
				CASE
					WHEN ca.assignment_id IS NOT NULL AND ca.unassigned_at IS NULL
					THEN CONCAT(i.first_name, ' ', i.last_name)
					ELSE NULL
				END AS current_investigator_name,
				ca.assigned_at as current_investigator_assigned_date,
				CASE
					WHEN ca.assignment_id IS NOT NULL AND ca.unassigned_at IS NULL THEN 'current'
					ELSE 'unassigned'
				END AS investigator_assignment_status,

				-- Completed date calculation
				CASE
					WHEN c.status IN ('closed_pending_bill', 'closed_billed')
					THEN c.updated_at
					ELSE NULL
				END as completed_date,

				-- Activity statistics for this case
				COALESCE(activity_stats.total_activities, 0) as total_activities,
				COALESCE(activity_stats.total_hours, 0) as total_hours_logged,
				COALESCE(activity_stats.approved_activities, 0) as approved_activities,
				COALESCE(activity_stats.approved_hours, 0) as approved_hours

			FROM cases c

			-- Current investigator assignment - find the active lead assignment
			LEFT JOIN case_assignments ca
				ON c.case_id = ca.case_id
				AND ca.role = 'lead'
				AND ca.unassigned_at IS NULL
			LEFT JOIN investigators i
				ON ca.investigator_id = i.investigator_id

			-- Activity statistics
			LEFT JOIN (
				SELECT
					case_id,
					COUNT(*) as total_activities,
					SUM(duration_hours) as total_hours,
					SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_activities,
					SUM(CASE WHEN status = 'approved' THEN duration_hours ELSE 0 END) as approved_hours
				FROM activities
				GROUP BY case_id
			) activity_stats ON c.case_id = activity_stats.case_id

			WHERE c.case_id = :case_id
		";
		
		$this->caseArr = $this->pdo->queryFirst($sql, [':case_id' => $this->caseID]);
		
		if (empty($this->caseArr)) {
			return;
		}

		// Set full_access_code if access_code exists
		if (isset($this->caseArr['access_code'])) {
			$this->caseArr['full_access_code'] = str_pad($this->caseArr['access_code'], 4, '0', STR_PAD_LEFT) . $this->caseArr['case_id'];
		} else {
			// Generate one if it doesn't exist
			$randomCode = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
			$this->pdo->update("cases", ['access_code' => $randomCode], ['case_id' => $this->caseArr['case_id']]);
			$this->caseArr['access_code'] = $randomCode;
			$this->caseArr['full_access_code'] = $randomCode . $this->caseArr['case_id'];
		}
		
		$ct = $this->pdo->selectFirst("case_type", array("case_type_id" => $this->caseArr['case_type_id']));
		$this->caseArr['case_type'] = $ct['description'];
		
		$case_status = "";
		switch($this->caseArr['status']) {
			case "closed_billed":
				$case_status = "billed";
				break;
			case "closed_pending_bill":
				$case_status = "closed";
				break;
			default:
				$case_status = str_replace("_", " ", $this->caseArr['status']);
				break;
		}
		$this->caseArr['case_status'] = $case_status;
		
		if ($include === true) {
			$include = ['*'];
		}
		
		// If empty array, load nothing extra (just base case data)
		if (empty($include)) {
			return;
		}
		
		// Handle "load everything" shortcut
		if (in_array('*', $include)) {
            error_log("loading full case data");
			$this->loadFullCaseData($user);
			return;
		}
		
		
		// Load only requested sections
		
		if (in_array('client_simple', $include)) {
			$this->caseArr['client'] = $this->getClientSimple();
		}
		
		if (in_array('client', $include)) {
			$this->caseArr['client'][] = $this->getClient();
		}
		
		if (in_array('participants', $include)) {
			$this->caseArr['participants'] = $this->getParticipants();
		}
		
		// Vehicles depends on participants, so check both
		if (in_array('vehicles', $include) && isset($this->caseArr['participants'])) {
			$this->attachVehiclesToParticipants();
		}
		
		if (in_array('assignments', $include)) {
			$this->caseArr['assignments'] = $this->getAssignments();
		}
		
		if (in_array('investigators', $include)) {
			// Investigators depend on assignments being loaded
			if (!isset($this->caseArr['assignments'])) {
				$this->caseArr['assignments'] = $this->getAssignments();
			}
			$this->caseArr['investigators'] = $this->getInvestigators();
		}
		
		if (in_array('reports', $include)) {
            $this->caseArr['reports'] = $this->getReports();
        }
		
		if (in_array('media', $include)) {
			$this->caseArr['media'] = $this->getMedia();
		}

		if (in_array('messages', $include)) {
			$user_id = $user['user_id'] ?? null;
			$user_type = $user['user_type'] ?? null;
			$this->caseArr['messages'] = $this->getMessages($user_id, $user_type);
		}
		
		if (in_array('objectives', $include)) {
			$this->caseArr['objectives'] = $this->getObjectives();
		}
		
		if (in_array('trackers', $include)) {
			$this->caseArr['trackers'] = $this->getTrackers();
		}
		
		if (in_array('billing_items', $include)) {
			$this->caseArr['billing_items'] = $this->getBillingItems();
		}
		
		if (in_array('invoices', $include)) {
			$this->caseArr['invoices'] = $this->getInvoices();
		}
	}
	
	/**
	 * Get a list of cases with optional filtering, sorting, and pagination
	 * 
	 * @param array $user Authenticated user data (user_type, user_id)
	 * @param array $filters Filtering options (status, case_type, client_id, search, sort, order, page, limit)
	 * @return array Array containing 'cases' and 'pagination' information
	 */
	public function listCases(array $user, array $filters = []): array {
		$params = [];
		$where = [];

		// Use case_summary view which includes objectives, investigators, and activities
		$query = "SELECT
			cs.case_id,
			cs.case_number,
			cs.case_title,
			cs.case_type_id,
			cs.case_type,
			cs.case_type_code,
			cs.status,
			cs.start_date,
			cs.end_date,
			cs.created_at,
			cs.updated_at,
			cs.initial_hours_allotted,
			cs.hours_used,
			cs.remaining_hours,
			cs.budget_amount,
			cs.total_cost_billed,
			cs.budget_remaining,
			cs.total_objectives,
			cs.completed_objectives,
			cs.objectives_completion_percentage,
			cs.client_first_name,
			cs.client_last_name,
			cs.client_email,
			cs.client_company,
			cs.client_name,
			cs.investigators,
			cs.assigned_investigator,
			cs.total_activities,
			c.ai_synopsis
		FROM case_summary cs
		LEFT JOIN cases c ON cs.case_id = c.case_id";

		// Filter based on user type
		if ($user['user_type'] === 'client') {
			// Clients can only see their own cases
			$query .= " LEFT JOIN clients cl ON cs.client_name = CONCAT(cl.first_name, ' ', cl.last_name)";
			$where[] = "cl.client_id = :client_id";
			$params[':client_id'] = $user['user_id'];
		} elseif ($user['user_type'] === 'investigator') {
			// Investigators can see cases where they are in the investigators list
			$where[] = "FIND_IN_SET(:investigator_name, REPLACE(cs.investigators, ', ', ',')) > 0";
			// Get investigator name
			$invQuery = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM investigators WHERE investigator_id = :inv_id";
			$invResult = $this->pdo->queryFirst($invQuery, [':inv_id' => $user['user_id']]);
			$params[':investigator_name'] = $invResult['full_name'];
		}

		// Optional filters from parameters
		// Support multiple statuses (comma-separated or array)
		if (isset($filters['status']) && !empty($filters['status'])) {
			$statuses = is_array($filters['status']) ? $filters['status'] : explode(',', $filters['status']);
			$statusPlaceholders = [];
			foreach ($statuses as $i => $status) {
				$key = ':status_' . $i;
				$statusPlaceholders[] = $key;
				$params[$key] = trim($status);
			}
			$where[] = "cs.status IN (" . implode(', ', $statusPlaceholders) . ")";
		}

		// Support multiple case types (comma-separated or array)
		if (isset($filters['case_type']) && !empty($filters['case_type'])) {
			$caseTypes = is_array($filters['case_type']) ? $filters['case_type'] : explode(',', $filters['case_type']);
			$typePlaceholders = [];
			foreach ($caseTypes as $i => $type) {
				$key = ':case_type_' . $i;
				$typePlaceholders[] = $key;
				$params[$key] = trim($type);
			}
			$where[] = "cs.case_type_id IN (" . implode(', ', $typePlaceholders) . ")";
		}

		// Filter by client_id (admins only)
		if (isset($filters['client_id']) && !empty($filters['client_id']) && in_array($user['user_type'], ['admin', 'super_admin', 'case_manager'])) {
			$query .= " LEFT JOIN clients cl2 ON cs.client_name = CONCAT(cl2.first_name, ' ', cl2.last_name)";
			$where[] = "cl2.client_id = :filter_client_id";
			$params[':filter_client_id'] = $filters['client_id'];
		}

		// Add WHERE clause if any conditions exist
		if (!empty($where)) {
			$query .= " WHERE " . implode(" AND ", $where);
		}

		// Add ordering
		$sort = $filters['sort'] ?? 'created_at';
		$order = (isset($filters['order']) && strtoupper($filters['order']) === 'ASC') ? 'ASC' : 'DESC';
		$validSorts = ['start_date', 'created_at', 'case_number', 'status'];
		if (!in_array($sort, $validSorts)) {
			$sort = 'created_at';
		}
		$query .= " ORDER BY cs.$sort $order";

		// Add pagination
		$page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
		$limit = isset($filters['limit']) ? min(100, max(1, intval($filters['limit']))) : 20;
		$offset = ($page - 1) * $limit;
		
		$queryWithLimit = $query . " LIMIT :limit OFFSET :offset";
		$paramsWithLimit = $params;
		$paramsWithLimit[':limit'] = $limit;
		$paramsWithLimit[':offset'] = $offset;

		// Execute main query
		$cases = $this->pdo->query($queryWithLimit, $paramsWithLimit);

		// Get total count for pagination
		$countQuery = "SELECT COUNT(DISTINCT cs.case_id) as total FROM case_summary cs";
		if ($user['user_type'] === 'client') {
			$countQuery .= " LEFT JOIN clients cl2 ON cs.client_name = CONCAT(cl2.first_name, ' ', cl2.last_name)";
		}
		if (!empty($where)) {
			$countQuery .= " WHERE " . implode(" AND ", $where);
		}
		$totalResult = $this->pdo->queryFirst($countQuery, $params);
		$total = $totalResult ? intval($totalResult['total']) : 0;

		return [
			'cases' => $cases ?: [],
			'query' => str_replace(array("\n", "\t"), "", $queryWithLimit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'total_pages' => ceil($total / $limit)
			]
		];
	}

	private function loadFullCaseData($user = null) {
		// Load everything

		$this->caseArr['client'] = $this->getClient();
		$this->caseArr['participants'] = $this->getParticipants();
		$this->attachVehiclesToParticipants();
		$this->caseArr['assignments'] = $this->getAssignments();
		$this->caseArr['investigators'] = $this->getInvestigators();
		$this->caseArr['reports'] = $this->getReports();
		$this->caseArr['time_summary'] = $this->getTimeSummary();
		$this->caseArr['media'] = $this->getMedia();
		$this->caseArr['objectives'] = $this->getObjectives();
		$this->caseArr['trackers'] = $this->getTrackers();
		$this->caseArr['billing_items'] = $this->getBillingItems();
		
		$user_id = $user['user_id'] ?? null;
		$user_type = $user['user_type'] ?? null;
		$this->caseArr['messages'] = $this->getMessages($user_id, $user_type);
		
		$this->caseArr['invoices'] = $this->getInvoices();
	}
	
	/**
	 * Create a new case (draft)
	 *
	 * @param array $data Case data
	 * @return int The new case ID
	 */
	public function createCase(array $data): int {
		// Validate required fields
		$requiredFields = ['client_id', 'case_type', 'description', 'start_date'];
		foreach ($requiredFields as $field) {
			if (!isset($data[$field]) || empty($data[$field])) {
				throw new InvalidArgumentException("Missing required field: {$field}");
			}
		}

		// Set default access code if not provided
		if (!isset($data['access_code'])) {
			$data['access_code'] = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
		}

		// Generate case number if not provided
		if (!isset($data['case_number']) || empty($data['case_number'])) {
			$data['case_number'] = $this->generateCaseNumber();
		}

		// Set defaults
		$caseData = [
			'client_id' => $data['client_id'],
			'case_number' => $data['case_number'],
			'case_title' => $data['case_title'] ?? null,
			'case_type_id' => $data['case_type_id'] ?? $data['case_type'],
			'status' => $data['status'] ?? 'new',
			'priority' => $data['priority'] ?? 'medium',
			'description' => $data['description'],
			'initial_hours_allotted' => $data['initial_hours_allotted'] ?? 12.00,
			'budget_amount' => $data['budget_amount'] ?? null,
			'start_date' => $data['start_date'],
			'end_date' => $data['end_date'] ?? null,
			'admin_notes' => $data['admin_notes'] ?? null,
			'access_code' => str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT)
		];

		$caseId = $this->pdo->insert('cases', $caseData);

		if (!$caseId) {
			throw new RuntimeException("Failed to create case");
		}

		$this->caseID = $caseId;
		return $caseId;
	}

	/**
	 * Generate a unique case number
	 * Format: CASE-YYYYMM-XXXX (e.g., CASE-202601-0001)
	 */
	private function generateCaseNumber(): string {
		$prefix = 'CASE-' . date('Ym') . '-';

		// Find the highest case number for this month
		$sql = "SELECT case_number FROM cases
				WHERE case_number LIKE :prefix
				ORDER BY case_number DESC LIMIT 1";

		$result = $this->pdo->queryFirst($sql, [':prefix' => $prefix . '%']);

		if ($result && $result['case_number']) {
			// Extract the sequence number and increment
			$lastNumber = (int)substr($result['case_number'], -4);
			$nextNumber = $lastNumber + 1;
		} else {
			$nextNumber = 1;
		}

		return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
	}

	/**
	 * Create Bunny CDN folder for case
	 */
	public function createCaseFolder(int $caseId, int $clientId): ?string {
		try {
			$client = $this->pdo->selectFirst("clients", ["client_id" => $clientId]);
			if (!$client) {
				return null;
			}

			$caseFolder = "cases/" . $client['first_name'] . $client['last_name'] . '/' . $caseId;

			// Initialize Bunny client
			$bunnyClient = new \Bunny\Storage\Client(
				$_ENV['BUNNY_CLIENT_SECRET'],
				$_ENV['BUNNY_STORAGE_ZONE'],
				\Bunny\Storage\Region::NEW_YORK
			);

			// Create a placeholder file to establish the folder
			$placeholderPath = $caseFolder . '/.folder';
			$bunnyClient->putContents($placeholderPath, '');

			return $caseFolder;
		} catch (Exception $e) {
			error_log("Failed to create Bunny folder: " . $e->getMessage());
			return null;
		}
	}

	public function updateCase() {
		if (empty($this->caseArr)) {
			throw new BadMethodCallException("Cannot update: case not loaded");
		}
		
		if ($this->caseID == null || $this->caseID == 0) {
			throw new BadMethodCallException("Cannot update: invalid case ID");
		}
		
		$caseData = filterTableFields(
			$this->caseArr, 
			'cases', 
			self::UPDATE_EXCLUDED_FIELDS
		);
		
		if (empty($caseData)) {
			throw new BadMethodCallException("No valid fields to update");
		}
		
		$this->pdo->update("cases", $caseData, array("case_id" => $this->caseID));
		return true;
	}
	
	/**
	 * Get active cases currently assigned to a specific investigator
	 * 
	 * @param int $investigatorId The investigator ID
	 * @return array Array of active cases currently assigned to the investigator
	 */
	public function getActiveCasesForInvestigator(int $investigatorId): array {
		return array();
	}
	
	/**
	 * Get all active cases an investigator has been part of (currently or previously)
	 * Includes indicators for current assignment status
	 * 
	 * @param int $investigatorId The investigator ID
	 * @return array Array of active cases with assignment status indicators
	 */
	public function getActiveInvestigatorHistory(int $investigatorId): array {
		return array();
	}
	
	/**
	 * Get ALL cases an investigator has been part of (active and closed)
	 * Includes indicators for current assignment and case status
	 * 
	 * @param int $investigatorId The investigator ID
	 * @return array Array of all cases with full status indicators
	 */
	public function getAllInvestigatorCases(int $investigatorId): array {
		$sql = "
			SELECT
                c.case_id,
                c.case_number,
                ct.description as case_type,
                c.status,
                c.start_date,
                c.initial_hours_allotted as budgeted_hours,
                c.closed_at as completed_date,
                cl.first_name as client_first_name,
                cl.last_name as client_last_name,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_full_name,
                CASE
                    WHEN c.status IN ('closed_pending_bill', 'closed_billed')
                        THEN c.updated_at
                    ELSE NULL
                    END as completed_date,
                CASE
                    WHEN ca.unassigned_at IS NULL THEN 'current'
                    ELSE 'previous'
                    END AS assignment_status,
                CASE
                    WHEN c.status = 'closed_pending_bill' THEN 'completed'
                    WHEN c.status = 'closed_billed' THEN 'completed'
                    WHEN c.status IN ('in_progress', 'on_hold') THEN REPLACE(c.status, '_', ' ')
                    ELSE c.status
                    END AS case_status,
                CASE
                    WHEN c.investigator_id = ca.investigator_id
                        AND ca.unassigned_at IS NULL
                        AND c.status NOT IN ('closed_pending_bill', 'closed_billed')
                        THEN 'YES'
                    ELSE 'NO'
                    END AS is_current_investigator,
                CONCAT(i.first_name, ' ', i.last_name) AS investigator_name,
                ca.assigned_at as investigator_assigned_date,
            
                -- Objective counts
                COALESCE(obj_stats.total_objectives, 0) as total_objectives,
                COALESCE(obj_stats.completed_objectives, 0) as completed_objectives,
                COALESCE(obj_stats.in_progress_objectives, 0) as in_progress_objectives,
            
                -- Hours logged by THIS investigator
                COALESCE(hours_stats.total_hours, 0) as hours_logged,
                hours_stats.first_activity_date as work_started_date,
            
                -- Calculate percentage of budget used
                CASE
                    WHEN c.initial_hours_allotted > 0
                        THEN ROUND((COALESCE(hours_stats.total_hours, 0) / c.initial_hours_allotted) * 100, 1)
                    ELSE NULL
                    END as budget_percentage_used
            
            FROM cases c
                     INNER JOIN (
                SELECT
                    case_id,
                    investigator_id,
                    assigned_at,
                    unassigned_at,
                    ROW_NUMBER() OVER (PARTITION BY case_id, investigator_id ORDER BY assigned_at DESC) as rn
                FROM case_assignments
                WHERE investigator_id = :investigator_id
            ) ca ON c.case_id = ca.case_id AND ca.rn = 1
            
                     INNER JOIN case_type ct
                                ON c.case_type_id = ct.case_type_id
                     INNER JOIN clients cl
                                ON c.client_id = cl.client_id
                     INNER JOIN investigators i
                                ON ca.investigator_id = i.investigator_id
            
                     LEFT JOIN (
                SELECT
                    case_id,
                    COUNT(*) as total_objectives,
                    SUM(CASE WHEN status IN ('completed', 'approved') THEN 1 ELSE 0 END) as completed_objectives,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_objectives
                FROM objectives
                GROUP BY case_id
            ) obj_stats ON c.case_id = obj_stats.case_id
            
                     LEFT JOIN (
                SELECT
                    case_id,
                    investigator_id,
                    SUM(duration_hours) as total_hours,
                    MIN(created_at) as first_activity_date
                FROM activities
                WHERE investigator_id = :investigator_id
                GROUP BY case_id, investigator_id
            ) hours_stats ON c.case_id = hours_stats.case_id
                AND ca.investigator_id = hours_stats.investigator_id
            
            ORDER BY
                c.status NOT IN ('closed_pending_bill', 'closed_billed') DESC,
                ca.unassigned_at IS NULL DESC,
                c.start_date DESC
		";
		
		return $this->pdo->query($sql, [':investigator_id' => $investigatorId]) ?: [];
	}
	
	/************************/
	/*   Helper Functions   */
	/************************/
	private function getClientSimple() {
		if($this->caseArr['client_id'] == null || $this->caseArr['client_id'] == 0) {
			return false;
		} 
		
		$c = initializeClass("ClientModel", $this->caseArr['client_id']);
		$cl = $c->clientArr;
		$client = array('client_full_name' => $cl['first_name'] . ' ' . $cl['last_name'],
						'phone' => $cl['phone_number'],
						'cell_phone' => $cl['cell_phone'],
						'email' => $cl['email']);
		return $client;
	}
	
	private function getClient() {
		if($this->caseArr['client_id'] == null || $this->caseArr['client_id'] == 0) {
			return false;
		} 
		
		$c = initializeClass("ClientModel", $this->caseArr['client_id']);
		$cl = $c->clientArr;
		$cl['full_name'] = $cl['first_name'] . ' ' . $cl['last_name'];
		return $cl;
	}
	
	private function getParticipants() {
		if($this->caseID == null || $this->caseID == 0) {
			return false;
		} 
		
		$pa = initializeClass("ParticipantModel");
		return $pa->getCaseParticipants($this->caseID);
		
	}
	
	private function attachVehiclesToParticipants() {
		if (empty($this->caseArr['participants'])) {
			return;
		}
		
		$vm = initializeClass("VehicleModel");
		$allVehicles = $vm->getCaseVehicles($this->caseID);
		
		if (!$allVehicles) {
			return;
		}
		
		// Index vehicles by participant_id for fast lookup
		$vehiclesByParticipant = [];
		foreach ($allVehicles as $vehicle) {
			$participantId = $vehicle['participant_id'] ?? 0;
			
			$vehiclesByParticipant[$participantId][] = $vehicle;
		}
		
		// Attach vehicles to participants
		foreach ($this->caseArr['participants'] as &$participant) {
			$participantId = $participant['participant_id'] ?? 0;
			$participant['vehicles'] = $vehiclesByParticipant[$participantId] ?? [];
		}
	}
	
	private function getInvestigators() {
		if (empty($this->caseArr['assignments'])) {
			return array();
		}
		
		$investigators = array();
		$seenIds = array();  // Track which investigator IDs we've already added
		
		foreach ($this->caseArr['assignments'] as $assignment) {
			
			$inv = initializeClass("InvestigatorModel", $assignment['investigator_id']);
			$investigator = $inv->investigatorArr;
			if ($investigator) {
				$investigatorId = $investigator['investigator_id'];
				
				// Skip if we've already added this investigator
				if (in_array($investigatorId, $seenIds)) {
					continue;
				}
				
				// Add investigator ID to our tracking array
				$seenIds[] = $investigatorId;
				
				// Remove sensitive/unnecessary fields
				unset($investigator['password_hash']);
				unset($investigator['hourly_rate']);
				
				$investigators[] = $investigator;
			}
		}
		
		return $investigators;
	}
	
	private function getAssignments() {
		if($this->caseID == null || $this->caseID == 0) {
			return false;
		}
		
		$am = initializeClass("AssignmentModel");
		$assignments = $am->getActiveAssignmentsByCase($this->caseID);
		
		if(!$assignments) {
			return array();
		}
		
		return $assignments;
	}
	
	private function getReports() {
		if($this->caseID == null || $this->caseID == 0) {
			return false;
		}
		
		$rm = initializeClass("ReportModel");
		
		if(!$rm) {
			return array();
		}
		
		return $rm->getReportsByCase($this->caseID);
	}
	
	private function getObjectives() {
		if($this->caseID == null || $this->caseID == 0) {
			return false;
		}
		
		$rm = initializeClass("ObjectiveModel");
		
		if(!$rm) {
			return array();
		}
		
		//pass true as second parameter to include activities
		return $rm->getObjectivesByCase($this->caseID, true);
	}
	
	private function getMedia() {
		if($this->caseID == null || $this->caseID == 0) {
			return false;
		}
		
		$mm = initializeClass("MediaModel");
		
		if(!$mm) {
			return array();
		}
		
		return $mm->getMediaByCase($this->caseID);
	}
	
	private function getTimeSummary() {
		$sql = "
			SELECT 
				COUNT(te.activity_id) as total_entries,
				COALESCE(SUM(te.hours_spent), 0) as total_hours,
				COALESCE(SUM(te.hours_spent * ca.assigned_hourly_rate), 0) as total_cost
			FROM activities te
			JOIN case_assignments ca ON te.case_id = ca.case_id 
				AND te.investigator_id = ca.investigator_id
			WHERE te.case_id = :case_id
				AND (te.status = 'approved' OR te.status = 'submitted')
				AND ca.unassigned_at IS NULL
		";
		
		$result = $this->pdo->query($sql, [":case_id" => $this->caseID]);
		
		if (empty($result)) {
			return [
				'total_entries' => 0,
				'total_hours' => 0.00,
				'total_cost' => 0.00
			];
		}
		
		return [
			'total_entries' => (int)$result[0]['total_entries'],
			'total_hours' => (float)$result[0]['total_hours'],
			'total_cost' => (float)$result[0]['total_cost']
		];
	}

	private function getTrackers() {
		if($this->caseID == null || $this->caseID == 0) {
			return [];
		}
		$tam = initializeClass("TrackerAssignmentModel");
		return $tam->getActiveAssignmentsByCase($this->caseID);
	}

	private function getBillingItems() {
		if($this->caseID == null || $this->caseID == 0) {
			return [];
		}
		$bim = initializeClass("BillingItemModel");
		return $bim->getItemsByCase($this->caseID);
	}

	public function getMessages($user_id = null, $user_type = null) {
		if($this->caseID == null || $this->caseID == 0) {
			return [];
		}
		$mm = initializeClass("MessageModel");
		$items = $mm->getMessagesByCase($this->caseID);
		
		$unread_count = 0;
		$last_read_message_id = null;
		
		if ($user_id !== null && $user_type !== null) {
			$unread_count = $mm->getUnreadCount($this->caseID, $user_id, $user_type);
			$last_read_message_id = $mm->getLastReadMessageId($this->caseID, $user_id, $user_type);
		}

		return [
			'room_id' => (string)$this->caseID,
            'case_id' => (string)$this->caseID,
			'channel' => "/cases/{$this->caseID}/messages",
			'unread_count' => $unread_count,
			'last_read_message_id' => $last_read_message_id,
			'items' => $items
		];
	}

	private function getInvoices() {
		if($this->caseID == null || $this->caseID == 0) {
			return [];
		}
		$im = initializeClass("InvoiceModel");
		return $im->getInvoicesByCase($this->caseID);
	}
	
}