<?php
/**
 * CryptoBox — Cifrado simétrico para credenciales sensibles.
 * Usa AES-256-GCM (autenticado). El resultado se prefija con "enc:v1:".
 *
 * - encrypt(): devuelve el texto cifrado. Si el valor es vacío/null, lo deja igual.
 * - decrypt(): descifra. Si el valor NO está cifrado (dato antiguo en texto plano),
 *   lo devuelve tal cual (retrocompatible).
 *
 * La clave se guarda en dashboard/config/crypto_key.php (se genera sola la 1ª vez).
 * Ese archivo devuelve la clave por PHP, así que aunque se abra por navegador no
 * expone nada.
 */
class CryptoBox
{
    const PREFIX = 'enc:v1:';
    private static $key = null;

    /** Obtiene (o genera) la clave binaria de 32 bytes. */
    private static function getKey()
    {
        if (self::$key !== null) return self::$key;

        $file = __DIR__ . '/../config/crypto_key.php';
        if (is_file($file)) {
            $b64 = include $file;
            $raw = base64_decode(is_string($b64) ? $b64 : '', true);
            if ($raw !== false && strlen($raw) === 32) {
                return self::$key = $raw;
            }
        }

        // Generar clave nueva y persistirla
        $raw = random_bytes(32);
        $b64 = base64_encode($raw);
        $content = "<?php\n// Clave de cifrado de credenciales. NO compartir ni versionar.\nreturn '" . $b64 . "';\n";
        @file_put_contents($file, $content, LOCK_EX);
        return self::$key = $raw;
    }

    /** ¿El valor ya está cifrado por esta clase? */
    public static function isEncrypted($value)
    {
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** Cifra un texto. Vacío/null se devuelve sin cambios. */
    public static function encrypt($plain)
    {
        if ($plain === null || $plain === '') return $plain;
        if (self::isEncrypted($plain)) return $plain; // ya cifrado

        $key = self::getKey();
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return $plain; // no romper el guardado
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Descifra un valor. Si no está cifrado, lo devuelve tal cual (retrocompatible). */
    public static function decrypt($value)
    {
        if (!self::isEncrypted($value)) return $value;

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) return '';

        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::getKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
