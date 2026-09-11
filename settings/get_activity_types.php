<?php
/**
 * Get Single Case Endpoint
 * GET /api/cases/{case_id}
 * 
 * Returns detailed information about a specific case
 * Requires authentication
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';


$activityTypeModel = initializeClass("ActivityTypeModel");
$activity_types = $activityTypeModel->getAllActivityTypes();

Response::success($activity_types);