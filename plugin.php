<?php

/*
  Plugin Name: Hyper Cache Tweaks
  Plugin URI: https://www.satollo.net/plugins/hyper-cache
  Description: A easy to configure and efficient cache to increase the speed of your blog.
  Version: 3.3.9-tweaks-1
  Author: Stefano Lissa
  Author URI: https://www.satollo.net
  Disclaimer: Use at your own risk. No warranty expressed or implied is provided.
  Contributors: satollo
 */

new HyperCache();

class HyperCache
{
    public $post_id;
    public $options;
    public $ob_started = false;
    public static $instance;

    const MOBILE_AGENTS = 'android|iphone|iemobile|up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|ipod|xoom|blackberry';

    public function __construct()
    {
        self::$instance = $this;
        $this->options = get_option('hyper-cache-tweaks', []);

        register_activation_hook('hyper-cache-tweaks/plugin.php', [$this, 'hook_activate']);
        register_deactivation_hook('hyper-cache-tweaks/plugin.php', [$this, 'hook_deactivate']);

        add_action('edit_post', [$this, 'hook_edit_post'], 1);
        add_action('save_post', [$this, 'hook_save_post'], 1);
        add_action('comment_post', [$this, 'hook_comment_post'], 1, 2);
        add_action('wp_update_comment_count', [$this, 'hook_wp_update_comment_count'], 1);
        add_action('bbp_new_reply', [$this, 'hook_bbp_new_reply']);
        add_action('bbp_new_topic', [$this, 'hook_bbp_new_topic']);
        add_action('wp', [$this, 'hook_wp']);

        add_action('hyper_cache_clean', [$this, 'hook_hyper_cache_clean']);

        add_action('autoptimize_action_cachepurged', [$this, 'hook_autoptimize_action_cachepurged']);

        if (!is_admin()) {
            // The function must exists or the advanced-cache.php has been removed
            if (!empty($GLOBALS['hyper_cache_is_mobile']) && !empty($this->options['theme'])) {
                add_filter('stylesheet', [$this, 'hook_get_stylesheet']);
                add_filter('template', [$this, 'hook_get_template']);
            }
            add_action('template_redirect', [$this, 'hook_template_redirect'], 0);
        } else {
            add_action('admin_menu', [$this, 'hook_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'hook_admin_enqueue_scripts']);
        }
    }

    public function hook_activate()
    {
        if (!isset($this->options['mobile'])) {
            $this->options['mobile'] = 0;
        }
        if (!isset($this->options['folder'])) {
            $this->options['folder'] = '';
        }
        if (!isset($this->options['max_age'])) {
            $this->options['max_age'] = 24;
        }
        if (!isset($this->options['clean_last_posts'])) {
            $this->options['clean_last_posts'] = 0;
        }
        if (!isset($this->options['mobile_agents'])) {
            $this->options['mobile_agents'] = explode('|', self::MOBILE_AGENTS);
        }
        if (!isset($this->options['reject_agents'])) {
            $this->options['reject_agents'] = [];
        }
        if (!isset($this->options['reject_cookies'])) {
            $this->options['reject_cookies'] = [];
        }
        if (!isset($this->options['reject_uris'])) {
            $this->options['reject_uris'] = [];
        }
        if (!isset($this->options['reject_uris_exact'])) {
            $this->options['reject_uris_exact'] = [];
        }
        if (!isset($this->options['clean_last_posts'])) {
            $this->options['clean_last_posts'] = 0;
        }

        if (!isset($this->options['https'])) {
            $this->options['https'] = 1;
        }

        if (!isset($this->options['theme'])) {
            $this->options['theme'] = '';
        }

        if (!isset($this->options['browser_cache_hours'])) {
            $this->options['browser_cache_hours'] = 24;
        }

        update_option('hyper-cache-tweaks', $this->options);

        @wp_mkdir_p(WP_CONTENT_DIR.'/cache/hyper-cache-tweaks');

        if (is_file(WP_CONTENT_DIR.'/advanced-cache.php')) {
            $this->build_advanced_cache();
            touch(WP_CONTENT_DIR.'/advanced-cache.php');
        }

        if (!wp_next_scheduled('hyper_cache_clean')) {
            wp_schedule_event(time() + 300, 'hourly', 'hyper_cache_clean');
        }
    }

    public function hook_deactivate()
    {
        // Reset the file without deleting it to avoid to lost manually assigned permissions
        file_put_contents(WP_CONTENT_DIR.'/advanced-cache.php', '');
        wp_clear_scheduled_hook('hyper_cache_clean');
    }

    public function hook_admin_enqueue_scripts()
    {
        if (!isset($_GET['page']) || 0 !== strpos($_GET['page'], 'hyper-cache-tweaks/')) {
            return;
        }
        wp_enqueue_style('hyper_cache', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.css');
        wp_enqueue_script('jquery-ui-tabs');
    }

    public function hook_admin_menu()
    {
        add_options_page('Hyper Cache', 'Hyper Cache', 'manage_options', 'hyper-cache-tweaks/options.php');
    }

    public function build_advanced_cache()
    {
        $advanced_cache = file_get_contents(__DIR__.'/advanced-cache.php');
        $advanced_cache = str_replace('HC_MOBILE_AGENTS', implode('|', array_map('preg_quote', $this->options['mobile_agents'])), $advanced_cache);
        $advanced_cache = str_replace('HC_MOBILE', $this->options['mobile'], $advanced_cache);

        $advanced_cache = str_replace('HC_REJECT_AGENTS_ENABLED', empty($this->options['reject_agents_enabled']) ? 0 : 1, $advanced_cache);
        $advanced_cache = str_replace('HC_REJECT_AGENTS', implode('|', array_map('preg_quote', $this->options['reject_agents'])), $advanced_cache);

        $advanced_cache = str_replace('HC_REJECT_COOKIES_ENABLED', empty($this->options['reject_cookies_enabled']) ? 0 : 1, $advanced_cache);
        $advanced_cache = str_replace('HC_REJECT_COOKIES', implode('|', array_map('preg_quote', $this->options['reject_cookies'])), $advanced_cache);

        $advanced_cache = str_replace('HC_GZIP', isset($this->options['gzip']) ? 1 : 0, $advanced_cache);
        $advanced_cache = str_replace('HC_FOLDER', $this->get_folder(), $advanced_cache);
        $advanced_cache = str_replace('HC_MAX_AGE', $this->options['max_age'], $advanced_cache);
        $advanced_cache = str_replace('HC_REJECT_COMMENT_AUTHORS', isset($this->options['reject_comment_authors']) ? 1 : 0, $advanced_cache);

        $advanced_cache = str_replace('HC_BROWSER_CACHE_HOURS', $this->options['browser_cache_hours'], $advanced_cache);
        $advanced_cache = str_replace('HC_BROWSER_CACHE', isset($this->options['browser_cache']) ? 1 : 0, $advanced_cache);

        $advanced_cache = str_replace('HC_HTTPS', (int) $this->options['https'], $advanced_cache);
        $advanced_cache = str_replace('HC_READFILE', isset($this->options['readfile']) ? 1 : 0, $advanced_cache);

        $advanced_cache = str_replace('HC_SERVE_EXPIRED_TO_BOT', isset($this->options['serve_expired_to_bots']) ? 1 : 0, $advanced_cache);
        $advanced_cache = str_replace('HC_BOTS_IGNORE_NOCACHE', isset($this->options['bots_ignore_nocache']) ? 1 : 0, $advanced_cache);

        return file_put_contents(WP_CONTENT_DIR.'/advanced-cache.php', $advanced_cache);
    }

    public function hook_bbp_new_reply($reply_id)
    {
        $topic_id = bbp_get_reply_topic_id($reply_id);
        $topic_url = bbp_get_topic_permalink($topic_id);
        $dir = $this->get_folder().'/'.substr($topic_url, strpos($topic_url, '://') + 3).'/';
        $this->remove_dir($dir);

        $forum_id = bbp_get_reply_forum_id($reply_id);
        $forum_url = bbp_get_forum_permalink($forum_id);
        $dir = $this->get_folder().'/'.substr($forum_url, strpos($forum_url, '://') + 3).'/';
        $this->remove_dir($dir);
    }

    public function hook_bbp_new_topic($topic_id)
    {
        $topic_url = bbp_get_topic_permalink($topic_id);
        $dir = $this->get_folder().'/'.substr($topic_url, strpos($topic_url, '://') + 3).'/';
        $this->remove_dir($dir);

        $forum_id = bbp_get_topic_forum_id($topic_id);
        $forum_url = bbp_get_forum_permalink($forum_id);
        $dir = $this->get_folder().'/'.substr($forum_url, strpos($forum_url, '://') + 3).'/';
        $this->remove_dir($dir);
    }

    public function hook_autoptimize_action_cachepurged()
    {
        $this->clean();
    }

    public function hook_comment_post($comment_id, $status)
    {
        if (1 === $status) {
            $comment = get_comment($comment_id);
            $this->clean_post($comment->comment_post_ID, isset($this->options['clean_archives_on_comment']), isset($this->options['clean_home_on_comment']));
        }
    }

    public function hook_wp_update_comment_count($post_id)
    {
        if ($this->post_id == $post_id) {
            return;
        }
        $this->clean_post($post_id, isset($this->options['clean_archives_on_comment']), isset($this->options['clean_home_on_comment']));
    }

    public function hook_save_post($post_id)
    {
    }

    /**
     * edit_post is called even when a comment is added, but the comment hook prevent the execution of
     * edit_post like if the post has been modified.
     */
    public function hook_edit_post($post_id)
    {
        $this->clean_post($post_id, isset($this->options['clean_archives_on_post_edit']), isset($this->options['clean_home_on_post_edit']));
    }

    public function clean()
    {
        $folder = $this->get_folder();
        $this->remove_dir($folder);
        do_action('hyper_cache_purged');
    }

    public function clean_post($post_id, $clean_archives = true, $clean_home = true)
    {
        // When someone deletes the advaced-cache.php file
        if (!\function_exists('hyper_cache_sanitize_uri')) {
            return;
        }

        if ($this->post_id == $post_id) {
            return;
        }

        $status = get_post_status($post_id);
        if ('publish' != $status && 'trash' != $status) {
            return;
        }

        if ('trash' == $status) {
            $clean_archives = true;
            $clean_home = true;
        }

        $this->post_id = $post_id;
        $folder = trailingslashit($this->get_folder());
        $dir = $folder.$this->post_folder($post_id);
        $this->remove_dir($dir);

        do_action('hyper_cache_flush', $post_id, get_permalink($post_id));

        if (0 != $this->options['clean_last_posts']) {
            $posts = get_posts(['numberposts' => $this->options['clean_last_posts']]);
            foreach ($posts as $post) {
                $dir = $folder.$this->post_folder($post_id);
                $this->remove_dir($dir);
            }
        }

        $dir = $folder.substr(get_option('home'), strpos(get_option('home'), '://') + 3);

        if ($clean_home) {
            @unlink($dir.'/index.html');
            @unlink($dir.'/index.html.gz');
            @unlink($dir.'/index-https.html');
            @unlink($dir.'/index-https.html.gz');
            @unlink($dir.'/index-mobile.html');
            @unlink($dir.'/index-mobile.html.gz');
            @unlink($dir.'/index-https-mobile.html');
            @unlink($dir.'/index-https-mobile.html.gz');

            $this->remove_dir($dir.'/feed/');
            // Home subpages
            $this->remove_dir($dir.'/page/');
            // Blog Pages (ERICH)
            if ((int) (get_option('page_for_posts')) > 0) {
                $url = get_permalink(get_option('page_for_posts'));
                $this->remove_dir($dir.parse_url($url, PHP_URL_PATH));
            }
        }

        //@unlink($dir . '/robots.txt');
        if ($clean_archives) {
            $base = get_option('category_base');
            if (empty($base)) {
                $base = 'category';
            }
            $this->remove_dir($dir.'/'.$base.'/');

            $permalink_structure = get_option('permalink_structure');
            //$this->log(substr($permalink_structure, 0, 11));
            if ('/%category%' == substr($permalink_structure, 0, 11)) {
                $categories = get_categories();
                //$this->log(print_r($categories, true));
                foreach ($categories as $category) {
                    //$this->log('Removing: ' . $dir . '/' . $category->slug . '/');
                    $this->remove_page($dir.'/'.$category->slug);
                }
            }

            $base = get_option('tag_base');
            if (empty($base)) {
                $base = 'tag';
            }
            $this->remove_dir($dir.'/'.$base.'/');

            $this->remove_dir($dir.'/type/');

            $this->remove_dir($dir.'/'.date('Y').'/');
        }
    }

    /*
     * Runs only if $GLOBALS['hyper_cache_is_mobile'] is true
     */

    public function hook_get_stylesheet($stylesheet = '')
    {
        $theme = wp_get_theme($this->options['theme']);
        if (!$theme->exists()) {
            return $stylesheet;
        }

        return $theme->stylesheet;
    }

    /*
     * Runs only if $GLOBALS['hyper_cache_is_mobile'] is true
     *
     * var WP_Theme $theme
     */

    public function hook_get_template($template)
    {
        $theme = wp_get_theme($this->options['theme']);
        if (!$theme->exists()) {
            return $template;
        }

        return $theme->template;
    }

    public function hook_wp()
    {
        if (is_404()) {
            if (isset($this->options['reject_404'])) {
                $GLOBALS['hyper_cache_stop'] = true;
            } else {
                $file = $this->get_folder().'/'.$GLOBALS['hc_host'].'/404'.$GLOBALS['hyper_cache_group'].'.html';

                if (file_exists($file) && (0 == $this->options['max_age'] || filemtime($file) > time() - $this->options['max_age'] * 3600)) {
                    header('Content-Type: text/html;charset=UTF-8');
                    // For some reason it seems more performant than readfile...
                    header('X-Cache: hit,404,wp');
                    echo file_get_contents($file);
                    die();
                }
            }
        }
    }

    public function hook_template_redirect()
    {
        if ($this->ob_started) {
            return;
        }

        $home_root = parse_url(get_option('home'), PHP_URL_PATH);

        if (!empty($GLOBALS['hyper_cache_stop'])) {
            $GLOBALS['hyper_cache_stop'] = true;
        } elseif ('POST' == $_SERVER['REQUEST_METHOD']) {
            $GLOBALS['hyper_cache_stop'] = true;
        } elseif (!empty($_SERVER['QUERY_STRING']) && hyper_cache_ignoreqs()) {
            $GLOBALS['hyper_cache_stop'] = true;
        } elseif (is_user_logged_in()) {
            $GLOBALS['hyper_cache_stop'] = true;
        } elseif (is_trackback()) {
            $GLOBALS['hyper_cache_stop'] = true;
        }

        // Global feed and global comment feed
        elseif (isset($this->options['reject_feeds']) && is_feed()) {
            $GLOBALS['hyper_cache_stop'] = true;
        }

        // Single post/page feed
        elseif (isset($this->options['reject_comment_feeds']) && is_comment_feed()) {
            $GLOBALS['hyper_cache_stop'] = true;
        } elseif (isset($this->options['reject_home']) && is_front_page()) {
            $GLOBALS['hyper_cache_stop'] = true;
        } elseif (is_robots()) {
            $GLOBALS['hyper_cache_stop'] = true;
        }

        if (\defined('SID') && SID != '') {
            $GLOBALS['hyper_cache_stop'] = true;
        }

        // Compatibility with XML Sitemap 4.x
        elseif (substr($_SERVER['REQUEST_URI'], 0, \strlen($home_root) + 8) == ($home_root.'/sitemap')) {
            $GLOBALS['hyper_cache_stop'] = true;
        }
        // Never cache pages generated for administrator (to be patched to see if the user is an administrator)
        //if (get_current_user_id() == 1) return;
        elseif (is_404()) {
            if (isset($this->options['reject_404'])) {
                $GLOBALS['hyper_cache_stop'] = true;
            } else {
                $file = $this->get_folder().'/'.$GLOBALS['hc_host'].'/404'.$GLOBALS['hyper_cache_group'].'.html';

                if (file_exists($file) && (0 == $this->options['max_age'] || filemtime($file) > time() - $this->options['max_age'] * 3600)) {
                    header('Content-Type: text/html;charset=UTF-8');
                    // For some reason it seems more performant than readfile...
                    header('X-Cache: hit,404,template_redirect');
                    echo file_get_contents($file);
                    die();
                }
            }
        }

        if (!$GLOBALS['hyper_cache_stop'] && substr($_SERVER['REQUEST_URI'], 0, \strlen($home_root) + 4) == ($home_root.'/wp-')) {
            $GLOBALS['hyper_cache_stop'] = true;
        }

        // URLs to reject (exact)
        if (!$GLOBALS['hyper_cache_stop'] && isset($this->options['reject_uris_exact_enabled'])) {
            if (\is_array($this->options['reject_uris_exact'])) {
                foreach ($this->options['reject_uris_exact'] as &$uri) {
                    if ($_SERVER['REQUEST_URI'] == $uri) {
                        $GLOBALS['hyper_cache_stop'] = true;
                        break;
                    }
                }
            }
        }

        // URLs to reject
        if (!$GLOBALS['hyper_cache_stop'] && isset($this->options['reject_uris_enabled'])) {
            if (\is_array($this->options['reject_uris'])) {
                foreach ($this->options['reject_uris'] as &$uri) {
                    if (0 === strpos($_SERVER['REQUEST_URI'], $uri)) {
                        $GLOBALS['hyper_cache_stop'] = true;
                        break;
                    }
                }
            }
        }

        if (!$GLOBALS['hyper_cache_stop'] && !empty($this->options['reject_old_posts']) && is_single()) {
            global $post;
            if (strtotime($post->post_date_gmt) < time() - 86400 * $this->options['reject_old_posts']) {
                return;
            }
        }

        // If is not require to bypass the comment authors, remove the cookies so the page is generated without
        // comment moderation noticies
        if (!isset($this->options['reject_comment_authors'])) {
            foreach ($_COOKIE as $n => $v) {
                if ('comment_author' == substr($n, 0, 14)) {
                    unset($_COOKIE[$n]);
                }
            }
        }

        $this->ob_started = true;
        ob_start('hyper_cache_callback');
    }

    public function post_folder($post_id)
    {
        $url = get_permalink($post_id);
        $parts = parse_url($url);

        return $parts['host'].hyper_cache_sanitize_uri($parts['path']);
    }

    public function remove_page($dir)
    {
        $dir = untrailingslashit($dir);
        @unlink($dir.'/index.html');
        @unlink($dir.'/index.html.gz');
        @unlink($dir.'/index-https.html');
        @unlink($dir.'/index-https.html.gz');
        @unlink($dir.'/index-mobile.html');
        @unlink($dir.'/index-mobile.html.gz');
        @unlink($dir.'/index-https-mobile.html');
        @unlink($dir.'/index-https-mobile.html.gz');

        $this->remove_dir($dir.'/feed/');
        // Pagination
        $this->remove_dir($dir.'/page/');
    }

    public function remove_dir($dir)
    {
        $dir = trailingslashit($dir);
        $files = glob($dir.'*', GLOB_MARK);
        if (!empty($files)) {
            foreach ($files as &$file) {
                if (DIRECTORY_SEPARATOR == substr($file, -1)) {
                    $this->remove_dir($file);
                } else {
                    @unlink($file);
                }
            }
        }
        @rmdir($dir);
    }

    public function hook_hyper_cache_clean()
    {
        if (!isset($this->options['autoclean'])) {
            return;
        }
        if (0 == $this->options['max_age']) {
            return;
        }
        $this->remove_older_than(time() - $this->options['max_age'] * 3600);
    }

    public function remove_older_than($time)
    {
        $this->_remove_older_than($time, $this->get_folder().'/');
    }

    public function _remove_older_than($time, $dir)
    {
        $files = glob($dir.'*', GLOB_MARK);
        if (!empty($files)) {
            foreach ($files as &$file) {
                if ('/' == substr($file, -1)) {
                    $this->_remove_older_than($time, $file);
                } else {
                    //$this->log($file . ' ' . ($time-filemtime($file)));
                    if (@filemtime($file) < $time) {
                        //$this->log('Removing ' . $file);
                        @unlink($file);
                    }
                }
            }
        }
    }

    public function get_folder()
    {
        if (\defined('HYPER_CACHE_FOLDER')) {
            return HYPER_CACHE_FOLDER;
        }

        return WP_CONTENT_DIR.'/cache/hyper-cache-tweaks';
    }

    public function text_to_list($text)
    {
        $list = [];
        $items = explode("\n", str_replace(["\n", "\r"], "\n", $text));
        foreach ($items as &$item) {
            $item = trim($item);
            if ('' == $item) {
                continue;
            }
            $list[] = $item;
        }

        return $list;
    }
}

function hyper_cache_cdn_callback($matches)
{
    //error_log($matches[1]);
    $parts = parse_url($matches[2]);
    //$return = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
    $return = HyperCache::$instance->options['cdn_url'].$parts['path'];

    if (!empty($parts['query'])) {
        $return .= '?'.$parts['query'];
    }

    return $matches[1].$return.$matches[3];
}

function hyper_cache_callback($buffer)
{
    global $hyper_cache_gzip_accepted;

    $buffer = trim($buffer);

    if (0 == \strlen($buffer)) {
        return '';
    }

    $options = HyperCache::$instance->options;

    // Replace the CDN Url
    if (isset($options['cdn_enabled'])) {
        $parts = parse_url(get_option('home'));
        $base = quotemeta($parts['scheme'].'://'.$parts['host']);
        $base = quotemeta('http://'.$parts['host']);

        $buffer = preg_replace_callback("#(<img.+src=[\"'])(".$base.".*)([\"'])#U", 'hyper_cache_cdn_callback', $buffer);
        $buffer = preg_replace_callback("#(<script.+src=[\"'])(".$base.".*)([\"'])#U", 'hyper_cache_cdn_callback', $buffer);
        $buffer = preg_replace_callback("#(<link.+href=[\"'])(".$base.".*\.css.*)([\"'])#U", 'hyper_cache_cdn_callback', $buffer);
    }

    $buffer = apply_filters('cache_buffer', $buffer);

    if (!empty($GLOBALS['hyper_cache_stop'])) {
        if (isset($options['gzip_on_the_fly']) && $hyper_cache_gzip_accepted && \function_exists('gzencode')) {
            header('Cache-Control: private, max-age=0, no-cache, no-transform', false);
            header('Vary: Accept-Encoding,User-Agent');
            header('Content-Encoding: gzip');
            header('X-Cache: gzip on the fly', false);

            return gzencode($buffer, 9);
        }

        return $buffer;
    }

    $uri = hyper_cache_sanitize_uri($_SERVER['REQUEST_URI']);
    $host = hyper_cache_sanitize_host($_SERVER['HTTP_HOST']);
    if (is_404()) {
        $lc_dir = HyperCache::$instance->get_folder().'/'.$host;
    } else {
        $lc_dir = HyperCache::$instance->get_folder().'/'.$host.$uri;
    }
    if (!empty($GLOBALS['hyper_cache_is_mobile'])) {
        // Bypass (should no need since there is that control on advanced-cache.php)
        if (2 == $options['mobile']) {
            if (isset($options['gzip_on_the_fly']) && $hyper_cache_gzip_accepted && \function_exists('gzencode')) {
                header('Cache-Control: private, max-age=0, no-cache, no-transform', false);
                header('Vary: Accept-Encoding,User-Agent');
                header('Content-Encoding: gzip');
                header('X-Cache: mobile, gzip on the fly', false);

                return gzencode($buffer, 9);
            }

            return $buffer;
        }
    }

    if (is_404()) {
        $lc_file = $lc_dir.'/404'.$GLOBALS['hyper_cache_group'].'.html';
    } else {
        $lc_file = $lc_dir.'/index'.$GLOBALS['hyper_cache_group'].'.html';

        if (!is_dir($lc_dir)) {
            wp_mkdir_p($lc_dir);
        }
    }

    if (!isset($options['reject_comment_authors']) && is_singular() && !is_feed() && !is_user_logged_in()) {
        if (\function_exists('is_amp_endpoint') && !is_amp_endpoint()) {
            $script = '<script>';
            $script .= 'function lc_get_cookie(name) {';
            $script .= 'var c = document.cookie;';
            $script .= 'if (c.indexOf(name) != -1) {';
            $script .= 'var x = c.indexOf(name)+name.length+1;';
            $script .= 'var y = c.indexOf(";",x);';
            $script .= 'if (y < 0) y = c.length;';
            $script .= 'return decodeURIComponent(c.substring(x,y));';
            $script .= '} else return "";}';
            $script .= 'if ((d = document.getElementById("commentform")) != null) { e = d.elements;';
            $script .= 'var z = lc_get_cookie("comment_author_email_'.COOKIEHASH.'");';
            $script .= 'if (z != "") e["email"].value = z;';
            $script .= 'z = lc_get_cookie("comment_author_'.COOKIEHASH.'");';
            $script .= 'if (z != "") e["author"].value = z.replace(/\+/g, " ");';
            $script .= 'z = lc_get_cookie("comment_author_url_'.COOKIEHASH.'");';
            $script .= 'if (z != "") e["url"].value = z;';
            $script .= '}';
            $script .= '</script>';
            $x = strrpos($buffer, '</body>');
            if ($x) {
                $buffer = substr($buffer, 0, $x).$script.'</body></html>';
            } else {
                $buffer .= $script;
            }
        }
    }

    @file_put_contents($lc_file, $buffer.'<!-- hyper cache '.date('Y-m-d H:i:s').' -->');

    // Saves the gzipped version
    if (isset($options['gzip'])) {
        $gzf = gzopen($lc_file.'.gz', 'wb9');
        if (false !== $gzf) {
            gzwrite($gzf, $buffer.'<!-- hyper cache gzip '.date('Y-m-d H:i:s').' -->');
            gzclose($gzf);
        }
    }

    return $buffer;
}

if (!\function_exists('hyper_cache_sanitize_uri')) {
    function hyper_cache_sanitize_uri($uri)
    {
        $uri = strtok($uri, '?');
        $uri = preg_replace('|[^a-zA-Z0-9/\-_]+|', '_', $uri);
        $uri = preg_replace('|/+|', '/', $uri);
        $uri = rtrim($uri, '/');
        if (empty($uri) || '/' != $uri[0]) {
            $uri = '/'.$uri;
        }

        return $uri;
    }
}

if (!\function_exists('hyper_cache_sanitize_host')) {
    function hyper_cache_sanitize_host($host)
    {
        $host = preg_replace('|[^a-zA-Z0-9\.]+|', '', $host);

        return strtolower($host);
    }
}

if (!\function_exists('hyper_cache_ignoreqs')) {
    function hyper_cache_ignoreqs()
    {
        if (!empty($_GET)) {
            $hyper_cache_iqs = array_diff_key(
                $_GET,
                [
                    'utm_source' => 1,
                    'utm_medium' => 1,
                    'utm_campaign' => 1,
                    'utm_expid' => 1,
                    'fb_action_ids' => 1,
                    'fb_action_types' => 1,
                    'fb_source' => 1,
                    'fbclid' => 1,
                    'campaignid' => 1,
                    'adgroupid' => 1,
                    'adid' => 1,
                    'gclid' => 1,
                    'age-verified' => 1,
                    'ao_noptimize' => 1,
                    'usqp' => 1,
                    'cn-reloaded' => 1,
                    '_ga' => 1,
                ]
            );

            if (!empty($hyper_cache_iqs)) {
                return true;
            }
        }

        return false;
    }
}
