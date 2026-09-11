<?php
/**
 * RESTful API Router
 * Handles dynamic routing with path parameters like /cases/{id}
 */

// FORCE read the actual request method from Apache environment
if (isset($_SERVER['REDIRECT_REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REDIRECT_REQUEST_METHOD'];
}

// Check all possible method preservation variables
$possibleMethods = [
    'REDIRECT_REQUEST_METHOD',
    'HTTP_X_HTTP_METHOD_OVERRIDE',
    'HTTP_X_HTTP_METHOD',
    'HTTP_X_METHOD_OVERRIDE',
    'REQUEST_METHOD'
];

foreach ($possibleMethods as $methodVar) {
    if (isset($_SERVER[$methodVar]) && in_array(strtoupper($_SERVER[$methodVar]), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'])) {
        $_SERVER['REQUEST_METHOD'] = strtoupper($_SERVER[$methodVar]);
        break;
    }
}

require_once 'config.php';
require_once 'Response.php';

// Parse the request URI
$request_uri = $_SERVER['REQUEST_URI'];

// Remove query string
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove /api/ prefix if present (handles both /api/clients and /api/api/clients)
$path = preg_replace('#^/api/#', '', $path);

// Remove any remaining /api/ that might exist
$path = preg_replace('#^api/#', '', $path);

// Remove leading/trailing slashes
$path = trim($path, '/');

// Debug logging (remove this later)
//error_log("REQUEST_URI: " . $request_uri);
//error_log("Parsed path: " . $path);

// Get HTTP method
$method = $_SERVER['REQUEST_METHOD'];

// Route matching function
function matchRoute($pattern, $path) {
    // Convert {param} to regex capture groups
    $pattern = '#^' . preg_replace('/\{[^\}]+\}/', '([^/]+)', $pattern) . '$#';
    return preg_match($pattern, $path, $matches) ? array_slice($matches, 1) : false;
}

// Extract route parameters
function extractParams($pattern, $path) {
    // Extract parameter names from pattern
    preg_match_all('/\{([^\}]+)\}/', $pattern, $paramNames);
    
    // Get values from path
    $values = matchRoute($pattern, $path);
    
    if ($values === false) return false;
    
    // Map parameter names to values
    $params = [];
    foreach ($paramNames[1] as $index => $name) {
        $params[$name] = $values[$index] ?? null;
    }
    return $params;
}

function searchArray($array, $key, $value) {
    $results = array();

    // if it is array
    if (is_array($array)) {

        // if array has required key and value
        // matched store result
        if (isset($array[$key]) && $array[$key] == $value) {
            $results[] = $array;
        }

        // Iterate for each element in array
        foreach ($array as $subarray) {

            // recur through each element and append result
            $results = array_merge(
                $results,
               searchArray($subarray, $key, $value)
            );
        }
    }

    return $results;
}

// Route definitions - maps "METHOD|pattern" to file
$routes = [
    // Authentication
    'POST|auth/login' => 'auth/login.php',
    'POST|auth/logout' => 'auth/logout.php',
    'GET|auth/check' => 'auth/check.php',
    'GET|auth/verify' => 'auth/verify-token.php',
    'POST|auth/change-password' => 'auth/change-password.php',
    'POST|auth/forgot-password' => 'auth/forgot-password.php', //need
    'POST|auth/reset-password' => 'auth/reset-password.php', //need
    
    // Debug
    'GET|debug/test' => 'debug/debug.php',
    'POST|debug/test' => 'debug/debug.php',
    
    // Admin Management
    'GET|admins' => 'admins/list.php',
    'POST|admins' => 'admins/create.php',
    'GET|admins/{id}' => 'admins/get.php',
    'PUT|admins/{id}' => 'admins/update.php',
    'DELETE|admins/{id}' => 'admins/delete.php',
    
    // Client Management
    'GET|clients' => 'clients/list.php',
    'POST|clients' => 'clients/create.php',
    'GET|clients/{id}' => 'clients/get.php',
    'PUT|clients/{id}' => 'clients/update.php',
    'DELETE|clients/{id}' => 'clients/delete.php',
    'PUT|clients/{id}/password' => 'clients/change-password.php',
    
    // Investigator Management
    'GET|investigators' => 'investigators/list.php',
    'POST|investigators' => 'investigators/create.php',
    'GET|investigators/{id}' => 'investigators/get.php',
    'PUT|investigators/{id}' => 'investigators/update.php',
    'DELETE|investigators/{id}' => 'investigators/delete.php',
    'PUT|investigators/{id}/password' => 'investigators/change-password.php',
    'GET|investigators/{id}/cases' => 'investigators/cases.php',
    
    // Case Management
    'GET|cases' => 'cases/list.php',
    'POST|cases' => 'cases/create.php',
    'POST|cases/draft' => 'cases/create-draft.php',
    'GET|cases/client-case/{case_id}' => 'cases/client-get.php',
    'GET|cases/{id}' => 'cases/get.php',
    'GET|cases/{id}/complete' => 'cases/get_complete.php',
    
    //Activity Management
    'GET|cases/{case_id}/activities' => 'activities/list.php',
    'POST|cases/{case_id}/activities' => 'activities/create.php',
    'GET|activities/{activity_id}' => 'activities/get.php',
    'PUT|activities/{activity_id}' => 'activities/update.php',
    'DELETE|activities/{activity_id}' => 'activities/delete.php',
    
    'PUT|cases/{id}' => 'cases/update.php',
    'DELETE|cases/{id}' => 'cases/delete.php',
    'POST|cases/{id}/assign' => 'cases/reassign-investigator.php',
    'POST|cases/{case_id}/assignments' => 'cases/add-assignment.php',
    'POST|cases/{case_id}/objectives' => 'objectives/create.php',
    'POST|cases/{case_id}/objectives/' => 'cases/add-objectives.php',
    'DELETE|cases/{case_id}/assignments/{assignment_id}' => 'cases/unassign-investigator.php',
    'GET|cases/{id}/investigators' => 'cases/get-investigators.php',
    
    // Report Management
    'GET|cases/{case_id}/reports' => 'reports/list.php',
    'POST|cases/{case_id}/reports' => 'reports/create.php',
    'GET|reports/{report_id}' => 'reports/get.php',
    'PUT|reports/{report_id}' => 'reports/update.php',
    'DELETE|reports/{report_id}' => 'reports/delete.php',
    'PUT|reports/{report_id}/status' => 'reports/update-status.php',
    
    // Media Management
    'GET|cases/{case_id}/media' => 'media/list.php',
    'POST|cases/{case_id}/media' => 'media/upload_bunny.php',
    'PUT|media/bulk-update' => 'media/bulk-update.php',
    'DELETE|media/bulk-delete' => 'media/bulk-delete.php',
    'GET|media/{media_id}' => 'media/get.php',
    'PUT|media/{media_id}' => 'media/update.php',
    'DELETE|media/{media_id}' => 'media/delete.php',
    'GET|media/client/{case_id}' => 'media/client-list.php',
    'GET|media/unlinked/{case_id}' => 'media/get_unlinked.php',
    'GET|cases/{case_id}/media-zip-request' => 'media/get_zip_request.php',
    'POST|cases/{case_id}/initiate-media-zip' => 'media/initiate_zip_request.php',
    'POST|media/link-to-entry' => 'media/link_to_entry.php',
    'POST|media/unlink' => 'media/link_to_entry.php',
    
    // Time Entry Management
    // 'GET|cases/{case_id}/time-entries' => 'time-entries/list-by-case.php',
    // 'POST|cases/{case_id}/time-entries' => 'create-time-entry.php',
    // 'GET|time-entries' => 'get-time-entries.php',
    // 'GET|time-entries/{entry_id}' => 'time-entries/get.php',
    // 'PUT|time-entries/{entry_id}' => 'time-entries/update.php',
    // 'DELETE|time-entries/{entry_id}' => 'time-entries/delete.php',
    // 'PUT|time-entries/{entry_id}/status' => 'time-entries/update-status.php',
    
    // Billing Item Management
    'GET|cases/{case_id}/billing-items' => 'billing/list.php',
    'POST|cases/{case_id}/billing-items' => 'billing/create.php',
    'GET|billing-items/{item_id}' => 'billing/get.php',
    'PUT|billing-items/{item_id}' => 'billing/update.php',
    'DELETE|billing-items/{item_id}' => 'billing/delete.php',
    'PUT|billing-items/{item_id}/status' => 'billing/update-status.php',
    
    // AI Integration
    'POST|ai/generate-synopsis' => 'ai/generate-synopsis.php',
    'POST|ai/generate-client-report-summary' => 'ai/generate-summary.php',
    'POST|ai/generate-objectives' => 'ai/generate-objectives.php',
    'POST|ai/generate-synopsis' => 'ai/generate-synopsis.php',
    'POST|ai/generate-activity-summary' => 'ai/generate-activity-summary.php',
    
    //Settings
    //Settings - Case Types
    'GET|settings/case_types' => 'settings/get_types.php',
    'POST|settings/case_types' => 'settings/create_type.php', //TODO:: Create
    'PUT|settings/case_types/{id}' => 'settings/update_type.php', //TODO:: Create
    'DELETE|settings/case_types/{id}' => 'settings/delete_type.php', //TODO:: Create

    //Settings - Activity Types
    'GET|settings/activity_types' => 'settings/get_activity_types.php',
    'POST|settings/activity_types' => 'settings/create_activity_type.php', //TODO:: Create
    'PUT|settings/activity_types/{id}' => 'settings/update_activity_type.php', //TODO:: Create
    'DELETE|settings/activity_types/{id}' => 'settings/delete_activity_type.php', //TODO:: Create

    //Settings - Sim Carriers
    'GET|settings/sim_carriers' => 'settings/get_sim_carriers.php',
    'POST|settings/sim_carriers' => 'settings/create_sim_carrier.php', //TODO:: Create
    'PUT|settings/sim_carriers/{id}' => 'settings/update_sim_carrier.php', //TODO:: Create
    'DELETE|settings/sim_carriers/{id}' => 'settings/delete_sim_carrier.php', //TODO:: Create

    'GET|settings/communications' => 'settings/get_communications.php',
    'PUT|settings/communications' => 'settings/update_communications.php',

    // Email Sending
    'POST|emails/send/{type_key}' => 'emails/send/send_by_type.php',

    'GET|dashboard/stats' => 'dashboard/stats.php',

    // Objectives Management
    'GET|objectives/{id}' => 'objectives/get.php',
    'PUT|objectives/{id}' => 'objectives/update.php',
    'DELETE|objectives/{id}' => 'objectives/delete.php',
    'POST|cases/{id}/objectives/batch' => 'objectives/batch.php',

    // Participants Management
    'GET|cases/{case_id}/participants' => 'participants/list.php',
    'GET|cases/{id}/audit-logs' => 'cases/audit-logs.php',
    'POST|cases/{case_id}/participants' => 'participants/create.php',
    'GET|participants/{id}' => 'participants/get.php',
    'PUT|participants/{id}' => 'participants/update.php',
    'DELETE|participants/{id}' => 'participants/delete.php',

    // Message Management
    'POST|cases/{case_id}/messages' => 'messages/add-message.php',
    'PUT|messages/{message_id}/read' => 'messages/mark-as-read.php',

    // Case Type Management
    'GET|casetypes' => 'casetypes/list.php',
    'POST|casetypes' => 'casetypes/create.php',
    'GET|casetypes/{id}' => 'casetypes/get.php',
    'PUT|casetypes/{id}' => 'casetypes/update.php',
    'DELETE|casetypes/{id}' => 'casetypes/delete.php',

    //Tracker Management
    'GET|trackers' => 'trackers/list.php',
    'POST|trackers' => 'trackers/create.php',
    'GET|trackers/case/{case_id}' => 'trackers/by-case.php',
    'GET|trackers/models' => 'trackers/brand_model.php',
    'POST|trackers/models' => 'settings/create_tracker_model.php',
    'PUT|trackers/models/{id}' => 'settings/update_tracker_model.php',
    'DELETE|trackers/brands/{id}' => 'trackers/delete_tracker_brand.php',
    'POST|trackers/brand' => 'settings/create_tracker_brand.php',
    'PUT|trackers/brand/{id}' => 'settings/update_tracker_brand.php',
    'DELETE|trackers/models/{id}' => 'settings/delete_tracker_model.php',
    'GET|trackers/{id}/last-position' => 'trackers/last-position.php',
    'GET|trackers/{id}/track-history' => 'trackers/track-history.php',
    'POST|trackers/{id}/assign' => 'trackers/assign.php',
    'POST|trackers/{id}/unassign' => 'trackers/unassign.php',
    'GET|trackers/{id}' => 'trackers/get.php',
    'PUT|trackers/{id}' => 'trackers/update.php',
    'DELETE|trackers/{id}' => 'trackers/delete.php',
    'POST|trackers/command/{token}' => 'trackers/command.php',

    'POST|incoming' => 'incoming.php',

    // Address Lookup (geocoding proxy)
    'GET|address/lookup' => 'address/lookup.php',
];

// Find matching route
$matched = false;
$routeFile = null;
$routeParams = [];

foreach ($routes as $routePattern => $file) {
    list($routeMethod, $routePath) = explode('|', $routePattern, 2);

    // Check if method matches
    if ($routeMethod !== $method) {
        continue;
    }
    
    // Check if path matches
    $params = extractParams($routePath, $path);
    if ($params !== false) {
        $matched = true;
        $routeFile = $file;
        $routeParams = $params;
        break;
    }
}

// If route found, set params and include the file
if ($matched && $routeFile) {
    // Add route parameters to $_GET so endpoints can access them easily
    foreach ($routeParams as $key => $value) {
        $_GET[$key] = $value;
    }
    
    $fullPath = __DIR__ . '/' . $routeFile;

    if (file_exists($fullPath)) {
        require_once $fullPath;
    } else {
        Response::notFound("Endpoint file not found: $routeFile");
    }
} else {
    Response::notFound("No route found for: $method /$path");
}