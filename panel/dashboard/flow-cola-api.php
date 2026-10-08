<?php
/**
 * API de la cola de Flow para la extensión Flow Helper.
 *
 * Autenticación: cabecera X-Flow-Token con el token que cada usuario genera en
 * flow-cola.php. No usa la sesión del panel. Cada token es de un usuario y su
 * extensión solo ve y toca los pedidos de ese usuario.
 *
 * Se llama SIN ".php" (flow-cola-api?accion=...): el .htaccess redirige con un
 * 301 las URLs con .php, y esa redirección convierte los POST en GET.
 *
 *   GET  ?accion=ping                         comprobar token; contadores y configuración
 *   POST ?accion=latido    activa, mensaje, version, trabajo
 *                                             la extensión cuenta qué hace; responde la configuración
 *   GET  ?accion=siguiente                    toma el próximo trabajo (o null)
 *   POST ?accion=archivo   id, archivo        sube un video o imagen terminado
 *   POST ?accion=enlace    id, url, tipo      plan B: el enlace de Flow (caduca)
 *   POST ?accion=terminar  id, ok, mensaje, transitorio
 *                                             cierra el trabajo; transitorio=1 lo reintenta si quedan intentos
 */

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/class/flowcola.php';
require_once __DIR__ . '/class/flow_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// CORS solo para extensiones de Chrome: así la extensión conecta aunque el
// dominio del panel no esté en su manifest. Sin el token no pueden hacer nada.
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^chrome-extension://[a-p]{32}$#', $origen)) {
    header("Access-Control-Allow-Origin: $origen");
    header('Access-Control-Allow-Headers: X-Flow-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}
// Consulta previa del navegador (preflight): se responde sin tocar la base de datos
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function flowResponder($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    FlowCola::asegurarTablas($conn);

    // El token dice de quién es la extensión: solo verá y tocará los pedidos de su dueño
    $usuario = FlowCola::usuarioDeToken($conn, $_SERVER['HTTP_X_FLOW_TOKEN'] ?? '');
    if (!$usuario) {
        flowResponder(401, ['ok' => false, 'error' => 'Token incorrecto. Genera uno nuevo en la página "Crear con Flow" del panel y pégalo en la extensión.']);
    }
    $uid = $usuario['id'];
    // Desde que el admin puso la clave de Google (05/10/2026), genera el propio
    // servidor (class/flow_api.php): la extensión ya no toma pedidos, así nunca
    // hay dos generando el mismo
    FlowApi::asegurar($conn);
    if (FlowApi::activa($conn)) {
        flowResponder(410, ['ok' => false, 'error' => 'Ya no hace falta la extensión: SnaveCloud genera con la cuenta de Google Flow del administrador (Núcleo SNAVE). Puedes desinstalarla.']);
    }
    // Acceso de la página pública /flow desactivado o borrado: su extensión deja de funcionar
    if ($usuario['rol'] === FlowCola::ROL_ACCESO && !FlowCola::accesoActivo($conn, $uid)) {
        flowResponder(403, ['ok' => false, 'error' => 'Tu acceso a Flow está desactivado. Habla con el administrador.']);
    }
    if (in_array($usuario['rol'], FlowCola::ROLES_CLIENTE, true) && !FlowCola::config($conn)['clientes_activo']) {
        flowResponder(403, ['ok' => false, 'error' => 'El administrador desactivó Flow para clientes.']);
    }

    $accion = $_GET['accion'] ?? '';
    $esPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($accion === 'ping') {
        flowResponder(200, ['ok' => true, 'config' => FlowCola::configPara($conn, $uid)] + FlowCola::resumen($conn, $uid));
    }

    if ($accion === 'siguiente') {
        FlowCola::vencerAtrasados($conn);
        $t = FlowCola::tomarSiguiente($conn, $uid);
        flowResponder(200, ['ok' => true, 'trabajo' => $t ? FlowCola::paraExtension($t) : null]);
    }

    // ¿Sigue siendo mío y en proceso el pedido que estoy esperando? (si alguien
    // lo reintentó o lo borró, la extensión lo suelta en vez de esperar en vano)
    if ($accion === 'estado') {
        $t = FlowCola::buscar($conn, (int) ($_GET['id'] ?? 0));
        $estado = $t && (int) $t['creado_por'] === $uid ? $t['estado'] : 'no_existe';
        flowResponder(200, ['ok' => true, 'estado' => $estado]);
    }

    if (!$esPost) {
        flowResponder(400, ['ok' => false, 'error' => 'Acción desconocida.']);
    }

    // Subir, enlazar o cerrar solo pedidos propios
    if ((in_array($accion, ['archivo', 'enlace', 'terminar'], true) || ($accion === 'proyecto' && $id)) && !FlowCola::esDe($conn, $id, $uid)) {
        flowResponder(404, ['ok' => false, 'error' => 'Ese pedido no existe o no es tuyo.']);
    }

    if ($accion === 'archivo') {
        $error = FlowCola::guardarSubida($conn, $id, $_FILES['archivo'] ?? null);
        flowResponder($error ? 400 : 200, $error ? ['ok' => false, 'error' => $error] : ['ok' => true]);
    }

    if ($accion === 'enlace') {
        $error = FlowCola::guardarEnlace($conn, $id, $_POST['url'] ?? '', $_POST['tipo'] ?? 'video');
        flowResponder($error ? 400 : 200, $error ? ['ok' => false, 'error' => $error] : ['ok' => true]);
    }

    // La extensión cuenta en qué proyecto de Flow trabajó (o cuál creó)
    if ($accion === 'proyecto') {
        $ok = FlowCola::registrarProyecto($conn, $uid, $_POST['flow_id'] ?? '', $_POST['nombre'] ?? '', $id, $_POST['alias'] ?? '');
        flowResponder($ok ? 200 : 400, $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Proyecto no válido.']);
    }

    if ($accion === 'latido') {
        FlowCola::guardarLatido($conn, $uid, $_POST, $_SERVER['HTTP_X_FLOW_TOKEN'] ?? '');
        // El proyecto que la extensión tiene abierto queda en la lista de proyectos
        if (!empty($_POST['flow_id'])) FlowCola::registrarProyecto($conn, $uid, $_POST['flow_id'], $_POST['nombre'] ?? '');
        flowResponder(200, ['ok' => true, 'config' => FlowCola::configPara($conn, $uid)]);
    }

    if ($accion === 'terminar') {
        // El pedido queda apuntando al proyecto donde se generó (si iba en "Automático")
        $t = FlowCola::buscar($conn, $id);
        if (!empty($_POST['flow_id']) && $t && in_array($t['proyecto'], ['', 'nuevo'], true)) {
            FlowCola::registrarProyecto($conn, $uid, $_POST['flow_id'], $_POST['nombre'] ?? '', $id);
        }
        $hecho = FlowCola::terminar($conn, $id, ($_POST['ok'] ?? '') === '1', $_POST['mensaje'] ?? '', ($_POST['transitorio'] ?? '') === '1', ($_POST['rechazo'] ?? '') === '1');
        flowResponder($hecho ? 200 : 409, $hecho ? ['ok' => true] : ['ok' => false, 'error' => 'Ese trabajo no está en proceso.']);
    }

    flowResponder(400, ['ok' => false, 'error' => 'Acción desconocida.']);
} catch (Throwable $e) {
    error_log('flow-cola-api: ' . $e->getMessage());
    flowResponder(500, ['ok' => false, 'error' => 'Error del servidor.']);
}
