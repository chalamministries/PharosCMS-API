<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Response.php';

$user = Auth::authenticate();
if (!$user) exit();

$query = trim($_GET['query'] ?? '');

if ($query === '') {
    Response::error('Missing query', 400);
    exit();
}
if (strlen($query) < 4) {
    Response::error('Query must be at least 4 characters', 411);
    exit();
}

// NOTE: To switch geocoding providers, replace the curl call and parsing block below.
// Add MAPBOX_API_KEY to the .env file (e.g. MAPBOX_API_KEY=pk.eyJ1...).
$apiKey = defined('MAPBOX_PUBLIC_KEY') ? MAPBOX_PUBLIC_KEY : '';
$url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/'
     . urlencode($query)
     . '.json?access_token=' . urlencode($apiKey)
     . '&autocomplete=true&types=address,place,locality,neighborhood,region&country=us&limit=7';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err || !$raw) {
    Response::error('Geocoding service unavailable', 503);
    exit();
}

$results = json_decode($raw, true);
if (!isset($results['features'])) {
    Response::success(['suggestions' => []]);
    exit();
}

$suggestions = [];

foreach ($results['features'] as $feature) {
    if ($feature['type'] !== 'Feature') continue;

    $placeType = $feature['place_type'][0] ?? '';
    $s = [
        'value'   => $feature['place_name'],
        'address' => '',
        'city'    => '',
        'state'   => '',
        'zip'     => '',
        'country' => '',
    ];

    if ($placeType === 'address') {
        $s['address'] = trim(($feature['address'] ?? '') . ' ' . ($feature['text'] ?? ''));
        foreach (($feature['context'] ?? []) as $ctx) {
            $type = explode('.', $ctx['id'])[0];
            switch ($type) {
                case 'postcode': $s['zip']     = $ctx['text']; break;
                case 'region':   $s['state']   = ltrim($ctx['short_code'] ?? $ctx['text'], 'US-'); break;
                case 'place':    $s['city']    = $ctx['text']; break;
                case 'country':  $s['country'] = strtoupper($ctx['short_code'] ?? ''); break;
            }
        }
    } else {
        $s['address'] = $feature['text'] ?? '';
        foreach (($feature['context'] ?? []) as $ctx) {
            $type = explode('.', $ctx['id'])[0];
            switch ($type) {
                case 'place':   $s['city']    = $ctx['text']; break;
                case 'region':  $s['state']   = ltrim($ctx['short_code'] ?? $ctx['text'], 'US-'); break;
                case 'country': $s['country'] = strtoupper($ctx['short_code'] ?? ''); break;
            }
        }
    }

    $suggestions[] = $s;
}

Response::success(['suggestions' => $suggestions]);
