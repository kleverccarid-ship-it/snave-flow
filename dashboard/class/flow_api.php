<?php
/**
 * Generación con la Google Flow API de Núcleo SNAVE (sin extensión).
 *
 * El admin pega en el panel (dashboard/flow-cola, "Conexión con Google") su
 * TOKEN de Núcleo SNAVE y el EMAIL de la cuenta de Google Flow que tiene
 * conectada allí. Desde ahí el SERVIDOR genera los pedidos de la cola de todos
 * (admin, clientes y accesos de /flow): videos con Veo 3.1 / Omni Flash e
 * imágenes con Nano Banana. El archivo terminado se guarda en uploads/flow
 * igual que antes, así que la galería, los proyectos y los Spaces no cambian.
 *
 * El token y el email viven en tbl_flow_ajustes ('api_token', 'api_email') y el
 * token NO sale NUNCA en la página ni en ninguna respuesta: los clientes ni lo
 * ven ni saben que existe.
 *
 * Hostinger corta las peticiones largas: avanzar() trabaja "a trozos". Cada
 * llamada manda pedidos nuevos, pregunta por los videos que se están generando
 * y guarda los terminados, y vuelve en pocos segundos. La llama la propia
 * página (?vivo=1, cada pocos segundos) DESPUÉS de responder.
 *
 * Video (asíncrono):  POST /videos -> jobid -> GET /jobs/{jobid} -> descarga.
 * Imagen (síncrono):  POST /images -> media[].image.generatedImage (fifeUrl o
 *                     encodedImage) en la misma llamada.
 * Imágenes de entrada (Spaces): POST /assets/{email} -> mediaGenerationId, que
 *                     se pasa como startImage/endImage (video) o reference_N (imagen).
 *
 * API y parámetros: https://useapi.net/docs/api-google-flow-v1
 */
require_once __DIR__ . '/flowcola.php';

class FlowApi
{
    const BASE = 'https://api.useapi.net/v1/google-flow/';

    // Modelos que se ofrecen (id de la API => etiqueta). Si Núcleo SNAVE saca otro, el
    // admin puede escribir su id a mano en el panel.
    const MODELOS = [
        'video' => [
            'veo-3.1-lite-low-priority' => 'Veo 3.1 Lite · baja prioridad',
            'veo-3.1-fast'              => 'Veo 3.1 Fast',
            'veo-3.1-quality'           => 'Veo 3.1 Quality',
            'veo-3.1-lite'              => 'Veo 3.1 Lite',
            'omni-flash'                => 'Omni Flash · audio',
        ],
        'imagen' => [
            'nano-banana-2-lite' => '🍌 Nano Banana 2 Lite',
            'nano-banana-2'      => '🍌 Nano Banana 2',
            'nano-banana-2.1'    => '🍌 Nano Banana 2.1',
            'nano-banana-pro'    => '🍌 Nano Banana Pro',
        ],
    ];
    const DEFECTO = ['video' => 'veo-3.1-lite-low-priority', 'imagen' => 'nano-banana-2-lite'];

    // ¿Es un modelo gratis (sin créditos)? Lite baja prioridad y todas las Nano Banana.
    public static function modeloGratis($modelo)
    {
        $m = (string) $modelo;
        return $m === 'veo-3.1-lite-low-priority' || strpos($m, 'nano-banana') === 0;
    }

    // Imágenes que se pueden mandar (inicio, fin, ingredientes, referencias)
    const MAX_IMAGEN_BYTES = 7 * 1024 * 1024;

    // Caché de assets subidos en esta misma petición: { "email\nruta" => mediaGenerationId }.
    // La clave lleva el email porque un asset solo vale en la cuenta donde se subió.
    private static $assets = [];

    private static function base()
    {
        // Solo para las pruebas (un Núcleo SNAVE de mentira en local)
        $b = getenv('FLOW_API_BASE') ?: ($_SERVER['FLOW_API_BASE'] ?? '');
        return is_string($b) && $b !== '' ? rtrim($b, '/') . '/' : self::BASE;
    }

    /* ---------- Tablas ---------- */

    public static function asegurar(PDO $conn)
    {
        static $listo = false;
        if ($listo) return;
        FlowCola::asegurarTablas($conn);
        // operacion: el jobid de Núcleo SNAVE que se está generando (videos)
        // entradas: imágenes de los Spaces (inicio, fin, ingredientes, referencias)
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'operacion'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN operacion VARCHAR(255) NOT NULL DEFAULT '' AFTER archivos");
        }
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'entradas'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN entradas TEXT NULL AFTER operacion");
        }
        // cuenta: en qué cuenta de Google Flow se generó (para auditar el reparto)
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'cuenta'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN cuenta VARCHAR(190) NOT NULL DEFAULT '' AFTER entradas");
        }
        $listo = true;
    }

    /* ---------- Ajustes (solo admin) ---------- */

    private static function ajuste(PDO $conn, $clave)
    {
        $st = $conn->prepare("SELECT valor FROM tbl_flow_ajustes WHERE clave = ?");
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    private static function guardarAjuste(PDO $conn, $clave, $valor)
    {
        $conn->prepare("INSERT INTO tbl_flow_ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
             ->execute([$clave, mb_substr((string) $valor, 0, 255, 'UTF-8')]);
    }

    private static function token(PDO $conn)
    {
        return trim(self::ajuste($conn, 'api_token'));
    }

    // Token secreto para el cron (flow-cron.php): empuja la cola cada minuto aunque
    // nadie tenga la página abierta. Se crea solo la primera vez.
    public static function cronToken(PDO $conn)
    {
        $t = trim(self::ajuste($conn, 'cron_token'));
        if ($t === '') { $t = bin2hex(random_bytes(16)); self::guardarAjuste($conn, 'cron_token', $t); }
        return $t;
    }

    public static function email(PDO $conn)
    {
        return trim(self::ajuste($conn, 'api_email'));
    }

    // ¿Rotación round-robin entre todas las cuentas conectadas en Núcleo SNAVE?
    public static function rotar(PDO $conn)
    {
        return self::ajuste($conn, 'api_rotar') === '1';
    }

    // Quita la espera entre pasadas para que el próximo ?vivo=1 lance YA los pedidos
    // nuevos (se llama justo después de crear, para que arranquen sin demora).
    public static function despertar(PDO $conn)
    {
        self::guardarAjuste($conn, 'api_ultimo', '0');
    }

    /* ---------- Almacenamiento en Cloudflare R2 (bucket público) ---------- */

    // Config de R2 si está COMPLETA, o null. Vive en tbl_flow_ajustes.
    public static function r2Config(PDO $conn)
    {
        $c = [
            'account'   => trim(self::ajuste($conn, 'r2_account')),
            'bucket'    => trim(self::ajuste($conn, 'r2_bucket')),
            'access'    => trim(self::ajuste($conn, 'r2_access')),
            'secret'    => trim(self::ajuste($conn, 'r2_secret')),
            'publicurl' => rtrim(trim(self::ajuste($conn, 'r2_publicurl')), '/'),
        ];
        foreach ($c as $v) if ($v === '') return null;
        return $c;
    }

    public static function r2Activo(PDO $conn)
    {
        return self::r2Config($conn) !== null;
    }

    // Para el panel (el secreto nunca sale: solo si está puesto)
    public static function r2Estado(PDO $conn)
    {
        return [
            'account'      => self::ajuste($conn, 'r2_account'),
            'bucket'       => self::ajuste($conn, 'r2_bucket'),
            'access'       => self::ajuste($conn, 'r2_access'),
            'tiene_secret' => self::ajuste($conn, 'r2_secret') !== '',
            'publicurl'    => self::ajuste($conn, 'r2_publicurl'),
            'activo'       => self::r2Activo($conn),
        ];
    }

    // Guarda la config de R2. $secret null = no tocarlo; '' lo borra.
    public static function r2Guardar(PDO $conn, $account, $bucket, $access, $secret, $publicurl)
    {
        self::guardarAjuste($conn, 'r2_account', trim((string) $account));
        self::guardarAjuste($conn, 'r2_bucket', trim((string) $bucket));
        self::guardarAjuste($conn, 'r2_access', trim((string) $access));
        if ($secret !== null) self::guardarAjuste($conn, 'r2_secret', trim((string) $secret));
        self::guardarAjuste($conn, 'r2_publicurl', rtrim(trim((string) $publicurl), '/'));
    }

    private static function mimeDeExt($ext)
    {
        return ['mp4' => 'video/mp4', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
    }

    // Sube bytes a R2 con firma AWS SigV4 (S3 compatible). Devuelve la URL pública o null.
    private static function subirR2(PDO $conn, $bytes, $mime, $key)
    {
        $cfg = self::r2Config($conn);
        if (!$cfg) return null;
        $host    = $cfg['account'] . '.r2.cloudflarestorage.com';
        $region  = 'auto';
        $service = 's3';
        $method  = 'PUT';
        // La ruta lleva el bucket; cada segmento se codifica pero se conservan las '/'
        $canonicalUri = '/' . $cfg['bucket'] . '/' . str_replace('%2F', '/', rawurlencode($key));
        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $bytes);
        $canonicalHeaders = "content-type:$mime\nhost:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "$method\n$canonicalUri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $algorithm = 'AWS4-HMAC-SHA256';
        $scope = "$dateStamp/$region/$service/aws4_request";
        $stringToSign = "$algorithm\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret'], true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = "$algorithm Credential={$cfg['access']}/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

        $ch = curl_init("https://$host$canonicalUri");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => [
                "Host: $host",
                "x-amz-date: $amzDate",
                "x-amz-content-sha256: $payloadHash",
                "Content-Type: $mime",
                "Authorization: $authorization",
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return $cfg['publicurl'] . '/' . str_replace('%2F', '/', rawurlencode($key));
        }
        error_log('R2 upload HTTP ' . $code . ': ' . substr((string) $res, 0, 200));
        return null;
    }

    // Borra un objeto de R2 (DELETE firmado SigV4). true si se borró o ya no estaba.
    private static function borrarR2(PDO $conn, $key)
    {
        $cfg = self::r2Config($conn);
        if (!$cfg) return false;
        $host    = $cfg['account'] . '.r2.cloudflarestorage.com';
        $region  = 'auto';
        $service = 's3';
        $method  = 'DELETE';
        $canonicalUri = '/' . $cfg['bucket'] . '/' . str_replace('%2F', '/', rawurlencode($key));
        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', '');
        $canonicalHeaders = "host:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "$method\n$canonicalUri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $algorithm = 'AWS4-HMAC-SHA256';
        $scope = "$dateStamp/$region/$service/aws4_request";
        $stringToSign = "$algorithm\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret'], true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = "$algorithm Credential={$cfg['access']}/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
        $ch = curl_init("https://$host$canonicalUri");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ["Host: $host", "x-amz-date: $amzDate", "x-amz-content-sha256: $payloadHash", "Authorization: $authorization"],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) || $code === 404;
    }

    // Uso del bucket R2: lista los objetos (ListObjectsV2) y suma su tamaño.
    // Devuelve ['ok'=>bool, 'bytes'=>int, 'objetos'=>int].
    public static function r2Uso(PDO $conn)
    {
        $cfg = self::r2Config($conn);
        if (!$cfg) return ['ok' => false];
        $host = $cfg['account'] . '.r2.cloudflarestorage.com';
        $region = 'auto'; $service = 's3';
        $bytes = 0; $objetos = 0; $cont = ''; $vueltas = 0;
        do {
            $params = ['list-type' => '2', 'max-keys' => '1000', 'prefix' => 'flow/'];
            if ($cont !== '') $params['continuation-token'] = $cont;
            ksort($params);
            $qs = [];
            foreach ($params as $k => $v) $qs[] = rawurlencode($k) . '=' . rawurlencode($v);
            $canonicalQuery = implode('&', $qs);
            $uri = '/' . $cfg['bucket'];
            $amz = gmdate('Ymd\THis\Z'); $ds = gmdate('Ymd');
            $ph = hash('sha256', '');
            $cHeaders = "host:$host\nx-amz-content-sha256:$ph\nx-amz-date:$amz\n";
            $sHeaders = 'host;x-amz-content-sha256;x-amz-date';
            $cr = "GET\n$uri\n$canonicalQuery\n$cHeaders\n$sHeaders\n$ph";
            $scope = "$ds/$region/$service/aws4_request";
            $sts = "AWS4-HMAC-SHA256\n$amz\n$scope\n" . hash('sha256', $cr);
            $k = hash_hmac('sha256', $ds, 'AWS4' . $cfg['secret'], true);
            $k = hash_hmac('sha256', $region, $k, true);
            $k = hash_hmac('sha256', $service, $k, true);
            $k = hash_hmac('sha256', 'aws4_request', $k, true);
            $sg = hash_hmac('sha256', $sts, $k);
            $auth = "AWS4-HMAC-SHA256 Credential={$cfg['access']}/$scope, SignedHeaders=$sHeaders, Signature=$sg";
            $ch = curl_init("https://$host$uri?$canonicalQuery");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => ["Host: $host", "x-amz-date: $amz", "x-amz-content-sha256: $ph", "Authorization: $auth"],
            ]);
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code !== 200) return ['ok' => false, 'code' => $code];
            $xml = @simplexml_load_string((string) $res);
            if (!$xml) return ['ok' => false];
            foreach ($xml->Contents as $c) { $bytes += (int) $c->Size; $objetos++; }
            $cont = (string) ($xml->NextContinuationToken ?? '');
            $trunc = ((string) ($xml->IsTruncated ?? 'false')) === 'true';
        } while ($trunc && $cont !== '' && ++$vueltas < 50);
        return ['ok' => true, 'bytes' => $bytes, 'objetos' => $objetos];
    }

    // Igual pero cacheado 5 min (para no listar el bucket en cada carga del panel)
    public static function r2UsoCacheado(PDO $conn, $actualizar = true)
    {
        $cache = json_decode(self::ajuste($conn, 'r2_uso_cache'), true);
        if (!$actualizar) return is_array($cache) ? $cache : null;
        if (is_array($cache) && time() - (int) ($cache['t'] ?? 0) < 300) return $cache;
        $u = self::r2Uso($conn);
        if (!empty($u['ok'])) {
            $cache = ['t' => time(), 'bytes' => (int) $u['bytes'], 'objetos' => (int) $u['objetos']];
            self::guardarAjuste($conn, 'r2_uso_cache', json_encode($cache));
            return $cache;
        }
        return is_array($cache) ? $cache : null;
    }

    /* ---------- Retención: borrar videos/imágenes viejos (R2 y local) ---------- */

    public static function retencionDias(PDO $conn)
    {
        return max(0, (int) self::ajuste($conn, 'retencion_dias')); // 0 = no borrar
    }

    public static function guardarRetencion(PDO $conn, $dias)
    {
        self::guardarAjuste($conn, 'retencion_dias', (string) max(0, (int) $dias));
    }

    // Borra los archivos (R2 + local) y el pedido cuando pasan los días de retención.
    public static function depurarAntiguos(PDO $conn)
    {
        $dias = self::retencionDias($conn);
        if ($dias <= 0) return;
        $cfg = self::r2Config($conn);
        $st = $conn->prepare(
            "SELECT id, archivos FROM tbl_flow_trabajos
             WHERE archivos IS NOT NULL AND archivos <> '' AND archivos <> '[]'
               AND COALESCE(terminado_en, creado_en) < (NOW() - INTERVAL ? DAY)
             ORDER BY id LIMIT 100"
        );
        $st->execute([$dias]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $lista = json_decode((string) $t['archivos'], true);
            foreach ((is_array($lista) ? $lista : []) as $a) {
                if (!empty($a['url']) && $cfg && strpos($a['url'], $cfg['publicurl']) === 0) {
                    $key = ltrim(substr($a['url'], strlen($cfg['publicurl'])), '/');
                    self::borrarR2($conn, rawurldecode($key));
                } elseif (!empty($a['ruta']) && strpos($a['ruta'], FlowCola::CARPETA . '/') === 0) {
                    @unlink(__DIR__ . '/../' . $a['ruta']);
                }
            }
            // El pedido desaparece: ya no tiene archivo
            $conn->prepare("DELETE FROM tbl_flow_trabajos WHERE id = ?")->execute([(int) $t['id']]);
        }
    }

    // ¿Hay token y, al menos, una forma de elegir cuenta (email fijo o rotación)?
    // Entonces genera el servidor (y la extensión ya no)
    public static function activa(PDO $conn)
    {
        // Interruptor "Desactivar servidor": con el token puesto pero desactivado,
        // el servidor NO genera y la extensión vuelve a funcionar (sin borrar nada).
        if (self::ajuste($conn, 'api_desactivada') === '1') return false;
        return self::token($conn) !== '' && (self::email($conn) !== '' || self::rotar($conn));
    }

    // Activa/desactiva el servidor sin borrar el token (interruptor del panel).
    public static function setDesactivada(PDO $conn, $desactivada)
    {
        self::guardarAjuste($conn, 'api_desactivada', $desactivada ? '1' : '0');
    }

    public static function estado(PDO $conn)
    {
        $token = self::token($conn);
        $email = self::email($conn);
        $rotar = self::rotar($conn);
        return [
            'configurada' => $token !== '' && ($email !== '' || $rotar),
            'tiene_token' => $token !== '',
            'final'       => $token !== '' ? substr($token, -4) : '',
            'email'       => $email,
            'rotar'       => $rotar,
            'pausa'       => self::ajuste($conn, 'api_pausa') === '1',
            'desactivada' => self::ajuste($conn, 'api_desactivada') === '1',
            'error'       => self::ajuste($conn, 'api_error'),
            'error_hace'  => self::haceDesde(self::ajuste($conn, 'api_error_en')),
            'modelos'     => self::modelos($conn),
        ];
    }

    // Borra el "último aviso" (lo pulsa el admin con "✕ Descartar")
    public static function limpiarAviso(PDO $conn)
    {
        self::guardarAjuste($conn, 'api_error', '');
        self::guardarAjuste($conn, 'api_error_en', '');
    }

    // "hace X" a partir de un timestamp guardado (para el aviso de error)
    private static function haceDesde($ts)
    {
        $ts = (int) $ts;
        if ($ts <= 0) return '';
        $d = max(0, time() - $ts); $m = intdiv($d, 60);
        if ($d < 60) return 'hace ' . $d . ' s';
        if ($m < 60) return 'hace ' . $m . ' min';
        if ($m < 1440) return 'hace ' . intdiv($m, 60) . ' h';
        return 'hace ' . intdiv($m, 1440) . ' d';
    }

    // Monitor en vivo para el admin: líneas de texto con lo que está pasando
    // (generándose ahora, cola, últimos listos, último error). Lo pinta el panel
    // en el cuadro "Progreso y resultado".
    public static function progreso(PDO $conn)
    {
        $lineas = [];
        $corta = function ($s, $n = 42) { $s = trim(preg_replace('/\s+/u', ' ', (string) $s)); return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') . '…' : $s; };

        // TODOS los que se están generando ahora, con su PROCESO paso a paso
        // (enviando prompt → job creado → esperando), igual que el test de useapi.
        $nProc = (int) $conn->query("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado = 'procesando'")->fetchColumn();
        if ($nProc > 0) {
            $lineas[] = '🎬 Generándose ahora: ' . $nProc;
            $proc = $conn->query("SELECT prompt, cuenta, operacion, tomado_en, mensaje FROM tbl_flow_trabajos WHERE estado = 'procesando' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($proc as $p) {
                $cta = $p['cuenta'] !== '' ? ' · ' . explode('@', $p['cuenta'])[0] : '';
                if (trim((string) $p['operacion']) !== '') {
                    // Ya se envió y Flow devolvió el job: esperando a que termine
                    $seg = $p['tomado_en'] ? max(0, time() - strtotime($p['tomado_en'])) : 0;
                    $est = trim((string) $p['mensaje']);
                    $estLinea = (stripos($est, 'Estado:') === 0) ? $est : 'Esperando a que se genere…';
                    $lineas[] = '🎥 ' . $corta($p['prompt']) . $cta;
                    $lineas[] = '   ✅ Job creado: ' . $corta($p['operacion'], 48);
                    $lineas[] = '   ⏳ ' . $estLinea . ' (' . $seg . 's)';
                } else {
                    // Recién tomado: enviando el prompt a Flow
                    $lineas[] = '🚀 ' . $corta($p['prompt']) . $cta;
                    $lineas[] = '   📤 Enviando prompt a Flow…';
                }
            }
            if ($nProc > count($proc)) $lineas[] = '   … y ' . ($nProc - count($proc)) . ' más generándose';
        }
        $pend = (int) $conn->query("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado = 'pendiente'")->fetchColumn();
        if ($pend > 0) $lineas[] = '⏳ En cola: ' . $pend . ' pedido' . ($pend === 1 ? '' : 's');

        // Últimos entregados, con su tiempo real
        foreach ($conn->query("SELECT prompt, tomado_en, terminado_en FROM tbl_flow_trabajos WHERE estado = 'hecho' AND terminado_en IS NOT NULL ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC) as $hh) {
            $seg = $hh['tomado_en'] ? (strtotime($hh['terminado_en']) - strtotime($hh['tomado_en'])) : 0;
            $lineas[] = '✓ Listo: ' . $corta($hh['prompt'], 34) . ($seg > 0 ? ' (' . $seg . 's)' : '');
        }
        $err = trim(self::ajuste($conn, 'api_error'));
        if ($err !== '') $lineas[] = '⚠️ ' . $corta($err, 90);
        if (!$lineas) $lineas[] = 'Esperando… (sin videos generándose ahora)';
        return $lineas;
    }

    public static function modelos(PDO $conn)
    {
        $m = json_decode(self::ajuste($conn, 'api_modelos'), true);
        $m = is_array($m) ? $m : [];
        return [
            'video'  => self::idValido($m['video'] ?? '') ? $m['video'] : self::DEFECTO['video'],
            'imagen' => self::idValido($m['imagen'] ?? '') ? $m['imagen'] : self::DEFECTO['imagen'],
        ];
    }

    private static function idValido($id)
    {
        return is_string($id) && (bool) preg_match('/^[a-z0-9][a-z0-9.\-]{2,80}$/', $id);
    }

    // El modelo con el que se genera un pedido: el que eligió el admin al
    // pedirlo, o el que tiene puesto por defecto
    public static function modeloDe(PDO $conn, $tipo, $pedido = '')
    {
        $tipo = $tipo === 'imagen' ? 'imagen' : 'video';
        $encaja = $tipo === 'video'
            ? preg_match('/^(veo-|omni-)/', (string) $pedido)
            : preg_match('/^(nano-banana|imagen-)/', (string) $pedido);
        return self::idValido($pedido) && (isset(self::MODELOS[$tipo][$pedido]) || $encaja) ? $pedido : self::modelos($conn)[$tipo];
    }

    public static function etiqueta($id)
    {
        foreach (self::MODELOS as $lista) if (isset($lista[$id])) return $lista[$id];
        return (string) $id;
    }

    // $token null = no tocarlo; '' = quitarlo (junto con el email).
    // $rotar = repartir round-robin entre todas las cuentas conectadas y sanas.
    public static function guardar(PDO $conn, $token, $email, array $modelos, $pausa, $rotar = false)
    {
        self::guardarAjuste($conn, 'api_rotar', $rotar ? '1' : '0');
        if ($token !== null) {
            $token = trim((string) $token);
            $email = trim((string) $email);
            if ($token === '') {
                // Quitar la conexión: se deja de generar para todos
                self::guardarAjuste($conn, 'api_token', '');
                self::guardarAjuste($conn, 'api_email', '');
                self::guardarAjuste($conn, 'api_error', '');
                self::guardarAjuste($conn, 'api_bloqueo', '0');
            } else {
                if (!preg_match('/^[A-Za-z0-9:_\-]{20,200}$/', $token)) {
                    return 'Ese token no parece un token de Núcleo SNAVE (suele empezar por "user:…").';
                }
                // Sin rotación hace falta una cuenta fija; con rotación el email es
                // opcional (se usa como respaldo si Núcleo SNAVE no devuelve cuentas).
                if (!$rotar && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                    return 'Escribe el email de la cuenta de Google Flow conectada en Núcleo SNAVE.';
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return 'El email de la cuenta de Google Flow no es válido.';
                }
                // Lo que Núcleo SNAVE rechaza no se guarda: se queda lo anterior
                $prueba = $rotar ? self::probarToken($conn, $token) : self::probar($conn, $token, $email);
                if ($prueba) return 'No se guardó la conexión. Núcleo SNAVE dice: ' . $prueba;
                self::guardarAjuste($conn, 'api_token', $token);
                self::guardarAjuste($conn, 'api_email', $email);
                self::guardarAjuste($conn, 'api_error', '');
                self::guardarAjuste($conn, 'api_bloqueo', '0');
            }
        } else {
            // No se cambió el token (se conserva el actual), pero el email SÍ se
            // actualiza: así se puede poner/cambiar la cuenta fija o de respaldo sin
            // tener que volver a pegar el token.
            $email = trim((string) $email);
            if (!$rotar && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                return 'Escribe el email de la cuenta de Google Flow conectada en Núcleo SNAVE.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'El email de la cuenta de Google Flow no es válido.';
            }
            self::guardarAjuste($conn, 'api_email', $email);
        }
        $m = self::modelos($conn);
        foreach (['video', 'imagen'] as $t) {
            if (isset($modelos[$t]) && self::idValido(trim((string) $modelos[$t]))) $m[$t] = trim((string) $modelos[$t]);
        }
        self::guardarAjuste($conn, 'api_modelos', json_encode($m));
        self::guardarAjuste($conn, 'api_pausa', $pausa ? '1' : '0');
        return null;
    }

    // De la respuesta de GET /accounts, los emails conectados y sanos (health OK)
    private static function filtrarSanas(array $cuentas)
    {
        $lista = [];
        foreach ($cuentas as $email => $info) {
            if (!is_string($email) || $email === '') continue;
            $salud = is_array($info) ? ($info['health'] ?? 'OK') : 'OK';
            if ($salud === '' || $salud === 'OK') $lista[] = $email;
        }
        return $lista;
    }

    // Lista en vivo de cuentas conectadas y sanas (para la rotación y el panel). []
    // si el token no vale o Núcleo SNAVE no responde.
    public static function listarCuentas(PDO $conn, $token = null)
    {
        $token = $token === null ? self::token($conn) : trim((string) $token);
        if ($token === '') return [];
        [$codigo, $j] = self::http('GET', self::base() . 'accounts', $token, null, 20);
        return $codigo === 200 ? self::filtrarSanas(is_array($j) ? $j : []) : [];
    }

    // TODAS las cuentas conectadas con su estado, para mostrarlas en el panel.
    // Cada una: ['email' => ..., 'salud' => 'OK'|..., 'sana' => bool].
    public static function cuentasDetalle(PDO $conn, $token = null)
    {
        $token = $token === null ? self::token($conn) : trim((string) $token);
        if ($token === '') return [];
        [$codigo, $j] = self::http('GET', self::base() . 'accounts', $token, null, 20);
        if ($codigo !== 200 || !is_array($j)) return [];
        $out = [];
        foreach ($j as $email => $info) {
            if (!is_string($email) || $email === '') continue;
            $salud = is_array($info) ? (string) ($info['health'] ?? 'OK') : 'OK';
            if ($salud === '') $salud = 'OK';
            $out[] = ['email' => $email, 'salud' => $salud, 'sana' => $salud === 'OK'];
        }
        return $out;
    }

    // "Probar la conexión" con rotación: basta con que el token valga y haya >=1
    // cuenta conectada y sana. $token null = el guardado.
    public static function probarToken(PDO $conn, $token = null)
    {
        $token = $token === null ? self::token($conn) : trim((string) $token);
        if ($token === '') return 'Aún no hay token puesto.';
        [$codigo, $j, $error] = self::http('GET', self::base() . 'accounts', $token, null, 20);
        if ($codigo !== 200) return $error ?: 'Núcleo SNAVE respondió con el código ' . $codigo . '.';
        if (!self::filtrarSanas(is_array($j) ? $j : [])) {
            return 'No hay ninguna cuenta de Google Flow conectada y sana en Núcleo SNAVE. Conecta al menos una.';
        }
        return null;
    }

    // "Probar la conexión" con cuenta fija: pide la lista y comprueba que el email
    // está conectado y sano. $token/$email null = los guardados.
    public static function probar(PDO $conn, $token = null, $email = null)
    {
        $token = $token === null ? self::token($conn) : trim((string) $token);
        $email = $email === null ? self::email($conn) : trim((string) $email);
        if ($token === '') return 'Aún no hay token puesto.';
        [$codigo, $j, $error] = self::http('GET', self::base() . 'accounts', $token, null, 20);
        if ($codigo !== 200) return $error ?: 'Núcleo SNAVE respondió con el código ' . $codigo . '.';
        $cuentas = is_array($j) ? $j : [];
        if ($email === '') return 'Falta el email de la cuenta de Google Flow.';
        if (!isset($cuentas[$email])) {
            $disp = implode(', ', array_keys($cuentas));
            return 'La cuenta ' . $email . ' no está conectada en Núcleo SNAVE.' . ($disp !== '' ? ' Conectadas: ' . $disp . '.' : ' No hay ninguna cuenta conectada.');
        }
        $salud = $cuentas[$email]['health'] ?? 'OK';
        if ($salud !== 'OK' && $salud !== '') return 'La cuenta ' . $email . ' tiene el estado "' . $salud . '". Revísala en Núcleo SNAVE.';
        return null;
    }

    /* ---------- Imágenes de entrada (Spaces) ---------- */

    // Solo rutas de nuestras carpetas (uploads/flow/...), que existan y sean imágenes
    public static function limpiarEntradas(PDO $conn, $entradas, $uid = null, $dispositivo = null, $estricto = false)
    {
        $e = is_array($entradas) ? $entradas : json_decode((string) $entradas, true);
        if (!is_array($e)) return [];
        $cfg = self::r2Config($conn);
        $r2base = $cfg ? $cfg['publicurl'] . '/flow/' : null;
        $una = function ($r) use ($r2base, $conn, $uid, $dispositivo) {
            $r = is_string($r) ? $r : '';
            // Imagen pública de NUESTRO bucket R2 (generada aquí): se acepta su URL
            if ($r2base && strpos($r, $r2base) === 0 && strpos($r, '..') === false && preg_match('#\.(png|jpe?g|webp|gif)$#i', $r)) return $r;
            // Solo enlaces de imágenes entregadas y registradas en SnaveCloud.
            if (preg_match('#^https://flow-content\.google/image/[A-Za-z0-9-]+(?:\?[^\s]*)?$#i', $r)) {
                $sql="SELECT archivos FROM tbl_flow_trabajos WHERE tipo='imagen' AND estado='hecho'";
                $params=[];
                if ($uid !== null) { $sql.=" AND creado_por=?"; $params[]=(int)$uid; }
                if ($dispositivo !== null && $dispositivo !== '') { $sql.=" AND dispositivo=?"; $params[]=(string)$dispositivo; }
                // Comparar JSON evita aceptar coincidencias parciales de URL.
                $st=$conn->prepare($sql); $st->execute($params);
                while ($fila=$st->fetch(PDO::FETCH_ASSOC)) foreach ((array)json_decode($fila['archivos'],true) as $archivo) {
                    if (($archivo['tipo'] ?? '')!=='imagen') continue;
                    if (($archivo['url'] ?? '')===$r) return $r;
                    if (($archivo['fuente_flow'] ?? '')===$r) return !empty($archivo['ruta']) ? $archivo['ruta'] : ($archivo['url'] ?? null);
                }
                return null;
            }
            // Archivo local uploads/flow/...
            if (!preg_match('#^uploads/flow/[A-Za-z0-9_./-]+\.(png|jpe?g|webp|gif)$#i', $r) || strpos($r, '..') !== false) return null;
            $raiz = realpath(__DIR__ . '/../uploads/flow');
            $f = realpath(__DIR__ . '/../' . $r);
            return $raiz && $f && strpos($f, $raiz) === 0 && is_file($f) ? $r : null;
        };
        $out = [];
        foreach (['inicio', 'fin'] as $k) if (!empty($e[$k]) && ($r = $una($e[$k]))) $out[$k] = $r;
        foreach (['ingredientes', 'referencias'] as $k) {
            $lista = array_values(array_filter(array_map($una, array_slice((array) ($e[$k] ?? []), 0, 10))));
            if ($lista) $out[$k] = $lista;
        }
        if ($estricto) {
            $pedidas=count(FlowCola::entradasParaExtension(['entradas'=>json_encode($e)]));
            $aceptadas=count(FlowCola::entradasParaExtension(['entradas'=>json_encode($out)]));
            if ($pedidas!==$aceptadas) throw new InvalidArgumentException('No se pudo usar una imagen adjunta. Vuelve a adjuntarla; el pedido no se enviará sin ella.');
        }
        return $out;
    }

    // Sube una imagen local a Núcleo SNAVE (POST /assets/{email}) y devuelve su
    // mediaGenerationId, o null. Se cachea por ruta dentro de esta petición.
    private static function subirAsset(PDO $conn, $token, $email, $ruta)
    {
        $clave = $email . "\n" . $ruta;
        if (array_key_exists($clave, self::$assets)) return self::$assets[$clave];
        self::$assets[$clave] = null;
        // Los bytes de la imagen: de una URL (R2) o de un archivo local
        if (preg_match('#^https?://#i', $ruta)) {
            $ch = curl_init($ruta);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
            $bytes = curl_exec($ch);
            $dcod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!is_string($bytes) || $dcod >= 400 || strlen($bytes) < 10 || strlen($bytes) > self::MAX_IMAGEN_BYTES) return null;
            $ext = strtolower((string) pathinfo((string) parse_url($ruta, PHP_URL_PATH), PATHINFO_EXTENSION));
            $mime = self::mimeDeExt($ext === 'jpeg' ? 'jpg' : $ext);
            if (strpos($mime, 'image/') !== 0) $mime = 'image/jpeg';
        } else {
            $f = __DIR__ . '/../' . $ruta;
            if (!is_file($f) || filesize($f) > self::MAX_IMAGEN_BYTES) return null;
            $mime = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f) : mime_content_type($f);
            if (strpos((string) $mime, 'image/') !== 0) return null;
            $bytes = file_get_contents($f);
        }

        $ch = curl_init(self::base() . 'assets/' . rawurlencode($email));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bytes,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: ' . $mime],
        ]);
        $res = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($res === false || $codigo >= 400) return null;
        $j = json_decode((string) $res, true);
        // POST /assets devuelve el id anidado como mediaGenerationId.mediaGenerationId
        $id = $j['mediaGenerationId']['mediaGenerationId'] ?? ($j['mediaGenerationId'] ?? null);
        self::$assets[$clave] = is_string($id) && $id !== '' ? $id : null;
        return self::$assets[$clave];
    }

    /* ---------- El trabajo: avanzar a trozos ---------- */

    public static function avanzar(PDO $conn)
    {
        self::asegurar($conn);
        // Depuración de archivos viejos (retención): como mucho una vez por hora,
        // pase lo que pase con la generación (aunque esté en pausa).
        if (time() - (int) self::ajuste($conn, 'depura_ultimo') > 3600) {
            self::guardarAjuste($conn, 'depura_ultimo', (string) time());
            try { self::depurarAntiguos($conn); } catch (Throwable $e) { error_log('depura: ' . $e->getMessage()); }
        }
        // Copia a R2/local los videos que se mostraron al instante con la URL
        // temporal de Google (visualización inmediata). Corre aunque la generación
        // esté en pausa: hay que bajarlos antes de que la URL caduque. Como mucho
        // una vez cada 6 s y una sola a la vez (candado propio).
        if (time() - (int) self::ajuste($conn, 'migra_ultimo') >= 6) {
            if ((int) $conn->query("SELECT GET_LOCK('snave_flow_migra', 0)")->fetchColumn()) {
                try {
                    self::guardarAjuste($conn, 'migra_ultimo', (string) time());
                    @set_time_limit(170);
                    ignore_user_abort(true);
                    self::migrarPendientesR2($conn);
                } catch (Throwable $e) { error_log('migraR2: ' . $e->getMessage()); }
                $conn->query("SELECT RELEASE_LOCK('snave_flow_migra')");
            }
        }
        $token = self::token($conn);
        if ($token === '' || !self::activa($conn) || self::ajuste($conn, 'api_pausa') === '1') return;
        // Como mucho una vez cada 3 s (la llaman todas las páginas abiertas / el cron)
        if (time() - (int) self::ajuste($conn, 'api_ultimo') < 3) return;
        // El token o la cuenta fallaron hace nada: se espera 1 min antes de volver a probar
        if (time() - (int) self::ajuste($conn, 'api_bloqueo') < 60) return;
        // Y de una en una: si otra petición ya está en ello, esta no hace nada
        if (!(int) $conn->query("SELECT GET_LOCK('snave_flow_api', 0)")->fetchColumn()) return;
        try {
            self::guardarAjuste($conn, 'api_ultimo', time());
            @set_time_limit(170);
            ignore_user_abort(true);
            // AUTO-DESCARTE del aviso: los errores TRANSITORIOS (rechazo puntual, cuenta
            // caída un momento, rate limit, corte de red) se borran SOLOS pasados ~3 min
            // sin que nadie los toque. Los PERSISTENTES (sin cuentas / sin créditos /
            // configuración) se quedan hasta resolverlos. Si el problema sigue, el
            // próximo intento vuelve a poner el aviso con hora nueva.
            $errAviso = self::ajuste($conn, 'api_error');
            $errEn    = (int) self::ajuste($conn, 'api_error_en');
            if ($errAviso !== '' && $errEn > 0 && time() - $errEn > 180
                && !preg_match('/cuenta|cr[eé]dito|configuraci|captcha/iu', $errAviso)) {
                self::limpiarAviso($conn);
            }
            $inicio = microtime(true);
            $quedaTiempo = function () use ($inicio) { return microtime(true) - $inicio < 45; };

            // 1) Los videos que Núcleo SNAVE está generando: ¿ya terminó alguno?
            $st = $conn->query("SELECT * FROM tbl_flow_trabajos WHERE estado = 'procesando' AND operacion <> '' ORDER BY id LIMIT 10");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                if (!$quedaTiempo()) return;
                self::revisar($conn, $token, $t);
            }
            // 1.5) Rescate RÁPIDO de envíos colgados: un video 'procesando' SIN jobid
            // por más de 25 s es un envío que se cortó a media (el proceso murió en
            // Hostinger). Vuelve a la cola para relanzarlo YA en esta misma pasada, sin
            // esperar la red de seguridad de 2 min. Así "Enviando prompt…" no se queda
            // pegado: a lo sumo ~25 s y se relanza solo.
            $conn->exec("UPDATE tbl_flow_trabajos SET estado = 'pendiente', operacion = '', tomado_en = NULL, mensaje = ''
                         WHERE estado = 'procesando' AND operacion = '' AND tipo <> 'imagen' AND tomado_en < NOW() - INTERVAL 25 SECOND");
            // 2) Pedidos nuevos. El límite "a la vez" es POR CUENTA: con rotación, CADA
            // cuenta de Google Flow genera hasta N videos a la vez. Así, con 3 cuentas
            // y N=3, se generan hasta 9 en paralelo (≈3 por cuenta). Las imágenes van
            // aparte (ilimitadas, síncronas).
            $config = FlowCola::config($conn);
            $simul  = max(1, (int) $config['simultaneos']); // por CUENTA
            // Pendientes, los más antiguos primero
            $pendientes = $conn->query("SELECT id, tipo FROM tbl_flow_trabajos WHERE estado = 'pendiente' ORDER BY id LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
            if ($pendientes) {
                // Las cuentas donde generar: todas las conectadas y sanas (rotación),
                // o la cuenta fija. La lista de Núcleo SNAVE se pide una vez por pasada.
                $pool = self::rotar($conn) ? self::listarCuentas($conn, $token) : [];
                if (!$pool) { $e = self::email($conn); if ($e !== '') $pool = [$e]; }
                if (!$pool) {
                    self::guardarAjuste($conn, 'api_error', 'No hay cuentas de Google Flow conectadas y sanas en Núcleo SNAVE.');
                    self::guardarAjuste($conn, 'api_bloqueo', time());
                    return;
                }
                $nP = count($pool);
                // TOPE TOTAL = N por cuenta × cuentas (ej. 3 × 3 = 9). Se cuentan TODOS
                // los videos en curso, incluidos los que aún se están ENVIANDO (sin
                // jobid). Así NO se sobre-lanza: antes se contaban solo los que ya
                // tenían jobid, y cada pasada apilaba más encima → se saturaba useapi.
                $limiteTotal  = max(1, $simul * $nP);
                $totalEnCurso = (int) $conn->query("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado = 'procesando' AND tipo <> 'imagen'")->fetchColumn();
                $huecos = max(0, $limiteTotal - $totalEnCurso);
                // Como mucho 4 envíos nuevos por pasada: en paralelo pero sin saturar
                // useapi (y si Hostinger corta, se pierden pocos, no una tanda enorme).
                $maxLote = min($huecos, 4);

                $idx = (int) self::ajuste($conn, 'api_rot_idx');
                $videosALanzar = [];
                foreach ($pendientes as $p) {
                    if (!$quedaTiempo()) break;
                    if ($p['tipo'] === 'imagen') {
                        $t = self::tomarId($conn, (int) $p['id']);
                        if ($t) self::lanzar($conn, $token, $pool, $idx, $t); // imágenes: síncronas, una a una
                        continue;
                    }
                    if (count($videosALanzar) >= $maxLote) break; // no pasar del tope total ni del lote
                    $email = ''; $idxAntes = $idx;
                    for ($salto=0; $salto<$nP; $salto++) {
                        $candidata = $pool[$idx % $nP]; $idx++;
                        $stOcupada = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado = 'procesando' AND tipo <> 'imagen' AND cuenta = ?");
                        $stOcupada->execute([$candidata]);
                        if ((int) $stOcupada->fetchColumn() < $simul) { $email = $candidata; break; }
                    }
                    if ($email === '') break;
                    $t = self::tomarId($conn, (int) $p['id'], $email);
                    if (!$t) { $idx = $idxAntes; continue; }
                    $videosALanzar[] = ['t' => $t, 'email' => $email];
                }
                // Dispara el lote de envíos en paralelo y guarda cada jobid apenas llega.
                if ($videosALanzar) self::lanzarVideosParalelo($conn, $token, $pool, $idx, $videosALanzar);
                // El cursor del round-robin se guarda para la próxima pasada
                self::guardarAjuste($conn, 'api_rot_idx', $nP ? ($idx % $nP) : 0);
            }
        } catch (Throwable $e) {
            error_log('FlowApi: ' . $e->getMessage());
        } finally {
            $conn->query("SELECT RELEASE_LOCK('snave_flow_api')");
        }
    }

    // El pendiente más antiguo pasa a "procesando" (de forma atómica)
    private static function tomar(PDO $conn)
    {
        for ($i = 0; $i < 3; $i++) {
            $id = $conn->query("SELECT id FROM tbl_flow_trabajos WHERE estado = 'pendiente' ORDER BY id LIMIT 1")->fetchColumn();
            if (!$id) return null;
            $st = $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'procesando', tomado_en = NOW(), operacion = '' WHERE id = ? AND estado = 'pendiente'");
            $st->execute([(int) $id]);
            if ($st->rowCount()) return FlowCola::buscar($conn, (int) $id);
        }
        return null;
    }

    // Pasa un pedido CONCRETO de 'pendiente' a 'procesando' (atómico). null si
    // otra petición ya lo tomó mientras tanto.
    private static function tomarId(PDO $conn, $id, $cuenta = '')
    {
        return FlowCola::reservarPedido($conn, $id, $cuenta);
    }

    // Round-robin con failover: prueba las cuentas del pool hasta que una acepte el
    // pedido. $idx avanza (por referencia) para repartir entre pedidos y pasadas.
    private static function lanzar(PDO $conn, $token, array $pool, &$idx, array $t)
    {
        $modelo = self::modeloDe($conn, $t['tipo'], $t['modelo']);
        $entradas = self::limpiarEntradas($conn, $t['entradas'] ?? null);
        $n = count($pool);
        // Orden de cuentas a probar (round-robin). FAILOVER: si ya se intentó en una
        // cuenta (y falló), esa se prueba la ÚLTIMA — así primero va a OTRA distinta.
        $orden = [];
        for ($i = 0; $i < $n; $i++) { $orden[] = $pool[($idx + $i) % $n]; }
        $idx += $n;
        if ($n > 1 && !empty($t['cuenta'])) {
            usort($orden, function ($a, $b) use ($t) { return ($a === $t['cuenta'] ? 1 : 0) - ($b === $t['cuenta'] ? 1 : 0); });
        }
        $soloSinCreditos = true; $sinHueco = false;
        foreach ($orden as $email) {
            if ($t['tipo'] !== 'imagen') {
                $ocup = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado = 'procesando' AND tipo <> 'imagen' AND cuenta = ? AND id <> ?");
                $ocup->execute([$email, (int) $t['id']]);
                if ((int) $ocup->fetchColumn() >= max(1, (int) FlowCola::config($conn)['simultaneos'])) { $sinHueco = true; continue; }
                $conn->prepare("UPDATE tbl_flow_trabajos SET cuenta = ? WHERE id = ? AND estado = 'procesando'")->execute([$email, (int) $t['id']]);
            }
            $res = $t['tipo'] === 'imagen'
                ? self::generarImagen($conn, $token, $email, $t, $modelo, $entradas)
                : self::lanzarVideo($conn, $token, $email, $t, $modelo, $entradas);
            if ($res === 'sincreditos') continue;                     // esa cuenta sin créditos: prueba otra
            if ($res === 'cuenta') { $soloSinCreditos = false; continue; } // config: prueba otra
            return; // ok / esperar / fallo (ya resuelto)
        }
        if ($sinHueco) {
            $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'pendiente', tomado_en = NULL, operacion = '', mensaje = 'Esperando una cuenta con capacidad.' WHERE id = ? AND estado = 'procesando'")->execute([(int) $t['id']]);
            return;
        }
        if ($soloSinCreditos) {
            // Ninguna cuenta tiene créditos para ESTE modelo -> se RECHAZA (no se usa el gratis
            // ni se reintenta en bucle). El cliente elige el modelo gratis o se recargan créditos.
            $conn->prepare("UPDATE tbl_flow_trabajos SET operacion = '' WHERE id = ?")->execute([(int) $t['id']]);
            FlowCola::terminar($conn, (int) $t['id'], false, 'Sin créditos para este modelo en las cuentas. Elige un modelo GRATIS (Lite baja prioridad / Nano Banana) o recarga créditos.', false, true);
            self::guardarAjuste($conn, 'api_error', 'Sin créditos para el modelo seleccionado en ninguna cuenta.');
            return;
        }
        // Problema de configuración (token/permiso/conexión): vuelve a la espera y frena 1 min
        $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'pendiente', tomado_en = NULL, operacion = '', mensaje = 'Esperando: las cuentas tienen un problema de configuración.' WHERE id = ? AND estado = 'procesando'")
             ->execute([(int) $t['id']]);
        self::guardarAjuste($conn, 'api_error', 'Las cuentas de Google Flow fallaron (token/permiso/conexión).');
        self::guardarAjuste($conn, 'api_bloqueo', time());
    }

    // Lanza un video en una cuenta. Devuelve 'esperar' (enviado, en curso),
    // 'cuenta' (problema de esta cuenta -> probar otra) o 'fallo' (ya registrado).
    private static function lanzarVideo(PDO $conn, $token, $email, array $t, $modelo, array $entradas)
    {
        $cuerpo = self::cuerpoVideo($conn, $token, $email, $t, $modelo, $entradas);
        // async=true: Núcleo SNAVE registra el job y devuelve el id en SEGUNDOS. 20s de
        // tope: si no respondió en 20s es que el envío se colgó → falla y se reintenta
        // (no se queda "Enviando prompt" pegado un buen rato).
        [$codigo, $j, $error] = self::http('POST', self::base() . 'videos', $token, $cuerpo, 20);
        // Sin créditos para este modelo -> se prueba OTRA cuenta; si ninguna tiene, se rechaza (no se usa el gratis)
        if ($codigo === 402) return 'sincreditos';
        // Token/permiso/no encontrado -> failover a otra cuenta
        if (in_array($codigo, [401, 403, 404], true)) return 'cuenta';
        if ($error || $codigo >= 400) {
            $errTxt = trim(($error ?: '') . ' ' . (is_array($j) ? (string) ($j['error'] ?? '') : ''));
            if (self::esErrorCaptcha($errTxt)) {
                // Captcha sin fondos: pausar y NO gastar intento (ni saldo de captcha).
                self::frenarPorCaptcha($conn, (int) $t['id']);
            } elseif (self::esViolacionPolitica($errTxt)) {
                // El prompt viola la política de Flow: rechazar, no reintentar.
                self::fallar($conn, $t, '🛑 Rechazado por la política de contenido de Flow.', 400, true);
            } else {
                // Cualquier otro fallo: TEMPORAL → se reintenta (503 = transitorio, y el
                // failover lo manda a otra cuenta). Antes un 400 cerraba sin reintentar.
                self::fallar($conn, $t, $error ?: 'Núcleo SNAVE no aceptó el pedido.', $codigo >= 500 ? $codigo : 503);
            }
            return 'fallo';
        }
        // El POST async devuelve 201 con "jobid" (o "jobId")
        $jobId = $j['jobid'] ?? ($j['jobId'] ?? '');
        if ($jobId === '') {
            self::fallar($conn, $t, 'Núcleo SNAVE no devolvió el trabajo.', $codigo);
            return 'fallo';
        }
        $conn->prepare("UPDATE tbl_flow_trabajos SET operacion = ?, cuenta = ?, mensaje = '' WHERE id = ? AND estado = 'procesando'")->execute([mb_substr((string) $jobId, 0, 255), mb_substr((string) $email, 0, 190), (int) $t['id']]);
        self::guardarAjuste($conn, 'api_error', '');
        return 'esperar';
    }

    // Lanza VARIOS videos EN PARALELO (todos los POST a la vez con curl_multi), para
    // que todos salten a "Job creado" casi al mismo tiempo, no en fila. Cada uno va a
    // una cuenta por turno (rotación). Los que una cuenta rechaza (sin créditos,
    // permiso…) se reintentan con failover secuencial. $trabajos ya están 'procesando'.
    private static function lanzarVideosParalelo(PDO $conn, $token, array $pool, &$idx, array $items)
    {
        if (!$items) return;
        $mh = curl_multi_init();
        $reqs = [];
        foreach ($items as $it) {
            $t = $it['t'];
            $email = $it['email']; // cuenta ya asignada (rotación con hueco)
            $modelo = self::modeloDe($conn, $t['tipo'], $t['modelo']);
            $entradas = self::limpiarEntradas($conn, $t['entradas'] ?? null);
            $cuerpo = self::cuerpoVideo($conn, $token, $email, $t, $modelo, $entradas);
            $ch = curl_init(self::base() . 'videos');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            curl_multi_add_handle($mh, $ch);
            $reqs[] = ['ch' => $ch, 'email' => $email, 't' => $t];
        }
        // Dispara todas a la vez, pero GUARDA el jobid de cada una APENAS termina su
        // envío (no al final), para que si Hostinger corta el proceso los que ya
        // salieron queden con jobid y solo falten los que iban en vuelo.
        $fallidos = [];
        $activos = null;
        do {
            curl_multi_exec($mh, $activos);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                foreach ($reqs as $i => $r) {
                    if ($r['ch'] !== $ch) continue;
                    self::guardarRespuestaVideo($conn, $ch, $r, $fallidos);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($reqs[$i]);
                    break;
                }
            }
            if ($activos) curl_multi_select($mh, 1.0);
        } while ($activos);
        // Por si quedó alguno sin leer (no debería)
        foreach ($reqs as $r) { self::guardarRespuestaVideo($conn, $r['ch'], $r, $fallidos); curl_multi_remove_handle($mh, $r['ch']); curl_close($r['ch']); }
        curl_multi_close($mh);
        // Los que una cuenta rechazó: failover secuencial (rota a otra cuenta)
        foreach ($fallidos as $t) self::lanzar($conn, $token, $pool, $idx, $t);
    }

    // Procesa la respuesta de UN envío de video del curl_multi: guarda el jobid, o
    // lo pone en $fallidos (para failover), o lo marca fallido.
    private static function guardarRespuestaVideo(PDO $conn, $ch, array $r, array &$fallidos)
    {
        $res    = curl_multi_getcontent($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $j = json_decode((string) $res, true);
        if (!is_string($res) || $res === '' || $codigo === 0) { $fallidos[] = $r['t']; return; } // sin respuesta: failover
        if (in_array($codigo, [402, 401, 403, 404], true)) { $fallidos[] = $r['t']; return; }     // cuenta: probar otra
        if ($codigo >= 400) {
            $errTxt = (string) $res . ' ' . (is_array($j) ? (string) ($j['error'] ?? '') : '');
            if (self::esErrorCaptcha($errTxt)) { self::frenarPorCaptcha($conn, (int) $r['t']['id']); return; }
            if (self::esViolacionPolitica($errTxt)) { self::fallar($conn, $r['t'], '🛑 Rechazado por la política de contenido de Flow.', 400, true); return; }
            self::fallar($conn, $r['t'], 'Núcleo SNAVE no aceptó el pedido.', $codigo >= 500 ? $codigo : 503);
            return;
        }
        $jobId = is_array($j) ? ($j['jobid'] ?? ($j['jobId'] ?? '')) : '';
        if ($jobId === '') { self::fallar($conn, $r['t'], 'Núcleo SNAVE no devolvió el trabajo.', $codigo); return; }
        $conn->prepare("UPDATE tbl_flow_trabajos SET operacion = ?, cuenta = ?, mensaje = '' WHERE id = ? AND estado = 'procesando'")
             ->execute([mb_substr((string) $jobId, 0, 255), mb_substr((string) $r['email'], 0, 190), (int) $r['t']['id']]);
        self::guardarAjuste($conn, 'api_error', '');
    }

    private static function cuerpoVideo(PDO $conn, $token, $email, array $t, $modelo, array $entradas)
    {
        $cuerpo = [
            'model'       => $modelo,
            'email'       => $email,
            'prompt'      => (string) $t['prompt'],
            'aspectRatio' => ['9:16' => 'portrait', '16:9' => 'landscape'][$t['formato']] ?? $t['formato'],
            'duration'    => (int) $t['segundos'] ?: 8,
            'count'       => max(1, min(4, (int) $t['cantidad'])),
            'async'       => true,
        ];
        // Inicio / fin (imagen-a-video): se suben como asset y van por su id
        if (!empty($entradas['inicio']) && ($id = self::subirAsset($conn, $token, $email, $entradas['inicio']))) {
            $cuerpo['startImage'] = $id;
        }
        // El fin solo tiene sentido con un inicio
        if (!empty($cuerpo['startImage']) && !empty($entradas['fin']) && ($id = self::subirAsset($conn, $token, $email, $entradas['fin']))) {
            $cuerpo['endImage'] = $id;
        }
        return $cuerpo;
    }

    // ¿Terminó el video? Si sí, se descarga y se entrega
    private static function revisar(PDO $conn, $token, array $t)
    {
        // El jobid va crudo en la ruta (trae ':', '@', '*'); Núcleo SNAVE NO lo quiere url-encoded
        [$codigo, $j, $error] = self::http('GET', self::base() . 'jobs/' . $t['operacion'], $token, null, 20);
        if ($error) {
            // Un fallo al preguntar no cierra el pedido: se vuelve a preguntar luego
            if ($codigo >= 400 && $codigo < 500 && $codigo !== 429) self::fallar($conn, $t, $error, $codigo);
            return;
        }
        $status = (string) ($j['status'] ?? '');
        if ($status === 'failed') {
            $err = (string) ($j['error'] ?? 'Núcleo SNAVE no pudo generarlo.');
            if (self::esViolacionPolitica($err)) {
                // Viola la política de Flow: NO se entrega ni se reintenta.
                self::fallar($conn, $t, '🛑 Rechazado por la política de contenido de Flow: ' . mb_substr($err, 0, 120, 'UTF-8'), 400, true);
            } else {
                // Fallo TEMPORAL: se reintenta (sí o sí) — y el failover lo manda a otra cuenta.
                self::fallar($conn, $t, $err, 503);
            }
            return;
        }
        if ($status !== 'completed') {
            // Aún generándose: guardamos el estado que reporta Flow (started,
            // pending, processing…) para mostrarlo en el monitor "Progreso".
            if ($status !== '') {
                $conn->prepare("UPDATE tbl_flow_trabajos SET mensaje = ? WHERE id = ? AND estado = 'procesando'")
                     ->execute(['Estado: ' . mb_substr($status, 0, 30, 'UTF-8'), (int) $t['id']]);
            }
            return; // pending / processing: se espera
        }
        $media = $j['response']['media'] ?? [];
        // Capturar el id REAL del proyecto en Google Flow (viene en media[].projectId)
        // y vincularlo, para poder abrir el proyecto (flow.google.com/project/ID)
        // desde el panel. Antes solo lo reportaba la extensión; ahora lo tomamos aquí.
        $projectId = '';
        foreach ((array) $media as $m) { if (!empty($m['projectId'])) { $projectId = (string) $m['projectId']; break; } }
        if ($projectId !== '' && class_exists('FlowCola')) {
            try {
                FlowCola::registrarProyecto($conn, (int) $t['creado_por'], $projectId,
                    (string) ($t['proyecto_nombre'] ?? ''), (int) $t['id'], (string) ($t['proyecto'] ?? ''));
            } catch (\Throwable $e) { /* el link es secundario: no debe frenar la entrega */ }
        }
        // VISUALIZACIÓN INMEDIATA: se guarda la URL temporal de Google y se marca
        // "hecho" al instante, para que el cliente lo vea en cuanto Flow lo termina
        // (son segundos). La copia permanente a R2/local la hace migrarPendientesR2
        // en segundo plano, antes de que esa URL caduque.
        $guardados = 0;
        foreach ((array) $media as $m) {
            $url = $m['videoUrl'] ?? ($m['video']['videoUrl'] ?? null);
            if ($url) { self::anotarArchivoUrl($conn, (int) $t['id'], $url, 'video', true); $guardados++; }
        }
        if ($guardados) {
            FlowCola::terminar($conn, (int) $t['id'], true, '');
            self::guardarAjuste($conn, 'api_error', '');
            return;
        }
        self::fallar($conn, $t, '🛑 Rechazado por el filtro de contenido de Flow (terminó sin entregar video).', 400, true);
    }

    // Genera una imagen en una cuenta (POST /images es síncrono). Devuelve 'ok'
    // (guardada), 'cuenta' (problema de esta cuenta -> probar otra) o 'fallo'.
    private static function generarImagen(PDO $conn, $token, $email, array $t, $modelo, array $entradas)
    {
        $cuerpo = [
            'model'       => $modelo,
            'email'       => $email,
            'prompt'      => (string) $t['prompt'],
            'aspectRatio' => ['9:16' => 'portrait', '16:9' => 'landscape'][$t['formato']] ?? $t['formato'],
            'count'       => max(1, min(4, (int) $t['cantidad'])),
        ];
        // Imagen-a-imagen: referencias + ingredientes -> reference_1..reference_10
        $refs = array_merge((array) ($entradas['referencias'] ?? []), (array) ($entradas['ingredientes'] ?? []));
        $n = 0;
        foreach ($refs as $r) {
            if ($n >= 10) break;
            if ($id = self::subirAsset($conn, $token, $email, $r)) { $cuerpo['reference_' . (++$n)] = $id; }
        }
        // POST /images es síncrono: responde con las imágenes en la misma llamada
        [$codigo, $j, $error] = self::http('POST', self::base() . 'images', $token, $cuerpo, 120);
        // Sin créditos -> se prueba otra cuenta; si ninguna tiene, se rechaza
        if ($codigo === 402) return 'sincreditos';
        // Token/permiso/no encontrado -> failover a otra cuenta
        if (in_array($codigo, [401, 403, 404], true)) return 'cuenta';
        if ($error || $codigo >= 400) {
            self::fallar($conn, $t, $error ?: 'Núcleo SNAVE no generó la imagen.', $codigo, in_array($codigo, [400, 500], true));
            return 'fallo';
        }
        $guardadas = 0;
        foreach ((array) ($j['media'] ?? []) as $m) {
            $img = $m['image']['generatedImage'] ?? [];
            if (!empty($img['fifeUrl'])) { self::anotarArchivoUrl($conn, $t['id'], $img['fifeUrl'], 'imagen', true); $guardadas++; continue; }
            if (!empty($img['encodedImage']) && self::guardarBytes($conn, $t, base64_decode($img['encodedImage']), 'image/jpeg')) $guardadas++;
        }
        if ($guardadas) {
            $conn->prepare("UPDATE tbl_flow_trabajos SET cuenta = ? WHERE id = ?")->execute([mb_substr((string) $email, 0, 190), (int) $t['id']]);
            // Id real del proyecto en Flow (media[].projectId) para el link del panel
            $pid = '';
            foreach ((array) ($j['media'] ?? []) as $m) { if (!empty($m['projectId'])) { $pid = (string) $m['projectId']; break; } }
            if ($pid !== '' && class_exists('FlowCola')) {
                try { FlowCola::registrarProyecto($conn, (int) $t['creado_por'], $pid, (string) ($t['proyecto_nombre'] ?? ''), (int) $t['id'], (string) ($t['proyecto'] ?? '')); } catch (\Throwable $e) {}
            }
            FlowCola::terminar($conn, (int) $t['id'], true, '');
            self::guardarAjuste($conn, 'api_error', '');
            return 'ok';
        }
        self::fallar($conn, $t, 'Núcleo SNAVE no entregó ninguna imagen (puede ser el filtro de contenido).', 400, true);
        return 'fallo';
    }

    // Errores: los pasajeros (alta demanda, caída) vuelven a la cola si quedan
    // intentos; los de contenido quedan como "rechazado". El token/cuenta/crédito
    // no son culpa del pedido: vuelve a la espera y se frena todo 1 min.
    // ¿El fallo es por la POLÍTICA DE CONTENIDO de Flow? (prompt bloqueado por el
    // filtro de seguridad). Si lo es, NO se reintenta: se rechaza de una. Cualquier
    // otro fallo (cuenta, red, Google saturado) sí se reintenta "sí o sí".
    private static function esViolacionPolitica($txt)
    {
        $txt = (string) $txt;
        if ($txt === '') return false;
        return (bool) preg_match('/(content[ _-]?polic|safety|pol[ií]tica|violat|viola|blocked|bloque|flag|prohibit|prohibid|not allowed|no permitid|sensitive|sensible|responsible ai|guideline|moderat|moderaci|inappropriate|inapropiad|filtro de contenido)/iu', $txt);
    }

    // ¿El fallo es del SERVICIO DE CAPTCHA de Núcleo SNAVE (sin fondos / llaves
    // inválidas)? Google Flow pide captcha y useapi lo resuelve con un servicio de
    // captcha de PAGO. Si esas llaves se quedan sin saldo, NO se puede enviar nada.
    private static function esErrorCaptcha($txt)
    {
        return (bool) preg_match('/captcha/i', (string) $txt);
    }

    // Captcha caído: el pedido vuelve a la cola SIN gastar intento, y el motor se
    // pausa unos minutos (no martillea la API ni quema saldo de captcha en reintentos).
    private static function frenarPorCaptcha(PDO $conn, $id)
    {
        $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'pendiente', tomado_en = NULL, operacion = '',
                        mensaje = 'En espera: el servicio de captcha de Núcleo SNAVE se quedó sin fondos. Recárgalo para seguir generando.'
                        WHERE id = ? AND estado = 'procesando'")->execute([(int) $id]);
        self::guardarAjuste($conn, 'api_error', '🔑 El servicio de captcha de Núcleo SNAVE no tiene fondos (o las llaves son inválidas). Recárgalo o cámbialo en useapi; sin captcha NO se puede generar.');
        self::guardarAjuste($conn, 'api_error_en', time());
        self::guardarAjuste($conn, 'api_bloqueo', time() + 240); // pausa ~5 min (useapi reintenta sus llaves a los ~15 min)
    }

    private static function fallar(PDO $conn, array $t, $mensaje, $codigo = 0, $rechazo = false)
    {
        if (in_array($codigo, [401, 402, 403, 404], true)) {
            $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'pendiente', tomado_en = NULL, operacion = '', mensaje = 'Esperando: el generador tiene un problema de configuración.' WHERE id = ? AND estado = 'procesando'")
                 ->execute([(int) $t['id']]);
            self::guardarAjuste($conn, 'api_error', $mensaje);
            self::guardarAjuste($conn, 'api_error_en', time());
            self::guardarAjuste($conn, 'api_bloqueo', time());
            return;
        }
        $transitorio = $codigo === 0 || $codigo === 429 || $codigo >= 500;
        $conn->prepare("UPDATE tbl_flow_trabajos SET operacion = '' WHERE id = ? AND estado = 'procesando'")->execute([(int) $t['id']]);
        FlowCola::terminar($conn, (int) $t['id'], false, $mensaje, $transitorio, $rechazo && !$transitorio);
        if ($codigo === 429 || $codigo === 0) { self::guardarAjuste($conn, 'api_error', $mensaje); self::guardarAjuste($conn, 'api_error_en', time()); }
    }

    /* ---------- Archivos ---------- */

    private static function carpeta()
    {
        $dir = __DIR__ . '/../' . FlowCola::CARPETA;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Que nada de lo que caiga en la carpeta se pueda ejecutar como PHP
        if (!file_exists("$dir/.htaccess")) {
            file_put_contents("$dir/.htaccess", "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n");
        }
        return $dir;
    }

    private static function anotarArchivo(PDO $conn, $id, $ruta, $tipo)
    {
        FlowCola::vencerAtrasados($conn);
        $st = $conn->prepare("SELECT archivos FROM tbl_flow_trabajos WHERE id = ?");
        $st->execute([(int) $id]);
        $lista = json_decode((string) $st->fetchColumn(), true);
        $lista = is_array($lista) ? $lista : [];
        $lista[] = ['ruta' => $ruta, 'tipo' => $tipo];
        $conn->prepare("UPDATE tbl_flow_trabajos SET archivos = ? WHERE id = ? AND estado IN ('pendiente', 'procesando')")->execute([json_encode($lista), (int) $id]);
    }

    // Igual, pero para un archivo que vive en una URL (p.ej. Cloudflare R2, permanente).
    // $temp = true marca una URL TEMPORAL de Google (se muestra al instante y luego
    // se baja a R2/local en segundo plano, antes de que caduque: ver migrarPendientesR2).
    private static function anotarArchivoUrl(PDO $conn, $id, $url, $tipo, $temp = false)
    {
        FlowCola::vencerAtrasados($conn);
        $st = $conn->prepare("SELECT archivos FROM tbl_flow_trabajos WHERE id = ?");
        $st->execute([(int) $id]);
        $lista = json_decode((string) $st->fetchColumn(), true);
        $lista = is_array($lista) ? $lista : [];
        $item = ['url' => $url, 'tipo' => $tipo];
        if ($temp) { $item['temp'] = 1; $item['entregado_en'] = time(); $item['conservar_desde'] = time() + 180; }
        $lista[] = $item;
        $conn->prepare("UPDATE tbl_flow_trabajos SET archivos = ? WHERE id = ? AND estado IN ('pendiente', 'procesando')")->execute([json_encode($lista, JSON_UNESCAPED_SLASHES), (int) $id]);
    }

    private static function guardarBytes(PDO $conn, array $t, $bytes, $mime)
    {
        $actual = FlowCola::buscar($conn, (int) $t['id']);
        if (!$actual || !in_array($actual['estado'], ['pendiente', 'procesando'], true)) return false;
        if (!is_string($bytes) || $bytes === '') return false;
        $ext = ['video/mp4' => 'mp4', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? ($t['tipo'] === 'imagen' ? 'jpg' : 'mp4');
        $nombre = sprintf('%d_%s.%s', $t['id'], bin2hex(random_bytes(6)), $ext);
        $tipo = $ext === 'mp4' ? 'video' : 'imagen';
        // Si R2 está configurado, sube ahí (URL pública permanente); si falla, local
        if (self::r2Activo($conn)) {
            $url = self::subirR2($conn, $bytes, $mime, 'flow/' . $nombre);
            if ($url) { self::anotarArchivoUrl($conn, $t['id'], $url, $tipo); return true; }
        }
        if (file_put_contents(self::carpeta() . '/' . $nombre, $bytes) === false) return false;
        self::anotarArchivo($conn, $t['id'], FlowCola::CARPETA . '/' . $nombre, $tipo);
        return true;
    }

    // El archivo se baja directo a disco (el video puede pesar varios MB). Las
    // URL de Núcleo SNAVE/Google ya vienen firmadas: se bajan sin cabeceras.
    private static function descargarUrl(PDO $conn, array $t, $uri, $ext, $tipo)
    {
        $nombre = sprintf('%d_%s.%s', $t['id'], bin2hex(random_bytes(6)), $ext);
        // Se baja a un temporal en la misma carpeta (mismo volumen que el destino local)
        $temp = self::carpeta() . '/.tmp_' . bin2hex(random_bytes(6));
        $fh = fopen($temp, 'wb');
        if (!$fh) return false;
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
        ]);
        $ok = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if (!$ok || $codigo >= 400 || filesize($temp) < 1000) {
            @unlink($temp);
            return false;
        }
        // Si R2 está configurado, sube ahí (URL pública); si no (o falla), se queda en local
        if (self::r2Activo($conn)) {
            $url = self::subirR2($conn, file_get_contents($temp), self::mimeDeExt($ext), 'flow/' . $nombre);
            if ($url) { @unlink($temp); self::anotarArchivoUrl($conn, $t['id'], $url, $tipo); return true; }
        }
        $destino = self::carpeta() . '/' . $nombre;
        if (!@rename($temp, $destino)) { @unlink($temp); return false; }
        self::anotarArchivo($conn, $t['id'], FlowCola::CARPETA . '/' . $nombre, $tipo);
        return true;
    }

    // Descarga una URL a memoria (para re-subirla a R2). Devuelve el contenido o null.
    private static function bajarBytes($uri)
    {
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
        ]);
        $data   = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($data) || $data === '' || $codigo >= 400 || strlen($data) < 1000) return null;
        return $data;
    }

    // SEGUNDO PLANO: los videos se muestran al instante con su URL temporal de
    // Google (marcada temp=1). Aquí se bajan a R2 (o local) y se cambia el enlace
    // al permanente, antes de que la URL de Google caduque. Idempotente y suave:
    // pocas a la vez, reintenta si aún no responde y se rinde tras varios intentos.
    // Devuelve una referencia permanente; si R2 falla conserva la URL actual.
    public static function almacenarEntrega(PDO $conn, $id, $bytes, $mime)
    {
        if (!is_string($bytes) || strlen($bytes) < 1000 || !isset(FlowCola::TIPOS_ARCHIVO[$mime])) return null;
        $ext = FlowCola::TIPOS_ARCHIVO[$mime]; $tipo = strpos($mime, 'video/') === 0 ? 'video' : 'imagen';
        $nombre = sprintf('%d_%s.%s', (int) $id, bin2hex(random_bytes(6)), $ext);
        if (self::r2Activo($conn)) {
            $url = self::subirR2($conn, $bytes, $mime, 'flow/' . $nombre);
            return $url ? ['url' => $url, 'tipo' => $tipo] : null;
        }
        if (file_put_contents(self::carpeta() . '/' . $nombre, $bytes) === false) return null;
        return ['ruta' => FlowCola::CARPETA . '/' . $nombre, 'tipo' => $tipo];
    }

    public static function migrarPendientesR2(PDO $conn)
    {
        $rows = $conn->query(
            "SELECT id, archivos FROM tbl_flow_trabajos
             WHERE estado = 'hecho' AND terminado_en <= DATE_SUB(NOW(), INTERVAL 3 MINUTE) AND archivos LIKE '%\"temp\":1%' AND archivos NOT LIKE '%\"via_extension\":true%'
             ORDER BY id LIMIT 2"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $lista = json_decode((string) $r['archivos'], true);
            if (!is_array($lista)) continue;
            $cambio = false;
            foreach ($lista as $i => $item) {
                if (empty($item['temp']) || empty($item['url'])) continue;
                if (!empty($item['via_extension']) || time() < (int) ($item['conservar_desde'] ?? 0)) continue;
                if (time() < (int) ($item['reintentar_en'] ?? 0)) continue;
                $tipo = $item['tipo'] ?? 'video';
                $ext  = $tipo === 'imagen' ? 'jpg' : 'mp4';
                $mime = $tipo === 'imagen' ? 'image/jpeg' : 'video/mp4';
                $bytes = self::bajarBytes($item['url']);
                if ($bytes === null) {
                    // Conserva el enlace y reintenta sin bloquear la visualización.
                    $try = (int) ($item['try'] ?? 0) + 1;
                    $lista[$i]['try'] = $try;
                    $lista[$i]['reintentar_en'] = time() + min(300, $try * 30);
                    $cambio = true;
                    continue;
                }
                $nuevo = self::almacenarEntrega($conn, $r['id'], $bytes, $mime);
                if ($nuevo) { $lista[$i] = $nuevo; $cambio = true; }
                else { $lista[$i]['try'] = (int) ($item['try'] ?? 0) + 1; $cambio = true; }
            }
            if ($cambio) {
                $conn->prepare("UPDATE tbl_flow_trabajos SET archivos = ? WHERE id = ?")
                     ->execute([json_encode(array_values($lista), JSON_UNESCAPED_SLASHES), (int) $r['id']]);
            }
        }
    }

    /* ---------- HTTP con Núcleo SNAVE ---------- */

    // [código, json, error legible o null]. El token va en la cabecera Authorization
    private static function http($metodo, $url, $token, $cuerpo = null, $timeout = 30)
    {
        $ch = curl_init($url);
        $opciones = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
        ];
        if ($metodo === 'POST') {
            $opciones[CURLOPT_POST] = true;
            $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opciones);
        $res = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return [0, null, 'Sin conexión con Núcleo SNAVE' . ($err !== '' ? ": $err" : '') . '.'];
        $j = json_decode((string) $res, true);
        if ($codigo < 400) return [$codigo, is_array($j) ? $j : [], null];
        $msg = is_array($j) ? (string) ($j['error'] ?? ($j['message'] ?? '')) : '';
        $amable = [
            400 => 'Núcleo SNAVE no aceptó el pedido',
            401 => 'El token de Núcleo SNAVE no vale (revísalo en el panel)',
            402 => 'La cuenta de Google Flow no tiene suscripción o se quedó sin créditos',
            403 => 'El token no tiene permiso para esta cuenta (revísalo en Núcleo SNAVE)',
            404 => 'No se encontró eso en Núcleo SNAVE (revisa el token y la cuenta)',
            429 => 'Núcleo SNAVE dice que hay mucha demanda o se pasó la cuota; se reintenta solo',
        ][$codigo] ?? ($codigo >= 500 ? 'Núcleo SNAVE está fallando ahora; se reintenta solo' : 'Núcleo SNAVE respondió con el código ' . $codigo);
        return [$codigo, is_array($j) ? $j : [], mb_substr($amable . ($msg !== '' ? ': ' . $msg : '') . '.', 0, 250, 'UTF-8')];
    }
}
