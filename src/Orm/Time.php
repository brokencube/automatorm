<?php
namespace Automatorm\Orm;

class Time extends \DateTime
{
    const MYSQL_DATE = 'Y-m-d H:i:s';
    public static $display_timezone = 'UTC'; // Default to UTC
    
    public function __construct($time = 'now', \DateTimeZone $rootTimezone = null)
    {
        // Set root timezone to UTC - all data for objects should be stored in UTC
        if (!$rootTimezone) $rootTimezone = new \DateTimeZone('UTC');
        parent::__construct($time, $rootTimezone);
        
        // Move date to display timezone for display
        $this->setTimezone(new \DateTimeZone(self::$display_timezone));
    }
    
    public function __toString()
    {
        // Format date (in display timezone)
        return $this->format(self::MYSQL_DATE);    
    }
    
    public function mysql()
    {
        // Store current timezone and set to UTC
        $timezone = $this->getTimezone();
        $this->setTimezone(new \DateTimeZone('UTC'));
        
        // Get MySQL formatted date in UTC
        $datetime = $this->format(self::MYSQL_DATE);
        
        // Return to original timezone
        $this->setTimezone($timezone);
        return $datetime;
    }
}
