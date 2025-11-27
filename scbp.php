<?php
/**
 * Plugin Name: Comment Beautifier
 * Plugin URI: https://github.com/garurprani/comment-beautifier
 * Description: Convert spammy comments to friendlier, cleaned-up comments with an enhanced UI.
 * Version: 2.2
 * Author: Ujjwal Raj
 * Author URI: https://github.com/garurprani
 * Text Domain: comment-beautifier
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class Comment_Beautifier_Pro {
    private static $instance = null;
    private $option_comments_key = 'cb_canned_comments';
    private $option_names_key = 'cb_canned_names';
    private $option_only_links_key = 'cb_only_links_setting';
    private $option_comments_per_page_key = 'cb_comments_per_page';
    private $option_remove_urls_key = 'cb_remove_urls_setting';

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_cb_beautify_comments', array($this, 'ajax_beautify_comments'));
        add_action('wp_ajax_cb_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_cb_approve_comments', array($this, 'ajax_approve_comments'));
        add_action('wp_ajax_cb_remove_profile_urls', array($this, 'remove_author_urls'));
        add_action('wp_ajax_cb_unapprove_comments', array($this, 'ajax_unapprove_comments'));
        add_action('wp_ajax_cb_remove_urls_only', array($this, 'ajax_remove_urls_only'));
        
        // Add comment meta for tracking
        add_action('init', array($this, 'register_comment_meta'));
    }

    public function register_comment_meta() {
        register_meta('comment', '_cb_backup_content', array(
            'type' => 'string',
            'description' => 'Original comment content before beautification',
            'single' => true,
            'show_in_rest' => false,
        ));
        
        register_meta('comment', '_cb_backup_author', array(
            'type' => 'string',
            'description' => 'Original comment author before beautification',
            'single' => true,
            'show_in_rest' => false,
        ));
    }

    public function register_admin_page() {
        // Main menu page - Overview as default
        add_menu_page(
            'Comment Beautifier - Overview',
            'Comment Beautifier',
            'manage_options',
            'comment-beautifier-pro',
            array($this, 'render_overview_page'),
            'dashicons-admin-comments',
            60
        );
    
        // Add Overview as first submenu item
        add_submenu_page(
            'comment-beautifier-pro',
            'Overview - Comment Beautifier',
            'Overview',
            'manage_options',
            'comment-beautifier-pro',
            array($this, 'render_overview_page')
        );
    
        // Other submenu pages
        add_submenu_page(
            'comment-beautifier-pro',
            'Awaiting Moderation - Comment Beautifier',
            'Awaiting Moderation',
            'manage_options',
            'comment-beautifier-pro-awaiting',
            array($this, 'render_awaiting_page')
        );
    
        add_submenu_page(
            'comment-beautifier-pro',
            'Settings - Comment Beautifier',
            'Settings',
            'manage_options',
            'comment-beautifier-pro-settings',
            array($this, 'render_settings_page')
        );
    
        // Add About/Developer page
        add_submenu_page(
            'comment-beautifier-pro',
            'About - Comment Beautifier',
            'About',
            'manage_options',
            'comment-beautifier-pro-about',
            array($this, 'render_about_page')
        );
    }

    public function enqueue_assets($hook) {
        $plugin_pages = array(
            'toplevel_page_comment-beautifier-pro', // Main overview page
            'comment-beautifier-pro_page_comment-beautifier-pro-awaiting',
            'comment-beautifier-pro_page_comment-beautifier-pro-settings'
        );
    
        // Also handle the case where the main page might have different hook names
        $current_screen = get_current_screen();
        $is_plugin_page = strpos($current_screen->base, 'comment-beautifier-pro') !== false;
    
        if (!$is_plugin_page) return;
    
        $plugin_url = plugin_dir_url(__FILE__);
    
        wp_register_style('scbp-admin-css', $plugin_url . 'css/style.css', array(), '2.2');
        wp_enqueue_style('scbp-admin-css');
    
        wp_register_script('scbp-admin-js', $plugin_url . 'js/scb.js', array('jquery'), '2.2', true);
        wp_enqueue_script('scbp-admin-js');
    
        wp_localize_script('scbp-admin-js', 'cb_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cb_nonce'),
            'remove_urls_setting' => get_option($this->option_remove_urls_key, '1')
        ));
    }
    
    /**
     * Extract first domain-like host from content text.
     */
    private function get_domain_from_text($text) {
        if (empty($text)) return '';
        if (preg_match('#https?://[^\s,<]+#i', $text, $m)) {
            $u = $m[0];
        } elseif (preg_match('#www\.[^\s,<]+#i', $text, $m)) {
            $u = (strpos($m[0], 'http') === 0) ? $m[0] : 'http://' . $m[0];
        } elseif (preg_match('#\b[\w-]+\.(com|net|org|io|in|co|info)\b#i', $text, $m)) {
            $u = 'http://' . $m[0];
        } else {
            return '';
        }
        $parts = wp_parse_url($u);
        return isset($parts['host']) ? $parts['host'] : '';
    }

    /**
     * Get comments without backup meta (non-beautified) - Optimized version
     */
    private function get_comments_without_backup($number, $offset = 0) {
        $cache_key = 'cb_comments_without_backup_' . $number . '_' . $offset;
        $cached = wp_cache_get($cache_key, 'comment-beautifier');
        
        if ($cached !== false) {
            return $cached;
        }

        // Get all pending comments first
        $all_comments = get_comments(array(
            'status' => 'hold',
            'number' => $number * 2, // Get more to account for filtering
            'offset' => $offset,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ));

        // Filter out comments that have backup meta
        $filtered_comments = array();
        foreach ($all_comments as $comment) {
            if (count($filtered_comments) >= $number) {
                break;
            }
            if (!metadata_exists('comment', $comment->comment_ID, '_cb_backup_content')) {
                $filtered_comments[] = $comment;
            }
        }

        wp_cache_set($cache_key, $filtered_comments, 'comment-beautifier', 300);
        return $filtered_comments;
    }

    /**
     * Count comments without backup meta (non-beautified) - Optimized version
     */
    private function count_comments_without_backup() {
        $cache_key = 'cb_count_comments_without_backup';
        $cached = wp_cache_get($cache_key, 'comment-beautifier');
        
        if ($cached !== false) {
            return $cached;
        }
    
        // Get total pending comments
        $total_pending = (int) get_comments(array(
            'status' => 'hold',
            'count' => true,
        ));
    
        // Get count of beautified comments by sampling
        $sample_comments = get_comments(array(
            'status' => 'hold',
            'number' => min(200, $total_pending), // Sample size
        ));
    
        $beautified_count = 0;
        foreach ($sample_comments as $comment) {
            if (metadata_exists('comment', $comment->comment_ID, '_cb_backup_content')) {
                $beautified_count++;
            }
        }
    
        // Estimate total beautified count based on sample ratio
        $sample_ratio = $total_pending > 0 ? count($sample_comments) / $total_pending : 0;
        $estimated_beautified = $sample_ratio > 0 ? $beautified_count / $sample_ratio : 0;
        
        $count = max(0, $total_pending - (int) $estimated_beautified);
        wp_cache_set($cache_key, $count, 'comment-beautifier', 300);
        
        return $count;
    }

    /**
     * Get comments with backup meta (beautified) - Optimized version
     */
    private function get_comments_with_backup($number, $offset = 0) {
        $cache_key = 'cb_comments_with_backup_' . $number . '_' . $offset;
        $cached = wp_cache_get($cache_key, 'comment-beautifier');

        if ($cached !== false) {
            return $cached;
        }

        // Get all pending comments and filter for those with backup meta
        $all_comments = get_comments(array(
            'status' => 'hold',
            'number' => $number * 3, // Get more to account for filtering
            'offset' => $offset,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ));

        $filtered_comments = array();
        foreach ($all_comments as $comment) {
            if (count($filtered_comments) >= $number) {
                break;
            }
            if (metadata_exists('comment', $comment->comment_ID, '_cb_backup_content')) {
                $filtered_comments[] = $comment;
            }
        }

        wp_cache_set($cache_key, $filtered_comments, 'comment-beautifier', 300);
        return $filtered_comments;
    }

    /**
     * Count comments with backup meta (beautified) - Optimized version
     */
    private function count_comments_with_backup() {
        $cache_key = 'cb_count_comments_with_backup';
        $cached = wp_cache_get($cache_key, 'comment-beautifier');

        if ($cached !== false) {
            return $cached;
        }

        // Get all pending comments and count how many have backup meta
        $all_comments = get_comments(array(
            'status' => 'hold',
            'number' => 1000, // Reasonable limit for counting
        ));

        $count = 0;
        foreach ($all_comments as $comment) {
            if (metadata_exists('comment', $comment->comment_ID, '_cb_backup_content')) {
                $count++;
            }
        }

        wp_cache_set($cache_key, $count, 'comment-beautifier', 300);
        return $count;
    }

    /**
     * Clear comments cache when comments are modified
     */
    private function clear_comments_cache() {
        $cache_keys = array(
            'cb_comments_without_backup',
            'cb_count_comments_without_backup',
            'cb_comments_with_backup',
            'cb_count_comments_with_backup'
        );
        
        foreach ($cache_keys as $base_key) {
            wp_cache_delete($base_key, 'comment-beautifier');
            // Also delete paginated cache entries
            for ($i = 0; $i < 10; $i++) {
                wp_cache_delete($base_key . '_' . ($i * 20) . '_' . $i, 'comment-beautifier');
            }
        }
    }

    /**
     * Overview page
     */
    public function render_overview_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        // Verify nonce for form processing
        if (isset($_GET['cb_page'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'cb_pagination_nonce')) {
                wp_die('Security check failed');
            }
        }

        $current_page = isset($_GET['cb_page']) ? max(1, intval($_GET['cb_page'])) : 1;
        $comments_per_page = get_option($this->option_comments_per_page_key, 20);
        $offset = ($current_page - 1) * $comments_per_page;

        // Get comments for overview page - EXCLUDE already beautified comments
        $all_comments = $this->get_comments_without_backup($comments_per_page, $offset);
        $total_comments = $this->count_comments_without_backup();

        $this->render_page_header('Overview');
        $this->render_comments_section($all_comments, 'overview', $current_page, $comments_per_page, $total_comments);
        $this->render_page_footer();
    }

    /**
     * Awaiting Moderation page
     */
    public function render_awaiting_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        
        // Verify nonce for form processing
        if (isset($_GET['cb_page'])) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'cb_pagination_nonce')) {
                wp_die('Security check failed');
            }
        }

        $current_page = isset($_GET['cb_page']) ? max(1, intval($_GET['cb_page'])) : 1;
        $comments_per_page = get_option($this->option_comments_per_page_key, 20);
        $offset = ($current_page - 1) * $comments_per_page;

        $awaiting_comments = $this->get_comments_with_backup($comments_per_page, $offset);
        $total_comments = $this->count_comments_with_backup();

        $totals = wp_count_comments();
        
        $this->render_page_header('Awaiting Moderation');
        $this->render_comments_section($awaiting_comments, 'awaiting', $current_page, $comments_per_page, $total_comments);
        $this->render_page_footer();
    }

    /**
     * Settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        
        $canned_comments = get_option($this->option_comments_key, "Thanks for the comment!\nAppreciate your input.\nNice work!");
        $canned_names = get_option($this->option_names_key, "Aman\nRajiv\nGuest");
        $comments_per_page_setting = get_option($this->option_comments_per_page_key, 20);
        $remove_urls_setting = get_option($this->option_remove_urls_key, '1');
        $only_links_setting = get_option($this->option_only_links_key, '1');
        
        $this->render_page_header('Settings');
        ?>
        <div class="cb-pro-settings-card">
            <h2><span class="dashicons dashicons-admin-generic"></span> Settings</h2>
            
            <div class="cb-pro-settings-grid">
                <!-- Left Column -->
                <div class="cb-pro-settings-column">
                    <div class="cb-pro-settings-section">
                        <h3>Default Behaviors</h3>
                        <p class="cb-pro-settings-description">These toggles control the default behavior when using Preview or Beautify actions.</p>
                        
                        <div class="cb-pro-settings-toggles">
                            <div class="cb-pro-setting-toggle">
                                <label class="cb-pro-toggle-label">
                                    <div class="cb-pro-toggle-switch">
                                        <input type="checkbox" id="cb-setting-remove-urls" <?php checked($remove_urls_setting, '1'); ?> />
                                        <span class="cb-pro-toggle-slider"></span> 
                                    </div>
                                    <div class="cb-pro-toggle-content">
                                        <strong>Remove URLs from content by default</strong>
                                        <p>Automatically strip URLs from comment content during beautification</p>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="cb-pro-setting-toggle">
                                <label class="cb-pro-toggle-label">
                                    <div class="cb-pro-toggle-switch">
                                        <input type="checkbox" id="cb-setting-only-links" <?php checked($only_links_setting, '1'); ?> />
                                        <span class="cb-pro-toggle-slider"></span>
                                    </div>
                                    <div class="cb-pro-toggle-content">
                                        <strong>Only show comments with links (Overview page only)</strong>
                                        <p>Limit display to comments that contain URLs in Overview page</p>
                                    </div>
                                </label>
                            </div>

                            <div class="cb-pro-setting-toggle">
                                <label class="cb-pro-toggle-label">
                                    <div class="cb-pro-toggle-switch">
                                        <input type="checkbox" id="cb-setting-auto-approve" />
                                        <span class="cb-pro-toggle-slider"></span>
                                    </div>
                                    <div class="cb-pro-toggle-content">
                                        <strong>Auto-approve after beautification</strong>
                                        <p>Automatically approve comments after they are beautified</p>
                                    </div>
                                </label>
                            </div>

                            <div class="cb-pro-setting-toggle">
                                <label class="cb-pro-toggle-label">
                                    <div class="cb-pro-toggle-switch">
                                        <input type="checkbox" id="cb-setting-remove-profile" />
                                        <span class="cb-pro-toggle-slider"></span>
                                    </div>
                                    <div class="cb-pro-toggle-content">
                                        <strong>Remove profile URLs by default</strong>
                                        <p>Automatically remove author profile URLs during beautification</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="cb-pro-settings-section">
                        <h3>Comments Display</h3>
                        <p>Control how many comments are displayed per page.</p>
                        <div class="cb-pro-setting-field">
                            <label for="cb-comments-per-page">Comments per page:</label>
                            <input type="number" id="cb-comments-per-page" class="cb-pro-input" min="5" max="100" value="<?php echo esc_attr($comments_per_page_setting); ?>" />
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="cb-pro-settings-column">
                    <div class="cb-pro-settings-section">
                        <h3>Canned Comments</h3>
                        <p>Enter canned replacement comments (one per line). These will be randomly used when beautifying comments.</p>
                        <textarea id="cb-canned-comments" class="cb-pro-textarea" rows="8" placeholder="Enter canned comments, one per line..."><?php echo esc_textarea($canned_comments); ?></textarea>
                    </div>
                    
                    <div class="cb-pro-settings-section">
                        <h3>Canned Names</h3>
                        <p>Enter canned names (one per line) for replacing the comment author when Beautify Name is used.</p>
                        <textarea id="cb-canned-names" class="cb-pro-textarea" rows="4" placeholder="Enter canned names, one per line..."><?php echo esc_textarea($canned_names); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Save Button - Full Width -->
            <div class="cb-pro-settings-section cb-pro-settings-fullwidth">
                <div class="cb-pro-settings-actions">
                    <button id="cb-save-settings" class="cb-pro-btn primary">
                        <span class="dashicons dashicons-yes"></span>
                        Save Settings
                    </button>
                    <span id="cb-save-status" class="cb-pro-save-status"></span>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div class="cb-pro-help-section">
            <h2><span class="dashicons dashicons-info"></span> How It Works</h2>
            <div class="cb-pro-help-grid">
                <div class="cb-pro-help-card">
                    <span class="dashicons dashicons-backup"></span>
                    <h3>Backup System</h3>
                    <p>Original comment content and author names are backed up as comment meta before any changes are made.</p>
                </div>
                
                <div class="cb-pro-help-card">
                    <span class="dashicons dashicons-filter"></span>
                    <h3>Smart Filtering</h3>
                    <p>Filter comments by status, links, profile URLs, or search for specific content.</p>
                </div>
                
                <div class="cb-pro-help-card">
                    <span class="dashicons dashicons-admin-customizer"></span>
                    <h3>Customizable</h3>
                    <p>Use canned comments and names to transform spam into friendly, natural conversations.</p>
                </div>
            </div>
        </div>
        <?php
        $this->render_page_footer();
    }

    /**
     * About/Developer page
     */
    public function render_about_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        
        $this->render_page_header('About');
        ?>
        <div class="cb-pro-about-section">
            <div class="cb-pro-about-hero">
                <div class="cb-pro-about-brand">
                    <!-- <span class="cb-pro-about-logo">💬</span> -->
                    <div class="cb-pro-about-title">
                        <h2>Comment Beautifier</h2>
                        <p class="cb-pro-version">Version 2.2</p>
                    </div>
                </div>
                <p class="cb-pro-about-description">
                    Transform spammy comments into friendly, engaging conversations.
                </p>
            </div>

          <div class="cb-pro-features-grid">
    <div class="cb-pro-feature-card">
        <span class="dashicons dashicons-shield"></span>
        <h3>Intelligent Spam Defense</h3>
        <p>Advanced detection algorithms identify and neutralize spam comments, converting them into genuine, engaging content that adds value to your discussions.</p>
    </div>
    
    <div class="cb-pro-feature-card">
        <span class="dashicons dashicons-admin-customizer"></span>
        <h3>Smart Content Transformation</h3>
        <p>Automatically replace spammy content with authentic-sounding comments from your custom library, maintaining natural conversation flow across your website.</p>
    </div>
    
    <div class="cb-pro-feature-card">
        <span class="dashicons dashicons-backup"></span>
        <h3>Zero-Risk Moderation</h3>
        <p>Comprehensive backup system preserves all original comment data before any modifications, giving you complete peace of mind and recovery options.</p>
    </div>
    
    <div class="cb-pro-feature-card">
        <span class="dashicons dashicons-chart-bar"></span>
        <h3>Real-Time Analytics</h3>
        <p>Monitor comment moderation performance with detailed metrics, filtering tools, and visual insights to optimize your spam management strategy.</p>
    </div>
</div>

<!-- Developer & Bug Report Side by Side -->
<div class="cb-pro-developer-bug-grid">
    <!-- Developer Section - LEFT -->
    <div class="cb-pro-developer-section">
        <h3>About the Developer</h3>
        <div class="cb-pro-developer-card">
            <div class="cb-pro-developer-avatar">
                <span class="cb-pro-avatar">👨‍💻</span>
            </div>
            <div class="cb-pro-developer-info">
                <h4>Ujjwal Raj (garurprani)</h4>
                <p class="cb-pro-developer-bio">
                    Full-stack developer passionate about creating tools that make WordPress management easier and more efficient. 
                    With experience in PHP, JavaScript, and WordPress development, I focus on building plugins that solve real problems.
                </p>
                <div class="cb-pro-developer-links">
                    <a href="https://github.com/garurprani" target="_blank" class="cb-pro-social-link">
                        <span class="dashicons dashicons-admin-users"></span>
                        GitHub
                    </a>
                    <a href="https://linkedin.com/in/garurprani" target="_blank" class="cb-pro-social-link">
                        <span class="dashicons dashicons-linkedin"></span>
                        LinkedIn
                    </a>
                    <a href="https://t.me/garurprani" target="_blank" class="cb-pro-social-link">
                        <span class="dashicons dashicons-format-chat"></span>
                        Telegram
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bug Report Section - RIGHT -->
    <div class="cb-pro-bug-report-section">
        <h3>Report a Bug</h3>
        <div class="cb-pro-bug-report-card">
            <div class="cb-pro-bug-report-info">
                <span class="dashicons dashicons-flag"></span>
                <h4>Found an Issue?</h4>
                <p>Help me improve Comment Beautifier by reporting any bugs or issues you encounter.</p>
                
                <div class="cb-pro-bug-report-form">
                    <button type="button" id="cb-pro-report-bug-btn" class="cb-pro-btn primary">

    <span class="dashicons dashicons-email"></span>
    <a href="mailto:garurprani@gmail.com" style="text-decoration:none; color:inherit;">
        Report a Bug via Email
    </a>
</button>

                    <p class="cb-pro-bug-report-note">
                        When reporting a bug, please include:
                    </p>
                    <ul class="cb-pro-bug-report-checklist">
                        <li>Detailed description of the issue</li>
                        <li>Steps to reproduce the problem</li>
                        <li>Screenshots (if possible)</li>
                        <li>Your WordPress version</li>
                        <li>PHP version</li>
                        <li>Plugin version (v2.2)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
        <?php
        $this->render_page_footer();
    }

/**
 * Common page header
 */
private function render_page_header($page_title = '') {
    $current_filter = current_filter();
    $is_about_page = strpos($current_filter, 'comment-beautifier-pro-about') !== false;
    $is_settings_page = strpos($current_filter, 'comment-beautifier-pro-settings') !== false;
    
    if (!$is_about_page && !$is_settings_page) {
        $totals = wp_count_comments();
        $beautified_count = $this->count_comments_with_backup();
    }
    ?>
    <div class="wrap cb-pro-wrap">
        <div class="cb-pro-header">
            <div class="cb-pro-title-section">
                <h1><span class=""></span> Comment Beautifier</h1>
                <p class="cb-pro-subtitle"> Clean up and transform spammy comments into friendly conversations</p>
            </div>
        </div>

        <?php if (!$is_about_page && !$is_settings_page): ?>
        <!-- Stats Cards - Only show on non-about and non-settings pages -->
        <div class="cb-pro-stats-grid">
            <div class="cb-pro-stat-card">
                <div class="cb-pro-stat-icon total">
                    <span class="dashicons dashicons-admin-comments"></span>
                </div>
                <div class="cb-pro-stat-content">
                    <div class="cb-pro-stat-number" id="cb-stat-total"><?php echo intval($totals->moderated); ?></div>
                    <div class="cb-pro-stat-label">Awaiting Moderation</div>
                </div>
            </div>
            
            <div class="cb-pro-stat-card">
                <div class="cb-pro-stat-icon beautified">
                    <span class="dashicons dashicons-yes"></span>
                </div>
                <div class="cb-pro-stat-content">
                    <div class="cb-pro-stat-number" id="cb-stat-beautified"><?php echo intval($beautified_count); ?></div>
                    <div class="cb-pro-stat-label">Beautified</div>
                </div>
            </div>
            
            <div class="cb-pro-stat-card">
                <div class="cb-pro-stat-icon awaiting">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="cb-pro-stat-content">
                    <div class="cb-pro-stat-number" id="cb-stat-awaiting"><?php echo intval($totals->moderated); ?></div>
                    <div class="cb-pro-stat-label">Pending Review</div>
                </div>
            </div>
            
            <div class="cb-pro-stat-card">
                <div class="cb-pro-stat-icon withlink">
                    <span class="dashicons dashicons-admin-links"></span>
                </div>
                <div class="cb-pro-stat-content">
                    <div class="cb-pro-stat-number" id="cb-stat-withlink"><?php
                        $withlink = 0;
                        $sample_comments = get_comments(array('number' => 100, 'status' => 'hold'));
                        foreach ($sample_comments as $c) if ($this->comment_has_link($c->comment_content)) $withlink++;
                        echo intval($withlink);
                    ?></div>
                    <div class="cb-pro-stat-label">With Links</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php
}

    /**
     * Common page footer
     */
    private function render_page_footer() {
        ?>
        <!-- Clean Modern Footer -->
        <div class="cb-pro-footer">
            <div class="cb-pro-footer-main">
                <div class="cb-pro-footer-brand">
                    <span class="cb-pro-footer-logo">💬</span>
                    <div class="cb-pro-footer-title">
                        <strong>Comment Beautifier</strong>
                        <span>v2.2 • By Ujjwal Raj aka garurprani</span>
                    </div>
                </div>
                
                <div class="cb-pro-footer-links">
                    <a href="https://github.com/garurprani" target="_blank" title="GitHub">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>
                        </svg>
                    </a>
                    <a href="https://linkedin.com/in/garurprani" target="_blank" title="LinkedIn">
                        <span class="dashicons dashicons-linkedin"></span>
                    </a>
                    <a href="https://t.me/garurprani" target="_blank" title="Telegram">
                        <span class="dashicons dashicons-format-chat"></span>
                    </a>
                </div>
            </div>
            
            <div class="cb-pro-footer-bottom">
                <span><?php echo esc_html__('Transform spammy comments into friendly conversations', 'comment-beautifier'); ?></span>
                <span>&copy; <?php echo esc_html(gmdate('Y')); ?> Comment Beautifier</span>
            </div>
        </div>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render comments section for a specific page
     */
    private function render_comments_section($comments, $page_type, $current_page, $per_page, $total_comments) {
        $total_pages = ceil($total_comments / $per_page);
        $is_awaiting_page = ($page_type === 'awaiting');
        ?>
        <!-- Enhanced Filter Bar -->
        <div class="cb-pro-filter-bar">
            <div class="cb-pro-search-box">
                <span class="dashicons dashicons-search"></span>
                <input id="cb-search-input" type="search" placeholder="Search comments, authors, or text...">
            </div>

            <div class="cb-pro-filter-controls">
                <div class="cb-pro-filter-group">
                    <label class="cb-pro-filter-toggle">
                        <input type="checkbox" id="cb-filter-has-profile-url" />
                        <span class="cb-pro-filter-label">Has Profile URL</span>
                    </label>
                </div>

                <div class="cb-pro-bulk-actions">
                    <button id="cb-select-has-link" class="cb-pro-btn secondary">
                        <span class="dashicons dashicons-admin-links"></span>
                        Select With Links
                    </button>
                    <button id="cb-select-visible" class="cb-pro-btn secondary">
                        <span class="dashicons dashicons-visibility"></span>
                        Select Visible
                    </button>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="cb-pro-action-bar">
            <div class="cb-pro-selection-info">
                <label class="cb-pro-checkbox">
                    <input type="checkbox" id="cb-check-all">
                    <span class="cb-pro-checkmark"></span>
                    Select All Comments
                </label>
                <span class="cb-pro-selection-count">0 selected</span>
            </div>

            <div class="cb-pro-action-controls">
                <?php if (!$is_awaiting_page): ?>
                <select id="cb-mode-select" class="cb-pro-select">
                    <option value="both">Beautify Content & Author</option>
                    <option value="content">Beautify Content Only</option>
                    <option value="name">Beautify Name Only</option>
                </select>
                <?php endif; ?>
                
                <div class="cb-pro-action-buttons">
                    <?php if (!$is_awaiting_page): ?>
                    <button id="cb-beautify-btn" class="cb-pro-btn primary">
                        <span class="dashicons dashicons-update"></span>
                        Beautify
                    </button>
                    <button id="cb-remove-urls-btn" class="cb-pro-btn warning">
                        <span class="dashicons dashicons-trash"></span>
                        Remove URLs Only
                    </button>
                    <button id="cb-remove-profile-urls" class="cb-pro-btn warning">
                        <span class="dashicons dashicons-no"></span>
                        Remove Profile URLs
                    </button>
                    <?php endif; ?>
                    
                    <button id="cb-approve-btn" class="cb-pro-btn success">
                        <span class="dashicons dashicons-yes"></span>
                        Approve
                    </button>
                </div>
            </div>
        </div>
                    
        <div id="cb-status" class="cb-pro-status-message"></div>

        <!-- Comments Grid -->
        <div class="cb-pro-comments-grid" id="cb-comments-container">
            <?php
            if (empty($comments)) {
                $message = $is_awaiting_page 
                    ? 'There are no comments awaiting moderation at the moment.'
                    : 'There are no non-beautified comments to display. All comments have been processed.';
                
                echo '<div class="cb-pro-empty-state">
                        <span class="dashicons dashicons-admin-comments"></span>
                        <h3>No comments found</h3>
                        <p>' . esc_html($message) . '</p>
                      </div>';
            } else {
                foreach ($comments as $c) :
                    $has_link = $this->comment_has_link($c->comment_content) ? '1' : '0';
                    $has_profile = !empty($c->comment_author_url) ? '1' : '0';
                    $comment_status = $c->comment_approved == '1' ? 'approved' : ($c->comment_approved == 'spam' ? 'spam' : 'awaiting');
                    $comment_excerpt = wp_trim_words(wp_strip_all_tags($c->comment_content), 22, '...');
                    $post_permalink = get_permalink($c->comment_post_ID);
                    $post_title = get_the_title($c->comment_post_ID);
                    $profile_domain = '';
                    if ($has_profile) {
                        $p = wp_parse_url($c->comment_author_url);
                        $profile_domain = isset($p['host']) ? $p['host'] : preg_replace('#https?://#i','', rtrim($c->comment_author_url,'/'));
                    }
                    $comment_link_domain = $this->get_domain_from_text($c->comment_content);

                    // Status classes
                    $status_class = 'status-awaiting';

                    // Beautified badge
                    $is_beautified = metadata_exists('comment', $c->comment_ID, '_cb_backup_content');
                    ?>
                    <div class="cb-pro-comment-card <?php echo esc_attr($status_class); ?>" 
                         data-comment-id="<?php echo esc_attr($c->comment_ID); ?>"
                         data-has-link="<?php echo esc_attr($has_link); ?>"
                         data-has-profile="<?php echo esc_attr($has_profile); ?>"
                         data-status="<?php echo esc_attr($comment_status); ?>"
                         data-beautified="<?php echo $is_beautified ? '1' : '0'; ?>">

                        <div class="cb-pro-card-header">
                            <label class="cb-pro-checkbox small">
                                <input type="checkbox" class="cb-check" name="comment_ids[]" value="<?php echo esc_attr($c->comment_ID); ?>">
                                <span class="cb-pro-checkmark"></span>
                            </label>

                            <div class="cb-pro-comment-meta">
                                <div class="cb-pro-author-info">
                                    <div class="cb-pro-author-name"><?php echo esc_html($c->comment_author); ?></div>
                                    <div class="cb-pro-author-email"><?php echo esc_html($c->comment_author_email); ?></div>
                                </div>

                                <div class="cb-pro-status-badges">
                                    <?php if ($is_beautified): ?>
                                        <span class="cb-pro-badge beautified">
                                            <span class="dashicons dashicons-yes"></span>
                                            Beautified
                                        </span>
                                    <?php else: ?>
                                        <span class="cb-pro-badge not-beautified">
                                            <span class="dashicons dashicons-no"></span>
                                            Not Beautified
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($has_link): ?>
                                        <span class="cb-pro-badge has-link">
                                            <span class="dashicons dashicons-admin-links"></span>
                                            Has Link
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($has_profile): ?>
                                        <span class="cb-pro-badge has-profile">
                                            <span class="dashicons dashicons-admin-users"></span>
                                            Has Profile
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                                    
                        <div class="cb-pro-comment-content">
                            <div class="cb-pro-comment-text">
                                <?php echo wp_kses_post(wpautop($c->comment_content)); ?>
                            </div>
                                
                            <?php if (!empty($comment_link_domain)): ?>
                                <div class="cb-pro-link-domain">
                                    <span class="dashicons dashicons-external"></span>
                                    <?php echo esc_html($comment_link_domain); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                                
                        <div class="cb-pro-card-footer">
                            <div class="cb-pro-post-info">
                                <?php if ($post_permalink) : ?>
                                    <a href="<?php echo esc_url($post_permalink); ?>" class="cb-pro-post-link" target="_blank" data-post-title="<?php echo esc_attr($post_title); ?>">
                                        <span class="dashicons dashicons-admin-post"></span>
                                        View Post
                                    </a>
                                <?php else: ?>
                                    <span class="cb-pro-no-post">—</span>
                                <?php endif; ?>
                            </div>
                                
                            <div class="cb-pro-card-actions">
                                <?php if (!$is_awaiting_page && $has_link == '1'): ?>
                                    <button class="cb-pro-action-btn remove-url" data-id="<?php echo esc_attr($c->comment_ID); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                        Remove URLs
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (!$is_awaiting_page && $has_profile == '1'): ?>
                                    <button class="cb-pro-action-btn remove-profile" data-id="<?php echo esc_attr($c->comment_ID); ?>">
                                        <span class="dashicons dashicons-no"></span>
                                        Remove Profile
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                endforeach;
            }
            ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="cb-pro-pagination">
            <div class="cb-pro-pagination-info">
                <?php 
                printf(
                    /* translators: 1: current page number, 2: total number of pages */
                    esc_html__('Page %1$s of %2$s', 'comment-beautifier'), 
                    esc_html((string)$current_page), 
                    esc_html((string)$total_pages)
                ); 
                ?>
            </div>
            <div class="cb-pro-pagination-controls">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo esc_url(add_query_arg(array('cb_page' => $current_page - 1, '_wpnonce' => wp_create_nonce('cb_pagination_nonce')))); ?>" class="cb-pro-btn secondary">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        <?php esc_html_e('Previous', 'comment-beautifier'); ?>
                    </a>
                <?php endif; ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo esc_url(add_query_arg(array('cb_page' => $current_page + 1, '_wpnonce' => wp_create_nonce('cb_pagination_nonce')))); ?>" class="cb-pro-btn secondary">
                        <?php 
                        /* translators: Next page button text */
                        esc_html_e('Next', 'comment-beautifier'); 
                        ?>
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    /* ---------- AJAX: Save settings ---------- */
    public function ajax_save_settings() {
        check_ajax_referer('cb_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);      

        $comments_raw = isset($_POST['comments']) ? sanitize_textarea_field(wp_unslash($_POST['comments'])) : '';
        $names_raw = isset($_POST['names']) ? sanitize_textarea_field(wp_unslash($_POST['names'])) : '';
        $comments_per_page = isset($_POST['comments_per_page']) ? intval($_POST['comments_per_page']) : 20;
        $remove_urls = isset($_POST['remove_urls']) ? sanitize_text_field(wp_unslash($_POST['remove_urls'])) : '0';
        $only_links = isset($_POST['only_links']) ? sanitize_text_field(wp_unslash($_POST['only_links'])) : '0';     

        update_option($this->option_comments_key, $comments_raw);
        update_option($this->option_names_key, $names_raw);
        update_option($this->option_comments_per_page_key, $comments_per_page);
        update_option($this->option_remove_urls_key, $remove_urls);
        update_option($this->option_only_links_key, $only_links);     

        wp_send_json_success('Settings saved');
    }

    /* ---------- AJAX: Beautify comments ---------- */
    public function ajax_beautify_comments() {
        check_ajax_referer('cb_nonce', 'nonce');
        if (!current_user_can('moderate_comments')) wp_send_json_error('Unauthorized', 403);

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        $change_author = isset($_POST['change_author']) && $_POST['change_author'] === '1';
        $change_content = isset($_POST['change_content']) && $_POST['change_content'] === '1';
        $remove_urls = isset($_POST['remove_urls']) && ($_POST['remove_urls'] == '1' || $_POST['remove_urls'] === 'true' );
        $move_with_links = isset($_POST['move_with_links']) && ($_POST['move_with_links'] == '1' || $_POST['move_with_links'] === 'true' );
        $auto_approve = isset($_POST['auto_approve']) && ($_POST['auto_approve'] == '1' || $_POST['auto_approve'] === 'true' );
        $remove_profile = isset($_POST['remove_profile']) && ($_POST['remove_profile'] == '1' || $_POST['remove_profile'] === 'true' );
        
        $per_row_remove = array();
        if (!empty($_POST['per_row_remove'])) {
            $decoded = json_decode(sanitize_text_field(wp_unslash($_POST['per_row_remove'])), true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) $per_row_remove[intval($k)] = intval($v);
            }
        }

        if (empty($ids)) wp_send_json_error('No comments selected');

        $canned_comments = get_option($this->option_comments_key, "Thanks for the comment!\nNice work!");
        $canned_names = get_option($this->option_names_key, "Aman\nRajiv\nGuest");
        $comments_pool = array_filter(array_map('trim', explode("\n", $canned_comments)));
        $names_pool = array_filter(array_map('trim', explode("\n", $canned_names)));

        $updated = 0;
        foreach ($ids as $id) {
            $comment = get_comment($id);
            if (!$comment) continue;

            if ($move_with_links && !$this->comment_has_link($comment->comment_content)) continue;

            // Always create backup if it doesn't exist
            if (!metadata_exists('comment', $id, '_cb_backup_content')) {
                add_comment_meta($id, '_cb_backup_content', $comment->comment_content, true);
            }
            if (!metadata_exists('comment', $id, '_cb_backup_author')) {
                add_comment_meta($id, '_cb_backup_author', $comment->comment_author, true);
            }

            $new_content = $comment->comment_content;
            $new_author = $comment->comment_author;
            
            if ($change_content && !empty($comments_pool)) {
                $new_content = $comments_pool[array_rand($comments_pool)];
            }
            
            if ($change_author && !empty($names_pool)) {
                $new_author = sanitize_text_field($names_pool[array_rand($names_pool)]);
            }

            $per_row_flag = isset($per_row_remove[$id]) && $per_row_remove[$id] == 1;
            if ($remove_urls || $per_row_flag) {
                $new_content = $this->strip_urls($new_content);
            }

            $data = array('comment_ID' => $id);
            
            if ($change_content) {
                $data['comment_content'] = wp_kses_post($new_content);
            }
            
            if ($change_author) {
                $data['comment_author'] = $new_author;
            }

            // Remove profile URL if requested
            if ($remove_profile) {
                $data['comment_author_url'] = '';
            }

            $result = wp_update_comment($data);
            
            // Auto-approve if requested
            if ($result && $auto_approve && $comment->comment_approved != '1') {
                wp_set_comment_status($id, 'approve');
            }
            
            if ($result) $updated++;
        }

        // Clear cache after modifications
        $this->clear_comments_cache();

        wp_send_json_success(array('updated' => $updated));
    }

    /* ---------- AJAX: Approve comments ---------- */
    public function ajax_approve_comments() {
        check_ajax_referer('cb_nonce', 'nonce');
        if (!current_user_can('moderate_comments')) wp_send_json_error('Unauthorized', 403);

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        if (empty($ids)) wp_send_json_error('No comments selected');

        $approved = 0;
        foreach ($ids as $id) {
            $comment = get_comment($id);
            if (!$comment) continue;
            if ($comment->comment_approved == '1') continue;
            $r = wp_set_comment_status($id, 'approve');
            if ($r) $approved++;
        }

        // Clear cache after modifications
        $this->clear_comments_cache();

        wp_send_json_success(array('approved' => $approved));
    }

    /* ---------- AJAX: Unapprove comments ---------- */
    public function ajax_unapprove_comments() {
        check_ajax_referer('cb_nonce', 'nonce');
        if (!current_user_can('moderate_comments')) wp_send_json_error('Unauthorized', 403);

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        if (empty($ids)) wp_send_json_error('No comments selected');

        $unapproved = 0;
        foreach ($ids as $id) {
            $comment = get_comment($id);
            if (!$comment) continue;
            if ($comment->comment_approved != '1') continue;
            $r = wp_set_comment_status($id, 'hold');
            if ($r) $unapproved++;
        }

        // Clear cache after modifications
        $this->clear_comments_cache();

        wp_send_json_success(array('unapproved' => $unapproved));
    }

    /* ---------- AJAX: Remove profile URLs ---------- */
    public function remove_author_urls() {
        check_ajax_referer('cb_nonce', 'nonce');
        if (!current_user_can('moderate_comments')) wp_send_json_error('Unauthorized', 403);

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        if (empty($ids)) wp_send_json_error('No comments selected');

        $removed = 0;
        foreach ($ids as $id) {
            $comment = get_comment($id);
            if (!$comment) continue;
            if (empty($comment->comment_author_url)) continue;
            $r = wp_update_comment(array('comment_ID' => $id, 'comment_author_url' => ''));
            if ($r) $removed++;
        }

        // Clear cache after modifications
        $this->clear_comments_cache();

        wp_send_json_success(array('removed' => $removed));
    }

    /* ---------- AJAX: Remove URLs only ---------- */
    public function ajax_remove_urls_only() {
        check_ajax_referer('cb_nonce', 'nonce');
        if (!current_user_can('moderate_comments')) wp_send_json_error('Unauthorized', 403);

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        if (empty($ids)) wp_send_json_error('No comments selected');

        $updated = 0;
        foreach ($ids as $id) {
            $comment = get_comment($id);
            if (!$comment) continue;
            
            // Always create backup if it doesn't exist
            if (!metadata_exists('comment', $id, '_cb_backup_content')) {
                add_comment_meta($id, '_cb_backup_content', $comment->comment_content, true);
            }
            
            $new_content = $this->strip_urls($comment->comment_content);
            
            $data = array(
                'comment_ID' => $id,
                'comment_content' => wp_kses_post($new_content)
            );
            
            $result = wp_update_comment($data);
            if ($result) $updated++;
        }

        // Clear cache after modifications
        $this->clear_comments_cache();

        wp_send_json_success(array('updated' => $updated));
    }

    

    /* ---------- Helpers ---------- */
    private function comment_has_link($text) {
        if (empty($text)) return false;
        if (preg_match('#https?://\S+#i', $text)) return true;
        if (preg_match('#www\.\S+#i', $text)) return true;
        if (preg_match('#\b[\w-]+\.(com|net|org|io|in|co|info)\b#i', $text)) return true;
        return false;
    }

    private function strip_urls($text) {
        $text = preg_replace('#https?://\S+\b#i', '', $text);
        $text = preg_replace('#www\.\S+\b#i', '', $text);
        $text = preg_replace('#\b[\w-]+\.(com|net|org|info|io|in|co)\b#i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}

Comment_Beautifier_Pro::init();
?>
