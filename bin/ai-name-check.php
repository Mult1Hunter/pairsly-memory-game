<?php
/**
 * Runs Plugin Check's AI "Namer" analysis (the same similarity + pre-review
 * prompts the wp.org review team's AI uses) against a plugin name, from the
 * command line instead of the Tools > Plugin Check Namer screen.
 *
 * Needs plugin-check active and an AI connector key on the local site:
 *   wp option update connectors_ai_anthropic_api_key 'sk-ant-...'
 *
 * Usage (from the repo root, stack running):
 *   docker cp bin/ai-name-check.php "$(docker compose ps -q wpcli):/tmp/"
 *   docker compose exec -T wpcli wp eval-file /tmp/ai-name-check.php "Pairsly - Memory Game" "Matic Korošec (bordar11)"
 *
 * Dev-only; excluded from the release zip via .distignore (/bin).
 */

/**
 * @param string[] $args Positional arguments from wp eval-file: name, author.
 */
function pairsmg_ai_name_check(array $args) {
    if (!class_exists('WordPress\Plugin_Check\Traits\AI_Check_Names')) {
        WP_CLI::error('plugin-check is not active.');
    }
    $name   = isset($args[0]) ? $args[0] : '';
    $author = isset($args[1]) ? $args[1] : '';
    if ($name === '') {
        WP_CLI::error('Usage: wp eval-file ai-name-check.php "<plugin name>" ["<author>"]');
    }

    $runner = new class() {
        use WordPress\Plugin_Check\Traits\AI_Check_Names;
        use WordPress\Plugin_Check\Traits\AI_Utils;

        public function similar($name) {
            return $this->run_similar_name_query('', $name);
        }

        public function full($name, $author) {
            return $this->run_name_analysis('', $name, $author);
        }
    };

    WP_CLI::log('== similar-name stage ==');
    WP_CLI::log(pairsmg_ai_name_check_format($runner->similar($name)));
    WP_CLI::log('');
    WP_CLI::log('== pre-review verdict ==');
    WP_CLI::log(pairsmg_ai_name_check_format($runner->full($name, $author)));
}

/**
 * @param mixed $result WP_Error, array with a "text" key, or string.
 * @return string
 */
function pairsmg_ai_name_check_format($result) {
    if (is_wp_error($result)) {
        return 'ERROR: ' . $result->get_error_message();
    }
    if (is_array($result)) {
        return isset($result['text']) ? (string) $result['text'] : (string) wp_json_encode($result, JSON_PRETTY_PRINT);
    }
    return (string) $result;
}

pairsmg_ai_name_check(isset($args) && is_array($args) ? $args : array());
