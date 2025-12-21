<?php
/**
 * Admin Page - Missing Topics
 * 
 * Shows questions that had no matching PDFs
 * Helps admin know what content to add
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Missing_Topics {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_page'));
        add_action('admin_post_ai_law_bot_mark_handled', array($this, 'mark_topic_handled'));
        add_action('admin_post_ai_law_bot_delete_topic', array($this, 'delete_topic'));
    }
    
    public function add_admin_page() {
        add_menu_page(
            'المساعد القانوني',
            'المساعد القانوني',
            'manage_options',
            'ai-law-bot-missing-topics',
            array($this, 'render_page'),
            'dashicons-businessman',
            30
        );
        
        add_submenu_page(
            'ai-law-bot-missing-topics',
            'مواضيع مفقودة',
            'مواضيع مفقودة',
            'manage_options',
            'ai-law-bot-missing-topics',
            array($this, 'render_page')
        );
    }
    
    public function render_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_law_bot_missing_topics';
        
        // Handle bulk actions
        if (isset($_POST['bulk_action']) && isset($_POST['topic_ids']) && check_admin_referer('ai_law_bot_bulk_action')) {
            $action = sanitize_text_field($_POST['bulk_action']);
            $topic_ids = array_map('intval', $_POST['topic_ids']);
            
            if ($action === 'mark_handled') {
                foreach ($topic_ids as $id) {
                    $wpdb->update($table_name, array('handled' => 1), array('id' => $id), array('%d'), array('%d'));
                }
                echo '<div class="notice notice-success"><p>تم تحديث المواضيع المحددة.</p></div>';
            } elseif ($action === 'delete') {
                foreach ($topic_ids as $id) {
                    $wpdb->delete($table_name, array('id' => $id), array('%d'));
                }
                echo '<div class="notice notice-success"><p>تم حذف المواضيع المحددة.</p></div>';
            }
        }
        
        // Get filter
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'pending';
        
        // Get topics
        $where = $filter === 'handled' ? 'handled = 1' : 'handled = 0';
        $topics = $wpdb->get_results("SELECT * FROM $table_name WHERE $where ORDER BY asked_count DESC, last_asked_at DESC");
        
        // Get counts
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE handled = 0");
        $handled_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE handled = 1");
        ?>
        <div class="wrap" dir="rtl">
            <h1>📋 مواضيع قانونية مفقودة</h1>
            <p class="description">
                هذه قائمة بالأسئلة التي طرحها المستخدمون ولم تجد ملفات PDF متطابقة في حقل <code>_cpp_pdf_files</code>.
                <br>استخدم هذه القائمة لإضافة محتوى جديد يغطي هذه المواضيع.
            </p>
            
            <!-- Filter Tabs -->
            <ul class="subsubsub" style="float: right;">
                <li>
                    <a href="?page=ai-law-bot-missing-topics&filter=pending" <?php echo $filter === 'pending' ? 'class="current"' : ''; ?>>
                        قيد الانتظار <span class="count">(<?php echo $pending_count; ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="?page=ai-law-bot-missing-topics&filter=handled" <?php echo $filter === 'handled' ? 'class="current"' : ''; ?>>
                        تم التعامل معه <span class="count">(<?php echo $handled_count; ?>)</span>
                    </a>
                </li>
            </ul>
            
            <div style="clear: both;"></div>
            
            <?php if (empty($topics)): ?>
                <div class="notice notice-info">
                    <p>
                        <?php if ($filter === 'pending'): ?>
                            🎉 لا توجد مواضيع قيد الانتظار حالياً.
                        <?php else: ?>
                            لا توجد مواضيع تم التعامل معها.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('ai_law_bot_bulk_action'); ?>
                    
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <select name="bulk_action">
                                <option value="">إجراءات جماعية</option>
                                <?php if ($filter === 'pending'): ?>
                                    <option value="mark_handled">وضع علامة "تم التعامل"</option>
                                <?php endif; ?>
                                <option value="delete">حذف</option>
                            </select>
                            <input type="submit" class="button action" value="تطبيق">
                        </div>
                    </div>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="check-column">
                                    <input type="checkbox" id="select-all">
                                </td>
                                <th>السؤال</th>
                                <th style="width: 100px;">الكلمات المفتاحية</th>
                                <th style="width: 80px;">عدد الطلبات</th>
                                <th style="width: 150px;">آخر طلب</th>
                                <th style="width: 150px;">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topics as $topic): ?>
                                <?php
                                $keywords = json_decode($topic->extracted_keywords, true);
                                $keywords_str = is_array($keywords) ? implode('، ', $keywords) : '';
                                ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox" name="topic_ids[]" value="<?php echo $topic->id; ?>" class="topic-checkbox">
                                    </th>
                                    <td>
                                        <strong><?php echo esc_html($topic->question); ?></strong>
                                        <?php if ($topic->handled): ?>
                                            <span style="color: green;">✓</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small style="color: #666;"><?php echo esc_html($keywords_str); ?></small>
                                    </td>
                                    <td>
                                        <span style="background: <?php echo $topic->asked_count > 3 ? '#ffc107' : '#e9ecef'; ?>; padding: 2px 8px; border-radius: 3px;">
                                            <?php echo intval($topic->asked_count); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date_i18n('Y-m-d H:i', strtotime($topic->last_asked_at)); ?></td>
                                    <td>
                                        <?php if (!$topic->handled): ?>
                                            <a href="<?php echo admin_url('admin-post.php?action=ai_law_bot_mark_handled&id=' . $topic->id . '&_wpnonce=' . wp_create_nonce('mark_handled_' . $topic->id)); ?>" 
                                               class="button button-small">
                                                ✓ تم
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo admin_url('admin-post.php?action=ai_law_bot_delete_topic&id=' . $topic->id . '&_wpnonce=' . wp_create_nonce('delete_topic_' . $topic->id)); ?>" 
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('هل أنت متأكد من الحذف؟');">
                                            حذف
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
            
            <div style="margin-top: 30px; padding: 20px; background: #f0f0f1; border-right: 4px solid #2271b1;">
                <h3>💡 كيف تستفيد من هذه القائمة؟</h3>
                <ol>
                    <li>راجع الأسئلة الأكثر تكراراً (عدد الطلبات الأعلى)</li>
                    <li>أضف ملفات PDF تغطي هذه المواضيع في حقل <code>_cpp_pdf_files</code></li>
                    <li>بعد إضافة المحتوى، ضع علامة "تم التعامل"</li>
                    <li>المساعد الذكي سيجد المحتوى الجديد تلقائياً</li>
                </ol>
            </div>
            
            <script>
                jQuery(document).ready(function($) {
                    $('#select-all').on('change', function() {
                        $('.topic-checkbox').prop('checked', $(this).prop('checked'));
                    });
                });
            </script>
            
            <style>
                .wrap[dir="rtl"] table { direction: rtl; text-align: right; }
                .wrap[dir="rtl"] .tablenav .alignleft { float: right !important; }
                .wrap[dir="rtl"] .subsubsub { float: right; }
            </style>
        </div>
        <?php
    }
    
    public function mark_topic_handled() {
        if (!current_user_can('manage_options')) {
            wp_die('غير مصرح');
        }
        
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        check_admin_referer('mark_handled_' . $id);
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ai_law_bot_missing_topics',
            array('handled' => 1),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
        
        wp_redirect(admin_url('admin.php?page=ai-law-bot-missing-topics&filter=pending'));
        exit;
    }
    
    public function delete_topic() {
        if (!current_user_can('manage_options')) {
            wp_die('غير مصرح');
        }
        
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        check_admin_referer('delete_topic_' . $id);
        
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'ai_law_bot_missing_topics',
            array('id' => $id),
            array('%d')
        );
        
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'pending';
        wp_redirect(admin_url('admin.php?page=ai-law-bot-missing-topics&filter=' . $filter));
        exit;
    }
}
