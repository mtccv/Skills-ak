<?php
declare(strict_types=1);
// Isolated PHP contract check: WordPress, HTTP server, SQL and application bootstrap are not run.
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/');
final class WP { public string $request = ''; public array $query_vars = array(); }
function is_admin(): bool { return false; }
function home_url(string $path = ''): string { return 'https://example.test' . ($GLOBALS['test_home_path'] ?? '') . ($GLOBALS['test_home_language'] ?? '') . $path; }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['test_options'][$name] ?? $default; }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return 'aish_frontend_request' === $hook ? ($GLOBALS['test_mapped_request'] ?? $value) : $value; }
// Match WordPress: new values are not encoded by add_query_arg().
function add_query_arg(array $args, string $url): string { return $url . '?' . implode('&', array_map(static fn($key) => $key . '=' . $args[$key], array_keys($args))); }
require dirname(__DIR__, 2) . '/wp-content/themes/ai-skills-hub/inc/core/Frontend/Router.php';
use AISkillsHub\Core\Frontend\Router;
$count = 0;
function check(bool $condition, string $message): void { global $count; if (!$condition) { throw new RuntimeException($message); } ++$count; }
function parsePath(string $uri, string $expected, string $locale = 'en_US', array $query = array('p'=>42, 'post_type'=>'aish_message')): void {
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $wp = new WP();
    $wp->query_vars = $query;
    $_SERVER['REQUEST_URI'] = $uri;
    $router->parse($wp);
    $request = (new ReflectionProperty(Router::class, 'request'))->getValue($router);
    check(($request['route'] ?? null) === $expected, 'Unexpected route for ' . $uri);
    check(($request['locale'] ?? null) === $locale, 'Unexpected locale for ' . $uri);
    check(array_keys($wp->query_vars) === array('aish_route','aish_language'), 'Native query leaked into handled route ' . $uri);
}
parsePath('/en/', 'home');
parsePath('/zh/', 'home', 'zh_CN');
parsePath('/en/skills/', 'skills');
parsePath('/zh/skills/123/a-real-skill/', 'skill', 'zh_CN');
parsePath('/en/skills/123/', 'skill');
parsePath('/en/category/developer-tools/', 'category');
parsePath('/en/tag/platform-cli/', 'tag');
parsePath('/en/tutorials/', 'tutorials');
parsePath('/en/tutorials/42/', 'tutorial');
parsePath('/en/search/?q=test', 'search');
parsePath('/en/about/', 'about');
parsePath('/en/contact/', 'contact');
parsePath('/en/submit/', 'submit');
parsePath('/en/aish_message/private-message/', '404');
parsePath('/en/skills/0/example/', '404');
parsePath('/en/skills/9999999999999999999/example/', '404');
parsePath('/unrecognized-native-post/', '404', 'zh_CN');
parsePath('/?p=123', 'root', 'zh_CN');
parsePath('/index.php/en/skills/', 'skills');
$GLOBALS['test_home_path'] = '/site';
parsePath('/site/en/skills/?q=test', 'skills');
parsePath('/site/index.php/en/tutorials/42/', 'tutorial');
$GLOBALS['test_mapped_request'] = array('route'=>'skill','locale'=>'en_US');
parsePath('/site/adapter-url/', '404');
$GLOBALS['test_mapped_request'] = array('route'=>'category','locale'=>'en_US','slug'=>array());
parsePath('/site/adapter-url/', '404');
unset($GLOBALS['test_mapped_request']);
$url = Router::url('skill', array('id'=>27,'slug'=>'sample-skill'), 'zh_CN');
check($url === 'https://example.test/site/zh/skills/27/sample-skill/', 'Stable Skill URL');
$url = Router::url('skills', array('q'=>'中文 & test','sort'=>'newest','page'=>1), 'en_US');
check(str_starts_with($url, 'https://example.test/site/en/skills/?'), 'Collection URL base');
parse_str((string)parse_url($url,PHP_URL_QUERY), $query);
check($query === array('q'=>'中文 & test'), 'Encoded search and omitted default parameters');
foreach (array('two words', 'a&b=c', '#part', 'C++', '100%_', '中文 空格', 'a?b/c') as $input) {
    $url = Router::url('search', array('q'=>$input), 'en_US');
    parse_str((string)parse_url($url,PHP_URL_QUERY), $actual);
    check($actual === array('q'=>$input) && null === parse_url($url,PHP_URL_FRAGMENT) && ! preg_match('/[\x00-\x20]/', $url), 'Search query preserves literal delimiters and Unicode: ' . $input);
}
foreach (array('/site/wp-json/wp/v2/posts', '/site/feed/', '/site/robots.txt', '/site/wp-sitemap.xml', '/site/aish-sitemap.xml', '/site/aish-sitemap-skills-zh-1.xml', '/site/sitemap_index.xml', '/site/post-sitemap2.xml', '/site/sitemap.xml', '/site/sitemap.xsl', '/site/main-sitemap.xsl', '/site/wp-sitemap-index.xsl') as $uri) {
    $router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
    $wp = new WP(); $_SERVER['REQUEST_URI'] = $uri; $router->parse($wp);
    check(!(new ReflectionProperty(Router::class, 'handled'))->getValue($router), 'Reserved route intercepted: ' . $uri);
}
// WPML can filter home_url() to the current language. The stored home option is the site root.
$GLOBALS['test_options']['home'] = 'https://example.test/site';
$GLOBALS['test_home_language'] = '/en';
try {
    check(home_url('/') === 'https://example.test/site/en/', 'Simulated current-language home URL');
    check(Router::url('skill', array('id'=>27, 'slug'=>'actual-translation'), 'zh_CN') === 'https://example.test/site/zh/skills/27/actual-translation/', 'Chinese canonical URL must not inherit English home prefix');
    check(Router::url('home', array(), 'en_US') === 'https://example.test/site/en/', 'English home URL must not duplicate language prefix');
    check(Router::siteUrl('/aish-sitemap.xml') === 'https://example.test/site/aish-sitemap.xml', 'Sitemap URL stays outside translated home prefix');
    parsePath('/site/zh/skills/', 'skills', 'zh_CN');
    parsePath('/site/en/skills/', 'skills', 'en_US');
    parsePath('/site/', 'root', 'zh_CN');
} finally {
    unset($GLOBALS['test_options']['home'], $GLOBALS['test_home_language']);
}
echo 'PASS: ' . $count . " isolated routing and URL assertions (PHP stubs; no WordPress/SQL integration).\n";
