<?php
/**
 * Rate Limiter - Bulletproof Version
 * Will NEVER cause fatal error
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Rate_Limiter {
    
    /**
     * Check if user can ask - ABSOLUTELY SAFE
     */
    public static function check_limit($user_identifier = null) {
        // Default - always allow
        $default = array('allowed' => true, 'remaining' => 5, 'limit' => 5, 'message' => '');
        
        // Wrap EVERYTHING in try-catch
        try {
            if (empty($user_identifier)) {
                $user_identifier = self::get_identifier_safe();
            }
            
            // Check premium - but if ANY error, skip it
            $is_premium = false;
            try {
                $is_premium = self::check_premium_safe();
            } catch (\Exception $e) {
                $is_premium = false;
            } catch (\Error $e) {
                $is_premium = false;
            } catch (\Throwable $e) {
                $is_premium = false;
            }
            
            if ($is_premium) {
                return array('allowed' => true, 'remaining' => 'unlimited', 'limit' => 'unlimited', 'message' => '');
            }
            
            // Get limit
            $limit = 5;
            try {
                $limit = absint(get_option('ai_law_bot_daily_limit', 5));
                if ($limit < 1) $limit = 5;
            } catch (\Exception $e) {
                $limit = 5;
            } catch (\Error $e) {
                $limit = 5;
            }
            
            // Get count
            $count = 0;
            try {
                $count = self::get_count_safe($user_identifier);
            } catch (\Exception $e) {
                $count = 0;
            } catch (\Error $e) {
                $count = 0;
            }
            
            if ($count >= $limit) {
                return array(
                    'allowed' => false,
                    'remaining' => 0,
                    'limit' => $limit,
                    'message' => 'وصلت للحد اليومي'
                );
            }
            
            return array(
                'allowed' => true,
                'remaining' => $limit - $count,
                'limit' => $limit,
                'message' => ''
            );
            
        } catch (\Exception $e) {
            return $default;
        } catch (\Error $e) {
            return $default;
        } catch (\Throwable $e) {
            return $default;
        }
        
        return $default;
    }
    
    /**
     * Increment count - SAFE
     */
    public static function increment_count($user_identifier = null) {
        try {
            // Check premium first
            $is_premium = false;
            try {
                $is_premium = self::check_premium_safe();
            } catch (\Throwable $e) {
                $is_premium = false;
            }
            
            if ($is_premium) {
                return true;
            }
            
            if (empty($user_identifier)) {
                $user_identifier = self::get_identifier_safe();
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'ai_law_bot_usage';
            
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($table_exists !== $table) {
                return false;
            }
            
            $today = date('Y-m-d');
            $now = current_time('mysql');
            
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT question_count, last_reset FROM $table WHERE user_identifier = %s",
                $user_identifier
            ));
            
            if ($row) {
                $last_date = substr($row->last_reset, 0, 10);
                if ($last_date < $today) {
                    $wpdb->update($table, 
                        array('question_count' => 1, 'last_reset' => $now),
                        array('user_identifier' => $user_identifier)
                    );
                } else {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $table SET question_count = question_count + 1 WHERE user_identifier = %s",
                        $user_identifier
                    ));
                }
            } else {
                $wpdb->insert($table, array(
                    'user_identifier' => $user_identifier,
                    'question_count' => 1,
                    'last_reset' => $now,
                    'created_at' => $now
                ));
            }
            
            return true;
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
    
    /**
     * Get count - SAFE
     */
    private static function get_count_safe($user_identifier) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'ai_law_bot_usage';
            
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($table_exists !== $table) {
                return 0;
            }
            
            $today = date('Y-m-d');
            
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT question_count, last_reset FROM $table WHERE user_identifier = %s",
                $user_identifier
            ));
            
            if (!$row) return 0;
            
            $last_date = substr($row->last_reset, 0, 10);
            if ($last_date < $today) return 0;
            
            return absint($row->question_count);
        } catch (\Throwable $e) {
            return 0;
        }
        return 0;
    }
    
    /**
     * Get user identifier - SAFE
     */
    private static function get_identifier_safe() {
        try {
            if (function_exists('is_user_logged_in') && function_exists('get_current_user_id')) {
                if (is_user_logged_in()) {
                    $uid = get_current_user_id();
                    if ($uid > 0) {
                        return 'user_' . $uid;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to IP
        }
        
        $ip = '127.0.0.1';
        if (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return 'ip_' . md5($ip);
    }
    
    /**
     * Check premium - COMPLETELY SAFE
     * Returns false on ANY error
     */
    private static function check_premium_safe() {
        try {
            // Is premium feature even enabled?
            $enabled = get_option('ai_law_bot_enable_premium', 'no');
            if ($enabled !== 'yes') {
                return false;
            }
            
            // Check if user functions exist
            if (!function_exists('is_user_logged_in') || !function_exists('wp_get_current_user')) {
                return false;
            }
            
            // Is user logged in?
            if (!is_user_logged_in()) {
                return false;
            }
            
            // Get current user
            $current_user = wp_get_current_user();
            
            // Validate user object
            if (!$current_user || !is_object($current_user)) {
                return false;
            }
            
            // Get email - check multiple ways
            $user_email = '';
            if (isset($current_user->user_email) && !empty($current_user->user_email)) {
                $user_email = $current_user->user_email;
            } elseif (method_exists($current_user, 'get') && $current_user->get('user_email')) {
                $user_email = $current_user->get('user_email');
            }
            
            if (empty($user_email)) {
                return false;
            }
            
            $user_email = strtolower(trim($user_email));
            
            // Get premium emails list
            $emails_option = get_option('ai_law_bot_premium_emails', '');
            if (empty($emails_option)) {
                return false;
            }
            
            // Parse emails - handle multiple formats
            $premium_emails = array();
            if (is_string($emails_option)) {
                // Split by newline, comma, or space
                $premium_emails = preg_split('/[\s,\n\r]+/', $emails_option, -1, PREG_SPLIT_NO_EMPTY);
            }
            
            if (empty($premium_emails) || !is_array($premium_emails)) {
                return false;
            }
            
            // Check if user email is in the list
            foreach ($premium_emails as $email) {
                if (is_string($email)) {
                    $email = strtolower(trim($email));
                    if (!empty($email) && $email === $user_email) {
                        return true;
                    }
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            return false;
        } catch (\Error $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
        
        return false;
    }
}
