<?php
/**
 * Learning Engine
 * 
 * This is NOT AI training. It's a pattern recognition system that:
 * 1. Remembers successful keyword-to-PDF matches
 * 2. Avoids repeating wrong/failed matches
 * 3. Uses conversation history for context
 * 4. Improves filtering accuracy over time
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Law_Bot_Learning_Engine {
    
    /**
     * Get enhanced search results using learning data
     * 
     * @param string $question User's question
     * @param array $keywords Extracted keywords
     * @return array Enhanced results with learning data applied
     */
    public static function enhance_search($question, $keywords) {
        $search = new AI_Law_Bot_PDF_Search();
        
        // First, check if we have cached successful matches for these keywords
        $cached_boosts = $search->boost_with_learning($keywords);
        
        // Perform the regular search
        $results = $search->search($question, $keywords);
        
        // If we got results, apply learning boosts
        if ($results['success'] && !empty($results['pdfs'])) {
            $results['pdfs'] = self::apply_boosts($results['pdfs'], $cached_boosts);
        }
        
        // If no results but we have cached matches, suggest those
        if (!$results['success'] && !empty($cached_boosts)) {
            // Try to get the cached posts directly
            $suggested = self::get_suggested_from_cache($cached_boosts);
            if (!empty($suggested)) {
                $results = array(
                    'success' => true,
                    'pdfs' => $suggested,
                    'keywords' => $keywords,
                    'reason' => null,
                    'from_learning' => true
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Apply learning boosts to search results
     */
    private static function apply_boosts($results, $boosts) {
        if (empty($boosts)) {
            return $results;
        }
        
        // Create a boost map
        $boost_map = array();
        foreach ($boosts as $boost) {
            $boost_map[$boost['post_id']] = $boost['boost_score'];
        }
        
        // Apply boosts
        foreach ($results as &$result) {
            if (isset($boost_map[$result['post_id']])) {
                $result['score'] += $boost_map[$result['post_id']];
                $result['learning_boosted'] = true;
            }
        }
        
        // Re-sort by score
        usort($results, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        return $results;
    }
    
    /**
     * Get suggested posts from cache when regular search fails
     */
    private static function get_suggested_from_cache($cached_boosts) {
        if (empty($cached_boosts)) {
            return array();
        }
        
        $results = array();
        
        foreach ($cached_boosts as $cache) {
            $post_id = $cache['post_id'];
            $post = get_post($post_id);
            
            if ($post && $post->post_status === 'publish') {
                $results[] = array(
                    'post_id' => $post_id,
                    'post_title' => $post->post_title,
                    'post_link' => get_permalink($post_id),
                    'pdf_title' => $cache['pdf_title'],
                    'pdf_url' => '', // We don't have URL in cache
                    'score' => $cache['boost_score'] + 30, // Base score
                    'from_learning' => true
                );
            }
        }
        
        return array_slice($results, 0, 3); // Limit to top 3
    }
    
    /**
     * Record a successful interaction
     * Called when AI gives a response that user found helpful
     */
    public static function record_success($question, $keywords, $matched_pdfs) {
        foreach ($matched_pdfs as $pdf) {
            foreach ($keywords as $keyword) {
                AI_Law_Bot_Database::cache_keyword_match(
                    $keyword,
                    $pdf['post_id'],
                    $pdf['pdf_title'],
                    $pdf['score']
                );
            }
        }
    }
    
    /**
     * Record a failed/wrong match
     * Called when user reports the answer was wrong or irrelevant
     */
    public static function record_failure($question, $post_id) {
        AI_Law_Bot_Database::log_failed_match($question, $post_id);
    }
    
    /**
     * Get conversation context for better understanding
     * 
     * @param string $session_id Current session ID
     * @return array Context information
     */
    public static function get_conversation_context($session_id) {
        $history = AI_Law_Bot_Database::get_conversation_history($session_id, 6);
        
        if (empty($history)) {
            return array(
                'has_context' => false,
                'messages' => array(),
                'topics' => array()
            );
        }
        
        // Extract topics from previous messages
        $topics = array();
        foreach ($history as $msg) {
            if ($msg['role'] === 'user') {
                $keywords = AI_Law_Bot_Keyword_Extractor::extract($msg['content']);
                $topics = array_merge($topics, $keywords);
            }
        }
        
        $topics = array_unique($topics);
        
        return array(
            'has_context' => true,
            'messages' => $history,
            'topics' => $topics
        );
    }
    
    /**
     * Enhance keywords based on conversation context
     */
    public static function enhance_keywords_with_context($keywords, $context) {
        if (!$context['has_context'] || empty($context['topics'])) {
            return $keywords;
        }
        
        // If current question is short or has few keywords, add context topics
        if (count($keywords) <= 2) {
            // Add up to 2 recent topics
            $extra_topics = array_slice($context['topics'], -2);
            $keywords = array_unique(array_merge($keywords, $extra_topics));
        }
        
        return $keywords;
    }
    
    /**
     * Get learning statistics for admin dashboard
     */
    public static function get_learning_stats() {
        global $wpdb;
        
        $keyword_table = $wpdb->prefix . 'ai_law_bot_keyword_cache';
        $failed_table = $wpdb->prefix . 'ai_law_bot_failed_matches';
        
        $stats = array();
        
        // Total learned keywords
        $stats['total_keywords'] = $wpdb->get_var("SELECT COUNT(DISTINCT keyword) FROM $keyword_table");
        
        // Total successful matches
        $stats['total_successful_matches'] = $wpdb->get_var("SELECT SUM(match_count) FROM $keyword_table");
        
        // Total failed matches
        $stats['total_failed_matches'] = $wpdb->get_var("SELECT COUNT(*) FROM $failed_table");
        
        // Top performing keywords
        $stats['top_keywords'] = $wpdb->get_results(
            "SELECT keyword, SUM(match_count) as total_matches 
             FROM $keyword_table 
             GROUP BY keyword 
             ORDER BY total_matches DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        // Most matched PDFs
        $stats['top_pdfs'] = $wpdb->get_results(
            "SELECT matched_pdf_title, SUM(match_count) as total_matches, matched_post_id
             FROM $keyword_table 
             GROUP BY matched_post_id, matched_pdf_title
             ORDER BY total_matches DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        return $stats;
    }
    
    /**
     * Clean up old learning data
     */
    public static function cleanup($days = 180) {
        global $wpdb;
        
        // Remove old keyword cache entries with low match counts
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ai_law_bot_keyword_cache 
             WHERE match_count < 3 
             AND last_matched_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Remove very old failed matches (give them a second chance)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ai_law_bot_failed_matches 
             WHERE reported_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days * 2
        ));
    }
}
