<?php
define('ABSPATH', __DIR__ . '/');
define('SSA_VERSION', 'fixture');
$filters = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($name, $value, ...$args) { return $GLOBALS['filters'][$name] ?? $value; }
function get_option($name, $fallback = false) { return $fallback; }
function get_privacy_policy_url() { return 'https://example.test/privacy-policy/'; }
function home_url($path) { return 'https://example.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../plugin/solartek-sms-auth/includes/settings.php';
require __DIR__ . '/../plugin/solartek-sms-auth/compat/motonaklejki-privacy-consent.php';
$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
class ConsentErrors { public array $errors = []; function add($code, $text) { $this->errors[$code] = $text; } }
$_POST = [];
$errors = new ConsentErrors();
moto_privacy_checkout_validate([], $errors);
check(isset($errors->errors['moto_checkout_personal_consent']), 'default checkout remains compatible');
ob_start(); moto_privacy_checkout_field(); $html = ob_get_clean();
check(str_contains($html, 'moto_checkout_personal_consent'), 'default checkbox retained');
$filters['ssa_checkout_consent_required'] = false;
$errors = new ConsentErrors(); moto_privacy_checkout_validate([], $errors);
check(!$errors->errors, 'contract-based checkout does not require an unrelated consent');
ob_start(); moto_privacy_checkout_field(); $html = ob_get_clean();
check(!str_contains($html, 'type="checkbox"') && str_contains($html, '/privacy-policy/'), 'contract notice keeps policy access');
$filters['ssa_consent_document_url'] = 'https://example.test/consent/account/';
$html = ssa_consent_statement('account');
check(str_contains($html, '/consent/account/') && str_contains($html, '/privacy-policy/'), 'consent and policy have separate links');
$filters['ssa_consent_document_url'] = 'javascript:alert(1)';
check(!str_contains(ssa_consent_statement(), 'javascript:'), 'unsafe site URL ignored');
echo json_encode(['suite' => 'consent-documents', 'checks' => $checks, 'ok' => true]) . PHP_EOL;
