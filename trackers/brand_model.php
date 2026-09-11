<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

try {
    $db = getDB();

    $query = "
    SELECT b.brand_id, b.brand_name, m.model_id, m.model_name, m.battery_field
    FROM tracker_brand b
    LEFT JOIN tracker_model m ON b.brand_id = m.brand_id
    ORDER BY b.brand_name ASC, m.model_name ASC";

    $brands = [];
    $currentBrandId = null;
    $brandIndex = -1;

    foreach ($db->query($query) as $row) {
        if ($row['brand_id'] !== $currentBrandId) {
            $currentBrandId = $row['brand_id'];
            $brands[] = [
                'brand_id' => $row['brand_id'],
                'brand_name' => $row['brand_name'],
                'models' => []
            ];
            $brandIndex++;
        }
        // Append model to current brand's list (already sorted by query)
        $brands[$brandIndex]['models'][] = [
            'model_id' => $row['model_id'],
            'model_name' => $row['model_name'],
            'battery_field' => $row['battery_field']
        ];
    }

    Response::success([
        'brands' => $brands
    ]);

} catch (Exception $e) {
    Response::serverError('An error occurred: ' . $e->getMessage());
}