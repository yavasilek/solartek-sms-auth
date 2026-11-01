<?php
defined('ABSPATH') || exit;

function moto_phone_antibot_answer(string $session, array $state, string $answer): string {
    return moto_phone_hash('antibot:' . moto_phone_origin(home_url('/')) . ':' . $session . ':' . $state['binding'] . ':' . $state['phone'] . ':' . $state['id'] . ':' . strtoupper(trim($answer)));
}

function moto_phone_antibot_image(string $answer): string {
    $font = dirname(__DIR__, 2) . '/assets/fonts/DejaVuSans-Bold.ttf';
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext') || !is_readable($font)) throw new RuntimeException('Antibot renderer unavailable');
    // FreeType on Windows cannot open an absolute path with Cyrillic directory names.
    if (PHP_OS_FAMILY === 'Windows' && preg_match('/[^\x00-\x7F]/', $font)) {
        $bytes = (string) file_get_contents($font);
        $digest = hash('sha256', $bytes);
        $font = sys_get_temp_dir() . '/moto-phone-font-' . $digest . '.ttf';
        if (!is_file($font)) {
            $temporary = tempnam(sys_get_temp_dir(), 'moto-font-');
            if (!$temporary || file_put_contents($temporary, $bytes) !== strlen($bytes)) throw new RuntimeException('Antibot font unavailable');
            if (!@rename($temporary, $font) && !is_file($font)) throw new RuntimeException('Antibot font unavailable');
            if (is_file($temporary)) unlink($temporary);
        }
        if (!hash_equals($digest, (string) hash_file('sha256', $font))) throw new RuntimeException('Antibot font changed');
    }
    $image = imagecreatetruecolor(288, 96);
    $background = imagecolorallocate($image, 248, 249, 250);
    imagefill($image, 0, 0, $background);
    for ($i = 0; $i < 9; $i++) {
        $noise = imagecolorallocate($image, random_int(155, 195), random_int(160, 200), random_int(165, 205));
        imageline($image, random_int(0, 287), random_int(0, 95), random_int(0, 287), random_int(0, 95), $noise);
    }
    for ($i = 0; $i < 6; $i++) {
        $ink = imagecolorallocate($image, random_int(20, 65), random_int(25, 65), random_int(30, 75));
        if (imagettftext($image, random_int(30, 34), random_int(-15, 15), 12 + $i * 45, random_int(59, 70), $ink, $font, $answer[$i]) === false) throw new RuntimeException('Antibot text rendering failed');
    }
    $warped = imagecreatetruecolor(288, 96);
    imagefill($warped, 0, 0, $background);
    $phase = random_int(0, 60);
    for ($x = 0; $x < 288; $x++) imagecopy($warped, $image, $x, (int) round(3 * sin(($x + $phase) / 21)), $x, 0, 1, 96);
    ob_start();
    try {
        if (!imagepng($warped)) throw new RuntimeException('Antibot PNG rendering failed');
        return 'data:image/png;base64,' . base64_encode((string) ob_get_contents());
    }
    finally { ob_end_clean(); }
}

function moto_phone_antibot_public(array $state): array {
    return ['id' => $state['id'], 'image' => $state['image'], 'work' => $state['work'], 'bits' => $state['bits'],
        'expires_in' => max(0, $state['expires'] - time()), 'attempts_left' => max(0, (int)ssa_config('captcha_attempts') - $state['attempts'])];
}

function moto_phone_antibot_issue(string $session, string $phone, bool $refresh = false) {
    if (!$phone) return moto_phone_error('invalid_phone', 'Укажите мобильный номер России, например +7 999 123-45-67.');
    $key = 'antibot:' . $session;
    $lock = moto_phone_lock($key);
    if (!$lock) return moto_phone_error('busy', 'Проверка ещё подготавливается. Повторите через несколько секунд.', 409);
    try {
        $previous = moto_phone_state($key);
        if (!$refresh && ($previous['status'] ?? '') === 'pending' && $previous['phone'] === $phone && $previous['binding'] === moto_phone_binding()) return moto_phone_antibot_public($previous);
        if ($previous && ($previous['issued_at'] ?? 0) > microtime(true) - 3) return moto_phone_error('captcha_rate', 'Новую картинку можно получить через несколько секунд.', 429);
        $rateLock = moto_phone_lock('antibot-rate');
        if (!$rateLock) return moto_phone_error('busy', 'Повторите через несколько секунд.', 409);
        try {
            $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
            $updates = [];
            foreach ([['ip:' . $ip, 60, (int)ssa_config('captcha_ip_minute')], ['ip:' . $ip, 3600, (int)ssa_config('captcha_ip_hour')], ['browser:' . $session, 60, (int)ssa_config('captcha_browser_minute')], ['browser:' . $session, 3600, (int)ssa_config('captcha_browser_hour')]] as [$identity, $window, $maximum]) {
                $bucket = (int) floor(time() / $window);
                $rateKey = 'antibot-rate:' . $identity . ':' . $window . ':' . $bucket;
                $rate = moto_phone_state($rateKey);
                if (($rate['count'] ?? 0) >= $maximum) return moto_phone_error('captcha_rate', 'Слишком много проверок. Попробуйте позже или войдите по паролю.', 429);
                $updates[$rateKey] = ['count' => ($rate['count'] ?? 0) + 1, 'expires' => ($bucket + 1) * $window];
            }
            foreach ($updates as $rateKey => $value) moto_phone_store($rateKey, $value);
        } finally { moto_phone_unlock('antibot-rate', $rateLock); }
        $alphabet = '234679ACDEFGHJKLMNPRTUVWXY';
        $answer = '';
        for ($i = 0; $i < 6; $i++) $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $state = ['id' => bin2hex(random_bytes(16)), 'phone' => $phone, 'binding' => moto_phone_binding(),
            'work' => bin2hex(random_bytes(16)), 'bits' => (int)ssa_config('captcha_bits'), 'attempts' => 0, 'status' => 'pending',
            'issued_at' => microtime(true), 'expires' => time() + (int)ssa_config('captcha_ttl')];
        $state['answer_hash'] = moto_phone_antibot_answer($session, $state, $answer);
        $state['image'] = moto_phone_antibot_image($answer);
        moto_phone_store($key, $state);
        return moto_phone_antibot_public($state);
    } finally { moto_phone_unlock($key, $lock); }
}

function moto_phone_antibot_work(string $seed, string $nonce, int $bits): bool {
    if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $nonce) || (float) $nonce > 4294967295 || $bits < 16 || $bits > 22) return false;
    $head = unpack('Nvalue', hash('sha256', $seed . ':' . $nonce, true));
    return ($head['value'] >> (32 - $bits)) === 0;
}

function moto_phone_antibot_check(string $session, string $phone, array $proof) {
    $key = 'antibot:' . $session;
    $lock = moto_phone_lock($key);
    if (!$lock) return moto_phone_error('busy', 'Проверка ещё выполняется.', 409);
    try {
        $state = moto_phone_state($key);
        if (!$state || ($state['status'] ?? '') !== 'pending' || $state['binding'] !== moto_phone_binding()
            || $state['phone'] !== $phone || !is_string($proof['id'] ?? null) || !hash_equals($state['id'], $proof['id'])) {
            return moto_phone_error('captcha_required', 'Пройдите проверку по картинке перед отправкой SMS.', 403);
        }
        if (microtime(true) - $state['issued_at'] < 0.8 || !is_string($proof['work'] ?? null)
            || !moto_phone_antibot_work($state['work'], $proof['work'], $state['bits'])) return moto_phone_error('captcha_work', 'Дождитесь подготовки проверки и повторите запрос.', 403);
        $state['attempts']++;
        $answer = $proof['answer'] ?? null;
        $correct = is_string($answer) && preg_match('/^[A-Za-z0-9]{6}$/D', trim($answer))
            && hash_equals($state['answer_hash'], moto_phone_antibot_answer($session, $state, $answer));
        if ($correct || $state['attempts'] >= (int)ssa_config('captcha_attempts')) {
            $state['status'] = $correct ? 'used' : 'blocked';
            unset($state['answer_hash'], $state['image']);
        }
        moto_phone_store($key, $state); // Consume before any billable operation, under the same lock as refresh.
        return $correct ? true : moto_phone_error($state['attempts'] >= (int)ssa_config('captcha_attempts') ? 'captcha_required' : 'captcha_wrong', 'Символы не совпали. Проверьте картинку или обновите её.', 403);
    } finally { moto_phone_unlock($key, $lock); }
}
