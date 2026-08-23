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
 */
if (!class_exists('WordPress\Plugin_Check\Traits\AI_Check_Names')) {
    WP_CLI::error('plugin-check is not active.');
}
$runner = new class {
    use WordPress\Plugin_Check\Traits\AI_Check_Names, WordPress\Plugin_Check\Traits\AI_Utils;
    public function similar($name) { return $this->run_similar_name_query('', $name); }
    public function full($name, $author) { return $this->run_name_analysis('', $name, $author); }
};
$name   = isset($args[0]) ? $args[0] : '';
$author = isset($args[1]) ? $args[1] : '';
if ($name === '') {
    WP_CLI::error('Usage: wp eval-file ai-name-check.php "<plugin name>" ["<author>"]');
}
$print = function ($r) {
    if (is_wp_error($r)) { return 'ERROR: ' . $r->get_error_message(); }
    return is_array($r) ? (isset($r['text']) ? $r['text'] : wp_json_encode($r, JSON_PRETTY_PRINT)) : (string) $r;
};
echo "== similar-name stage ==\n", $print($runner->similar($name)), "\n\n";
echo "== pre-review verdict ==\n", $print($runner->full($name, $author)), "\n";
