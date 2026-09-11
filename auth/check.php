<?php
/**
 * Check Endpoint
 * POST /api/auth/check
 * 
 * Checks for client login from hidden app
 */

require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../database.php';
 require_once __DIR__ . '/../JWT.php';
 require_once __DIR__ . '/../Auth.php';
 require_once __DIR__ . '/../Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	 Response::error('Method not allowed. Received: ' . $_SERVER['REQUEST_METHOD'], 400);
	 exit();
 }
 
 if(!isset($_GET['unlock'])) {
	Response::error('Missing Parameter: ', 400);
	exit();
 }

$unlock = explode("+", $_GET['unlock']);

if($unlock[0] != $_ENV['APP_PIN']) {
	Response::error('Invalid PIN ', 401);
	exit();
}

$userPIN = $unlock[1];

try {
	$db = getDB();
	$hashedPIN = password_hash($userPIN, PASSWORD_BCRYPT);
	
	$exists = $db->pdo->selectFirst("clients", array("password_hash" => $hashedPIN));
	if($exists) {
		echo json_encode(array("url" => "https://portal.thepiagency.com/client/login.php?auth_token=" . $exists['auth_token']));
	} else {
		Response::error('Not Authorized', 401);
		exit();
	}
	
} catch (Exception $e) {
	Response::serverError('An error occurred during login: ' . $e->getMessage());
}