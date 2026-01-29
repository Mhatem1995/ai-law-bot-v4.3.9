<?php
/**
 * OpenAI API Client - Fixed Version
 * 
 * Simplified API key handling and better error reporting
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_OpenAI_Client {
    
    private $api_key;
    private $model;
    
    public function __construct() {
        $this->api_key = get_option('ai_law_bot_openai_key', '');
        $this->model = get_option('ai_law_bot_model', 'gpt-4o');
    }
    
    /**
     * Send request to OpenAI API for conversational response
     */
    public function ask($question, $matched_content = array(), $conversation_history = array()) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'لم يتم تكوين مفتاح OpenAI API. يرجى إدخاله في الإعدادات.');
        }
        
        // Build the system prompt
        $system_message = $this->build_system_prompt();
        
        // Build the user prompt with matched content
        $user_prompt = $this->build_user_prompt($question, $matched_content);
        
        // Build messages array
        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_message
            )
        );
        
        // Add conversation history (last 6 messages for context)
        if (!empty($conversation_history) && is_array($conversation_history)) {
            $conversation_history = array_slice($conversation_history, -6);
            foreach ($conversation_history as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = array(
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    );
                }
            }
        }
        
        // Add current question
        $messages[] = array(
            'role' => 'user',
            'content' => $user_prompt
        );
        
        return $this->call_api($messages, 1200, 0.7);
    }
    
    /**
     * Make API call to OpenAI
     */
    private function call_api($messages, $max_tokens = 1000, $temperature = 0.7) {
        $request_body = array(
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
        );
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 90,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($request_body),
        ));
        
        // Check for connection errors
        if (is_wp_error($response)) {
            return new WP_Error('connection_error', 'خطأ في الاتصال: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Handle different error codes
        if ($status_code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'خطأ غير معروف';
            
            if ($status_code === 401) {
                return new WP_Error('invalid_api_key', 'مفتاح API غير صالح. تأكد من صحة المفتاح.');
            } elseif ($status_code === 429) {
                return new WP_Error('rate_limit', 'تم تجاوز حد الاستخدام في OpenAI. انتظر قليلاً.');
            } elseif ($status_code === 500 || $status_code === 503) {
                return new WP_Error('server_error', 'خدمة OpenAI غير متاحة حالياً. حاول لاحقاً.');
            }
            
            return new WP_Error('api_error', 'خطأ من OpenAI: ' . $error_message);
        }
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'استجابة غير صالحة من OpenAI');
        }
        
        $answer = trim($data['choices'][0]['message']['content']);
        
        return array(
            'answer' => $answer,
            'tokens_used' => isset($data['usage']['total_tokens']) ? $data['usage']['total_tokens'] : 0,
            'model' => $this->model
        );
    }
    
    /**
     * Build the system prompt for conversational legal assistant
     */
    private function build_system_prompt() {
        return 'أنت مساعد قانوني محادث ذكي متخصص حصرياً في القانون الكويتي.
اسمك "المساعد القانوني" وتعمل لصالح مكتب مسفر للمحاماة.

🎯 هويتك:
• محامٍ كويتي خبير ودود
• تشرح القانون ببساطة للجميع
• تتصرف مثل ChatGPT: ذكي، محادث، إنساني

💬 أسلوب الرد:
• اللغة العربية فقط
• ابدأ بتحية قصيرة
• اشرح الموضوع بتفصيل (200-300 كلمة)
• اذكر الروابط في النهاية فقط

⚠️ قواعد صارمة:
• لا تخترع معلومات غير موجودة
• لا تذكر روابط خارجية أبداً
• إذا لم تجد معلومات، قل ذلك بوضوح
• إذا كان السؤال غامضاً، اطلب توضيحاً

📋 هيكل الإجابة:
1. تحية قصيرة
2. شرح تفصيلي للموضوع
3. نقاط مهمة إن وجدت
4. سؤال توضيحي إذا لزم
5. الروابط في النهاية فقط';
    }
    
    /**
     * Build the user prompt with matched content
     */
    private function build_user_prompt($question, $matched_content) {
        $prompt = "سؤال المستخدم: {$question}\n\n";
        
        if (!empty($matched_content)) {
            $prompt .= "📚 المحتوى القانوني المتاح:\n";
            $prompt .= "═══════════════════════════\n\n";
            
            foreach ($matched_content as $index => $item) {
                $num = $index + 1;
                $title = $item['pdf_title'] ?? $item['title'] ?? 'بدون عنوان';
                $link = $item['post_link'] ?? '';
                $text = $item['text_content'] ?? '';
                
                $prompt .= "【{$num}】 {$title}\n";
                
                if (!empty($text)) {
                    $text = mb_substr($text, 0, 800, 'UTF-8');
                    $prompt .= "المحتوى: {$text}\n";
                }
                
                if (!empty($link)) {
                    $prompt .= "الرابط: {$link}\n";
                }
                $prompt .= "---\n";
            }
            
            $prompt .= "\n📝 التعليمات:\n";
            $prompt .= "1. اشرح الموضوع بالتفصيل بناءً على المحتوى أعلاه\n";
            $prompt .= "2. كن محادثاً وودوداً\n";
            $prompt .= "3. الشرح أولاً، الروابط في النهاية فقط\n";
        } else {
            $prompt .= "⚠️ لا يوجد محتوى متاح عن هذا الموضوع.\n";
            $prompt .= "قل للمستخدم: لا توجد معلومات عن هذا الموضوع حالياً في موقعنا، وسيتم إضافتها قريباً.\n";
        }
        
        return $prompt;
    }
    
    /**
     * Test API connection
     */
    public static function test_connection() {
        $api_key = get_option('ai_law_bot_openai_key', '');
        $model = get_option('ai_law_bot_model', 'gpt-4o');
        
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => 'مفتاح API غير موجود. يرجى إدخاله في الإعدادات.'
            );
        }
        
        // Validate API key format
        if (strpos($api_key, 'sk-') !== 0) {
            return array(
                'success' => false,
                'message' => 'صيغة مفتاح API غير صحيحة. يجب أن يبدأ بـ sk-'
            );
        }
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => $model,
                'messages' => array(
                    array('role' => 'user', 'content' => 'قل: مرحباً')
                ),
                'max_tokens' => 10,
            )),
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'خطأ في الاتصال: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 200) {
            $ai_response = $body['choices'][0]['message']['content'] ?? '';
            return array(
                'success' => true,
                'message' => 'تم الاتصال بنجاح! النموذج: ' . $model,
                'test_response' => $ai_response
            );
        }
        
        // Handle errors
        $error_message = $body['error']['message'] ?? 'خطأ غير معروف';
        
        if ($status_code === 401) {
            return array(
                'success' => false,
                'message' => 'مفتاح API غير صالح أو منتهي الصلاحية'
            );
        } elseif ($status_code === 404) {
            return array(
                'success' => false,
                'message' => 'النموذج ' . $model . ' غير متاح. جرب نموذجاً آخر.'
            );
        }
        
        return array(
            'success' => false,
            'message' => 'فشل الاتصال: ' . $error_message
        );
    }
}
