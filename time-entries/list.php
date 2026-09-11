<?php
/**
 * Get Time Entries Endpoint
 * GET /api/time-entries
 * 
 * Returns list of time entries with filtering
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

// Authenticate user
$user = Auth::authenticate();
if (!$user) {
    exit();
}

try {
    $db = getDB();
    
    // Build query based on user type
    $query = "SELECT 
        te.time_entry_id,
        te.case_id,
        te.investigator_id,
        te.entry_date,
        te.hours_spent,
        te.description,
        te.location,
        te.status,
        te.admin_notes,
        te.submitted_at,
        c.case_number,
        c.case_title,
        CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
        ca.assigned_hourly_rate,
        (te.hours_spent * ca.assigned_hourly_rate) as entry_cost
    FROM time_entries te
    JOIN cases c ON te.case_id = c.case_id
    JOIN investigators i ON te.investigator_id = i.investigator_id
    LEFT JOIN case_assignments ca ON te.case_id = ca.case_id 
        AND te.investigator_id = ca.investigator_id
        AND ca.unassigned_at IS NULL";
    
    $params = [];
    $where = [];
    
    // Filter based on user type
    if ($user['user_type'] === 'client') {
        // Clients can only see time entries for their cases
        $where[] = "c.client_id = :client_id";
        $params[':client_id'] = $user['user_id'];
        // Clients can only see approved entries
        $where[] = "te.status = 'approved'";
    } elseif ($user['user_type'] === 'investigator') {
        // Investigators can see their own time entries
        $where[] = "te.investigator_id = :investigator_id";
        $params[':investigator_id'] = $user['user_id'];
    }
    // Admins can see all time entries (no additional filters)
    
    // Optional filters from query parameters
    if (isset($_GET['case_id'])) {
        $where[] = "te.case_id = :case_id";
        $params[':case_id'] = intval($_GET['case_id']);
    }
    
    if (isset($_GET['investigator_id']) && Auth::hasRole($user, ['admin', 'super_admin', 'case_manager'])) {
        $where[] = "te.investigator_id = :filter_investigator_id";
        $params[':filter_investigator_id'] = intval($_GET['investigator_id']);
    }
    
    if (isset($_GET['status'])) {
        $allowedStatuses = ['pending', 'approved', 'rejected'];
        if (in_array($_GET['status'], $allowedStatuses)) {
            $where[] = "te.status = :status";
            $params[':status'] = $_GET['status'];
        }
    }
    
    // Date range filters
    if (isset($_GET['start_date'])) {
        $where[] = "te.entry_date >= :start_date";
        $params[':start_date'] = $_GET['start_date'];
    }
    
    if (isset($_GET['end_date'])) {
        $where[] = "te.entry_date <= :end_date";
        $params[':end_date'] = $_GET['end_date'];
    }
    
    // Add WHERE clause if any conditions exist
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }
    
    // Add ordering
    $query .= " ORDER BY te.entry_date DESC, te.submitted_at DESC";
    
    // Add pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    
    $query .= " LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    // Execute query
    $entries = $db->query($query, $params);
    
    if ($entries === false) {
        Response::serverError('Failed to retrieve time entries');
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total 
                   FROM time_entries te
                   JOIN cases c ON te.case_id = c.case_id
                   JOIN investigators i ON te.investigator_id = i.investigator_id";
    
    $countWhere = [];
    $countParams = [];
    
    if ($user['user_type'] === 'client') {
        $countWhere[] = "c.client_id = :client_id";
        $countParams[':client_id'] = $user['user_id'];
        $countWhere[] = "te.status = 'approved'";
    } elseif ($user['user_type'] === 'investigator') {
        $countWhere[] = "te.investigator_id = :investigator_id";
        $countParams[':investigator_id'] = $user['user_id'];
    }
    
    if (isset($_GET['case_id'])) {
        $countWhere[] = "te.case_id = :case_id";
        $countParams[':case_id'] = intval($_GET['case_id']);
    }
    
    if (isset($_GET['status'])) {
        $allowedStatuses = ['pending', 'approved', 'rejected'];
        if (in_array($_GET['status'], $allowedStatuses)) {
            $countWhere[] = "te.status = :status";
            $countParams[':status'] = $_GET['status'];
        }
    }
    
    if (isset($_GET['start_date'])) {
        $countWhere[] = "te.entry_date >= :start_date";
        $countParams[':start_date'] = $_GET['start_date'];
    }
    
    if (isset($_GET['end_date'])) {
        $countWhere[] = "te.entry_date <= :end_date";
        $countParams[':end_date'] = $_GET['end_date'];
    }
    
    if (!empty($countWhere)) {
        $countQuery .= " WHERE " . implode(" AND ", $countWhere);
    }
    
    $totalResult = $db->queryFirst($countQuery, $countParams);
    $total = $totalResult ? intval($totalResult['total']) : 0;
    
    // Calculate summary statistics
    $summaryQuery = "SELECT 
        COUNT(*) as total_entries,
        SUM(te.hours_spent) as total_hours,
        SUM(te.hours_spent * ca.assigned_hourly_rate) as total_cost,
        SUM(CASE WHEN te.status = 'pending' THEN te.hours_spent ELSE 0 END) as pending_hours,
        SUM(CASE WHEN te.status = 'approved' THEN te.hours_spent ELSE 0 END) as approved_hours
    FROM time_entries te
    JOIN cases c ON te.case_id = c.case_id
    LEFT JOIN case_assignments ca ON te.case_id = ca.case_id 
        AND te.investigator_id = ca.investigator_id
        AND ca.unassigned_at IS NULL";
    
    if (!empty($countWhere)) {
        $summaryQuery .= " WHERE " . implode(" AND ", $countWhere);
    }
    
    $summary = $db->queryFirst($summaryQuery, $countParams);
    
    // Return success response with pagination info
    Response::success([
        'time_entries' => $entries,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ],
        'summary' => [
            'total_entries' => intval($summary['total_entries'] ?? 0),
            'total_hours' => floatval($summary['total_hours'] ?? 0),
            'total_cost' => floatval($summary['total_cost'] ?? 0),
            'pending_hours' => floatval($summary['pending_hours'] ?? 0),
            'approved_hours' => floatval($summary['approved_hours'] ?? 0)
        ]
    ]);
    
} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
