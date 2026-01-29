<?php
/**
 * REST API Endpoints - Bulletproof Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_REST_Endpoints {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        register_rest_route('ai-law-bot/v1', '/ask', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_question'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('ai-law-bot/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        register_rest_route('ai-law-bot/v1', '/debug', array(
            'methods' => 'GET',
            'callback' => array($this, 'debug_info'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        register_rest_route('ai-law-bot/v1', '/stats-by-date', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_stats_by_date'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
    }
    
    /**
     * Handle question - BULLETPROOF
     */
    public function handle_question($request) {
        // Wrap ENTIRE function in try-catch
        try {
            return $this->process_question($request);
        } catch (\Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'حدث خطأ. يرجى المحاولة مرة أخرى. (E1)'
            ), 500);
        } catch (\Error $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'حدث خطأ. يرجى المحاولة مرة أخرى. (E2)'
            ), 500);
        } catch (\Throwable $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'حدث خطأ. يرجى المحاولة مرة أخرى. (E3)'
            ), 500);
        }
    }
    
    /**
     * Process the question
     */
    private function process_question($request) {
        $question = '';
        $session_id = '';
        
        // Get parameters safely
        try {
            $question = sanitize_text_field($request->get_param('question'));
            $session_id = sanitize_text_field($request->get_param('session_id'));
        } catch (\Throwable $e) {
            $question = isset($_POST['question']) ? sanitize_text_field($_POST['question']) : '';
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        }
        
        // Validate question
        if (empty($question) || mb_strlen($question, 'UTF-8') < 3) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'السؤال قصير جداً'
            ), 400);
        }
        
        // Generate session ID if empty
        if (empty($session_id)) {
            $session_id = 'sess_' . time() . '_' . rand(1000, 9999);
        }
        
        // Get user identifier safely
        $user_identifier = 'ip_' . md5(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1');
        try {
            if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
                $user_identifier = 'user_' . get_current_user_id();
            }
        } catch (\Throwable $e) {
            // Keep IP-based identifier
        }
        
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '127.0.0.1';
        
        // Check rate limit - SAFE
        $limit_check = array('allowed' => true, 'remaining' => 5, 'limit' => 5);
        try {
            if (class_exists('AI_Law_Bot_Rate_Limiter')) {
                $limit_check = AI_Law_Bot_Rate_Limiter::check_limit($user_identifier);
            }
        } catch (\Throwable $e) {
            // Use default - allow
        }
        
        if (!$limit_check['allowed']) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => "لقد وصلت إلى الحد اليومي المسموح به من الأسئلة.\n\nللحصول على عدد غير محدود من الاستفسارات، يرجى التواصل مع مكتب المحاماة.",
                'limit_reached' => true,
                'remaining' => 0
            ), 429);
        }
        
        // Extract keywords safely
        $keywords = array();
        try {
            if (class_exists('AI_Law_Bot_Keyword_Extractor')) {
                $keywords = AI_Law_Bot_Keyword_Extractor::extract($question);
            }
        } catch (\Throwable $e) {
            $keywords = array();
        }
        
        // Search for matching content safely
        $matched_content = array();
        try {
            if (class_exists('AI_Law_Bot_PDF_Search')) {
                $pdf_search = new AI_Law_Bot_PDF_Search();
                $search_results = $pdf_search->search($question, $keywords);
                
                if (isset($search_results['success']) && $search_results['success'] && !empty($search_results['pdfs'])) {
                    $matched_content = $search_results['pdfs'];
                } else {
                    $all_content = $pdf_search->get_all_content_for_semantic_search();
                    if (!empty($all_content)) {
                        $matched_content = array_slice($all_content, 0, 3);
                    }
                }
            }
        } catch (\Throwable $e) {
            $matched_content = array();
        }
        
        // Handle NO content found
        if (empty($matched_content)) {
            // Log missing topic safely
            try {
                if (class_exists('AI_Law_Bot_Database')) {
                    AI_Law_Bot_Database::log_missing_topic($question, $keywords, $ip_address);
                }
            } catch (\Throwable $e) {
                // Ignore logging errors
            }
            
            // Increment usage safely
            try {
                if (class_exists('AI_Law_Bot_Rate_Limiter')) {
                    AI_Law_Bot_Rate_Limiter::increment_count($user_identifier);
                }
            } catch (\Throwable $e) {
                // Ignore
            }
            
            return new WP_REST_Response(array(
                'success' => true,
                'message' => "لا توجد معلومات عن هذا الموضوع حالياً في موقعنا، وسيتم إضافتها قريباً.",
                'pdfs' => array(),
                'remaining' => $this->get_remaining_safe($limit_check),
                'session_id' => $session_id
            ), 200);
        }
        
        // Send to OpenAI
        $ai_response = null;
        try {
            if (class_exists('AI_Law_Bot_OpenAI_Client')) {
                $client = new AI_Law_Bot_OpenAI_Client();
                $ai_response = $client->ask($question, $matched_content, array());
            }
        } catch (\Throwable $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'خطأ في الاتصال بـ OpenAI'
            ), 500);
        }
        
        if (is_wp_error($ai_response)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'خطأ: ' . $ai_response->get_error_message()
            ), 500);
        }
        
        if (!$ai_response || !isset($ai_response['answer'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'لم نتمكن من الحصول على إجابة'
            ), 500);
        }
        
        // Format response WITH LINKS
        $formatted_response = $this->format_with_links($ai_response['answer'], $matched_content);
        
        // Log success safely
        try {
            if (class_exists('AI_Law_Bot_Database')) {
                AI_Law_Bot_Database::log_chat(array(
                    'session_id' => $session_id,
                    'user_identifier' => $user_identifier,
                    'question' => $question,
                    'keywords' => $keywords,
                    'matched_pdfs' => $matched_content,
                    'pdfs_found' => true,
                    'ai_response' => $formatted_response,
                    'tokens_used' => isset($ai_response['tokens_used']) ? $ai_response['tokens_used'] : 0,
                    'model' => isset($ai_response['model']) ? $ai_response['model'] : '',
                    'ip_address' => $ip_address
                ));
            }
        } catch (\Throwable $e) {
            // Ignore logging errors
        }
        
        // Record success in Learning Engine - THIS WAS MISSING!
        try {
            if (class_exists('AI_Law_Bot_Learning_Engine') && !empty($keywords) && !empty($matched_content)) {
                AI_Law_Bot_Learning_Engine::record_success($question, $keywords, $matched_content);
            }
        } catch (\Throwable $e) {
            // Ignore learning errors
        }
        
        // Increment usage safely
        try {
            if (class_exists('AI_Law_Bot_Rate_Limiter')) {
                AI_Law_Bot_Rate_Limiter::increment_count($user_identifier);
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        
        // Build PDF list
        $pdf_list = array();
        foreach ($matched_content as $pdf) {
            $pdf_list[] = array(
                'title' => isset($pdf['pdf_title']) ? $pdf['pdf_title'] : (isset($pdf['title']) ? $pdf['title'] : ''),
                'link' => isset($pdf['post_link']) ? $pdf['post_link'] : '',
                'post_id' => isset($pdf['post_id']) ? $pdf['post_id'] : 0
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $formatted_response,
            'pdfs' => $pdf_list,
            'remaining' => $this->get_remaining_safe($limit_check),
            'session_id' => $session_id
        ), 200);
    }
    
    /**
     * Format response with links - ALWAYS adds links
     */
    private function format_with_links($ai_text, $pdfs) {
        $formatted = "⚖️ " . $ai_text;
        
        if (!empty($pdfs) && is_array($pdfs)) {
            $formatted .= "\n\n📘 <strong>للمزيد من التفاصيل:</strong>\n";
            foreach ($pdfs as $pdf) {
                $title = isset($pdf['pdf_title']) ? $pdf['pdf_title'] : (isset($pdf['title']) ? $pdf['title'] : '');
                $link = isset($pdf['post_link']) ? $pdf['post_link'] : '';
                
                if (!empty($title) && !empty($link)) {
                    $formatted .= "• <a href=\"" . esc_url($link) . "\" target=\"_blank\" style=\"color:#3b82f6;text-decoration:underline;\">" . esc_html($title) . "</a>\n";
                }
            }
        }
        
        return $formatted;
    }
    
    /**
     * Get remaining safely
     */
    private function get_remaining_safe($limit_check) {
        if (isset($limit_check['remaining'])) {
            if ($limit_check['remaining'] === 'unlimited') {
                return 'unlimited';
            }
            $remaining = intval($limit_check['remaining']) - 1;
            return max(0, $remaining);
        }
        return 4;
    }
    
    /**
     * Test connection
     */
    public function test_connection($request) {
        try {
            if (class_exists('AI_Law_Bot_OpenAI_Client')) {
                $result = AI_Law_Bot_OpenAI_Client::test_connection();
                return new WP_REST_Response($result, isset($result['success']) && $result['success'] ? 200 : 500);
            }
            return new WP_REST_Response(array('success' => false, 'message' => 'OpenAI Client not found'), 500);
        } catch (\Throwable $e) {
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
    }
    
    /**
     * Debug info
     */
    public function debug_info($request) {
        try {
            $pdf_count = 0;
            if (class_exists('AI_Law_Bot_PDF_Search')) {
                $pdf_count = AI_Law_Bot_PDF_Search::get_pdf_count();
            }
            
            return new WP_REST_Response(array(
                'api_key_exists' => !empty(get_option('ai_law_bot_openai_key', '')),
                'model' => get_option('ai_law_bot_model', 'gpt-4o'),
                'pdf_count' => $pdf_count,
                'premium_enabled' => get_option('ai_law_bot_enable_premium', 'no'),
                'daily_limit' => get_option('ai_law_bot_daily_limit', 5),
                'version' => defined('AI_LAW_BOT_VERSION') ? AI_LAW_BOT_VERSION : 'unknown'
            ), 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(array('error' => $e->getMessage()), 500);
        }
    }
    
    /**
     * Get stats by date
     */
    public function get_stats_by_date($request) {
        try {
            $date = sanitize_text_field($request->get_param('date'));
            
            if (empty($date)) {
                $date = date('Y-m-d');
            }
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return new WP_REST_Response(array(
                    'questions' => 0,
                    'users' => 0,
                    'found' => 0,
                    'error' => 'Invalid date format'
                ), 400);
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'ai_law_bot_chat_logs';
            
            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            
            if ($table_exists !== $table) {
                return new WP_REST_Response(array(
                    'questions' => 0,
                    'users' => 0,
                    'found' => 0
                ), 200);
            }
            
            // Get questions count for the date
            $questions = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s",
                $date
            )));
            
            // Get unique users count for the date
            $users = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT user_identifier) FROM $table WHERE DATE(created_at) = %s",
                $date
            )));
            
            // Get questions that found PDFs
            $found = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s AND pdfs_found = 1",
                $date
            )));
            
            return new WP_REST_Response(array(
                'questions' => $questions,
                'users' => $users,
                'found' => $found,
                'date' => $date
            ), 200);
            
        } catch (\Throwable $e) {
            return new WP_REST_Response(array(
                'questions' => 0,
                'users' => 0,
                'found' => 0,
                'error' => $e->getMessage()
            ), 500);
        }
    }
}
