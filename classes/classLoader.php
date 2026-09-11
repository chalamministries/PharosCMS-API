<?php
if(!function_exists("initializeClass") ){
	function initializeClass($className, ...$args) {
	
		include_once (__DIR__ . '/class.' . strtolower($className) . '.php');
		
		return new $className(...$args);
	}
}

function typeSet($data, $table) {
	
	$db = $GLOBALS['pdo'];
	
	$query = "DESCRIBE " . $table;
	$columns = $db->query($query, [], true);
	
	if (!$columns) {
		return false;
	}
	
	foreach ($columns as $column) {
		$fieldName = $column['Field'];
		
		// Handle NULL values
		if ($data[$fieldName] === null) {
			continue;
		}
		
		$type = strtolower($column['Type']);

		// Boolean type
		if (strpos($type, 'tinyint(1)') !== false) {
			$value = filter_var($data[$fieldName], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			$data[$fieldName] = $value === null ? false : $value;
		}
		// Integer types
		else if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || 
			strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
			$data[$fieldName] = is_numeric($data[$fieldName]) ? floatval($data[$fieldName]) : 0;
		}
		// Date/Time types
		else if (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
			if (!is_numeric($data[$fieldName])) {
				$data[$fieldName] = (string)($data[$fieldName]);
			}
		}
		// String types
		else if (strpos($type, 'varchar') !== false || strpos($type, 'text') !== false || 
				 strpos($type, 'char') !== false) {
			$data[$fieldName] = (string)$data[$fieldName];
		}
	}
	
	return $data;
}

function getTableColumns($tableName) {
	static $cache = [];
	
	// Return cached columns if available
	if (isset($cache[$tableName])) {
		return $cache[$tableName];
	}
	
	global $pdo;  // Access global PDO instance
	
	// Query database for column names
	$sql = "SHOW COLUMNS FROM `{$tableName}`";
	$result = $pdo->query($sql);
	
	$columns = [];
	foreach ($result as $row) {
		$columns[] = $row['Field'];
	}
	
	// Cache for future use
	$cache[$tableName] = $columns;
	
	return $columns;
}

/**
 * Filter array to only include valid table columns
 * 
 * @param array $data The data to filter
 * @param string $tableName The table name
 * @param array $excludeFields Fields to exclude even if they're in the table
 * @return array Filtered data containing only valid table columns
 */
function filterTableFields($data, $tableName, $excludeFields = []) {
	$columns = getTableColumns($tableName);
	$filtered = [];
	
	foreach ($columns as $column) {
		// Skip excluded fields
		if (in_array($column, $excludeFields)) {
			continue;
		}
		
		// Only include if exists in data
		if (array_key_exists($column, $data)) {
			$filtered[$column] = $data[$column];
		}
	}
	
	return $filtered;
}