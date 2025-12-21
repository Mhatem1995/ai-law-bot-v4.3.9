<?php
/**
 * Arabic Keyword Extractor
 * 
 * Extracts meaningful legal keywords from Arabic questions
 * Used to match against PDF titles in cpp_pdf_meta
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Keyword_Extractor {
    
    /**
     * Comprehensive Arabic stopwords list
     */
    private static $stopwords = array(
        // Question words
        'ما', 'ماذا', 'من', 'متى', 'أين', 'كيف', 'لماذا', 'هل', 'أي', 'كم', 'أيّ', 'أيّة',
        'ماهي', 'ماهو', 'ماهم', 'ماهن',
        
        // Pronouns  
        'هو', 'هي', 'هما', 'هم', 'هن', 'أنا', 'أنت', 'أنتم', 'أنتن', 'نحن', 'أنتِ',
        'إياه', 'إياها', 'إياهم', 'إياك', 'إياكم', 'إيانا',
        
        // Demonstratives
        'هذا', 'هذه', 'هذان', 'هاتان', 'هؤلاء', 'ذلك', 'تلك', 'ذانك', 'تانك', 'أولئك',
        'الذي', 'التي', 'اللذان', 'اللتان', 'الذين', 'اللاتي', 'اللواتي',
        
        // Prepositions & particles
        'في', 'من', 'إلى', 'على', 'عن', 'مع', 'بـ', 'لـ', 'كـ', 'الى',
        'عند', 'لدى', 'حتى', 'منذ', 'بعد', 'قبل', 'خلال', 'أثناء', 'حول', 'ضد',
        'بين', 'فوق', 'تحت', 'أمام', 'خلف', 'داخل', 'خارج',
        
        // Conjunctions
        'و', 'أو', 'لكن', 'لأن', 'إن', 'أن', 'قد', 'لقد', 'لم', 'لن', 'ليس',
        'ثم', 'أم', 'بل', 'حيث', 'إذ', 'إذا', 'لو', 'لولا', 'كأن', 'فإن',
        
        // Common verbs
        'كان', 'كانت', 'يكون', 'تكون', 'يمكن', 'تمكن', 'يجب', 'تجب',
        'أريد', 'نريد', 'أرغب', 'نرغب', 'أبحث', 'نبحث', 'أسأل', 'نسأل',
        'يعني', 'تعني', 'يوجد', 'توجد', 'يحتاج', 'تحتاج',
        
        // Common nouns (generic)
        'شيء', 'أشياء', 'شخص', 'أشخاص', 'مكان', 'زمان', 'وقت', 'طريقة', 'طريق',
        'كل', 'بعض', 'جميع', 'كلا', 'كلتا', 'أحد', 'إحدى', 'بضع', 'عدة',
        
        // Polite words
        'فضلك', 'سمحت', 'تكرم', 'رجاء', 'أرجو', 'نرجو', 'شكرا', 'لطفا',
        
        // Country references (not legal keywords)
        'الكويت', 'كويت', 'الكويتي', 'الكويتية', 'كويتي', 'كويتية',
        'دولة', 'مدينة', 'بلد', 'محافظة',
        
        // Very generic legal terms (keep specific ones)
        'قانون', 'القانون', 'قوانين', 'القوانين', 'وفق', 'وفقا', 'حسب', 'طبقا',
        'نص', 'مادة', 'فقرة', 'بند', 'نظام', 'الأنظمة',
        
        // Articles and general words
        'ال', 'الـ', 'اللي', 'يلي', 'التالي', 'التالية',
        'مثل', 'نوع', 'أنواع', 'حالة', 'حالات',
        'سؤال', 'جواب', 'معلومات', 'تفاصيل',
    );
    
    /**
     * Legal keyword boosters - words that indicate important legal topics
     * These words should be prioritized
     */
    private static $legal_boosters = array(
        // Criminal Law
        'جريمة', 'جرائم', 'جنائي', 'جنائية', 'جناية', 'جنحة',
        'سرقة', 'قتل', 'اغتصاب', 'تحرش', 'ضرب', 'إيذاء', 'تهديد',
        'مخدرات', 'تزوير', 'احتيال', 'رشوة', 'اختلاس', 'غش',
        
        // Family Law
        'طلاق', 'زواج', 'نفقة', 'حضانة', 'ولاية', 'وصاية',
        'ميراث', 'إرث', 'تركة', 'وصية', 'نسب', 'أحوال',
        'مهر', 'عدة', 'خلع', 'فسخ', 'عقد',
        
        // Civil & Commercial
        'عقد', 'عقود', 'إيجار', 'بيع', 'شراء', 'ملكية',
        'تعويض', 'ضمان', 'كفالة', 'رهن', 'دين', 'ديون',
        'شركة', 'شركات', 'تجارة', 'تجاري', 'تجارية',
        'إفلاس', 'تصفية', 'شيك', 'كمبيالة', 'سند',
        
        // Labor Law
        'عمل', 'عامل', 'عمال', 'عمالة', 'وظيفة', 'توظيف',
        'راتب', 'أجر', 'مكافأة', 'إجازة', 'فصل', 'استقالة',
        'تأمين', 'تأمينات', 'معاش', 'تقاعد', 'إصابة',
        
        // Administrative Law
        'إداري', 'إدارية', 'حكومي', 'حكومية', 'رخصة', 'ترخيص',
        'تصريح', 'إقامة', 'جنسية', 'تأشيرة', 'كفيل', 'كفالة',
        
        // Procedures
        'دعوى', 'قضية', 'محكمة', 'محاكم', 'حكم', 'أحكام',
        'استئناف', 'نقض', 'تمييز', 'تنفيذ', 'حبس', 'سجن',
        'غرامة', 'عقوبة', 'عقوبات', 'براءة', 'إدانة',
        'محامي', 'محاماة', 'وكيل', 'وكالة', 'توكيل',
        
        // Property & Real Estate
        'عقار', 'عقارات', 'أرض', 'أراضي', 'شقة', 'بناء',
        'سكن', 'سكني', 'تجاري', 'صناعي', 'زراعي',
        
        // Rights & Obligations
        'حق', 'حقوق', 'واجب', 'واجبات', 'التزام', 'التزامات',
        'مسؤولية', 'مسؤوليات', 'ضرر', 'أضرار',
    );
    
    /**
     * Extract keywords from Arabic question
     * 
     * @param string $question The Arabic question
     * @return array Array of extracted keywords
     */
    public static function extract($question) {
        // Clean the question
        $question = self::clean_text($question);
        
        // Split into words
        $words = preg_split('/\s+/u', $question);
        
        $keywords = array();
        $boosted_keywords = array();
        
        foreach ($words as $word) {
            $word = trim($word);
            
            // Skip empty or very short words
            if (empty($word) || mb_strlen($word, 'UTF-8') <= 2) {
                continue;
            }
            
            // Remove common prefixes
            $word_clean = self::remove_prefixes($word);
            
            // Skip if word became too short
            if (mb_strlen($word_clean, 'UTF-8') <= 2) {
                continue;
            }
            
            // Check if stopword
            if (self::is_stopword($word) || self::is_stopword($word_clean)) {
                continue;
            }
            
            // Check if it's a legal booster keyword
            if (self::is_legal_keyword($word_clean)) {
                $boosted_keywords[] = $word_clean;
            } else {
                $keywords[] = $word_clean;
            }
        }
        
        // Combine: boosted keywords first, then regular keywords
        $result = array_unique(array_merge($boosted_keywords, $keywords));
        
        // Limit to reasonable number
        return array_slice($result, 0, 10);
    }
    
    /**
     * Extract keywords with their original forms (for better matching)
     */
    public static function extract_with_variants($question) {
        $question = self::clean_text($question);
        $words = preg_split('/\s+/u', $question);
        
        $keywords = array();
        
        foreach ($words as $word) {
            $word = trim($word);
            
            if (empty($word) || mb_strlen($word, 'UTF-8') <= 2) {
                continue;
            }
            
            $word_clean = self::remove_prefixes($word);
            
            if (mb_strlen($word_clean, 'UTF-8') <= 2) {
                continue;
            }
            
            if (self::is_stopword($word) || self::is_stopword($word_clean)) {
                continue;
            }
            
            // Add both original and cleaned versions
            $keywords[] = $word;
            if ($word !== $word_clean) {
                $keywords[] = $word_clean;
            }
            
            // Add root form if applicable
            $root = self::get_root_form($word_clean);
            if ($root && $root !== $word_clean) {
                $keywords[] = $root;
            }
        }
        
        return array_unique($keywords);
    }
    
    /**
     * Clean text for processing
     */
    private static function clean_text($text) {
        // Remove question marks, punctuation
        $text = str_replace(array('؟', '?', '،', ',', '.', ':', ';', '!', '"', "'", '«', '»'), ' ', $text);
        
        // Normalize Arabic characters
        $text = self::normalize_arabic($text);
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/u', ' ', $text);
        
        return mb_strtolower(trim($text), 'UTF-8');
    }
    
    /**
     * Normalize Arabic text (handle different forms of same letters)
     */
    private static function normalize_arabic($text) {
        // Normalize alef variants
        $text = str_replace(array('أ', 'إ', 'آ', 'ٱ'), 'ا', $text);
        
        // Normalize ya
        $text = str_replace('ى', 'ي', $text);
        
        // Normalize ta marbuta to ha
        $text = str_replace('ة', 'ه', $text);
        
        // Remove tashkeel (diacritics)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        
        return $text;
    }
    
    /**
     * Remove common Arabic prefixes
     */
    private static function remove_prefixes($word) {
        // Common prefixes to remove
        $prefixes = array(
            'ال', 'بال', 'وال', 'فال', 'كال', 'لل', 'ولل', 'بلل', 'فلل',
            'و', 'ب', 'ف', 'ك', 'ل'
        );
        
        // Sort by length (longest first) to avoid partial matches
        usort($prefixes, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($prefixes as $prefix) {
            if (mb_strpos($word, $prefix, 0, 'UTF-8') === 0) {
                $remainder = mb_substr($word, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8');
                // Only remove if remainder is still meaningful (3+ chars)
                if (mb_strlen($remainder, 'UTF-8') >= 3) {
                    return $remainder;
                }
            }
        }
        
        return $word;
    }
    
    /**
     * Check if word is a stopword
     */
    private static function is_stopword($word) {
        $word_lower = mb_strtolower($word, 'UTF-8');
        $word_normalized = self::normalize_arabic($word_lower);
        
        foreach (self::$stopwords as $stopword) {
            $stopword_normalized = self::normalize_arabic(mb_strtolower($stopword, 'UTF-8'));
            if ($word_normalized === $stopword_normalized || $word_lower === mb_strtolower($stopword, 'UTF-8')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if word is a legal keyword (should be boosted)
     */
    private static function is_legal_keyword($word) {
        $word_normalized = self::normalize_arabic(mb_strtolower($word, 'UTF-8'));
        
        foreach (self::$legal_boosters as $booster) {
            $booster_normalized = self::normalize_arabic(mb_strtolower($booster, 'UTF-8'));
            if ($word_normalized === $booster_normalized) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Try to get root form of Arabic word (basic implementation)
     */
    private static function get_root_form($word) {
        // Remove common suffixes
        $suffixes = array('ات', 'ون', 'ين', 'ية', 'ان', 'تي', 'ني', 'ها', 'هم');
        
        foreach ($suffixes as $suffix) {
            if (mb_strlen($word, 'UTF-8') > mb_strlen($suffix, 'UTF-8') + 2) {
                if (mb_substr($word, -mb_strlen($suffix, 'UTF-8'), null, 'UTF-8') === $suffix) {
                    return mb_substr($word, 0, -mb_strlen($suffix, 'UTF-8'), 'UTF-8');
                }
            }
        }
        
        return null;
    }
    
    /**
     * Calculate similarity between two Arabic strings
     */
    public static function calculate_similarity($str1, $str2) {
        $str1 = self::normalize_arabic(mb_strtolower($str1, 'UTF-8'));
        $str2 = self::normalize_arabic(mb_strtolower($str2, 'UTF-8'));
        
        // Exact match
        if ($str1 === $str2) {
            return 100;
        }
        
        // Contains check
        if (mb_strpos($str2, $str1, 0, 'UTF-8') !== false) {
            return 80;
        }
        
        if (mb_strpos($str1, $str2, 0, 'UTF-8') !== false) {
            return 80;
        }
        
        // Calculate Levenshtein-like similarity
        similar_text($str1, $str2, $percent);
        
        return $percent;
    }
}
