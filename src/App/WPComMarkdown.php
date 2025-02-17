<?php

namespace EditormdApp;

class WPComMarkdown {

    const POST_OPTION = "editormd_wpcom_publish_posts_with_markdown";
    const COMMENT_OPTION = "wpcom_publish_comments_with_markdown";
    const POST_TYPE_SUPPORT = "wpcom-markdown";
    const IS_MD_META = "_wpcom_is_markdown";

    private static $parser;

    // 翻译文本域
    protected $text_domain;

    // to ensure that our munged posts over xml-rpc are removed from the cache
    public $posts_to_uncache = array();
    private $monitoring = array("post" => array(), "parent" => array());

    /**
     * Singleton silence is golden
     */
    public function __construct($text_domain) {
        $this->text_domain = $text_domain;
        add_action("init", array($this, "load"));
    }

    /**
     * Kicks things off on `init` action
     */
    public function load() {
        $this->add_default_post_type_support();
        $this->maybe_load_actions_and_filters();
        if (defined("REST_API_REQUEST") && REST_API_REQUEST) {
            add_action("switch_blog", array($this, "maybe_load_actions_and_filters"), 10, 2);
        }
        add_action("admin_init", array($this, "register_setting"));
        add_action("admin_init", array($this, "maybe_unload_for_bulk_edit"));
        if (current_theme_supports("o2") || class_exists("P2")) {
            $this->add_o2_helpers();
        }
    }

    /**
     * If we're in a bulk edit session, unload so that we don't lose our markdown metadata
     *
     * @return null
     */
    public function maybe_unload_for_bulk_edit() {
        if (isset($_REQUEST["bulk_edit"]) && $this->is_posting_enabled()) {
            $this->unload_markdown_for_posts();
        }
    }

    /**
     * Called on init and fires on switch_blog to decide if our actions and filters
     * should be running.
     *
     * @param int|null $new_blog_id New blog ID
     * @param int|null $old_blog_id Old blog ID
     *
     * @return null
     */
    public function maybe_load_actions_and_filters($new_blog_id = null, $old_blog_id = null) {
        // If this is a switch_to_blog call, and the blog isn't changing, we'll already be loaded
        if ($new_blog_id && $new_blog_id === $old_blog_id) {
            return;
        }

        if ($this->is_posting_enabled()) {
            $this->load_markdown_for_posts();
        } else {
            $this->unload_markdown_for_posts();
        }

        if ($this->is_commenting_enabled()) {
            $this->load_markdown_for_comments();
        } else {
            $this->unload_markdown_for_comments();
        }
    }

    /**
     * Set up hooks for enabling Markdown conversion on posts
     *
     * @return null
     */
    public function load_markdown_for_posts() {
        add_filter("wp_kses_allowed_html", array($this, "wp_kses_allowed_html"), 10, 2);
        add_action("wp_insert_post", array($this, "wp_insert_post"));
        add_filter("wp_insert_post_data", array($this, "wp_insert_post_data"), 10, 2);
        add_filter("edit_post_content", array($this, "edit_post_content"), 10, 2);
        add_filter("edit_post_content_filtered", array($this, "edit_post_content_filtered"), 10, 2);
        add_action("wp_restore_post_revision", array($this, "wp_restore_post_revision"), 10, 2);
        add_filter("_wp_post_revision_fields", array($this, "_wp_post_revision_fields"));
        add_action("xmlrpc_call", array($this, "xmlrpc_actions"));
        add_filter("content_save_pre", array($this, "preserve_code_blocks"), 1);
        if (defined("XMLRPC_REQUEST") && XMLRPC_REQUEST) {
            $this->check_for_early_methods();
        }
    }

    /**
     * Removes hooks to disable Markdown conversion on posts
     *
     * @return null
     */
    public function unload_markdown_for_posts() {
        remove_filter("wp_kses_allowed_html", array($this, "wp_kses_allowed_html"));
        remove_action("wp_insert_post", array($this, "wp_insert_post"));
        remove_filter("wp_insert_post_data", array($this, "wp_insert_post_data"), 10, 2);
        remove_filter("edit_post_content", array($this, "edit_post_content"), 10, 2);
        remove_filter("edit_post_content_filtered", array($this, "edit_post_content_filtered"), 10, 2);
        remove_action("wp_restore_post_revision", array($this, "wp_restore_post_revision"), 10, 2);
        remove_filter("_wp_post_revision_fields", array($this, "_wp_post_revision_fields"));
        remove_action("xmlrpc_call", array($this, "xmlrpc_actions"));
        remove_filter("content_save_pre", array($this, "preserve_code_blocks"), 1);
    }

    /**
     * Set up hooks for enabling Markdown conversion on comments
     *
     * @return null
     */
    protected function load_markdown_for_comments() {
        // Use priority 9 so that Markdown runs before KSES, which can clean up
        // any munged HTML.
        add_filter("pre_comment_content", array($this, "pre_comment_content"), 9);

        return;
    }

    /**
     * Removes hooks to disable Markdown conversion
     *
     * @return null
     */
    protected function unload_markdown_for_comments() {
        remove_filter("pre_comment_content", array($this, "pre_comment_content"), 9);
    }

    /**
     * o2 does some of what we do. Let's take precedence.
     *
     * @return null
     */
    public function add_o2_helpers() {
        if ($this->is_posting_enabled()) {
            add_filter("content_save_pre", array($this, "o2_escape_lists"), 1);
        }

        add_filter("o2_preview_post", array($this, "o2_preview_post"));
        add_filter("o2_preview_comment", array($this, "o2_preview_comment"));

        add_filter("wpcom_markdown_transform_pre", array($this, "o2_unescape_lists"));
        add_filter("wpcom_untransformed_content", array($this, "o2_unescape_lists"));
    }

    /**
     * If Markdown is enabled for posts on this blog, filter the text for o2 previews
     *
     * @param  string $text Post text
     *
     * @return string       Post text transformed through the magic of Markdown
     */
    public function o2_preview_post($text) {
        if ($this->is_posting_enabled()) {
            $text = $this->transform($text, array("unslash" => false));
        }

        return $text;
    }

    /**
     * If Markdown is enabled for comments on this blog, filter the text for o2 previews
     *
     * @param  string $text Comment text
     *
     * @return string       Comment text transformed through the magic of Markdown
     */
    public function o2_preview_comment($text) {
        if ($this->is_commenting_enabled()) {
            $text = $this->transform($text, array("unslash" => false));
        }

        return $text;
    }

    /**
     * Escapes lists so that o2 doesn't trounce them
     *
     * @param  string $text Post/comment text
     *
     * @return string       Text escaped with HTML entity for asterisk
     */
    public function o2_escape_lists($text) {
        return preg_replace("/^\\* /um", "&#42; ", $text);
    }

    /**
     * Unescapes the token we inserted on o2_escape_lists
     *
     * @param  string $text Post/comment text with HTML entities for asterisks
     *
     * @return string       Text with the HTML entity removed
     */
    public function o2_unescape_lists($text) {
        return preg_replace("/^[&]\#042; /um", "* ", $text);
    }

    /**
     * Preserve code blocks from being munged by KSES before they have a chance
     *
     * @param  string $text post content
     *
     * @return string       post content with code blocks escaped
     */
    public function preserve_code_blocks($text) {
        return $this->get_parser()->codeblock_preserve($text);
    }

    /**
     * Remove KSES if it's there. Store the result to manually invoke later if needed.
     */
    public function maybe_remove_kses() {
        // Filters return true if they existed before you removed them
        if ($this->is_posting_enabled()) {
            $this->kses = remove_filter("content_filtered_save_pre", "wp_filter_post_kses") && remove_filter("content_save_pre", "wp_filter_post_kses");
        }
    }

    /**
     * Add our Writing and Discussion settings.
     */
    public function register_setting() {
        add_settings_field(self::POST_OPTION, __("Markdown", $this->text_domain), array($this, "post_field"), "writing");
        register_setting("writing", self::POST_OPTION, array($this, "sanitize_setting"));
        add_settings_field(self::COMMENT_OPTION, __("Markdown", $this->text_domain), array($this, "comment_field"), "discussion");
        register_setting("discussion", self::COMMENT_OPTION, array($this, "sanitize_setting"));
    }

    /**
     * Sanitize setting. Don't really want to store "on" value, so we'll store "1" instead!
     *
     * @param  string $input Value received by settings API via $_POST
     *
     * @return bool          Cast to boolean.
     */
    public function sanitize_setting($input) {
        return (bool) $input;
    }

    /**
     * Prints HTML for the Writing setting
     */
    public function post_field() {
        printf(
            '<label><input name="%s" id="%s" type="checkbox"%s /> %s</label><p class="description">%s</p>',
            self::POST_OPTION,
            self::POST_OPTION,
            checked($this->is_posting_enabled(), true, false),
            esc_html__("Use Markdown For Posts And Pages", $this->text_domain),
            sprintf('<a href="%s">%s</a>', esc_url($this->get_support_url()), esc_html__("Learn more about Markdown.", $this->text_domain))
        );
    }

    /**
     * Prints HTML for the Discussion setting
     */
    public function comment_field() {
        printf(
            '<label><input name="%s" id="%s" type="checkbox"%s /> %s</label><p class="description">%s</p>',
            self::COMMENT_OPTION,
            self::COMMENT_OPTION,
            checked($this->is_commenting_enabled(), true, false),
            esc_html__("Use Markdown for comments.", $this->text_domain),
            sprintf('<a href="%s">%s</a>', esc_url($this->get_support_url()), esc_html__("Learn more about Markdown.", $this->text_domain))
        );
    }

    /**
     * Get the support url for Markdown
     *
     * @uses   apply_filters
     * @return string support url
     */
    protected function get_support_url() {
        /**
         * Filter the Markdown support URL.
         *
         * @module markdown
         *
         * @since  2.8.0
         *
         * @param string $url Markdown support URL.
         */
        return apply_filters("easy_markdown_support_url", "http://en.support.wordpress.com/markdown-quick-reference/");
    }

    /**
     * Is Markdown conversion for posts enabled?
     *
     * @return boolean
     */
    public function is_posting_enabled() {
        return (bool) get_option(self::POST_OPTION, "");
    }

    /**
     * Is Markdown conversion for comments enabled?
     *
     * @return boolean
     */
    public function is_commenting_enabled() {
        return (bool) get_option(self::COMMENT_OPTION, "");
    }

    /**
     * Check if a $post_id has Markdown enabled.
     *
     * @param  int $post_id A post ID.
     *
     * @return boolean
     */
    public function is_markdown($post_id) {
        return get_metadata("post", $post_id, self::IS_MD_META, true);
    }

    /**
     * Set Markdown as enabled on a post_id. We skip over
     * update_postmeta so we can sneakily set metadata on post
     * revisions, which we need.
     *
     * @param  int $post_id A post ID.
     *
     * @return bool The metadata was successfully set.
     */
    protected function set_as_markdown($post_id) {
        return update_metadata("post", $post_id, self::IS_MD_META, true);
    }

    /**
     * 获取Markdown解析对象，可选：选择需要所有类并且实例化Markdown解析器
     *
     *
     * @return object MarkdownParser 实例
     */
    public function get_parser() {

        if (! self::$parser) {
            self::$parser = new WPMarkdownParser();
        }

        return self::$parser;
    }

    /**
     * We don"t want Markdown conversion all over the place.
     *
     * @return null
     */
    public function add_default_post_type_support() {
        add_post_type_support("post", self::POST_TYPE_SUPPORT);
        add_post_type_support("page", self::POST_TYPE_SUPPORT);
        add_post_type_support("revision", self::POST_TYPE_SUPPORT);
    }

    /**
     * Figure out the post type of the post screen we"re on.
     *
     * @return string Current post_type
     */
    protected function get_post_screen_post_type() {
        global $pagenow;
        if ("post-new.php" === $pagenow) {
            return (isset($_GET["post_type"])) ? $_GET["post_type"] : "post";
        }
        if (isset($_GET["post"])) {
            $post = get_post((int) $_GET["post"]);
            if (is_object($post) && isset($post->post_type)) {
                return $post->post_type;
            }
        }

        return "post";
    }

    /**
     * Swap post_content and post_content_filtered for editing.
     *
     * @param  string $content Post content.
     * @param  int $id         Post ID.
     *
     * @return string          Swapped content.
     */
    public function edit_post_content($content, $id) {
        if ($this->is_markdown($id)) {
            $post = get_post($id);
            if ($post && ! empty($post->post_content_filtered)) {
                $post = $this->swap_for_editing($post);

                return $post->post_content;
            }
        }

        return $content;
    }

    /**
     * Swap post_content_filtered and post_content for editing
     *
     * @param  string $content Post content_filtered
     * @param  int $id         post ID
     *
     * @return string          Swapped content
     */
    public function edit_post_content_filtered($content, $id) {
        // if markdown was disabled, let"s turn this off
        if (! $this->is_posting_enabled() && $this->is_markdown($id)) {
            $post = get_post($id);
            if ($post && ! empty($post->post_content_filtered)) {
                $content = "";
            }
        }

        return $content;
    }

    /**
     * Some tags are allowed to have a 'markdown' attribute, allowing them to contain Markdown.
     * We need to tell KSES about those tags.
     *
     * @param  array $tags     List of tags that KSES allows.
     * @param  string $context The context that KSES is allowing these tags.
     *
     * @return array           The tags that KSES allows, with our extra 'markdown' parameter where necessary.
     */
    public function wp_kses_allowed_html($tags, $context) {
        if ("post" !== $context) {
            return $tags;
        }

        $re = "/" . $this->get_parser()->contain_span_tags_re . "/";
        foreach ($tags as $tag => $attributes) {
            if (preg_match($re, $tag)) {
                $attributes["markdown"] = true;
                $tags[$tag]           = $attributes;
            }
        }

        return $tags;
    }

    /**
     * Magic happens here. Markdown is converted and stored on post_content. Original Markdown is stored
     * in post_content_filtered so that we can continue editing as Markdown.
     *
     * @param  array $post_data The post data that will be inserted into the DB. Slashed.
     * @param  array $postarr   All the stuff that was in $_POST.
     *
     * @return array             $post_data with post_content and post_content_filtered modified
     */
    public function wp_insert_post_data($post_data, $postarr) {
        // $post_data array is slashed!
        $post_id = isset($postarr["ID"]) ? $postarr["ID"] : false;
        // bail early if markdown is disabled or this post type is unsupported.
        if (! $this->is_posting_enabled() || ! post_type_supports($post_data["post_type"], self::POST_TYPE_SUPPORT)) {
            // it"s disabled, but maybe this *was* a markdown post before.
            if ($this->is_markdown($post_id) && ! empty($post_data["post_content_filtered"])) {
                $post_data["post_content_filtered"] = "";
            }
            // we have no context to determine supported post types in the `post_content_pre` hook,
            // which already ran to sanitize code blocks. Undo that.
            $post_data["post_content"] = $this->get_parser()->codeblock_restore($post_data["post_content"]);

            return $post_data;
        }
        // rejigger post_content and post_content_filtered
        // revisions are already in the right place, except when we"re restoring, but that"s taken care of elsewhere
        // also prevent quick edit feature from overriding already-saved markdown (issue https://github.com/Automattic/jetpack/issues/636)
        if ("revision" !== $post_data["post_type"] && ! isset($_POST["_inline_edit"])) {
            /**
             * Filter the original post content passed to Markdown.
             *
             * @module markdown
             *
             * @since  2.8.0
             *
             * @param string $post_data ["post_content"] Untransformed post content.
             */
            $post_data["post_content_filtered"] = apply_filters("wpcom_untransformed_content", $post_data["post_content"]);
            $post_data["post_content"]          = $this->transform($post_data["post_content"], array("id" => $post_id));
            /** This filter is already documented in core/wp-includes/default-filters.php */
            $post_data["post_content"] = apply_filters("content_save_pre", $post_data["post_content"]);
        } elseif (0 === strpos($post_data["post_name"], $post_data["post_parent"] . "-autosave")) {
            // autosaves for previews are weird
            /** This filter is already documented in modules/markdown/easy-markdown.php */
            $post_data["post_content_filtered"] = apply_filters("wpcom_untransformed_content", $post_data["post_content"]);
            $post_data["post_content"]          = $this->transform($post_data["post_content"], array("id" => $post_data["post_parent"]));
            /** This filter is already documented in core/wp-includes/default-filters.php */
            $post_data["post_content"] = apply_filters("content_save_pre", $post_data["post_content"]);
        }

        // set as markdown on the wp_insert_post hook later
        if ($post_id) {
            $this->monitoring["post"][$post_id] = true;
        } else {
            $this->monitoring["content"] = wp_unslash($post_data["post_content"]);
        }
        if ("revision" === $postarr["post_type"] && $this->is_markdown($postarr["post_parent"])) {
            $this->monitoring["parent"][$postarr["post_parent"]] = true;
        }

        return $post_data;
    }

    /**
     * Calls on wp_insert_post action, after wp_insert_post_data. This way we can
     * still set postmeta on our revisions after it's all been deleted.
     *
     * @param  int $post_id The post ID that has just been added/updated
     *
     */
    public function wp_insert_post($post_id) {
        $post_parent = get_post_field("post_parent", $post_id);
        // this didn"t have an ID yet. Compare the content that was just saved.
        if (isset($this->monitoring["content"]) && $this->monitoring["content"] === get_post_field("post_content", $post_id)) {
            unset($this->monitoring["content"]);
            $this->set_as_markdown($post_id);
        }
        if (isset($this->monitoring["post"][$post_id])) {
            unset($this->monitoring["post"][$post_id]);
            $this->set_as_markdown($post_id);
        } elseif (isset($this->monitoring["parent"][$post_parent])) {
            unset($this->monitoring["parent"][$post_parent]);
            $this->set_as_markdown($post_id);
        }
    }

    /**
     * Run a comment through Markdown. Easy peasy.
     *
     * @param  string $content
     *
     * @return string
     */
    public function pre_comment_content($content) {
        return $this->transform($content, array(
            "id" => $this->comment_hash($content),
        ));
    }

    protected function comment_hash($content) {
        return "c-" . substr(md5($content), 0, 8);
    }

    /**
     * Markdown转换，重复转换任务
     *
     * @param  string $text    Content to be run through Markdown
     * @param  array $args     Arguments, with keys:
     *                         id: provide a string to prefix footnotes with a unique identifier
     *                         unslash: when true, expects and returns slashed data
     *                         decode_code_blocks: when true, assume that text in fenced code blocks is already
     *                         HTML encoded and should be decoded before being passed to Markdown, which does
     *                         its own encoding.
     *
     * @return string        Markdown-processed content
     */
    public function transform($text, $args = array()) {

        // If this contains Gutenberg content, let"s keep it intact.
        if (function_exists("has_blocks") && has_blocks($text)) {
            return $text;
        }

        $args = wp_parse_args($args, array(
            "id"                 => false,
            "unslash"            => true,
            "decode_code_blocks" => true
        ));
        // 删除函数值中的斜线（\）
        if ($args["unslash"]) {
            $text = wp_unslash($text);
        }

        /**
         * Filter the content to be run through Markdown, before it's transformed by Markdown.
         *
         * @module markdown
         *
         * @since  2.8.0
         *
         * @param string $text Content to be run through Markdown
         * @param array $args  Array of Markdown options.
         */
        $text = apply_filters("wpcom_markdown_transform_pre", $text, $args);
        // ensure our paragraphs are separated
        $text = str_replace(array("</p><p>", "</p>\n<p>"), "</p>\n\n<p>", $text);
        // visual editor likes to add <p>s. Buh-bye.
        $text = $this->get_parser()->unp($text);
        // sometimes we get an encoded > at start of line, breaking blockquotes
        $text = preg_replace("/^&gt;/m", ">", $text);
        // prefixes are because we need to namespace footnotes by post_id
        $this->get_parser()->fn_id_prefix = $args["id"] ? $args["id"] . "-" : "";
        // If we"re not using the code shortcode, prevent over-encoding.
        if ($args["decode_code_blocks"]) {
            $text = $this->get_parser()->codeblock_restore($text);
        }
        // Transform it!
        $text = $this->get_parser()->transform($text);
        // Fix footnotes - kses doesn"t like the : IDs it supplies
        $text = preg_replace( '/((id|href)="#?fn(ref)?):/', "$1-", $text );
        // Markdown inserts extra spaces to make itself work. Buh-bye.
        $text = rtrim($text);
        /**
         * Filter the content to be run through Markdown, after it was transformed by Markdown.
         *
         * @module markdown
         *
         * @since  2.8.0
         *
         * @param string $text Content to be run through Markdown
         * @param array $args  Array of Markdown options.
         */
        $text = apply_filters("wpcom_markdown_transform_post", $text, $args);

        // probably need to re-slash
        if ($args["unslash"]) {
            $text = wp_slash($text);
        }

        return $text;
    }

    /**
     * Shows Markdown in the Revisions screen, and ensures that post_content_filtered
     * is maintained on revisions
     *
     * @param  array $fields Post fields pertinent to revisions
     *
     * @return array          Modified array to include post_content_filtered
     */
    public function _wp_post_revision_fields($fields) {
        $fields["post_content_filtered"] = __("Markdown content", $this->text_domain);

        return $fields;
    }

    /**
     * Do some song and dance to keep all post_content and post_content_filtered content
     * in the expected place when a post revision is restored.
     *
     * @param  int $post_id     The post ID have a restore done to it
     * @param  int $revision_id The revision ID being restored
     *
     */
    public function wp_restore_post_revision($post_id, $revision_id) {
        if ($this->is_markdown($revision_id)) {
            $revision             = get_post($revision_id, ARRAY_A);
            $post                 = get_post($post_id, ARRAY_A);
            $post["post_content"] = $revision["post_content_filtered"]; // Yes, we put it in post_content, because our wp_insert_post_data() expects that
            // set this flag so we can restore the post_content_filtered on the last revision later
            $this->monitoring["restore"] = true;
            // let"s not make a revision of our fixing update
            add_filter("wp_revisions_to_keep", "__return_false", 99);
            wp_update_post($post);
            $this->fix_latest_revision_on_restore($post_id);
            remove_filter("wp_revisions_to_keep", "__return_false", 99);
        }
    }

    /**
     * We need to ensure the last revision has Markdown, not HTML in its post_content_filtered
     * column after a restore.
     *
     * @param  int $post_id The post ID that was just restored.
     *
     */
    protected function fix_latest_revision_on_restore($post_id) {
        global $wpdb;
        $post                                 = get_post($post_id);
        $last_revision                        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d ORDER BY ID DESC", $post->ID ) );
        $last_revision->post_content_filtered = $post->post_content_filtered;
        wp_insert_post((array) $last_revision);
    }

    /**
     * Kicks off magic for an XML-RPC session. We want to keep editing Markdown
     * and publishing HTML.
     *
     * @param  string $xmlrpc_method The current XML-RPC method
     *
     */
    public function xmlrpc_actions($xmlrpc_method) {
        switch ($xmlrpc_method) {
            case "metaWeblog.getRecentPosts":
            case "wp.getPosts":
            case "wp.getPages":
                add_action("parse_query", array($this, "make_filterable"), 10, 1);
                break;
            case "wp.getPost":
                $this->prime_post_cache();
                break;
        }
    }

    /**
     * metaWeblog.getPost and wp.getPage fire xmlrpc_call action *after* get_post() is called.
     * So, we have to detect those methods and prime the post cache early.
     */
    protected function check_for_early_methods() {
        $raw_post_data = file_get_contents("php://input");
        if (false === strpos($raw_post_data, "metaWeblog.getPost")
             && false === strpos($raw_post_data, "wp.getPage")) {
            return;
        }
        include_once(ABSPATH . WPINC . "/class-IXR.php");
        $message = new \IXR_Message($raw_post_data);
        $message->parse();
        $post_id_position = "metaWeblog.getPost" === $message->methodName ? 0 : 1;
        $this->prime_post_cache($message->params[$post_id_position]);
    }

    /**
     * Prime the post cache with swapped post_content. This is a sneaky way of getting around
     * the fact that there are no good hooks to call on the *.getPost xmlrpc methods.
     *
     */
    private function prime_post_cache($post_id = false) {
        global $wp_xmlrpc_server;
        if (! $post_id) {
            $post_id = $wp_xmlrpc_server->message->params[3];
        }

        // prime the post cache
        if ($this->is_markdown($post_id)) {
            $post = get_post($post_id);
            if (! empty($post->post_content_filtered)) {
                wp_cache_delete($post->ID, "posts");
                $post = $this->swap_for_editing($post);
                wp_cache_add($post->ID, $post, "posts");
                $this->posts_to_uncache[] = $post_id;
            }
        }
        // uncache munged posts if using a persistent object cache
        if (wp_using_ext_object_cache()) {
            add_action("shutdown", array($this, "uncache_munged_posts"));
        }
    }

    /**
     * Swaps `post_content_filtered` back to `post_content` for editing purposes.
     *
     * @param  object $post WP_Post object
     *
     * @return object       WP_Post object with swapped `post_content_filtered` and `post_content`
     */
    protected function swap_for_editing($post) {
        $markdown = $post->post_content_filtered;
        // unencode encoded code blocks
        $markdown = $this->get_parser()->codeblock_restore($markdown);
        // restore beginning of line blockquotes
        $markdown                    = preg_replace("/^&gt; /m", "> ", $markdown);
        $post->post_content_filtered = $post->post_content;
        $post->post_content          = $markdown;

        return $post;
    }


    /**
     * We munge the post cache to serve proper markdown content to XML-RPC clients.
     * Uncache these after the XML-RPC session ends.
     */
    public function uncache_munged_posts() {
        // $this context gets lost in testing sometimes. Weird.
        foreach ($this->posts_to_uncache as $post_id) {
            wp_cache_delete($post_id, "posts");
        }
    }

    /**
     * Since *.(get)?[Rr]ecentPosts calls get_posts with suppress filters on, we need to
     * turn them back on so that we can swap things for editing.
     *
     * @param  object $wp_query WP_Query object
     *
     */
    public function make_filterable($wp_query) {
        $wp_query->set("suppress_filters", false);
        add_action("the_posts", array($this, "the_posts"), 10, 2);
    }

    /**
     * Swaps post_content and post_content_filtered for editing.
     *
     * @param  array $posts     Posts returned by the just-completed query.
     * @param  object $wp_query Current WP_Query object.
     *
     * @return array            Modified $posts.
     */
    public function the_posts($posts, $wp_query) {
        foreach ($posts as $key => $post) {
            if ($this->is_markdown($post->ID) && ! empty($posts[$key]->post_content_filtered)) {
                $markdown                             = $posts[$key]->post_content_filtered;
                $posts[$key]->post_content_filtered = $posts[$key]->post_content;
                $posts[$key]->post_content          = $markdown;
            }
        }

        return $posts;
    }
}