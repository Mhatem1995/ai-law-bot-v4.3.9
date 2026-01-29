<?php
/**
 * Database Handler - Creates and manages all custom tables
 * 
 * Tables:
 * - ai_law_bot_chat_logs: Stores all questions/answers with keywords
 * - ai_law_bot_missing_topics: Questions with no matching PDFs
 * - ai_law_bot_conversation_memory: Session-based conversation history
 * - ai_law_bot_keyword_cache: Successful keyword matches for learning
 * - ai_law_bot_usage: Rate limiting tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Database {
    
    /**
     * Create all required database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Table 1: Chat Logs - stores all conversations with analysis
        $table_chat_logs = $wpdb->prefix . 'ai_law_bot_chat_logs';
        $sql_chat_logs = "CREATE TABLE $table_chat_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            user_identifier varchar(255) NOT NULL,
            question text NOT NULL,
            extracted_keywords text NOT NULL,
            matched_pdfs text DEFAULT NULL,
            pdfs_found tinyint(1) DEFAULT 0,
            ai_response longtext DEFAULT NULL,
            tokens_used int(11) DEFAULT 0,
            model varchar(100) DEFAULT '',
            ip_address varchar(100) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_identifier (user_identifier),
            KEY pdfs_found (pdfs_found),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_chat_logs);
        
        // Table 2: Missing Topics - questions without matching PDFs
        $table_missing = $wpdb->prefix . 'ai_law_bot_missing_topics';
        $sql_missing = "CREATE TABLE $table_missing (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            question text NOT NULL,
            extracted_keywords text NOT NULL,
            asked_count int(11) DEFAULT 1,
            first_asked_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_asked_at datetime DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(100) DEFAULT '',
            handled tinyint(1) DEFAULT 0,
            admin_notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY handled (handled),
            KEY asked_count (asked_count),
            KEY last_asked_at (last_asked_at)
        ) $charset_collate;";
        dbDelta($sql_missing);
        
        // Table 3: Conversation Memory - stores conversation context per session
        $table_memory = $wpdb->prefix . 'ai_law_bot_conversation_memory';
        $sql_memory = "CREATE TABLE $table_memory (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            user_identifier varchar(255) NOT NULL,
            role enum('user','assistant') NOT NULL,
            content text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_identifier (user_identifier),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql_memory);
        
        // Table 4: Keyword Cache - successful keyword-to-PDF matches for learning
        $table_keywords = $wpdb->prefix . 'ai_law_bot_keyword_cache';
        $sql_keywords = "CREATE TABLE $table_keywords (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword varchar(100) NOT NULL,
            matched_post_id bigint(20) NOT NULL,
            matched_pdf_title varchar(255) NOT NULL,
            match_count int(11) DEFAULT 1,
            relevance_score float DEFAULT 0,
            last_matched_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY keyword_post (keyword, matched_post_id),
            KEY keyword (keyword),
            KEY matched_post_id (matched_post_id),
            KEY match_count (match_count)
        ) $charset_collate;";
        dbDelta($sql_keywords);
        
        // Table 5: Usage Tracking - rate limiting
        $table_usage = $wpdb->prefix . 'ai_law_bot_usage';
        $sql_usage = "CREATE TABLE $table_usage (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_identifier varchar(255) NOT NULL,
            question_count int(11) DEFAULT 0,
            last_reset datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_identifier (user_identifier)
        ) $charset_collate;";
        dbDelta($sql_usage);
        
        // Table 6: Failed Matches Log - to avoid repeating bad matches
        $table_failed = $wpdb->prefix . 'ai_law_bot_failed_matches';
        $sql_failed = "CREATE TABLE $table_failed (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            question_hash varchar(64) NOT NULL,
            wrong_post_id bigint(20) NOT NULL,
            reported_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY question_post (question_hash, wrong_post_id),
            KEY question_hash (question_hash)
        ) $charset_collate;";
        dbDelta($sql_failed);
    }
    
    /**
     * Log a chat interaction
     */
    public static function log_chat($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_chat_logs';
        
        return $wpdb->insert(
            $table,
            array(
                'session_id' => $data['session_id'],
                'user_identifier' => $data['user_identifier'],
                'question' => $data['question'],
                'extracted_keywords' => json_encode($data['keywords'], JSON_UNESCAPED_UNICODE),
                'matched_pdfs' => isset($data['matched_pdfs']) ? json_encode($data['matched_pdfs'], JSON_UNESCAPED_UNICODE) : null,
                'pdfs_found' => $data['pdfs_found'] ? 1 : 0,
                'ai_response' => isset($data['ai_response']) ? $data['ai_response'] : null,
                'tokens_used' => isset($data['tokens_used']) ? $data['tokens_used'] : 0,
                'model' => isset($data['model']) ? $data['model'] : '',
                'ip_address' => $data['ip_address'],
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Log or update missing topic
     */
    public static function log_missing_topic($question, $keywords, $ip_address) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_missing_topics';
        
        // Normalize question for comparison
        $normalized = self::normalize_question($question);
        
        // Check if similar question exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, asked_count FROM $table WHERE question = %s OR question LIKE %s LIMIT 1",
            $question,
            '%' . $wpdb->esc_like($normalized) . '%'
        ));
        
        if ($existing) {
            // Update existing
            return $wpdb->update(
                $table,
                array(
                    'asked_count' => $existing->asked_count + 1,
                    'last_asked_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%d', '%s'),
                array('%d')
            );
        } else {
            // Insert new
            return $wpdb->insert(
                $table,
                array(
                    'question' => $question,
                    'extracted_keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE),
                    'asked_count' => 1,
                    'first_asked_at' => current_time('mysql'),
                    'last_asked_at' => current_time('mysql'),
                    'ip_address' => $ip_address,
                    'handled' => 0
                ),
                array('%s', '%s', '%d', '%s', '%s', '%s', '%d')
            );
        }
    }
    
    /**
     * Save conversation message to memory
     */
    public static function save_conversation_message($session_id, $user_identifier, $role, $content) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_conversation_memory';
        
        // Clean old messages (keep only last 20 per session)
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %s",
            $session_id
        ));
        
        if ($count >= 20) {
            // Delete oldest messages
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE session_id = %s ORDER BY created_at ASC LIMIT %d",
                $session_id,
                $count - 19
            ));
        }
        
        return $wpdb->insert(
            $table,
            array(
                'session_id' => $session_id,
                'user_identifier' => $user_identifier,
                'role' => $role,
                'content' => $content,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get conversation history for a session
     */
    public static function get_conversation_history($session_id, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_conversation_memory';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content FROM $table 
             WHERE session_id = %s 
             ORDER BY created_at DESC 
             LIMIT %d",
            $session_id,
            $limit
        ), ARRAY_A);
        
        // Reverse to get chronological order
        return array_reverse($results);
    }
    
    /**
     * Cache a successful keyword match
     */
    public static function cache_keyword_match($keyword, $post_id, $pdf_title, $score) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_keyword_cache';
        
        // Check if exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, match_count FROM $table WHERE keyword = %s AND matched_post_id = %d",
            $keyword,
            $post_id
        ));
        
        if ($existing) {
            // Update match count
            return $wpdb->update(
                $table,
                array(
                    'match_count' => $existing->match_count + 1,
                    'relevance_score' => $score,
                    'last_matched_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%d', '%f', '%s'),
                array('%d')
            );
        } else {
            // Insert new
            return $wpdb->insert(
                $table,
                array(
                    'keyword' => $keyword,
                    'matched_post_id' => $post_id,
                    'matched_pdf_title' => $pdf_title,
                    'match_count' => 1,
                    'relevance_score' => $score,
                    'last_matched_at' => current_time('mysql'),
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%d', '%s', '%d', '%f', '%s', '%s')
            );
        }
    }
    
    /**
     * Get cached keyword suggestions
     */
    public static function get_cached_keywords($keywords) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_keyword_cache';
        
        if (empty($keywords)) {
            return array();
        }
        
        $placeholders = implode(',', array_fill(0, count($keywords), '%s'));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT keyword, matched_post_id, matched_pdf_title, match_count, relevance_score 
             FROM $table 
             WHERE keyword IN ($placeholders) 
             ORDER BY match_count DESC, relevance_score DESC",
            $keywords
        ), ARRAY_A);
    }
    
    /**
     * Log a failed/wrong match to avoid in future
     */
    public static function log_failed_match($question, $post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_failed_matches';
        
        $question_hash = md5(mb_strtolower(trim($question), 'UTF-8'));
        
        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE question_hash = %s AND wrong_post_id = %d",
            $question_hash,
            $post_id
        ));
        
        if (!$existing) {
            return $wpdb->insert(
                $table,
                array(
                    'question_hash' => $question_hash,
                    'wrong_post_id' => $post_id,
                    'reported_at' => current_time('mysql')
                ),
                array('%s', '%d', '%s')
            );
        }
        
        return false;
    }
    
    /**
     * Check if a post was previously marked as wrong match
     */
    public static function is_failed_match($question, $post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_failed_matches';
        
        $question_hash = md5(mb_strtolower(trim($question), 'UTF-8'));
        
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE question_hash = %s AND wrong_post_id = %d",
            $question_hash,
            $post_id
        ));
    }
    
    /**
     * Normalize question for comparison
     */
    private static function normalize_question($question) {
        // Remove question marks, extra spaces
        $normalized = str_replace(array('؟', '?', '،', ',', '.'), ' ', $question);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return mb_strtolower(trim($normalized), 'UTF-8');
    }
    
    /**
     * Get statistics for admin dashboard
     */
    public static function get_statistics($days = 30) {
        global $wpdb;
        $chat_table = $wpdb->prefix . 'ai_law_bot_chat_logs';
        $missing_table = $wpdb->prefix . 'ai_law_bot_missing_topics';
        
        $stats = array();
        
        // Total questions
        $stats['total_questions'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $chat_table WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Questions with matching PDFs
        $stats['matched_questions'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $chat_table WHERE pdfs_found = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Missing topics count
        $stats['missing_topics'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $missing_table WHERE handled = 0"
        );
        
        // Most asked missing topics
        $stats['top_missing'] = $wpdb->get_results(
            "SELECT question, asked_count FROM $missing_table WHERE handled = 0 ORDER BY asked_count DESC LIMIT 10",
            ARRAY_A
        );
        
        // Unique users
        $stats['unique_users'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_identifier) FROM $chat_table WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return $stats;
    }
    
    /**
     * Clean old data (for maintenance)
     */
    public static function cleanup_old_data($days = 90) {
        global $wpdb;
        
        // Clean old chat logs
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ai_law_bot_chat_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Clean old conversation memory
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ai_law_bot_conversation_memory WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Clean resolved missing topics older than 1 year
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ai_law_bot_missing_topics WHERE handled = 1 AND last_asked_at < DATE_SUB(NOW(), INTERVAL 365 DAY)"
        );
    }
}
