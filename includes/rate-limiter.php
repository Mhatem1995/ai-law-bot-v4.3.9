<?php
/**
 * Rate Limiter - Ultra Safe Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Rate_Limiter {
    
    /**
     * Check if user can ask - NEVER THROWS ERROR
     */
    public static function check_limit($user_identifier = null) {
        // Default response - always allow if something goes wrong
        $default = array('allowed' => true, 'remaining' => 5, 'limit' => 5, 'message' => '');
        
        try {
            // Get user identifier
            if (empty($user_identifier)) {
                $user_identifier = self::get_safe_identifier();
            }
            
            // Check if premium
            if (self::is_premium_safe()) {
                return array('allowed' => true, 'remaining' => 'unlimited', 'limit' => 'unlimited', 'message' => '');
            }
            
            // Get limit
            $limit = absint(get_option('ai_law_bot_daily_limit', 5));
            if ($limit < 1) $limit = 5;
            
            // Get current count
            $count = self::get_count_safe($user_identifier);
            
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
            
        } catch (Exception $e) {
            return $default;
        } catch (Error $e) {
            return $default;
        }
    }
    
    /**
     * Increment count - NEVER THROWS ERROR
     */
    public static function increment_count($user_identifier = null) {
        try {
            // Skip for premium
            if (self::is_premium_safe()) {
                return true;
            }
            
            if (empty($user_identifier)) {
                $user_identifier = self::get_safe_identifier();
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'ai_law_bot_usage';
            
            // Check table exists
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
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
        } catch (Exception $e) {
            return false;
        } catch (Error $e) {
            return false;
        }
    }
    
    /**
     * Get count safely
     */
    private static function get_count_safe($user_identifier) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'ai_law_bot_usage';
            
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
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
        } catch (Exception $e) {
            return 0;
        } catch (Error $e) {
            return 0;
        }
    }
    
    /**
     * Get identifier safely
     */
    private static function get_safe_identifier() {
        try {
            if (function_exists('is_user_logged_in') && function_exists('get_current_user_id')) {
                if (is_user_logged_in()) {
                    $uid = get_current_user_id();
                    if ($uid > 0) {
                        return 'user_' . $uid;
                    }
                }
            }
        } catch (Exception $e) {
            // Continue to IP
        } catch (Error $e) {
            // Continue to IP
        }
        
        // Use IP
        $ip = '127.0.0.1';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return 'ip_' . md5($ip);
    }
    
    /**
     * Check premium SAFELY - most important function
     */
    private static function is_premium_safe() {
        try {
            // Check if enabled
            $enabled = get_option('ai_law_bot_enable_premium', 'no');
            if ($enabled !== 'yes') {
                return false;
            }
            
            // Must be logged in
            if (!function_exists('is_user_logged_in')) {
                return false;
            }
            if (!is_user_logged_in()) {
                return false;
            }
            
            // Get user email safely
            if (!function_exists('wp_get_current_user')) {
                return false;
            }
            
            $user = wp_get_current_user();
            if (!$user) {
                return false;
            }
            if (!isset($user->user_email)) {
                return false;
            }
            if (empty($user->user_email)) {
                return false;
            }
            
            $user_email = strtolower(trim($user->user_email));
            
            // Get premium emails
            $emails_raw = get_option('ai_law_bot_premium_emails', '');
            if (empty($emails_raw)) {
                return false;
            }
            
            // Split and check
            $emails = preg_split('/[\s,\n\r]+/', strtolower($emails_raw));
            if (!is_array($emails)) {
                return false;
            }
            
            foreach ($emails as $email) {
                $email = trim($email);
                if (!empty($email) && $email === $user_email) {
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            return false;
        } catch (Error $e) {
            return false;
        }
    }
}
