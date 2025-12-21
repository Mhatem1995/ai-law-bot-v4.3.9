<?php
/**
 * REST API Endpoints - Safe Version with Links
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_REST_Endpoints {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        // Main ask endpoint
        register_rest_route('ai-law-bot/v1', '/ask', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_question'),
            'permission_callback' => array($this, 'check_permission'),
        ));
        
        // Test connection endpoint
        register_rest_route('ai-law-bot/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        // Debug endpoint
        register_rest_route('ai-law-bot/v1', '/debug', array(
            'methods' => 'GET',
            'callback' => array($this, 'debug_info'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
    }
    
    public function check_permission($request) {
        return true; // Public access - rate limiting handles abuse
    }
    
    /**
     * Main question handler - WRAPPED IN TRY-CATCH
     */
    public function handle_question($request) {
        try {
            $question = sanitize_text_field($request->get_param('question'));
            $session_id = sanitize_text_field($request->get_param('session_id'));
            
            // Validate question
            if (empty($question) || mb_strlen($question, 'UTF-8') < 3) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'السؤال قصير جداً'
                ), 400);
            }
            
            // Generate session ID if empty
            if (empty($session_id)) {
                $session_id = 'sess_' . time() . '_' . wp_rand(1000, 9999);
            }
            
            $user_identifier = $this->get_user_identifier();
            $ip_address = $this->get_client_ip();
            
            // Check rate limit - SAFE CALL
            $limit_check = $this->safe_check_limit($user_identifier);
            if (!$limit_check['allowed']) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => "لقد وصلت إلى الحد اليومي المسموح به من الأسئلة.\n\nللحصول على عدد غير محدود من الاستفسارات، يرجى التواصل مع مكتب المحاماة.",
                    'limit_reached' => true,
                    'remaining' => 0
                ), 429);
            }
            
            // Extract keywords
            $keywords = array();
            if (class_exists('AI_Law_Bot_Keyword_Extractor')) {
                $keywords = AI_Law_Bot_Keyword_Extractor::extract($question);
            }
            
            // Search for matching content
            $matched_content = array();
            if (class_exists('AI_Law_Bot_PDF_Search')) {
                $pdf_search = new AI_Law_Bot_PDF_Search();
                $search_results = $pdf_search->search($question, $keywords);
                
                if ($search_results['success'] && !empty($search_results['pdfs'])) {
                    $matched_content = $search_results['pdfs'];
                } else {
                    // Try broader search
                    $all_content = $pdf_search->get_all_content_for_semantic_search();
                    if (!empty($all_content)) {
                        $matched_content = array_slice($all_content, 0, 3);
                    }
                }
            }
            
            // Handle NO content found
            if (empty($matched_content)) {
                $this->log_missing_topic($question, $keywords, $ip_address, $session_id, $user_identifier);
                $this->safe_increment_count($user_identifier);
                
                return new WP_REST_Response(array(
                    'success' => true,
                    'message' => "لا توجد معلومات عن هذا الموضوع حالياً في موقعنا، وسيتم إضافتها قريباً.",
                    'pdfs' => array(),
                    'remaining' => $this->get_remaining($limit_check),
                    'session_id' => $session_id
                ), 200);
            }
            
            // Send to OpenAI
            if (!class_exists('AI_Law_Bot_OpenAI_Client')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'خطأ في تحميل OpenAI Client'
                ), 500);
            }
            
            $client = new AI_Law_Bot_OpenAI_Client();
            $ai_response = $client->ask($question, $matched_content, array());
            
            if (is_wp_error($ai_response)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'خطأ: ' . $ai_response->get_error_message()
                ), 500);
            }
            
            // Format response WITH LINKS
            $formatted_response = $this->format_response_with_links($ai_response['answer'], $matched_content);
            
            // Log success
            $this->log_chat_success($session_id, $user_identifier, $question, $keywords, $matched_content, $formatted_response, $ai_response, $ip_address);
            
            // Increment usage
            $this->safe_increment_count($user_identifier);
            
            // Build PDF list for response
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
                'remaining' => $this->get_remaining($limit_check),
                'session_id' => $session_id
            ), 200);
            
        } catch (Exception $e) {
            error_log('AI Law Bot Error: ' . $e->getMessage());
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'حدث خطأ. يرجى المحاولة مرة أخرى.'
            ), 500);
        }
    }
    
    /**
     * Format AI response with links - ALWAYS ADD LINKS
     */
    private function format_response_with_links($ai_text, $pdfs) {
        $formatted = "⚖️ " . $ai_text;
        
        // Always add links section if we have PDFs
        if (!empty($pdfs)) {
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
     * Safe rate limit check - won't crash
     */
    private function safe_check_limit($user_identifier) {
        try {
            if (class_exists('AI_Law_Bot_Rate_Limiter')) {
                return AI_Law_Bot_Rate_Limiter::check_limit($user_identifier);
            }
        } catch (Exception $e) {
            error_log('Rate limit check error: ' . $e->getMessage());
        }
        
        // Default: allow with 5 remaining
        return array('allowed' => true, 'remaining' => 5, 'limit' => 5);
    }
    
    /**
     * Safe increment count - won't crash
     */
    private function safe_increment_count($user_identifier) {
        try {
            if (class_exists('AI_Law_Bot_Rate_Limiter')) {
                AI_Law_Bot_Rate_Limiter::increment_count($user_identifier);
            }
        } catch (Exception $e) {
            error_log('Increment count error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get remaining count safely
     */
    private function get_remaining($limit_check) {
        if (isset($limit_check['remaining'])) {
            if ($limit_check['remaining'] === 'unlimited') {
                return 'unlimited';
            }
            return max(0, intval($limit_check['remaining']) - 1);
        }
        return 5;
    }
    
    /**
     * Log missing topic safely
     */
    private function log_missing_topic($question, $keywords, $ip, $session_id, $user_id) {
        try {
            if (class_exists('AI_Law_Bot_Database')) {
                AI_Law_Bot_Database::log_missing_topic($question, $keywords, $ip);
                AI_Law_Bot_Database::log_chat(array(
                    'session_id' => $session_id,
                    'user_identifier' => $user_id,
                    'question' => $question,
                    'keywords' => $keywords,
                    'pdfs_found' => false,
                    'ip_address' => $ip
                ));
            }
        } catch (Exception $e) {
            error_log('Log missing topic error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log successful chat safely
     */
    private function log_chat_success($session_id, $user_id, $question, $keywords, $pdfs, $response, $ai_response, $ip) {
        try {
            if (class_exists('AI_Law_Bot_Database')) {
                AI_Law_Bot_Database::log_chat(array(
                    'session_id' => $session_id,
                    'user_identifier' => $user_id,
                    'question' => $question,
                    'keywords' => $keywords,
                    'matched_pdfs' => $pdfs,
                    'pdfs_found' => true,
                    'ai_response' => $response,
                    'tokens_used' => isset($ai_response['tokens_used']) ? $ai_response['tokens_used'] : 0,
                    'model' => isset($ai_response['model']) ? $ai_response['model'] : '',
                    'ip_address' => $ip
                ));
                
                AI_Law_Bot_Database::save_conversation_message($session_id, $user_id, 'user', $question);
                AI_Law_Bot_Database::save_conversation_message($session_id, $user_id, 'assistant', strip_tags($response));
            }
        } catch (Exception $e) {
            error_log('Log chat error: ' . $e->getMessage());
        }
    }
    
    /**
     * Test OpenAI connection
     */
    public function test_connection($request) {
        try {
            if (class_exists('AI_Law_Bot_OpenAI_Client')) {
                $result = AI_Law_Bot_OpenAI_Client::test_connection();
                return new WP_REST_Response($result, $result['success'] ? 200 : 500);
            }
            return new WP_REST_Response(array('success' => false, 'message' => 'OpenAI Client not loaded'), 500);
        } catch (Exception $e) {
            return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500);
        }
    }
    
    /**
     * Debug info
     */
    public function debug_info($request) {
        try {
            global $wpdb;
            
            $api_key = get_option('ai_law_bot_openai_key', '');
            $pdf_count = 0;
            
            if (class_exists('AI_Law_Bot_PDF_Search')) {
                $pdf_count = AI_Law_Bot_PDF_Search::get_pdf_count();
            }
            
            return new WP_REST_Response(array(
                'api_key_exists' => !empty($api_key),
                'model' => get_option('ai_law_bot_model', 'gpt-4o'),
                'pdf_count' => $pdf_count,
                'premium_enabled' => get_option('ai_law_bot_enable_premium', 'no'),
                'daily_limit' => get_option('ai_law_bot_daily_limit', 5)
            ), 200);
        } catch (Exception $e) {
            return new WP_REST_Response(array('error' => $e->getMessage()), 500);
        }
    }
    
    private function get_user_identifier() {
        try {
            if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
                return 'user_' . get_current_user_id();
            }
        } catch (Exception $e) {
            // Ignore
        }
        
        $ip = $this->get_client_ip();
        return 'ip_' . md5($ip);
    }
    
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return sanitize_text_field(trim($ips[0]));
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
        return '127.0.0.1';
    }
}
