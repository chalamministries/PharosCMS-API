 <?php
/**
 * JWT Helper Class
 * Handles JWT token generation and validation
 * Simple implementation without external libraries
 */

class JWT {
    
    /**
     * Generate a JWT token
     * 
     * @param array $payload - Data to encode in the token
     * @param int $expiration - Token expiration time in seconds
     * @return string - The JWT token
     */
    public static function encode($payload, $expiration = null) {
        $expiration = $expiration ?? JWT_EXPIRATION;
        
        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => JWT_ALGORITHM
        ];
        
        // Add expiration and issued at to payload
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiration;
        
        // Encode Header
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        
        // Encode Payload
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        
        // Create Signature
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET_KEY, true);
        $signatureEncoded = self::base64UrlEncode($signature);
        
        // Create JWT
        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }
    
    /**
     * Decode and validate a JWT token
     * 
     * @param string $token - The JWT token to decode
     * @return array|false - Returns payload if valid, false otherwise
     */
    public static function decode($token) {
        // Split the token
        $tokenParts = explode('.', $token);
        
        if (count($tokenParts) !== 3) {
            return false;
        }
        
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $tokenParts;
        
        // Verify signature
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET_KEY, true);
        $signatureCheck = self::base64UrlEncode($signature);
        
        if ($signatureEncoded !== $signatureCheck) {
            return false;
        }
        
        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        
        // Check if token is expired
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Extract token from Authorization header
     * 
     * @return string|null - The token or null if not found
     */
    public static function getBearerToken() {
        $headers = self::getAuthorizationHeader();
        
        // Check if Authorization header exists
        if (!empty($headers)) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Get Authorization header
     */
   private static function getAuthorizationHeader() {
       $headers = null;
       
       // Try different ways to get the Authorization header
       if (isset($_SERVER['Authorization'])) {
           $headers = trim($_SERVER['Authorization']);
       } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
           $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
       } elseif (function_exists('apache_request_headers')) {
           $requestHeaders = apache_request_headers();
           // Case-insensitive search
           foreach ($requestHeaders as $key => $value) {
               if (strtolower($key) === 'authorization') {
                   $headers = trim($value);
                   break;
               }
           }
       }
       
       // If still not found, check if it's passed via REDIRECT_HTTP_AUTHORIZATION (some servers)
       if (empty($headers) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
           $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
       }
       
       return $headers;
   }
}