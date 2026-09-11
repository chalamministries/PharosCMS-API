<?php
/**
 * Cron Job: Process Media ZIP Requests
 * 
 * 1. Identifies 'pending' requests, marks as 'processing'.
 * 2. Generates a ZIP of client-viewable media.
 * 3. Uploads the ZIP to Bunny Storage.
 * 4. Updates database with status 'completed' and bunny_path.
 * 5. Deletes expired ZIPs (older than 3 days) from Bunny and DB.
 */

require_once __DIR__ . '/../config.php';

use BunnyCDN\Storage\BunnyCDNStorage;
use ZipStream\ZipStream;
use ZipStream\Option\Archive as ArchiveOptions;

// Set up logger for cron
function cronLogger($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    echo $logMessage;
    error_log($logMessage, 3, BASE_DIR . '/cron/media_zip_cron.log');
}

cronLogger("Starting Media ZIP Cron Job");

try {
    $db = $GLOBALS['pdo'];

    // --- Part 1: Process Pending Requests ---
    $pendingRequests = $db->select("media_zip_requests", array("status" => 'pending'));

    if ($pendingRequests) {
        foreach ($pendingRequests as $request) {
            $requestId = $request['request_id'];
            $caseId = $request['case_id'];

            cronLogger("Processing request ID $requestId for Case ID $caseId");

            // Get client information to construct folder path
            $clientId = $request['client_id'];
            try {
                $clientModel = initializeClass("ClientModel", $clientId);
                $clientRecord = $clientModel->clientArr;
                if (!$clientRecord) {
                    throw new Exception("Client record not found for Client ID $clientId");
                }
            } catch (Exception $e) {
                cronLogger("Error fetching client info for request ID $requestId: " . $e->getMessage());
                $db->update("media_zip_requests", [
                    'status' => 'failed',
                    'error_message' => "Client record error: " . $e->getMessage()
                ], ['request_id' => $requestId]);
                continue;
            }

            $caseFolder = "cases/" . $clientRecord['first_name'] . $clientRecord['last_name'] . '/' . $caseId;
            $tempZipName = 'case_' . $caseId . '_' . uniqid() . '.zip';
            $remoteZipPath = $caseFolder . '/' . $tempZipName;

            // Mark as processing
            $db->update("media_zip_requests", ['status' => 'processing'], ['request_id' => $requestId]);

            try {
                // Get client-viewable media
                $mediaModel = initializeClass("MediaModel");
                $mediaFiles = $mediaModel->getClientViewableMedia($caseId);

                if (empty($mediaFiles)) {
                    cronLogger("No viewable media found for Case ID $caseId. Marking as failed.");
                    $db->update("media_zip_requests", [
                        'status' => 'failed',
                        'error_message' => 'No viewable media found for this case.'
                    ], ['request_id' => $requestId]);
                    continue;
                }

                // Configure Bunny Storage
                $storageZone = $_ENV['BUNNY_STORAGE_ZONE'];
                $accessKey = $_ENV['BUNNY_CLIENT_SECRET'];
                
                $bunnyClient = new \Bunny\Storage\Client($accessKey, $storageZone, \Bunny\Storage\Region::NEW_YORK);
                
                cronLogger("Starting ZIP generation and streaming to Bunny Storage: $remoteZipPath");

                // Create a temporary stream for the ZIP
                $zipOutputStream = fopen('php://temp', 'r+');

                // Initialize ZipStream
                $zip = new \ZipStream\ZipStream(
                    outputStream: $zipOutputStream,
                    defaultEnableZeroHeader: true, // CRITICAL: prevent RAM buffering for checksums
                    sendHttpHeaders: false
                );

                foreach ($mediaFiles as $file) {
                    $sourceUrl = $file['file_path'];
                    $fileName = basename($sourceUrl);
                    cronLogger("Streaming file into ZIP: $fileName");

                    // Open remote stream for the file
                    // CRITICAL: Encode the URL to handle spaces and special characters like apostrophes
                    $encodedSourceUrl = str_replace([' ', "'"], ['%20', '%27'], $sourceUrl);
                    $fileStream = fopen($encodedSourceUrl, 'r');
                    if ($fileStream) {
                        $zip->addFileFromStream($fileName, $fileStream);
                        fclose($fileStream);
                    } else {
                        cronLogger("Warning: Could not open remote stream for $fileName");
                    }
                }

                // Finalize ZIP
                $zip->finish();
                
                // Seek to beginning of stream for upload
                rewind($zipOutputStream);
                
                cronLogger("Uploading to Bunny Storage via streaming PUT...");
                
                // Upload to Bunny using curl directly to support streaming resource
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://ny.storage.bunnycdn.com/" . $storageZone . "/" . $remoteZipPath);
                curl_setopt($ch, CURLOPT_PUT, true);
                curl_setopt($ch, CURLOPT_INFILE, $zipOutputStream);
                curl_setopt($ch, CURLOPT_INFILESIZE, fstat($zipOutputStream)['size']);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "AccessKey: " . $accessKey
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode < 200 || $httpCode >= 300) {
                    throw new Exception("Bunny upload FAILED with HTTP $httpCode: $response");
                }
                
                // Get size for database record
                $zipSize = fstat($zipOutputStream)['size'];
                
                cronLogger("Uploaded to Bunny successfully via streaming PUT.");
                
                $pullZoneUrl = defined('BUNNY_PULL_ZONE_URL') ? BUNNY_PULL_ZONE_URL : $_ENV['BUNNY_PULL_ZONE_URL'];
                $finalBunnyUrl = rtrim($pullZoneUrl, '/') . '/' . ltrim($remoteZipPath, '/');

                // Update database
                $db->update("media_zip_requests", [
                    'status' => 'completed',
                    'bunny_path' => $finalBunnyUrl,
                    'file_size_bytes' => $zipSize, 
                    'completed_at' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', strtotime('+3 days'))
                ], ['request_id' => $requestId]);

                cronLogger("Request ID $requestId completed successfully.");

                if (isset($zipOutputStream) && is_resource($zipOutputStream)) {
                    fclose($zipOutputStream);
                }
            } catch (Exception $e) {
                cronLogger("Error processing request ID $requestId: " . $e->getMessage());
                $db->update("media_zip_requests", [
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ], ['request_id' => $requestId]);
                
                if (isset($zipOutputStream) && is_resource($zipOutputStream)) {
                    fclose($zipOutputStream);
                }
            }
        }
    } else {
        cronLogger("No pending requests found.");
    }

    // --- Part 2: Cleanup Expired ZIPs ---
    // Check for ZIPs where expires_at is older than now (which was set to 3 days from completion)
    // The requirement said "expires_at is older than 3 days", but since we set expires_at = now + 3 days, 
    // it means if expires_at < now, it has already been 3 days since it was created.
    
    $expiredRequests = $db->query("SELECT * FROM media_zip_requests WHERE expires_at < NOW()");

    if ($expiredRequests) {
        $storageZone = $_ENV['BUNNY_STORAGE_ZONE'];
        $accessKey = $_ENV['BUNNY_CLIENT_SECRET'];
        $bunnyClient = new \Bunny\Storage\Client($accessKey, $storageZone, \Bunny\Storage\Region::NEW_YORK);

        foreach ($expiredRequests as $request) {
            $requestId = $request['request_id'];
            $bunnyUrl = $request['bunny_path'];

            cronLogger("Cleaning up expired request ID $requestId");

            if ($bunnyUrl) {
                try {
                    // Extract remote path from URL
                    // Example: https://pull-zone.b-cdn.net/cases/FirstLast/123/case_123_abc.zip
                    $parsedUrl = parse_url($bunnyUrl);
                    $urlPath = ltrim($parsedUrl['path'], '/');
                    
                    // We need to extract the path within the storage zone
                    // The remoteZipPath we saved was cases/{FirstLast}/{case_id}/{zipname}
                    if (preg_match('/(cases\/.*)/', $urlPath, $matches)) {
                        $remotePath = $matches[1];
                    } else {
                        $remotePath = $urlPath;
                    }

                    cronLogger("Deleting from Bunny: $remotePath");
                    
                    $bunnyClient->delete($remotePath);
                    cronLogger("Deleted from Bunny successfully.");
                } catch (Exception $e) {
                    cronLogger("Warning: Could not delete from Bunny: " . $e->getMessage());
                }
            }

            // Delete from database
            $db->delete("media_zip_requests", ['request_id' => $requestId]);
            cronLogger("Deleted request ID $requestId from database.");
        }
    } else {
        cronLogger("No expired requests found.");
    }

} catch (Exception $e) {
    cronLogger("CRITICAL ERROR: " . $e->getMessage());
}

cronLogger("Cron Job Finished");
