<?php
/**
 * Dashboard Statistics Endpoint
 * GET /api/dashboard/stats
 *
 * Returns dashboard statistics and metrics
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

    // Initialize stats array
    $stats = [
        'total_cases' => 0,
        'open_cases' => 0,
        'available_hours' => 0,
        'pending_approvals' => 0
    ];

    // Get total cases count
    $totalCasesQuery = "SELECT COUNT(*) as count FROM cases";
    $totalCasesResult = $db->queryFirst($totalCasesQuery);
    $stats['total_cases'] = intval($totalCasesResult['count'] ?? 0);

    // Get open cases count (cases that are not closed)
    $openCasesQuery = "SELECT COUNT(*) as count FROM cases WHERE status != 'closed'";
    $openCasesResult = $db->queryFirst($openCasesQuery);
    $stats['open_cases'] = intval($openCasesResult['count'] ?? 0);

    // Get available hours (total allotted minus used for active cases)
    $availableHoursQuery = "SELECT
        SUM(initial_hours_allotted - COALESCE(hours_used, 0)) as available
        FROM cases
        WHERE status != 'closed'";
    $availableHoursResult = $db->queryFirst($availableHoursQuery);
    $stats['available_hours'] = floatval($availableHoursResult['available'] ?? 0);

    // Get pending approvals count (time entries with pending status)
    $pendingApprovalsQuery = "SELECT COUNT(*) as count FROM time_entries WHERE status = 'pending'";
    $pendingApprovalsResult = $db->queryFirst($pendingApprovalsQuery);
    $stats['pending_approvals'] = intval($pendingApprovalsResult['count'] ?? 0);

    // Get recent active cases (limit to 10)
    $recentCasesQuery = "SELECT
      case_id,
      case_number,
      case_title,
      case_type,
      status,
      initial_hours_allotted,
      hours_used,
      updated_at,
      client_name,
      investigators
  FROM case_summary
  WHERE status IN ('new', 'assigned', 'in_progress')
  ORDER BY updated_at DESC
  LIMIT 10";

    $recentCases = $db->query($recentCasesQuery);

    // Get pending time entries for approval
    $pendingEntriesQuery = "SELECT
        te.time_entry_id,
        te.case_id,
        te.hours_spent,
        te.submitted_at,
        te.description,
        c.case_number,
        CONCAT(i.first_name, ' ', i.last_name) as investigator_name,
        ca.assigned_hourly_rate,
        (te.hours_spent * ca.assigned_hourly_rate) as entry_cost
    FROM time_entries te
    JOIN cases c ON te.case_id = c.case_id
    JOIN investigators i ON te.investigator_id = i.investigator_id
    LEFT JOIN case_assignments ca ON te.case_id = ca.case_id
        AND te.investigator_id = ca.investigator_id
        AND ca.unassigned_at IS NULL
    WHERE te.status = 'pending'
    ORDER BY te.submitted_at ASC
    LIMIT 10";

    $pendingEntries = $db->query($pendingEntriesQuery);

    Response::success([
        'metrics' => $stats,
        'recent_cases' => $recentCases ?? [],
        'pending_entries' => $pendingEntries ?? []
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
