<?php
/**
 * PDF Search Engine - Optimized for Large Scale
 * 
 * Meta Key: _cpp_pdf_files
 * Structure: Serialized array of PDFs, each with 'title' field
 * 
 * Optimized for 1000+ posts with multiple PDFs each
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_PDF_Search {
    
    const PDF_META_KEY = '_cpp_pdf_files';
    
    private $min_score;
    private $max_results;
    
    // Cache for all PDFs (loaded once per request)
    private static $pdf_cache = null;
    
    public function __construct() {
        $this->min_score = intval(get_option('ai_law_bot_min_relevance_score', 15));
        $this->max_results = intval(get_option('ai_law_bot_max_results', 5));
    }
    
    /**
     * Main search function - searches PDF titles
     * 
     * @param string $question User's question
     * @param array $keywords Extracted keywords (optional)
     * @return array Search results
     */
    public function search($question, $keywords = null) {
        // Extract keywords if not provided
        if ($keywords === null) {
            $keywords = AI_Law_Bot_Keyword_Extractor::extract($question);
        }
        
        // If no keywords, use question words
        if (empty($keywords)) {
            $keywords = $this->extract_simple_keywords($question);
        }
        
        // Get all PDFs (cached)
        $all_pdfs = $this->get_all_pdfs();
        
        if (empty($all_pdfs)) {
            return array(
                'success' => false,
                'pdfs' => array(),
                'keywords' => $keywords,
                'reason' => 'no_pdf_posts',
                'total_pdfs' => 0
            );
        }
        
        // Search through PDF titles
        $matches = $this->search_pdf_titles($all_pdfs, $keywords, $question);
        
        if (empty($matches)) {
            return array(
                'success' => false,
                'pdfs' => array(),
                'keywords' => $keywords,
                'reason' => 'no_matches',
                'total_pdfs' => count($all_pdfs)
            );
        }
        
        // Sort by score (highest first)
        usort($matches, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Limit results
        $matches = array_slice($matches, 0, $this->max_results);
        
        return array(
            'success' => true,
            'pdfs' => $matches,
            'keywords' => $keywords,
            'reason' => null,
            'total_pdfs' => count($all_pdfs)
        );
    }
    
    /**
     * Get all PDFs from all posts - with caching
     * Optimized for large datasets
     */
    private function get_all_pdfs() {
        // Return cached if available
        if (self::$pdf_cache !== null) {
            return self::$pdf_cache;
        }
        
        global $wpdb;
        
        // Single query to get all PDF meta data
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID as post_id, p.post_title, pm.meta_value
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_status = 'publish'
             AND pm.meta_key = %s
             AND pm.meta_value IS NOT NULL
             AND pm.meta_value != ''
             AND pm.meta_value != 'a:0:{}'",
            self::PDF_META_KEY
        ), ARRAY_A);
        
        if (empty($results)) {
            self::$pdf_cache = array();
            return array();
        }
        
        $all_pdfs = array();
        
        foreach ($results as $row) {
            $post_id = $row['post_id'];
            $post_title = $row['post_title'];
            $post_link = get_permalink($post_id);
            
            // Parse the serialized PDF data
            $pdfs = $this->parse_pdf_data($row['meta_value']);
            
            foreach ($pdfs as $pdf) {
                if (!empty($pdf['title'])) {
                    $all_pdfs[] = array(
                        'post_id' => $post_id,
                        'post_title' => $post_title,
                        'post_link' => $post_link,
                        'pdf_title' => $pdf['title'],
                        'pdf_url' => $pdf['url'] ?? '',
                        'pdf_title_normalized' => $this->normalize_arabic($pdf['title'])
                    );
                }
            }
        }
        
        // Cache the results
        self::$pdf_cache = $all_pdfs;
        
        return $all_pdfs;
    }
    
    /**
     * Parse serialized PDF data
     */
    private function parse_pdf_data($meta_value) {
        $pdfs = array();
        
        // Unserialize
        $data = @maybe_unserialize($meta_value);
        
        if (!is_array($data)) {
            // Try JSON
            $data = @json_decode($meta_value, true);
        }
        
        if (!is_array($data)) {
            return array();
        }
        
        // Handle single PDF or array of PDFs
        if (isset($data['title'])) {
            // Single PDF
            $pdfs[] = array(
                'title' => $data['title'] ?? '',
                'url' => $data['file'] ?? $data['url'] ?? ''
            );
        } else {
            // Array of PDFs
            foreach ($data as $item) {
                if (is_array($item) && isset($item['title'])) {
                    $pdfs[] = array(
                        'title' => $item['title'] ?? '',
                        'url' => $item['file'] ?? $item['url'] ?? ''
                    );
                }
            }
        }
        
        return $pdfs;
    }
    
    /**
     * Search through PDF titles - optimized matching
     */
    private function search_pdf_titles($all_pdfs, $keywords, $question) {
        $matches = array();
        $question_normalized = $this->normalize_arabic($question);
        
        foreach ($all_pdfs as $pdf) {
            $title_normalized = $pdf['pdf_title_normalized'];
            $score = 0;
            
            // Check each keyword against PDF title
            foreach ($keywords as $keyword) {
                $keyword_normalized = $this->normalize_arabic($keyword);
                
                if (mb_strlen($keyword_normalized, 'UTF-8') < 2) {
                    continue;
                }
                
                // Exact substring match (keyword found in title)
                if (mb_strpos($title_normalized, $keyword_normalized, 0, 'UTF-8') !== false) {
                    $score += 50;
                    
                    // Bonus if keyword is a significant part of title
                    $keyword_len = mb_strlen($keyword_normalized, 'UTF-8');
                    $title_len = mb_strlen($title_normalized, 'UTF-8');
                    if ($keyword_len > 3 && $keyword_len / $title_len > 0.2) {
                        $score += 20;
                    }
                    continue;
                }
                
                // Word-level matching
                $title_words = preg_split('/[\s\-_.,،؛:()]+/u', $title_normalized);
                foreach ($title_words as $word) {
                    if (mb_strlen($word, 'UTF-8') < 2) continue;
                    
                    // Exact word match
                    if ($word === $keyword_normalized) {
                        $score += 45;
                        break;
                    }
                    
                    // Word starts with keyword
                    if (mb_strpos($word, $keyword_normalized, 0, 'UTF-8') === 0) {
                        $score += 35;
                        break;
                    }
                    
                    // Keyword starts with word
                    if (mb_strpos($keyword_normalized, $word, 0, 'UTF-8') === 0) {
                        $score += 25;
                        break;
                    }
                    
                    // Similar words (fuzzy matching)
                    if (mb_strlen($word, 'UTF-8') >= 3 && mb_strlen($keyword_normalized, 'UTF-8') >= 3) {
                        similar_text($keyword_normalized, $word, $percent);
                        if ($percent >= 70) {
                            $score += 20;
                            break;
                        }
                    }
                }
            }
            
            // Also check post title with lower weight
            $post_title_normalized = $this->normalize_arabic($pdf['post_title']);
            foreach ($keywords as $keyword) {
                $keyword_normalized = $this->normalize_arabic($keyword);
                if (mb_strpos($post_title_normalized, $keyword_normalized, 0, 'UTF-8') !== false) {
                    $score += 15;
                }
            }
            
            // Add match if score meets minimum
            if ($score >= $this->min_score) {
                // Skip if previously marked as wrong match
                if (class_exists('AI_Law_Bot_Database') && AI_Law_Bot_Database::is_failed_match($question, $pdf['post_id'])) {
                    continue;
                }
                
                $matches[] = array(
                    'post_id' => $pdf['post_id'],
                    'post_title' => $pdf['post_title'],
                    'post_link' => $pdf['post_link'],
                    'pdf_title' => $pdf['pdf_title'],
                    'pdf_url' => $pdf['pdf_url'],
                    'text_content' => $pdf['pdf_title'], // For AI context
                    'score' => $score
                );
            }
        }
        
        return $matches;
    }
    
    /**
     * Simple keyword extraction (fallback)
     */
    private function extract_simple_keywords($text) {
        $text = $this->normalize_arabic($text);
        $words = preg_split('/[\s\-_.,،؛:؟?!()]+/u', $text);
        
        $keywords = array();
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word, 'UTF-8') >= 2) {
                $keywords[] = $word;
            }
        }
        
        return array_unique($keywords);
    }
    
    /**
     * Normalize Arabic text for comparison
     */
    private function normalize_arabic($text) {
        if (empty($text)) return '';
        
        $text = mb_strtolower($text, 'UTF-8');
        
        // Normalize Arabic letters
        $text = str_replace(array('أ', 'إ', 'آ', 'ٱ', 'ء'), 'ا', $text);
        $text = str_replace(array('ى', 'ئ'), 'ي', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ؤ', 'و', $text);
        
        // Remove diacritics (tashkeel)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        
        // Normalize whitespace
        $text = preg_replace('/\s+/u', ' ', trim($text));
        
        return $text;
    }
    
    /**
     * Get all content for AI context
     */
    public function get_all_content_for_semantic_search() {
        $all_pdfs = $this->get_all_pdfs();
        
        $content = array();
        foreach ($all_pdfs as $pdf) {
            $content[] = array(
                'post_id' => $pdf['post_id'],
                'post_title' => $pdf['post_title'],
                'post_link' => $pdf['post_link'],
                'title' => $pdf['pdf_title'],
                'pdf_title' => $pdf['pdf_title'],
                'pdf_url' => $pdf['pdf_url'],
                'text_content' => $pdf['pdf_title']
            );
        }
        
        return $content;
    }
    
    /**
     * Get all PDF topics for admin display
     */
    public static function get_all_pdf_topics() {
        $searcher = new self();
        $all_pdfs = $searcher->get_all_pdfs();
        
        $topics = array();
        foreach ($all_pdfs as $pdf) {
            $topics[] = array(
                'post_id' => $pdf['post_id'],
                'post_title' => $pdf['post_title'],
                'pdf_title' => $pdf['pdf_title'],
                'pdf_url' => $pdf['pdf_url'],
                'has_text_content' => true,
                'text_preview' => mb_substr($pdf['pdf_title'], 0, 80, 'UTF-8')
            );
        }
        
        return $topics;
    }
    
    /**
     * Get total PDF count
     */
    public static function get_pdf_count() {
        $searcher = new self();
        return count($searcher->get_all_pdfs());
    }
    
    /**
     * Clear cache (for testing)
     */
    public static function clear_cache() {
        self::$pdf_cache = null;
    }
    
    /**
     * Boost with learning data
     */
    public function boost_with_learning($keywords) {
        if (!class_exists('AI_Law_Bot_Database')) {
            return array();
        }
        
        $cached = AI_Law_Bot_Database::get_cached_keywords($keywords);
        
        if (empty($cached)) {
            return array();
        }
        
        $boosted = array();
        foreach ($cached as $cache) {
            if ($cache['match_count'] >= 2) {
                $boosted[] = array(
                    'post_id' => $cache['matched_post_id'],
                    'pdf_title' => $cache['matched_pdf_title'],
                    'boost_score' => min($cache['match_count'] * 5, 25)
                );
            }
        }
        
        return $boosted;
    }
}
