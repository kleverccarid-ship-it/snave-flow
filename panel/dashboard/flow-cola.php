<?php
/**
 * Nuevo render: pedidos de videos e imágenes de Google Flow (Admin).
 *
 * DESDE EL 05/10/2026 GENERA EL SERVIDOR con la clave de Google del admin
 * (class/flow_api.php): ya no hace falta la extensión. Lo de abajo es la historia.
 *
 * El admin encola prompts; la extensión Flow Helper, con "Procesar
 * automáticamente" encendido, los genera en la cuenta de Flow abierta en su
 * Chrome y sube aquí los resultados, que se ven en la galería.
 * La lógica vive en class/flowcola.php; la API de la extensión en flow-cola-api.php.
 * El diseño sigue al "Novo render" de La Casa Dark Studio, en español.
 *
 * Acceso: Administrador (rol 1) y Administrador secundario (rol 5) siempre: ven
 * todos los pedidos y ponen las reglas. Cliente (rol 2) y partner (rol 3) solo
 * si el admin lo activa: ven solo lo suyo, con un límite diario, y generan en
 * SU cuenta de Flow con SU extensión y SU token.
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/class/flowcola.php';
require_once __DIR__ . '/class/flow_api.php';

// DOS MODOS. Dentro del panel (dashboard/flow-cola), con la sesión del panel.
// O como página pública (/flow, ver ../flow.php): ese archivo comprueba el
// correo y la contraseña del acceso y deja aquí $FR_PUBLICO antes de incluir
// esta página. En modo público no se carga nada del panel (ni su menú ni su login).
$frPublico = $GLOBALS['FR_PUBLICO'] ?? null;
$frBase    = $frPublico ? 'flow' : 'flow-cola';   // a dónde apuntan formularios y redirecciones
$frMedia   = $frPublico ? 'dashboard/' : '';      // la página pública está en la raíz, los archivos en dashboard/
// En el PANEL (/dashboard/flow-cola) NO se cargan reproductores de video: eran lo
// que hacía la página pesada (y además el panel ya carga global.php ~890 KB). Se
// muestra una miniatura liviana con enlace para abrir/descargar; para VER y CREAR
// videos cómodo, el admin entra por /flow con su cuenta.
$frSinVideo = !$frPublico;

if ($frPublico) {
    if (isset($frPublico['uid'])) {
        // Cliente del panel entrando por /flow con su propia cuenta: su user_id REAL
        $user_id   = (int) $frPublico['uid'];
        $user_role = (int) $frPublico['rol'];
    } else {
        // Acceso público legacy (correo + contraseña aparte): uid negativo
        $user_id   = FlowCola::uidDeAcceso($frPublico['id']);
        $user_role = FlowCola::ROL_ACCESO;
    }
} else {
    require_once __DIR__ . '/class/global.php';
    if (!isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/app/sesion_cerrada.php';
        sesionCerrada();
    }
    $user_id   = (int) $_SESSION['user_id'];
    $user_role = isset($_SESSION['user_role']) ? (int) $_SESSION['user_role'] : null;
}
$frUid = $user_id; // app/header.php pisa $user_id: esta copia no se toca

// Aislamiento por dispositivo: cuando varias personas entran con la MISMA cuenta
// de acceso de /flow, cada navegador solo ve y toca lo que generó él. Se identifica
// con una cookie larga por equipo. En el panel (cuentas propias) no aplica: $frDisp
// queda null y cada usuario ya ve solo lo suyo por su id.
// El aislamiento por dispositivo solo aplica a los ACCESOS legacy (cuenta compartida).
// Un cliente del panel entra con su propia cuenta, así que no necesita cookie: ve lo suyo por su user_id.
$frDisp = null;
$frDeviceId = null;
if ($frPublico) {
    // Cookie de dispositivo para TODOS los usuarios de /flow (límite de equipos por cliente)
    $ck = $_COOKIE['flow_dev'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', (string) $ck)) {
        $ck = bin2hex(random_bytes(16));
        setcookie('flow_dev', $ck, [
            'expires'  => time() + 86400 * 365 * 2,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['flow_dev'] = $ck;
    }
    $frDeviceId = $ck;
    // El aislamiento por dispositivo solo para accesos legacy (cuenta compartida)
    if (!isset($frPublico['uid'])) $frDisp = $ck;
}

FlowCola::asegurarTablas($conn);
FlowApi::asegurar($conn);

// Límite de dispositivos SOLO para CLIENTES DEL PANEL (anti-reventa): cada cliente
// que compra el servicio Flow entra con SU cuenta y puede usar como mucho N equipos.
// Los ACCESOS que crea el admin en "Accesos a la página pública" (cuenta compartida,
// p. ej. VIP CREADOR) NO tienen límite: están pensados para repartirse, y cada
// dispositivo ve solo lo suyo (aislamiento por dispositivo). El admin del dashboard
// tampoco cuenta. (Los clientes del panel llevan 'uid'; los accesos legacy, 'id'.)
$frAvisoDisp = null;
if ($frPublico && $frDeviceId && isset($frPublico['uid'])) {
    FlowCola::registrarDispositivo($conn, $user_id, $frDeviceId);
    $maxDisp = FlowCola::dispositivosMax($conn);
    if ($maxDisp > 0) {
        $nDisp = FlowCola::contarDispositivos($conn, $user_id);
        if ($nDisp > $maxDisp) {
            $frAvisoDisp = "⚠️ Tu cuenta se usó en $nDisp dispositivos (máximo permitido: $maxDisp). Compartir tu acceso puede BLOQUEAR tu cuenta. Úsala solo en tus equipos.";
        }
    }
}

// Admin: ve todo y pone las reglas. Cliente/partner: solo si el admin lo activó.
// Acceso público: ya lo validó flow.php. Todos generan en SU cuenta de Flow con
// SU extensión, y los que no son admin solo ven y tocan lo suyo.
$esAdmin = !$frPublico && in_array($user_role, FlowCola::ROLES_ADMIN, true);
// PANEL "solo números": el admin, en /dashboard/flow-cola, ve un TABLERO de totales
// + lo técnico (conexión, reglas, cron, proceso en vivo), SIN la galería ni el
// cuadro de crear. La creación visual vive en /flow. (En /flow esto es false.)
$frPanelNumeros = $esAdmin;
if (!$esAdmin && !$frPublico && !(in_array($user_role, FlowCola::ROLES_CLIENTE, true) && FlowCola::config($conn)['clientes_activo'])) {
    http_response_code(403);
    exit('Acceso denegado');
}

if (empty($_SESSION['flow_csrf'])) {
    $_SESSION['flow_csrf'] = bin2hex(random_bytes(16));
}

// Modelos por modo: los de la API de Google (class/flow_api.php). Desde el
// 05/10/2026 genera el servidor con la clave del admin, no la extensión.
// El modelo por defecto (el que tiene puesto el admin) va primero.
$frModelosApi = FlowApi::modelos($conn);
$frListaModelos = [];
foreach (FlowApi::MODELOS as $tipoM => $listaM) {
    if (!isset($listaM[$frModelosApi[$tipoM]])) $listaM = [$frModelosApi[$tipoM] => $frModelosApi[$tipoM]] + $listaM; // id escrito a mano
    $listaM = [$frModelosApi[$tipoM] => $listaM[$frModelosApi[$tipoM]]] + $listaM;
    foreach ($listaM as $idM => $etqM) $frListaModelos[$tipoM][$idM] = ['etiqueta' => $etqM, 'flow' => $idM, 'gratis' => FlowApi::modeloGratis($idM)];
}
define('FLOW_MODELOS', $frListaModelos);

// ---------- SPACES (05/10/2026): lienzo de nodos Texto -> Imagen / Video ----------
// Cada Space es de un usuario (o de un acceso público) y guarda sus nodos y
// cables en JSON. "Generar" en un nodo crea un pedido normal de la cola (igual
// que la caja de abajo): lo hace la extensión y sale también en el proyecto.
// Responde y, si $avanzar, DESPUÉS de responder hace avanzar la generación con
// Google (class/flow_api.php): quien espera la respuesta no nota la demora.
$frAvanzar = function () use ($conn) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    FlowApi::avanzar($conn);
};
$frJson = function ($datos, $codigo = 200, $avanzar = false) use ($frAvanzar) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    if ($avanzar) $frAvanzar();
    exit;
};
$esPeticionSpace = isset($_GET['spaces']) || isset($_GET['space_estado'])
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos((string) ($_POST['accion'] ?? ''), 'space_') === 0);
if ($esPeticionSpace) {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_flow_spaces (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            dueno       INT NOT NULL,
            nombre      VARCHAR(80) NOT NULL DEFAULT 'Mi Space',
            datos       MEDIUMTEXT NULL,
            actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dueno (dueno)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['flow_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $frJson(['error' => 'La página caducó. Recárgala y vuelve a intentarlo.'], 403);
    }
    // Lista de tus Spaces, o uno con sus nodos (?spaces=ID)
    if (isset($_GET['spaces'])) {
        if ((int) $_GET['spaces'] > 0) {
            $st = $conn->prepare("SELECT id, nombre, datos FROM tbl_flow_spaces WHERE id = ? AND dueno = ?");
            $st->execute([(int) $_GET['spaces'], $user_id]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) $frJson(['error' => 'Ese Space ya no existe.'], 404);
            $frJson(['id' => (int) $s['id'], 'nombre' => $s['nombre'], 'datos' => json_decode((string) $s['datos'], true)]);
        }
        $st = $conn->prepare("SELECT id, nombre FROM tbl_flow_spaces WHERE dueno = ? ORDER BY actualizado DESC, id DESC");
        $st->execute([$user_id]);
        $frJson(['spaces' => array_map(function ($s) { return ['id' => (int) $s['id'], 'nombre' => $s['nombre']]; }, $st->fetchAll(PDO::FETCH_ASSOC))]);
    }
    // Cómo van los pedidos que lanzaron los nodos (solo los tuyos; el admin, todos)
    if (isset($_GET['space_estado'])) {
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $_GET['space_estado'])))), 0, 80);
        $out = [];
        if ($ids) {
            $sql = "SELECT * FROM tbl_flow_trabajos WHERE id IN (" . implode(',', $ids) . ")" . ($esAdmin ? '' : " AND creado_por = " . (int) $user_id);
            foreach ($conn->query($sql) as $t) {
                $archivo = null;
                foreach (FlowCola::archivosDe($t) as $a) {
                    if (!empty($a['ruta']) || !empty($a['url'])) {
                        // "ruta": para mandar esa imagen a Google desde otro nodo (inicio, fin…)
                        $archivo = ['tipo' => $a['tipo'], 'src' => !empty($a['ruta']) ? $frMedia . $a['ruta'] : $a['url'], 'ruta' => (string) ($a['ruta'] ?? '')];
                        break;
                    }
                }
                $out[(int) $t['id']] = ['estado' => $t['estado'], 'mensaje' => $t['mensaje'], 'archivo' => $archivo];
            }
        }
        $frJson(['trabajos' => $out], 200, true);
    }
    $accion = (string) $_POST['accion'];
    $sid    = (int) ($_POST['id'] ?? 0);
    $esMio  = function ($sid) use ($conn, $user_id) {
        $st = $conn->prepare("SELECT 1 FROM tbl_flow_spaces WHERE id = ? AND dueno = ?");
        $st->execute([$sid, $user_id]);
        return (bool) $st->fetchColumn();
    };
    if ($accion === 'space_guardar') {
        $nombre = trim(mb_substr((string) ($_POST['nombre'] ?? ''), 0, 80, 'UTF-8'));
        $datos  = (string) ($_POST['datos'] ?? '');
        if (strlen($datos) > 300000 || !is_array(json_decode($datos, true))) $frJson(['error' => 'El Space es demasiado grande o llegó dañado.'], 400);
        if ($sid > 0) {
            if (!$esMio($sid)) $frJson(['error' => 'Ese Space ya no existe.'], 404);
            $conn->prepare("UPDATE tbl_flow_spaces SET nombre = ?, datos = ?, actualizado = NOW() WHERE id = ? AND dueno = ?")
                 ->execute([$nombre !== '' ? $nombre : 'Mi Space', $datos, $sid, $user_id]);
        } else {
            $conn->prepare("INSERT INTO tbl_flow_spaces (dueno, nombre, datos) VALUES (?, ?, ?)")
                 ->execute([$user_id, $nombre !== '' ? $nombre : 'Mi Space', $datos]);
            $sid = (int) $conn->lastInsertId();
        }
        $frJson(['ok' => true, 'id' => $sid]);
    }
    // "Subir" en un nodo de Imagen: una foto tuya (jpg, png, webp, gif) para el lienzo
    if ($accion === 'space_subir') {
        $f = $_FILES['imagen'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $frJson(['error' => 'No llegó la imagen. Prueba con una más pequeña.'], 400);
        if ($f['size'] > 10 * 1024 * 1024) $frJson(['error' => 'La imagen pasa de 10 MB.'], 400);
        // El tipo se mira en el contenido, no en lo que diga el navegador
        $mime = function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : mime_content_type($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (!$ext) $frJson(['error' => 'Solo imágenes JPG, PNG, WEBP o GIF.'], 400);
        $carpeta = 'uploads/flow/spaces';
        $dir = __DIR__ . '/' . $carpeta;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Que nada de lo que caiga en la carpeta se pueda ejecutar como PHP
        if (!file_exists("$dir/.htaccess")) {
            file_put_contents("$dir/.htaccess", "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n");
        }
        $nombre = sprintf('u%d_%s.%s', $user_id, bin2hex(random_bytes(8)), $ext);
        if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre")) $frJson(['error' => 'No se pudo guardar la imagen en el servidor.'], 500);
        $frJson(['ok' => true, 'ruta' => $carpeta . '/' . $nombre]);
    }
    if ($accion === 'space_borrar') {
        $conn->prepare("DELETE FROM tbl_flow_spaces WHERE id = ? AND dueno = ?")->execute([$sid, $user_id]);
        $frJson(['ok' => true]);
    }
    if ($accion === 'space_generar') {
        $tipo   = ($_POST['tipo'] ?? '') === 'imagen' ? 'imagen' : 'video';
        $prompt = trim((string) ($_POST['prompt'] ?? ''));
        if ($prompt === '') $frJson(['error' => 'Escribe el prompt o conecta un nodo de Texto.'], 400);
        if (mb_strlen($prompt, 'UTF-8') > 1200) $frJson(['error' => 'El prompt pasa de 1200 caracteres. Acórtalo.'], 400);
        $modelos = FLOW_MODELOS[$tipo];
        $ajustes = [
            'tipo'     => $tipo,
            'modelo'   => (array_key_exists($_POST['modelo'] ?? '', $modelos) && ($esAdmin || FlowApi::modeloGratis($_POST['modelo'] ?? ''))) ? $_POST['modelo'] : ($esAdmin ? array_key_first($modelos) : FlowApi::DEFECTO[$tipo]),
            'formato'  => in_array($_POST['formato'] ?? '', ['16:9', '9:16'], true) ? $_POST['formato'] : '16:9',
            'segundos' => 8, // Solo 8 s (lo que pidió el admin)
            'calidad'  => isset(FlowCola::CALIDADES['720p']) ? '720p' : (string) array_key_first(FlowCola::CALIDADES),
            'cantidad' => 1,
            'proyecto' => '',
            'proyecto_nombre' => '',
        ];
        $pidPedido = (string) ($_POST['proyecto'] ?? '');
        if ($pidPedido !== '') {
            foreach (FlowCola::proyectos($conn, $user_id, $frDisp) as $p) {
                if ($p['flow_id'] === $pidPedido) { $ajustes['proyecto'] = $pidPedido; $ajustes['proyecto_nombre'] = $p['nombre']; }
            }
        }
        // Cola ilimitada: no se bloquea al llegar al límite; el motor genera N a la
        // vez por dispositivo y el resto espera en cola. (Igual que la caja normal.)
        $quedan = null;
        if (!FlowCola::crear($conn, $prompt, $ajustes, $user_id, false, $quedan, $frDisp ?? '')) $frJson(['error' => 'No se pudo crear el pedido.'], 400);
        $nuevoId = (int) $conn->lastInsertId();
        // Las imágenes conectadas (inicio, fin, ingredientes, referencias) viajan a Google
        $entradas = FlowApi::limpiarEntradas($conn, $_POST['entradas'] ?? '');
        if ($entradas) {
            $conn->prepare("UPDATE tbl_flow_trabajos SET entradas = ? WHERE id = ?")->execute([json_encode($entradas), $nuevoId]);
        }
        FlowApi::despertar($conn); // arranca YA la generación en el próximo avance
        $frJson(['ok' => true, 'id' => $nuevoId], 200, true);
    }
    $frJson(['error' => 'Acción desconocida.'], 400);
}

// Volver a la página con un aviso. El aviso (y el proyecto que hay que abrir)
// viajan en la sesión, no en la dirección: el enlace queda corto (/flow).
$frIr = function ($ok = null, $err = null, array $extra = [], $ancla = '') use ($frBase) {
    $datos = array_filter(['ok' => $ok, 'err' => $err] + $extra, function ($v) { return $v !== null && $v !== ''; });
    // Envío por AJAX (desde /flow o el panel): respondemos JSON y NO recargamos la
    // página (así el envío se siente instantáneo, no pesado).
    if (($_POST['fr_ajax'] ?? '') === '1') {
        while (ob_get_level()) ob_end_clean();
        http_response_code($err ? 422 : 200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['flow_flash'] = $datos;
    header('Location: ' . $frBase . $ancla);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['flow_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $frIr(null, 'La página caducó. Vuelve a intentarlo.');
    }
    $accion = $_POST['accion'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    // Pegar el token que genera la extensión en el navegador (lo copias de ahí).
    if ($accion === 'fijar_token') {
        $err = FlowCola::fijarToken($conn, $user_id, (int) $user_role, (string) ($_POST['token'] ?? ''));
        if ($err) $frIr(null, $err, [], '#conexion-ext');
        $frIr('✅ Token de la extensión guardado. Ahora pulsa "Comprobar conexión" en la extensión.', null, [], '#conexion-ext');
    }

    if ($accion === 'crear') {
        $tipo = ($_POST['tipo'] ?? '') === 'imagen' ? 'imagen' : 'video';
        $modelos = FLOW_MODELOS[$tipo];
        $ajustes = [
            'tipo'     => $tipo,
            // Admin elige cualquiera; los clientes solo modelos GRATIS (si no, el gratis por defecto)
            'modelo'   => (array_key_exists($_POST['modelo'] ?? '', $modelos) && ($esAdmin || FlowApi::modeloGratis($_POST['modelo'] ?? ''))) ? $_POST['modelo'] : ($esAdmin ? array_key_first($modelos) : FlowApi::DEFECTO[$tipo]),
            'formato'  => in_array($_POST['formato'] ?? '', ['16:9', '9:16'], true) ? $_POST['formato'] : '16:9',
            'segundos' => 8, // Solo 8 s (lo que pidió el admin)
            'calidad'  => isset(FlowCola::CALIDADES[$_POST['calidad'] ?? '']) ? $_POST['calidad'] : '720p',
            'cantidad' => max(1, min(4, (int) ($_POST['cantidad'] ?? 1))),
            // '' automático, o uno de SUS proyectos (el abierto en "Tus resultados"):
            // de Flow, o creado con el "+" (id "nuevo-…", la extensión lo crea en Flow)
            'proyecto' => '',
            'proyecto_nombre' => '',
        ];
        $pidPedido = (string) ($_POST['proyecto'] ?? '');
        if ($pidPedido !== '') {
            foreach (FlowCola::proyectos($conn, $user_id, $frDisp) as $p) {
                if ($p['flow_id'] === $pidPedido) { $ajustes['proyecto'] = $pidPedido; $ajustes['proyecto_nombre'] = $p['nombre']; }
            }
        }
        // VIDEOS: puedes tener ILIMITADOS en cola. El motor genera como mucho N a la
        // vez por dispositivo (lo controla avanzar()); los demás esperan en cola y la
        // tarjeta muestra cuántos van antes. Si no hay cola, arranca directo. IMÁGENES
        // (Nano Banana): ilimitadas, sin cola. El admin tampoco tiene tope. Ya no se
        // bloquea crear al llegar al límite: se encola.
        $quedan = null;
        // ANTI-DOBLE-ENVÍO: si EXACTAMENTE el mismo pedido (mismos prompts + tipo +
        // modo + proyecto) se mandó hace menos de 15 s, es un doble-clic / doble-Enter
        // → no se vuelve a crear (así no se repite el mismo prompt varias veces).
        $firmaCrear = md5(($_POST['prompts'] ?? '') . '|' . ($ajustes['tipo'] ?? '') . '|' . ($_POST['modo_prompts'] ?? '') . '|' . ($ajustes['proyecto'] ?? ''));
        if (($_SESSION['flow_ultimo_crear']['f'] ?? '') === $firmaCrear && time() - (int) ($_SESSION['flow_ultimo_crear']['t'] ?? 0) < 15) {
            $frIr(null, 'Ese pedido ya se envió hace un momento; no lo duplicamos.', ['ver' => $ajustes['proyecto']]);
        }
        $_SESSION['flow_ultimo_crear'] = ['f' => $firmaCrear, 't' => time()];
        $n = FlowCola::crear($conn, $_POST['prompts'] ?? '', $ajustes, $user_id, ($_POST['modo_prompts'] ?? '') === 'varios', $quedan, $frDisp ?? '');
        $_SESSION['flow_ultimos_ajustes'] = $ajustes;
        if (!$n) {
            $frIr(null, 'Escribe al menos un prompt.', ['ver' => $ajustes['proyecto']]);
        }
        // Mensaje claro y los números de los trabajos nuevos, para resaltarlos en la galería
        $ultimo = (int) $conn->lastInsertId();
        // Imágenes adjuntas desde el "+" (inicio/fin/referencia): se guardan en
        // todos los pedidos recién creados para que viajen al generador.
        $entradasCrear = FlowApi::limpiarEntradas($conn, $_POST['entradas'] ?? '');
        if ($entradasCrear && $n > 0) {
            $conn->prepare("UPDATE tbl_flow_trabajos SET entradas = ? WHERE id BETWEEN ? AND ?")
                 ->execute([json_encode($entradasCrear), $ultimo - $n + 1, $ultimo]);
        }
        // CADA PEDIDO EN SU PROPIO PROYECTO (como Google Flow): en modo "automático"
        // (sin un proyecto elegido), a cada video/imagen recién creado se le hace su
        // propio proyecto interno, nombrado con su prompt. Si la persona eligió un
        // proyecto concreto, se respeta ese y no se crea nada aquí.
        if (($ajustes['proyecto'] ?? '') === '' && $n > 0) {
            $filas = $conn->query("SELECT id, prompt, tipo FROM tbl_flow_trabajos WHERE id BETWEEN " . ($ultimo - $n + 1) . " AND " . $ultimo)->fetchAll(PDO::FETCH_ASSOC);
            $uSet = $conn->prepare("UPDATE tbl_flow_trabajos SET proyecto = ?, proyecto_nombre = ? WHERE id = ?");
            foreach ($filas as $f) {
                $nom = trim(preg_replace('/\s+/u', ' ', mb_substr((string) $f['prompt'], 0, 60, 'UTF-8')));
                if ($nom === '') $nom = ($f['tipo'] === 'imagen' ? 'Imagen' : 'Video') . ' ' . date('d/m H:i');
                $pid = FlowCola::crearProyecto($conn, $user_id, $nom, $frDisp ?? '');
                if ($pid) $uSet->execute([$pid, mb_substr($nom, 0, 120, 'UTF-8'), (int) $f['id']]);
            }
        }
        FlowApi::despertar($conn); // que el próximo ?vivo=1 lance YA los pedidos nuevos
        $que = $tipo === 'imagen' ? ($n === 1 ? 'Tu imagen quedó' : "Tus $n imágenes quedaron") : ($n === 1 ? 'Tu video quedó' : "Tus $n videos quedaron");
        $msg = "$que en espera. Empieza a generarse en unos segundos y lo verás aparecer aquí, en tu proyecto.";
        // ENVÍO DIRECTO A FLOW: responde ya (redirige) y, justo después, lanza el
        // pedido a Núcleo SNAVE en segundo plano. Así salta a "Generándose" al
        // instante, sin esperar la siguiente vuelta del motor. En servidores con
        // FPM/LiteSpeed no frena el clic; en local (sin finish_request) se deja al
        // ?vivo, que ya lo toma enseguida por el despertar().
        $nuevos = ($ultimo - $n + 1) . '-' . $ultimo;
        $esAjaxCrear = ($_POST['fr_ajax'] ?? '') === '1';
        if ($esAjaxCrear) {
            // AJAX: respondemos JSON al INSTANTE (sin recargar la página) y lanzamos
            // el pedido a Flow en segundo plano. Así se siente ligero.
            while (ob_get_level()) ob_end_clean();
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => $msg, 'nuevos' => $nuevos], JSON_UNESCAPED_UNICODE);
        } else {
            $_SESSION['flow_flash'] = ['ok' => $msg, 'nuevos' => $nuevos];
            header('Location: ' . $frBase);
        }
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            else litespeed_finish_request();
            @set_time_limit(60);
            ignore_user_abort(true);
            try { FlowApi::avanzar($conn); } catch (Throwable $e) { error_log('crear-avanzar: ' . $e->getMessage()); }
        }
        exit;
    }
    // Reintentar y eliminar: el admin cualquiera; los demás, solo los suyos
    if (in_array($accion, ['reintentar', 'borrar'], true) && !$esAdmin && !FlowCola::esDe($conn, $id, $user_id, $frDisp)) {
        $frIr(null, 'Ese pedido no es tuyo.');
    }
    if ($accion === 'reintentar') {
        FlowCola::reintentar($conn, $id);
        $frIr('Vuelve a estar en espera.', null, ['ver' => $_POST['ver'] ?? null]);
    }
    if ($accion === 'borrar') {
        // Nada se elimina (ni videos ni imágenes, para nadie): se depura solo a los días de retención
        $frIr(null, 'El contenido no se puede eliminar; se borra solo al cumplir los días de retención.', ['ver' => $_POST['ver'] ?? null]);
    }
    if ($accion === 'proyecto_nuevo') {
        $pid = FlowCola::crearProyecto($conn, $user_id, $_POST['nombre'] ?? '', $frDisp ?? '');
        $pid ? $frIr('Proyecto creado. Lo que pidas ahora va a este proyecto.', null, ['ver' => $pid])
             : $frIr(null, 'Escribe un nombre para el proyecto.');
    }
    if ($accion === 'renombrar') {
        $pid = (string) ($_POST['proyecto'] ?? '');
        $ok = FlowCola::renombrarProyecto($conn, $user_id, $pid, $_POST['nombre'] ?? '', $frDisp);
        $ok ? $frIr('Nombre del proyecto cambiado.', null, ['ver' => $pid]) : $frIr(null, 'Escribe un nombre para el proyecto.', ['ver' => $pid]);
    }
    // Accesos de la página pública /flow (solo admin)
    if ($esAdmin && in_array($accion, ['acceso_crear', 'acceso_estado', 'acceso_clave', 'acceso_borrar', 'acceso_token'], true)) {
        $aid = (int) ($_POST['acceso_id'] ?? 0);
        // Pegar el token de la extensión para ESA cuenta (cada cuenta de Google su token)
        if ($accion === 'acceso_token') {
            $uidAcc = FlowCola::uidDeAcceso($aid);
            $err = $uidAcc ? FlowCola::fijarToken($conn, $uidAcc, FlowCola::ROL_ACCESO, $_POST['token'] ?? '') : 'Acceso no encontrado.';
            $err ? $frIr(null, $err, [], '#accesos') : $frIr('✅ Token de extensión guardado para esa cuenta.', null, [], '#accesos');
        }
        $err = null;
        if ($accion === 'acceso_crear') $err = FlowCola::crearAcceso($conn, $_POST['correo'] ?? '', $_POST['nombre'] ?? '', $_POST['clave'] ?? '');
        if ($accion === 'acceso_clave') $err = FlowCola::claveAcceso($conn, $aid, $_POST['clave'] ?? '');
        if ($accion === 'acceso_estado') FlowCola::estadoAcceso($conn, $aid, ($_POST['activo'] ?? '') === '1');
        if ($accion === 'acceso_borrar') FlowCola::borrarAcceso($conn, $aid);
        $ok = ['acceso_crear' => 'Acceso creado. Ya puede entrar en la página pública.', 'acceso_clave' => 'Contraseña cambiada.',
               'acceso_estado' => 'Acceso actualizado.', 'acceso_borrar' => 'Acceso borrado.'][$accion];
        $err ? $frIr(null, $err, [], '#accesos') : $frIr($ok, null, [], '#accesos');
    }
    // Almacenamiento en Cloudflare R2
    if ($esAdmin && $accion === 'r2_guardar') {
        FlowApi::guardarRetencion($conn, $_POST['retencion_dias'] ?? 5);
        if (!empty($_POST['r2_quitar'])) {
            FlowApi::r2Guardar($conn, '', '', '', '', '');
            $frIr('Almacenamiento R2 desconectado: los nuevos archivos se guardan en el servidor (local).', null, [], '#r2');
        }
        $secret = trim((string) ($_POST['r2_secret'] ?? '')) !== '' ? (string) $_POST['r2_secret'] : null;
        FlowApi::r2Guardar($conn, $_POST['r2_account'] ?? '', $_POST['r2_bucket'] ?? '', $_POST['r2_access'] ?? '', $secret, $_POST['r2_publicurl'] ?? '');
        $frIr('Almacenamiento R2 guardado. Los nuevos videos e imágenes se subirán a Cloudflare.', null, [], '#r2');
    }
    // Elegir qué servicio del catálogo es "Flow" (quien lo tenga activo entra a /flow)
    if ($esAdmin && $accion === 'flow_servicio') {
        FlowCola::guardarFlowServicioId($conn, (int) ($_POST['flow_servicio_id'] ?? 0));
        $frIr('Servicio de Flow guardado. Los clientes con ese servicio activo ya pueden entrar a /flow con su cuenta.', null, [], '#clientes-flow');
    }
    // Flow como servicio por cliente: habilitar/pausar/quitar Flow a cuentas del panel (override manual)
    if ($esAdmin && in_array($accion, ['flow_cliente_add', 'flow_cliente_estado', 'flow_cliente_quitar'], true)) {
        if ($accion === 'flow_cliente_add') {
            $cli = FlowCola::buscarClientePanel($conn, $_POST['correo'] ?? '');
            if (!$cli) $frIr(null, 'No hay un cliente o partner del panel con ese correo.', [], '#clientes-flow');
            FlowCola::activarClienteFlow($conn, (int) $cli['id'], true);
            $frIr('Flow habilitado para ' . $cli['correo'] . '. Ya puede entrar en /flow con su cuenta de SnaveCloud.', null, [], '#clientes-flow');
        }
        $uid = (int) ($_POST['usuario_id'] ?? 0);
        if ($accion === 'flow_cliente_estado') { FlowCola::activarClienteFlow($conn, $uid, ($_POST['activo'] ?? '') === '1'); $frIr('Cliente actualizado.', null, [], '#clientes-flow'); }
        if ($accion === 'flow_cliente_quitar') { FlowCola::quitarClienteFlow($conn, $uid); $frIr('Servicio Flow quitado al cliente. Sus pedidos se quedan.', null, [], '#clientes-flow'); }
    }
    if ($accion === 'config' && $esAdmin) {
        FlowCola::guardarConfig($conn, $_POST + ['clientes_activo' => 0]);
        $frIr('Reglas guardadas. Las extensiones las reciben en unos segundos.', null, [], '#conexion');
    }
    // Conexión con Núcleo SNAVE (solo admin): el token, el email, los modelos y la
    // pausa. El token no vuelve a salir nunca en la página: solo sus 4 últimas letras.
    if ($accion === 'api_guardar' && $esAdmin) {
        $nuevo = trim((string) ($_POST['api_token'] ?? ''));
        $token = !empty($_POST['api_quitar']) ? '' : ($nuevo !== '' ? $nuevo : null);
        $email = trim((string) ($_POST['api_email'] ?? ''));
        $rotar = !empty($_POST['api_rotar']);
        // guardar() comprueba el token/email con Núcleo SNAVE antes de guardarlos
        $err = FlowApi::guardar($conn, $token, $email, ['video' => $_POST['api_video'] ?? '', 'imagen' => $_POST['api_imagen'] ?? ''], !empty($_POST['api_pausa']), $rotar);
        if ($err) $frIr(null, $err, [], '#conexion');
        if ($token === '') $frIr('Conexión con Núcleo SNAVE quitada: ya no se genera nada.', null, [], '#conexion');
        $frIr($token !== null ? 'Conexión con Núcleo SNAVE guardada y comprobada. Desde ahora SnaveCloud genera los pedidos de todos.' : 'Ajustes de generación guardados.', null, [], '#conexion');
    }
    // Interruptor: desactivar/activar el servidor SIN borrar el token. Desactivado
    // = genera solo la extensión; activado = genera el servidor (bloquea extensión).
    if ($accion === 'api_toggle' && $esAdmin) {
        $des = !empty($_POST['api_desactivada']);
        FlowApi::setDesactivada($conn, $des);
        $frIr($des
            ? '🔌 Servidor DESACTIVADO: ahora genera solo la extensión (el token se conserva).'
            : '⚡ Servidor REACTIVADO: vuelve a generar SnaveCloud (la extensión queda bloqueada).',
            null, [], '#conexion');
    }
    if ($accion === 'api_probar' && $esAdmin) {
        if (FlowApi::rotar($conn)) {
            $err = FlowApi::probarToken($conn);
            if ($err) $frIr(null, 'Núcleo SNAVE dice: ' . $err, [], '#conexion');
            $cuentas = FlowApi::listarCuentas($conn);
            $frIr('La conexión funciona. Cuentas conectadas y sanas para la rotación (' . count($cuentas) . '): ' . implode(', ', $cuentas) . '.', null, [], '#conexion');
        }
        $err = FlowApi::probar($conn);
        $err ? $frIr(null, 'Núcleo SNAVE dice: ' . $err, [], '#conexion') : $frIr('La conexión funciona: Núcleo SNAVE responde bien y la cuenta está conectada.', null, [], '#conexion');
    }
    if ($accion === 'api_limpiar_error' && $esAdmin) {
        FlowApi::limpiarAviso($conn);
        $frIr('Aviso descartado.', null, [], '#conexion');
    }
    header('Location: ' . $frBase . '');
    exit;
}

// Por defecto, lo mismo que La Casa Dark: Veo 3.1 Lite [Lower Priority] (sin créditos), 16:9, 8 s, x1
$ult = array_merge(
    ['tipo' => 'video', 'modelo' => $frModelosApi['video'], 'formato' => '16:9', 'segundos' => 8, 'calidad' => '720p', 'cantidad' => 1],
    $_SESSION['flow_ultimos_ajustes'] ?? []
);

// El admin ve los pedidos de todos; los demás, solo los suyos
$ambito     = $esAdmin ? null : $user_id;
FlowCola::vencerAtrasados($conn);

// Datos en vivo para la página (la consulta cada pocos segundos, sin recargar):
// estado de SU extensión, contadores y una "firma" de cada pedido para saber
// cuáles cambiaron
// Estado del generador (la clave de Google del admin), con la forma que ya
// entendía la página: "conectada" = hay clave, "procesar" = no está en pausa.
// El último error de Google solo lo ve el admin.
$frGenerador = function () use ($conn, $esAdmin) {
    $a = FlowApi::estado($conn);
    return ['nunca' => !$a['configurada'], 'conectada' => $a['configurada'], 'activa' => !$a['pausa'], 'procesar' => !$a['pausa'],
            'mensaje' => $esAdmin ? $a['error'] : '', 'version' => '', 'hace' => 0, 'trabajo' => 0];
};
// Detalle del proceso de UN video (al hacer click en el historial): toda su
// línea de tiempo, desde que se pidió hasta que se entregó.
if (isset($_GET['proceso'])) {
    if (!$esAdmin) { $frJson(['error' => 'no autorizado'], 403, false); }
    $det = FlowCola::detalleProceso($conn, (int) $_GET['proceso'], $ambito, $frDisp);
    $frJson($det ?: ['error' => 'no encontrado'], $det ? 200 : 404, false);
}
// Proceso COMPLETO de TODOS los videos (visor "Examinar proceso")
if (isset($_GET['procesos'])) {
    if (!$esAdmin) { $frJson(['error' => 'no autorizado'], 403, false); }
    $frJson(['videos' => FlowCola::logProcesoTodos($conn, 150, $ambito, $frDisp)], 200, false);
}
if (isset($_GET['vivo'])) {
    $firmas = [];
    foreach (FlowCola::ultimos($conn, 100, $ambito, $frDisp) as $t) {
        $firmas[$t['id']] = $t['estado'] . '|' . (int) $t['intentos'] . '|' . count(FlowCola::archivosDe($t)) . '|' . $t['mensaje'] . '|' . $t['proyecto'];
    }
    // Después de responder, la generación con Google avanza un trozo
    $frJson([
        'ext' => $frGenerador(),
        'cuentas' => [
            'pendientes' => FlowCola::contar($conn, 'pendiente', $ambito, $frDisp),
            'procesando' => FlowCola::contar($conn, 'procesando', $ambito, $frDisp),
            'hechos'     => FlowCola::contar($conn, 'hecho', $ambito, $frDisp),
            'enLinea'    => null,
        ],
        'firmas' => $firmas,
        // Totales para el tablero del panel (solo admin): se actualizan en vivo sin
        // bajar toda la página.
        'totales' => $esAdmin ? FlowCola::totales($conn, $ambito, $frDisp) : null,
        // Historial del proceso de todos los videos (para el monitor del panel)
        'historial' => $esAdmin ? FlowCola::historialProceso($conn, 60, $ambito, $frDisp) : null,
        // Monitor en vivo (solo admin): líneas de "Progreso y resultado"
        'progreso' => $esAdmin ? FlowApi::progreso($conn) : null,
    ], 200, true);
}
// El aviso de la última acción (y el proyecto a abrir): llegan por la sesión
$frFlash = $_SESSION['flow_flash'] ?? [];
unset($_SESSION['flow_flash']);
$frNuevos = (string) ($frFlash['nuevos'] ?? '');
// Página pública: quién está dentro y su "Salir" (van en la barra de arriba)
$frUsuario = null;
if ($frPublico) {
    // flow.php ya calcula el nombre (cliente del panel o acceso legacy) y lo pasa aquí
    $nombre = trim((string) ($frPublico['nombre'] ?? ''));
    $frUsuario = ['nombre' => $nombre !== '' ? $nombre : 'Tú',
                  'salir'  => 'flow?salir=' . ($_SESSION['flow_login_csrf'] ?? '')];
}

$config     = FlowCola::config($conn);
$ext        = $frGenerador(); // la clave de Google del admin (ya no hay extensión)
$trabajos   = FlowCola::ultimos($conn, 100, $ambito, $frDisp);
$pendientes = FlowCola::contar($conn, 'pendiente', $ambito, $frDisp);
$enProceso  = FlowCola::contar($conn, 'procesando', $ambito, $frDisp);
$hechos     = FlowCola::contar($conn, 'hecho', $ambito, $frDisp);
// Totales para el TABLERO del panel (solo admin, vista "números + proceso")
$frTotales  = $esAdmin ? FlowCola::totales($conn, $ambito, $frDisp) : null;
// Historial del proceso de TODOS los videos, para el monitor "Progreso y resultado"
$frHistorial = $esAdmin ? FlowCola::historialProceso($conn, 60, $ambito, $frDisp) : [];
$proyectos  = FlowCola::proyectos($conn, $user_id, $frDisp);
$quedanHoy  = $esAdmin ? null : max(0, (int) $config['limite_diario'] - FlowCola::pedidosHoy($conn, $user_id, $frDisp));
$enLinea    = null;
$apiEstado  = FlowApi::estado($conn);
// Cuentas conectadas (en vivo) para mostrarlas en la tarjeta de conexión (solo admin)
$apiCuentas = ($esAdmin && $apiEstado['tiene_token']) ? FlowApi::cuentasDetalle($conn) : [];
$r2Estado = $esAdmin ? FlowApi::r2Estado($conn) : null;
$retencionDias = FlowApi::retencionDias($conn);
$r2Uso = ($esAdmin && $r2Estado && $r2Estado['activo']) ? FlowApi::r2UsoCacheado($conn) : null;
$fmtBytes = function ($b) {
    $b = (int) $b;
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
};
// Tarjetas de proyectos: cada proyecto de Flow con su portada y cuántos videos
// e imágenes tiene aquí. Los pedidos sin proyecto van en "Sin proyecto".
$tarjetasProyecto = [];
foreach ($proyectos as $p) {
    $tarjetasProyecto[$p['flow_id']] = ['id' => $p['flow_id'], 'nombre' => $p['nombre'] !== '' ? $p['nombre'] : 'Proyecto ' . substr($p['flow_id'], 0, 8),
        'videos' => 0, 'imagenes' => 0, 'portada' => null, 'ultimo' => $p['actualizado']];
}
foreach ($trabajos as $t) {
    $pid = in_array($t['proyecto'], ['', 'nuevo'], true) ? '' : $t['proyecto'];
    if (!isset($tarjetasProyecto[$pid])) {
        $tarjetasProyecto[$pid] = ['id' => $pid, 'nombre' => $pid === '' ? 'Sin proyecto' : ($t['proyecto_nombre'] ?: 'Proyecto ' . substr($pid, 0, 8)),
            'videos' => 0, 'imagenes' => 0, 'portada' => null, 'ultimo' => $t['creado_en']];
    }
    $tarjetasProyecto[$pid][$t['tipo'] === 'imagen' ? 'imagenes' : 'videos']++;
    if ($t['creado_en'] > $tarjetasProyecto[$pid]['ultimo']) $tarjetasProyecto[$pid]['ultimo'] = $t['creado_en'];
    if (!$tarjetasProyecto[$pid]['portada'] && $t['estado'] === 'hecho') {
        foreach (FlowCola::archivosDe($t) as $a) {
            // La portada puede estar en local ('ruta') o en una URL (R2 o temporal de Google)
            if (!empty($a['ruta']) || !empty($a['url'])) { $tarjetasProyecto[$pid]['portada'] = $a; break; }
        }
    }
}
// El proyecto con el pedido más reciente primero (es el que se abre al entrar); "Sin proyecto" al final
uasort($tarjetasProyecto, function ($a, $b) {
    if (($a['id'] === '') !== ($b['id'] === '')) return $a['id'] === '' ? 1 : -1;
    return strcmp($b['ultimo'], $a['ultimo']);
});
$tarjetasProyecto = array_filter($tarjetasProyecto, function ($p) { return $p['id'] !== '' || $p['videos'] + $p['imagenes'] > 0; });

// Proyecto que se abre al llegar de "Crear": el del pedido nuevo. Si iba en
// automático o a un proyecto nuevo, el más reciente (el que usará la extensión).
$proyectoInicial = null;
if ($frNuevos !== '') {
    [, $hasta] = array_map('intval', array_pad(explode('-', $frNuevos), 2, 0));
    foreach ($trabajos as $t) {
        if ((int) $t['id'] === $hasta) $proyectoInicial = in_array($t['proyecto'], ['', 'nuevo'], true) ? null : $t['proyecto'];
    }
    if ($proyectoInicial === null) {
        foreach ($tarjetasProyecto as $p) { if ($p['id'] !== '') { $proyectoInicial = $p['id']; break; } }
    }
}

$nVideos    = count(array_filter($trabajos, function ($t) { return $t['tipo'] === 'video'; }));
$nImagenes  = count($trabajos) - $nVideos;

// La URL que hay que pegar en la extensión: la carpeta de esta página. Sirve
// también para dar la dirección completa de cada archivo ("Copiar URL").
$esquema  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$urlPanel = $esquema . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(strtok($_SERVER['REQUEST_URI'], '?')), '/\\') . '/';
// Desde la página pública (en la raíz) la API y los archivos están en dashboard/
if ($frPublico) $urlPanel .= 'dashboard/';
// Para el admin: la dirección de la página pública y los accesos creados
$urlPublica = preg_replace('#dashboard/$#', '', $urlPanel) . 'flow';
$accesos = $esAdmin ? FlowCola::accesos($conn) : [];
$clientesFlow = $esAdmin ? FlowCola::clientesFlow($conn) : [];
$flowServicioId = $esAdmin ? FlowCola::flowServicioId($conn) : 0;
$catalogoServicios = [];
if ($esAdmin) {
    try { $catalogoServicios = $conn->query("SELECT id, nombre FROM tbl_servicios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}
$correoDeAcceso = [];
foreach ($accesos as $a) $correoDeAcceso[FlowCola::uidDeAcceso($a['id'])] = $a['correo'];

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$csrf = $h($_SESSION['flow_csrf']);
$etiquetaModelo = function ($t) {
    foreach (FLOW_MODELOS as $lista) if (isset($lista[$t['modelo']])) return $lista[$t['modelo']]['etiqueta'];
    return $t['modelo'] !== '' ? $t['modelo'] : 'modelo de Flow';
};
// Lista "Mis videos" de la barra lateral (filas con puntito de estado + nombre).
// Se usa en la carga y el refresco en vivo la vuelve a pintar desde la página.
$frPintarMisVideos = function (array $trabajos) use ($h) {
    $clasePt = ['hecho' => 'ok', 'procesando' => 'proc', 'pendiente' => 'pend', 'error' => 'err', 'rechazado' => 'err'];
    $out = '';
    foreach ($trabajos as $t) {
        $cls = $clasePt[$t['estado']] ?? 'pend';
        // El nombre de cada fila es el PROMPT del propio video (no el del proyecto),
        // para que cada uno se distinga aunque compartan proyecto.
        $nom = trim(preg_replace('/\s+/u', ' ', (string) $t['prompt']));
        if ($nom === '') $nom = trim((string) $t['proyecto_nombre']);
        if ($nom === '') $nom = ($t['tipo'] === 'imagen' ? 'Imagen' : 'Video') . ' #' . (int) $t['id'];
        $corto = mb_strlen($nom, 'UTF-8') > 28 ? mb_substr($nom, 0, 28, 'UTF-8') . '…' : $nom;
        $out .= '<button type="button" class="fl-vid" data-ir="' . (int) $t['id'] . '" data-proy="' . $h($t['proyecto']) . '" title="' . $h($nom) . '">'
              . '<span class="pt ' . $cls . '"></span>'
              . '<span class="nm">' . $h($corto) . '</span>'
              . '<span class="tp">' . ($t['tipo'] === 'imagen' ? '🖼️' : '🎬') . '</span>'
              . '</button>';
    }
    return $out;
};
const FLOW_ESTADOS = [
    'pendiente'  => ['En espera', 'cola'],
    'procesando' => ['Generándose', 'generando'],
    'hecho'      => ['Listo', 'hecho'],
    'error'      => ['Falló', 'error'],
    'rechazado'  => ['Google no lo generó', 'rechazo'],
];
// Trabajos recién creados (vienen en ?nuevos=desde-hasta) para resaltarlos
[$nuevoDesde, $nuevoHasta] = array_map('intval', array_pad(explode('-', $frNuevos), 2, 0));
$conectada = $ext['conectada'] && $ext['procesar']; // hay clave y no está en pausa
$procesando = $ext['procesar'];

if ($frPublico) {
    ($frPublico['cabecera'])(); // cabecera propia de la página pública (flow.php)
} else {
    include __DIR__ . '/app/header.php';
}
// app/header.php pisa $user_id con el valor de la sesión en texto ("1"): se
// vuelve a poner como número, o los pedidos propios parecen de otro usuario
$user_id = $frUid;
?>
<?php if (!$frPublico) { include __DIR__ . '/app/sidebar.php'; include __DIR__ . '/app/topbar.php'; } ?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300..500,0..1,0&display=block">
<style>
    /* IGUAL QUE GOOGLE FLOW (05/10/2026), con los toques de SNAVE CLOUD.
       Portada: banner, proyectos en 3 columnas y "+ Nuevo proyecto" flotando.
       Dentro de un proyecto: barra lateral, buscador arriba, la galería y la
       caja "¿Qué quieres crear?" abajo. Todo va dentro de .fr para no tocar el
       resto del panel; los ids y el comportamiento son los de siempre. */
    .fr { --morado:#b98aff; --morado-vivo:#6d00eb; --morado-suave:rgba(155,93,229,.18); --morado-borde:rgba(185,138,255,.45);
          --verde:#00ff88; --verde-osc:#00d471; --verde-texto:#04261a; --amarillo:#facc15;
          --texto:#e3e3e3; --suave:#a99dbb; --borde:#2f2540; --fondo:#07050c; --panel:#16121e; --panel2:#241d30; --hover:#2f2540;
          --rojo:#f28b82; --azul:#8ab4f8;
          color:var(--texto); font-family:'Google Sans', 'Google Sans Text', 'Plus Jakarta Sans', system-ui, sans-serif;
          background:var(--fondo); border-radius:0; min-height:calc(100vh - 70px); }
    /* A toda la página, como Flow: sin márgenes alrededor */
    .container-fluid.fr-pagina { max-width:none !important; padding:0 !important; margin:0 !important; }
    .fr *, .fr *::before, .fr *::after { box-sizing:border-box; }
    .fr a { color:var(--morado); }
    .fr .ms { font-family:'Material Symbols Outlined'; font-weight:normal; font-style:normal; font-size:22px; line-height:1; letter-spacing:normal;
              text-transform:none; display:inline-block; white-space:nowrap; direction:ltr; -webkit-font-smoothing:antialiased; vertical-align:middle; }
    .fr .tarjeta { background:var(--panel); border:1px solid var(--borde); border-radius:18px; }
    .fr .mini { font-size:.78rem; color:var(--suave); }
    .fr.en-lista .solo-proyecto, .fr.en-proyecto .solo-lista { display:none !important; }
    /* Página pública: todo negro, como Flow */
    body:has(.fr.studio.publico) { background:#07050c; }
    .fr.publico { min-height:100vh; }
    /* La barra morada de la página pública sobra: tu nombre y "Salir" van arriba a la derecha */
    body:has(.fr.studio.publico) .fp-barra { display:none; }
    .fr .fl-yo { display:inline-flex; align-items:center; gap:8px; height:42px; padding:0 6px 0 14px; background:#110d18; border-radius:999px; font-size:.82rem; color:#e3e3e3; }
    .fr .fl-yo .av { width:30px; height:30px; border-radius:50%; display:grid; place-items:center; font-weight:700; color:#fff;
                     background:linear-gradient(135deg, #6d00eb, #00c96b); }
    .fr .fl-yo a { color:#e3e3e3; text-decoration:none; background:#241d30; border-radius:999px; padding:6px 12px; font-weight:500; }
    .fr .fl-yo a:hover { background:#352a46; color:#fff; }
    .fp-barra { background:#07050c !important; box-shadow:none !important; border-bottom:1px solid #1b1624; }
    .fp-usuario a { background:#16121e !important; }

    /* ---------- Barra de arriba ---------- */
    .fr .fl-top { display:flex; align-items:center; gap:12px; padding:14px 22px; min-height:68px; }
    .fr .fl-izq { display:flex; align-items:center; gap:6px; width:250px; min-width:0; flex:none; }
    .fr.en-lista .fl-izq { width:auto; }
    .fr .fl-logo { background:none; border:0; color:#fff; font:inherit; font-size:1.15rem; font-weight:500; padding:4px 0; cursor:pointer; display:flex; align-items:center; gap:8px; }
    .fr .fl-logo .de { font-size:.6rem; font-weight:700; letter-spacing:.08em; color:var(--verde); border:1px solid rgba(0,255,136,.35); border-radius:999px; padding:2px 7px; }
    .fr .fl-nombre { font-size:1.05rem; font-weight:400; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:0 2px 0 6px; min-width:0; }
    .fr .fl-icono { width:40px; height:40px; flex:none; border-radius:50%; border:0; background:none; color:var(--texto); display:grid; place-items:center;
                    cursor:pointer; text-decoration:none; }
    .fr .fl-icono:hover { background:var(--panel2); color:#fff; }
    .fr .fl-buscar { flex:1; display:flex; align-items:center; gap:8px; max-width:480px; margin:0 auto; }
    .fr .fl-buscar label { flex:1; display:flex; align-items:center; gap:8px; background:#1b1624; border:1px solid transparent; border-radius:14px; padding:0 14px; height:42px; margin:0; }
    .fr .fl-buscar label:focus-within { border-color:#3d3050; }
    .fr .fl-buscar .ms { color:var(--suave); font-size:20px; }
    .fr .fl-buscar input { flex:1; background:none !important; border:0 !important; outline:none; color:var(--texto); font:inherit; padding:0 !important; min-width:0; }
    .fr .fl-buscar .fl-icono { background:#1b1624; border-radius:12px; }
    .fr .fl-der { margin-left:auto; display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
    .fr .fl-pill { display:inline-flex; align-items:center; gap:8px; height:42px; padding:0 16px; border:1px solid #3d3050; border-radius:999px; background:none;
                   color:#fff; font:inherit; font-size:.88rem; font-weight:500; text-decoration:none; white-space:nowrap; }
    .fr a.fl-pill:hover { background:var(--panel2); }
    .fr .fl-pill .n { display:inline-flex; align-items:center; gap:3px; color:var(--suave); }
    .fr .fl-pill .n b { color:#fff; font-weight:500; }
    .fr .fl-pill .ms { font-size:18px; }
    .fr .fl-estado .ico { width:9px; height:9px; border-radius:50%; background:#6b5c7d; flex:none; }
    .fr .fl-estado .ico.on { background:var(--verde); box-shadow:0 0 8px var(--verde); }
    .fr .fl-estado .ico.medio { background:var(--amarillo); }
    .fr .fl-estado .ico.off { background:var(--rojo); }
    .fr .fl-marca { display:inline-flex; align-items:center; height:42px; padding:0 6px 0 12px; gap:8px; background:#110d18; border-radius:999px; font-size:.75rem; font-weight:700; }
    .fr .fl-marca i { width:30px; height:30px; border-radius:50%; background:conic-gradient(from 200deg, #6d00eb, #00ff88, #b98aff, #6d00eb); padding:3px; display:block; }
    .fr .fl-marca i::after { content:''; display:block; width:100%; height:100%; border-radius:50%; background:#110d18 url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="%23b98aff" d="M12 2l2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z"/></svg>') center/14px no-repeat; }

    /* ---------- Cuerpo: barra lateral (dentro de un proyecto) + centro ---------- */
    .fr .fl-cuerpo { display:grid; grid-template-columns:250px minmax(0, 1fr); gap:0 12px; padding:0 22px 18px; }
    .fr.en-lista .fl-cuerpo, .fr.side-oculta .fl-cuerpo { grid-template-columns:minmax(0, 1fr); }
    .fr.side-oculta .fl-side { display:none !important; }
    .fr .fl-mostrar { display:none; }
    .fr.side-oculta .fl-mostrar { display:grid; }
    .fr.side-oculta .fl-izq { width:auto; }
    .fr .fl-side { position:sticky; top:0; align-self:start; display:flex; flex-direction:column; gap:4px; height:calc(100vh - 90px); min-height:420px; padding:4px 0 8px; }
    .fr .fl-nav button, .fr .fl-side .it { display:flex; align-items:center; gap:14px; width:100%; background:none; border:0; color:#fff; text-align:left;
                   padding:12px 14px; border-radius:14px; font:inherit; font-size:.93rem; font-weight:500; cursor:pointer; text-decoration:none; }
    .fr .fl-nav button:hover, .fr .fl-side .it:hover { background:#1a1522; }
    .fr .fl-nav button.on { background:#2f2540; }
    .fr .fl-nav .t { flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fr .fl-nav .c { font-size:.72rem; color:var(--suave); }
    .fr .fl-sep { height:1px; background:#2f2540; margin:8px 2px; }
    .fr .fl-abajo { margin-top:auto; display:flex; flex-direction:column; gap:4px; }

    .fr .st-main { min-width:0; display:flex; flex-direction:column; gap:16px; min-height:calc(100vh - 140px); }

    /* ---------- Banner de la portada (como "Tools from creators like you") ---------- */
    .fr .fl-banner { position:relative; overflow:hidden; border:1px solid #241d30; border-radius:20px; background:#07050c; min-height:420px; display:flex; align-items:center; }
    .fr .fl-banner .txt { position:relative; z-index:2; padding:48px 44px; max-width:660px; }
    .fr .fl-banner h1 { font-size:clamp(2rem, 3.6vw, 3.3rem); font-weight:400; line-height:1.12; letter-spacing:-1px; margin:0 0 16px; color:#fff; }
    .fr .fl-banner h1 em { font-style:normal; background:linear-gradient(90deg, #b98aff, #00ff88); -webkit-background-clip:text; background-clip:text; color:transparent; }
    .fr .fl-banner p { font-size:1.02rem; line-height:1.55; color:#e3e3e3; margin:0 0 30px; }
    .fr .fl-banner .btns { display:flex; gap:10px; flex-wrap:wrap; }
    .fr .fl-blanco { display:inline-flex; align-items:center; gap:6px; background:#fff; color:#07050c; border:0; border-radius:999px; padding:12px 24px; font:inherit; font-weight:500; cursor:pointer; text-decoration:none; }
    .fr .fl-blanco:hover { background:#e8e8e8; color:#07050c; }
    .fr .fl-banner .fl-pill { height:46px; padding:0 22px; }
    .fr .fl-banner .cerrar { position:absolute; top:12px; right:12px; z-index:3; }
    .fr .fl-arte { position:absolute; inset:-40px -30px -40px 38%; display:grid; grid-template-columns:repeat(6, 110px); grid-auto-rows:110px; gap:22px;
                   transform:rotate(-8deg); -webkit-mask-image:linear-gradient(90deg, transparent 0%, #07050c 30%); mask-image:linear-gradient(90deg, transparent 0%, #07050c 30%); }
    .fr .fl-arte span { border-radius:28px; display:grid; place-items:center; color:rgba(255,255,255,.92); background:var(--c);
                        box-shadow:inset 0 -8px 18px rgba(0,0,0,.25), inset 0 6px 14px rgba(255,255,255,.35), 0 18px 30px -12px rgba(0,0,0,.7); }
    .fr .fl-arte span .ms { font-size:52px; font-variation-settings:'FILL' 1; filter:drop-shadow(0 4px 6px rgba(0,0,0,.3)); }
    .fr .fl-arte span:nth-child(odd) { transform:translateY(36px); }

    /* Primeros pasos: solo mientras la extensión no está conectada */
    .fr .pasos { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }
    .fr .paso { background:var(--panel); border:1px solid var(--borde); border-radius:18px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start; }
    .fr .paso .num { flex:none; width:28px; height:28px; border-radius:50%; background:var(--panel2); display:grid; place-items:center; font-weight:700; }
    .fr .paso.hecho .num { background:var(--verde); color:var(--verde-texto); }
    .fr .paso b { display:block; font-size:.9rem; font-weight:500; }
    .fr .paso span { font-size:.78rem; color:var(--suave); }

    .fr .aviso { border-radius:16px; padding:12px 16px; font-size:.9rem; }
    .fr .aviso.ok { background:rgba(0,255,136,.08); border:1px solid rgba(0,255,136,.3); color:#b6f5d6; }
    .fr .aviso.err { background:rgba(242,139,130,.1); border:1px solid rgba(242,139,130,.35); color:#fecaca; }
    .fr .aviso.alerta { background:rgba(250,204,21,.08); border:1px solid rgba(250,204,21,.3); color:#fde68a; }
    .fr .aviso-ext { display:none; }
    .fr .aviso-ext.ver { display:block; }

    /* ---------- Galería ---------- */
    .fr #galeria { background:transparent; border:0; flex:1; display:flex; flex-direction:column; }
    .fr .cab { padding:4px 2px 14px; display:flex; justify-content:space-between; align-items:flex-end; gap:10px; flex-wrap:wrap; }
    .fr .cab h2 { font-size:1.05rem; font-weight:500; margin:0 0 2px; }
    .fr .cab p { margin:0; color:var(--suave); font-size:.85rem; }
    .fr .cuerpo { padding:4px 18px 18px; }
    .fr .etq { display:inline-block; white-space:nowrap; font-size:.7rem; font-weight:500; padding:4px 10px; border-radius:999px; }
    .fr .etq.gratis { background:rgba(0,255,136,.1); color:#9ff5cb; }
    .fr .etq.pago { background:rgba(0,0,0,.6); color:#e3e3e3; }
    .fr .etq.morado { background:rgba(0,0,0,.62); color:#fff; backdrop-filter:blur(6px); }
    /* Badge con la cuenta del Núcleo que generó el video (solo admin) */
    .fr .etq.cuenta-gen { background:rgba(109,0,235,.82); color:#fff; backdrop-filter:blur(6px); font-weight:600; }
    .fr .btn-verde { background:linear-gradient(90deg, var(--verde) 0%, var(--verde-osc) 100%); color:var(--verde-texto); border:0; border-radius:999px; padding:12px; font-weight:700; }
    .fr .btn-borde { display:inline-flex; align-items:center; gap:6px; background:transparent; color:var(--texto); border:1px solid #3d3050; border-radius:999px;
                     padding:8px 15px; font-weight:500; font-size:.86rem; cursor:pointer; }
    .fr .btn-borde:hover { background:var(--panel2); color:#fff; }
    .fr .btn-morado { background:var(--morado-vivo); color:#fff; border:0; border-radius:999px; padding:8px 16px; font-weight:500; font-size:.85rem; cursor:pointer; }

    /* Proyectos de la portada: 3 columnas, portada grande y el nombre debajo */
    .fr .proyectos { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:26px 18px; padding:8px 0 4px; }
    .fr .proyecto { position:relative; display:flex; flex-direction:column; gap:10px; cursor:pointer; }
    .fr .proyecto .portada { display:block; width:100%; aspect-ratio:16/9; border-radius:16px; overflow:hidden; background:#1b1624; transition:filter .15s; }
    .fr .proyecto:hover .portada { filter:brightness(1.12); }
    .fr .proyecto.on .portada { outline:2px solid var(--morado); }
    .fr .proyecto .portada img, .fr .proyecto .portada video { width:100%; height:100%; object-fit:cover; display:block; }
    /* Video aún sin cargar (perezoso): recuadro suave en vez de negro */
    .fr video.fr-lazy { background:linear-gradient(135deg,#2a2140,#1b1530); }
    .fr #frVerMas { background:#2a2140; color:#dcc8ff; border:1px solid #42356b; border-radius:999px; padding:9px 22px; font-weight:700; cursor:pointer; font-size:.9rem; }
    .fr #frVerMas:hover { background:#352a52; }
    /* Panel: miniatura liviana en vez de reproductor de video (página rápida) */
    .fr .fr-verarchivo { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; width:100%; aspect-ratio:16/9;
        background:linear-gradient(135deg,#2a2140,#1b1530); color:#dcc8ff; text-decoration:none; font-weight:700; font-size:.85rem; border-radius:12px; }
    .fr .fr-verarchivo .ms { font-size:2.4rem; }
    .fr .fr-verarchivo:hover { background:linear-gradient(135deg,#352a52,#241c3e); }
    .fr .fr-minivid { display:flex; align-items:center; justify-content:center; width:100%; height:100%;
        background:linear-gradient(135deg,#2a2140,#1b1530); color:#9f8ad0; }
    /* ===== PANEL "solo números": oculta la creación visual, deja tablero + avisos ===== */
    .fr.panel-numeros .st-main > :not(.fr-tablero):not(.aviso) { display:none !important; }
    /* Sin galería, el main NO debe reservar una pantalla completa (quitaba ese hueco) */
    .fr.panel-numeros .st-main { min-height:0 !important; gap:10px; }
    .fr.panel-numeros .fl-cuerpo { grid-template-columns:minmax(0, 1fr) !important; }
    .fr.panel-numeros .fl-side { display:none !important; }
    .fr.panel-numeros .fr-tablero { margin-top:2px; }
    .fr.panel-numeros .fl-buscar, .fr.panel-numeros .fl-flotante,
    .fr.panel-numeros .fl-pill.solo-proyecto, .fr.panel-numeros #frVistaLista,
    .fr.panel-numeros #frNavNuevo, .fr.panel-numeros [data-abre-space] { display:none !important; }
    .fr-tablero { margin:4px 0 10px; }
    .fr-tablero .fr-tab-cab { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin:0 0 14px; }
    .fr-tablero h2 { margin:0; font-size:1.35rem; font-weight:800; letter-spacing:-.4px; display:flex; align-items:center; gap:8px; }
    .fr-tab-ir { display:inline-flex; align-items:center; gap:7px; background:linear-gradient(135deg,#6d00eb,#8b3dff); color:#fff; text-decoration:none;
        font-weight:700; font-size:.85rem; padding:10px 18px; border-radius:999px; box-shadow:0 10px 24px -10px rgba(109,0,235,.75); transition:transform .15s, filter .15s; }
    .fr-tab-ir:hover { filter:brightness(1.08); transform:translateY(-1px); }
    .fr-tab-ir .ms { font-size:18px; }
    /* ===== Tarjetas de estadísticas (tablero del panel) — estilo moderno ===== */
    .fr-tab-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; }
    .fr-card { --c:#6d00eb; --cbg:rgba(109,0,235,.1);
        position:relative; background:#fff; border:1px solid #eceef4; border-radius:18px; padding:18px 18px 18px 20px;
        display:flex; align-items:center; gap:14px; overflow:hidden;
        box-shadow:0 1px 2px rgba(16,24,40,.05); transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
    .fr-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c); }
    .fr-card:hover { transform:translateY(-3px); box-shadow:0 14px 30px -14px rgba(16,24,40,.3); border-color:var(--c); }
    .fr-card .ico { flex:0 0 auto; width:48px; height:48px; border-radius:14px; display:grid; place-items:center; background:var(--cbg); color:var(--c); }
    .fr-card .ico .ms { font-size:24px; }
    .fr-card .dat { display:flex; flex-direction:column; gap:2px; min-width:0; }
    .fr-card .n { font-size:1.95rem; font-weight:800; line-height:1.05; color:#111827; letter-spacing:-.6px; }
    .fr-card .t { font-size:.8rem; color:#6b7280; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fr-card.ok  { --c:#10b981; --cbg:rgba(16,185,129,.12); }
    .fr-card.img { --c:#3b82f6; --cbg:rgba(59,130,246,.12); }
    .fr-card.cola{ --c:#f59e0b; --cbg:rgba(245,158,11,.15); }
    .fr-card.gen { --c:#8b5cf6; --cbg:rgba(139,92,246,.15); }
    .fr-card.err { --c:#ef4444; --cbg:rgba(239,68,68,.12); }
    .fr-card.hoy { --c:#06b6d4; --cbg:rgba(6,182,212,.12); }
    .fr-card.tot { --c:#64748b; --cbg:rgba(100,116,139,.15); }
    .fr-tab-nota { margin:14px 0 0; font-size:.85rem; line-height:1.55; color:#5b6577; background:#f4f6fb; border:1px solid #e8ebf3; border-radius:14px; padding:12px 16px; }
    .fr-tab-nota a { color:#6d00eb; font-weight:700; }
    .fr.studio:not(.publico) .fr-tablero h2 { color:#111827; }
    .fr.panel-numeros .fr-tablero { margin-top:2px; margin-bottom:8px; }
    .fr .proyecto .vacia { display:grid; place-items:center; height:100%; color:#4a3b5e; }
    .fr .proyecto .vacia .ms { font-size:40px; }
    .fr .proyecto .pie { display:flex; align-items:center; gap:8px; padding:0 16px; min-height:24px; }
    .fr .proyecto b { font-size:.95rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fr .proyecto .mini { margin-left:auto; white-space:nowrap; display:flex; align-items:center; gap:3px; }
    .fr .proyecto .mini .ms { font-size:16px; }
    .fr .proyecto .lapiz { background:none; border:0; color:#fff; padding:2px; cursor:pointer; opacity:0; transition:.15s; }
    .fr .proyecto:hover .lapiz, .fr .proyecto .lapiz:focus { opacity:1; }
    .fr .proyecto .lapiz .ms { font-size:18px; }
    /* "+ Nuevo proyecto", flotando abajo en el centro */
    .fr .fl-flotante { position:sticky; bottom:22px; z-index:5; align-self:center; display:inline-flex; align-items:center; gap:12px; margin-top:10px;
                       background:rgba(92,92,92,.72); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); color:#ececec; border:0;
                       border-radius:26px; padding:30px 34px; font:inherit; font-size:1rem; cursor:pointer; box-shadow:0 20px 40px -14px rgba(0,0,0,.9); }
    .fr .fl-flotante:hover { background:rgba(110,110,110,.8); color:#fff; }

    /* Resultados dentro del proyecto: como las piezas de Flow */
    .fr .rejilla { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px 12px; padding:2px 0 8px; align-items:start; }
    .fr .job { position:relative; display:flex; flex-direction:column; gap:8px; }
    .fr .job > video, .fr .job > a, .fr .job > .hueco { order:-2; }
    .fr .job > .extra { order:-1; }
    .fr .job > .arriba { position:absolute; top:10px; left:10px; right:10px; z-index:2; pointer-events:none; }
    .fr .job > .prompt, .fr .job > .meta, .fr .job > .acciones { padding:0 4px; }
    .fr .job.nuevo video, .fr .job.nuevo img, .fr .job.nuevo .hueco { outline:2px solid var(--verde-osc); }
    .fr .job .arriba { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .fr .job .arriba .id { color:#fff; font-size:.72rem; flex:1; text-shadow:0 1px 4px #07050c; }
    .fr .job .prompt { font-size:.86rem; line-height:1.45; color:#cdc2de; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .fr .job:hover .prompt { color:#e3e3e3; } /* el prompt se queda cortado a 2 líneas, no se expande */
    .fr .job video, .fr .job img, .fr .job .hueco { width:100%; aspect-ratio:16/9; border-radius:14px; background:#0d0a13; object-fit:cover; display:block; }
    .fr .job.vertical video, .fr .job.vertical img, .fr .job.vertical .hueco { aspect-ratio:9/16; max-height:440px; object-fit:contain; background:#09070e; }
    .fr .job .hueco { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:var(--suave); font-size:.84rem;
                      text-align:center; padding:40px 16px 14px; background:#16121e; }
    .fr .job .hueco.error { color:#fecaca; background:#241514; }
    .fr .job .hueco .emoji { font-size:1.6rem; }
    .fr .job .extra { display:flex; gap:6px; overflow-x:auto; }
    .fr .job .extra video, .fr .job .extra img { width:70px; flex:none; border-radius:8px; }
    .fr .job .meta { color:var(--suave); font-size:.74rem; line-height:1.55; }
    .fr .job .acciones { display:flex; gap:6px; flex-wrap:wrap; }
    .fr .job .acciones button, .fr .job .acciones a { background:#1b1624; color:var(--texto); border:0; border-radius:999px; padding:7px 13px;
                     font:inherit; font-size:.8rem; font-weight:500; text-decoration:none; cursor:pointer; }
    .fr .job .acciones button:hover, .fr .job .acciones a:hover { background:#2f2540; color:#fff; }
    .fr .job .acciones .rojo { color:var(--rojo); }
    /* La info y los botones de cada video van SIEMPRE visibles (sin hover) */
    .fr .job .meta { display:block; }
    .fr .job .acciones { display:flex; }
    .fr .estado { font-size:.7rem; font-weight:500; padding:4px 10px; border-radius:999px; white-space:nowrap; background:rgba(0,0,0,.62); backdrop-filter:blur(6px); }
    .fr .estado.cola { color:#e3e3e3; }
    .fr .estado.generando { color:#dcc8ff; }
    .fr .estado.hecho { color:#9ff5cb; }
    .fr .estado.error { color:#fecaca; }
    .fr .estado.rechazo { background:#8c1d18; color:#fff; }
    .fr .job .hueco.rechazo { color:#fecaca; background:#241514; }
    .fr .job .hueco.rechazo b { font-size:.95rem; }
    .fr .job .plazo { font-weight:500; color:#dcc8ff; }
    .fr .job .plazo.tarde { color:var(--rojo); }
    .fr .job .pct { font-weight:800; font-size:1.5rem; color:var(--morado); letter-spacing:-.5px; margin-top:4px; line-height:1; }

    /* ======= VISTA LISTA (filas compactas tipo NeuroFlix) ======= */
    .fr .rejilla.lista { display:flex; flex-direction:column; gap:8px; grid-template-columns:none; }
    .fr .rejilla.lista .job { flex-direction:row; align-items:center; gap:12px; background:var(--panel);
                              border:1px solid var(--borde); border-radius:12px; padding:8px 10px; }
    .fr .rejilla.lista .job > video, .fr .rejilla.lista .job > a, .fr .rejilla.lista .job > .hueco {
        order:0; width:112px; flex:none; aspect-ratio:16/9; border-radius:8px; max-height:none; }
    .fr .rejilla.lista .job.vertical > video, .fr .rejilla.lista .job.vertical > a, .fr .rejilla.lista .job.vertical > .hueco {
        width:66px; aspect-ratio:9/16; max-height:116px; }
    .fr .rejilla.lista .job > a img, .fr .rejilla.lista .job video, .fr .rejilla.lista .job .hueco { width:100%; height:100%; }
    .fr .rejilla.lista .job > .prompt { order:1; flex:1; min-width:0; padding:0; -webkit-line-clamp:2; }
    .fr .rejilla.lista .job > .arriba { position:static; order:2; flex:none; width:auto; pointer-events:auto; justify-content:flex-end; }
    .fr .rejilla.lista .job > .arriba .id { display:none; }
    .fr .rejilla.lista .job > .meta, .fr .rejilla.lista .job > .extra, .fr .rejilla.lista .job > .acciones { display:none; }
    /* Hueco (en espera/generándose/error) compacto dentro de la miniatura */
    .fr .rejilla.lista .job .hueco { gap:3px; font-size:.68rem; padding:3px; }
    .fr .rejilla.lista .job .hueco .pct { font-size:.78rem; margin:0; }
    .fr .rejilla.lista .job .hueco .giro { width:16px; height:16px; border-width:2px; }
    .fr .rejilla.lista .job .hueco .emoji { font-size:1rem; }
    .fr .rejilla.lista .job .hueco b, .fr .rejilla.lista .job .hueco span:not(.pct):not(.giro):not(.emoji) { display:none; }

    /* ======= LISTA "MIS VIDEOS" en la barra lateral (tipo NeuroFlix) ======= */
    .fr .fl-misvid-tit { font-size:.66rem; font-weight:800; letter-spacing:.09em; color:var(--suave); text-transform:uppercase;
                         padding:8px 12px 4px; display:flex; justify-content:space-between; align-items:center; }
    .fr .fl-misvid { display:flex; flex-direction:column; gap:1px; overflow-y:auto; max-height:42vh; padding:0 4px 6px; }
    .fr .fl-vid { display:flex; align-items:center; gap:9px; width:100%; background:transparent; border:0; border-radius:8px;
                  padding:7px 9px; cursor:pointer; color:var(--texto); text-align:left; font:inherit; font-size:.82rem; }
    .fr .fl-vid:hover { background:var(--hover); }
    .fr .fl-vid.activo { background:var(--hover); }
    .fr .fl-vid .pt { width:9px; height:9px; border-radius:50%; flex:none; background:#64748b; }
    .fr .fl-vid .pt.ok   { background:#22c55e; }
    .fr .fl-vid .pt.proc { background:#eab308; box-shadow:0 0 6px #eab308; animation:frlatir 1.3s infinite; }
    .fr .fl-vid .pt.pend { background:#94a3b8; }
    .fr .fl-vid .pt.err  { background:#ef4444; }
    .fr .fl-vid .nm { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .fr .fl-vid .tp { opacity:.55; font-size:.78rem; flex:none; }
    .fr .vivo { display:inline-flex; align-items:center; gap:6px; font-size:.72rem; color:var(--suave); font-weight:500; }
    .fr .vivo i { width:7px; height:7px; border-radius:50%; background:var(--verde-osc); box-shadow:0 0 6px var(--verde); animation:frlatir 2s infinite; }
    .fr .vivo.caido i { background:#5d4f6e; box-shadow:none; animation:none; }
    @keyframes frlatir { 50% { opacity:.35; } }
    .fr.en-proyecto #frVistaProyectos { display:none; }
    /* "Empieza a crear…" en el centro, como Flow */
    .fr .vacio, .fr .fl-vacio { color:#e3e3e3; text-align:center; margin:auto; padding:70px 20px; font-size:1.3rem; font-weight:400; }
    .fr .vacio .emoji, .fr .fl-vacio .flor { display:block; margin:0 auto 18px; }
    .fr .vacio b { display:block; font-weight:400; margin-bottom:6px; }
    .fr .vacio small { display:block; font-size:.85rem; color:var(--suave); }
    .fr .fl-vacio[hidden] { display:none; }
    .fr .giro { width:26px; height:26px; border:3px solid #352a46; border-top-color:var(--morado); border-radius:50%; animation:frgiro 1s linear infinite; }
    @keyframes frgiro { to { transform:rotate(360deg); } }

    /* ---------- Caja "¿Qué quieres crear?", fija abajo ---------- */
    .fr .st-compositor { position:sticky; bottom:14px; z-index:5; width:min(640px, 100%); margin:6px auto 0; background:#16121e; border:1px solid #352a46;
                         border-radius:22px; padding:12px 12px 10px 16px; box-shadow:0 24px 50px -18px rgba(0,0,0,.95); }
    .fr .st-compositor:focus-within { border-color:#4a3b5e; }
    .fr .st-compositor textarea { width:100%; background:transparent; border:0; outline:none; color:var(--texto); font:inherit; font-size:.95rem;
                                  resize:none; min-height:40px; max-height:240px; padding:4px 2px 8px; line-height:1.5; display:block; }
    .fr .st-compositor textarea::placeholder { color:#8f84a2; }
    .fr .st-barra { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .fr .seg { display:inline-flex; gap:2px; }
    .fr .seg button { display:inline-flex; align-items:center; gap:5px; background:none; border:0; color:var(--suave); padding:6px 11px; border-radius:999px;
                      font:inherit; font-weight:500; font-size:.78rem; cursor:pointer; }
    .fr .seg button .ms { font-size:17px; }
    .fr .seg button:hover { color:#fff; background:#241d30; }
    .fr .seg button.on { background:#2f2540; color:#fff; }
    .fr .st-barra .etq { font-size:.66rem; }
    .fr .st-derecha { margin-left:auto; display:flex; align-items:center; gap:6px; }
    .fr .st-ajustes-btn { display:inline-flex; align-items:center; gap:6px; background:#241d30; color:#e3e3e3; border:0; border-radius:999px;
                          padding:7px 12px; font:inherit; font-weight:500; font-size:.78rem; cursor:pointer; max-width:260px; }
    .fr .st-ajustes-btn > span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fr .st-ajustes-btn .ms { font-size:16px; }
    .fr .st-ajustes-btn:hover, .fr .st-ajustes-btn[aria-expanded="true"] { background:#352a46; color:#fff; }
    .fr .st-crear { width:34px; height:34px; flex:none; display:grid; place-items:center; background:#2f2540; color:#c6badb; border:0; border-radius:50%;
                    cursor:pointer; transition:.15s; padding:0; }
    .fr .st-crear .ms { font-size:20px; }
    .fr .st-compositor.listo .st-crear { background:var(--morado-vivo); color:#fff; box-shadow:0 6px 18px -6px rgba(109,0,235,.9); }
    .fr .st-crear:hover { filter:brightness(1.2); }
    /* Botón "+" y panel de recursos (subir imagen y usarla de inicio/fin/referencia) */
    .fr .st-mas { position:relative; width:34px; height:34px; flex:none; display:grid; place-items:center; background:var(--panel2); color:var(--texto); border:0; border-radius:50%; cursor:pointer; }
    .fr .st-mas:hover { filter:brightness(1.12); }
    .fr .st-mas.con { background:var(--morado-vivo); color:#fff; }
    .fr .st-mas .n { position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px; border-radius:999px; background:var(--morado-vivo); color:#fff; font-size:.62rem; font-weight:700; display:grid; place-items:center; }
    .fr .st-recursos { display:none; position:absolute; left:0; bottom:calc(100% + 10px); width:min(430px, 100%); background:var(--panel); border:1px solid var(--borde); border-radius:20px; padding:16px; box-shadow:0 24px 60px -18px rgba(0,0,0,.55); z-index:10; }
    .fr .st-recursos.ver { display:block; }
    .fr .st-compositor.soltando { box-shadow:0 0 0 3px var(--morado-vivo), 0 24px 50px -18px rgba(0,0,0,.6); }
    .fr .st-compositor.soltando::after { content:'Suelta la imagen para crear un video con ella'; position:absolute; inset:0; display:grid; place-items:center; background:rgba(109,0,235,.12); border-radius:22px; font-weight:600; color:var(--morado-vivo); pointer-events:none; }
    .fr .rec-tit { display:flex; align-items:center; gap:8px; font-weight:600; margin-bottom:4px; }
    .fr .rec-ayuda { font-size:.76rem; color:var(--suave); margin:0 0 12px; line-height:1.5; }
    .fr .rec-subir { display:inline-flex; align-items:center; gap:8px; background:var(--morado-suave); color:var(--morado); border:1px dashed var(--morado-borde); border-radius:12px; padding:10px 14px; font-size:.84rem; font-weight:600; cursor:pointer; }
    .fr .rec-subir:hover { filter:brightness(1.05); }
    .fr .rec-progreso { margin-top:12px; display:flex; align-items:center; gap:10px; font-size:.75rem; color:var(--suave); }
    .fr .rec-barra { flex:1; height:6px; background:var(--panel2); border-radius:999px; overflow:hidden; }
    .fr .rec-barra i { display:block; height:100%; width:0; background:var(--morado-vivo); transition:width .15s; }
    .fr .rec-lista { display:flex; flex-direction:column; gap:8px; margin-top:12px; }
    .fr .rec-item { display:flex; align-items:center; gap:10px; }
    .fr .rec-item img { width:44px; height:44px; border-radius:8px; object-fit:cover; flex:none; background:var(--panel2); }
    .fr .rec-rol { flex:1; }
    .fr .rec-x { background:none; border:0; color:var(--rojo); font-size:1rem; cursor:pointer; padding:4px; }
    .fr .rec-pie { display:flex; justify-content:flex-end; margin-top:12px; }
    .fr .fl-nota { text-align:center; font-size:.72rem; color:#8f84a2; margin:8px auto 0; max-width:640px; }
    .fr .fl-nota b { color:#c6badb; font-weight:500; }
    /* Ajustes: ventanita encima de la caja */
    .fr .st-ajustes { display:none; position:absolute; right:0; bottom:calc(100% + 10px); width:min(520px, 100%); background:#16121e;
                      border:1px solid #352a46; border-radius:20px; padding:18px; box-shadow:0 24px 60px -18px rgba(0,0,0,.95); }
    .fr .st-ajustes.ver { display:block; }
    .fr .st-ajustes .titulo { display:flex; align-items:center; gap:8px; font-weight:500; margin-bottom:14px; }
    .fr .st-ajustes .grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
    .fr label.l { display:flex; justify-content:space-between; align-items:baseline; font-size:.78rem; font-weight:500; color:var(--suave); margin:0 0 6px; }
    .fr .ayuda { font-size:.72rem; color:var(--suave); margin:6px 0 0; }
    .fr select, .fr input[type=number], .fr input[type=text], .fr input[type=email] {
        width:100%; background:#241d30; color:var(--texto); border:1px solid transparent; border-radius:12px; padding:9px 12px; font:inherit; outline:none; }
    .fr select:focus, .fr input:focus { border-color:#555; }
    .fr select:disabled { color:#5c5c5c; }

    /* ---------- Extensión y conexión (debajo) ---------- */
    .fr > .conexion, .fr > .tarjeta { margin-left:22px; margin-right:22px; }
    .fr > .tarjeta:last-child { margin-bottom:22px; }
    .fr .conexion { display:grid; grid-template-columns:1fr 1.4fr; gap:16px; margin-top:10px; margin-bottom:22px; align-items:stretch; }
    .fr .conexion .fr-col-der { display:flex; flex-direction:column; gap:16px; min-width:0; }
    .fr .conexion .cab { padding:18px 20px 10px; }
    .fr .punto { display:inline-block; width:10px; height:10px; border-radius:50%; background:#5d4f6e; margin-right:7px; }
    .fr .punto.on { background:var(--verde-osc); box-shadow:0 0 8px var(--verde); }
    .fr .punto.pausa { background:var(--amarillo); }
    .fr .token { background:#0d0a13; border:1px solid var(--borde); border-radius:12px; padding:9px 11px; word-break:break-all; font-size:.8rem; margin-bottom:10px; font-family:ui-monospace, Consolas, monospace; }
    .fr .cfg { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
    .fr .cfg label { font-size:.74rem; color:var(--suave); margin-bottom:4px; display:block; font-weight:500; }
    .fr .cfg input { padding:8px 10px; border-radius:10px; }
    .fr table { color:var(--texto); }

    @media (max-width:1100px) {
        .fr .conexion { grid-template-columns:1fr; }
        .fr .fl-cuenta { display:none; }
        .fr .fl-cuerpo { grid-template-columns:200px minmax(0, 1fr); }
        .fr .fl-izq { width:200px; }
    }
    @media (max-width:760px) {
        .fr .fl-top { flex-wrap:wrap; padding:10px 12px; gap:6px; }
        .fr .fl-izq, .fr.side-oculta .fl-izq { width:100%; flex:1 1 100%; }
        .fr .fl-der { width:100%; justify-content:flex-start; }
        .fr .fl-buscar { order:3; max-width:none; width:100%; flex-basis:100%; }
        .fr .fl-der { margin-left:0; }
        .fr .fl-marca, .fr .fl-pill .t { display:none; }
        .fr .fl-cuerpo { grid-template-columns:minmax(0, 1fr); padding:0 12px 14px; }
        .fr .fl-side { position:static; height:auto; min-height:0; }
        .fr .fl-nav { display:flex; gap:4px; overflow-x:auto; }
        .fr .fl-nav button { width:auto; white-space:nowrap; padding:9px 12px; }
        .fr .fl-side .fl-sep, .fr .fl-side .fl-abajo, .fr .fl-side > .it { display:none; }
        .fr > .conexion, .fr > .tarjeta { margin-left:12px; margin-right:12px; }
        .fr .fl-banner { min-height:0; }
        .fr .fl-banner .txt { padding:30px 22px; }
        .fr .fl-arte { opacity:.35; inset:-40px -200px -40px 10%; }
        .fr .pasos, .fr .cfg, .fr .st-ajustes .grid { grid-template-columns:1fr; }
        .fr .proyectos, .fr .rejilla { grid-template-columns:1fr; }
        .fr .st-compositor { bottom:8px; }
        .fr .st-derecha { margin-left:0; width:100%; justify-content:space-between; }
        .fr .fl-flotante { padding:18px 26px; }
    }
    /* ---------- Celular ---------- */
    @media (max-width:560px) {
        html, body { overflow-x:hidden; }
        .fr { min-height:auto; }
        .fr .fl-top { padding:8px 10px; gap:6px; }
        .fr .fl-nombre { font-size:.95rem; }
        .fr .fl-der { gap:5px; flex-wrap:wrap; }
        .fr .fl-pill { height:38px; padding:0 10px; font-size:.8rem; }
        .fr .fl-icono { width:38px; height:38px; }
        /* Galería: una columna, sin desbordar */
        .fr .rejilla, .fr .proyectos { grid-template-columns:1fr; gap:14px; }
        .fr .job.vertical video, .fr .job.vertical img, .fr .job.vertical .hueco { max-height:72vh; }
        .fr .job .acciones { flex-wrap:wrap; }
        /* Compositor fijo abajo, que encaje de borde a borde */
        .fr .st-compositor { width:100%; border-radius:18px; padding:10px 10px 8px 12px; bottom:6px; }
        .fr .st-barra { gap:4px; }
        .fr .seg button { padding:6px 9px; font-size:.74rem; }
        .fr .seg button .ms { font-size:15px; }
        .fr .st-ajustes-btn { max-width:140px; font-size:.72rem; padding:6px 10px; }
        .fr .st-derecha { width:auto; margin-left:auto; }
        /* Paneles flotantes (ajustes, recursos): de borde a borde y con scroll */
        .fr .st-ajustes, .fr .st-recursos { left:0; right:0; width:auto; max-height:68vh; overflow-y:auto; }
        .fr .st-ajustes .grid { grid-template-columns:1fr; }
        /* Barra superior de la página pública */
        .fp-barra { padding:10px 14px; }
        .fp-marca { font-size:1rem; }
    }

    /* Lo que lleva "hidden" no se ve nunca, aunque un estilo le ponga display */
    .fr [hidden] { display:none !important; }

    /* ---------- Colores SNAVE CLOUD (05/10/2026): morado #6d00eb y verde neón ---------- */
    .fr .fl-nav button.on { background:linear-gradient(90deg, rgba(109,0,235,.45), rgba(109,0,235,.10)); box-shadow:inset 3px 0 0 #8b3dff; }
    .fr .fl-nav button:hover, .fr .fl-side .it:hover { background:rgba(109,0,235,.16); }
    .fr .seg button.on { background:#6d00eb; color:#fff; }
    .fr .fl-pill { border-color:rgba(185,138,255,.32); }
    .fr a.fl-pill:hover, .fr button.fl-pill:hover { background:rgba(109,0,235,.2); color:#fff; }
    .fr .fl-icono:hover { background:rgba(109,0,235,.22); }
    .fr .fl-blanco, .fr .fl-blanco:hover { background:linear-gradient(90deg, #00ff88, #00d471); color:#04261a; font-weight:600; box-shadow:0 10px 24px -8px rgba(0,255,136,.55); }
    .fr .fl-blanco:hover { filter:brightness(1.06); }
    .fr .fl-flotante { background:rgba(109,0,235,.8); color:#fff; box-shadow:0 20px 40px -14px rgba(109,0,235,.75); }
    .fr .fl-flotante:hover { background:rgba(139,61,255,.92); }
    .fr .fl-buscar label:focus-within { border-color:#8b3dff; }
    .fr .st-compositor { border-color:#3a2d52; box-shadow:0 24px 50px -18px rgba(0,0,0,.95), 0 0 0 1px rgba(109,0,235,.12); }
    .fr .st-compositor:focus-within { border-color:#8b3dff; box-shadow:0 24px 50px -18px rgba(0,0,0,.95), 0 0 0 4px rgba(109,0,235,.18); }
    .fr .st-compositor.listo .st-crear { background:linear-gradient(135deg, #00ff88, #00d471); color:#04261a; box-shadow:0 6px 18px -6px rgba(0,255,136,.75); }
    .fr .st-ajustes-btn { background:rgba(109,0,235,.2); color:#eadcff; }
    .fr .st-ajustes-btn:hover, .fr .st-ajustes-btn[aria-expanded="true"] { background:rgba(109,0,235,.35); }
    .fr .st-ajustes { border-color:#3a2d52; }
    .fr .proyecto:hover .portada { outline:2px solid #8b3dff; }
    .fr .job .acciones button:hover, .fr .job .acciones a:hover { background:rgba(109,0,235,.32); }
    .fr .estado.hecho { color:#00ff88; }
    .fr .btn-morado { background:linear-gradient(135deg, #8b3dff, #6d00eb); }
    .fr .btn-borde:hover { background:rgba(109,0,235,.2); border-color:#8b3dff; }
    .fr .vivo i { background:#00ff88; }
    .fr .fl-nota b, .fr .st-proyecto b { color:#d9c2ff; }
    .fr .ayuda a { color:#00ff88; font-weight:600; }
    .fr select:focus, .fr input:focus { border-color:#8b3dff; }

    /* ---------- Conexión con Google (solo admin) ---------- */
    .fr .conexion { grid-template-columns:1.3fr 1fr; }
    .fr .tarjeta.google { border-color:rgba(139,61,255,.5); background:radial-gradient(120% 120% at 0% 0%, rgba(109,0,235,.24), transparent 55%), var(--panel); }
    .fr .conexion h2 .ms { color:#b98aff; font-size:20px; vertical-align:-4px; margin-right:4px; }
    .fr .estado-api { display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:999px; font-size:.8rem; font-weight:600; background:#241d30; white-space:nowrap; }
    .fr .estado-api .punto { margin:0; }
    .fr .estado-api.on { background:rgba(0,255,136,.12); color:#00ff88; }
    .fr .estado-api.on .punto { background:#00ff88; box-shadow:0 0 8px #00ff88; }
    .fr .estado-api.pausa { background:rgba(250,204,21,.12); color:#facc15; }
    .fr .estado-api.pausa .punto { background:#facc15; }
    .fr .estado-api.off { background:rgba(242,139,130,.12); color:#f28b82; }
    .fr .estado-api.off .punto { background:#f28b82; }
    /* Lista de cuentas conectadas en Núcleo SNAVE */
    .fr .cuentas-conectadas { margin-top:12px; background:var(--panel2); border:1px solid var(--borde); border-radius:12px; padding:10px 12px; }
    .fr .cc-tit { font-size:.8rem; font-weight:600; color:var(--suave); display:flex; align-items:center; gap:8px; margin-bottom:6px; }
    .fr .cc-n { background:var(--morado-vivo); color:#fff; border-radius:999px; padding:1px 8px; font-size:.72rem; font-weight:700; }
    .fr .cc-item { display:flex; align-items:center; gap:8px; padding:6px 0; font-size:.82rem; border-top:1px solid var(--borde); }
    .fr .cc-item:first-of-type { border-top:0; }
    .fr .cc-punto { width:8px; height:8px; border-radius:50%; flex:none; }
    .fr .cc-item.ok .cc-punto { background:var(--verde); box-shadow:0 0 6px var(--verde); }
    .fr .cc-item.mal .cc-punto { background:var(--rojo); }
    .fr .cc-mail { flex:1; word-break:break-all; }
    .fr .cc-salud { font-size:.72rem; color:var(--suave); white-space:nowrap; }
    .fr .cc-item.ok .cc-salud { color:var(--verde-osc); }
    .fr .cc-item.mal .cc-salud { color:var(--rojo); }
    .fr .cc-vacio { font-size:.76rem; color:var(--suave); }
    .fr .cc-aviso { margin-top:8px; font-size:.76rem; color:var(--amarillo); }
    .fr input[type=password] { width:100%; background:#241d30; color:var(--texto); border:1px solid transparent; border-radius:12px; padding:10px 12px; font:inherit; outline:none; }
    .fr .fila-api { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:14px; }
    .fr .chk { display:inline-flex; gap:8px; align-items:center; font-size:.85rem; cursor:pointer; margin:0; }
    .fr .chk input { accent-color:#6d00eb; width:16px; height:16px; }
    .fr .chk.peligro { color:#f28b82; }
    .fr .chk.caja { display:flex; align-items:flex-start; background:rgba(109,0,235,.14); border:1px solid rgba(139,61,255,.3); border-radius:14px; padding:12px 14px; margin-bottom:14px; }
    .fr .chk.caja input { margin-top:3px; }
    @media (max-width:1100px) { .fr .conexion { grid-template-columns:1fr; } }
</style>

<div class="container-fluid fr-pagina">
<div class="fr studio<?= $frPublico ? ' publico' : '' ?><?= $frPanelNumeros ? ' panel-numeros' : '' ?>">
    <!-- ================= BARRA DE ARRIBA ================= -->
    <header class="fl-top">
        <div class="fl-izq">
            <!-- Portada: el nombre. Dentro de un proyecto: casa, nombre y ⋮ (como Flow) -->
            <button type="button" class="fl-logo solo-lista" id="frNavProyectos" title="Mis proyectos">Flow <span class="de">SNAVE CLOUD</span></button>
            <button type="button" class="fl-icono solo-proyecto fl-mostrar" id="frMostrar" title="Mostrar la barra lateral"><span class="ms">left_panel_open</span></button>
            <button type="button" class="fl-icono solo-proyecto" id="frVolver" title="Volver a mis proyectos"><span class="ms">home</span></button>
            <span class="fl-nombre solo-proyecto" id="frTituloResultados">Proyecto</span>
            <button type="button" class="fl-icono solo-proyecto" id="frRenombrar" title="Cambiar el nombre" hidden><span class="ms">more_vert</span></button>
        </div>

        <div class="fl-buscar solo-proyecto">
            <label><span class="ms">search</span><input type="text" id="frBuscar" placeholder="Buscar en el proyecto" autocomplete="off"></label>
            <button type="button" class="fl-icono" data-abre-ajustes title="Ajustes de creación"><span class="ms">tune</span></button>
        </div>

        <div class="fl-der">
            <!-- Estado del generador: solo lo ve el admin (los clientes no) -->
            <?php if ($esAdmin): ?>
            <a href="#conexion" class="fl-pill fl-estado" title="El generador de SNAVE CLOUD">
                <span class="ico <?= $conectada ? 'on' : ($ext['conectada'] ? 'medio' : 'off') ?>" id="frExtIco"></span>
                <span class="t">Generador:</span> <span id="frExtTxt"><?= !$ext['conectada'] ? 'Sin configurar' : ($ext['procesar'] ? 'Encendido' : 'En pausa') ?></span>
                <span class="vivo fl-cuenta" id="frVivo"><i></i>en vivo</span>
            </a>
            <?php endif; ?>
            <!-- Contadores, en vivo -->
            <span class="fl-pill fl-cuenta" title="En espera · Generándose · Listos">
                <span class="n"><span class="ms">schedule</span><b id="frNPend"><?= $pendientes ?></b></span>
                <span class="n"><span class="ms">progress_activity</span><b id="frNProc"><?= $enProceso ?></b></span>
                <span class="n"><span class="ms">check_circle</span><b id="frNHechos"><?= $hechos ?></b></span>
            </span>
            <button type="button" class="fl-icono" id="frNavNuevo" title="Nuevo proyecto"><span class="ms">add</span></button>
            <?php if ($esAdmin): ?><a class="fl-icono" href="#conexion" title="Conexión con Google"><span class="ms">key</span></a><?php endif; ?>
            <button type="button" class="fl-pill solo-proyecto" id="frVistaLista" title="Ver en lista"><span class="ms">view_list</span><span class="t">Lista</span></button>
            <button type="button" class="fl-icono" id="frNavActualizar" title="Actualizar"><span class="ms">refresh</span></button>
            <?php if ($esAdmin): ?><a class="fl-icono" href="#accesos" title="Accesos públicos"><span class="ms">group</span></a><?php endif; ?>
            <button type="button" class="fl-pill" data-abre-space title="Spaces: conecta nodos de Texto con Imagen y Video"><span class="ms">hub</span><span class="t">Spaces</span></button>
            <?php if ($frUsuario): ?>
                <span class="fl-yo"><span class="fl-cuenta"><?= $h($frUsuario['nombre']) ?></span><span class="av"><?= $h(mb_strtoupper(mb_substr($frUsuario['nombre'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><a href="<?= $h($frUsuario['salir']) ?>">Salir</a></span>
            <?php else: ?>
                <span class="fl-marca">SNAVE<i></i></span>
            <?php endif; ?>
        </div>
    </header>

    <div class="fl-cuerpo">
    <!-- ================= BARRA LATERAL (dentro de un proyecto) ================= -->
    <aside class="fl-side solo-proyecto">
        <div class="fl-nav" id="frPestanas">
            <button type="button" data-filtro="todos" class="on"><span class="ms">grid_view</span><span class="t">Todo el contenido</span><span class="c"><?= count($trabajos) ?></span></button>
            <button type="button" data-filtro="video"><span class="ms">movie</span><span class="t">Videos</span><span class="c"><?= $nVideos ?></span></button>
            <button type="button" data-filtro="imagen"><span class="ms">image</span><span class="t">Imágenes</span><span class="c"><?= $nImagenes ?></span></button>
        </div>
        <div class="fl-sep"></div>
        <!-- Lista completa de videos/imágenes (tipo NeuroFlix): clic abre ese video -->
        <div class="fl-misvid-tit">Mis videos <span class="c" id="frMisVideosN"><?= count($trabajos) ?></span></div>
        <div class="fl-misvid" id="frMisVideos">
            <?= $frPintarMisVideos($trabajos) ?>
        </div>
        <div class="fl-sep"></div>
        <a class="it" id="frProyectoActivoFlow" href="#" target="_blank" rel="noopener" hidden style="display:none"></a>
        <button type="button" class="it" data-abre-space><span class="ms">hub</span> Spaces</button>
        <?php if ($esAdmin): ?><a class="it" href="#conexion"><span class="ms">key</span> Conexión con Google</a><?php endif; ?>
        <div class="fl-abajo">
            <button type="button" class="it" data-ir-lista><span class="ms">folder_open</span> Mis proyectos</button>
            <button type="button" class="it" id="frOcultar"><span class="ms">left_panel_close</span> Ocultar</button>
        </div>
    </aside>

    <!-- ================= CENTRO ================= -->
    <main class="st-main">
        <?php if ($frPanelNumeros): $T = $frTotales; ?>
        <!-- ============ TABLERO (panel admin): solo números + proceso ============ -->
        <section class="fr-tablero">
            <div class="fr-tab-cab">
                <h2>📊 Resumen de generación</h2>
                <a class="fr-tab-ir" href="../flow" target="_blank" rel="noopener"><span class="ms">movie</span> Crear videos en /flow</a>
            </div>
            <div class="fr-tab-grid">
                <div class="fr-card ok">
                    <span class="ico"><span class="ms">movie</span></span>
                    <span class="dat"><span class="n" data-k="videos_ok"><?= (int) $T['videos_ok'] ?></span><span class="t">Videos generados</span></span>
                </div>
                <div class="fr-card img">
                    <span class="ico"><span class="ms">image</span></span>
                    <span class="dat"><span class="n" data-k="imagenes_ok"><?= (int) $T['imagenes_ok'] ?></span><span class="t">Imágenes generadas</span></span>
                </div>
                <div class="fr-card cola">
                    <span class="ico"><span class="ms">schedule</span></span>
                    <span class="dat"><span class="n" data-k="pendientes"><?= (int) $T['pendientes'] ?></span><span class="t">Pendientes (en cola)</span></span>
                </div>
                <div class="fr-card gen">
                    <span class="ico"><span class="ms">bolt</span></span>
                    <span class="dat"><span class="n" data-k="procesando"><?= (int) $T['procesando'] ?></span><span class="t">Generándose ahora</span></span>
                </div>
                <div class="fr-card err">
                    <span class="ico"><span class="ms">error</span></span>
                    <span class="dat"><span class="n" data-k="err_total"><?= (int) ($T['errores'] + $T['rechazados']) ?></span><span class="t">Errores / rechazados</span></span>
                </div>
                <div class="fr-card hoy">
                    <span class="ico"><span class="ms">today</span></span>
                    <span class="dat"><span class="n" data-k="hechos_hoy"><?= (int) $T['hechos_hoy'] ?></span><span class="t">Entregados hoy</span></span>
                </div>
                <div class="fr-card tot">
                    <span class="ico"><span class="ms">inventory_2</span></span>
                    <span class="dat"><span class="n" data-k="total"><?= (int) $T['total'] ?></span><span class="t">Pedidos totales</span></span>
                </div>
            </div>
            <p class="fr-tab-nota">Aquí ves los <b>números</b>, el <b>proceso en vivo</b> (abajo, en "Progreso y resultado") y la parte <b>técnica</b> (cuentas conectadas, reglas y cron). La <b>creación visual</b> de videos e imágenes está en <a href="../flow" target="_blank" rel="noopener">la página /flow</a> (entra con tu cuenta de admin).</p>
        </section>
        <?php endif; ?>
        <!-- Banner de la portada -->
        <div class="fl-banner solo-lista" id="frBanner">
            <div class="fl-arte" aria-hidden="true">
                <?php foreach ([['movie', '#7c3aed'], ['image', '#16a34a'], ['auto_awesome', '#db2777'], ['videocam', '#eab308'], ['palette', '#2563eb'], ['music_note', '#ea580c'],
                                ['photo_camera', '#9333ea'], ['animation', '#0d9488'], ['theaters', '#6d00eb'], ['brush', '#dc2626'], ['movie_filter', '#22c55e'], ['star', '#f59e0b'],
                                ['slow_motion_video', '#a855f7'], ['landscape', '#059669'], ['face', '#e11d48'], ['camera_roll', '#3b82f6'], ['bolt', '#00c96b'], ['favorite', '#f472b6']] as [$ic, $c]): ?>
                    <span style="--c:linear-gradient(145deg, <?= $c ?>cc, <?= $c ?>)"><span class="ms"><?= $ic ?></span></span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="fl-icono cerrar" id="frCerrarBanner" title="Cerrar"><span class="ms">close</span></button>
            <div class="txt">
                <h1>Crea videos con <em>Flow</em> desde SNAVE CLOUD</h1>
                <p>Abre un proyecto, escribe lo que quieres ver y en unos minutos lo tienes aquí, listo para descargar. Videos con Veo e imágenes con Nano Banana, de Google.</p>
                <div class="btns">
                    <button type="button" class="fl-blanco" data-nuevo-proyecto><span class="ms">add</span> Nuevo proyecto</button>
                    <?php if ($esAdmin && !$ext['conectada']): ?><a class="fl-pill" href="#conexion"><span class="ms">key</span> Conectar con Google</a><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($esAdmin && !$ext['conectada']): ?>
        <!-- Primeros pasos (admin): solo mientras falte la clave de Google -->
        <div class="pasos solo-lista">
            <div class="paso <?= $conectada ? 'hecho' : '' ?>">
                <span class="num"><?= $conectada ? '✓' : '1' ?></span>
                <div><b>Conecta Núcleo SNAVE</b><span>Pega tu token y el email de tu cuenta de Google Flow, abajo en "Conexión con Google Flow". <a href="#conexion">Ir ahí</a>.</span></div>
            </div>
            <div class="paso <?= $pendientes || $enProceso || $hechos ? 'hecho' : '' ?>">
                <span class="num"><?= $pendientes || $enProceso || $hechos ? '✓' : '2' ?></span>
                <div><b>Abre un proyecto y escribe</b><span>Describe la escena en "¿Qué quieres crear?".</span></div>
            </div>
            <div class="paso <?= $hechos ? 'hecho' : '' ?>">
                <span class="num"><?= $hechos ? '✓' : '3' ?></span>
                <div><b>Recibe el resultado</b><span>Tarda unos minutos y aparece aquí, listo para descargar.</span></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($frFlash['ok'])): ?>
            <div class="aviso ok">✅ <?= $h($frFlash['ok']) ?></div>
        <?php endif; ?>
        <!-- Aviso en vivo: se enciende si el generador no está listo o está en pausa -->
        <div class="aviso alerta aviso-ext" id="frAvisoExt"></div>
        <?php if (!empty($frAvisoDisp)): ?>
        <div class="aviso" style="background:rgba(242,139,130,.14); border:1px solid rgba(242,139,130,.5); color:#fecaca; font-weight:600; margin:0 0 6px"><?= $h($frAvisoDisp) ?></div>
        <?php endif; ?>
        <?php if ($retencionDias > 0 && !$frPanelNumeros): ?>
        <div class="aviso" style="background:rgba(109,0,235,.12); border:1px solid rgba(109,0,235,.3); color:var(--texto); font-size:.85rem; margin:0 0 6px">⏳ Tus videos e imágenes se guardan <b><?= (int) $retencionDias ?> días</b> — descárgalos antes, después se borran automáticamente.</div>
        <?php endif; ?>
        <?php if (!empty($frFlash['err'])): ?><div class="aviso err">⚠️ <?= $h($frFlash['err']) ?></div><?php endif; ?>

        <!-- Galería -->
        <div class="tarjeta" id="galeria">
            <!-- Vista 1: tus proyectos. Al pulsar uno se pasa a la vista 2 (sus videos) -->
            <div class="proyectos-zona" id="frVistaProyectos">
                <div class="proyectos">
                    <?php if (!$frPanelNumeros): foreach ($tarjetasProyecto as $p): ?>
                        <div class="proyecto" role="button" tabindex="0" data-proyecto="<?= $h($p['id']) ?>" data-nombre="<?= $h($p['nombre']) ?>">
                            <span class="portada">
                                <?php if ($p['portada']): $portSrc = !empty($p['portada']['ruta']) ? $frMedia . $p['portada']['ruta'] : $p['portada']['url']; ?>
                                    <?php if ($p['portada']['tipo'] === 'imagen'): ?><img src="<?= $h($portSrc) ?>" alt="" loading="lazy">
                                    <?php elseif ($frSinVideo): ?><span class="vacia"><span class="ms">movie</span></span>
                                    <?php else: ?><video data-src="<?= $h($portSrc) ?>#t=0.5" preload="none" muted class="fr-lazy"></video><?php endif; ?>
                                <?php else: ?><span class="vacia"><span class="ms"><?= $p['id'] === '' ? 'folder_special' : 'movie' ?></span></span><?php endif; ?>
                            </span>
                            <span class="pie">
                                <b title="<?= FlowCola::esProyectoPanel($p['id']) ? 'Se crea en Flow con el primer pedido' : '' ?>"><?= $h($p['nombre']) ?></b>
                                <?php if ($p['id'] !== ''): ?><button type="button" class="lapiz" data-renombrar title="Cambiar el nombre"><span class="ms">edit</span></button><?php endif; ?>
                                <span class="mini"><span class="ms">movie</span><?= $p['videos'] ?> <span class="ms">image</span><?= $p['imagenes'] ?></span>
                            </span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <!-- Para cambiar el nombre de un proyecto (solo en SnaveCloud) -->
            <form method="post" id="frFormRenombrar" hidden>
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="accion" value="renombrar">
                <input type="hidden" name="proyecto">
                <input type="hidden" name="nombre">
            </form>
            <!-- Para crear un proyecto con el "+" -->
            <form method="post" id="frFormNuevoProyecto" hidden>
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="accion" value="proyecto_nuevo">
                <input type="hidden" name="nombre">
            </form>

            <!-- Vista 2: un proyecto vacío (o sin coincidencias en la búsqueda) -->
            <div class="fl-vacio solo-proyecto" id="frVacioProyecto" hidden>
                <?php // La florecita en píxeles de Flow ("#" blanco, "@" morado SNAVE)
                $flor = ['....###.###....', '...#...#...#...', '...#...#...#...', '.###.#####.###.', '#...##.@.##...#', '#...#.@@@.#...#',
                         '#...##.@.##...#', '.###.#####.###.', '...#...#...#...', '...#...#...#...', '....###.###....', '.......#.......',
                         '.......#...##..', '..##...#..#....', '....#..#.#.....', '.....#.##......', '.......#.......', '.....#####.....']; ?>
                <svg class="flor" width="60" height="72" viewBox="0 0 15 18" shape-rendering="crispEdges" aria-hidden="true">
                    <?php foreach ($flor as $y => $fila): for ($x = 0; $x < strlen($fila); $x++): if ($fila[$x] === '.') continue; ?><rect x="<?= $x ?>" y="<?= $y ?>" width="1" height="1" fill="<?= $fila[$x] === '@' ? '#b98aff' : '#e3e3e3' ?>"/><?php endfor; endforeach; ?>
                </svg>
                <span id="frVacioTxt">Empieza a crear escribiendo lo que quieres ver</span>
            </div>

            <?php if (!$trabajos): ?>
                <p class="vacio solo-lista"><b>Aún no tienes proyectos</b><small>Pulsa "+ Nuevo proyecto", ponle nombre y empieza a crear dentro de él.</small></p>
            <?php else: ?>
            <div class="rejilla solo-proyecto">
                <?php if (!$frPanelNumeros): foreach ($trabajos as $t):
                    [$estadoTxt, $estadoCls] = FLOW_ESTADOS[$t['estado']] ?? [ucfirst($t['estado']), 'cola'];
                    $archivos = array_values(array_filter(FlowCola::archivosDe($t), function ($a) { return !empty($a['ruta']) || !empty($a['url']); }));
                    $src = function ($a) use ($frMedia) { return !empty($a['ruta']) ? $frMedia . $a['ruta'] : $a['url']; };
                    $principal = $archivos[0] ?? null;
                    $esNuevo = $nuevoDesde && (int) $t['id'] >= $nuevoDesde && (int) $t['id'] <= $nuevoHasta;
                    $esMio = (int) $t['creado_por'] === $user_id;
                    // Entrega máxima: desde que la extensión lo toma, la espera de las reglas + 3 min
                    $minEntrega = FlowCola::minutosEntrega($config, $t['tipo'], $t['calidad'] ?? '');
                    $limite = $t['tomado_en'] ? strtotime($t['tomado_en']) + $minEntrega * 60 : 0;
                    // Progreso visual (como Flow): arranca cuando se toma el pedido y una
                    // duración "esperada" según tipo/calidad. El % lo pinta el JS, subiendo
                    // suave y acercándose a 99% (nunca se queda clavado); al terminar, la
                    // tarjeta pasa a mostrar el video.
                    $inicioMs = ($t['tomado_en'] ? strtotime($t['tomado_en']) : time()) * 1000;
                    $esperadoSeg = $t['tipo'] === 'imagen' ? 30 : (($t['calidad'] ?? '') === '1080p' ? 300 : 150); ?>
                <div class="job <?= $t['formato'] === '9:16' ? 'vertical' : '' ?> <?= $esNuevo ? 'nuevo' : '' ?>" data-tipo="<?= $h($t['tipo']) ?>"
                     data-id="<?= (int) $t['id'] ?>" data-mio="<?= $esMio ? 1 : 0 ?>" data-limite="<?= $limite * 1000 ?>"
                     data-inicio="<?= $inicioMs ?>" data-esperado="<?= $esperadoSeg ?>" data-proyecto="<?= $h($t['proyecto']) ?>">
                    <div class="arriba">
                        <span class="etq morado"><?= $t['tipo'] === 'imagen' ? '🖼️ Imagen' : '🎬 Video' ?></span>
                        <?php if ($esNuevo): ?><span class="etq" style="background:#dcfce7;color:#166534">Nuevo</span><?php endif; ?>
                        <?php if ($esAdmin && (int) $t['creado_por'] !== $user_id): ?><span class="etq pago" title="Pedido de otro usuario">👤 <?= (int) $t['creado_por'] < 0
                            ? $h($correoDeAcceso[(int) $t['creado_por']] ?? 'acceso borrado')   // acceso de la página pública
                            : 'usuario #' . (int) $t['creado_por'] ?></span><?php endif; ?>
                        <span class="id">#<?= (int) $t['id'] ?></span>
                        <span class="estado <?= $estadoCls ?>"><?= $estadoTxt ?></span>
                        <?php if ($esAdmin && !empty($t['cuenta'])): ?><span class="etq cuenta-gen" title="Generado en la cuenta: <?= $h($t['cuenta']) ?>">⚙️ <?= $h(explode('@', $t['cuenta'])[0]) ?></span><?php endif; ?>
                    </div>
                    <div class="prompt"><?= $h($t['prompt']) ?></div>

                    <?php if ($principal): ?>
                        <?php if ($principal['tipo'] === 'imagen'): $refImg = !empty($principal['ruta']) ? $principal['ruta'] : $principal['url']; ?>
                            <a href="<?= $h($src($principal)) ?>" target="_blank" rel="noopener" draggable="false"><img src="<?= $h($src($principal)) ?>" alt="" loading="lazy" draggable="true" data-ref="<?= $h($refImg) ?>" title="Arrástrala al cuadro de crear para generar un video con esta imagen"></a>
                        <?php elseif ($frSinVideo): ?>
                            <a class="fr-verarchivo" href="<?= $h($src($principal)) ?>" target="_blank" rel="noopener" title="Abrir el video (se ve en /flow más cómodo)"><span class="ms">play_circle</span><span>Ver / descargar video</span></a>
                        <?php else: ?>
                            <video data-src="<?= $h($src($principal)) ?>" controls preload="none" class="fr-lazy"></video>
                        <?php endif; ?>
                        <?php if (count($archivos) > 1): ?>
                            <div class="extra">
                                <?php foreach (array_slice($archivos, 1) as $a): ?>
                                    <a href="<?= $h($src($a)) ?>" target="_blank" rel="noopener" title="Abrir">
                                        <?php if ($a['tipo'] === 'imagen'): ?><img src="<?= $h($src($a)) ?>" alt="" loading="lazy"><?php elseif ($frSinVideo): ?><span class="fr-minivid"><span class="ms">movie</span></span><?php else: ?><video data-src="<?= $h($src($a)) ?>" preload="none" muted class="fr-lazy"></video><?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($t['estado'] === 'pendiente'):
                        // Cuántos videos del MISMO dueño+dispositivo van ANTES (cola).
                        // Las imágenes no cuentan (son ilimitadas y no hacen cola).
                        $frAntes = 0;
                        if ($t['tipo'] !== 'imagen') {
                            foreach ($trabajos as $oo) {
                                if (($oo['tipo'] ?? '') === 'imagen') continue;
                                if ((int) $oo['id'] >= (int) $t['id']) continue;
                                if (!in_array($oo['estado'], ['pendiente', 'procesando'], true)) continue;
                                if ((int) $oo['creado_por'] === (int) $t['creado_por']
                                    && (string) ($oo['dispositivo'] ?? '') === (string) ($t['dispositivo'] ?? '')) $frAntes++;
                            }
                        } ?>
                        <div class="hueco"><span class="giro"></span>
                            <?php if ($frAntes > 0): ?>
                                <span class="pct" style="font-size:1.15rem">⏳ En cola</span>
                                <span>Hay <b><?= $frAntes ?></b> video<?= $frAntes === 1 ? '' : 's' ?> antes del tuyo</span>
                            <?php else: ?>
                                <span class="pct" data-pct>0%</span>
                                <span data-espera><?= $conectada ? 'Empezando a generar…' : 'En espera: el generador no está listo; se hará en cuanto lo esté.' ?></span>
                            <?php endif; ?>
                            <?php if ((int) ($t['intentos'] ?? 0) > 0): ?><b>Intento <?= (int) $t['intentos'] + 1 ?></b><span class="mini"><?= $h($t['mensaje']) ?></span><?php endif; ?>
                        </div>
                    <?php elseif ($t['estado'] === 'procesando'): ?>
                        <div class="hueco"><span class="giro"></span>
                            <span class="pct" data-pct>0%</span>
                            <span>Generándose…</span>
                            <?php if ($t['tipo'] === 'video' && ($t['calidad'] ?? '') === '1080p'): ?><span class="mini">En 1080p puede tardar un poco más</span><?php endif; ?>
                        </div>
                    <?php elseif ($t['estado'] === 'rechazado'): ?>
                        <div class="hueco rechazo"><span class="emoji">🚫</span><b>Google no lo generó</b>
                            <?= $h($t['mensaje'] !== '' ? $t['mensaje'] : 'Flow no quiso generarlo.') ?>
                            <span class="mini">Cambia el prompt (evita personas famosas, marcas o contenido sensible) y vuelve a pedirlo, o pulsa "Reintentar".</span></div>
                    <?php elseif ($t['estado'] === 'error'): ?>
                        <div class="hueco error"><span class="emoji">⚠️</span><?= $h($t['mensaje'] !== '' ? $t['mensaje'] : 'Falló sin motivo conocido.') ?><br>Puedes pulsar "Reintentar".</div>
                    <?php else: ?>
                        <div class="hueco">Terminó sin archivos. <?= $h($t['mensaje']) ?></div>
                    <?php endif; ?>

                    <div class="meta">
                        <?= $h($t['formato'] !== '' ? $t['formato'] : '16:9') ?> · <?= $h($etiquetaModelo($t)) ?>
                        <?= $t['tipo'] === 'video' ? ' · ' . (int) $t['segundos'] . ' s · ' . (($t['calidad'] ?? '') === '1080p' ? '1080p' : '720p') : '' ?>
                        <?php if (count($archivos) > 1): ?> · <?= count($archivos) ?> archivos<?php endif; ?>
                        <?php $proyNuevo = $t['proyecto'] === 'nuevo' || FlowCola::esProyectoPanel($t['proyecto']); ?>
                        <br>📁 <?= $t['proyecto'] === '' ? 'Proyecto automático' : ($proyNuevo ? $h($t['proyecto_nombre']) : $h($t['proyecto_nombre'] !== '' ? $t['proyecto_nombre'] : 'Proyecto ' . substr($t['proyecto'], 0, 8))) ?>
                        <br>Pedido el <?= $h(date('d/m/Y H:i', strtotime($t['creado_en']))) ?>
                        <?php if ($t['estado'] === 'hecho' && $t['terminado_en']):
                            // Tiempo EXACTO de todo el proceso: desde que se pidió hasta que se entregó.
                            $segProc = max(1, strtotime($t['terminado_en']) - strtotime($t['creado_en']));
                            $mm = intdiv($segProc, 60); $ss = $segProc % 60;
                            $txtProc = $mm > 0 ? ($mm . ' min ' . $ss . ' s') : ($ss . ' s'); ?>
                            <br>⏱️ <strong>Entregado en <?= $h($txtProc) ?></strong> <span class="mini">(<?= (int) $segProc ?> s exactos, desde que se pidió)</span>
                        <?php endif; ?>
                        <?php if ($esAdmin && $t['estado'] === 'hecho' && $t['terminado_en']):
                            $segTotal = strtotime($t['terminado_en']) - strtotime($t['creado_en']);
                            $segGen   = $t['tomado_en'] ? strtotime($t['terminado_en']) - strtotime($t['tomado_en']) : $segTotal; ?>
                            <br><span class="mini" style="color:var(--morado);font-weight:700" title="Cronómetro exacto (solo admin): total desde que se pidió · generación en Google">⏱️ <?= (int) $segTotal ?>s total · <?= (int) $segGen ?>s generando</span>
                        <?php endif; ?>
                        <?php if ($esAdmin && !empty($t['cuenta'])): ?><br><span class="mini" title="Cuenta donde se generó">⚙️ Cuenta: <?= $h($t['cuenta']) ?></span><?php endif; ?>
                        <?php if ($principal && empty($principal['ruta']) && !empty($principal['caduca'])): ?><br>⚠️ Guardado solo como enlace de Flow: descárgalo pronto.<?php endif; ?>
                    </div>

                    <div class="acciones">
                        <?php if ($principal && ($principal['tipo'] ?? '') === 'imagen'): $refUsar = !empty($principal['ruta']) ? $principal['ruta'] : $principal['url']; // el video ya tiene "Descargar" en el menú del reproductor ?>
                            <a href="<?= $h($src($principal)) ?>" download>⬇ Descargar</a>
                            <button type="button" class="usar-video" data-usarvideo="<?= $h($refUsar) ?>">🎬 Usar para video</button>
                        <?php endif; ?>
                        <?php if (in_array($t['estado'], ['error', 'procesando', 'rechazado'], true)): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="accion" value="reintentar">
                                <input type="hidden" name="ver" value="<?= $h(in_array($t['proyecto'], ['', 'nuevo'], true) ? '' : $t['proyecto']) ?>">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <button>↻ Reintentar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <!-- "Ver más": de arranque se enseñan pocas tarjetas (página ligera) y el
                 resto se muestran al pulsar. Lo controla el JS (frTope). -->
            <div class="fr-vermas solo-proyecto" style="text-align:center;margin:14px 0 4px">
                <button type="button" id="frVerMas" hidden>Ver más (<span class="n">0</span>)</button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Portada: "+ Nuevo proyecto" flotando abajo, como en Flow -->
        <button type="button" class="fl-flotante solo-lista" id="frNuevoProyecto" title="Crear un proyecto nuevo"><span class="ms">add</span> Nuevo proyecto</button>

        <!-- ================= CAJA "¿QUÉ QUIERES CREAR?" (dentro de un proyecto) =================
             Mismos campos y nombres que siempre: el servidor no nota el cambio. -->
        <form method="post" class="st-compositor solo-proyecto" id="frForm">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="tipo" id="frTipo" value="<?= $h($ult['tipo']) ?>">
            <input type="hidden" name="modo_prompts" id="frModoPrompts" value="uno">
            <input type="hidden" name="cantidad" value="1">
            <!-- El proyecto es el que está abierto -->
            <input type="hidden" name="proyecto" id="frProyecto" value="">
            <!-- Imágenes adjuntas con el "+" (JSON: inicio/fin/referencias) -->
            <input type="hidden" name="entradas" id="frEntradas" value="">

            <textarea name="prompts" id="frPrompts" required maxlength="20000" rows="1"
                placeholder="¿Qué quieres crear?"></textarea>

            <div class="st-barra">
                <button type="button" class="st-mas" id="frMasBtn" title="Añadir imagen (inicio, fin o referencia)" aria-label="Añadir imagen"><span class="ms">add</span><span class="n" id="frMasN" hidden>0</span></button>
                <div class="seg" data-grupo="tipo">
                    <button type="button" data-valor="video"><span class="ms">videocam</span>Video</button>
                    <button type="button" data-valor="imagen"><span class="ms">image</span>Imagen</button>
                </div>
                <div class="seg" data-grupo="prompts">
                    <button type="button" data-valor="uno" class="on">Uno</button>
                    <button type="button" data-valor="varios">Varios</button>
                </div>
                <span class="etq gratis" id="frEtqCreditos" hidden></span>
                <div class="st-derecha">
                    <button type="button" class="st-ajustes-btn" id="frAjustesBtn" aria-expanded="false" title="Modelo, formato y duración">
                        <span id="frResumen">Video · 8s · 16:9</span>
                    </button>
                    <button class="st-crear" type="submit" id="frEnviar" title="Crear video" aria-label="Crear video"><span class="ms">arrow_forward</span></button>
                </div>
            </div>

            <!-- Recursos: subir imagen y usarla como inicio/fin/referencia (el "+") -->
            <div class="st-recursos" id="frRecursos">
                <div class="rec-tit"><span class="ms">add_photo_alternate</span> Añadir imagen</div>
                <p class="rec-ayuda">Súbela y elige cómo usarla: <b>Inicio</b> o <b>Fin</b> del video, o <b>Referencia</b> de la imagen. (JPG, PNG, WEBP o GIF, máx. 10 MB.)</p>
                <label class="rec-subir"><input type="file" id="frRecFile" accept="image/*" hidden><span class="ms">upload</span> Subir imagen</label>
                <div class="rec-progreso" id="frRecProg" hidden><div class="rec-barra"><i id="frRecBarra"></i></div><span id="frRecProgTxt">Subiendo…</span></div>
                <div class="rec-lista" id="frRecLista"></div>
                <div class="rec-pie"><button type="button" class="btn-borde" id="frRecListo">Listo</button></div>
            </div>

            <!-- Ajustes: una ventanita encima de la caja -->
            <div class="st-ajustes" id="frAjustes">
                <div class="titulo"><span class="ms">tune</span> Ajustes</div>
                <div class="grid">
                    <div>
                        <label class="l">Modelo<?= $esAdmin ? ' de Google' : '' ?></label>
                        <select name="modelo" id="frModelo">
                            <?php foreach (FLOW_MODELOS as $tipo => $lista): foreach ($lista as $valor => $m): if (!$esAdmin && !$m['gratis']) continue; ?>
                                <option value="<?= $h($valor) ?>" data-tipo="<?= $tipo ?>" data-flow="<?= $h($m['flow']) ?>" data-gratis="<?= $m['gratis'] ? 1 : 0 ?>"
                                    <?= $ult['modelo'] === $valor ? 'selected' : '' ?>><?= $h($m['etiqueta']) ?><?= ($esAdmin && $m['gratis']) ? ' (gratis)' : '' ?></option>
                            <?php endforeach; endforeach; ?>
                        </select>
                        <p class="ayuda" id="frNotaModelo"></p>
                    </div>
                    <div>
                        <label class="l">Formato</label>
                        <select name="formato" id="frFormato">
                            <option value="16:9" <?= $ult['formato'] === '16:9' ? 'selected' : '' ?>>16:9 · Horizontal</option>
                            <option value="9:16" <?= $ult['formato'] === '9:16' ? 'selected' : '' ?>>9:16 · Vertical</option>
                        </select>
                        <p class="ayuda">Horizontal: YouTube · Vertical: TikTok, Reels</p>
                    </div>
                    <div id="frDuracionZona">
                        <label class="l">Duración</label>
                        <select name="segundos" id="frSegundos">
                            <?php foreach ([8] as $s): // Solo 8 s (lo que pidió el admin) ?>
                                <option value="<?= $s ?>" selected><?= $s ?> segundos</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="ayuda" id="frAyudaDuracion">Solo para videos</p>
                    </div>
                    <!-- Calidad del video: las dos que Flow da sin gastar créditos -->
                    <div id="frCalidadZona">
                        <label class="l">Calidad del video</label>
                        <select name="calidad" id="frCalidad">
                            <?php foreach (FlowCola::CALIDADES as $valor => $c): ?>
                                <option value="<?= $valor ?>" data-aprox="<?= $h($c['aprox']) ?>" <?= ($ult['calidad'] ?? '720p') === $valor ? 'selected' : '' ?>><?= $h($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="ayuda">⏱️ <span id="frCalidadAprox"></span></p>
                    </div>
                </div>
                <p class="ayuda" style="margin-top:12px"><span id="frEtqPrompt">Describe la escena</span> · <span id="frContador">0 / 1200</span></p>
            </div>
        </form>
        <p class="fl-nota solo-proyecto">Se guarda en <b id="frProyectoTxt">tu proyecto</b> · <span id="frAyudaPrompt">Cuanto más detalle (lugar, luz, cámara), mejor sale.</span> Flow puede cometer errores: revisa los resultados.<?php if ($retencionDias > 0): ?><br>⏳ <b>Los videos e imágenes se guardan <?= (int) $retencionDias ?> días</b> — descárgalos antes, luego se borran.<?php endif; ?></p>
    </main>

    <script>
    /* El "+" del compositor: subir una imagen (con progreso) y usarla como
       inicio/fin del video o referencia de la imagen. Reusa space_subir. */
    (function () {
        const form = document.getElementById('frForm');
        if (!form) return;
        const FR_BASE = <?= json_encode($frBase) ?>;
        const FR_MEDIA = <?= json_encode($frMedia) ?>;
        const CSRF = (form.querySelector('[name=csrf]') || {}).value || '';
        const masBtn = document.getElementById('frMasBtn');
        const panel = document.getElementById('frRecursos');
        const fileInput = document.getElementById('frRecFile');
        const prog = document.getElementById('frRecProg');
        const barra = document.getElementById('frRecBarra');
        const progTxt = document.getElementById('frRecProgTxt');
        const lista = document.getElementById('frRecLista');
        const entradasInput = document.getElementById('frEntradas');
        const tipoInput = document.getElementById('frTipo');
        const masN = document.getElementById('frMasN');
        if (!masBtn || !panel) return;
        let recursos = [];

        function tipoActual() { return tipoInput && tipoInput.value === 'imagen' ? 'imagen' : 'video'; }

        function construir() {
            const e = {};
            recursos.forEach(function (r) {
                if (r.rol === 'inicio') e.inicio = r.ruta;
                else if (r.rol === 'fin') e.fin = r.ruta;
                else { (e.referencias = e.referencias || []).push(r.ruta); }
            });
            entradasInput.value = Object.keys(e).length ? JSON.stringify(e) : '';
            if (masN) { masN.textContent = recursos.length; masN.hidden = recursos.length === 0; }
            masBtn.classList.toggle('con', recursos.length > 0);
        }
        // Limpiar las imágenes adjuntas tras enviar (lo usa el envío por AJAX)
        window.frLimpiarAdjuntos = function () { recursos = []; construir(); pintar(); };
        // Repintar los roles cuando se cambia Video <-> Imagen (los roles válidos cambian)
        window.frRepintarRecursos = function () { if (recursos.length) pintar(); };

        function pintar() {
            lista.innerHTML = '';
            // En Imagen solo tiene sentido "Referencia" (Inicio/Fin son fotogramas de
            // video). En Video: Inicio, Fin o Referencia.
            const opciones = tipoActual() === 'imagen'
                ? [['referencia', 'Referencia']]
                : [['inicio', 'Inicio del video'], ['fin', 'Fin del video'], ['referencia', 'Referencia']];
            recursos.forEach(function (r, i) {
                // Si el rol ya no encaja con el modo (p. ej. venía "inicio" y pasó a Imagen), se ajusta
                if (!opciones.some(function (o) { return o[0] === r.rol; })) r.rol = opciones[0][0];
                const item = document.createElement('div');
                item.className = 'rec-item';
                const img = document.createElement('img');
                img.src = /^https?:\/\//i.test(r.ruta) ? r.ruta : (FR_MEDIA + r.ruta); img.alt = '';
                const sel = document.createElement('select');
                sel.className = 'rec-rol';
                sel.disabled = opciones.length === 1; // en Imagen no hay nada que elegir
                opciones.forEach(function (o) {
                    const op = document.createElement('option');
                    op.value = o[0]; op.textContent = o[1];
                    if (r.rol === o[0]) op.selected = true;
                    sel.appendChild(op);
                });
                sel.addEventListener('change', function () {
                    recursos[i].rol = sel.value;
                    // Solo puede haber UN Inicio y UN Fin: si otra imagen ya lo tenía, pasa a Referencia
                    if (sel.value === 'inicio' || sel.value === 'fin') {
                        recursos.forEach(function (o, j) { if (j !== i && o.rol === sel.value) o.rol = 'referencia'; });
                    }
                    pintar();
                });
                const x = document.createElement('button');
                x.type = 'button'; x.className = 'rec-x'; x.textContent = '✕'; x.title = 'Quitar';
                x.addEventListener('click', function () { recursos.splice(i, 1); pintar(); });
                item.appendChild(img); item.appendChild(sel); item.appendChild(x);
                lista.appendChild(item);
            });
            construir();
        }

        function subir(file) {
            if (!file) return;
            prog.hidden = false; barra.style.width = '0%';
            progTxt.textContent = 'Subiendo ' + file.name + '… 0%';
            const fd = new FormData();
            fd.append('csrf', CSRF); fd.append('accion', 'space_subir'); fd.append('imagen', file);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', FR_BASE);
            xhr.upload.addEventListener('progress', function (ev) {
                if (ev.lengthComputable) {
                    const p = Math.round(ev.loaded / ev.total * 100);
                    barra.style.width = p + '%';
                    progTxt.textContent = 'Subiendo ' + file.name + '… ' + p + '%';
                }
            });
            xhr.addEventListener('load', function () {
                let j = null; try { j = JSON.parse(xhr.responseText); } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300 && j && j.ruta) {
                    barra.style.width = '100%';
                    setTimeout(function () { prog.hidden = true; }, 400);
                    recursos.push({ ruta: j.ruta, rol: tipoActual() === 'imagen' ? 'referencia' : 'inicio' });
                    pintar();
                } else {
                    progTxt.textContent = (j && j.error) || 'No se pudo subir la imagen.';
                    barra.style.width = '0%';
                    setTimeout(function () { prog.hidden = true; }, 3500);
                }
            });
            xhr.addEventListener('error', function () {
                progTxt.textContent = 'Error de red al subir.';
                setTimeout(function () { prog.hidden = true; }, 3500);
            });
            xhr.send(fd);
        }

        masBtn.addEventListener('click', function (e) { e.stopPropagation(); panel.classList.toggle('ver'); });
        fileInput.addEventListener('change', function (e) { const f = e.target.files[0]; subir(f); e.target.value = ''; });
        const listo = document.getElementById('frRecListo');
        if (listo) listo.addEventListener('click', function () { panel.classList.remove('ver'); });
        document.addEventListener('click', function (e) {
            const usar = (e.target && e.target.closest) ? e.target.closest('[data-usarvideo]') : null;
            if (usar) {
                // Botón "🎬 Usar para video" (funciona en táctil): adjunta la imagen como inicio y pasa a Video
                const ref = usar.getAttribute('data-usarvideo');
                const segVideo = document.querySelector('.fr .seg[data-grupo="tipo"] button[data-valor="video"]');
                if (segVideo) segVideo.click();
                if (!recursos.some(function (r) { return r.ruta === ref; })) { recursos.push({ ruta: ref, rol: 'inicio' }); pintar(); }
                panel.classList.add('ver');
                return;
            }
            if (panel.classList.contains('ver') && !panel.contains(e.target) && !masBtn.contains(e.target) && e.target !== masBtn) {
                panel.classList.remove('ver');
            }
        });

        // Arrastrar una imagen generada al cuadro de crear -> se usa como INICIO del video
        // (imagen + prompt = video). Se adjunta la imagen, no se pega la URL.
        let arrastrando = null;
        document.addEventListener('dragstart', function (e) {
            const el = (e.target && e.target.closest) ? e.target.closest('.fr .job img[data-ref]') : null;
            arrastrando = el ? el.getAttribute('data-ref') : null;
            if (arrastrando) { try { e.dataTransfer.effectAllowed = 'copy'; e.dataTransfer.setData('text/plain', ''); } catch (_) {} }
        });
        const compositor = document.querySelector('.fr .st-compositor');
        if (compositor) {
            compositor.addEventListener('dragover', function (e) { if (arrastrando) { e.preventDefault(); compositor.classList.add('soltando'); } });
            compositor.addEventListener('dragleave', function (e) { if (!compositor.contains(e.relatedTarget)) compositor.classList.remove('soltando'); });
            compositor.addEventListener('drop', function (e) {
                if (!arrastrando) return;
                e.preventDefault();
                compositor.classList.remove('soltando');
                if (!recursos.some(function (r) { return r.ruta === arrastrando; })) {
                    recursos.push({ ruta: arrastrando, rol: tipoActual() === 'imagen' ? 'referencia' : 'inicio' });
                    pintar();
                    panel.classList.add('ver'); // abre el panel para que vea la imagen adjunta
                }
                arrastrando = null;
            });
        }
    })();
    </script>
    </div>

    <!-- Conexión con la EXTENSIÓN del navegador: pega aquí el token que la extensión
         SnaveCloud genera en tu Chrome (botón "Copiar"). Vale para clientes y accesos. -->
    <div class="conexion" id="conexion-ext">
        <form method="post" class="tarjeta google" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="accion" value="fijar_token">
            <div class="cab">
                <div><h2><span class="ms">extension</span> Conexión con la extensión</h2>
                    <p>Pega el token de la extensión (botón "Copiar"). Puedes pegar <b>VARIOS</b>, uno por cada perfil/navegador: se van <b>sumando</b> (no se reemplazan). Abajo, en "Extensiones conectadas", ves todos.</p></div>
                <span class="estado-api <?= FlowCola::hayToken($conn, $user_id) ? 'on' : 'off' ?>">
                    <span class="punto"></span><?= FlowCola::hayToken($conn, $user_id) ? 'Activada' : 'Sin activar' ?>
                </span>
            </div>
            <div class="cuerpo">
                <label class="l">Token de la extensión (48 caracteres)</label>
                <input type="text" name="token" placeholder="Pega aquí el token de la extensión" autocomplete="off" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace">
                <button type="submit" class="btn" style="margin-top:10px;padding:10px 16px;border:0;border-radius:10px;background:#6d28d9;color:#fff;font-weight:700;cursor:pointer;">Guardar token</button>
            </div>
        </form>

        <?php if ($esAdmin): $tokensConn = FlowCola::tokensConectados($conn); ?>
        <!-- Lista de extensiones con token: para ver qué cuentas están conectadas -->
        <div class="tarjeta" style="margin-top:12px">
            <div class="cab">
                <div><h2><span class="ms">devices</span> Extensiones conectadas</h2>
                    <p>Cada cuenta con su token y si su extensión está en línea ahora.</p></div>
                <span class="estado-api on"><span class="punto"></span><?= count(array_filter($tokensConn, fn($t) => $t['conectada'])) ?> en línea</span>
            </div>
            <div class="cuerpo">
                <?php if (!$tokensConn): ?>
                    <p class="mini" style="margin:0">Ninguna cuenta tiene token todavía. Pega el token de cada extensión en su cuenta (arriba, o en "Accesos").</p>
                <?php else: ?>
                <div style="overflow-x:auto">
                <table style="width:100%; border-collapse:collapse; font-size:.86rem">
                    <thead><tr style="text-align:left; color:var(--suave); font-size:.75rem">
                        <th style="padding:6px">Cuenta (panel)</th><th>Cuenta de Flow</th><th>Tipo</th><th>Estado</th><th>Versión</th><th>Última señal</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tokensConn as $t): ?>
                        <tr style="border-top:1px solid var(--borde)">
                            <td style="padding:8px 6px; font-weight:600"><?= $h($t['cuenta']) ?></td>
                            <td style="font-weight:600; color:var(--morado)"><?= $t['cuentaFlow'] ? $h($t['cuentaFlow']) : '<span class="mini" style="color:var(--suave)">— detectando…</span>' ?></td>
                            <td class="mini"><?= $h($t['tipo']) ?></td>
                            <td>
                                <?php if ($t['conectada']): ?><span class="estado hecho">🟢 <?= $t['activa'] ? 'Procesando' : 'Conectada' ?></span>
                                <?php elseif ($t['nunca']): ?><span class="estado error">⚪ Nunca se conectó</span>
                                <?php else: ?><span class="estado error">🔴 Desconectada</span><?php endif; ?>
                            </td>
                            <td class="mini"><?= $t['version'] ? 'v' . $h($t['version']) : '—' ?></td>
                            <td class="mini"><?= $t['hace'] === null ? 'nunca' : ($t['hace'] < 60 ? 'hace ' . (int) $t['hace'] . ' s' : 'hace ' . (int) round($t['hace'] / 60) . ' min') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($esAdmin): ?>
    <!-- Conexión con Núcleo SNAVE (solo admin): el token y la cuenta de Google Flow
         con los que SnaveCloud genera los pedidos de todos. Los clientes no ven esto. -->
    <div class="conexion" id="conexion">
        <form method="post" class="tarjeta google" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="accion" value="api_guardar">
            <div class="cab">
                <div><h2><span class="ms">key</span> Conexión con Google Flow (Núcleo SNAVE)</h2>
                    <p>Con este token SnaveCloud genera los videos e imágenes de todos tus clientes. Solo tú lo ves.</p></div>
                <span class="estado-api <?= !$apiEstado['configurada'] ? 'off' : ($apiEstado['pausa'] ? 'pausa' : 'on') ?>" id="frExtEstado">
                    <span class="punto"></span><?= !$apiEstado['configurada'] ? 'Sin conexión' : ($apiEstado['pausa'] ? 'En pausa' : 'Conectado') ?>
                </span>
            </div>
            <div class="cuerpo">
                <label class="l">Token de Núcleo SNAVE</label>
                <?php if ($apiEstado['tiene_token']): ?>
                    <div class="token">user:•••••••••••••••••••••••••••<?= $h($apiEstado['final']) ?></div>
                <?php endif; ?>
                <input type="password" name="api_token" placeholder="<?= $apiEstado['tiene_token'] ? 'Pega otro para cambiarlo (vacío = se queda el actual)' : 'Pega aquí tu token (empieza por user:…)' ?>" autocomplete="new-password" spellcheck="false">
                <label class="chk caja" style="margin-top:12px">
                    <input type="checkbox" name="api_rotar" value="1" <?= $apiEstado['rotar'] ? 'checked' : '' ?>>
                    <span><b>Rotar entre todas mis cuentas conectadas (round-robin)</b><br>
                        <span class="mini">Reparte los pedidos, uno a cada cuenta por turno, entre todas las cuentas de Google Flow conectadas y sanas en Núcleo SNAVE. Si una se queda sin créditos o se desconecta, el pedido salta solo a otra.</span></span>
                </label>
                <?php if ($apiEstado['tiene_token']): ?>
                <div class="cuentas-conectadas">
                    <div class="cc-tit">Cuentas conectadas en Núcleo SNAVE <span class="cc-n"><?= count($apiCuentas) ?></span></div>
                    <?php if (!$apiCuentas): ?>
                        <div class="cc-vacio">No se detectan cuentas (token inválido o sin cuentas conectadas). Pulsa "Probar la conexión".</div>
                    <?php else: foreach ($apiCuentas as $c): ?>
                        <div class="cc-item <?= $c['sana'] ? 'ok' : 'mal' ?>">
                            <span class="cc-punto"></span>
                            <span class="cc-mail"><?= $h($c['email']) ?></span>
                            <span class="cc-salud"><?= $c['sana'] ? 'Lista' : $h($c['salud']) ?></span>
                        </div>
                    <?php endforeach; endif; ?>
                    <?php if ($apiCuentas && count(array_filter($apiCuentas, function ($c) { return !$c['sana']; }))): ?>
                        <div class="cc-aviso">Las cuentas con aviso no reciben pedidos hasta que las repares en Núcleo SNAVE.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <label class="l" style="margin-top:12px">Email de la cuenta de Google Flow <?= $apiEstado['rotar'] ? '(opcional: cuenta de respaldo)' : '' ?></label>
                <input type="email" name="api_email" value="<?= $h($apiEstado['email']) ?>" placeholder="<?= $apiEstado['rotar'] ? 'Opcional cuando rotas (respaldo si Núcleo SNAVE no devuelve cuentas)' : 'tu-cuenta@gmail.com' ?>" autocomplete="off" spellcheck="false">
                <p class="ayuda">Saca el token en <a href="https://useapi.net/docs/start-here/setup-useapi" target="_blank" rel="noopener">Núcleo SNAVE</a> y conecta allí tus cuentas de Google Flow (<a href="https://useapi.net/docs/start-here/setup-google-flow" target="_blank" rel="noopener">guía</a>). <b>Todo consume la suscripción/créditos de Flow</b> de esas cuentas. Por eso conviene el límite diario por cliente. Con la rotación activada no hace falta un email fijo: SnaveCloud usa las cuentas que Núcleo SNAVE tenga conectadas.</p>
                <?php if ($apiEstado['error'] !== ''): ?>
                    <div class="aviso err" style="margin:10px 0 0; font-size:.82rem; display:flex; align-items:center; gap:10px; justify-content:space-between; flex-wrap:wrap">
                        <span>⚠️ Último aviso de Núcleo SNAVE: <?= $h($apiEstado['error']) ?><?php if (!empty($apiEstado['error_hace'])): ?> <span style="opacity:.7">(<?= $h($apiEstado['error_hace']) ?>)</span><?php endif; ?></span>
                        <button type="submit" name="accion" value="api_limpiar_error" formnovalidate style="flex:0 0 auto; background:transparent; border:1px solid currentColor; color:inherit; border-radius:999px; padding:4px 12px; font-size:.75rem; font-weight:700; cursor:pointer; white-space:nowrap">✕ Descartar</button>
                    </div>
                <?php endif; ?>
                <div class="cfg" style="grid-template-columns:1fr 1fr; margin-top:14px">
                    <div><label>Modelo de video</label>
                        <input type="text" name="api_video" id="frModeloVideo" list="frListaVideo" value="<?= $h($apiEstado['modelos']['video']) ?>" spellcheck="false">
                        <datalist id="frListaVideo"><?php foreach (FlowApi::MODELOS['video'] as $idM => $etqM): ?><option value="<?= $h($idM) ?>"><?= $h($etqM) ?></option><?php endforeach; ?></datalist>
                        <div style="display:flex; gap:6px; margin-top:6px; flex-wrap:wrap">
                            <button type="button" class="fr-modo-btn rapido" data-modelo="veo-3.1-fast" title="Entrega en ~20-40s · usa créditos de las cuentas">⚡ Rápido (créditos)</button>
                            <button type="button" class="fr-modo-btn" data-modelo="veo-3.1-lite-low-priority" title="Gratis pero lento (baja prioridad de Google)">💤 Gratis (lento)</button>
                        </div></div>
                    <div><label>Modelo de imagen</label>
                        <input type="text" name="api_imagen" list="frListaImagen" value="<?= $h($apiEstado['modelos']['imagen']) ?>" spellcheck="false">
                        <datalist id="frListaImagen"><?php foreach (FlowApi::MODELOS['imagen'] as $idM => $etqM): ?><option value="<?= $h($idM) ?>"><?= $h($etqM) ?></option><?php endforeach; ?></datalist></div>
                </div>
                <p class="ayuda"><b>Imágenes (Nano Banana): ilimitadas y gratis</b> — sin límite de "a la vez". <b>Videos:</b> "Lite baja prioridad" es <b>gratis</b>; Fast/Quality/Omni usan <b>créditos</b>. Si eliges uno de créditos: se genera en la cuenta que tenga créditos; si <b>ninguna cuenta tiene créditos</b>, ese pedido se <b>rechaza</b> (no cae al gratis). El modelo gratis siempre funciona. Veo 3.1 acepta "inicio" y "fin"; las imágenes aceptan referencias.</p>
                <div class="fila-api">
                    <label class="chk"><input type="checkbox" name="api_pausa" value="1" <?= $apiEstado['pausa'] ? 'checked' : '' ?>> Pausar la generación (los pedidos esperan)</label>
                    <?php if ($apiEstado['tiene_token']): ?>
                        <label class="chk peligro"><input type="checkbox" name="api_quitar" value="1" onchange="if (this.checked && !confirm('¿Quitar la conexión? Se deja de generar para todos.')) this.checked = false"> Quitar la conexión</label>
                    <?php endif; ?>
                </div>
                <div class="fila-api">
                    <button class="btn-verde" style="padding:11px 22px">Guardar</button>
                    <?php if ($apiEstado['configurada']): ?>
                        <button class="btn-borde" type="submit" name="accion" value="api_probar" formnovalidate>Probar la conexión</button>
                    <?php endif; ?>
                </div>
                <?php
                // URL del cron: pegándola en Hostinger (cada 1 min) la cola avanza sola,
                // aunque nadie tenga la página abierta (igual de rápido que en local).
                $cronEsq = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $cronUrl = $cronEsq . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio') . '/dashboard/flow-cron.php?k=' . FlowApi::cronToken($conn);
                ?>
                <div style="margin-top:16px;padding:12px 14px;border:1px dashed var(--borde);border-radius:12px;background:rgba(109,0,235,.05)">
                    <div style="font-weight:700;font-size:.85rem;margin-bottom:4px">⏱️ Acelerar en el hosting (cron, opcional pero recomendado)</div>
                    <div style="font-size:.8rem;color:var(--suave);margin-bottom:8px">
                        Pega esta URL en Hostinger → <b>Avanzado → Trabajos Cron</b>, cada <b>1 minuto</b>. Así la cola genera sola aunque nadie tenga la página abierta. No la compartas: lleva tu clave.
                    </div>
                    <input type="text" readonly onclick="this.select()" value="<?= $h($cronUrl) ?>"
                           style="width:100%;box-sizing:border-box;font-family:monospace;font-size:.78rem;padding:8px 10px;border:1px solid var(--borde);border-radius:8px;background:var(--panel);color:var(--texto)">
                </div>
            </div>
        </form>

        <!-- Interruptor: desactivar el servidor (sin borrar el token) para usar SOLO la extensión -->
        <form method="post" class="tarjeta" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="accion" value="api_toggle">
            <input type="hidden" name="api_desactivada" value="<?= $apiEstado['desactivada'] ? '0' : '1' ?>">
            <div class="cab">
                <div><h2><span class="ms">power_settings_new</span> Generación por servidor</h2>
                    <p><?= $apiEstado['desactivada'] ? 'DESACTIVADO: ahora genera solo la extensión.' : 'ACTIVO: genera el servidor (la extensión queda bloqueada).' ?></p></div>
                <span class="estado-api <?= $apiEstado['desactivada'] ? 'off' : 'on' ?>">
                    <span class="punto"></span><?= $apiEstado['desactivada'] ? 'Servidor apagado' : 'Servidor encendido' ?>
                </span>
            </div>
            <div class="cuerpo">
                <button type="submit" formnovalidate style="padding:11px 20px;border:0;border-radius:10px;font-weight:700;cursor:pointer;color:#fff;<?= $apiEstado['desactivada'] ? 'background:#059669' : 'background:#b91c1c' ?>">
                    <?= $apiEstado['desactivada'] ? '⚡ Reactivar servidor (bloquea la extensión)' : '🔌 Desactivar servidor (usar solo la extensión)' ?>
                </button>
            </div>
        </form>

        <!-- Columna derecha: Reglas y, debajo, el monitor de Progreso (llena el hueco) -->
        <div class="fr-col-der">
        <form method="post" class="tarjeta">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="accion" value="config">
            <div class="cab">
                <div><h2><span class="ms">tune</span> Reglas</h2><p>Valen para todos los pedidos.</p></div>
                <button class="btn-morado">Guardar</button>
            </div>
            <div class="cuerpo">
                <label class="chk caja">
                    <input type="checkbox" name="clientes_activo" value="1" <?= $config['clientes_activo'] ? 'checked' : '' ?>>
                    <span><b>Clientes y partners del panel pueden usar Flow desde el dashboard</b><br>
                        <span class="mini">Generan ilimitado en total, con el máximo "a la vez por dispositivo" de abajo. El acceso por <b>/flow</b> se gobierna con el <b>servicio del catálogo</b> (más abajo).</span></span>
                </label>
                <div class="cfg" style="grid-template-columns:1fr 1fr">
                    <div><label>Videos a la vez POR CUENTA (1-5)</label><input type="number" name="simultaneos" min="1" max="5" value="<?= (int) $config['simultaneos'] ?>"><span class="mini">Cada cuenta de Google Flow genera como máximo estos a la vez. El total en paralelo = este número × cuentas conectadas (ej. 3 × 3 cuentas = 9). La rotación reparte.</span></div>
                    <div><label>Dispositivos por cliente en /flow (0 = ilimitado)</label><input type="number" name="dispositivos_max" min="0" max="20" value="<?= (int) $config['dispositivos_max'] ?>"><span class="mini">Si un cliente entra desde más equipos que esto, se le avisa que su cuenta puede bloquearse (anti-reventa). El admin del panel no tiene límite.</span></div>
                    <div><label>Espera máxima de un video (min)</label><input type="number" name="espera_video_min" min="2" max="30" value="<?= (int) $config['espera_video_min'] ?>"></div>
                    <div><label>Espera máxima de una imagen (min)</label><input type="number" name="espera_imagen_min" min="1" max="15" value="<?= (int) $config['espera_imagen_min'] ?>"></div>
                    <div><label>Intentos por pedido</label><input type="number" name="max_intentos" min="1" max="10" value="<?= (int) $config['max_intentos'] ?>"><span class="mini">⚠️ Cada reintento usa 1 captcha (cuesta). Con 2-3 es suficiente; más solo gasta captcha de más. Los fallos por política o captcha ya NO reintentan.</span></div>
                </div>
                <p class="mini" style="margin:12px 0 0">Si Google está saturado o se pasa la cuota, el pedido vuelve solo a la espera hasta agotar los intentos.
                    Si pasa la espera máxima sin terminar, queda como fallido y se puede pulsar "Reintentar".</p>
            </div>
        </form>

        <!-- Monitor en vivo, DEBAJO de Reglas y en su misma columna (llena el hueco) -->
        <!-- Tarjeta de acceso (como "Logs de Pasarela"): abre el monitor en grande -->
        <div class="tarjeta fr-card-acceso" id="progreso">
            <div class="cab"><div><h2><span class="ms">monitoring</span> Progreso y resultado</h2>
                <p>Todo el proceso de cada video, de principio a fin — en vivo y con su historial.</p></div>
                <span class="vivo"><i></i>en vivo</span>
            </div>
            <div class="cuerpo">
                <button type="button" class="fr-abrir-monitor" id="frAbrirMonitor"><span class="ms">travel_explore</span> Examinar proceso</button>
            </div>
        </div>
        </div><!-- /.fr-col-der -->
    </div><!-- /.conexion -->

    <!-- Monitor en grande: terminal en vivo + historial (se abre con "Examinar proceso") -->
    <div class="fr-proc-modal fr-mon-modal" id="frMonitorModal" hidden>
        <div class="fr-proc-box fr-mon-box">
            <div class="fr-term-head">
                <div class="fr-term-tit"><span class="ms">monitoring</span> Progreso y resultado <span class="vivo"><i></i>en vivo</span></div>
                <div class="fr-term-acc">
                    <button type="button" class="fr-term-btn alt" id="frExaminar" title="Ver todo como JSON"><span class="ms">data_object</span> JSON</button>
                    <button type="button" class="fr-term-btn" id="frProgRecargar" title="Actualizar ahora"><span class="ms">refresh</span> Recargar</button>
                    <button type="button" class="fr-proc-x" id="frMonitorCerrar" title="Cerrar"><span class="ms">close</span></button>
                </div>
            </div>
            <div id="frProgreso" class="fr-term-live">Esperando…</div>
            <div class="fr-term-sep"><span class="ms">history</span> Historial del proceso <span class="fr-hist-n" id="frHistN">0</span></div>
            <div id="frHistorial" class="fr-hist"></div>
        </div>
    </div>

    <!-- Modal: línea de tiempo completa de UN video (al hacer click en el historial) -->
    <div class="fr-proc-modal" id="frProcModal" hidden>
        <div class="fr-proc-box">
            <div class="fr-proc-head">
                <div class="fr-proc-tit"><span class="ms" id="frProcIco">movie</span> <span id="frProcPrompt">—</span></div>
                <button type="button" class="fr-proc-x" id="frProcCerrar" title="Cerrar"><span class="ms">close</span></button>
            </div>
            <div class="fr-proc-sub" id="frProcSub"></div>
            <div class="fr-proc-steps" id="frProcSteps"></div>
        </div>
    </div>

    <!-- Visor grande: proceso completo de TODOS los videos (de principio a fin) -->
    <div class="fr-proc-modal fr-log-modal" id="frLogModal" hidden>
        <div class="fr-proc-box fr-log-box">
            <div class="fr-proc-head">
                <div class="fr-proc-tit"><span class="ms">travel_explore</span> Proceso de todos los videos <span class="fr-log-n" id="frLogN"></span></div>
                <div class="fr-term-acc">
                    <button type="button" class="fr-term-btn alt" id="frLogCopiar"><span class="ms">content_copy</span> Copiar</button>
                    <button type="button" class="fr-term-btn alt" id="frLogDescargar"><span class="ms">download</span> Descargar .json</button>
                    <button type="button" class="fr-term-btn" id="frLogRecargar"><span class="ms">refresh</span> Recargar</button>
                    <button type="button" class="fr-proc-x" id="frLogCerrar" title="Cerrar"><span class="ms">close</span></button>
                </div>
            </div>
            <div class="fr-log-body" id="frLogBody"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($esAdmin): ?>
    <!-- Accesos de la página pública /flow: correo + contraseña que crea el admin -->
    <div class="tarjeta" id="accesos" style="margin-top:18px">
        <div class="cab">
            <div><h2>🌐 Accesos a la página pública</h2>
                <p>Quien tenga uno entra en <a href="<?= $h($urlPublica) ?>" target="_blank" rel="noopener" style="color:var(--morado); font-weight:700"><?= $h($urlPublica) ?></a>
                   con su correo y contraseña, sin cuenta en el panel. Lo que pida se genera con tu conexión de Google Flow.</p></div>
        </div>
        <div class="cuerpo">
            <form method="post" class="cfg" style="grid-template-columns:1fr 1fr 1fr auto; align-items:end; margin-bottom:14px">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="accion" value="acceso_crear">
                <div><label>Nombre (opcional)</label><input type="text" name="nombre" maxlength="120" placeholder="Juan Pérez"></div>
                <div><label>Correo</label><input type="email" name="correo" required placeholder="juan@correo.com"></div>
                <div><label>Contraseña (mín. 8)</label><input type="text" name="clave" required minlength="8" autocomplete="new-password"></div>
                <button class="btn-morado" style="padding:10px 16px">➕ Crear acceso</button>
            </form>
            <?php if (!$accesos): ?>
                <p class="mini" style="margin:0">Aún no hay accesos.</p>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table style="width:100%; border-collapse:collapse; font-size:.86rem">
                <thead><tr style="text-align:left; color:var(--suave); font-size:.75rem">
                    <th style="padding:6px">Correo</th><th>Nombre</th><th>Estado</th><th>Pedidos</th><th>Último acceso</th><th>Token extensión</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($accesos as $a): ?>
                    <tr style="border-top:1px solid var(--borde)">
                        <td style="padding:8px 6px; font-weight:600"><?= $h($a['correo']) ?></td>
                        <td><?= $h($a['nombre']) ?></td>
                        <td><?= (int) $a['activo'] ? '<span class="estado hecho">Activo</span>' : '<span class="estado error">Desactivado</span>' ?></td>
                        <td><?= (int) $a['pedidos'] ?></td>
                        <td class="mini"><?= $a['ultimo_acceso'] ? $h(date('d/m/Y H:i', strtotime($a['ultimo_acceso']))) : 'nunca' ?></td>
                        <td>
                            <?php $tieneTok = FlowCola::hayToken($conn, FlowCola::uidDeAcceso($a['id'])); ?>
                            <form method="post" style="display:flex; gap:4px; align-items:center">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="accion" value="acceso_token">
                                <input type="hidden" name="acceso_id" value="<?= (int) $a['id'] ?>">
                                <input type="text" name="token" placeholder="<?= $tieneTok ? 'Pega otro para cambiarlo' : 'Pega el token (48)' ?>" style="width:150px; font-family:monospace; font-size:.72rem; padding:4px 6px; border:1px solid var(--borde); border-radius:6px; background:var(--panel); color:var(--texto)">
                                <button class="btn-borde" style="padding:5px 8px"><?= $tieneTok ? '💾' : 'Guardar' ?></button>
                                <?php if ($tieneTok): ?><span class="estado hecho" title="Tiene token">✅</span><?php endif; ?>
                            </form>
                        </td>
                        <td style="white-space:nowrap; text-align:right">
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="accion" value="acceso_estado">
                                <input type="hidden" name="acceso_id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="activo" value="<?= (int) $a['activo'] ? 0 : 1 ?>">
                                <button class="btn-borde" style="padding:5px 10px"><?= (int) $a['activo'] ? '⏸ Desactivar' : '▶ Activar' ?></button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="const c = prompt('Nueva contraseña para ' + <?= $h(json_encode($a['correo'])) ?> + ' (mínimo 8 caracteres):'); if (!c) return false; this.clave.value = c; return true;">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="accion" value="acceso_clave">
                                <input type="hidden" name="acceso_id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="clave">
                                <button class="btn-borde" style="padding:5px 10px">🔑 Contraseña</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('¿Borrar el acceso de ' + <?= $h(json_encode($a['correo'])) ?> + '? Sus pedidos y videos se quedan aquí.')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="accion" value="acceso_borrar">
                                <input type="hidden" name="acceso_id" value="<?= (int) $a['id'] ?>">
                                <button class="btn-borde" style="padding:5px 10px; color:var(--rojo)">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Flow como SERVICIO por cliente: habilitar a cuentas del panel (entran en /flow con su cuenta) -->
    <div class="tarjeta" id="clientes-flow" style="margin-top:18px">
        <div class="cab">
            <div><h2>🧩 Flow como servicio del catálogo</h2>
                <p>Elige qué servicio del catálogo es "Flow". Quien lo tenga <b>activo</b> (comprado o asignado) entra a <a href="<?= $h($urlPublica) ?>" target="_blank" rel="noopener" style="font-weight:700"><?= $h($urlPublica) ?></a> con <b>su cuenta de SnaveCloud</b>. Cada cliente ve solo lo suyo.</p></div>
        </div>
        <div class="cuerpo">
            <form method="post" class="cfg" style="grid-template-columns:1fr auto; align-items:end; margin-bottom:8px">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="accion" value="flow_servicio">
                <div><label>Servicio de Flow en el catálogo</label>
                    <select name="flow_servicio_id">
                        <option value="0">— Ninguno (nadie tiene Flow) —</option>
                        <?php foreach ($catalogoServicios as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= $flowServicioId === (int) $s['id'] ? 'selected' : '' ?>><?= $h($s['nombre']) ?> (#<?= (int) $s['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-verde" style="padding:10px 16px">Guardar</button>
            </form>
            <p class="ayuda" style="margin:0">Crea el servicio "Flow" en <a href="servicios">Servicios</a> y selecciónalo aquí. La <b>lista de clientes</b> y la asignación se gestionan en Servicios → ese servicio → <b>"Ver usuarios"</b> / "Asignar/Compra manual". Quien tenga ese servicio <b>activo</b> entra a /flow con su cuenta.</p>
        </div>
    </div>

    <!-- Almacenamiento en Cloudflare R2 (bucket público). Los nuevos archivos se suben ahí. -->
    <div class="tarjeta" id="r2" style="margin-top:18px">
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="accion" value="r2_guardar">
            <div class="cab">
                <div><h2>☁️ Almacenamiento (Cloudflare R2)</h2>
                    <p>Guarda los videos e imágenes <b>nuevos</b> en tu bucket público de Cloudflare R2 (en vez del disco del servidor). Los que ya tienes se quedan en local.</p></div>
                <span class="estado-api <?= ($r2Estado && $r2Estado['activo']) ? 'on' : 'off' ?>">
                    <span class="punto"></span><?= ($r2Estado && $r2Estado['activo']) ? 'Conectado' : 'Sin configurar' ?>
                </span>
            </div>
            <div class="cuerpo">
                <?php if ($r2Uso):
                    $usado = (int) $r2Uso['bytes']; $limite = 10 * 1073741824; $pct = min(100, $limite > 0 ? round($usado / $limite * 100, 1) : 0);
                ?>
                <div style="background:var(--panel2); border:1px solid var(--borde); border-radius:12px; padding:12px; margin-bottom:14px">
                    <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:.85rem; margin-bottom:8px"><span style="color:var(--suave)">Almacenamiento usado</span><b><?= $h($fmtBytes($usado)) ?> / 10 GB gratis</b></div>
                    <div style="height:9px; background:var(--borde); border-radius:999px; overflow:hidden"><div style="height:100%; width:<?= $pct ?>%; background:<?= $pct > 90 ? 'var(--rojo)' : 'var(--morado-vivo)' ?>"></div></div>
                    <div style="font-size:.76rem; color:var(--suave); margin-top:6px"><?= (int) $r2Uso['objetos'] ?> archivos · <?= $pct ?>% usado · quedan <?= $h($fmtBytes(max(0, $limite - $usado))) ?> del tramo gratis</div>
                </div>
                <?php endif; ?>
                <div class="cfg" style="grid-template-columns:1fr 1fr">
                    <div><label>Account ID</label><input type="text" name="r2_account" value="<?= $h($r2Estado['account'] ?? '') ?>" placeholder="32 caracteres hex" spellcheck="false"></div>
                    <div><label>Bucket</label><input type="text" name="r2_bucket" value="<?= $h($r2Estado['bucket'] ?? '') ?>" placeholder="snave-flow" spellcheck="false"></div>
                    <div><label>Access Key ID</label><input type="text" name="r2_access" value="<?= $h($r2Estado['access'] ?? '') ?>" spellcheck="false"></div>
                    <div><label>Secret Access Key <?= ($r2Estado && $r2Estado['tiene_secret']) ? '(guardado — déjalo vacío para no cambiarlo)' : '' ?></label>
                        <input type="password" name="r2_secret" placeholder="<?= ($r2Estado && $r2Estado['tiene_secret']) ? '•••••••• (sin cambios)' : 'Pega el secreto' ?>" autocomplete="new-password" spellcheck="false"></div>
                </div>
                <label class="l" style="margin-top:10px">URL pública del bucket</label>
                <input type="text" name="r2_publicurl" value="<?= $h($r2Estado['publicurl'] ?? '') ?>" placeholder="https://pub-xxxx.r2.dev  o  https://media.tudominio.com" spellcheck="false">
                <p class="ayuda">En Cloudflare: R2 → tu bucket → <b>Settings → Public access</b> (r2.dev) o conecta un <b>dominio propio</b>. El Account ID y las llaves salen de <b>R2 → Manage R2 API Tokens</b> (permiso Object Read & Write).</p>
                <label class="l" style="margin-top:10px">Borrar los videos/imágenes después de (días)</label>
                <input type="number" name="retencion_dias" min="0" max="365" value="<?= $retencionDias > 0 ? (int) $retencionDias : 5 ?>">
                <p class="ayuda">A los N días, el archivo se borra de R2 (o del servidor) y el pedido desaparece. <b>0 = no borrar nunca.</b> La limpieza corre sola cada hora.</p>
                <div class="fila-api">
                    <button class="btn-verde" style="padding:11px 22px">Guardar</button>
                    <?php if ($r2Estado && $r2Estado['activo']): ?>
                        <label class="chk peligro"><input type="checkbox" name="r2_quitar" value="1" onchange="if (this.checked && !confirm('¿Desconectar R2? Los nuevos archivos volverán a guardarse en el servidor.')) this.checked = false"> Desconectar R2</label>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <!-- ================= SPACE: lienzo de nodos (pantalla completa) =================
         Texto -> Imagen / Video. Cada nodo de Imagen o Video crea un pedido
         normal de la cola al pulsar "Generar". Se guarda solo. -->
    <style>
        .fr .sp { position:fixed; inset:0; z-index:2000; background:#09070e; display:flex; flex-direction:column; color:#e3e3e3; }
        .fr .sp[hidden] { display:none; }
        .fr .sp-top { display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid #211a2b; background:#0d0a13; flex-wrap:wrap; }
        .fr .sp-btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 14px; border:0; border-radius:999px; background:#1b1624;
                      color:#e3e3e3; font:inherit; font-size:.85rem; font-weight:500; cursor:pointer; white-space:nowrap; }
        .fr .sp-btn:hover { background:#2c2338; color:#fff; }
        .fr .sp-btn .ms { font-size:18px; }
        .fr .sp-btn.peligro:hover { background:#3a1715; color:#fecaca; }
        .fr .sp-nombre { background:transparent !important; border:1px solid transparent !important; color:#fff !important; font:inherit; font-size:1rem !important;
                         font-weight:600; width:200px !important; padding:6px 10px !important; border-radius:10px !important; }
        .fr .sp-nombre:hover, .fr .sp-nombre:focus { border-color:#352a46 !important; }
        .fr .sp-top select { width:auto !important; height:36px; padding:0 30px 0 12px !important; border-radius:999px !important; background-color:#1b1624 !important; font-size:.85rem; }
        .fr .sp-proy { display:inline-flex; align-items:center; gap:6px; color:#a99dbb; font-size:.8rem; margin:0; }
        .fr .sp-estado { margin-left:auto; font-size:.8rem; color:#8f84a2; }
        .fr .sp-estado.error { color:#f28b82; }
        .fr .sp-lienzo { position:relative; flex:1; overflow:hidden; cursor:grab; touch-action:none;
                         background-color:#09070e; background-image:radial-gradient(#2a2236 1px, transparent 1px); background-size:22px 22px; }
        .fr .sp-lienzo.moviendo { cursor:grabbing; }
        .fr .sp-mundo { position:absolute; left:0; top:0; transform-origin:0 0; }
        .fr .sp-cables { position:absolute; left:0; top:0; width:1px; height:1px; overflow:visible; pointer-events:none; }
        .fr .sp-cables path { fill:none; stroke-width:2.5; pointer-events:stroke; cursor:pointer; }
        .fr .sp-cables path:hover { stroke-width:5; filter:drop-shadow(0 0 4px rgba(242,139,130,.8)); stroke:#f28b82 !important; }
        .fr .sp-cables path.temporal { pointer-events:none; stroke-dasharray:6 5; }
        .fr .sp-ayuda { position:absolute; inset:0; display:grid; place-items:center; text-align:center; color:#786c8c; font-size:.9rem; line-height:1.7; pointer-events:none; }
        .fr .sp-ayuda[hidden] { display:none; }
        .fr .sp-herr { position:absolute; left:16px; top:50%; transform:translateY(-50%); z-index:3; display:flex; flex-direction:column; gap:4px; padding:8px 6px;
                       background:#130f1a; border:1px solid #2a2236; border-radius:16px; box-shadow:0 14px 30px -10px rgba(0,0,0,.8); }
        .fr .sp-herr button { display:flex; flex-direction:column; align-items:center; gap:3px; width:60px; padding:8px 0; border:0; border-radius:12px; background:none;
                              color:#e3e3e3; font:inherit; font-size:.7rem; cursor:pointer; }
        .fr .sp-herr button:hover { background:#241d30; color:#fff; }
        .fr .sp-herr .ms { font-size:22px; }
        .fr .sp-zoom { position:absolute; right:16px; bottom:16px; z-index:3; display:flex; align-items:center; gap:2px; padding:4px; background:#130f1a;
                       border:1px solid #2a2236; border-radius:999px; font-size:.8rem; }
        .fr .sp-zoom button { width:32px; height:32px; border:0; border-radius:50%; background:none; color:#e3e3e3; cursor:pointer; display:grid; place-items:center; }
        .fr .sp-zoom button:hover { background:#241d30; }
        .fr .sp-zoom span { min-width:46px; text-align:center; color:#c6badb; }
        /* Nodos */
        .fr .sp-nodo { position:absolute; width:340px; background:#15111c; border:1px solid #2c2338; border-radius:18px; box-shadow:0 18px 40px -16px rgba(0,0,0,.9);
                       cursor:default; user-select:none; }
        .fr .sp-nodo[data-tipo="texto"] { width:330px; }
        .fr .sp-nodo.elegido { border-color:rgba(255,255,255,.75); box-shadow:0 0 0 1px rgba(255,255,255,.15), 0 18px 40px -16px rgba(0,0,0,.9); }
        .fr .sp-cab { display:flex; align-items:center; gap:10px; padding:12px 12px 10px 16px; cursor:grab; font-size:1rem; font-weight:600; }
        .fr .sp-cab .ms { font-size:18px; color:#c6badb; }
        .fr .sp-cab b { flex:1; font-weight:600; }
        .fr .sp-x { border:0; background:none; color:#8f84a2; cursor:pointer; width:26px; height:26px; border-radius:50%; display:grid; place-items:center; }
        .fr .sp-x:hover { background:#2c2338; color:#f28b82; }
        .fr .sp-cuerpo { padding:4px 14px 14px; display:flex; flex-direction:column; gap:10px; }
        .fr .sp-cuerpo textarea { width:100%; min-height:90px; resize:vertical; background:#0c0912; border:1px solid #2a2236; border-radius:10px; color:#e3e3e3;
                                  font:inherit; font-size:.82rem; padding:8px 10px; outline:none; user-select:text; }
        .fr .sp-cuerpo textarea:focus { border-color:#4a3b5e; }
        .fr .sp-cuerpo textarea:disabled { opacity:.5; }
        .fr .sp-vista { aspect-ratio:16/8; border-radius:12px; background:#07050c; display:grid; place-items:center; overflow:hidden; color:#786c8c; font-size:.78rem;
                        text-align:center; padding:0; position:relative; }
        .fr .sp-nodo.vertical .sp-vista { aspect-ratio:9/16; max-height:300px; margin:0 auto; width:auto; height:300px; }
        .fr .sp-vista img, .fr .sp-vista video { width:100%; height:100%; object-fit:contain; display:block; }
        .fr .sp-vista .msg { padding:10px; display:flex; flex-direction:column; align-items:center; gap:8px; }
        .fr .sp-vista .msg.error { color:#f28b82; }
        .fr .sp-fila { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .fr .sp-gen { display:inline-flex; align-items:center; gap:5px; height:38px; padding:0 16px; border:0; border-radius:999px; background:#f1f1f1; color:#0d0a13;
                      font:inherit; font-size:.88rem; font-weight:500; cursor:pointer; }
        .fr .sp-subir { height:38px; padding:0 15px; border:0; border-radius:999px; background:#2a2236; color:#e3e3e3; font:inherit; font-size:.88rem; font-weight:500; cursor:pointer; }
        .fr .sp-subir:hover { background:#3a2e4c; color:#fff; }
        .fr .sp-refs { display:flex; gap:6px; flex-wrap:wrap; }
        .fr .sp-refs[hidden] { display:none; }
        .fr .sp-ref { position:relative; width:58px; height:58px; border-radius:10px; overflow:hidden; background:#0c0912; border:1px solid #2a2236; }
        .fr .sp-ref img, .fr .sp-ref video { width:100%; height:100%; object-fit:cover; display:block; }
        .fr .sp-ref.vacia { border-style:dashed; }
        .fr .sp-ref small { position:absolute; left:0; right:0; bottom:0; font-size:.55rem; text-align:center; background:rgba(0,0,0,.7); color:#cfe0ff; padding:1px 0; }
        .fr .sp-aviso { font-size:.7rem; line-height:1.4; color:#fde68a; background:rgba(250,204,21,.07); border:1px solid rgba(250,204,21,.25); border-radius:10px; padding:6px 8px; }
        .fr .sp-aviso[hidden] { display:none; }
        .fr .sp-gen:hover { background:#e3e3e3; }
        .fr .sp-gen:disabled { background:#3a2e4c; color:#8f84a2; cursor:default; }
        .fr .sp-fila select { width:auto !important; height:38px; padding:0 30px 0 14px !important; font-size:.88rem; border-radius:12px !important; background-color:#1b1624 !important; border:1px solid #2c2338 !important; }
        .fr .sp-fila a { color:#c6badb; font-size:.75rem; margin-left:auto; text-decoration:none; }
        .fr .sp-fila a:hover { color:#fff; }
        .fr .sp-nota { font-size:.7rem; color:#82769a; }
        /* Puertos: entradas a la izquierda, salidas a la derecha */
        .fr .sp-puerto { position:absolute; display:flex; align-items:center; gap:5px; font-size:.82rem; color:#a99dbb; }
        .fr .sp-puerto i { width:17px; height:17px; border-radius:50%; background:#09070e; border:2.5px solid var(--c); cursor:crosshair; flex:none; }
        .fr .sp-puerto i:hover, .fr .sp-puerto.conectado i { background:var(--c); }
        .fr .sp-puerto.ent { left:-9px; }
        .fr .sp-puerto.ent span { position:absolute; right:22px; white-space:nowrap; }
        .fr .sp-puerto.sal { right:-9px; }
        .fr .sp-puerto.sal i { cursor:grab; }
        .fr .sp-puerto.sal span { position:absolute; left:22px; white-space:nowrap; }
        .fr .sp-puerto.sal[data-t="video"] span, .fr .sp-puerto.sal[data-t="video"] { color:#c9b3ff; }
        /* Mientras arrastras un cable, se iluminan solo las entradas donde encaja */
        .fr .sp-lienzo.lleva-texto .sp-puerto.ent[data-t="texto"] i,
        .fr .sp-lienzo.lleva-imagen .sp-puerto.ent[data-t="imagen"] i { box-shadow:0 0 0 5px color-mix(in srgb, var(--c) 35%, transparent); background:var(--c); }
        @media (max-width:700px) {
            .fr .sp-nombre { width:130px !important; }
            .fr .sp-btn .t { display:none; }
            .fr .sp-herr { top:auto; bottom:16px; left:50%; transform:translateX(-50%); flex-direction:row; }
        }
        /* Colores SNAVE CLOUD */
        .fr .sp-lienzo { background-image:radial-gradient(#33284a 1px, transparent 1px); }
        .fr .sp-top { background:#0f0b16; }
        .fr .sp-gen, .fr .sp-gen:hover { background:linear-gradient(135deg, #00ff88, #00d471); color:#04261a; font-weight:600; }
        .fr .sp-gen:hover { filter:brightness(1.06); }
        .fr .sp-gen:disabled { background:#3a2e4c; color:#8f84a2; filter:none; }
        .fr .sp-nodo.elegido { border-color:#8b3dff; box-shadow:0 0 0 3px rgba(109,0,235,.25), 0 18px 40px -16px rgba(0,0,0,.9); }
        .fr .sp-herr button:hover, .fr .sp-btn:hover, .fr .sp-zoom button:hover { background:rgba(109,0,235,.3); color:#fff; }

        /* ======================================================================
           TEMA CLARO SnaveCloud — TODA la página del dashboard de Flow.
           Acotado a .fr.studio:not(.publico): la página pública /flow sigue oscura.
           No se tocan las miniaturas de video/imagen ni las pastillas que van
           encima de ellas (se leen sobre el propio medio).
           ====================================================================== */
        .fr.studio:not(.publico) {
            --texto:#1e1e2e; --suave:#64748b; --borde:#e7e8f0; --fondo:#f6f7fb;
            --panel:#ffffff; --panel2:#eef0f8; --hover:#e7e9f5; --morado:#6d00eb;
            --morado-suave:rgba(109,0,235,.08); --morado-borde:rgba(109,0,235,.25);
            --rojo:#e03a52;
        }
        /* Barra superior */
        .fr.studio:not(.publico) .fl-logo,
        .fr.studio:not(.publico) .fl-nombre,
        .fr.studio:not(.publico) .fl-pill,
        .fr.studio:not(.publico) .fl-pill .n b { color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-icono:hover { color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-pill { border-color:#e2d6fb; }
        .fr.studio:not(.publico) .fl-buscar label,
        .fr.studio:not(.publico) .fl-buscar .fl-icono { background:#eef0f8; }
        .fr.studio:not(.publico) .fl-buscar label:focus-within { border-color:#d9c2ff; }
        .fr.studio:not(.publico) .fl-marca { background:#eef0f8; color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-marca i::after { background-color:#f6f7fb; }
        .fr.studio:not(.publico) .fl-yo { background:#eef0f8; color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-yo a { background:#e3e0f5; color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-estado .ico { background:#c0c6d2; }
        /* Barra lateral (dentro de un proyecto) */
        .fr.studio:not(.publico) .fl-nav button,
        .fr.studio:not(.publico) .fl-side .it { color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-nav button:hover,
        .fr.studio:not(.publico) .fl-side .it:hover { background:#eef0f8; }
        .fr.studio:not(.publico) .fl-nav button.on { background:#ece4fe; color:#5a00c4; }
        .fr.studio:not(.publico) .fl-sep { background:#e7e8f0; }
        /* Banner de portada */
        .fr.studio:not(.publico) .fl-banner { background:linear-gradient(120deg,#f1edfd,#eef3ff); border-color:#e4def7; }
        .fr.studio:not(.publico) .fl-banner h1 { color:#1e1e2e; }
        .fr.studio:not(.publico) .fl-banner p { color:#475569; }
        .fr.studio:not(.publico) .fl-blanco { background:#6d00eb; color:#fff; }
        .fr.studio:not(.publico) .fl-blanco:hover { background:#5a00c4; color:#fff; }
        /* Avisos */
        .fr.studio:not(.publico) .aviso.ok { background:rgba(0,179,107,.1); border-color:rgba(0,179,107,.3); color:#0a7d4f; }
        .fr.studio:not(.publico) .aviso.err { background:rgba(224,58,82,.08); border-color:rgba(224,58,82,.3); color:#b42334; }
        .fr.studio:not(.publico) .aviso.alerta { background:rgba(234,179,8,.12); border-color:rgba(234,179,8,.35); color:#8a6d05; }
        /* Galería */
        .fr.studio:not(.publico) .cab p { color:#64748b; }
        .fr.studio:not(.publico) .proyecto .portada { background:#eef0f8; }
        .fr.studio:not(.publico) .proyecto .vacia { color:#aab2c0; }
        .fr.studio:not(.publico) .proyecto .lapiz { color:#64748b; }
        .fr.studio:not(.publico) .job .prompt { color:#475569; }
        .fr.studio:not(.publico) .job:hover .prompt { color:#1e1e2e; }
        .fr.studio:not(.publico) .job video,
        .fr.studio:not(.publico) .job img,
        .fr.studio:not(.publico) .job .hueco { background:#eef0f8; }
        .fr.studio:not(.publico) .job.vertical video,
        .fr.studio:not(.publico) .job.vertical img,
        .fr.studio:not(.publico) .job.vertical .hueco { background:#e9ebf4; }
        .fr.studio:not(.publico) .job .hueco { color:#64748b; }
        .fr.studio:not(.publico) .job .hueco.error,
        .fr.studio:not(.publico) .job .hueco.rechazo { background:#fdecee; color:#c0303f; }
        .fr.studio:not(.publico) .job .meta { color:#64748b; }
        .fr.studio:not(.publico) .job .acciones button,
        .fr.studio:not(.publico) .job .acciones a { background:#eef0f8; color:#1e1e2e; }
        .fr.studio:not(.publico) .job .acciones button:hover,
        .fr.studio:not(.publico) .job .acciones a:hover { background:#e3e0f5; color:#1e1e2e; }
        .fr.studio:not(.publico) .vacio,
        .fr.studio:not(.publico) .fl-vacio { color:#1e1e2e; }
        .fr.studio:not(.publico) .vacio small { color:#64748b; }
        .fr.studio:not(.publico) .giro { border-color:#e2d6fb; border-top-color:#6d00eb; }
        /* "+ Nuevo proyecto" flotante */
        .fr.studio:not(.publico) .fl-flotante { background:#6d00eb; color:#fff; box-shadow:0 20px 40px -14px rgba(76,29,149,.45); }
        .fr.studio:not(.publico) .fl-flotante:hover { background:#5a00c4; color:#fff; }
        /* Compositor "¿Qué quieres crear?" */
        .fr.studio:not(.publico) .st-compositor { background:#fff; border-color:#e7e8f0; box-shadow:0 20px 44px -22px rgba(76,29,149,.3); }
        .fr.studio:not(.publico) .st-compositor:focus-within { border-color:#b98aff; }
        .fr.studio:not(.publico) .st-compositor textarea::placeholder,
        .fr.studio:not(.publico) .fl-nota { color:#8a93a3; }
        .fr.studio:not(.publico) .fl-nota b { color:#6d00eb; }
        .fr.studio:not(.publico) .seg button:hover { background:#eef0f8; color:#1e1e2e; }
        .fr.studio:not(.publico) .seg button.on { background:#ece4fe; color:#5a00c4; }
        .fr.studio:not(.publico) .st-ajustes-btn { background:#eef0f8; color:#1e1e2e; }
        .fr.studio:not(.publico) .st-ajustes-btn:hover,
        .fr.studio:not(.publico) .st-ajustes-btn[aria-expanded="true"] { background:#e3e0f5; color:#1e1e2e; }
        .fr.studio:not(.publico) .st-crear { background:#e7e8f0; color:#6d00eb; }
        .fr.studio:not(.publico) .st-ajustes { background:#fff; border-color:#e7e8f0; box-shadow:0 24px 60px -22px rgba(76,29,149,.35); }
        /* Inputs y selects (fuera de la zona de config, que ya tiene los suyos) */
        .fr.studio:not(.publico) select,
        .fr.studio:not(.publico) input[type=number],
        .fr.studio:not(.publico) input[type=text],
        .fr.studio:not(.publico) input[type=email] { background:#f5f6fc; color:#1e1e2e; border:1px solid #e2e4ef; }
        .fr.studio:not(.publico) select:focus,
        .fr.studio:not(.publico) input:focus { border-color:#6d00eb; }
        .fr.studio:not(.publico) select:disabled { color:#aab2c0; }

        /* ===== Tema CLARO SnaveCloud — solo la zona de configuración (admin):
               Conexión con Google Flow, Reglas y Accesos públicos. No toca la galería. ===== */
        .fr .conexion, .fr #accesos {
            --texto:#1e1e2e; --suave:#64748b; --borde:#e7e8f0;
            --panel:#ffffff; --panel2:#f3f4fb; --hover:#eceef6; --morado:#6d00eb;
            color:#1e1e2e;
        }
        .fr .conexion .tarjeta, .fr #accesos { background:#fff !important; border:1px solid #e7e8f0 !important; box-shadow:0 14px 34px -22px rgba(76,29,149,.3); }
        .fr .conexion .tarjeta.google { background:radial-gradient(120% 120% at 0% 0%, rgba(109,0,235,.07), #fff 55%) !important; border-color:rgba(109,0,235,.22) !important; }
        .fr .conexion h2, .fr #accesos h2 { color:#1e1e2e; }
        .fr .conexion h2 .ms { color:#6d00eb; }
        .fr .conexion .cab p, .fr #accesos .cab p, .fr .conexion .ayuda, .fr .conexion .mini, .fr #accesos .mini { color:#64748b; }
        .fr .conexion .l, .fr #accesos label, .fr .conexion .cfg label, .fr #accesos .cfg label { color:#475569; }
        /* Inputs */
        .fr .conexion input, .fr .conexion select, .fr .conexion textarea,
        .fr #accesos input, .fr #accesos select,
        .fr .conexion input[type=password] { background:#f5f6fc !important; color:#1e1e2e !important; border:1px solid #e2e4ef !important; }
        .fr .conexion input::placeholder, .fr #accesos input::placeholder { color:#9aa3b2; }
        .fr .conexion input:focus, .fr .conexion select:focus, .fr #accesos input:focus { border-color:#6d00eb !important; box-shadow:0 0 0 3px rgba(109,0,235,.12); }
        /* Token */
        .fr .conexion .token { background:#f3edfd !important; border:1px solid #e2d4fb !important; color:#5b4b86 !important; }
        /* Estado (pastilla Conectado/Pausa/Sin conexión) */
        .fr .conexion .estado-api { background:#eef0f6; color:#475569; }
        .fr .conexion .estado-api.on { background:rgba(0,179,107,.12); color:#0a7d4f; }
        .fr .conexion .estado-api.on .punto { background:#00b36b; box-shadow:0 0 8px rgba(0,179,107,.45); }
        .fr .conexion .estado-api.pausa { background:rgba(234,179,8,.16); color:#8a6d05; }
        .fr .conexion .estado-api.pausa .punto { background:#eab308; }
        .fr .conexion .estado-api.off { background:rgba(224,58,82,.12); color:#c0303f; }
        .fr .conexion .estado-api.off .punto { background:#e03a52; }
        /* Lista de cuentas conectadas */
        .fr .conexion .cuentas-conectadas { background:#f5f6fc; border:1px solid #e7e8f0; }
        .fr .conexion .cc-tit { color:#64748b; }
        .fr .conexion .cc-item { border-top-color:#e7e8f0; }
        .fr .conexion .cc-item.ok .cc-salud { color:#0a7d4f; }
        .fr .conexion .cc-item.mal .cc-salud { color:#c0303f; }
        .fr .conexion .cc-salud { color:#64748b; }
        .fr .conexion .cc-aviso { color:#8a6d05; }
        /* Casillas tipo "caja" */
        .fr .conexion .chk.caja, .fr #accesos .chk.caja { background:rgba(109,0,235,.06); border:1px solid rgba(109,0,235,.2); }
        .fr .conexion .chk.peligro { color:#c0303f; }
        /* Aviso de error dentro de la tarjeta */
        .fr .conexion .aviso.err { background:rgba(224,58,82,.08) !important; border:1px solid rgba(224,58,82,.3) !important; color:#b42334 !important; }
        /* Botones */
        .fr .conexion .btn-borde, .fr #accesos .btn-borde { background:#fff; color:#6d00eb; border:1px solid #d9c2ff; }
        .fr .conexion .btn-borde:hover, .fr #accesos .btn-borde:hover { background:rgba(109,0,235,.08); color:#5a00c4; border-color:#b98aff; }
        /* Pastillas de estado en la tabla de accesos */
        .fr #accesos .estado { background:#eef0f6; }
        .fr #accesos .estado.hecho { color:#0a7d4f; }
        .fr #accesos .estado.error { color:#c0303f; }
        /* Enlaces */
        .fr .conexion a, .fr #accesos a { color:#6d00eb; }
        /* Responsive / zoom: las tarjetas de config se reacomodan solas */
        .fr .conexion { grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); align-items:start; }
        @media (max-width:420px) { .fr .conexion { grid-template-columns:1fr; } }

        /* ========= MODERNIZACIÓN del panel (tarjetas de configuración) ========= */
        .fr.studio:not(.publico) .tarjeta { border-radius:20px; box-shadow:0 1px 3px rgba(16,24,40,.05), 0 18px 40px -26px rgba(76,29,149,.3); }
        .fr.studio:not(.publico) .cab { padding:18px 22px 14px; align-items:center; }
        .fr.studio:not(.publico) .cab h2 { font-size:1.18rem; font-weight:800; letter-spacing:-.3px; color:#111827; display:inline-flex; align-items:center; gap:11px; }
        .fr.studio:not(.publico) .cab h2 .ms { width:36px; height:36px; border-radius:11px; background:rgba(109,0,235,.1); color:#6d00eb; display:grid; place-items:center; font-size:20px; }
        .fr.studio:not(.publico) .cab h3 { font-size:1.05rem; font-weight:800; color:#111827; margin:0; letter-spacing:-.2px; }
        .fr.studio:not(.publico) .cuerpo { padding:4px 22px 20px; }
        /* Inputs y selects: redondeados, con glow suave al enfocar */
        .fr.studio:not(.publico) .conexion input, .fr.studio:not(.publico) .conexion select,
        .fr.studio:not(.publico) #accesos input, .fr.studio:not(.publico) #accesos select {
            border-radius:12px !important; padding:11px 13px !important; transition:border-color .15s, box-shadow .15s; }
        /* Botones con micro-interacción */
        .fr.studio:not(.publico) .btn-verde, .fr.studio:not(.publico) .btn-morado {
            box-shadow:0 10px 24px -10px rgba(109,0,235,.55); transition:transform .15s, filter .15s; cursor:pointer; }
        .fr.studio:not(.publico) .btn-verde:hover, .fr.studio:not(.publico) .btn-morado:hover { transform:translateY(-1px); filter:brightness(1.05); }
        .fr.studio:not(.publico) .btn-borde { border-radius:999px; transition:transform .15s, background .15s; }
        .fr.studio:not(.publico) .btn-borde:hover { transform:translateY(-1px); }
        /* Monitor "Progreso y resultado": terminal moderno, redondeado */
        .fr.studio:not(.publico) #frProgreso { background:#f3f5fb !important; border:1px solid #e4e7f0 !important; border-radius:12px !important; box-shadow:inset 0 1px 4px rgba(16,24,40,.06); color:#0a7d4f !important; }
        /* Casilla "caja" (toggles destacados): un poco más suave */
        .fr.studio:not(.publico) .chk.caja { border-radius:14px; }

        /* ===== Monitor "Progreso y resultado" — TEMA CLARO ===== */
        .fr .fr-term-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:14px 18px; border-bottom:1px solid #e7e8f0; background:#f5f6fc; }
        .fr .fr-term-tit { display:flex; align-items:center; gap:9px; color:#6d00eb; font-weight:800; font-size:1rem; }
        .fr .fr-term-tit .ms { font-size:20px; }
        .fr .fr-term-tit .vivo { color:#64748b; }
        .fr .fr-term-tit .vivo i { background:#00b36b; box-shadow:0 0 6px rgba(0,179,107,.5); }
        .fr .fr-term-btn { display:inline-flex; align-items:center; gap:5px; color:#6d00eb; background:#fff; border:1px solid #d9c2ff;
            padding:7px 13px; border-radius:9px; font:inherit; font-size:.8rem; font-weight:700; cursor:pointer; transition:background .12s, color .12s, border-color .12s; }
        .fr .fr-term-btn:hover { background:#6d00eb; color:#fff; border-color:#6d00eb; }
        .fr .fr-term-btn .ms { font-size:16px; }
        .fr .fr-term-btn.girando .ms { animation:frspin .5s linear; }
        @keyframes frspin { to { transform:rotate(360deg); } }
        .fr .fr-term-acc { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .fr .fr-term-btn.alt { color:#0a7d4f; border-color:#9ae0c0; }
        .fr .fr-term-btn.alt:hover { background:#0a9d57; color:#fff; border-color:#0a9d57; }
        .fr .fr-term-live { flex:0 0 auto; background:#f3f5fb; padding:13px 16px; font-size:.8rem; line-height:1.7; color:#0a7d4f; font-weight:600;
            white-space:pre-wrap; word-break:break-word; height:130px; min-height:48px; max-height:70vh; overflow:auto; resize:vertical;
            border-bottom:1px solid #e7e8f0; }
        .fr .fr-term-sep { display:flex; align-items:center; gap:8px; padding:13px 18px 9px; font-size:.84rem; color:#374151; font-weight:800; border-top:1px solid #eceef4; background:#fbfcff; }
        .fr .fr-term-sep .ms { font-size:18px; color:#6d00eb; }
        .fr .fr-hist-n { background:#ece4fe; color:#5a00c4; font-weight:800; border-radius:999px; padding:1px 9px; font-size:.72rem; }
        /* Lista del historial (tarjetas claras) */
        .fr .fr-hist { display:flex; flex-direction:column; max-height:380px; overflow-y:auto; padding:12px 14px; background:#f6f7fb; }
        .fr .fr-hist::-webkit-scrollbar { width:9px; }
        .fr .fr-hist::-webkit-scrollbar-thumb { background:#cfd4e3; border-radius:8px; }
        .fr .fr-h { display:flex; align-items:center; gap:12px; padding:11px 13px; border-radius:12px; margin-bottom:8px;
            background:#fff; border:1px solid #e7e8f0; box-shadow:0 1px 2px rgba(16,24,40,.04); transition:border-color .12s, box-shadow .12s, transform .12s; }
        .fr .fr-h:hover { border-color:#c9b3f5; box-shadow:0 6px 16px -10px rgba(76,29,149,.4); transform:translateY(-1px); }
        .fr .fh-ic { width:32px; height:32px; border-radius:10px; display:grid; place-items:center; flex:0 0 auto; }
        .fr .fh-ic .ms { font-size:18px; }
        .fr .fr-h.ok  .fh-ic { background:rgba(16,185,129,.12); color:#0a9d57; }
        .fr .fr-h.gen .fh-ic { background:rgba(37,99,235,.1); color:#2563eb; }
        .fr .fr-h.cola .fh-ic { background:rgba(245,158,11,.14); color:#b45309; }
        .fr .fr-h.err .fh-ic { background:rgba(239,68,68,.1); color:#dc2626; }
        .fr .fh-mid { display:flex; flex-direction:column; gap:2px; min-width:0; flex:1; }
        .fr .fh-prompt { font-size:.87rem; font-weight:700; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:6px; }
        .fr .fh-prompt .tipo { font-size:16px; color:#9aa3b2; flex:0 0 auto; }
        .fr .fh-meta { font-size:.75rem; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .fr .fh-badge { font-size:.68rem; font-weight:800; padding:4px 11px; border-radius:999px; flex:0 0 auto; }
        .fr .fr-h.ok  .fh-badge { background:rgba(16,185,129,.14); color:#0a9d57; }
        .fr .fr-h.gen .fh-badge { background:rgba(37,99,235,.12); color:#2563eb; }
        .fr .fr-h.cola .fh-badge { background:rgba(245,158,11,.16); color:#b45309; }
        .fr .fr-h.err .fh-badge { background:rgba(239,68,68,.12); color:#dc2626; }
        .fr .fh-vacio { color:#64748b; font-size:.85rem; padding:18px; text-align:center; }
        .fr .fr-h { cursor:pointer; }
        .fr .fh-ver { color:#aab2c0; font-size:19px; flex:0 0 auto; transition:color .12s, transform .12s; }
        .fr .fr-h:hover .fh-ver { color:#6d00eb; transform:translateX(2px); }
        .fr .fh-flow { display:inline-flex; align-items:center; gap:4px; flex:0 0 auto; text-decoration:none; font-size:.7rem; font-weight:800;
            color:#6d00eb; background:#f2ecfe; border:1px solid #e2d4fb; border-radius:999px; padding:4px 10px; transition:background .12s, color .12s, border-color .12s; }
        .fr .fh-flow:hover { background:#6d00eb; color:#fff; border-color:#6d00eb; }
        .fr .fh-flow .ms { font-size:14px; }

        /* ===== Modal: línea de tiempo completa de UN video ===== */
        .fr-proc-modal { position:fixed; inset:0; z-index:1000; background:rgba(30,24,50,.45); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; padding:20px; }
        .fr-proc-modal[hidden] { display:none; }
        .fr-proc-box { width:min(560px, 100%); max-height:86vh; overflow:hidden; display:flex; flex-direction:column; background:#fff; border:1px solid #e7e8f0; border-radius:18px; box-shadow:0 30px 80px -20px rgba(20,16,40,.45); color:#1e1e2e; animation:fpUp .18s ease; }
        @keyframes fpUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:none; } }
        .fr-proc-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 18px; border-bottom:1px solid #e7e8f0; background:#f5f6fc; }
        .fr-proc-tit { display:flex; align-items:center; gap:9px; color:#6d00eb; font-weight:800; font-size:.95rem; min-width:0; }
        .fr-proc-tit #frProcPrompt { color:#1e1e2e; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .fr-proc-tit .ms { flex:0 0 auto; }
        .fr-proc-x { background:transparent; border:0; color:#64748b; cursor:pointer; display:grid; place-items:center; width:32px; height:32px; border-radius:8px; }
        .fr-proc-x:hover { background:#eceef6; color:#1e1e2e; }
        .fr-proc-sub { padding:12px 18px; border-bottom:1px solid #eceef4; font-size:.8rem; color:#64748b; display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
        .fr-proc-sub .fp-chip { font-weight:800; padding:3px 11px; border-radius:999px; font-size:.72rem; }
        .fr-proc-sub .fp-chip.ok { background:rgba(16,185,129,.14); color:#0a9d57; }
        .fr-proc-sub .fp-chip.gen { background:rgba(37,99,235,.12); color:#2563eb; }
        .fr-proc-sub .fp-chip.cola { background:rgba(245,158,11,.16); color:#b45309; }
        .fr-proc-sub .fp-chip.err { background:rgba(239,68,68,.12); color:#dc2626; }
        .fr-proc-sub .fp-dim { color:#64748b; }
        .fr-proc-sub .fp-job { flex-basis:100%; color:#8a93a3; font-size:.72rem; word-break:break-all; margin-top:2px; }
        .fr-proc-sub .fp-proy { flex-basis:100%; display:flex; align-items:center; gap:5px; margin-top:5px; font-size:.8rem; }
        .fr-proc-sub .fp-proy .ms { font-size:16px; color:#6d00eb; }
        .fr-proc-sub .fp-proy a { color:#6d00eb; font-weight:700; text-decoration:none; }
        .fr-proc-sub .fp-proy a:hover { text-decoration:underline; }
        .fr-proc-steps { padding:18px 20px 20px; overflow-y:auto; }
        .fp-step { position:relative; display:flex; gap:14px; padding-bottom:16px; }
        .fp-step::before { content:''; position:absolute; left:15px; top:32px; bottom:-2px; width:2px; background:#e2e4ef; }
        .fp-step:last-child { padding-bottom:0; }
        .fp-step:last-child::before { display:none; }
        .fp-dot { flex:0 0 auto; width:32px; height:32px; border-radius:50%; display:grid; place-items:center; z-index:1; background:#eef0f8; }
        .fp-dot .ms { font-size:17px; }
        .fp-step.ok .fp-dot { background:rgba(16,185,129,.14); color:#0a9d57; }
        .fp-step.gen .fp-dot { background:rgba(37,99,235,.12); color:#2563eb; }
        .fp-step.cola .fp-dot { background:rgba(245,158,11,.16); color:#b45309; }
        .fp-step.err .fp-dot { background:rgba(239,68,68,.12); color:#dc2626; }
        .fp-body { padding-top:5px; min-width:0; }
        .fp-t { font-size:.86rem; font-weight:700; color:#1f2937; }
        .fp-d { font-size:.78rem; color:#475569; margin-top:2px; word-break:break-word; }
        .fp-when { font-size:.72rem; color:#8a93a3; margin-top:3px; }

        /* ===== Tarjeta de acceso "Progreso y resultado" + monitor en grande ===== */
        .fr .fr-abrir-monitor { display:inline-flex; align-items:center; gap:8px; justify-content:center; background:#fff; color:#6d00eb;
            border:1px solid #d9c2ff; border-radius:12px; padding:12px 20px; font:inherit; font-weight:700; font-size:.9rem; cursor:pointer;
            transition:background .15s, transform .15s, border-color .15s, box-shadow .15s; box-shadow:0 6px 16px -10px rgba(109,0,235,.4); }
        .fr .fr-abrir-monitor:hover { background:rgba(109,0,235,.07); border-color:#b98aff; transform:translateY(-1px); }
        .fr .fr-abrir-monitor .ms { font-size:19px; }
        .fr .fr-card-acceso .cab .vivo { color:#64748b; }
        .fr-mon-box { width:min(700px, 100%); max-height:92vh; resize:both; min-width:320px; min-height:280px; max-width:96vw; }
        .fr-mon-box .fr-hist { flex:1; max-height:none; min-height:90px; }

        /* ===== Visor JSON: proceso de TODOS los videos ===== */
        .fr-log-box { width:min(820px, 100%); max-height:92vh; resize:both; min-width:320px; min-height:280px; max-width:96vw; }
        .fr-log-n { background:#ece4fe; color:#5a00c4; font-weight:800; border-radius:999px; padding:1px 10px; font-size:.75rem; margin-left:2px; }
        .fr-log-body { overflow:auto; padding:16px 18px; background:#f8f9fc; }
        .fr-json { margin:0; font-family:ui-monospace, Consolas, Menlo, monospace; font-size:.78rem; line-height:1.6; color:#1e1e2e; white-space:pre; }
        .fr-json .jk { color:#6d00eb; }     /* claves */
        .fr-json .js { color:#0a7d4f; }     /* textos */
        .fr-json .jn { color:#b45309; }     /* números */
        .fr-json .jb { color:#2563eb; }     /* true/false */
        .fr-json .jnull { color:#9333ea; }  /* null */

        /* Toast (aviso flotante del envío por AJAX) */
        #frToasts { position:fixed; left:50%; bottom:24px; transform:translateX(-50%); z-index:1200; display:flex; flex-direction:column; gap:8px; align-items:center; pointer-events:none; }
        #frToasts .fr-toast { background:#111827; color:#fff; font-size:.88rem; font-weight:600; padding:12px 18px; border-radius:12px;
            box-shadow:0 14px 34px -12px rgba(0,0,0,.55); max-width:min(460px,92vw); opacity:0; transform:translateY(12px); transition:opacity .25s, transform .25s; }
        #frToasts .fr-toast.ir { opacity:1; transform:none; }
        #frToasts .fr-toast.err { background:#b42334; }
        /* Botones rápidos de modelo de video */
        .fr .fr-modo-btn { font:inherit; font-size:.75rem; font-weight:700; padding:6px 12px; border-radius:999px; cursor:pointer;
            background:#fff; color:#64748b; border:1px solid #e2e4ef; transition:background .12s, color .12s, border-color .12s; }
        .fr .fr-modo-btn:hover { border-color:#b98aff; color:#6d00eb; }
        .fr .fr-modo-btn.rapido { color:#0a7d4f; border-color:#9ae0c0; }
        .fr .fr-modo-btn.rapido:hover { background:#0a9d57; color:#fff; border-color:#0a9d57; }
        .fr .fr-modo-btn.on { background:#6d00eb; color:#fff; border-color:#6d00eb; }
    </style>
    <div class="sp" id="frSpace" hidden>
        <div class="sp-top">
            <button type="button" class="sp-btn" id="spVolver"><span class="ms">arrow_back</span><span class="t">Studio</span></button>
            <input type="text" class="sp-nombre" id="spNombre" maxlength="80" value="Mi Space" title="Nombre del Space">
            <select id="spLista" title="Tus Spaces"></select>
            <button type="button" class="sp-btn" id="spNuevo"><span class="ms">add</span><span class="t">Nuevo Space</span></button>
            <button type="button" class="sp-btn peligro" id="spBorrar"><span class="ms">delete</span><span class="t">Borrar</span></button>
            <label class="sp-proy" title="Proyecto donde se guardan los videos e imágenes"><span class="ms">folder</span>
                <select id="spProyecto">
                    <option value="">Automático</option>
                    <?php foreach ($tarjetasProyecto as $p): if ($p['id'] === '') continue; ?>
                        <option value="<?= $h($p['id']) ?>"><?= $h($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span class="sp-estado" id="spEstado">Guardado</span>
        </div>
        <div class="sp-lienzo" id="spLienzo">
            <div class="sp-mundo" id="spMundo"><svg class="sp-cables" id="spCables"></svg></div>
            <div class="sp-ayuda" id="spAyuda">Agrega nodos desde la barra de la izquierda.<br>
                Conecta los puntos de salida (derecha) con las entradas (izquierda): amarillo = texto, azul = imagen.<br>
                Arrastra el fondo para moverte · rueda del ratón para el zoom.</div>
            <div class="sp-herr">
                <button type="button" data-nodo="texto"><span class="ms">title</span>Texto</button>
                <button type="button" data-nodo="imagen"><span class="ms">image</span>Imagen</button>
                <button type="button" data-nodo="video"><span class="ms">videocam</span>Video</button>
            </div>
            <div class="sp-zoom">
                <button type="button" id="spMenos" title="Alejar"><span class="ms">remove</span></button>
                <span id="spZoomTxt">100%</span>
                <button type="button" id="spMas" title="Acercar"><span class="ms">add</span></button>
                <button type="button" id="spCentrar" title="Ver todo"><span class="ms">fit_screen</span></button>
            </div>
        </div>
    </div>
</div>
</div>

<script>
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const FR_BASE = <?= json_encode($frBase) ?>; // 'flow-cola' en el panel, 'flow' en la página pública
    const FR_PANEL = <?= $frPanelNumeros ? 'true' : 'false' ?>; // panel admin "solo números": sin galería
    // Pinta los totales del tablero del panel desde el JSON liviano de ?vivo=1
    function pintarTablero(T) {
        if (!T) return;
        const vals = { videos_ok: T.videos_ok, imagenes_ok: T.imagenes_ok, pendientes: T.pendientes,
            procesando: T.procesando, err_total: (T.errores || 0) + (T.rechazados || 0),
            hechos_hoy: T.hechos_hoy, total: T.total };
        document.querySelectorAll('.fr-tablero .n[data-k]').forEach((el) => {
            const v = vals[el.dataset.k]; if (v !== undefined && v !== null) el.textContent = v;
        });
    }
    // Historial del proceso de TODOS los videos (monitor "Progreso y resultado")
    const FR_HIST0 = <?= json_encode($frHistorial, JSON_UNESCAPED_UNICODE) ?>;
    const FR_HEST = { hecho:{c:'ok',ic:'check_circle',t:'Listo'}, procesando:{c:'gen',ic:'bolt',t:'Generándose'},
        pendiente:{c:'cola',ic:'schedule',t:'En cola'}, error:{c:'err',ic:'error',t:'Error'}, rechazado:{c:'err',ic:'cancel',t:'Rechazado'} };
    function frEsc(s){ return String(s==null?'':s).replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
    function pintarHistorial(rows){
        const cont = document.getElementById('frHistorial'); if (!cont || !Array.isArray(rows)) return;
        cont.innerHTML = rows.map(r => {
            const e = FR_HEST[r.estado] || {c:'cola',ic:'help',t:r.estado};
            const tipoIc = r.tipo === 'imagen' ? 'image' : 'movie';
            const meta = [];
            if (r.cuenta) meta.push(frEsc(r.cuenta));
            if (r.estado === 'hecho' && r.seg) meta.push(r.seg + 's');
            else if ((r.estado === 'error' || r.estado === 'rechazado') && r.intentos) meta.push('intento ' + r.intentos);
            if (r.hace) meta.push(frEsc(r.hace));
            const flow = r.proyectoUrl ? `<a class="fh-flow" href="${frEsc(r.proyectoUrl)}" target="_blank" rel="noopener" title="Abrir el proyecto en Google Flow"><span class="ms">open_in_new</span> Flow</a>` : '';
            return `<div class="fr-h ${e.c}" data-id="${r.id}" role="button" tabindex="0" title="Ver todo el proceso de este video"><span class="fh-ic"><span class="ms">${e.ic}</span></span>`
                 + `<span class="fh-mid"><span class="fh-prompt"><span class="ms tipo">${tipoIc}</span>${frEsc(r.prompt || '(sin texto)')}</span>`
                 + `<span class="fh-meta">${meta.join(' · ')}</span></span>`
                 + `<span class="fh-badge">${e.t}</span>${flow}<span class="fh-ver ms">chevron_right</span></div>`;
        }).join('') || '<div class="fh-vacio">Aún no hay videos en el historial.</div>';
        const n = document.getElementById('frHistN'); if (n) n.textContent = rows.length;
    }
    try { pintarHistorial(FR_HIST0); } catch (e) {}

    // Al hacer CLICK en una fila del historial: abrir su proceso completo (línea
    // de tiempo, desde que se pidió hasta que se entregó), como "Examinar Cobros".
    async function verProceso(id) {
        const modal = document.getElementById('frProcModal'); if (!modal) return;
        const pasos = document.getElementById('frProcSteps');
        document.getElementById('frProcPrompt').textContent = 'Cargando…';
        document.getElementById('frProcSub').innerHTML = '';
        pasos.innerHTML = '<div class="fh-vacio">Cargando el proceso…</div>';
        modal.hidden = false; document.body.style.overflow = 'hidden';
        try {
            const d = await (await fetch(FR_BASE + '?proceso=' + encodeURIComponent(id), { cache: 'no-store', credentials: 'same-origin' })).json();
            if (!d || d.error) { pasos.innerHTML = '<div class="fh-vacio">No se encontró el proceso.</div>'; document.getElementById('frProcPrompt').textContent = '—'; return; }
            const e = FR_HEST[d.estado] || { c: 'cola', t: d.estado };
            document.getElementById('frProcIco').textContent = d.tipo === 'imagen' ? 'image' : 'movie';
            document.getElementById('frProcPrompt').textContent = d.prompt || '(sin texto)';
            document.getElementById('frProcSub').innerHTML =
                `<span class="fp-chip ${e.c}">${frEsc(e.t)}</span>`
                + (d.cuenta ? ` <span class="fp-dim">cuenta ${frEsc(d.cuenta)}</span>` : '')
                + (d.segTotal ? ` <span class="fp-dim">· ⏱️ ${d.segTotal}s en total</span>` : '')
                + (d.proyectoUrl ? `<div class="fp-proy"><span class="ms">open_in_new</span> <a href="${frEsc(d.proyectoUrl)}" target="_blank" rel="noopener">Abrir proyecto en Google Flow</a></div>` : '')
                + (d.jobid ? `<div class="fp-job">🔖 ${frEsc(d.jobid)}</div>` : '');
            pasos.innerHTML = (d.pasos || []).map((p) =>
                `<div class="fp-step ${p.c}"><span class="fp-dot"><span class="ms">${p.ic}</span></span>`
                + `<div class="fp-body"><div class="fp-t">${frEsc(p.t)}</div>`
                + (p.d ? `<div class="fp-d">${frEsc(p.d)}</div>` : '')
                + (p.cuando ? `<div class="fp-when">🕒 ${frEsc(p.cuando)}</div>` : '')
                + `</div></div>`
            ).join('') || '<div class="fh-vacio">Sin pasos.</div>';
        } catch (e) { pasos.innerHTML = '<div class="fh-vacio">Error al cargar el proceso.</div>'; }
    }
    function cerrarProceso() { const m = document.getElementById('frProcModal'); if (m) { m.hidden = true; document.body.style.overflow = ''; } }

    // VISOR JSON: todo el proceso de TODOS los videos como JSON (crudo, legible)
    function colorearJson(txt) {
        return txt.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]))
            .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)/g, (m) => {
                let cls = 'jn';
                if (/^"/.test(m)) cls = /:$/.test(m) ? 'jk' : 'js';
                else if (/true|false/.test(m)) cls = 'jb';
                else if (/null/.test(m)) cls = 'jnull';
                return '<span class="' + cls + '">' + m + '</span>';
            });
    }
    let frLogTexto = '';
    async function verTodoProceso() {
        const modal = document.getElementById('frLogModal'); if (!modal) return;
        const body = document.getElementById('frLogBody');
        body.innerHTML = '<div class="fh-vacio">Cargando el proceso de todos los videos…</div>';
        modal.hidden = false; document.body.style.overflow = 'hidden';
        try {
            const d = await (await fetch(FR_BASE + '?procesos=1', { cache: 'no-store', credentials: 'same-origin' })).json();
            const vids = (d && d.videos) || [];
            const n = document.getElementById('frLogN'); if (n) n.textContent = vids.length;
            frLogTexto = JSON.stringify(d, null, 2);
            body.innerHTML = '<pre class="fr-json">' + colorearJson(frLogTexto) + '</pre>';
        } catch (e) { frLogTexto = ''; body.innerHTML = '<div class="fh-vacio">Error al cargar el proceso.</div>'; }
    }
    function avisoBoton(btn, txt) {
        if (!btn) return; const ini = btn.innerHTML;
        btn.innerHTML = '<span class="ms">check</span> ' + txt;
        setTimeout(() => { btn.innerHTML = ini; }, 1400);
    }
    async function copiarLog() {
        if (!frLogTexto) return; const btn = document.getElementById('frLogCopiar');
        try { await navigator.clipboard.writeText(frLogTexto); avisoBoton(btn, 'Copiado'); }
        catch (e) { // respaldo si el navegador no deja clipboard
            const ta = document.createElement('textarea'); ta.value = frLogTexto; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); avisoBoton(btn, 'Copiado'); } catch (e2) {} document.body.removeChild(ta);
        }
    }
    function descargarLog() {
        if (!frLogTexto) return;
        const blob = new Blob([frLogTexto], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const fecha = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
        a.href = url; a.download = 'proceso-flow-' + fecha + '.json';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function cerrarLog() { const m = document.getElementById('frLogModal'); if (m) { m.hidden = true; document.body.style.overflow = ''; } }
    // Abrir / cerrar el MONITOR en grande (terminal en vivo + historial)
    function abrirMonitor() { const m = document.getElementById('frMonitorModal'); if (m) { m.hidden = false; document.body.style.overflow = 'hidden'; } latido(); }
    function cerrarMonitor() { const m = document.getElementById('frMonitorModal'); if (m) { m.hidden = true; document.body.style.overflow = ''; } }
    { const ab = document.getElementById('frAbrirMonitor'); if (ab) ab.addEventListener('click', abrirMonitor);
      const mc = document.getElementById('frMonitorCerrar'); if (mc) mc.addEventListener('click', cerrarMonitor);
      const mm = document.getElementById('frMonitorModal'); if (mm) mm.addEventListener('click', (ev) => { if (ev.target === mm) cerrarMonitor(); });
      document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarMonitor(); });
    }
    { const b = document.getElementById('frExaminar'); if (b) b.addEventListener('click', verTodoProceso);
      const x = document.getElementById('frLogCerrar'); if (x) x.addEventListener('click', cerrarLog);
      const rc = document.getElementById('frLogRecargar'); if (rc) rc.addEventListener('click', verTodoProceso);
      const cp = document.getElementById('frLogCopiar'); if (cp) cp.addEventListener('click', copiarLog);
      const dl = document.getElementById('frLogDescargar'); if (dl) dl.addEventListener('click', descargarLog);
      const md = document.getElementById('frLogModal'); if (md) md.addEventListener('click', (ev) => { if (ev.target === md) cerrarLog(); });
      document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarLog(); });
    }
    { const cont = document.getElementById('frHistorial');
      if (cont) cont.addEventListener('click', (ev) => { if (ev.target.closest('.fh-flow')) return; const fila = ev.target.closest('.fr-h[data-id]'); if (fila) verProceso(fila.dataset.id); });
      if (cont) cont.addEventListener('keydown', (ev) => { if ((ev.key === 'Enter' || ev.key === ' ') && ev.target.closest('.fr-h[data-id]')) { ev.preventDefault(); verProceso(ev.target.closest('.fr-h[data-id]').dataset.id); } });
      const x = document.getElementById('frProcCerrar'); if (x) x.addEventListener('click', cerrarProceso);
      const md = document.getElementById('frProcModal'); if (md) md.addEventListener('click', (ev) => { if (ev.target === md) cerrarProceso(); });
      document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarProceso(); });
    }
    const tipo = $('#frTipo'), modelo = $('#frModelo'), segundos = $('#frSegundos');
    const prompts = $('#frPrompts'), contador = $('#frContador'), modoPrompts = $('#frModoPrompts');

    function marcar(grupo, valor) {
        document.querySelectorAll('.fr .seg[data-grupo="' + grupo + '"] button')
            .forEach((b) => b.classList.toggle('on', b.dataset.valor === valor));
    }

    // Cuántos pedidos van a salir con lo escrito
    const cuantos = () => modoPrompts.value === 'varios'
        ? prompts.value.split(/\r?\n/).filter((l) => l.trim()).length
        : (prompts.value.trim() ? 1 : 0);

    // El botón dice exactamente qué va a pasar: "Crear video", "Crear 3 imágenes"...
    function pintarBoton() {
        const n = Math.max(cuantos(), 1);
        const cosa = tipo.value === 'imagen' ? (n === 1 ? 'imagen' : 'imágenes') : (n === 1 ? 'video' : 'videos');
        // La flecha redonda, como en Flow: el texto va en el título; se enciende al escribir
        const txt = n === 1 ? `Crear ${cosa}` : `Crear ${n} ${cosa}`;
        $('#frEnviar').title = txt;
        $('#frEnviar').setAttribute('aria-label', txt);
        $('#frForm').classList.toggle('listo', cuantos() > 0);
    }

    // Cada tipo enseña solo sus modelos; la duración solo existe en video
    function pintarTipo() {
        marcar('tipo', tipo.value);
        let primero = null;
        [...modelo.options].forEach((o) => {
            const ver = o.dataset.tipo === tipo.value;
            o.hidden = !ver; o.disabled = !ver;
            if (ver && !primero) primero = o;
        });
        if (modelo.selectedOptions[0].disabled) modelo.value = primero.value;
        segundos.disabled = tipo.value === 'imagen';
        // Las imágenes no tienen duración: ni se enseña
        $('#frDuracionZona').hidden = tipo.value === 'imagen';
        $('#frAyudaDuracion').textContent = tipo.value === 'imagen' ? 'Las imágenes no tienen duración' : 'Solo para videos';
        // La calidad es solo de videos
        $('#frCalidadZona').hidden = tipo.value === 'imagen';
        $('#frCalidad').disabled = tipo.value === 'imagen';
        pintarModelo();
        pintarBoton();
    }

    // Cuánto tarda aprox. la calidad elegida
    function pintarCalidad() {
        $('#frCalidadAprox').textContent = $('#frCalidad').selectedOptions[0].dataset.aprox;
    }
    $('#frCalidad').addEventListener('change', pintarCalidad);
    pintarCalidad();

    function pintarModelo() {
        const o = modelo.selectedOptions[0];
        // Se genera con la API de Google: cada pedido lo cobra Google al admin
        $('#frNotaModelo').textContent = o.index === [...modelo.options].findIndex((x) => !x.disabled)
            ? 'El modelo que tiene puesto el admin.' : 'Modelo de Google (' + o.value + ').';
    }

    function pintarContador() {
        if (modoPrompts.value === 'varios') {
            const n = cuantos();
            contador.textContent = n + (n === 1 ? ' pedido' : ' pedidos');
            contador.style.color = '';
        } else {
            contador.textContent = prompts.value.length + ' / 1200';
            contador.style.color = prompts.value.length > 1200 ? 'var(--rojo)' : '';
        }
        pintarBoton();
    }

    document.querySelectorAll('.fr .seg button').forEach((b) => b.addEventListener('click', () => {
        const grupo = b.closest('.seg').dataset.grupo;
        if (grupo === 'tipo') { tipo.value = b.dataset.valor; pintarTipo(); if (window.frRepintarRecursos) window.frRepintarRecursos(); }
        if (grupo === 'prompts') {
            modoPrompts.value = b.dataset.valor;
            marcar('prompts', b.dataset.valor);
            const varios = b.dataset.valor === 'varios';
            $('#frEtqPrompt').textContent = varios ? 'Una escena por línea' : 'Describe la escena';
            $('#frAyudaPrompt').textContent = varios
                ? 'Cada línea se convierte en un pedido distinto. Se hacen uno detrás de otro.'
                : 'Cuanto más detalle (lugar, luz, cámara), mejor sale.';
            prompts.placeholder = varios
                ? 'Un faro en la tormenta, cámara lenta\nUn zorro en el bosque nevado al amanecer\nUna taza de café junto a la ventana con lluvia'
                : '¿Qué quieres crear?';
            pintarContador();
            crecer();
        }
    }));
    modelo.addEventListener('change', pintarModelo);
    prompts.addEventListener('input', pintarContador);

    /* ---------- ESTUDIO (05/10/2026) ---------- */
    // La caja de escribir crece con el texto (hasta un tope; luego hace scroll)
    function crecer() {
        prompts.style.height = 'auto';
        prompts.style.height = Math.min(prompts.scrollHeight, 260) + 'px';
    }
    prompts.addEventListener('input', crecer);
    // Enter crea; Mayús+Enter (o "Varios a la vez") hace salto de línea
    prompts.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !ev.shiftKey && modoPrompts.value !== 'varios' && prompts.value.trim()) {
            ev.preventDefault();
            $('#frForm').requestSubmit ? $('#frForm').requestSubmit() : $('#frForm').submit();
        }
    });
    // La pastilla del modelo, como la de Flow ("🍌 Nano Banana 2 ▭ x2"):
    // modelo, formato y duración; al pulsarla se abren los ajustes
    function pintarResumen() {
        const esImg = tipo.value === 'imagen';
        const r = $('#frResumen');
        r.textContent = modelo.selectedOptions[0].textContent.replace(/\s*\(gratis\)\s*$/, '').trim() + ' ';
        const ico = document.createElement('span');
        ico.className = 'ms';
        ico.textContent = $('#frFormato').value === '9:16' ? 'crop_portrait' : 'crop_landscape';
        r.append(ico, ' ' + $('#frFormato').value + (esImg ? '' : ' · ' + segundos.value + 's'));
    }
    ['#frFormato', '#frSegundos', '#frCalidad', '#frModelo'].forEach((s) => $(s).addEventListener('change', pintarResumen));
    document.querySelectorAll('.fr .seg[data-grupo="tipo"] button').forEach((b) => b.addEventListener('click', () => setTimeout(pintarResumen)));
    $('#frAjustesBtn').addEventListener('click', () => {
        const ver = !$('#frAjustes').classList.contains('ver');
        $('#frAjustes').classList.toggle('ver', ver);
        $('#frAjustesBtn').setAttribute('aria-expanded', ver ? 'true' : 'false');
    });
    // La ventanita de ajustes se cierra al pulsar fuera o con Escape
    function cerrarAjustes() {
        $('#frAjustes').classList.remove('ver');
        $('#frAjustesBtn').setAttribute('aria-expanded', 'false');
    }
    document.addEventListener('click', (ev) => {
        if (!ev.target.closest('#frAjustes') && !ev.target.closest('#frAjustesBtn') && !ev.target.closest('[data-abre-ajustes]')) cerrarAjustes();
    });
    // El botón de ajustes de arriba (junto al buscador) abre la misma ventanita
    document.querySelectorAll('.fr [data-abre-ajustes]').forEach((b) => b.addEventListener('click', () => $('#frAjustesBtn').click()));
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarAjustes(); });
    // Aviso flotante ligero (toast): sin recargar nada
    function frToast(msg, esError) {
        if (!msg) return;
        let cont = document.getElementById('frToasts');
        if (!cont) { cont = document.createElement('div'); cont.id = 'frToasts'; document.body.appendChild(cont); }
        const t = document.createElement('div');
        t.className = 'fr-toast' + (esError ? ' err' : '');
        t.textContent = msg;
        cont.appendChild(t);
        setTimeout(() => { t.classList.add('ir'); }, 10);
        setTimeout(() => { t.classList.remove('ir'); setTimeout(() => t.remove(), 300); }, esError ? 5000 : 3500);
    }

    // ENVÍO POR AJAX: no recarga la página (antes el 302 recargaba TODO y se sentía
    // pesado). Manda el pedido, limpia el cuadro y la tarjeta nueva aparece sola.
    let frEnviando = false;
    $('#frForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (modoPrompts.value === 'uno' && prompts.value.length > 1200) {
            alert('El texto pasa de 1200 caracteres. Acórtalo o usa "Varios a la vez" (una escena por línea).');
            return;
        }
        if (frEnviando) return;
        if (!prompts.value.trim()) { prompts.focus(); return; }
        frEnviando = true;
        const b = $('#frEnviar');
        if (b) b.disabled = true;
        const form = e.target;
        try {
            const fd = new FormData(form);
            fd.set('fr_ajax', '1');
            const r = await fetch(FR_BASE, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
            let d = {};
            try { d = await r.json(); } catch (e2) { d = {}; }
            if (d && d.err) {
                frToast(d.err, true);
            } else {
                // Limpiar el compositor y mostrar el pedido al instante (sin recargar)
                prompts.value = '';
                try { prompts.dispatchEvent(new Event('input')); } catch (e3) {}
                try { if (window.frLimpiarAdjuntos) window.frLimpiarAdjuntos(); } catch (e4) {}
                frToast((d && d.ok) ? d.ok : 'Pedido en espera. Empieza a generarse en unos segundos.');
                try { await latido(); } catch (e5) {}
            }
        } catch (err) {
            // Si el AJAX falla por lo que sea, se envía a la antigua (recarga) como respaldo
            frEnviando = false; if (b) b.disabled = false;
            form.submit();
            return;
        }
        frEnviando = false;
        if (b) b.disabled = false;
    });

    // Botones rápidos de modelo de video: ⚡ Rápido (créditos) / 💤 Gratis
    document.querySelectorAll('.fr-modo-btn').forEach((b) => b.addEventListener('click', () => {
        const inp = document.getElementById('frModeloVideo');
        if (inp) { inp.value = b.dataset.modelo; try { inp.dispatchEvent(new Event('input')); } catch (e) {} }
        document.querySelectorAll('.fr-modo-btn').forEach((x) => x.classList.toggle('on', x === b));
        if (typeof frToast === 'function') frToast('Modelo puesto en "' + b.textContent.trim() + '". Pulsa GUARDAR para aplicarlo.');
    }));

    // El formulario crea en el proyecto abierto en "Tus resultados"
    function proyectoDelFormulario(id, nombre) {
        $('#frProyecto').value = id || '';
        $('#frProyectoTxt').textContent = id ? nombre : 'Sin proyecto';
    }
    // "+": proyecto nuevo con su nombre (se guarda y la extensión lo crea en Flow)
    $('#frNuevoProyecto').addEventListener('click', () => {
        const nombre = prompt('Nombre del proyecto nuevo (ej. Canal de gatos):', '');
        if (!nombre || !nombre.trim()) return;
        const f = $('#frFormNuevoProyecto');
        f.nombre.value = nombre.trim();
        f.submit();
    });

    // Filtros de la galería: tipo (pestañas) y proyecto
    // null = todos los proyectos; '' = "Sin proyecto"; si no, el id del proyecto de Flow
    let proyectoElegido = null;
    let videoUnico = null; // si está puesto, SOLO se ve ese video (clic en la lista lateral)
    function visibleEnFiltro(j) {
        if (videoUnico !== null) return j.dataset.id === videoUnico; // ver solo ese
        const tipoF = ($('#frPestanas .on') || {}).dataset?.filtro || 'todos';
        const suyo = proyectoElegido === null
            || (proyectoElegido === '' ? ['', 'nuevo'].includes(j.dataset.proyecto) : j.dataset.proyecto === proyectoElegido);
        // Buscador de arriba: busca en el texto del pedido
        const q = $('#frBuscar').value.trim().toLowerCase();
        const coincide = !q || (j.querySelector('.prompt') || {}).textContent?.toLowerCase().includes(q);
        return (tipoF === 'todos' || j.dataset.tipo === tipoF) && suyo && coincide;
    }
    // De arranque se enseñan solo las primeras 'frTope' tarjetas que pasan el
    // filtro (página LIGERA); el resto aparece al pulsar "Ver más". Así no se
    // cargan ~100 tarjetas de golpe, ni para admin ni para cliente.
    let frTope = 24;
    let aplicarFiltros = () => {
        let vistos = 0, coinciden = 0;
        const sinTope = videoUnico !== null; // viendo un solo video: sin límite
        document.querySelectorAll('.fr .job').forEach((j) => {
            const ok = visibleEnFiltro(j);
            let mostrar = ok;
            if (ok) { coinciden++; if (!sinTope && coinciden > frTope) mostrar = false; }
            j.style.display = mostrar ? '' : 'none';
            vistos += mostrar ? 1 : 0;
        });
        // Botón "Ver más": cuántas quedan ocultas por el tope
        const btn = $('#frVerMas');
        if (btn) {
            const restan = sinTope ? 0 : Math.max(0, coinciden - frTope);
            btn.hidden = restan <= 0;
            const n = btn.querySelector('.n'); if (n) n.textContent = restan;
        }
        // Nada que enseñar: "Empieza a crear…" en el centro, como Flow
        $('#frVacioProyecto').hidden = vistos > 0;
        $('#frVacioTxt').textContent = $('#frBuscar').value.trim() ? 'Nada coincide con tu búsqueda'
            : (($('#frPestanas .on') || {}).dataset?.filtro || 'todos') !== 'todos' ? 'Aún no hay nada de este tipo en el proyecto'
            : 'Empieza a crear escribiendo lo que quieres ver';
    };
    $('#frBuscar').addEventListener('input', () => { videoUnico = null; frTope = 24; aplicarFiltros(); });
    document.querySelectorAll('#frPestanas button').forEach((b) => b.addEventListener('click', () => {
        videoUnico = null; frTope = 24;
        document.querySelectorAll('#frPestanas button').forEach((x) => x.classList.toggle('on', x === b));
        aplicarFiltros();
    }));
    // "Ver más": sube el tope y vuelve a pintar (los videos nuevos entran perezosos)
    { const vm = $('#frVerMas'); if (vm) vm.addEventListener('click', () => { frTope += 24; aplicarFiltros(); activarLazyVideos(); }); }
    // Elegir proyecto (tarjeta o desplegable): la galería enseña SOLO lo de ese
    // proyecto; nunca todo mezclado. Se recuerda el último elegido.
    // Números de las pestañas (Todos / Videos / Imágenes) del proyecto elegido
    function contarPestanas() {
        const suyos = [...document.querySelectorAll('.fr .job')].filter((j) =>
            proyectoElegido === null || (proyectoElegido === '' ? ['', 'nuevo'].includes(j.dataset.proyecto) : j.dataset.proyecto === proyectoElegido));
        const n = { todos: suyos.length, video: suyos.filter((j) => j.dataset.tipo === 'video').length, imagen: suyos.filter((j) => j.dataset.tipo === 'imagen').length };
        document.querySelectorAll('#frPestanas button').forEach((b) => { const c = b.querySelector('.c'); if (c) c.textContent = n[b.dataset.filtro]; });
    }
    const aplicarFiltrosBase = aplicarFiltros;
    aplicarFiltros = () => { aplicarFiltrosBase(); contarPestanas(); };

    // Dos vistas: la lista de proyectos, o UN proyecto con sus videos e
    // imágenes y la flecha "← Proyectos" para volver
    const raizFr = $('.fr');
    const hayProyectos = document.querySelectorAll('.fr .proyecto').length > 0;
    function verLista() {
        videoUnico = null; frTope = 24;
        proyectoElegido = null;
        proyectoDelFormulario('');
        raizFr.classList.add('en-lista');
        raizFr.classList.remove('en-proyecto');
        raizFr.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function elegirProyecto(id) {
        const tarjeta = [...document.querySelectorAll('.fr .proyecto')].find((c) => c.dataset.proyecto === id);
        if (!tarjeta) return verLista();
        proyectoElegido = id;
        raizFr.classList.remove('en-lista');
        raizFr.classList.add('en-proyecto');
        $('#frTituloResultados').textContent = tarjeta.dataset.nombre;
        $('#frRenombrar').hidden = !id;
        // Los creados con "+" aún no existen en Flow hasta el primer pedido
        const enFlow = id && !/^nuevo-/.test(id);
        $('#frProyectoActivoFlow').hidden = !enFlow;
        if (enFlow) $('#frProyectoActivoFlow').href = 'https://flow.google.com/project/' + encodeURIComponent(id);
        proyectoDelFormulario(id, tarjeta.dataset.nombre);
        aplicarFiltros();
    }
    function renombrar(id, actual) {
        const nombre = prompt('Nuevo nombre para el proyecto (solo cambia aquí en SnaveCloud):', actual);
        if (!nombre || !nombre.trim() || nombre.trim() === actual) return;
        const f = $('#frFormRenombrar');
        f.proyecto.value = id;
        f.nombre.value = nombre.trim();
        f.submit();
    }
    document.querySelectorAll('.fr .proyecto').forEach((c) => {
        c.addEventListener('click', (ev) => {
            if (ev.target.closest('[data-renombrar]')) return renombrar(c.dataset.proyecto, c.dataset.nombre);
            elegirProyecto(c.dataset.proyecto);
            raizFr.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        c.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') c.click(); });
    });
    $('#frVolver').addEventListener('click', () => verLista());
    $('#frRenombrar').addEventListener('click', () => {
        const t = [...document.querySelectorAll('.fr .proyecto')].find((c) => c.dataset.proyecto === proyectoElegido);
        if (t) renombrar(proyectoElegido, t.dataset.nombre);
    });

    // Al cambiar de proyecto, el buscador empieza vacío
    const elegirProyectoBase = elegirProyecto;
    elegirProyecto = function (id) { videoUnico = null; frTope = 24; $('#frBuscar').value = ''; elegirProyectoBase(id); };

    // Lista "Mis videos" de la barra: clic en una fila abre ese video (en su
    // muestra SOLO ese video (modo "video único") y hace scroll hasta él.
    function irAVideo(id) {
        videoUnico = String(id);
        $('#frBuscar').value = '';
        raizFr.classList.remove('en-lista');
        raizFr.classList.add('en-proyecto');
        const b = document.querySelector('#frMisVideos .fl-vid[data-ir="' + id + '"]');
        const tit = $('#frTituloResultados');
        if (tit) tit.textContent = (b && b.querySelector('.nm')) ? b.querySelector('.nm').textContent : 'Video';
        aplicarFiltros();
        setTimeout(() => {
            const card = document.querySelector('.fr .rejilla .job[data-id="' + id + '"]');
            if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            document.querySelectorAll('#frMisVideos .fl-vid').forEach((x) => x.classList.toggle('activo', x.dataset.ir === String(id)));
        }, 60);
    }
    const _misVid = document.getElementById('frMisVideos');
    if (_misVid) _misVid.addEventListener('click', (ev) => {
        const b = ev.target.closest('.fl-vid');
        if (b) irAVideo(b.dataset.ir);
    });

    $('#frNavProyectos').addEventListener('click', () => { if (hayProyectos) verLista(); });
    document.querySelectorAll('.fr [data-ir-lista]').forEach((b) => b.addEventListener('click', () => verLista()));
    $('#frNavActualizar').addEventListener('click', () => location.reload());
    $('#frNavNuevo').addEventListener('click', () => $('#frNuevoProyecto').click());
    document.querySelectorAll('.fr [data-nuevo-proyecto]').forEach((b) => b.addEventListener('click', () => $('#frNuevoProyecto').click()));
    // "Ocultar" la barra lateral, como en Flow (se recuerda en este navegador)
    const guardar = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
    const leer = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
    $('#frOcultar').addEventListener('click', () => { raizFr.classList.add('side-oculta'); guardar('frSideOculta', '1'); });
    $('#frMostrar').addEventListener('click', () => { raizFr.classList.remove('side-oculta'); guardar('frSideOculta', ''); });
    if (leer('frSideOculta') === '1') raizFr.classList.add('side-oculta');
    // El banner de la portada se puede cerrar
    $('#frCerrarBanner').addEventListener('click', () => { $('#frBanner').remove(); guardar('frSinBanner', '1'); });
    if (leer('frSinBanner') === '1' && $('#frBanner')) $('#frBanner').remove();
    // Enlace corto: la dirección queda en /flow (sin ?ver=…&ok=…), aunque se
    // llegue con un enlace antiguo; el proyecto abierto se recuerda en la pestaña
    if (location.search) history.replaceState(null, '', location.pathname + (location.hash === '#space' ? '#space' : ''));
    const elegirProyectoRecordado = elegirProyecto;
    elegirProyecto = function (id) { elegirProyectoRecordado(id); try { sessionStorage.setItem('frProyecto', proyectoElegido ?? ''); } catch (e) {} };
    const verListaRecordada = verLista;
    verLista = function () { verListaRecordada(); try { sessionStorage.removeItem('frProyecto'); } catch (e) {} };

    // Al entrar: si vienes de crear un pedido o de renombrar, ese proyecto
    // abierto; si recargas, el que tenías abierto; si no, la lista de proyectos
    if (hayProyectos) {
        let inicial = <?= json_encode($frFlash['ver'] ?? $_GET['ver'] ?? $proyectoInicial) ?>;
        if (inicial === null) { try { inicial = sessionStorage.getItem('frProyecto'); } catch (e) {} }
        if (inicial !== null) elegirProyecto(inicial);
        else { raizFr.classList.add('en-lista'); proyectoElegido = null; }
    } else {
        // Sin nada todavía: solo "Nuevo proyecto" y el mensaje de bienvenida,
        // sin una sección de resultados vacía debajo.
        raizFr.classList.add('en-lista');
    }
    contarPestanas();

    // Copiar enlace (delegado: sirve también para las tarjetas que llegan en vivo)
    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('.fr [data-copiar]');
        if (!b) return;
        const antes = b.textContent;
        try { await navigator.clipboard.writeText(b.dataset.copiar); b.textContent = '✓ Copiado'; }
        catch (e) { prompt('Copia el enlace:', b.dataset.copiar); }
        setTimeout(() => { b.textContent = antes; }, 1800);
    });

    pintarTipo();
    pintarContador();
    pintarResumen();
    crecer();

    /* ---------- En vivo: sin recargar la página ---------- */
    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    let firmas = null, ext = null;

    // Estado del generador (la clave de Google del admin): la pastilla de
    // arriba, el aviso y cada pedido en espera. Desde el 05/10/2026 no hay
    // extensión: genera el servidor.
    const ES_ADMIN = <?= $esAdmin ? 'true' : 'false' ?>;
    function pintarExtension(e) {
        ext = e;
        const listo = !!(e && e.conectada && e.procesar);
        const txt = !e || e.nunca ? 'Sin configurar' : !e.procesar ? 'En pausa' : 'Encendido';
        const _extTxt = $('#frExtTxt'); if (_extTxt) _extTxt.textContent = txt;
        const _extIco = $('#frExtIco'); if (_extIco) _extIco.className = 'ico ' + (listo ? 'on' : e && e.conectada ? 'medio' : 'off');

        const aviso = $('#frAvisoExt');
        let msg = '';
        if (!e || e.nunca) msg = ES_ADMIN ? '⚠️ Falta conectar Núcleo SNAVE. Pega tu token y email abajo, en "Conexión con Google Flow", para empezar a generar.'
                                          : '⏳ El generador de SNAVE CLOUD aún no está listo. Tus pedidos esperan y se harán en cuanto lo esté.';
        else if (!e.procesar) msg = ES_ADMIN ? '⏸ La generación está en pausa. Quita la pausa abajo, en "Conexión con Google Flow".'
                                             : '⏳ El generador está en pausa un momento. Tus pedidos esperan su turno.';
        else if (ES_ADMIN && e.mensaje) msg = '⚠️ Último aviso de Núcleo SNAVE: ' + e.mensaje;
        aviso.textContent = msg;
        aviso.classList.toggle('ver', !!msg);

        const tarjeta = $('#frExtEstado');
        if (tarjeta) {
            tarjeta.className = 'estado-api ' + (listo ? 'on' : e && e.conectada ? 'pausa' : 'off');
            tarjeta.innerHTML = '<span class="punto"></span>' + esc(!e || e.nunca ? 'Sin conexión' : !e.procesar ? 'En pausa' : 'Conectado');
        }
        document.querySelectorAll('.fr [data-espera]').forEach((s) => {
            s.textContent = listo ? 'En espera: se genera en cuanto le toque.'
                : 'En espera: el generador ' + (e && e.conectada ? 'está en pausa' : 'no está listo') + '; se hará en cuanto lo esté.';
        });
    }

    // Progreso visual de los que se están generando (como Flow): un % que sube
    // suave y se acerca a 99% sin clavarse. No es la barra real de Google (la API
    // no la da), es una estimación por tiempo transcurrido; al terminar, la tarjeta
    // pasa a mostrar el video. Sin cuenta regresiva.
    function pintarPlazos() {
        document.querySelectorAll('.fr .job').forEach((j) => {
            const p = j.querySelector('[data-pct]');
            if (!p) return;
            const inicio = Number(j.dataset.inicio) || Date.now();
            const esperado = Number(j.dataset.esperado) || 150;
            const t = Math.max(0, (Date.now() - inicio) / 1000);
            const tau = Math.max(20, esperado * 0.45);
            let pct = 99 * (1 - Math.exp(-t / tau));
            if (pct < 1) pct = 1;
            if (pct > 99) pct = 99;
            p.textContent = Math.floor(pct) + '%';
        });
    }

    // Trae la página y cambia solo las tarjetas que cambiaron (o las nuevas)
    async function refrescarGaleria(cambiadas) {
        const rejilla = $('.fr .rejilla');
        const escribiendo = prompts.value.trim() || (document.activeElement && document.activeElement.closest('.fr form'));
        if (!rejilla) { if (!escribiendo) location.reload(); return; } // antes estaba vacía
        const html = await (await fetch(FR_BASE, { cache: 'no-store', credentials: 'same-origin' })).text();
        const nueva = new DOMParser().parseFromString(html, 'text/html');
        const ids = [...nueva.querySelectorAll('.fr .rejilla .job')].map((j) => j.dataset.id);
        let anterior = null;
        for (const id of ids) {
          try {
            const fresca = nueva.querySelector(`.fr .rejilla .job[data-id="${id}"]`);
            if (!fresca) continue;
            const actual = rejilla.querySelector(`.job[data-id="${id}"]`);
            const reproduciendo = actual && [...actual.querySelectorAll('video')].some((v) => !v.paused);
            let puesta = actual;
            if (!actual) {
                puesta = document.importNode(fresca, true);
                puesta.classList.add('nuevo');
                anterior ? anterior.after(puesta) : rejilla.prepend(puesta);
            } else if (cambiadas.has(id) && !reproduciendo) {
                puesta = document.importNode(fresca, true);
                actual.replaceWith(puesta);
            }
            puesta.style.display = visibleEnFiltro(puesta) ? '' : 'none';
            anterior = puesta;
          } catch (e) { /* una tarjeta mal no debe frenar el resto del refresco */ }
        }
        rejilla.querySelectorAll('.job').forEach((j) => { if (!ids.includes(j.dataset.id)) j.remove(); });
        // Lo de abajo es cosmético: si algo falla, las tarjetas YA se actualizaron
        // Refrescar la lista lateral "Mis videos" (estados y pedidos nuevos)
        try {
            const nL = nueva.querySelector('#frMisVideos'), aL = document.querySelector('#frMisVideos');
            if (nL && aL && aL.innerHTML !== nL.innerHTML) aL.innerHTML = nL.innerHTML;
            const nN = nueva.querySelector('#frMisVideosN'), aN = document.querySelector('#frMisVideosN');
            if (nN && aN) aN.textContent = nN.textContent;
            // Tablero de números del panel: refrescar los totales en vivo
            const nT = nueva.querySelector('.fr-tablero .fr-tab-grid'), aT = document.querySelector('.fr-tablero .fr-tab-grid');
            if (nT && aT && aT.innerHTML !== nT.innerHTML) aT.innerHTML = nT.innerHTML;
        } catch (e) {}
        try { aplicarFiltros(); if (ext) pintarExtension(ext); pintarPlazos(); activarLazyVideos(); } catch (e) {}
    }

    // Carga PEREZOSA de videos: en vez de descargar los ~100 videos al abrir la
    // página (lo que la hacía sentir pesada), cada video se baja SOLO cuando estás
    // por verlo (al acercarse a la pantalla). Así la página abre al instante.
    let lazyVidObs = null;
    function activarLazyVideos() {
        // Carga el video y, de paso, su PRIMER FOTOGRAMA como vista previa (si no,
        // se ve negro hasta darle play). preload=metadata + #t=0.1 pinta ese frame
        // sin bajar el video entero.
        const cargarLazy = (v) => {
            if (v.dataset.src) {
                v.preload = 'metadata';
                v.src = /#t=/.test(v.dataset.src) ? v.dataset.src : v.dataset.src + '#t=0.1';
                v.removeAttribute('data-src');
            }
            v.classList.remove('fr-lazy');
        };
        if (!('IntersectionObserver' in window)) { // navegador viejo: carga todo
            document.querySelectorAll('video.fr-lazy[data-src]').forEach(cargarLazy);
            return;
        }
        if (!lazyVidObs) {
            lazyVidObs = new IntersectionObserver((entradas, obs) => {
                for (const e of entradas) {
                    if (!e.isIntersecting) continue;
                    cargarLazy(e.target);
                    obs.unobserve(e.target);
                }
            }, { rootMargin: '400px 0px' }); // empieza a bajarlo un poco antes de llegar
        }
        document.querySelectorAll('video.fr-lazy[data-src]').forEach((v) => lazyVidObs.observe(v));
    }
    activarLazyVideos();

    let enCurso = false; // una comprobación a la vez (si no, una tarjeta nueva salía dos veces)
    async function latido() {
        if (enCurso) return;
        enCurso = true;
        try {
            const r = await (await fetch(FR_BASE + '?vivo=1', { cache: 'no-store', credentials: 'same-origin' })).json();
            { const _v = $('#frVivo'); if (_v) _v.classList.remove('caido'); }
            // PANEL "solo números": no hay galería, así que NO se baja toda la página.
            // Solo se actualizan los totales del tablero desde este JSON liviano.
            if (FR_PANEL) {
                try { pintarTablero(r.totales); } catch (e3) {}
                try { if (r.historial) pintarHistorial(r.historial); } catch (e4) {}
            } else
            // LO IMPORTANTE primero: refrescar la galería (nuevos pedidos y cambios de estado).
            if (firmas) {
                const cambiadas = new Set();
                const rejillaViva = document.querySelector('.fr .rejilla');
                for (const [id, f] of Object.entries(r.firmas)) {
                    if (firmas[id] !== f) cambiadas.add(id);
                    // Red de seguridad: lo que está en curso se refresca SIEMPRE, no se queda pegado
                    const est = String(f).split('|')[0];
                    if (est === 'pendiente' || est === 'procesando') cambiadas.add(id);
                    // Red de seguridad extra (imágenes sobre todo): si el servidor ya lo
                    // terminó pero la tarjeta en pantalla sigue mostrando el hueco de
                    // "en espera/generándose" y aún no tiene la imagen/el video, forzar
                    // el refresco hasta que el medio aparezca.
                    if (est === 'hecho' || est === 'error' || est === 'rechazado') {
                        const card = rejillaViva && rejillaViva.querySelector('.job[data-id="' + id + '"]');
                        if (card && card.querySelector('.hueco') && !card.querySelector('img, video')) cambiadas.add(id);
                    }
                }
                const hayBorradas = Object.keys(firmas).some((id) => !(id in r.firmas));
                if (cambiadas.size || hayBorradas) await refrescarGaleria(cambiadas);
            }
            firmas = r.firmas;
            // Contadores y estado del generador (cosmético): protegido, NO debe frenar el refresco.
            try {
                const _p = $('#frNPend'); if (_p) _p.textContent = r.cuentas.pendientes;
                const _q = $('#frNProc'); if (_q) _q.textContent = r.cuentas.procesando;
                const _c = $('#frNHechos'); if (_c) _c.textContent = r.cuentas.hechos;
                pintarExtension(r.ext);
                const _pr = $('#frProgreso');
                if (_pr && Array.isArray(r.progreso)) _pr.textContent = r.progreso.join('\n');
            } catch (e2) {}
        } catch (e) {
            { const _v = $('#frVivo'); if (_v) _v.classList.add('caido'); } // sin conexión con el panel: se reintenta
        } finally {
            enCurso = false;
        }
    }
    latido();
    setInterval(latido, 4000);
    // Con la pestaña en segundo plano Chrome frena los relojes (una vez por
    // minuto): al volver a ella se mira al instante, sin esperar
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') latido(); });
    window.addEventListener('focus', latido);
    { const _rb = $('#frProgRecargar'); if (_rb) _rb.addEventListener('click', () => { _rb.classList.add('girando'); latido().finally(() => setTimeout(() => _rb.classList.remove('girando'), 400)); }); }
    setInterval(pintarPlazos, 1000);
    pintarPlazos();

    // Alternar vista Cuadrícula / Lista (se recuerda por navegador)
    (function () {
        const btn = document.getElementById('frVistaLista');
        if (!btn) return;
        function aplica(lista) {
            const r = document.querySelector('.fr .rejilla');
            if (r) r.classList.toggle('lista', lista);
            const ic = btn.querySelector('.ms');
            if (ic) ic.textContent = lista ? 'grid_view' : 'view_list';
            const tx = btn.querySelector('.t');
            if (tx) tx.textContent = lista ? 'Cuadrícula' : 'Lista';
            btn.title = lista ? 'Ver en cuadrícula' : 'Ver en lista';
        }
        let lista = false;
        try { lista = localStorage.getItem('frVista') === 'lista'; } catch (e) {}
        aplica(lista);
        btn.addEventListener('click', function () {
            lista = !lista;
            try { localStorage.setItem('frVista', lista ? 'lista' : 'grid'); } catch (e) {}
            aplica(lista);
        });
    })();
})();
</script>

<script>
/* SPACES (05/10/2026): lienzo de nodos con conectores, como el de Flow.
   Texto (texto) -> Imagen (prompt, referencias -> imagen) -> Video (prompt,
   inicio, fin, ingredientes -> video, último cuadro). "Generar" crea un pedido
   normal de la cola: lo genera el servidor con la clave de Google del admin y el
   resultado aparece en el nodo (y en el proyecto). Se guarda solo (tbl_flow_spaces).
   Las imágenes conectadas viajan a Google: inicio -> primer cuadro, fin -> último
   cuadro, ingredientes -> imágenes de referencia (Veo 3.1); referencias -> Nano Banana. */
(function () {
    const $ = (s, r) => (r || document).querySelector(s);
    const FR_BASE = <?= json_encode($frBase) ?>;
    const MEDIA = <?= json_encode($frMedia) ?>;
    const CSRF = <?= json_encode($_SESSION['flow_csrf']) ?>;
    const FORMATO_DEFECTO = <?= json_encode($ult['formato'] === '9:16' ? '9:16' : '16:9') ?>;
    const COLOR = { texto: '#facc15', imagen: '#8ab4f8', video: '#b98aff' };
    // El modelo con el que genera cada nodo (el que tiene puesto el admin)
    const MODELO = <?= json_encode(['imagen' => ['id' => $frModelosApi['imagen'], 'nombre' => FlowApi::etiqueta($frModelosApi['imagen'])],
                                    'video'  => ['id' => $frModelosApi['video'],  'nombre' => FlowApi::etiqueta($frModelosApi['video'])]], JSON_UNESCAPED_UNICODE) ?>;
    // Conectores de cada nodo: p = nombre, t = qué lleva, multi = admite varios cables
    const TIPOS = {
        texto:  { nombre: 'Texto',  icono: 'edit', entradas: [], salidas: [{ p: 'texto', t: 'texto' }] },
        imagen: { nombre: 'Imagen', icono: 'image',
                  entradas: [{ p: 'prompt', t: 'texto' }, { p: 'referencias', t: 'imagen', multi: true }],
                  salidas: [{ p: 'imagen', t: 'imagen' }] },
        video:  { nombre: 'Video', icono: 'play_arrow',
                  entradas: [{ p: 'prompt', t: 'texto' }, { p: 'inicio', t: 'imagen' }, { p: 'fin', t: 'imagen' }, { p: 'ingredientes', t: 'imagen', multi: true }],
                  salidas: [{ p: 'video', t: 'video' }, { p: 'ultimo', t: 'imagen', etiqueta: 'último cuadro' }] },
    };
    const MAX_MULTI = 3;
    const raiz = $('#frSpace'), lienzo = $('#spLienzo'), mundo = $('#spMundo'), cables = $('#spCables');
    let space = { id: 0, nombre: 'Mi Space' };
    let nodos = [];    // { id, tipo, x, y, texto, formato, segundos, trabajo, subida } + estado en vivo
    let enlaces = [];  // { de, sal, a, ent }
    let vista = { x: 140, y: 90, z: 1 };
    let cargado = false, primeraVez = true, elegido = null, capa = 1;

    const nuevoId = () => 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    const nodo = (id) => nodos.find((n) => n.id === id);
    const elDe = (id) => mundo.querySelector('.sp-nodo[data-id="' + id + '"]');
    const conector = (tipo, lado, p) => (TIPOS[tipo] ? TIPOS[tipo][lado] : []).find((c) => c.p === p);
    const escribiendo = (t) => t && (t.closest('textarea, input, select') !== null);
    const esc = (x) => String(x ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    async function api(params, post) {
        const opciones = { credentials: 'same-origin', cache: 'no-store' };
        let url = FR_BASE;
        if (post) {
            const fd = post instanceof FormData ? post : new FormData();
            fd.append('csrf', CSRF);
            if (!(post instanceof FormData)) Object.keys(post).forEach((k) => fd.append(k, post[k] ?? ''));
            opciones.method = 'POST';
            opciones.body = fd;
        } else {
            url += '?' + new URLSearchParams(params);
        }
        const r = await fetch(url, opciones);
        let j = null;
        try { j = await r.json(); } catch (e) {}
        if (!r.ok || !j || j.error) throw new Error((j && j.error) || 'No se pudo conectar con el panel.');
        return j;
    }
    function estado(txt, error) {
        $('#spEstado').textContent = txt;
        $('#spEstado').classList.toggle('error', !!error);
    }

    /* ---------- Vista: moverse y zoom ---------- */
    function pintarVista() {
        mundo.style.transform = 'translate(' + vista.x + 'px, ' + vista.y + 'px) scale(' + vista.z + ')';
        lienzo.style.backgroundPosition = vista.x + 'px ' + vista.y + 'px';
        lienzo.style.backgroundSize = (22 * vista.z) + 'px ' + (22 * vista.z) + 'px';
        $('#spZoomTxt').textContent = Math.round(vista.z * 100) + '%';
    }
    function zoomEn(z, cx, cy) {
        z = Math.min(2, Math.max(0.3, z));
        vista.x = cx - (cx - vista.x) * (z / vista.z);
        vista.y = cy - (cy - vista.y) * (z / vista.z);
        vista.z = z;
        pintarVista();
        cambio();
    }
    lienzo.addEventListener('pointerdown', (ev) => {
        if (ev.target !== lienzo && ev.target !== mundo) return;
        elegir(null);
        const x0 = ev.clientX - vista.x, y0 = ev.clientY - vista.y;
        lienzo.classList.add('moviendo');
        const mover = (e) => { vista.x = e.clientX - x0; vista.y = e.clientY - y0; pintarVista(); };
        const soltar = () => {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
            lienzo.classList.remove('moviendo');
            cambio();
        };
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
    });
    lienzo.addEventListener('wheel', (ev) => {
        if (ev.target.closest('textarea')) return;
        ev.preventDefault();
        const r = lienzo.getBoundingClientRect();
        zoomEn(vista.z * (ev.deltaY < 0 ? 1.1 : 1 / 1.1), ev.clientX - r.left, ev.clientY - r.top);
    }, { passive: false });
    const centroLienzo = () => { const r = lienzo.getBoundingClientRect(); return [r.width / 2, r.height / 2]; };
    $('#spMas').addEventListener('click', () => zoomEn(vista.z * 1.2, ...centroLienzo()));
    $('#spMenos').addEventListener('click', () => zoomEn(vista.z / 1.2, ...centroLienzo()));
    $('#spCentrar').addEventListener('click', verTodo);
    function verTodo() {
        const r = lienzo.getBoundingClientRect();
        if (!nodos.length) { vista = { x: 140, y: 90, z: 1 }; pintarVista(); return; }
        let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        nodos.forEach((n) => {
            const d = elDe(n.id);
            x1 = Math.min(x1, n.x); y1 = Math.min(y1, n.y);
            x2 = Math.max(x2, n.x + d.offsetWidth); y2 = Math.max(y2, n.y + d.offsetHeight);
        });
        const z = Math.min(1.2, Math.max(0.3, Math.min((r.width - 260) / (x2 - x1), (r.height - 140) / (y2 - y1))));
        vista = { z, x: (r.width - (x2 - x1) * z) / 2 - x1 * z, y: (r.height - (y2 - y1) * z) / 2 - y1 * z };
        pintarVista();
        cambio();
    }

    /* ---------- Cables ---------- */
    function puntoDe(id, lado, p) {
        const d = elDe(id);
        const c = d && d.querySelector('.sp-puerto.' + lado + '[data-puerto="' + p + '"] i');
        if (!c) return null;
        const r = c.getBoundingClientRect(), m = mundo.getBoundingClientRect();
        return { x: (r.left + r.width / 2 - m.left) / vista.z, y: (r.top + r.height / 2 - m.top) / vista.z };
    }
    function curva(a, b) {
        const dx = Math.max(50, Math.abs(b.x - a.x) / 2);
        return 'M' + a.x + ' ' + a.y + ' C' + (a.x + dx) + ' ' + a.y + ', ' + (b.x - dx) + ' ' + b.y + ', ' + b.x + ' ' + b.y;
    }
    const tipoSalida = (e) => { const n = nodo(e.de); const c = n && conector(n.tipo, 'salidas', e.sal); return c ? c.t : 'texto'; };
    function pintarCables(temporal) {
        let html = '';
        enlaces.forEach((e, i) => {
            const a = puntoDe(e.de, 'sal', e.sal), b = puntoDe(e.a, 'ent', e.ent);
            if (a && b) html += '<path d="' + curva(a, b) + '" stroke="' + COLOR[tipoSalida(e)] + '" data-i="' + i + '"><title>Pulsa para quitar este cable</title></path>';
        });
        if (temporal) html += '<path class="temporal" d="' + curva(temporal.a, temporal.b) + '" stroke="' + temporal.color + '"></path>';
        cables.innerHTML = html;
        mundo.querySelectorAll('.sp-puerto').forEach((p) => p.classList.remove('conectado'));
        enlaces.forEach((e) => {
            const s = elDe(e.de) && elDe(e.de).querySelector('.sp-puerto.sal[data-puerto="' + e.sal + '"]');
            const t = elDe(e.a) && elDe(e.a).querySelector('.sp-puerto.ent[data-puerto="' + e.ent + '"]');
            if (s) s.classList.add('conectado');
            if (t) t.classList.add('conectado');
        });
    }
    cables.addEventListener('click', (ev) => {
        const p = ev.target.closest('path[data-i]');
        if (!p) return;
        const quitado = enlaces.splice(Number(p.dataset.i), 1)[0];
        pintarCables();
        repintarDestino(quitado && quitado.a);
        cambio();
    });
    // Unir una salida con una entrada del mismo tipo (texto con texto, imagen con imagen)
    function conectar(de, sal, a, ent) {
        const nd = nodo(de), na = nodo(a);
        const cs = nd && conector(nd.tipo, 'salidas', sal), ce = na && conector(na.tipo, 'entradas', ent);
        if (!cs || !ce || de === a || cs.t !== ce.t) return false;
        enlaces = enlaces.filter((x) => !(x.de === de && x.sal === sal && x.a === a && x.ent === ent));
        const yaEnEsa = enlaces.filter((x) => x.a === a && x.ent === ent);
        if (!ce.multi) enlaces = enlaces.filter((x) => !(x.a === a && x.ent === ent));       // una entrada, un cable
        else if (yaEnEsa.length >= MAX_MULTI) enlaces = enlaces.filter((x) => x !== yaEnEsa[0]); // como mucho 3
        enlaces.push({ de, sal, a, ent });
        return true;
    }
    function empezarCable(n, sal, ev) {
        ev.stopPropagation();
        ev.preventDefault();
        const a = puntoDe(n.id, 'sal', sal);
        const t = conector(n.tipo, 'salidas', sal).t;
        lienzo.classList.add('cableando', 'lleva-' + t);
        const mover = (e) => {
            const m = mundo.getBoundingClientRect();
            pintarCables({ a, b: { x: (e.clientX - m.left) / vista.z, y: (e.clientY - m.top) / vista.z }, color: COLOR[t] });
        };
        const soltar = (e) => {
            window.removeEventListener('pointermove', mover);
            window.removeEventListener('pointerup', soltar);
            lienzo.classList.remove('cableando', 'lleva-texto', 'lleva-imagen', 'lleva-video');
            // Sobre un conector concreto, o sobre el nodo: el primer conector libre que encaje
            const bajo = document.elementFromPoint(e.clientX, e.clientY);
            const puerto = bajo && bajo.closest('.sp-puerto.ent');
            const destino = bajo && bajo.closest('.sp-nodo');
            let hecho = false, idA = null;
            if (puerto && destino) {
                idA = destino.dataset.id;
                hecho = conectar(n.id, sal, idA, puerto.dataset.puerto);
            } else if (destino && destino.dataset.id !== n.id) {
                idA = destino.dataset.id;
                const lista = TIPOS[nodo(idA).tipo].entradas.filter((c) => c.t === t);
                const libre = lista.find((c) => !enlaces.some((x) => x.a === idA && x.ent === c.p)) || lista[0];
                if (libre) hecho = conectar(n.id, sal, idA, libre.p);
            }
            pintarCables();
            if (hecho) { repintarDestino(idA); cambio(); }
        };
        window.addEventListener('pointermove', mover);
        window.addEventListener('pointerup', soltar);
    }

    /* ---------- Nodos ---------- */
    function crearEl(n) {
        const t = TIPOS[n.tipo];
        const d = document.createElement('div');
        d.className = 'sp-nodo';
        d.dataset.id = n.id;
        d.dataset.tipo = n.tipo;
        let cuerpo;
        if (n.tipo === 'texto') {
            cuerpo = '<textarea data-campo="texto" maxlength="1200" placeholder="Escribe tu prompt…"></textarea>';
        } else {
            cuerpo = '<div class="sp-vista"></div>'
                + '<div class="sp-refs"></div>'
                + '<textarea data-campo="texto" maxlength="1200"></textarea>'
                + '<div class="sp-fila">'
                + (n.tipo === 'imagen' ? '<button type="button" class="sp-subir">Subir</button><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>' : '')
                + '<button type="button" class="sp-gen"></button>'
                + '<select data-campo="formato" title="Formato"><option value="16:9">16:9</option><option value="9:16">9:16</option></select>'
                + (n.tipo === 'video' ? '<select data-campo="segundos" title="Duración"><option value="8">8 s</option></select>' : '')
                + '<a class="sp-abrir" target="_blank" rel="noopener" hidden>Abrir ↗</a></div>'
                + '<div class="sp-aviso" hidden></div>';
        }
        const puerto = (c, i, lado) => '<div class="sp-puerto ' + lado + '" data-puerto="' + c.p + '" data-t="' + c.t + '" style="top:' + (62 + i * 34) + 'px; --c:' + COLOR[c.t] + '">'
            + '<i title="' + (c.etiqueta || c.p) + '"></i><span>' + (c.etiqueta || c.p) + '</span></div>';
        d.innerHTML = '<div class="sp-cab"><span class="ms">' + t.icono + '</span><b>' + t.nombre + '</b>'
            + '<button type="button" class="sp-x" title="Quitar este nodo"><span class="ms">close</span></button></div>'
            + '<div class="sp-cuerpo">' + cuerpo + '</div>'
            + t.entradas.map((c, i) => puerto(c, i, 'ent')).join('')
            + t.salidas.map((c, i) => puerto(c, i, 'sal')).join('');
        d.style.left = n.x + 'px';
        d.style.top = n.y + 'px';
        d.querySelectorAll('[data-campo]').forEach((c) => {
            c.value = String(n[c.dataset.campo] ?? '');
            c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', () => {
                n[c.dataset.campo] = c.tagName === 'SELECT' && c.dataset.campo === 'segundos' ? Number(c.value) : c.value;
                if (c.dataset.campo === 'formato') pintarNodo(n);
                cambio();
            });
        });
        d.addEventListener('pointerdown', () => elegir(n.id));
        d.querySelector('.sp-x').addEventListener('click', () => borrarNodo(n.id));
        d.querySelector('.sp-cab').addEventListener('pointerdown', (ev) => {
            if (ev.target.closest('.sp-x')) return;
            ev.stopPropagation();
            ev.preventDefault();
            elegir(n.id);
            const x0 = ev.clientX, y0 = ev.clientY, nx = n.x, ny = n.y;
            const mover = (e) => {
                n.x = Math.round(nx + (e.clientX - x0) / vista.z);
                n.y = Math.round(ny + (e.clientY - y0) / vista.z);
                d.style.left = n.x + 'px';
                d.style.top = n.y + 'px';
                pintarCables();
            };
            const soltar = () => {
                window.removeEventListener('pointermove', mover);
                window.removeEventListener('pointerup', soltar);
                cambio();
            };
            window.addEventListener('pointermove', mover);
            window.addEventListener('pointerup', soltar);
        });
        d.querySelectorAll('.sp-puerto.sal i').forEach((i) => i.addEventListener('pointerdown', (ev) => empezarCable(n, i.parentNode.dataset.puerto, ev)));
        const gen = d.querySelector('.sp-gen');
        if (gen) gen.addEventListener('click', () => generar(n));
        const subir = d.querySelector('.sp-subir');
        if (subir) {
            const archivo = d.querySelector('input[type=file]');
            subir.addEventListener('click', () => archivo.click());
            archivo.addEventListener('change', () => { if (archivo.files[0]) subirImagen(n, archivo.files[0]); archivo.value = ''; });
        }
        mundo.appendChild(d);
        return d;
    }
    // Lo que sale de un nodo: su imagen (generada o subida) o su video
    function medioDe(n) {
        if (!n) return null;
        if (n.archivo && n.archivo.src) return n.archivo;
        if (n.subida) return { tipo: 'imagen', src: MEDIA + n.subida, ruta: n.subida };
        return null;
    }
    // Lo que se ve dentro de un nodo de Imagen o Video: el resultado o cómo va
    function pintarNodo(n) {
        const d = elDe(n.id);
        if (!d || n.tipo === 'texto') return;
        d.classList.toggle('vertical', n.formato === '9:16');
        const caja = d.querySelector('.sp-vista');
        const abrir = d.querySelector('.sp-abrir');
        const gen = d.querySelector('.sp-gen');
        const cosa = n.tipo === 'imagen' ? 'imagen' : 'video';
        caja.innerHTML = '';
        abrir.hidden = true;
        const msg = (texto, error, giro) => {
            const m = document.createElement('div');
            m.className = 'msg' + (error ? ' error' : '');
            if (giro) { const g = document.createElement('span'); g.className = 'giro'; m.appendChild(g); }
            m.appendChild(document.createTextNode(texto));
            caja.appendChild(m);
        };
        const ocupado = ['enviando', 'pendiente', 'procesando', 'consultando', 'subiendo'].includes(n.estado);
        const medio = medioDe(n);
        if (ocupado && n.estado === 'subiendo') msg('Subiendo…', false, true);
        else if (ocupado && !medio) {
            if (n.estado === 'enviando') msg('Enviando…', false, true);
            else if (n.estado === 'consultando') msg('Consultando…', false, true);
            else if (n.estado === 'pendiente') msg('⏳ En espera: se genera en cuanto le toque.');
            else msg('Generándose…', false, true);
        } else if (medio) {
            const m = document.createElement(medio.tipo === 'imagen' ? 'img' : 'video');
            if (m.tagName === 'VIDEO') {
                m.controls = true; m.preload = 'metadata';
                // #t=0.1: pinta el primer fotograma como vista previa (si no, se ve negro)
                m.src = /#t=/.test(medio.src) ? medio.src : medio.src + '#t=0.1';
            } else { m.src = medio.src; m.alt = ''; }
            caja.appendChild(m);
            abrir.href = medio.src;
            abrir.hidden = false;
            if (ocupado) msg(n.estado === 'pendiente' ? '⏳ En espera…' : 'Generándose…', false, true);
        }
        else if (n.estado === 'hecho') msg('Terminó sin archivo. Prueba otra vez.', true);
        else if (['error', 'rechazado', 'fallo'].includes(n.estado)) msg(n.mensaje || 'No salió. Prueba otra vez.', true);
        else if (n.estado === 'borrado') msg('Ese pedido se borró.', true);
        else msg(n.tipo === 'imagen' ? 'Sin imagen' : 'Sin video');
        if (n.mensaje && ['fallo', 'error', 'rechazado'].includes(n.estado) && medio) msg(n.mensaje, true);
        gen.disabled = ocupado;
        gen.textContent = ocupado && n.estado !== 'subiendo' ? 'Generando…' : (n.archivo ? 'Generar otra vez' : 'Generar ' + cosa);
        pintarRefs(n);
    }
    // Las imágenes conectadas a "referencias", "inicio", "fin" e "ingredientes", en miniatura
    function pintarRefs(n) {
        const d = elDe(n.id);
        const zona = d && d.querySelector('.sp-refs');
        if (!zona) return;
        zona.innerHTML = '';
        const deImagen = enlaces.filter((e) => e.a === n.id && conector(n.tipo, 'entradas', e.ent).t === 'imagen');
        deImagen.forEach((e) => {
            const chip = document.createElement('span');
            chip.className = 'sp-ref';
            const origen = nodo(e.de);
            const medio = origen && medioDe(origen);
            if (medio && e.sal === 'ultimo' && medio.tipo === 'video') {
                // El último cuadro de un video: el propio video parado al final
                const v = document.createElement('video');
                v.muted = true; v.preload = 'metadata'; v.src = medio.src;
                v.addEventListener('loadedmetadata', () => { try { v.currentTime = Math.max(0, v.duration - 0.1); } catch (er) {} });
                chip.appendChild(v);
            } else if (medio && medio.tipo === 'imagen') {
                const im = document.createElement('img');
                im.src = medio.src; im.alt = '';
                chip.appendChild(im);
            } else {
                chip.classList.add('vacia');
            }
            const t = document.createElement('small');
            t.textContent = e.ent;
            chip.appendChild(t);
            zona.appendChild(chip);
        });
        zona.hidden = !deImagen.length;
        const aviso = d.querySelector('.sp-aviso');
        let texto = '';
        if (deImagen.length && n.tipo === 'video' && !/^veo-3\.1/.test(MODELO.video.id)) texto = 'Inicio, fin e ingredientes solo los usa Veo 3.1 (ahora está puesto ' + MODELO.video.nombre + ').';
        if (deImagen.length && n.tipo === 'imagen' && /^imagen-/.test(MODELO.imagen.id)) texto = MODELO.imagen.nombre + ' no usa imágenes de referencia: pon Nano Banana.';
        aviso.textContent = texto;
        aviso.hidden = !texto;
    }
    // Tras conectar o quitar un cable: el nodo de destino repinta lo suyo
    function repintarDestino(id) {
        pintarEntradas();
        const n = id && nodo(id);
        if (n) pintarRefs(n);
    }
    // Si el "prompt" está conectado, se usa el texto del nodo de Texto
    function pintarEntradas() {
        nodos.forEach((n) => {
            if (n.tipo === 'texto') return;
            const ta = elDe(n.id) && elDe(n.id).querySelector('textarea');
            if (!ta) return;
            const conectado = enlaces.some((e) => e.a === n.id && e.ent === 'prompt');
            ta.disabled = conectado;
            ta.placeholder = conectado ? 'Usa el texto del nodo conectado'
                : 'Prompt ' + (n.tipo === 'imagen' ? 'para generar la imagen' : 'del video') + ' (o conecta un nodo de Texto)';
        });
    }
    function promptDe(n) {
        const e = enlaces.find((x) => x.a === n.id && x.ent === 'prompt');
        const origen = e && nodo(e.de);
        return String(origen ? origen.texto : n.texto || '').trim();
    }
    function elegir(id) {
        elegido = id;
        mundo.querySelectorAll('.sp-nodo').forEach((d) => d.classList.toggle('elegido', d.dataset.id === id));
        // El nodo que tocas pasa delante de los demás
        const d = id && elDe(id);
        if (d) d.style.zIndex = String(++capa);
    }
    function ayuda() { $('#spAyuda').hidden = nodos.length > 0; }
    function agregar(tipo) {
        const r = lienzo.getBoundingClientRect();
        const k = nodos.length % 6;
        const n = { id: nuevoId(), tipo, texto: '', formato: FORMATO_DEFECTO, segundos: 8, trabajo: null, subida: null, estado: '',
                    x: Math.round((r.width / 2 - vista.x) / vista.z - 170 + k * 26), y: Math.round((r.height / 2 - vista.y) / vista.z - 140 + k * 26) };
        nodos.push(n);
        crearEl(n);
        pintarNodo(n);
        elegir(n.id);
        pintarEntradas();
        ayuda();
        cambio();
        const ta = elDe(n.id).querySelector('textarea');
        if (ta) ta.focus();
    }
    function borrarNodo(id) {
        const afectados = enlaces.filter((e) => e.de === id).map((e) => e.a);
        nodos = nodos.filter((n) => n.id !== id);
        enlaces = enlaces.filter((e) => e.de !== id && e.a !== id);
        const d = elDe(id);
        if (d) d.remove();
        if (elegido === id) elegido = null;
        pintarCables();
        pintarEntradas();
        afectados.forEach((a) => { const n = nodo(a); if (n) pintarRefs(n); });
        ayuda();
        cambio();
    }
    document.querySelectorAll('#frSpace [data-nodo]').forEach((b) => b.addEventListener('click', () => agregar(b.dataset.nodo)));
    document.addEventListener('keydown', (ev) => {
        if (raiz.hidden || !elegido || escribiendo(ev.target)) return;
        if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); borrarNodo(elegido); }
    });
    // Cuando cambia lo que sale de un nodo, se refrescan las miniaturas de quien lo recibe
    function avisarSalida(n) {
        enlaces.filter((e) => e.de === n.id).forEach((e) => { const a = nodo(e.a); if (a) pintarRefs(a); });
    }

    /* ---------- Subir una imagen propia ---------- */
    async function subirImagen(n, file) {
        n.estado = 'subiendo';
        n.mensaje = '';
        pintarNodo(n);
        try {
            const fd = new FormData();
            fd.append('accion', 'space_subir');
            fd.append('imagen', file);
            const r = await api(null, fd);
            n.subida = r.ruta;
            n.archivo = null;       // lo subido pasa a ser la imagen del nodo
            n.trabajo = null;
            n.estado = '';
            cambio();
        } catch (e) {
            n.estado = 'fallo';
            n.mensaje = e.message;
        }
        pintarNodo(n);
        avisarSalida(n);
    }

    /* ---------- Generar y seguir el pedido ---------- */
    async function generar(n) {
        const prompt = promptDe(n);
        if (!prompt) { n.estado = 'fallo'; n.mensaje = 'Escribe el prompt o conecta un nodo de Texto con texto.'; pintarNodo(n); return; }
        n.estado = 'enviando';
        n.mensaje = '';
        pintarNodo(n);
        try {
            const entradas = await entradasDe(n);
            const r = await api(null, { accion: 'space_generar', tipo: n.tipo, prompt, formato: n.formato, segundos: n.segundos || 8, proyecto: $('#spProyecto').value,
                                        entradas: JSON.stringify(entradas) });
            n.trabajo = r.id;
            n.subida = null;
            n.archivo = null;
            n.estado = 'pendiente';
            cambio();
            vigilar();
        } catch (e) {
            n.estado = 'fallo';
            n.mensaje = e.message;
        }
        pintarNodo(n);
    }
    // Las imágenes conectadas a este nodo, como rutas del servidor que se mandan a Google
    async function entradasDe(n) {
        const out = {};
        for (const e of enlaces.filter((x) => x.a === n.id && conector(n.tipo, 'entradas', x.ent).t === 'imagen')) {
            const origen = nodo(e.de);
            const medio = origen && medioDe(origen);
            if (!medio) throw new Error('La imagen conectada a "' + e.ent + '" aún no está lista.');
            let ruta = null;
            if (e.sal === 'ultimo' && medio.tipo === 'video') ruta = await ultimoCuadro(origen, medio.src);
            else if (medio.tipo === 'imagen') ruta = medio.ruta || null;
            if (!ruta) throw new Error('La imagen conectada a "' + e.ent + '" no se puede usar.');
            if (e.ent === 'inicio' || e.ent === 'fin') out[e.ent] = ruta;
            else (out[e.ent] = out[e.ent] || []).push(ruta);
        }
        return out;
    }
    // "último cuadro": se saca del video en el navegador y se sube como imagen
    async function ultimoCuadro(origen, src) {
        if (origen.ultimo && origen.ultimo.src === src) return origen.ultimo.ruta;
        const v = document.createElement('video');
        v.muted = true; v.preload = 'auto'; v.src = src;
        await new Promise((ok, mal) => { v.onloadedmetadata = ok; v.onerror = () => mal(new Error('No se pudo leer el video para sacar el último cuadro.')); });
        v.currentTime = Math.max(0, v.duration - 0.05);
        await new Promise((ok) => { v.onseeked = ok; });
        const c = document.createElement('canvas');
        c.width = v.videoWidth; c.height = v.videoHeight;
        c.getContext('2d').drawImage(v, 0, 0);
        const blob = await new Promise((ok) => c.toBlob(ok, 'image/png'));
        if (!blob) throw new Error('No se pudo sacar el último cuadro del video.');
        const fd = new FormData();
        fd.append('accion', 'space_subir');
        fd.append('imagen', blob, 'ultimo-cuadro.png');
        const r = await api(null, fd);
        origen.ultimo = { src, ruta: r.ruta };
        return r.ruta;
    }

    let reloj = null;
    async function vigilar() {
        clearTimeout(reloj);
        if (raiz.hidden) return;
        const ids = nodos.filter((n) => n.trabajo).map((n) => n.trabajo);
        if (!ids.length) return;
        try {
            const r = await api({ space_estado: ids.join(',') });
            nodos.forEach((n) => {
                if (!n.trabajo) return;
                const t = r.trabajos[n.trabajo];
                const antes = n.archivo && n.archivo.src;
                if (!t) { n.estado = 'borrado'; n.archivo = null; }
                else { n.estado = t.estado; n.mensaje = t.mensaje; n.archivo = t.archivo; }
                pintarNodo(n);
                if ((n.archivo && n.archivo.src) !== antes) avisarSalida(n);
            });
        } catch (e) {}
        if (nodos.some((n) => n.trabajo && ['pendiente', 'procesando', 'consultando'].includes(n.estado))) reloj = setTimeout(vigilar, 4000);
    }

    /* ---------- Guardar solo ---------- */
    const datos = () => ({
        nodos: nodos.map((n) => ({ id: n.id, tipo: n.tipo, x: n.x, y: n.y, texto: n.texto || '', formato: n.formato, segundos: n.segundos,
                                   trabajo: n.trabajo || null, subida: n.subida || null })),
        enlaces, vista, proyecto: $('#spProyecto').value,
    });
    let espera = null, guardando = null, otraVez = false;
    function cambio() {
        if (!cargado) return;
        estado('Sin guardar…');
        clearTimeout(espera);
        espera = setTimeout(guardar, 700);
    }
    async function guardar() {
        espera = null;
        if (guardando) { otraVez = true; return guardando; }
        guardando = (async () => {
            estado('Guardando…');
            try {
                const r = await api(null, { accion: 'space_guardar', id: space.id, nombre: $('#spNombre').value, datos: JSON.stringify(datos()) });
                const nuevo = !space.id;
                space.id = r.id;
                space.nombre = $('#spNombre').value.trim() || 'Mi Space';
                if (nuevo) await pintarLista();
                const op = [...$('#spLista').options].find((o) => Number(o.value) === space.id);
                if (op) op.textContent = space.nombre;
                try { localStorage.setItem('frSpace', String(space.id)); } catch (e) {}
                estado('Guardado');
            } catch (e) {
                estado('No se pudo guardar: ' + e.message, true);
            }
        })();
        await guardando;
        guardando = null;
        if (otraVez) { otraVez = false; await guardar(); }
    }
    async function guardarYa() {
        if (espera) { clearTimeout(espera); await guardar(); }
        else if (guardando) await guardando;
    }
    // Si se cierra la pestaña con cambios sin guardar, se mandan igual
    window.addEventListener('pagehide', () => {
        if (!espera || !cargado) return;
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('accion', 'space_guardar');
        fd.append('id', space.id);
        fd.append('nombre', $('#spNombre').value);
        fd.append('datos', JSON.stringify(datos()));
        try { navigator.sendBeacon(FR_BASE, fd); } catch (e) {}
    });
    $('#spNombre').addEventListener('input', cambio);
    $('#spProyecto').addEventListener('change', cambio);

    /* ---------- Tus Spaces: lista, abrir, nuevo, borrar ---------- */
    async function pintarLista() {
        const r = await api({ spaces: 0 });
        const sel = $('#spLista');
        sel.innerHTML = '';
        r.spaces.forEach((s) => sel.add(new Option(s.nombre, s.id)));
        if (!space.id) sel.add(new Option(($('#spNombre').value.trim() || 'Mi Space') + ' (sin guardar)', 0));
        sel.value = String(space.id);
        return r.spaces;
    }
    function aplicar(d) {
        mundo.querySelectorAll('.sp-nodo').forEach((x) => x.remove());
        elegido = null;
        nodos = (Array.isArray(d.nodos) ? d.nodos : []).filter((n) => n && TIPOS[n.tipo]).map((n) => ({
            texto: '', formato: '16:9', segundos: 8, trabajo: null, subida: null, ...n, estado: n.trabajo ? 'consultando' : '', archivo: null,
        }));
        // Los Spaces del primer día guardaban { de, a }: era Texto -> prompt
        enlaces = (Array.isArray(d.enlaces) ? d.enlaces : [])
            .map((e) => ({ de: e.de, a: e.a, sal: e.sal || 'texto', ent: e.ent || 'prompt' }))
            .filter((e) => {
                const nd = nodo(e.de), na = nodo(e.a);
                const cs = nd && conector(nd.tipo, 'salidas', e.sal), ce = na && conector(na.tipo, 'entradas', e.ent);
                return cs && ce && cs.t === ce.t;
            });
        vista = d.vista && typeof d.vista.z === 'number' ? { x: +d.vista.x || 0, y: +d.vista.y || 0, z: Math.min(2, Math.max(0.3, d.vista.z)) } : { x: 140, y: 90, z: 1 };
        const proy = typeof d.proyecto === 'string' ? d.proyecto : ($('#frProyecto') ? $('#frProyecto').value : '');
        $('#spProyecto').value = [...$('#spProyecto').options].some((o) => o.value === proy) ? proy : '';
        nodos.forEach((n) => { crearEl(n); pintarNodo(n); });
        pintarVista();
        pintarEntradas();
        ayuda();
        requestAnimationFrame(() => pintarCables());
    }
    async function abrirSpace(id) {
        await guardarYa();
        cargado = false;
        try {
            if (id) {
                const r = await api({ spaces: id });
                space = { id: r.id, nombre: r.nombre };
                aplicar(r.datos || {});
            } else {
                space = { id: 0, nombre: 'Mi Space' };
                aplicar({});
            }
            $('#spNombre').value = space.nombre;
            await pintarLista();
            if (space.id) { try { localStorage.setItem('frSpace', String(space.id)); } catch (e) {} }
            estado(space.id ? 'Guardado' : 'Se guarda al agregar el primer nodo');
        } catch (e) {
            estado(e.message, true);
        }
        cargado = true;
        vigilar();
    }
    $('#spLista').addEventListener('change', () => abrirSpace(Number($('#spLista').value)));
    $('#spNuevo').addEventListener('click', async () => {
        await guardarYa();
        cargado = false;
        space = { id: 0, nombre: 'Space ' + ($('#spLista').options.length + 1) };
        aplicar({});
        $('#spNombre').value = space.nombre;
        cargado = true;
        await guardar();
        $('#spNombre').select();
    });
    $('#spBorrar').addEventListener('click', async () => {
        if (!confirm('¿Borrar el Space "' + ($('#spNombre').value || 'Mi Space') + '"? Los videos e imágenes ya creados siguen en tu proyecto.')) return;
        clearTimeout(espera);
        espera = null;
        try {
            if (space.id) await api(null, { accion: 'space_borrar', id: space.id });
            space = { id: 0, nombre: 'Mi Space' };
            try { localStorage.removeItem('frSpace'); } catch (e) {}
            const lista = await pintarLista();
            await abrirSpace(lista.length ? lista[0].id : 0);
        } catch (e) {
            estado(e.message, true);
        }
    });

    /* ---------- Abrir y cerrar (a pantalla completa) ---------- */
    async function abrir() {
        raiz.hidden = false;
        document.body.style.overflow = 'hidden';
        if (location.hash !== '#space') history.replaceState(null, '', location.pathname + '#space');
        if (!primeraVez) { requestAnimationFrame(() => pintarCables()); return vigilar(); }
        primeraVez = false;
        try {
            const lista = await pintarLista();
            let id = 0;
            try { id = Number(localStorage.getItem('frSpace')) || 0; } catch (e) {}
            if (!lista.some((s) => s.id === id)) id = lista.length ? lista[0].id : 0;
            await abrirSpace(id);
        } catch (e) {
            estado(e.message, true);
            cargado = true;
        }
    }
    async function cerrar() {
        await guardarYa();
        clearTimeout(reloj);
        raiz.hidden = true;
        document.body.style.overflow = '';
        history.replaceState(null, '', location.pathname);
    }
    document.querySelectorAll('.fr [data-abre-space]').forEach((b) => b.addEventListener('click', abrir));
    $('#spVolver').addEventListener('click', cerrar);
    if (location.hash === '#space') abrir();
})();
</script>

<?php if ($frPublico) { ($frPublico['pie'])(); } else { include __DIR__ . '/app/footer.php'; } ?>
