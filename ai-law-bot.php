<?php
/**
 * Plugin Name: AI Law Bot for Mesfer Law
 * Plugin URI: https://mesferlaw.com
 * Description: مساعد قانوني محادث ذكي
 * Version: 4.3.6
 * Author: Marwan Hatem Mohamed
 * Text Domain: ai-law-bot
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI_LAW_BOT_VERSION', '4.4.0');
define('AI_LAW_BOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_LAW_BOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AI_LAW_BOT_DB_VERSION', '4.0.0');

// Include files
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/database.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/admin-settings.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/admin-missing-topics.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/admin-learning.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/rest-endpoints.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/openai-client.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/rate-limiter.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/pdf-search.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/keyword-extractor.php';
require_once AI_LAW_BOT_PLUGIN_DIR . 'includes/learning-engine.php';

class AI_Law_Bot {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'check_database'));
        
        if (is_admin()) {
            new AI_Law_Bot_Admin_Settings();
            new AI_Law_Bot_Missing_Topics();
            new AI_Law_Bot_Learning_Admin();
        }
        
        new AI_Law_Bot_REST_Endpoints();
        
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_shortcode('ai_law_bot', array($this, 'render_chat_widget'));
        add_action('wp_footer', array($this, 'maybe_add_chat_widget'));
    }
    
    public function check_database() {
        $installed = get_option('ai_law_bot_db_version', '0');
        if (version_compare($installed, AI_LAW_BOT_DB_VERSION, '<')) {
            AI_Law_Bot_Database::create_tables();
            update_option('ai_law_bot_db_version', AI_LAW_BOT_DB_VERSION);
        }
    }
    
    public function enqueue_assets() {
        if (!$this->should_show_widget()) return;
        
        wp_enqueue_style('ai-law-bot-widget', AI_LAW_BOT_PLUGIN_URL . 'assets/css/chat-widget.css', array(), AI_LAW_BOT_VERSION);
        wp_enqueue_script('ai-law-bot-widget', AI_LAW_BOT_PLUGIN_URL . 'assets/js/chat-widget.js', array('jquery'), AI_LAW_BOT_VERSION, true);
        
        wp_localize_script('ai-law-bot-widget', 'aiLawBotConfig', array(
            'apiUrl' => rest_url('ai-law-bot/v1/ask'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => array(
                'placeholder' => 'اكتب سؤالك القانوني...',
                'send' => 'إرسال',
                'thinking' => 'جارٍ التحليل...',
                'error' => 'حدث خطأ',
                'limitReached' => 'وصلت للحد اليومي',
                'chatTitle' => 'مساعد قانوني ذكي',
                'minimize' => 'تصغير',
                'close' => 'إغلاق'
            )
        ));
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'ai-law-bot') === false) return;
        wp_enqueue_style('ai-law-bot-admin', AI_LAW_BOT_PLUGIN_URL . 'assets/css/admin-style.css', array(), AI_LAW_BOT_VERSION);
    }
    
    public function render_chat_widget($atts) {
        if (!$this->should_show_widget()) return '';
        return '<div id="ai-law-bot-container"></div>';
    }
    
    public function maybe_add_chat_widget() {
        if (get_option('ai_law_bot_show_everywhere', 'yes') === 'yes' && $this->should_show_widget()) {
            echo '<div id="ai-law-bot-container"></div>';
        }
    }
    
    private function should_show_widget() {
        return !empty(get_option('ai_law_bot_openai_key'));
    }
}

function ai_law_bot_init() {
    return AI_Law_Bot::get_instance();
}
add_action('plugins_loaded', 'ai_law_bot_init');

register_activation_hook(__FILE__, function() {
    add_option('ai_law_bot_daily_limit', 5);
    add_option('ai_law_bot_model', 'gpt-4o');
    add_option('ai_law_bot_show_everywhere', 'yes');
    add_option('ai_law_bot_min_relevance_score', 30);
    add_option('ai_law_bot_max_results', 5);
    AI_Law_Bot_Database::create_tables();
    update_option('ai_law_bot_db_version', AI_LAW_BOT_DB_VERSION);
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
