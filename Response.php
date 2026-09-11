<?php
/**
 * Response Helper Class
 * Provides consistent response formatting for the API
 */

class Response {
    
    /**
     * Send a success response and optionally continue processing
     */
    public static function success($data = null, $message = 'Success', $code = 200, $continue = false) {
        $response = [
            'success' => true,
            'code' => $code,
            'message' => $message,
            'errors' => array()
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($code !== 200) {
            http_response_code($code);
        } else {
            http_response_code(200);
        }

        // If we want to continue processing in the background
        if ($continue) {
            $json = json_encode($response, JSON_PRETTY_PRINT);
            
            // Clear any previous output
            if (ob_get_level()) ob_end_clean();
            
            // Start output buffering
            ob_start();
            echo $json;
            
            // Get content length
            $size = ob_get_length();
            
            // Standard headers for background processing
            if (!headers_sent()) {
                header("Content-Length: $size");
                header('Connection: close');
            }
            
            // Close all output buffers
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            // Log that we are attempting to finish request
            if (function_exists('Logger')) {
                Logger("DEBUG: Attempting to continue in background...");
            }

            // The absolute best way if using PHP-FPM
            if (function_exists('fastcgi_finish_request')) {
                if (function_exists('Logger')) {
                    Logger("DEBUG: Calling fastcgi_finish_request()...");
                }
                fastcgi_finish_request();
                if (function_exists('Logger')) {
                    Logger("DEBUG: fastcgi_finish_request() called.");
                }
            } else {
                if (function_exists('Logger')) {
                    Logger("DEBUG: fastcgi_finish_request not available.");
                }
            }
            if (function_exists('Logger')) {
                Logger("DEBUG: Returning from Response::success to caller.");
            }
            // Code after calling success($data, $msg, $code, true) will continue to run
            return;
        }

        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Send an error response
     * 
     * @param string $message - Error message
     * @param int $code - HTTP status code
     * @param array $errors - Additional error details
     */
    public static function error($message = 'An error occurred', $code = 400, $errors = array()) {
        //for Bubble, we have to always return http_response_code of 200
        http_response_code(200);
        
        $response = [
            'success' => false,
            'code' => $code,
            'message' => $message
        ];
        
        if (count($errors) > 0) {
            $response['errors'] = $errors;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Send validation error response
     */
    public static function validationError($errors) {
        self::error('Validation failed', 422, $errors);
    }
    
    /**
     * Send not found response
     */
    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }
    
    /**
     * Send server error response
     */
    public static function serverError($message = 'Internal server error') {
        self::error($message, 500);
    }
    
    /**
     * Get request body as JSON
     * 
     * @return array|null
     */
    public static function getJsonInput() {
        $input = file_get_contents('php://input');
        return json_decode($input, true);
    }
    
    /**
     * Validate required fields in request
     * 
     * @param array $data - Request data
     * @param array $required - Required field names
     * @return array - Array of missing fields (empty if all present)
     */
    public static function validateRequired($data, $required) {
        $missing = [];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $missing[] = $field;
            }
        }
        
        return $missing;
    }
}
