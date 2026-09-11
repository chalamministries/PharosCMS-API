<?php
/**
 * ClientModel - Manages client accounts
 * 
 * TABLE: clients
 * ==============
 * Primary Key: client_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * client_id       int(11)         Primary key, auto-increment
 * first_name      varchar(100)    First name
 * last_name       varchar(100)    Last name
 * email           varchar(255)    Email address (unique)
 * phone_number    varchar(20)     Phone number (nullable)
 * company_name    varchar(255)    Company name (nullable)
 * address         text            Full address (nullable)
 * password_hash   varchar(255)    Hashed password
 * status          enum            Values: 'active', 'inactive', default: 'active'
 * created_at      datetime        Auto-populated timestamp
 * updated_at      datetime        Auto-updated timestamp
 * 
 * RELATIONSHIPS:
 * --------------
 * - Has many: cases (client_id)
 */
 
class ClientModel
{
	public $pdo;
	public $clientID = null;
	public $clientArr = [];
	
	function __construct(?int $clientId = null) {
		$this->pdo = $GLOBALS['pdo'];
		if($clientId !== null) {
			// The class STILL validates - defense in depth
			if(!is_int($clientId)) {
				throw new InvalidArgumentException("Client ID must be an integer");
			}
			
			if($clientId <= 0) {
				throw new OutOfRangeException("Client ID must be positive");
			}
			
			$this->clientID = $clientId;
			$this->getClient();
			
			// Check if client exists
			if(empty($this->clientArr)) {
				throw new OutOfBoundsException("Client {$clientId} not found");
			}
			
			return $this->clientArr;
		}
	}
	
	private function getClient() {
		
		$this->clientArr = $this->pdo->selectFirst("clients", array("client_id" => $this->clientID));
		//return $this->clientArr;
	}
	
	/**
	 * Update a client's fields
	 *
	 * @param int $clientId Client ID to update
	 * @param array $data Associative array of fields to update
	 * @return array Refreshed client row
	 */
	public function updateClient(int $clientId, array $data): array {

		$allowed = [
			'first_name', 'last_name', 'email', 'phone_number', 'cell_phone',
			'address_street', 'address_city', 'address_state', 'address_zip',
			'company_name', 'occupation', 'work_schedule', 'contact_method',
			'subject_relationship', 'living'
		];

		$filtered = array_intersect_key($data, array_flip($allowed));
		if (empty($filtered)) {
			throw new InvalidArgumentException("No valid fields provided for update");
		}

		$this->pdo->update("clients", $filtered, ['client_id' => $clientId]);

		return $this->pdo->selectFirst("clients", ['client_id' => $clientId]);
	}

	/************************/
	/*   Helper Functions   */
	/************************/
	/**
	 * Get a list of clients with filtering, sorting, and pagination
	 * 
	 * @param array $filters Filtering options (search, company, city, state, order_by, order_dir, page, limit, include_stats)
	 * @return array Array containing 'clients' and 'pagination' information
	 */
	public function listClients(array $filters = []): array {
		// Build query
		$query = "SELECT 
			client_id,
			first_name,
			last_name,
			email,
			phone_number,
			cell_phone,
			address_street,
			address_city,
			address_state,
			address_zip,
			company_name,
			occupation,
			work_schedule,
			contact_method,
			subject_relationship,
			living,
			created_at,
			updated_at
		FROM clients";
		
		$params = [];
		$where = [];
		
		// Search by name or email
		if (isset($filters['search']) && !empty($filters['search'])) {
			$search = '%' . $filters['search'] . '%';
			$where[] = "(first_name LIKE :search 
						 OR last_name LIKE :search 
						 OR email LIKE :search 
						 OR company_name LIKE :search)";
			$params[':search'] = $search;
		}
		
		// Filter by company name
		if (isset($filters['company']) && !empty($filters['company'])) {
			$where[] = "company_name LIKE :company";
			$params[':company'] = '%' . $filters['company'] . '%';
		}
		
		// Filter by city
		if (isset($filters['city']) && !empty($filters['city'])) {
			$where[] = "address_city = :city";
			$params[':city'] = $filters['city'];
		}
		
		// Filter by state
		if (isset($filters['state']) && !empty($filters['state'])) {
			$where[] = "address_state = :state";
			$params[':state'] = $filters['state'];
		}
		
		// Add WHERE clause if any conditions exist
		if (!empty($where)) {
			$query .= " WHERE " . implode(" AND ", $where);
		}
		
		// Add ordering
		$orderBy = $filters['order_by'] ?? 'created_at';
		$orderDir = strtoupper($filters['order_dir'] ?? 'DESC');
		if (!in_array($orderDir, ['ASC', 'DESC'])) {
			$orderDir = 'DESC';
		}
		$allowedOrderFields = ['client_id', 'first_name', 'last_name', 'email', 'company_name', 'created_at', 'updated_at'];
		if (!in_array($orderBy, $allowedOrderFields)) {
			$orderBy = 'created_at';
		}
		$query .= " ORDER BY $orderBy $orderDir";
		
		// Add pagination
		$page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
		$limit = isset($filters['limit']) ? min(100, max(1, intval($filters['limit']))) : 20;
		$offset = ($page - 1) * $limit;
		
		$queryWithLimit = $query . " LIMIT :limit OFFSET :offset";
		$paramsWithLimit = $params;
		$paramsWithLimit[':limit'] = (int)$limit;
		$paramsWithLimit[':offset'] = (int)$offset;
		
		// Execute query
		$clients = $this->pdo->query($queryWithLimit, $paramsWithLimit);
		
		// Get total count for pagination
		$countQuery = "SELECT COUNT(*) as total FROM clients";
		if (!empty($where)) {
			$countQuery .= " WHERE " . implode(" AND ", $where);
		}
		$totalResult = $this->pdo->queryFirst($countQuery, $params);
		$total = $totalResult ? intval($totalResult['total']) : 0;
		
		// Get additional stats for each client
		if (isset($filters['include_stats']) && $filters['include_stats'] === 'true') {
			foreach ($clients as &$client) {
				// Get case count
				$caseCount = $this->pdo->queryFirst(
					"SELECT COUNT(*) as count FROM cases WHERE client_id = :client_id",
					[':client_id' => $client['client_id']]
				);
				$client['total_cases'] = intval($caseCount['count']);
				
				// Get active case count
				$activeCases = $this->pdo->queryFirst(
					"SELECT COUNT(*) as count FROM cases 
					 WHERE client_id = :client_id 
					 AND status IN ('new', 'assigned', 'in_progress')",
					[':client_id' => $client['client_id']]
				);
				$client['active_cases'] = intval($activeCases['count']);
				
				// Get total billed
				$totalBilled = $this->pdo->queryFirst(
					"SELECT SUM(total_cost_billed) as total FROM cases WHERE client_id = :client_id",
					[':client_id' => $client['client_id']]
				);
				$client['total_billed'] = floatval($totalBilled['total'] ?? 0);
			}
		}
		
		return [
			'clients' => $clients ?: [],
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'total_pages' => ceil($total / $limit)
			]
		];
	}

	public function getClientCases() {
		
	}
}