<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings screen: one option array, five tabs. Each tab posts only its own
 * fields; sanitize() merges them over the stored values so a checkbox that
 * is absent from the POST (unchecked) is only treated as "off" when its own
 * tab was the one submitted.
 */
class PairsMG_Admin_Settings {

    const MENU_SLUG = 'pairsly-memory-game';
    const GROUP = 'pairsmg_settings_group';

    public static function tabs() {
        return array(
            'general'    => __('General', 'pairsly-memory-game'),
            'game'       => __('Game', 'pairsly-memory-game'),
            'protection' => __('Bot protection', 'pairsly-memory-game'),
            'appearance' => __('Appearance', 'pairsly-memory-game'),
            'advanced'   => __('Advanced', 'pairsly-memory-game'),
        );
    }

    /** Which setting keys belong to which tab (drives sanitize()). */
    private static function tab_keys() {
        return array(
            'general'    => array('brand_name', 'intro_eyebrow', 'intro_title', 'intro_copy', 'leaderboard_title', 'name_max_length', 'anonymous_name', 'create_page', 'game_slug', 'exit_url'),
            'game'       => array('pairs_easy', 'pairs_medium', 'pairs_hard', 'default_tier', 'special_per_game', 'use_default_deck', 'leaderboard_enabled', 'leaderboard_limit', 'sound_default', 'confetti', 'immersive_mobile'),
            'protection' => array('captcha_provider', 'captcha_site_key', 'captcha_secret_key', 'captcha_test_mode', 'recaptcha_v3_threshold'),
            'appearance' => array('theme', 'color_bg', 'color_panel', 'color_ink', 'color_accent', 'color_success', 'color_card_back', 'color_card_frame', 'font_mode', 'card_ratio', 'corner_radius', 'card_back_image_id', 'card_image_fit'),
            'advanced'   => array('rate_limit_submit', 'rate_limit_verify', 'rate_limit_start', 'trust_proxy_headers', 'delete_on_uninstall'),
        );
    }

    /** admin-post: recreate the dedicated game page on explicit request. */
    public static function handle_recreate_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'pairsly-memory-game'));
        }
        check_admin_referer('pairsmg_recreate_page');
        PairsMG_Game_Page::ensure();
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public static function register_menu() {
        add_menu_page(
            __('Memory Game', 'pairsly-memory-game'),
            __('Memory Game', 'pairsly-memory-game'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render'),
            'dashicons-games',
            58
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'pairsly-memory-game'),
            __('Settings', 'pairsly-memory-game'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render')
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Leaderboards', 'pairsly-memory-game'),
            __('Leaderboards', 'pairsly-memory-game'),
            'manage_options',
            PairsMG_Admin_Leaderboard::MENU_SLUG,
            array('PairsMG_Admin_Leaderboard', 'render')
        );
    }

    public static function register_settings() {
        register_setting(self::GROUP, PairsMG_Settings::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default'           => PairsMG_Settings::defaults(),
        ));
    }

    public static function enqueue($hook) {
        if (strpos((string) $hook, self::MENU_SLUG) === false) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_script(
            'pairsmg-admin',
            PAIRSMG_URL . 'assets/admin/settings.js',
            array('jquery', 'wp-color-picker'),
            PAIRSMG_VERSION,
            true
        );
        wp_localize_script('pairsmg-admin', 'PairsMGAdmin', array(
            'chooseImage' => __('Choose card back image', 'pairsly-memory-game'),
            'useImage'    => __('Use this image', 'pairsly-memory-game'),
            'presets'     => PairsMG_Settings::theme_presets(),
        ));
        wp_enqueue_style('pairsmg-admin', PAIRSMG_URL . 'assets/admin/settings.css', array(), PAIRSMG_VERSION);
    }

    /* ---------------- sanitize ---------------- */

    public static function sanitize($input) {
        $current = get_option(PairsMG_Settings::OPTION, array());
        $current = is_array($current) ? $current : array();
        $out = wp_parse_args($current, PairsMG_Settings::defaults());
        $input = is_array($input) ? $input : array();

        $tab = isset($input['_tab']) ? sanitize_key($input['_tab']) : '';
        $groups = self::tab_keys();
        if (!isset($groups[$tab])) {
            // Unknown or missing tab marker: sanitize whatever keys arrived.
            $keys = array_keys($input);
        } else {
            $keys = $groups[$tab];
        }

        $bools = array('create_page', 'use_default_deck', 'leaderboard_enabled', 'sound_default', 'confetti', 'immersive_mobile', 'captcha_test_mode', 'trust_proxy_headers', 'delete_on_uninstall');
        $texts = array('brand_name', 'intro_eyebrow', 'intro_title', 'leaderboard_title', 'anonymous_name', 'captcha_site_key');
        $colors = array('color_bg', 'color_panel', 'color_ink', 'color_accent', 'color_success', 'color_card_back', 'color_card_frame');

        foreach ($keys as $key) {
            if ($key === '_tab') {
                continue;
            }
            $has = array_key_exists($key, $input);
            $val = $has ? $input[$key] : null;

            if (in_array($key, $bools, true)) {
                $out[$key] = !empty($val);
            } elseif (in_array($key, $texts, true)) {
                $out[$key] = $has ? sanitize_text_field($val) : '';
            } elseif (in_array($key, $colors, true)) {
                $hex = $has ? sanitize_hex_color($val) : '';
                if ($hex) {
                    $out[$key] = $hex;
                }
            } elseif ($key === 'intro_copy') {
                $out[$key] = $has ? sanitize_textarea_field($val) : '';
            } elseif ($key === 'captcha_secret_key') {
                // Blank means "keep the stored secret" - the field renders
                // empty on purpose so re-saving the page does not wipe it.
                if ($has && $val !== '') {
                    $out[$key] = sanitize_text_field($val);
                }
            } elseif ($key === 'name_max_length') {
                $out[$key] = max(3, min(40, (int) $val));
            } elseif ($key === 'game_slug') {
                $slug = sanitize_title((string) $val);
                $out[$key] = $slug !== '' ? $slug : PairsMG_Game_Page::DEFAULT_SLUG;
            } elseif ($key === 'exit_url') {
                $out[$key] = trim((string) $val) === '' ? '' : esc_url_raw($val);
            } elseif (in_array($key, array('pairs_easy', 'pairs_medium', 'pairs_hard'), true)) {
                $out[$key] = max(PairsMG_Settings::MIN_PAIRS, min(PairsMG_Settings::MAX_PAIRS, (int) $val));
            } elseif ($key === 'default_tier') {
                $out[$key] = in_array($val, PairsMG_Settings::TIERS, true) ? $val : 'medium';
            } elseif ($key === 'special_per_game') {
                $out[$key] = max(0, min(PairsMG_Settings::MAX_PAIRS, (int) $val));
            } elseif ($key === 'leaderboard_limit') {
                $out[$key] = max(1, min(200, (int) $val));
            } elseif ($key === 'captcha_provider') {
                $out[$key] = in_array($val, PairsMG_Settings::PROVIDERS, true) ? $val : 'none';
            } elseif ($key === 'recaptcha_v3_threshold') {
                $out[$key] = max(0, min(1, round((float) $val, 2)));
            } elseif ($key === 'theme') {
                $out[$key] = in_array($val, PairsMG_Settings::THEMES, true) ? $val : 'light';
            } elseif ($key === 'font_mode') {
                $out[$key] = in_array($val, PairsMG_Settings::FONT_MODES, true) ? $val : 'bundled';
            } elseif ($key === 'card_ratio') {
                $out[$key] = in_array($val, PairsMG_Settings::RATIOS, true) ? $val : '7:10';
            } elseif ($key === 'corner_radius') {
                $out[$key] = max(0, min(32, (int) $val));
            } elseif ($key === 'card_back_image_id') {
                $out[$key] = max(0, (int) $val);
            } elseif ($key === 'card_image_fit') {
                $out[$key] = in_array($val, PairsMG_Settings::FITS, true) ? $val : 'inset';
            } elseif (in_array($key, array('rate_limit_submit', 'rate_limit_verify', 'rate_limit_start'), true)) {
                $out[$key] = max(0, min(10000, (int) $val));
            }
        }

        // Pairs must be non-decreasing across tiers, or "hard" makes no sense.
        if ($tab === 'game') {
            $out['pairs_medium'] = max($out['pairs_medium'], $out['pairs_easy']);
            $out['pairs_hard'] = max($out['pairs_hard'], $out['pairs_medium']);
        }

        // Keep the special-card quota inside the smallest board.
        $out['special_per_game'] = min((int) $out['special_per_game'], (int) $out['pairs_easy']);

        PairsMG_Post_Type::bust_cache();
        return $out;
    }

    /* ---------------- render ---------------- */

    private static function field_name($key) {
        return PairsMG_Settings::OPTION . '[' . $key . ']';
    }

    private static function text_row($key, $label, $s, $desc = '', $type = 'text', $placeholder = '') {
        ?>
        <tr>
            <th scope="row"><label for="pmg_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="<?php echo esc_attr($type); ?>" id="pmg_<?php echo esc_attr($key); ?>" class="regular-text"
                       name="<?php echo esc_attr(self::field_name($key)); ?>"
                       value="<?php echo esc_attr($s[$key]); ?>"
                       <?php if ($placeholder !== '') : ?>placeholder="<?php echo esc_attr($placeholder); ?>"<?php endif; ?> />
                <?php if ($desc !== '') : ?><p class="description"><?php echo esc_html($desc); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function number_row($key, $label, $s, $min, $max, $desc = '') {
        ?>
        <tr>
            <th scope="row"><label for="pmg_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="number" id="pmg_<?php echo esc_attr($key); ?>" style="width:90px"
                       min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="1"
                       name="<?php echo esc_attr(self::field_name($key)); ?>"
                       value="<?php echo esc_attr($s[$key]); ?>" />
                <?php if ($desc !== '') : ?><p class="description"><?php echo esc_html($desc); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function checkbox_row($key, $label, $s, $text, $desc = '') {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="checkbox" value="1" name="<?php echo esc_attr(self::field_name($key)); ?>" <?php checked(!empty($s[$key])); ?> />
                    <?php echo esc_html($text); ?>
                </label>
                <?php if ($desc !== '') : ?><p class="description"><?php echo esc_html($desc); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function select_row($key, $label, $s, $options, $desc = '', $class = '') {
        ?>
        <tr>
            <th scope="row"><label for="pmg_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <select id="pmg_<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::field_name($key)); ?>" class="<?php echo esc_attr($class); ?>">
                    <?php foreach ($options as $value => $text) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($s[$key], $value); ?>><?php echo esc_html($text); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($desc !== '') : ?><p class="description"><?php echo esc_html($desc); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function color_row($key, $label, $s) {
        ?>
        <tr class="pmg-color-row">
            <th scope="row"><label for="pmg_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="text" id="pmg_<?php echo esc_attr($key); ?>" class="pmg-color" data-key="<?php echo esc_attr($key); ?>"
                       name="<?php echo esc_attr(self::field_name($key)); ?>" value="<?php echo esc_attr($s[$key]); ?>" />
            </td>
        </tr>
        <?php
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pairsly-memory-game'));
        }
        $s = PairsMG_Settings::get();
        $tabs = self::tabs();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
        if (!isset($tabs[$tab])) {
            $tab = 'general';
        }
        $pool = PairsMG_Deck::stats();
        $counts = PairsMG_Settings::pair_counts();
        ?>
        <div class="wrap pmg-settings">
            <h1><?php esc_html_e('Pairsly - Memory Game', 'pairsly-memory-game'); ?></h1>

            <?php settings_errors(); ?>

            <div class="notice notice-info inline pmg-notice">
                <p>
                    <strong><?php esc_html_e('Where the game is', 'pairsly-memory-game'); ?>:</strong>
                    <?php if (PairsMG_Game_Page::enabled()) : ?>
                        <a href="<?php echo esc_url(PairsMG_Game_Page::url()); ?>" target="_blank" rel="noopener"><code><?php echo esc_html(PairsMG_Game_Page::url()); ?></code></a>
                        <?php if (PairsMG_Game_Page::page_missing()) : ?>
                            <span style="color:#b32d2e"><?php esc_html_e('(page missing or unpublished)', 'pairsly-memory-game'); ?></span>
                            <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'pairsmg_recreate_page', admin_url('admin-post.php')), 'pairsmg_recreate_page')); ?>"><?php esc_html_e('Recreate page', 'pairsly-memory-game'); ?></a>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php esc_html_e('no dedicated page (turn it on under General)', 'pairsly-memory-game'); ?>
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <?php esc_html_e('Shortcode:', 'pairsly-memory-game'); ?> <code>[pairs_memory_game]</code>
                    &nbsp;|&nbsp;
                    <?php esc_html_e('Block: "Pairsly - Memory Game"', 'pairsly-memory-game'); ?>
                </p>
                <p>
                    <?php
                    printf(
                        /* translators: 1: number of published cards, 2: of which special, 3: pairs needed for the hardest board */
                        esc_html__('Published cards: %1$d (%2$d special). The hardest board needs %3$d.', 'pairsly-memory-game'),
                        (int) $pool['custom'],
                        (int) $pool['special'],
                        (int) $counts['hard']
                    );
                    ?>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . PairsMG_Post_Type::POST_TYPE)); ?>"><?php esc_html_e('Manage cards', 'pairsly-memory-game'); ?></a>.
                    <?php if ($pool['custom'] < $counts['hard']) : ?>
                        <?php if (PairsMG_Deck::default_enabled()) : ?>
                            <?php
                            printf(
                                /* translators: %d: number of built-in cards */
                                esc_html__('Until then the built-in deck (%d cards) tops up the board.', 'pairsly-memory-game'),
                                (int) $pool['defaults']
                            );
                            ?>
                        <?php else : ?>
                            <strong style="color:#b32d2e"><?php esc_html_e('The built-in deck is off, so some difficulties cannot start.', 'pairsly-memory-game'); ?></strong>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(array('page' => self::MENU_SLUG, 'tab' => $key), admin_url('admin.php'))); ?>"
                       class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <input type="hidden" name="<?php echo esc_attr(self::field_name('_tab')); ?>" value="<?php echo esc_attr($tab); ?>" />
                <table class="form-table" role="presentation">
                    <?php
                    switch ($tab) {
                        case 'general':
                            self::tab_general($s);
                            break;
                        case 'game':
                            self::tab_game($s);
                            break;
                        case 'protection':
                            self::tab_protection($s);
                            break;
                        case 'appearance':
                            self::tab_appearance($s);
                            break;
                        case 'advanced':
                            self::tab_advanced($s);
                            break;
                    }
                    ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function tab_general($s) {
        self::text_row('brand_name', __('Brand line', 'pairsly-memory-game'), $s, __('Small header text above the game. Defaults to the site title.', 'pairsly-memory-game'));
        self::text_row('intro_eyebrow', __('Intro eyebrow', 'pairsly-memory-game'), $s, __('Short label above the intro title. Leave empty for the default.', 'pairsly-memory-game'), 'text', PairsMG_Settings::text('intro_eyebrow'));
        self::text_row('intro_title', __('Intro title', 'pairsly-memory-game'), $s, '', 'text', PairsMG_Settings::text('intro_title'));
        ?>
        <tr>
            <th scope="row"><label for="pmg_intro_copy"><?php esc_html_e('Intro text', 'pairsly-memory-game'); ?></label></th>
            <td>
                <textarea id="pmg_intro_copy" class="large-text" rows="3" name="<?php echo esc_attr(self::field_name('intro_copy')); ?>"
                          placeholder="<?php echo esc_attr(PairsMG_Settings::text('intro_copy')); ?>"><?php echo esc_textarea($s['intro_copy']); ?></textarea>
            </td>
        </tr>
        <?php
        self::text_row('leaderboard_title', __('Leaderboard title', 'pairsly-memory-game'), $s, '', 'text', PairsMG_Settings::text('leaderboard_title'));
        self::number_row('name_max_length', __('Max name length', 'pairsly-memory-game'), $s, 3, 40, __('Characters allowed in the player name on the leaderboard.', 'pairsly-memory-game'));
        self::text_row('anonymous_name', __('Name when left blank', 'pairsly-memory-game'), $s, '', 'text', PairsMG_Settings::text('anonymous_name'));
        self::checkbox_row('create_page', __('Dedicated page', 'pairsly-memory-game'), $s, __('Create and maintain a page for the game at the address below', 'pairsly-memory-game'), __('The plugin keeps a page at this slug and renames it when the slug changes. It never renames a page it did not create. Turn this off if you only use the shortcode or block.', 'pairsly-memory-game'));
        ?>
        <tr>
            <th scope="row"><label for="pmg_game_slug"><?php esc_html_e('Page slug', 'pairsly-memory-game'); ?></label></th>
            <td>
                <code><?php echo esc_html(trailingslashit(home_url())); ?></code>
                <input type="text" id="pmg_game_slug" style="width:200px" name="<?php echo esc_attr(self::field_name('game_slug')); ?>" value="<?php echo esc_attr($s['game_slug']); ?>" />
                <code>/</code>
            </td>
        </tr>
        <?php
        self::text_row('exit_url', __('Exit URL', 'pairsly-memory-game'), $s, __('Where the "back to site" button (shown on phones in full-screen mode) leads. Empty means the home page.', 'pairsly-memory-game'), 'url', home_url('/'));
    }

    private static function tab_game($s) {
        self::number_row('pairs_easy', __('Pairs - Easy', 'pairsly-memory-game'), $s, PairsMG_Settings::MIN_PAIRS, PairsMG_Settings::MAX_PAIRS);
        self::number_row('pairs_medium', __('Pairs - Medium', 'pairsly-memory-game'), $s, PairsMG_Settings::MIN_PAIRS, PairsMG_Settings::MAX_PAIRS);
        self::number_row('pairs_hard', __('Pairs - Hard', 'pairsly-memory-game'), $s, PairsMG_Settings::MIN_PAIRS, PairsMG_Settings::MAX_PAIRS, __('Each difficulty has its own leaderboard. Changing a pair count keeps old scores in the same tier, so change it before the game goes live if you can.', 'pairsly-memory-game'));
        self::select_row('default_tier', __('Preselected difficulty', 'pairsly-memory-game'), $s, PairsMG_Settings::tier_labels());
        self::number_row('special_per_game', __('Special cards per board', 'pairsly-memory-game'), $s, 0, PairsMG_Settings::MAX_PAIRS, __('How many cards flagged "special" are guaranteed on every board (if that many exist). 0 = no guarantee, they appear at random like the rest.', 'pairsly-memory-game'));
        self::checkbox_row('use_default_deck', __('Built-in deck', 'pairsly-memory-game'), $s, __('Top up the board with the built-in animal cards when there are not enough of my own', 'pairsly-memory-game'), __('Once you have published enough cards for the hardest board, the built-in ones stop appearing by themselves.', 'pairsly-memory-game'));
        self::checkbox_row('leaderboard_enabled', __('Leaderboard', 'pairsly-memory-game'), $s, __('Let players save their score with a name', 'pairsly-memory-game'), __('Off: the game still shows the score, but nothing is stored.', 'pairsly-memory-game'));
        self::number_row('leaderboard_limit', __('Leaderboard entries shown', 'pairsly-memory-game'), $s, 1, 200);
        self::checkbox_row('sound_default', __('Sound', 'pairsly-memory-game'), $s, __('Sound effects on by default (players can toggle)', 'pairsly-memory-game'));
        self::checkbox_row('confetti', __('Confetti', 'pairsly-memory-game'), $s, __('Confetti on a good result', 'pairsly-memory-game'));
        self::checkbox_row('immersive_mobile', __('Full screen on phones', 'pairsly-memory-game'), $s, __('Pin the game over the whole viewport on small screens', 'pairsly-memory-game'), __('Recommended for a game that is played at events from a QR code. Off: the game sits inside the page like any other block.', 'pairsly-memory-game'));
    }

    private static function tab_protection($s) {
        self::select_row('captcha_provider', __('Provider', 'pairsly-memory-game'), $s, PairsMG_Captcha::labels(), __('Players solve one challenge per visit before the first game. Score submissions are always tied to a server-issued single-use token, so bot protection guards against automated play, not against score tampering (which is prevented regardless).', 'pairsly-memory-game'), 'pmg-provider');
        self::text_row('captcha_site_key', __('Site key', 'pairsly-memory-game'), $s, __('The public key rendered in the browser.', 'pairsly-memory-game'));
        ?>
        <tr>
            <th scope="row"><label for="pmg_captcha_secret_key"><?php esc_html_e('Secret key', 'pairsly-memory-game'); ?></label></th>
            <td>
                <input type="password" id="pmg_captcha_secret_key" class="regular-text" autocomplete="new-password"
                       name="<?php echo esc_attr(self::field_name('captcha_secret_key')); ?>" value="" />
                <p class="description">
                    <?php
                    if (!empty($s['captcha_secret_key'])) {
                        esc_html_e('A secret key is stored. Leave blank to keep it.', 'pairsly-memory-game');
                    } else {
                        esc_html_e('Never leaves the server.', 'pairsly-memory-game');
                    }
                    ?>
                </p>
            </td>
        </tr>
        <?php
        self::checkbox_row('captcha_test_mode', __('Test mode', 'pairsly-memory-game'), $s, __('Use the provider\'s official always-pass test keys', 'pairsly-memory-game'), __('For local and staging sites, where real keys will not validate. The server-side verification still runs. reCAPTCHA v3 has no test keys - in test mode it is skipped. Turn this off before going live.', 'pairsly-memory-game'));
        ?>
        <tr class="pmg-v3-only">
            <th scope="row"><label for="pmg_recaptcha_v3_threshold"><?php esc_html_e('reCAPTCHA v3 score threshold', 'pairsly-memory-game'); ?></label></th>
            <td>
                <input type="number" id="pmg_recaptcha_v3_threshold" style="width:90px" min="0" max="1" step="0.05"
                       name="<?php echo esc_attr(self::field_name('recaptcha_v3_threshold')); ?>" value="<?php echo esc_attr($s['recaptcha_v3_threshold']); ?>" />
                <p class="description"><?php esc_html_e('Scores below this are rejected. Google suggests 0.5.', 'pairsly-memory-game'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Privacy', 'pairsly-memory-game'); ?></th>
            <td>
                <p class="description">
                    <?php esc_html_e('With a provider selected, the game page loads that provider\'s script and the verification token is sent to the provider\'s servers along with the visitor\'s IP address. Mention this in your privacy policy. With "None", no third-party requests are made.', 'pairsly-memory-game'); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    private static function tab_appearance($s) {
        self::select_row('theme', __('Theme', 'pairsly-memory-game'), $s, array(
            'light'     => __('Light', 'pairsly-memory-game'),
            'dark'      => __('Dark', 'pairsly-memory-game'),
            'parchment' => __('Parchment (warm)', 'pairsly-memory-game'),
            'custom'    => __('Custom colours', 'pairsly-memory-game'),
        ), __('Presets fill the colour fields; pick "Custom" to keep your own values.', 'pairsly-memory-game'), 'pmg-theme');
        self::color_row('color_bg', __('Background', 'pairsly-memory-game'), $s);
        self::color_row('color_panel', __('Panels', 'pairsly-memory-game'), $s);
        self::color_row('color_ink', __('Text', 'pairsly-memory-game'), $s);
        self::color_row('color_accent', __('Accent / buttons', 'pairsly-memory-game'), $s);
        self::color_row('color_success', __('Success (matched cards)', 'pairsly-memory-game'), $s);
        self::color_row('color_card_back', __('Card back', 'pairsly-memory-game'), $s);
        self::color_row('color_card_frame', __('Card frame', 'pairsly-memory-game'), $s);
        self::select_row('font_mode', __('Fonts', 'pairsly-memory-game'), $s, array(
            'bundled' => __('Bundled (Rajdhani + Open Sans, served from this site)', 'pairsly-memory-game'),
            'inherit' => __('Inherit from the theme', 'pairsly-memory-game'),
        ));
        self::select_row('card_ratio', __('Card shape', 'pairsly-memory-game'), $s, array(
            '7:10' => __('Portrait 7:10 (playing card)', 'pairsly-memory-game'),
            '3:4'  => __('Portrait 3:4', 'pairsly-memory-game'),
            '1:1'  => __('Square', 'pairsly-memory-game'),
        ));
        self::number_row('corner_radius', __('Corner radius (px)', 'pairsly-memory-game'), $s, 0, 32, __('0 for square corners everywhere.', 'pairsly-memory-game'));
        self::select_row('card_image_fit', __('Card image fit', 'pairsly-memory-game'), $s, array(
            'inset' => __('Inset - image centred with a margin (logos)', 'pairsly-memory-game'),
            'full'  => __('Full face - image fills the card (finished artwork)', 'pairsly-memory-game'),
        ), __('Default for your cards; each card can override it.', 'pairsly-memory-game'));
        $back_id = (int) $s['card_back_image_id'];
        $back_url = $back_id ? wp_get_attachment_image_url($back_id, 'medium') : '';
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Card back image', 'pairsly-memory-game'); ?></th>
            <td>
                <input type="hidden" id="pmg_card_back_image_id" name="<?php echo esc_attr(self::field_name('card_back_image_id')); ?>" value="<?php echo esc_attr($back_id); ?>" />
                <div class="pmg-back-preview" id="pmg_back_preview">
                    <?php if ($back_url) : ?><img src="<?php echo esc_url($back_url); ?>" alt="" /><?php endif; ?>
                </div>
                <button type="button" class="button" id="pmg_pick_back"><?php esc_html_e('Choose image', 'pairsly-memory-game'); ?></button>
                <button type="button" class="button" id="pmg_clear_back" <?php echo $back_id ? '' : 'style="display:none"'; ?>><?php esc_html_e('Remove', 'pairsly-memory-game'); ?></button>
                <p class="description"><?php esc_html_e('Optional. Drawn centred on the card back on top of the card back colour. A logo or emblem as a PNG with transparency (or SVG, if your site allows SVG uploads).', 'pairsly-memory-game'); ?></p>
            </td>
        </tr>
        <?php
    }

    private static function tab_advanced($s) {
        self::number_row('rate_limit_verify', __('Verifications per IP per hour', 'pairsly-memory-game'), $s, 0, 10000, __('Each verification may cost an outbound request to the bot-protection provider. 0 disables the limit.', 'pairsly-memory-game'));
        self::number_row('rate_limit_start', __('Game starts per IP per hour', 'pairsly-memory-game'), $s, 0, 10000);
        self::number_row('rate_limit_submit', __('Scores saved per IP per hour', 'pairsly-memory-game'), $s, 0, 10000);
        self::checkbox_row('trust_proxy_headers', __('Reverse proxy / CDN', 'pairsly-memory-game'), $s, __('This site is behind Cloudflare or another proxy - read the visitor IP from CF-Connecting-IP / X-Forwarded-For', 'pairsly-memory-game'), __('Only turn this on if the site really is behind a proxy, otherwise a visitor could forge the header and dodge the rate limits. IPs are only ever stored as a salted hash.', 'pairsly-memory-game'));
        self::checkbox_row('delete_on_uninstall', __('Uninstall', 'pairsly-memory-game'), $s, __('Delete all plugin data (settings, scores, cards) when the plugin is deleted', 'pairsly-memory-game'));
    }
}
