<?php
/**
 * Get Audit Logs for a Case
 * GET /api/cases/{id}/audit-logs
 *
 * Returns audit logs for a specific case
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

// Get case ID from route
if (!isset($_GET['id'])) {
    Response::error('Case ID is required', 400);
}

$caseId = intval($_GET['id']);

if ($caseId <= 0) {
    Response::error('Invalid case ID', 400);
}

try {
    $pdo = $GLOBALS['pdo'];

    // Join with audit_action_types to get the template
    $sql = "
        SELECT 
            al.id,
            al.user_type,
            al.user_id,
            al.action_type_id,
            al.variables,
            al.created_at,
            aat.template,
            aat.code as action_code,
            CASE 
                WHEN al.user_type = 'A' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM admins WHERE admin_id = al.user_id)
                WHEN al.user_type = 'I' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM investigators WHERE investigator_id = al.user_id)
                WHEN al.user_type = 'C' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM clients WHERE client_id = al.user_id)
                ELSE 'Unknown'
            END as user_full_name
        FROM audit_logs al
        JOIN audit_action_types aat ON al.action_type_id = aat.id
        WHERE al.case_id = :case_id
        ORDER BY al.created_at DESC
    ";

    $logs = $pdo->query($sql, [':case_id' => $caseId]);

    // Process logs to parse variables and apply template
    foreach ($logs as &$log) {
        $variables = json_decode($log['variables'], true) ?: [];
        $template = $log['template'];
        
        // Simple template replacement
        $actionText = $template;
        foreach ($variables as $key => $value) {
            $val = is_array($value) ? json_encode($value) : $value;
            $actionText = str_replace('{' . $key . '}', '<strong>' . htmlspecialchars($val) . '</strong>', $actionText);
        }

        // If 'actor' wasn't in variables, we can still show who did it if the template uses it
        if (!isset($variables['actor']) && strpos($actionText, '{actor}') !== false) {
             $actionText = str_replace('{actor}', '<strong>' . htmlspecialchars($log['user_full_name'] ?? 'Unknown') . '</strong>', $actionText);
        }
        
        $log['action_text'] = $actionText;
        
        // Clean up internal fields if desired
        // unset($log['variables']);
        // unset($log['template']);
    }

    Response::success([
        'logs' => $logs,
        'total_count' => count($logs)
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}
