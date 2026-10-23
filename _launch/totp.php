<?php
/* ==========================================================================
   LoggX – Zwei-Faktor-Anmeldung (TOTP, RFC 6238)
   Funktioniert mit jeder Authenticator-App (Google/Microsoft Authenticator,
   1Password, Bitwarden, Aegis …): 6-stelliger Code, alle 30 Sekunden neu.
   Notfall: Wiederherstellungscodes (je einmal nutzbar) – oder in
   _launch/data/config.php "totp_on": false setzen.
   ========================================================================== */
declare(strict_types=1);

const LX_TOTP_PERIOD = 30;
const LX_TOTP_DIGITS = 6;
const LX_TOTP_TRUST_DAYS = 30;

function lx_b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function lx_b32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $ch) $bits .= str_pad(decbin(strpos($alpha, $ch)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function lx_totp_new_secret(): string { return lx_b32_encode(random_bytes(20)); }

function lx_totp_code(string $secret, int $step): string {
    $key = lx_b32_decode($secret);
    $msg = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
    $h = hash_hmac('sha1', $msg, $key, true);
    $o = ord($h[19]) & 0x0f;
    $num = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($num % (10 ** LX_TOTP_DIGITS)), LX_TOTP_DIGITS, '0', STR_PAD_LEFT);
}
/** Prüft einen Code (±1 Zeitfenster gegen kleine Uhr-Abweichungen). Gibt das Zeitfenster zurück oder null. */
function lx_totp_check(string $secret, string $code, int $lastStep = 0): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== LX_TOTP_DIGITS || $secret === '') return null;
    $now = intdiv(time(), LX_TOTP_PERIOD);
    for ($s = $now - 1; $s <= $now + 1; $s++) {
        if ($s <= $lastStep) continue; // jeder Code nur einmal
        if (hash_equals(lx_totp_code($secret, $s), $code)) return $s;
    }
    return null;
}
function lx_totp_uri(string $secret, string $account, string $issuer = 'LoggX Admin'): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/* ---------- Wiederherstellungscodes ---------- */
function lx_recovery_new(int $n = 8): array {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $s = ''; for ($j = 0; $j < 10; $j++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
        $codes[] = substr($s, 0, 5) . '-' . substr($s, 5);
    }
    return $codes;
}
function lx_recovery_norm(string $c): string { return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $c)); }

/* ---------- "Diesem Gerät 30 Tage vertrauen" (signiertes Cookie) ---------- */
function lx_trust_key(array $c): string { return hash('sha256', lx_secret() . '|' . ($c['totp_secret'] ?? '') . '|' . ($c['admin_hash'] ?? '')); }
function lx_trust_set(array $c): void {
    $exp = time() + LX_TOTP_TRUST_DAYS * 86400;
    $val = $exp . '.' . hash_hmac('sha256', (string)$exp, lx_trust_key($c));
    setcookie('loggx_2fa', $val, ['expires' => $exp, 'path' => (lx_base() ?: '') . '/admin/', 'secure' => lx_https(), 'httponly' => true, 'samesite' => 'Strict']);
}
function lx_trust_ok(array $c): bool {
    $raw = (string)($_COOKIE['loggx_2fa'] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $raw, $m) || (int)$m[1] < time()) return false;
    return hash_equals(hash_hmac('sha256', $m[1], lx_trust_key($c)), $m[2]);
}
function lx_trust_clear(): void {
    setcookie('loggx_2fa', '', ['expires' => time() - 3600, 'path' => (lx_base() ?: '') . '/admin/', 'secure' => lx_https(), 'httponly' => true, 'samesite' => 'Strict']);
}
