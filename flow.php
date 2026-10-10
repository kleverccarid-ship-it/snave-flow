<?php
/**
 * Página pública de Flow: /flow
 *
 * Se entra con un correo y una contraseña que crea el admin en
 * dashboard/flow-cola (tarjeta "Accesos a la página pública"). No hace falta
 * cuenta en el panel. Una vez dentro se ve la misma página que en el panel
 * (dashboard/flow-cola.php, en su modo público), sin el menú del panel.
 * Cada acceso genera en SU propia cuenta de Google Flow, con su extensión.
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
header('Cache-Control: no-store');
require_once __DIR__ . '/dashboard/config/conexion.php';
require_once __DIR__ . '/dashboard/class/flowcola.php';
FlowCola::asegurarTablas($conn);

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
if (empty($_SESSION['flow_login_csrf'])) $_SESSION['flow_login_csrf'] = bin2hex(random_bytes(16));

// Freno a quien prueba contraseñas: 5 fallos desde la misma conexión en 15 min
// bloquean esa conexión 15 min. Se guarda en tbl_flow_ajustes (clave por IP).
$ipClave = 'login_' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 30);
$leerFallos = function () use ($conn, $ipClave) {
    $st = $conn->prepare("SELECT valor FROM tbl_flow_ajustes WHERE clave = ?");
    $st->execute([$ipClave]);
    $f = json_decode((string) $st->fetchColumn(), true);
    return (is_array($f) && time() - (int) $f['t'] < 900) ? $f : ['n' => 0, 't' => time()];
};
$guardarFallos = function ($f) use ($conn, $ipClave) {
    $conn->prepare("INSERT INTO tbl_flow_ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
         ->execute([$ipClave, json_encode($f)]);
};

// Salir
if (isset($_GET['salir']) && hash_equals($_SESSION['flow_login_csrf'], (string) $_GET['salir'])) {
    unset($_SESSION['flow_admin_id'], $_SESSION['flow_acceso_id'], $_SESSION['flow_cliente_id'],
          $_SESSION['flow_csrf'], $_SESSION['flow_flash'], $_SESSION['flow_ultimo_crear'], $_SESSION['flow_ultimos_ajustes']);
    $_SESSION['flow_sso_desactivado'] = true;
    $_SESSION['flow_login_csrf'] = bin2hex(random_bytes(16));
    session_regenerate_id(true);
    header('Location: /flow');
    exit;
}

// Entrar: el tipo elegido determina qué cuenta se valida.
$errorLogin = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'entrar') {
    $fallos = $leerFallos();
    if (!hash_equals($_SESSION['flow_login_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errorLogin = 'La página caducó. Vuelve a intentarlo.';
    } elseif ($fallos['n'] >= 5) {
        $errorLogin = 'Demasiados intentos fallidos. Espera 15 minutos y vuelve a probar.';
    } else {
        $modoAdmin = ($_POST['tipo_acceso'] ?? 'cliente') === 'admin';
        $adm = $cli = $a = null;
        if ($modoAdmin) {
            $adm = FlowCola::entrarAdmin($conn, $_POST['correo'] ?? '', $_POST['clave'] ?? '');
        } else {
            // Prioridad al acceso público creado por el administrador.
            $a = FlowCola::entrarAcceso($conn, $_POST['correo'] ?? '', $_POST['clave'] ?? '');
            if (!$a) {
                $cli = FlowCola::entrarCliente($conn, $_POST['correo'] ?? '', $_POST['clave'] ?? '');
                if ($cli && !FlowCola::clienteFlowActivo($conn, (int) $cli['id'])) {
                    $cli = null; $errorLogin = 'Tu cuenta no tiene habilitado el servicio Flow. Pídeselo al administrador.';
                }
            }
        }
        if ($adm || $cli || $a) {
            session_regenerate_id(true);
            unset($_SESSION['flow_admin_id'], $_SESSION['flow_cliente_id'], $_SESSION['flow_acceso_id']);
            if ($adm) $_SESSION['flow_admin_id'] = (int) $adm['id'];
            elseif ($cli) $_SESSION['flow_cliente_id'] = (int) $cli['id'];
            else $_SESSION['flow_acceso_id'] = (int) $a['id'];
            $_SESSION['flow_sso_desactivado'] = true;
            $conn->prepare("DELETE FROM tbl_flow_ajustes WHERE clave = ?")->execute([$ipClave]);
            header('Location: /flow');
            exit;
        }
        $fallos['n']++;
        $guardarFallos($fallos);
        $errorLogin = $errorLogin ?: 'Correo o contraseña incorrectos, o no tienes acceso a Flow.';
    }
}

// ¿Sesión válida? (el admin puede haber desactivado o borrado el acceso/servicio)
$acceso = null;
$cliente = null;
$admin = null;
if (!empty($_SESSION['flow_admin_id'])) {
    // Admin entrando por /flow con su cuenta: sigue existiendo y es rol 1/5
    $st = $conn->prepare("SELECT id, nombre, apellidos, correo, rol FROM tbl_usuarios WHERE id = ? AND rol IN (1, 5)");
    $st->execute([(int) $_SESSION['flow_admin_id']]);
    $admin = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$admin) {
        unset($_SESSION['flow_admin_id']);
        if (!empty($_GET['vivo'])) { http_response_code(401); exit; }
    }
} elseif (!empty($_SESSION['flow_cliente_id'])) {
    // Cliente del panel: sigue existiendo (rol 2/3) y con Flow habilitado
    $st = $conn->prepare("SELECT id, nombre, apellidos, correo, rol FROM tbl_usuarios WHERE id = ? AND rol IN (2, 3)");
    $st->execute([(int) $_SESSION['flow_cliente_id']]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$cliente || !FlowCola::clienteFlowActivo($conn, (int) $cliente['id'])) {
        unset($_SESSION['flow_cliente_id']);
        $cliente = null;
        if (!empty($_GET['vivo'])) { http_response_code(401); exit; }
        $errorLogin = $errorLogin ?: 'Tu acceso a Flow fue desactivado. Habla con el administrador.';
    }
} elseif (!empty($_SESSION['flow_acceso_id'])) {
    $st = $conn->prepare("SELECT id, correo, nombre, activo FROM tbl_flow_accesos WHERE id = ?");
    $st->execute([(int) $_SESSION['flow_acceso_id']]);
    $acceso = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$acceso || (int) $acceso['activo'] !== 1) {
        unset($_SESSION['flow_acceso_id']);
        $acceso = null;
        if (!empty($_GET['vivo'])) { http_response_code(401); exit; }
        $errorLogin = $errorLogin ?: 'Tu acceso fue desactivado. Habla con el administrador.';
    }
}

// SSO: si YA está logueado en el panel de SnaveCloud como cliente/partner con el
// servicio Flow activo, entra directo a /flow (sin volver a escribir su contraseña).
if (!$cliente && !$acceso && !$admin && empty($_SESSION['flow_sso_desactivado']) && !empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $rol = (int) ($_SESSION['user_role'] ?? 0);
    if (in_array($rol, [1, 5], true)) {
        // Admin logueado en el panel entra directo a /flow con su cuenta
        $st = $conn->prepare("SELECT id, nombre, apellidos, correo, rol FROM tbl_usuarios WHERE id = ? AND rol IN (1, 5)");
        $st->execute([$uid]);
        $admin = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($admin) $_SESSION['flow_admin_id'] = $uid;
    } elseif (in_array($rol, [2, 3], true) && FlowCola::clienteFlowActivo($conn, $uid)) {
        $st = $conn->prepare("SELECT id, nombre, apellidos, correo, rol FROM tbl_usuarios WHERE id = ? AND rol IN (2, 3)");
        $st->execute([$uid]);
        $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cliente) $_SESSION['flow_cliente_id'] = $uid;
    }
}

// Cabecera y pie propios (la página pública no carga nada del panel)
$cabecera = function ($titulo) use ($h) {
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { margin:0; background:#f8f9fc; font-family:'Plus Jakarta Sans', system-ui, sans-serif; color:#1e1e1e; }
    .fp-barra { background:#6d00eb; color:#fff; display:flex; align-items:center; justify-content:space-between; gap:12px;
                padding:12px 22px; box-shadow:0 8px 24px -10px rgba(109,0,235,.5); }
    .fp-marca { font-weight:800; font-size:1.15rem; letter-spacing:-.3px; }
    .fp-marca span { opacity:.75; font-weight:600; }
    .fp-usuario { font-size:.85rem; display:flex; align-items:center; gap:12px; }
    .fp-usuario a { color:#fff; background:rgba(255,255,255,.18); border-radius:10px; padding:6px 12px; text-decoration:none; font-weight:700; }
    .container-fluid { max-width:1500px; margin:0 auto; padding:18px; box-sizing:border-box; }
</style>
</head>
<body>
<?php
};

if (!$acceso && !$cliente && !$admin) {
    $cabecera('Entrar · SnaveCloud Flow');
    ?>
    <style>
        .fp-login { max-width:380px; margin:9vh auto; background:#fff; border:1px solid #eceef4; border-radius:24px; padding:30px;
                    box-shadow:0 20px 50px -20px rgba(109,0,235,.35); box-sizing:border-box; }
        .fp-login h1 { margin:0 0 4px; font-size:1.5rem; }
        .fp-login p { margin:0 0 20px; color:#64748b; font-size:.9rem; }
        .fp-login label { display:block; font-weight:700; font-size:.85rem; margin:0 0 6px; }
        .fp-login select, .fp-login input { width:100%; box-sizing:border-box; border:1px solid #eceef4; border-radius:14px; padding:12px 14px; font:inherit; margin-bottom:14px; outline:none; }
        .fp-login select:focus, .fp-login input:focus { border-color:#6d00eb; box-shadow:0 0 0 3px rgba(109,0,235,.12); }
        .fp-login button { width:100%; border:0; border-radius:14px; padding:13px; font:inherit; font-weight:800; cursor:pointer;
                           background:linear-gradient(90deg,#00ff88,#00d471); color:#064e3b; box-shadow:0 10px 20px -5px rgba(0,255,136,.45); }
        .fp-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:12px; padding:10px 12px; font-size:.85rem; margin-bottom:14px; }
    </style>
    <div class="fp-barra"><div class="fp-marca">SnaveCloud <span>· Flow</span></div></div>
    <form method="post" class="fp-login" autocomplete="on">
        <h1>🎬 Crear con Flow</h1>
        <p>Entra con el correo y la contraseña de tu acceso.</p>
        <?php if ($errorLogin): ?><div class="fp-error"><?= $h($errorLogin) ?></div><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['flow_login_csrf']) ?>">
        <input type="hidden" name="accion" value="entrar">
        <label for="tipo_acceso">Entrar como</label>
        <select name="tipo_acceso" id="tipo_acceso">
            <option value="cliente" <?= ($_POST['tipo_acceso'] ?? 'cliente') !== 'admin' ? 'selected' : '' ?>>Cliente</option>
            <option value="admin" <?= ($_POST['tipo_acceso'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador</option>
        </select>
        <label for="correo">Correo</label>
        <input type="email" name="correo" id="correo" required autofocus autocomplete="username" value="<?= $h($_POST['correo'] ?? '') ?>">
        <label for="clave">Contraseña</label>
        <input type="password" name="clave" id="clave" required autocomplete="current-password">
        <button type="submit">Entrar</button>
    </form>
    </body></html>
    <?php
    exit;
}

// Dentro: la página de Flow en modo público (admin, cliente del panel o acceso legacy)
$yoNombre = $admin
    ? (trim(($admin['nombre'] ?? '') . ' ' . ($admin['apellidos'] ?? '')) ?: $admin['correo'])
    : ($cliente
        ? (trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellidos'] ?? '')) ?: $cliente['correo'])
        : ($acceso['nombre'] !== '' ? $acceso['nombre'] : $acceso['correo']));
$FR_PUBLICO = [
    'nombre'   => $yoNombre,
    'cabecera' => function () use ($cabecera, $yoNombre, $h) {
        $cabecera('Crear con Flow · SnaveCloud');
        ?>
        <div class="fp-barra">
            <div class="fp-marca">SnaveCloud <span>· Flow</span></div>
            <div class="fp-usuario">
                <span>👤 <?= $h($yoNombre) ?></span>
                <a href="/flow?salir=<?= $h($_SESSION['flow_login_csrf']) ?>">Salir</a>
            </div>
        </div>
        <?php
    },
    'pie' => function () { echo "</body>\n</html>\n"; },
];
if ($admin) {
    // Admin por /flow: su user_id REAL y su rol (1/5) → ve TODO el contenido
    $FR_PUBLICO['uid'] = (int) $admin['id'];
    $FR_PUBLICO['rol'] = (int) $admin['rol'];
} elseif ($cliente) {
    // Cliente del panel: usa su user_id REAL (su contenido se liga a su cuenta)
    $FR_PUBLICO['uid'] = (int) $cliente['id'];
    $FR_PUBLICO['rol'] = (int) $cliente['rol'];
} else {
    // Acceso público legacy: id del acceso (mapeado a un uid negativo)
    $FR_PUBLICO['id'] = (int) $acceso['id'];
}
require __DIR__ . '/dashboard/flow-cola.php';
