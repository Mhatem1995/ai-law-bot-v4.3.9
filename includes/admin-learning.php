<?php
/**
 * Admin Page - Learning Dashboard
 * 
 * Shows learning statistics and cached keyword data
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Learning_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_page'));
    }
    
    public function add_admin_page() {
        add_submenu_page(
            'ai-law-bot-missing-topics',
            'تعلم النظام',
            '📊 تعلم النظام',
            'manage_options',
            'ai-law-bot-learning',
            array($this, 'render_page')
        );
        
        add_submenu_page(
            'ai-law-bot-missing-topics',
            'سجل المحادثات',
            '💬 سجل المحادثات',
            'manage_options',
            'ai-law-bot-chat-logs',
            array($this, 'render_chat_logs_page')
        );
        
        add_submenu_page(
            'ai-law-bot-missing-topics',
            'ملفات PDF',
            '📚 ملفات PDF',
            'manage_options',
            'ai-law-bot-pdf-list',
            array($this, 'render_pdf_list_page')
        );
    }
    
    public function render_page() {
        $stats = AI_Law_Bot_Learning_Engine::get_learning_stats();
        ?>
        <div class="wrap" dir="rtl">
            <h1>📊 تعلم النظام</h1>
            <p class="description">
                هذه الصفحة تعرض كيف يتعلم النظام من الاستخدام السابق لتحسين دقة النتائج.
                <br><strong>ملاحظة:</strong> هذا ليس تدريب AI، بل نظام تخزين مؤقت للنتائج الناجحة.
            </p>
            
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2>📈 إحصائيات عامة</h2>
                <table class="widefat" style="max-width: 500px;">
                    <tr>
                        <th>كلمات مفتاحية محفوظة</th>
                        <td><strong><?php echo intval($stats['total_keywords']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>إجمالي التطابقات الناجحة</th>
                        <td><strong><?php echo intval($stats['total_successful_matches']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>تطابقات خاطئة مُبلّغ عنها</th>
                        <td><strong><?php echo intval($stats['total_failed_matches']); ?></strong></td>
                    </tr>
                </table>
            </div>
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2>🔑 أكثر الكلمات المفتاحية نجاحاً</h2>
                    <?php if (!empty($stats['top_keywords'])): ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>الكلمة</th>
                                    <th style="width: 100px;">عدد التطابقات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top_keywords'] as $keyword): ?>
                                    <tr>
                                        <td><?php echo esc_html($keyword['keyword']); ?></td>
                                        <td><?php echo intval($keyword['total_matches']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>لا توجد بيانات بعد. سيتم تجميعها مع الاستخدام.</p>
                    <?php endif; ?>
                </div>
                
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2>📄 أكثر ملفات PDF تطابقاً</h2>
                    <?php if (!empty($stats['top_pdfs'])): ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>عنوان PDF</th>
                                    <th style="width: 100px;">التطابقات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top_pdfs'] as $pdf): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo get_permalink($pdf['matched_post_id']); ?>" target="_blank">
                                                <?php echo esc_html($pdf['matched_pdf_title']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo intval($pdf['total_matches']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>لا توجد بيانات بعد.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card" style="margin-top: 20px; background: #f0f8ff;">
                <h2>❓ كيف يعمل نظام التعلم؟</h2>
                <ul>
                    <li><strong>تخزين النجاحات:</strong> عندما يتطابق سؤال مع PDF ويحصل المستخدم على إجابة، يتم حفظ الكلمات المفتاحية والـ PDF المرتبط.</li>
                    <li><strong>تعزيز النتائج:</strong> في المرات القادمة، إذا استُخدمت نفس الكلمات، يتم تعزيز نقاط الـ PDFs التي نجحت سابقاً.</li>
                    <li><strong>تجنب الأخطاء:</strong> إذا أبلغ مستخدم عن إجابة خاطئة، يتم تسجيل ذلك لتجنب نفس التطابق مستقبلاً.</li>
                    <li><strong>ليس تدريب AI:</strong> هذا النظام لا يعدّل نموذج OpenAI، بل يحسّن منطق البحث المحلي فقط.</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    public function render_chat_logs_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_law_bot_chat_logs';
        
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap" dir="rtl">
            <h1>💬 سجل المحادثات</h1>
            <p class="description">جميع الأسئلة التي طرحها المستخدمون وإجاباتها.</p>
            
            <?php if (empty($logs)): ?>
                <div class="notice notice-info">
                    <p>لا توجد محادثات مسجلة بعد.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;">التاريخ</th>
                            <th>السؤال</th>
                            <th style="width: 100px;">وجد PDFs؟</th>
                            <th style="width: 80px;">Tokens</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date_i18n('Y-m-d H:i', strtotime($log->created_at)); ?></td>
                                <td>
                                    <strong><?php echo esc_html(wp_trim_words($log->question, 15)); ?></strong>
                                    <?php if (!empty($log->extracted_keywords)): ?>
                                        <br><small style="color: #666;">
                                            الكلمات: <?php echo esc_html(implode('، ', json_decode($log->extracted_keywords, true) ?: array())); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log->pdfs_found): ?>
                                        <span style="color: green;">✓ نعم</span>
                                    <?php else: ?>
                                        <span style="color: red;">✗ لا</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo intval($log->tokens_used); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="pagination-links">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <?php if ($i === $page): ?>
                                        <span class="tablenav-pages-navspan button disabled"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a class="button" href="?page=ai-law-bot-chat-logs&paged=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_pdf_list_page() {
        $topics = AI_Law_Bot_PDF_Search::get_all_pdf_topics();
        ?>
        <div class="wrap" dir="rtl">
            <h1>📚 ملفات PDF المتاحة</h1>
            <p class="description">
                قائمة بجميع ملفات PDF المخزنة في حقل <code>_cpp_pdf_files</code>.
                <br>هذه هي الملفات الوحيدة التي يبحث فيها المساعد الذكي.
            </p>
            
            <?php if (empty($topics)): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>⚠️ لا توجد ملفات PDF!</strong>
                        <br>تأكد من أن مقالاتك تحتوي على حقل مخصص <code>_cpp_pdf_files</code> مع بيانات PDF.
                    </p>
                </div>
            <?php else: ?>
                <p>إجمالي الملفات: <strong><?php echo count($topics); ?></strong></p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>عنوان PDF</th>
                            <th style="width: 200px;">المقال المرتبط</th>
                            <th style="width: 100px;">رابط PDF</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topics as $topic): ?>
                            <tr>
                                <td><strong><?php echo esc_html($topic['pdf_title']); ?></strong></td>
                                <td>
                                    <a href="<?php echo get_permalink($topic['post_id']); ?>" target="_blank">
                                        <?php echo esc_html(wp_trim_words($topic['post_title'], 8)); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if (!empty($topic['pdf_url'])): ?>
                                        <a href="<?php echo esc_url($topic['pdf_url']); ?>" target="_blank" class="button button-small">
                                            عرض PDF
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
