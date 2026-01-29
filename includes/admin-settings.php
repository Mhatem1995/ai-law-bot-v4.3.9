<?php
/**
 * Admin Settings Page - Modern UI
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Admin_Settings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function add_settings_page() {
        add_options_page(
            'AI Law Bot Settings',
            'AI Law Bot',
            'manage_options',
            'ai-law-bot-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('ai_law_bot_settings', 'ai_law_bot_openai_key', 'sanitize_text_field');
        register_setting('ai_law_bot_settings', 'ai_law_bot_auth_token', 'sanitize_text_field');
        register_setting('ai_law_bot_settings', 'ai_law_bot_model', 'sanitize_text_field');
        register_setting('ai_law_bot_settings', 'ai_law_bot_daily_limit', 'absint');
        register_setting('ai_law_bot_settings', 'ai_law_bot_enable_premium', 'sanitize_text_field');
        register_setting('ai_law_bot_settings', 'ai_law_bot_premium_emails', 'sanitize_textarea_field');
        register_setting('ai_law_bot_settings', 'ai_law_bot_show_everywhere', 'sanitize_text_field');
        register_setting('ai_law_bot_settings', 'ai_law_bot_min_relevance_score', 'absint');
        register_setting('ai_law_bot_settings', 'ai_law_bot_max_results', 'absint');
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        
        // Get current settings
        $api_key = get_option('ai_law_bot_openai_key', '');
        $model = get_option('ai_law_bot_model', 'gpt-4o');
        $daily_limit = get_option('ai_law_bot_daily_limit', 5);
        $premium_enabled = get_option('ai_law_bot_enable_premium', 'no') === 'yes';
        $premium_emails_raw = get_option('ai_law_bot_premium_emails', '');
        $show_everywhere = get_option('ai_law_bot_show_everywhere', 'yes') === 'yes';
        $min_score = get_option('ai_law_bot_min_relevance_score', 30);
        $max_results = get_option('ai_law_bot_max_results', 5);
        
        // Parse premium emails
        $premium_emails = array();
        if (!empty($premium_emails_raw)) {
            $premium_emails = array_filter(array_map('trim', preg_split('/[\s,\n\r]+/', strtolower($premium_emails_raw))));
        }
        
        // Get WordPress users for selector
        $all_users = get_users(array('number' => 200, 'orderby' => 'display_name'));
        
        // Get PDF count
        $pdf_count = 0;
        if (class_exists('AI_Law_Bot_PDF_Search')) {
            $pdf_count = AI_Law_Bot_PDF_Search::get_pdf_count();
        }
        
        $has_api_key = !empty($api_key) && strpos($api_key, 'sk-') === 0;
        
        // Get usage statistics from database
        global $wpdb;
        $chat_table = $wpdb->prefix . 'ai_law_bot_chat_logs';
        
        // Today's usage
        $today_count = 0;
        $today_users = 0;
        $week_count = 0;
        $total_count = 0;
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $chat_table));
            
            if ($table_exists === $chat_table) {
                // Today's questions
                $today_count = intval($wpdb->get_var(
                    "SELECT COUNT(*) FROM $chat_table WHERE DATE(created_at) = CURDATE()"
                ));
                
                // Today's unique users
                $today_users = intval($wpdb->get_var(
                    "SELECT COUNT(DISTINCT user_identifier) FROM $chat_table WHERE DATE(created_at) = CURDATE()"
                ));
                
                // This week's questions
                $week_count = intval($wpdb->get_var(
                    "SELECT COUNT(*) FROM $chat_table WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
                ));
                
                // Total questions
                $total_count = intval($wpdb->get_var(
                    "SELECT COUNT(*) FROM $chat_table"
                ));
            }
        } catch (Exception $e) {
            // Ignore errors, keep defaults
        }
        ?>
        <style>
            .ailb-wrap { max-width: 900px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .ailb-header { background: linear-gradient(135deg, #1a5490 0%, #2d7ab8 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 25px; }
            .ailb-header h1 { margin: 0 0 10px 0; font-size: 28px; }
            .ailb-header p { margin: 0; opacity: 0.9; }
            .ailb-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px; overflow: hidden; }
            .ailb-card-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; display: flex; align-items: center; gap: 10px; }
            .ailb-card-header h2 { margin: 0; font-size: 16px; color: #1a5490; }
            .ailb-card-header .dashicons { color: #1a5490; }
            .ailb-card-body { padding: 20px; }
            .ailb-field { margin-bottom: 20px; }
            .ailb-field:last-child { margin-bottom: 0; }
            .ailb-field label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
            .ailb-field input[type="text"], .ailb-field input[type="number"], .ailb-field select, .ailb-field textarea { 
                width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; transition: border-color 0.2s; 
            }
            .ailb-field input:focus, .ailb-field select:focus, .ailb-field textarea:focus { border-color: #1a5490; outline: none; box-shadow: 0 0 0 3px rgba(26,84,144,0.1); }
            .ailb-field .description { color: #666; font-size: 13px; margin-top: 6px; }
            .ailb-field input[type="text"].mono { font-family: 'Monaco', 'Consolas', monospace; }
            .ailb-row { display: flex; gap: 20px; }
            .ailb-row > div { flex: 1; }
            .ailb-checkbox { display: flex; align-items: center; gap: 10px; padding: 12px 15px; background: #f8f9fa; border-radius: 8px; cursor: pointer; }
            .ailb-checkbox input { width: 18px; height: 18px; }
            .ailb-checkbox span { font-weight: 500; }
            .ailb-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 13px; font-weight: 500; }
            .ailb-status.success { background: #d4edda; color: #155724; }
            .ailb-status.error { background: #f8d7da; color: #721c24; }
            .ailb-status.warning { background: #fff3cd; color: #856404; }
            .ailb-premium-section { background: linear-gradient(135deg, #fef9e7 0%, #fdebd0 100%); border: 1px solid #f9e79f; border-radius: 10px; padding: 20px; margin-top: 15px; }
            .ailb-premium-section h3 { margin: 0 0 15px 0; color: #9a7b1a; font-size: 15px; display: flex; align-items: center; gap: 8px; }
            .ailb-user-selector { display: flex; gap: 10px; margin-bottom: 15px; }
            .ailb-user-selector select { flex: 1; }
            .ailb-user-selector button { white-space: nowrap; }
            .ailb-user-list { background: white; border-radius: 8px; max-height: 200px; overflow-y: auto; }
            .ailb-user-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border-bottom: 1px solid #eee; }
            .ailb-user-item:last-child { border-bottom: none; }
            .ailb-user-item .name { font-weight: 500; }
            .ailb-user-item .email { color: #666; font-size: 13px; }
            .ailb-user-item .remove { color: #dc3545; cursor: pointer; font-size: 13px; }
            .ailb-user-item .remove:hover { text-decoration: underline; }
            .ailb-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
            .ailb-stats-usage { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
            .ailb-stat.highlight { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); }
            .ailb-stat.highlight .number { color: #2e7d32; }
            .ailb-stat { text-align: center; padding: 20px; background: #f8f9fa; border-radius: 10px; }
            .ailb-stat .number { font-size: 32px; font-weight: 700; color: #1a5490; }
            .ailb-stat .label { color: #666; font-size: 13px; margin-top: 5px; }
            .ailb-btn { background: #1a5490; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 15px; font-weight: 500; cursor: pointer; }
            .ailb-btn:hover { background: #2d7ab8; }
            .ailb-btn-secondary { background: #6c757d; }
            .ailb-btn-secondary:hover { background: #5a6268; }
            .ailb-btn-success { background: #28a745; }
            .ailb-btn-success:hover { background: #218838; }
            .ailb-test-result { margin-top: 10px; padding: 10px 15px; border-radius: 8px; display: none; }
            .ailb-test-result.show { display: block; }
            @media (max-width: 768px) {
                .ailb-row { flex-direction: column; }
                .ailb-stats { grid-template-columns: 1fr; }
                .ailb-stats-usage { grid-template-columns: repeat(2, 1fr); }
            }
        </style>
        
        <div class="ailb-wrap" dir="rtl">
            <!-- Header -->
            <div class="ailb-header">
                <h1>⚖️ AI Law Bot</h1>
                <p>المساعد القانوني الذكي - الإصدار <?php echo AI_LAW_BOT_VERSION; ?></p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('ai_law_bot_settings'); ?>
                
                <!-- Status Cards -->
                <div class="ailb-stats">
                    <div class="ailb-stat">
                        <div class="number"><?php echo $has_api_key ? '✓' : '✗'; ?></div>
                        <div class="label">حالة API</div>
                    </div>
                    <div class="ailb-stat">
                        <div class="number"><?php echo intval($pdf_count); ?></div>
                        <div class="label">ملفات PDF</div>
                    </div>
                    <div class="ailb-stat">
                        <div class="number"><?php echo count($premium_emails); ?></div>
                        <div class="label">مستخدمون مميزون</div>
                    </div>
                </div>
                
                <!-- Usage Statistics -->
                <div class="ailb-stats-usage">
                    <div class="ailb-stat highlight">
                        <div class="number"><?php echo $today_count; ?></div>
                        <div class="label">📊 أسئلة اليوم</div>
                    </div>
                    <div class="ailb-stat highlight">
                        <div class="number"><?php echo $today_users; ?></div>
                        <div class="label">👥 زوار اليوم</div>
                    </div>
                    <div class="ailb-stat">
                        <div class="number"><?php echo $week_count; ?></div>
                        <div class="label">📅 أسئلة الأسبوع</div>
                    </div>
                    <div class="ailb-stat">
                        <div class="number"><?php echo $total_count; ?></div>
                        <div class="label">📈 إجمالي الأسئلة</div>
                    </div>
                </div>
                
                <!-- Date Picker for Questions -->
                <div class="ailb-card">
                    <div class="ailb-card-header">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <h2>البحث حسب التاريخ</h2>
                    </div>
                    <div class="ailb-card-body">
                        <div class="ailb-row" style="align-items: flex-end;">
                            <div class="ailb-field" style="flex: 1;">
                                <label>📅 اختر التاريخ</label>
                                <input type="date" id="ailb-date-picker" value="<?php echo date('Y-m-d'); ?>" style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; width: 100%;">
                            </div>
                            <div class="ailb-field" style="flex: 0;">
                                <button type="button" class="ailb-btn" onclick="searchByDate()">🔍 بحث</button>
                            </div>
                        </div>
                        <div id="date-search-results" style="margin-top: 15px; display: none;">
                            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                <div style="background: #e3f2fd; padding: 15px 20px; border-radius: 8px; flex: 1; min-width: 150px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: #1565c0;" id="date-questions">0</div>
                                    <div style="color: #666; font-size: 13px;">عدد الأسئلة</div>
                                </div>
                                <div style="background: #e8f5e9; padding: 15px 20px; border-radius: 8px; flex: 1; min-width: 150px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: #2e7d32;" id="date-users">0</div>
                                    <div style="color: #666; font-size: 13px;">عدد الزوار</div>
                                </div>
                                <div style="background: #fff3e0; padding: 15px 20px; border-radius: 8px; flex: 1; min-width: 150px; text-align: center;">
                                    <div style="font-size: 28px; font-weight: 700; color: #e65100;" id="date-found">0</div>
                                    <div style="color: #666; font-size: 13px;">وجد إجابة</div>
                                </div>
                            </div>
                            <div id="date-selected" style="margin-top: 10px; color: #666; font-size: 13px; text-align: center;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- API Settings -->
                <div class="ailb-card">
                    <div class="ailb-card-header">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2>إعدادات OpenAI</h2>
                    </div>
                    <div class="ailb-card-body">
                        <div class="ailb-row">
                            <div class="ailb-field">
                                <label>🔑 API Key</label>
                                <input type="text" name="ai_law_bot_openai_key" class="mono"
                                       value="<?php echo esc_attr($api_key); ?>" 
                                       placeholder="sk-...">
                                <p class="description">
                                    احصل عليه من <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Dashboard</a>
                                </p>
                            </div>
                            <div class="ailb-field">
                                <label>🤖 النموذج</label>
                                <select name="ai_law_bot_model">
                                    <option value="gpt-4o" <?php selected($model, 'gpt-4o'); ?>>GPT-4o (الأفضل)</option>
                                    <option value="gpt-4o-mini" <?php selected($model, 'gpt-4o-mini'); ?>>GPT-4o Mini (أرخص)</option>
                                    <option value="gpt-4-turbo" <?php selected($model, 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                                    <option value="gpt-3.5-turbo" <?php selected($model, 'gpt-3.5-turbo'); ?>>GPT-3.5 (الأرخص)</option>
                                </select>
                            </div>
                        </div>
                        
                        <?php if ($has_api_key): ?>
                        <div style="margin-top: 15px;">
                            <button type="button" class="ailb-btn ailb-btn-secondary" onclick="testAPIConnection()">
                                🔌 اختبار الاتصال
                            </button>
                            <div id="api-test-result" class="ailb-test-result"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Usage Limits -->
                <div class="ailb-card">
                    <div class="ailb-card-header">
                        <span class="dashicons dashicons-clock"></span>
                        <h2>حدود الاستخدام</h2>
                    </div>
                    <div class="ailb-card-body">
                        <div class="ailb-row">
                            <div class="ailb-field">
                                <label>📊 الحد اليومي للأسئلة</label>
                                <input type="number" name="ai_law_bot_daily_limit" 
                                       value="<?php echo intval($daily_limit); ?>" 
                                       min="1" max="100">
                                <p class="description">عدد الأسئلة المجانية يومياً لكل مستخدم</p>
                            </div>
                            <div class="ailb-field">
                                <label>&nbsp;</label>
                                <label class="ailb-checkbox">
                                    <input type="checkbox" name="ai_law_bot_enable_premium" value="yes" 
                                           <?php checked($premium_enabled); ?> id="enable-premium">
                                    <span>⭐ تفعيل المستخدمين المميزين (أسئلة غير محدودة)</span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Premium Users Section -->
                        <div class="ailb-premium-section" id="premium-section" style="<?php echo $premium_enabled ? '' : 'display:none;'; ?>">
                            <h3>⭐ إدارة المستخدمين المميزين</h3>
                            
                            <div class="ailb-user-selector">
                                <select id="user-selector">
                                    <option value="">-- اختر مستخدم لإضافته --</option>
                                    <?php foreach ($all_users as $user): 
                                        $email_lower = strtolower($user->user_email);
                                        if (!in_array($email_lower, $premium_emails)):
                                    ?>
                                        <option value="<?php echo esc_attr($user->user_email); ?>">
                                            <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                                        </option>
                                    <?php endif; endforeach; ?>
                                </select>
                                <button type="button" class="ailb-btn ailb-btn-success" onclick="addPremiumUser()">
                                    + إضافة
                                </button>
                            </div>
                            
                            <div class="ailb-user-list" id="premium-list">
                                <?php if (empty($premium_emails)): ?>
                                    <div class="ailb-user-item" id="no-users-msg">
                                        <span style="color:#666;">لا يوجد مستخدمون مميزون</span>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($premium_emails as $email): 
                                        $user = get_user_by('email', $email);
                                        $name = $user ? $user->display_name : $email;
                                    ?>
                                    <div class="ailb-user-item" data-email="<?php echo esc_attr($email); ?>">
                                        <div>
                                            <div class="name"><?php echo esc_html($name); ?></div>
                                            <div class="email"><?php echo esc_html($email); ?></div>
                                        </div>
                                        <span class="remove" onclick="removePremiumUser('<?php echo esc_js($email); ?>')">إزالة ❌</span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <textarea name="ai_law_bot_premium_emails" id="premium-emails" style="display:none;"><?php echo esc_textarea($premium_emails_raw); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Search Settings -->
                <div class="ailb-card">
                    <div class="ailb-card-header">
                        <span class="dashicons dashicons-search"></span>
                        <h2>إعدادات البحث</h2>
                    </div>
                    <div class="ailb-card-body">
                        <div class="ailb-row">
                            <div class="ailb-field">
                                <label>🎯 الحد الأدنى للتطابق</label>
                                <input type="number" name="ai_law_bot_min_relevance_score" 
                                       value="<?php echo intval($min_score); ?>" 
                                       min="10" max="100">
                                <p class="description">قيمة أقل = نتائج أكثر (الافتراضي: 30)</p>
                            </div>
                            <div class="ailb-field">
                                <label>📄 أقصى عدد نتائج</label>
                                <input type="number" name="ai_law_bot_max_results" 
                                       value="<?php echo intval($max_results); ?>" 
                                       min="1" max="10">
                                <p class="description">عدد ملفات PDF في كل إجابة</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Display Settings -->
                <div class="ailb-card">
                    <div class="ailb-card-header">
                        <span class="dashicons dashicons-visibility"></span>
                        <h2>إعدادات العرض</h2>
                    </div>
                    <div class="ailb-card-body">
                        <label class="ailb-checkbox">
                            <input type="checkbox" name="ai_law_bot_show_everywhere" value="yes" 
                                   <?php checked($show_everywhere); ?>>
                            <span>💬 عرض أيقونة الدردشة في جميع صفحات الموقع</span>
                        </label>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="ailb-btn" style="padding: 15px 50px; font-size: 16px;">
                        💾 حفظ الإعدادات
                    </button>
                </div>
            </form>
        </div>
        
        <script>
        // Toggle premium section
        document.getElementById('enable-premium').addEventListener('change', function() {
            document.getElementById('premium-section').style.display = this.checked ? 'block' : 'none';
        });
        
        // Add premium user
        function addPremiumUser() {
            var selector = document.getElementById('user-selector');
            var email = selector.value;
            if (!email) {
                alert('اختر مستخدماً أولاً');
                return;
            }
            
            var textarea = document.getElementById('premium-emails');
            var currentEmails = textarea.value.toLowerCase();
            
            if (currentEmails.indexOf(email.toLowerCase()) !== -1) {
                alert('هذا المستخدم موجود مسبقاً');
                return;
            }
            
            // Add to textarea
            textarea.value = textarea.value.trim();
            if (textarea.value !== '') {
                textarea.value += '\n';
            }
            textarea.value += email;
            
            // Add to list UI
            var name = selector.options[selector.selectedIndex].text.split(' (')[0];
            var list = document.getElementById('premium-list');
            var noMsg = document.getElementById('no-users-msg');
            if (noMsg) noMsg.remove();
            
            var item = document.createElement('div');
            item.className = 'ailb-user-item';
            item.setAttribute('data-email', email);
            item.innerHTML = '<div><div class="name">' + name + '</div><div class="email">' + email + '</div></div>' +
                            '<span class="remove" onclick="removePremiumUser(\'' + email + '\')">إزالة ❌</span>';
            list.appendChild(item);
            
            // Remove from selector
            selector.remove(selector.selectedIndex);
            selector.value = '';
        }
        
        // Remove premium user
        function removePremiumUser(email) {
            var textarea = document.getElementById('premium-emails');
            var lines = textarea.value.split('\n');
            var newLines = lines.filter(function(line) {
                return line.trim().toLowerCase() !== email.toLowerCase();
            });
            textarea.value = newLines.join('\n');
            
            // Remove from UI
            var item = document.querySelector('.ailb-user-item[data-email="' + email + '"]');
            if (item) item.remove();
            
            // Show no users message if empty
            var list = document.getElementById('premium-list');
            if (list.children.length === 0) {
                list.innerHTML = '<div class="ailb-user-item" id="no-users-msg"><span style="color:#666;">لا يوجد مستخدمون مميزون</span></div>';
            }
        }
        
        // Test API connection
        function testAPIConnection() {
            var result = document.getElementById('api-test-result');
            result.className = 'ailb-test-result show';
            result.style.background = '#e3f2fd';
            result.innerHTML = '⏳ جاري الاختبار...';
            
            fetch('<?php echo rest_url('ai-law-bot/v1/test'); ?>', {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    result.style.background = '#d4edda';
                    result.innerHTML = '✅ ' + data.message;
                } else {
                    result.style.background = '#f8d7da';
                    result.innerHTML = '❌ ' + data.message;
                }
            })
            .catch(error => {
                result.style.background = '#f8d7da';
                result.innerHTML = '❌ خطأ في الاتصال';
            });
        }
        
        // Search by date
        function searchByDate() {
            var datePicker = document.getElementById('ailb-date-picker');
            var selectedDate = datePicker.value;
            var resultsDiv = document.getElementById('date-search-results');
            
            if (!selectedDate) {
                alert('اختر تاريخاً أولاً');
                return;
            }
            
            resultsDiv.style.display = 'block';
            document.getElementById('date-questions').textContent = '...';
            document.getElementById('date-users').textContent = '...';
            document.getElementById('date-found').textContent = '...';
            
            fetch('<?php echo rest_url('ai-law-bot/v1/stats-by-date'); ?>?date=' + selectedDate, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('date-questions').textContent = data.questions || 0;
                document.getElementById('date-users').textContent = data.users || 0;
                document.getElementById('date-found').textContent = data.found || 0;
                document.getElementById('date-selected').textContent = '📅 ' + selectedDate;
            })
            .catch(error => {
                document.getElementById('date-questions').textContent = '0';
                document.getElementById('date-users').textContent = '0';
                document.getElementById('date-found').textContent = '0';
                document.getElementById('date-selected').textContent = '❌ خطأ في الاتصال';
            });
        }
        </script>
        <?php
    }
    
    public static function get_decrypted_api_key() {
        return get_option('ai_law_bot_openai_key', '');
    }
}
