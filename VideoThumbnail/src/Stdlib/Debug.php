<?php
namespace VideoThumbnail\Stdlib;

class Debug
{
    /**
     * @var bool Whether debug mode is enabled
     */
    protected static $enabled = false;
    
    /**
     * @var bool Whether configuration has been loaded
     */
    protected static $initialized = false;
    
    /**
     * Initialize the debug system with settings
     *
     * @param \Omeka\Settings\Settings|null $settings Omeka settings
     * @return void
     */
    public static function init($settings = null): void
    {
        if (self::$initialized) {
            return;
        }
        
        // Use settings to determine debug mode instead of forcing it on
        if ($settings !== null) {
            self::$enabled = (bool) $settings->get('videothumbnail_debug_mode', false);
        } else {
            self::$enabled = false; // Default to disabled if no settings provided
        }
        
        self::$initialized = true;
    }
    
    /**
     * Enable or disable debug mode
     *
     * @param bool $enabled
     * @return void
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
        self::$initialized = true;
    }
    
    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * Log a debug message with function context
     *
     * @param string $message The message to log
     * @param string $function The calling function name
     * @param string $type The type of log entry (entry, exit, info, error)
     * @return void
     */
    public static function log(string $message, string $function = '', string $type = 'info'): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $prefix = 'VideoThumbnail Debug';
        
        if (!empty($function)) {
            $prefix .= " | {$function}";
        }
        
        switch ($type) {
            case 'entry':
                $message = "ENTER: {$message}";
                break;
            case 'exit':
                $message = "EXIT: {$message}";
                break;
            case 'error':
                $prefix .= " | ERROR";
                break;
            default:
                // Default info formatting
                break;
        }
        
        error_log("{$prefix} | {$message}");
    }
    
    /**
     * Log function entry
     *
     * @param string $function Function name
     * @param array $params Optional parameters to log
     * @return void
     */
    public static function logEntry(string $function, array $params = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $message = "Function called";
        
        if (!empty($params)) {
            $paramsString = '';
            foreach ($params as $key => $value) {
                if (is_object($value)) {
                    $paramsString .= ", {$key}: " . get_class($value);
                } elseif (is_array($value)) {
                    $paramsString .= ", {$key}: array(" . count($value) . ")";
                } elseif (is_resource($value)) {
                    $paramsString .= ", {$key}: resource(" . get_resource_type($value) . ")";
                } else {
                    // Truncate string values if too long
                    if (is_string($value) && strlen($value) > 100) {
                        $value = substr($value, 0, 97) . '...';
                    }
                    $paramsString .= ", {$key}: " . var_export($value, true);
                }
            }
            
            if (!empty($paramsString)) {
                $message .= " with params" . substr($paramsString, 1);
            }
        }
        
        self::log($message, $function, 'entry');
    }
    
    /**
     * Log function exit
     *
     * @param string $function Function name
     * @param mixed $result Optional return value to log
     * @return void
     */
    public static function logExit(string $function, $result = null): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $message = "Function completed";
        
        if ($result !== null) {
            if (is_object($result)) {
                $message .= " returning " . get_class($result);
            } elseif (is_array($result)) {
                $message .= " returning array(" . count($result) . ")";
            } elseif (is_resource($result)) {
                $message .= " returning resource(" . get_resource_type($result) . ")";
            } else {
                // Truncate string values if too long
                if (is_string($result) && strlen($result) > 100) {
                    $result = substr($result, 0, 97) . '...';
                }
                $message .= " returning " . var_export($result, true);
            }
        }
        
        self::log($message, $function, 'exit');
    }
    
    /**
     * Log an error message
     *
     * @param string $message Error message
     * @param string $function Function where the error occurred
     * @param \Throwable|null $exception Optional exception
     * @return void
     */
    public static function logError(string $message, string $function = '', \Throwable $exception = null): void
    {
        // Always log errors, even if debug mode is disabled
        
        if ($exception !== null) {
            $message .= " Exception: " . $exception->getMessage();
            // Only add trace in debug mode
            if (self::$enabled) {
                $message .= "\nTrace: " . $exception->getTraceAsString();
            }
        }
        
        self::log($message, $function, 'error');
    }
}