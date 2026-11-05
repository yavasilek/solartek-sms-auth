<?php
class WC_Session_Handler {
    public function get_session($customer_id, $default_value = false) {
        return ['_moto_merge_epoch' => moto_merge_cart_epoch((int) $customer_id)];
    }
    public function save_data($old_session_key = '') {}
}
require __DIR__ . '/merge-bootstrap.php';

if (($argv[1] ?? '') === '--hold') {
    $id = (int) $argv[2];
    $token = moto_phone_lock('identity-user:' . $id);
    if (!$token) exit(2);
    echo "ready\n";
    flush();
    usleep(300000);
    if (($argv[3] ?? '') === '--save') update_user_meta($id, '_moto_cart_epoch', 'saved-before-unlock');
    moto_phone_unlock('identity-user:' . $id, $token);
    exit(0);
}

class CartConflictResponse extends RuntimeException {
    public function __construct(public array $response) { parent::__construct('HTTP conflict'); }
}
function wp_die($message, $title = '', $args = []) {
    throw new CartConflictResponse(['message' => $message, 'title' => $title] + $args);
}

$checks = [];
function lock_check(string $name, bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks[] = $name;
}

[$source, $target] = merge_fixture_pair();
$session = new class {
    public string $epoch = '';
    public function get($key, $default = '') { return $key === '_moto_merge_epoch' ? $this->epoch : $default; }
};
$GLOBALS['fixtureWC'] = (object) ['session' => $session];
$class = moto_merge_session_handler('WC_Session_Handler');
$native = new $class();

$worker = proc_open([PHP_BINARY, __FILE__, '--hold', (string) $source, '--save'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
], $pipes);
lock_check('Parallel lock worker starts', is_resource($worker));
fclose($pipes[0]);
lock_check('Another request owns the identity lock', trim(fgets($pipes[1])) === 'ready');
$error = null;
$started = hrtime(true);
try { $snapshot = $native->get_session((string) $source); } catch (Throwable $caught) { $error = $caught; }
$elapsed = (hrtime(true) - $started) / 1e9;
stream_get_contents($pipes[1]);
$workerError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
lock_check('Parallel lock worker exits cleanly', proc_close($worker) === 0 && $workerError === '');
lock_check('Ordinary parallel request resumes when the lock clears', $error === null && $elapsed >= 0.05 && $elapsed < 3);
lock_check('Resumed request reads the preceding committed snapshot', ($snapshot['_moto_merge_epoch'] ?? '') === 'saved-before-unlock');
$session->epoch = $snapshot['_moto_merge_epoch'];
moto_merge_cart_hook();
lock_check('Resumed request owns the lock through its saves', moto_phone_lock('identity-user:' . $source) === '');
moto_merge_order_unlock();

$external = moto_phone_lock('identity-user:' . $source);
$started = hrtime(true);
$response = null;
try { $native->get_session((string) $source); } catch (CartConflictResponse $error) { $response = $error->response; }
$elapsed = (hrtime(true) - $started) / 1e9;
lock_check('Long conflict becomes HTTP 409 instead of an uncaught exception', ($response['response'] ?? null) === 409);
lock_check('Conflict waiting is bounded', $elapsed >= 1.8 && $elapsed < 3);
lock_check('Another request keeps ownership after a conflict', moto_phone_raw('lock:identity-user:' . $source) === $external);
moto_phone_unlock('identity-user:' . $source, $external);

$worker = proc_open([PHP_BINARY, __FILE__, '--hold', (string) $source], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
], $pipes);
fclose($pipes[0]);
lock_check('Merge-race worker owns the lock', trim(fgets($pipes[1])) === 'ready');
update_user_meta($source, '_moto_cart_epoch', 'merged-while-waiting');
$response = null;
try { moto_merge_cart_hook(); } catch (CartConflictResponse $error) { $response = $error->response; }
stream_get_contents($pipes[1]);
$workerError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
lock_check('Merge-race worker exits cleanly', proc_close($worker) === 0 && $workerError === '');
lock_check('Resumed stale request still cannot overwrite a merged cart', ($response['response'] ?? null) === 409);
moto_merge_order_unlock();

lock_check('No real SMS or customer mutations', count($fixtureCalls) === 0);
echo json_encode(['passed' => count($checks), 'checks' => $checks, 'realSmsSent' => 0, 'realCustomerMutations' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
