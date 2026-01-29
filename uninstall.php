<?php
/**
 * Uninstall AI Law Bot
 * 
 * This file is executed when the plugin is deleted from WordPress.
 * It cleans up all plugin data from the database.
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
delete_option('ai_law_bot_openai_key');
delete_option('ai_law_bot_auth_token');
delete_option('ai_law_bot_model');
delete_option('ai_law_bot_daily_limit');
delete_option('ai_law_bot_enable_premium');
delete_option('ai_law_bot_show_everywhere');
delete_option('ai_law_bot_min_relevance_score');
delete_option('ai_law_bot_max_results');
delete_option('ai_law_bot_encryption_key');
delete_option('ai_law_bot_db_version');

// Delete all custom tables
global $wpdb;

$tables = array(
    $wpdb->prefix . 'ai_law_bot_chat_logs',
    $wpdb->prefix . 'ai_law_bot_missing_topics',
    $wpdb->prefix . 'ai_law_bot_conversation_memory',
    $wpdb->prefix . 'ai_law_bot_keyword_cache',
    $wpdb->prefix . 'ai_law_bot_usage',
    $wpdb->prefix . 'ai_law_bot_failed_matches',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// Remove the custom role if it exists
remove_role('ai_premium_user');

// Clean up any transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_law_bot_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ai_law_bot_%'");

// Clean up user meta
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ai_law_bot_%'");
