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

        // Sanitize the message
        $sanitizedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        error_log("{$prefix} | {$sanitizedMessage}");
    }
    
    // Other methods remain unchanged...
}
