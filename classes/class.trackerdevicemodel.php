<?php
/**
 * TrackerDeviceModel - Manages GPS tracker devices
 * 
 * TABLE: trackers
 * ===============
 * Primary Key: tracker_id (int, AUTO_INCREMENT)
 * 
 * FIELDS:
 * -------
 * tracker_id   int             Primary key, auto-increment
 * imei         varchar(20)     Unique IMEI number
 * sim_card_id  varchar(20)     SIM card ID (nullable)
 * carrier_id   int             Foreign key to sim_carrier table
 * model_id     int             Foreign key to tracker_model table
 * name         varchar(255)    User-friendly name
 * status       enum            'available', 'assigned', 'lost', 'broken'
 * phone_number varchar(20)     SIM card phone number
 * last_checkin datetime        When the device last connected
 * battery_life int             Current battery level percentage
 * created_at   datetime        Auto-populated timestamp
 * updated_at   datetime        Auto-updated timestamp
 */

class TrackerDeviceModel
{
    public $pdo;
    public $trackerID = null;
    public $trackerArr = [];

    public function __construct(?int $trackerId = null) {
        $this->pdo = $GLOBALS['pdo'];
        if ($trackerId !== null) {
            $this->trackerID = $trackerId;
            $this->getTracker();
        }
    }

    private function getTracker() {
        $tracker = $this->pdo->selectFirst("trackers", ["tracker_id" => $this->trackerID]);
        if ($tracker) {
            $this->trackerArr = typeSet($tracker, "trackers");
        }
    }

    public function getAllTrackers() {
        $sql = "SELECT t.*, tb.brand_name, tm.model_name, sc.carrier_name
                FROM trackers t
                LEFT JOIN tracker_model tm ON t.device_model = tm.model_id
                LEFT JOIN tracker_brand tb ON tm.brand_id = tb.brand_id
                LEFT JOIN sim_carrier sc ON t.sim_carrier = sc.carrier_id
                ORDER BY t.name ASC";
        $trackers = $this->pdo->query($sql);
        if (!$trackers) {
            return [];
        }
        foreach ($trackers as &$tracker) {
            $tracker = typeSet($tracker, "trackers");
        }
        return $trackers;
    }

    public function getAvailableTrackers() {
        $sql = "SELECT t.*, tb.brand_name, tm.model_name
                FROM trackers t
                JOIN tracker_model tm ON t.device_model = tm.model_id
                JOIN tracker_brand tb ON tm.brand_id = tb.brand_id
                WHERE t.status = 'available'
                ORDER BY t.name ASC";
        $trackers = $this->pdo->query($sql);
        if (!$trackers) {
            return [];
        }
        foreach ($trackers as &$tracker) {
            $tracker = typeSet($tracker, "trackers");
        }
        return $trackers;
    }

    /**
     * Get a list of trackers with filtering, sorting, and pagination
     * 
     * @param array $filters Filtering options (search, page, limit)
     * @return array Array containing 'trackers', 'total', 'page', and 'limit'
     */
    public function listTrackers(array $filters = []): array {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $page = isset($filters['page']) ? (int)$filters['page'] : 1;
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 25;
        $offset = ($page - 1) * $limit;

        $whereClauses = [];
        $params = [];

        if (!empty($search)) {
            $whereClauses[] = "(t.name LIKE :search OR t.unique_id LIKE :search OR t.phone_number LIKE :search OR c.case_number LIKE :search OR cl.first_name LIKE :search OR cl.last_name LIKE :search OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($status)) {
            $whereClauses[] = "t.status = :status";
            $params[':status'] = $status;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

        // Get total count for pagination
        $countQuery = "
            SELECT COUNT(DISTINCT t.tracker_id) as total
            FROM trackers t
            LEFT JOIN tracker_assignments ta ON t.tracker_id = ta.tracker_id
                AND ta.assigned_at IS NOT NULL
                AND ta.removed_at IS NULL
            LEFT JOIN cases c ON ta.case_id = c.case_id
            LEFT JOIN clients cl ON c.client_id = cl.client_id
            $whereSql
        ";
        $totalResult = $this->pdo->queryFirst($countQuery, $params);
        $totalCount = $totalResult ? $totalResult['total'] : 0;

        $query = "
            SELECT t.*,
                   tm.model_name,
                   tb.brand_name,
                   sc.carrier_name AS carrier_name,
                   ta.case_id AS assigned_case_id,
                   c.case_number AS assigned_case_number,
                   CONCAT(cl.first_name, ' ', cl.last_name) AS assigned_client_name,
                   td.latitude AS last_lat,
                   td.longitude AS last_lng,
                   td.recorded_at AS last_position_at,
                   td.speed AS last_speed,
                   td.battery_level AS last_battery
            FROM trackers t
            LEFT JOIN tracker_model tm ON t.device_model = tm.model_id
            LEFT JOIN tracker_brand tb ON tm.brand_id = tb.brand_id
            LEFT JOIN sim_carrier sc ON t.sim_carrier = sc.carrier_id
            LEFT JOIN tracker_assignments ta ON t.tracker_id = ta.tracker_id
                AND ta.assigned_at IS NOT NULL
                AND ta.removed_at IS NULL
            LEFT JOIN cases c ON ta.case_id = c.case_id
            LEFT JOIN clients cl ON c.client_id = cl.client_id
            LEFT JOIN (
                SELECT td1.*
                FROM tracker_data td1
                JOIN (
                    SELECT tracker_id, MAX(id) as max_id
                    FROM tracker_data
                    GROUP BY tracker_id
                ) td2 ON td1.id = td2.max_id
            ) td ON t.traccar_id = td.tracker_id
            $whereSql
            ORDER BY t.created_at DESC
            LIMIT $limit OFFSET $offset
        ";

        $trackers = $this->pdo->query($query, $params);

        if ($trackers) {
            foreach ($trackers as &$tracker) {
                $tracker = typeSet($tracker, "trackers");
            }
        }

        return [
            'trackers' => $trackers ?: [],
            'total' => (int)$totalCount,
            'page' => $page,
            'limit' => $limit
        ];
    }
    public function createTracker(array $input) {
        // Check for duplicate IMEI
        $existing = $this->pdo->selectFirst("trackers", ["imei" => $input['imei']]);
        if ($existing) {
            throw new Exception('A tracker with this IMEI already exists', 409);
        }

        // Step 1: Create device on Traccar server
        $traccarDevice = [
            'name'     => $input['name'],
            'uniqueId' => $input['unique_id'],
            'phone'    => $input['unique_id'],
            'model'    => $input['imei'],
            'category' => 'default'
        ];

        $ch = curl_init('https://tracker.pharoscms.com/api/devices');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_USERPWD        => $_ENV['TRACKER_USERNAME'] . ':' . $_ENV['TRACKER_PASSWORD'],
            CURLOPT_POSTFIELDS     => json_encode($traccarDevice),
            CURLOPT_TIMEOUT        => 15
        ]);

        $traccarResponse = curl_exec($ch);
        $traccarHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $traccarError = curl_error($ch);
        curl_close($ch);

        if ($traccarError || $traccarHttpCode < 200 || $traccarHttpCode >= 300) {
            $errorDetail = $traccarError ?: $traccarResponse;
            throw new Exception('Failed to create device on tracker server: ' . $errorDetail, 502);
        }

        $traccarData = json_decode($traccarResponse, true);
        $traccarId = $traccarData['id'] ?? 0;

        // Determine status: use provided status or default to available
        $status = $input['status'] ?? 'available';

        // Step 2: Insert into local database with Traccar ID
        $data = [
            'traccar_id'          => intval($traccarId),
            'name'                => $input['name'],
            'imei'                => $input['imei'],
            'unique_id'           => $input['unique_id'],
            'device_id'           => $input['device_id'],
            'device_model'        => intval($input['device_model']),
            'sim_carrier'         => !empty($input['sim_carrier']) ? intval($input['sim_carrier']) : null,
            'sim_number'          => $input['sim_number'] ?? null,
            'sms_phone_number'    => $input['sms_phone_number'] ?? null,
            'service_start_date'  => $input['service_start_date'] ?? null,
            'service_expire_date' => $input['service_expire_date'] ?? null,
            'status'              => $status,
            'rebounce_enabled'    => $input['rebounce_enabled'] ?? 0,
            'rebounce_host'       => $input['rebounce_host'] ?? null,
            'rebounce_port'       => $input['rebounce_port'] ?? null
        ];

        $trackerId = $this->pdo->insert("trackers", $data);

        if (!$trackerId) {
            throw new Exception('Failed to create tracker in local database', 500);
        }

        // Return updated tracker
        $tracker = $this->pdo->selectFirst("trackers", ["tracker_id" => $trackerId]);
        return typeSet($tracker, "trackers");
    }

    public function updateTracker(int $trackerId, array $input) {
        // Get existing tracker
        $existing = $this->pdo->selectFirst("trackers", ["tracker_id" => $trackerId]);
        if (!$existing) {
            throw new Exception('Tracker not found', 404);
        }

        // Check for duplicate IMEI (exclude current tracker)
        $duplicate = $this->pdo->queryFirst("SELECT tracker_id FROM trackers WHERE imei = :imei AND tracker_id != :tracker_id", [
            ':imei' => $input['imei'],
            ':tracker_id' => $trackerId
        ]);

        if ($duplicate) {
            throw new Exception('A tracker with this IMEI already exists', 409);
        }

        // Step 1: Update device on Traccar server
        $traccarId = $existing['traccar_id'];
        if ($traccarId) {
            $traccarDevice = [
                'id'       => intval($traccarId),
                'name'     => $input['name'],
                'uniqueId' => $input['unique_id'],
                'phone'    => $input['unique_id'],
                'model'    => $input['imei'],
                'category' => 'default'
            ];

            $ch = curl_init('https://tracker.pharoscms.com/api/devices/' . $traccarId);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_USERPWD        => $_ENV['TRACKER_USERNAME'] . ':' . $_ENV['TRACKER_PASSWORD'],
                CURLOPT_POSTFIELDS     => json_encode($traccarDevice),
                CURLOPT_TIMEOUT        => 15
            ]);

            $traccarResponse = curl_exec($ch);
            $traccarHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $traccarError = curl_error($ch);
            curl_close($ch);

            if ($traccarError || $traccarHttpCode < 200 || $traccarHttpCode >= 300) {
                $errorDetail = $traccarError ?: $traccarResponse;
                throw new Exception('Failed to update device on tracker server: ' . $errorDetail, 502);
            }
        }

        // Determine status: use provided status or default to available
        $status = $input['status'] ?? 'available';

        // Step 2: Update local database
        $data = [
            'name'                => $input['name'],
            'imei'                => $input['imei'],
            'unique_id'           => $input['unique_id'],
            'device_id'           => $input['device_id'],
            'device_model'        => intval($input['device_model']),
            'sim_carrier'         => !empty($input['sim_carrier']) ? intval($input['sim_carrier']) : null,
            'sim_number'          => $input['sim_number'] ?? null,
            'sms_phone_number'    => $input['sms_phone_number'] ?? null,
            'service_start_date'  => $input['service_start_date'] ?? null,
            'service_expire_date' => $input['service_expire_date'] ?? null,
            'status'              => $status,
            'rebounce_enabled'    => $input['rebounce_enabled'] ?? 0,
            'rebounce_host'       => $input['rebounce_host'] ?? null,
            'rebounce_port'       => $input['rebounce_port'] ?? null
        ];

        $this->pdo->update("trackers", $data, ["tracker_id" => $trackerId]);

        // Return updated tracker
        $updated = $this->pdo->selectFirst("trackers", ["tracker_id" => $trackerId]);
        return typeSet($updated, "trackers");
    }
}
