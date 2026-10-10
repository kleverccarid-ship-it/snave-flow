<?php
/**
 * Cron de Flow: empuja la cola de generación cada minuto, aunque NADIE tenga la
 * página abierta. Así en el hosting los videos avanzan y se entregan solos, a la
 * misma velocidad que en local.
 *
 * Configúralo en Hostinger (hPanel -> Avanzado -> Trabajos Cron / Cron Jobs),
 * cada 1 minuto, con una de estas dos formas:
 *
 *   wget -q -O /dev/null "https://TU-DOMINIO/dashboard/flow-cron.php?k=TOKEN"
 *   curl -s "https://TU-DOMINIO/dashboard/flow-cron.php?k=TOKEN" > /dev/null
 *
 * El TOKEN exacto aparece en el panel (Crear con Flow -> tarjeta de conexión),
 * o se puede ver la URL lista para copiar allí mismo.
 *
 * Seguridad: sin el token correcto responde 403 y no hace nada. No usa la sesión
 * del panel (el cron no inicia sesión).
 */

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/class/flowcola.php';
require_once __DIR__ . '/class/flow_api.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    FlowCola::asegurarTablas($conn);
    FlowApi::asegurar($conn);

    // Por línea de comandos (cron tipo PHP: /usr/bin/php .../flow-cron.php) no hace
    // falta token: ejecutarlo ya implica acceso al servidor. Por HTTP (wget/curl)
    // sí se exige el token secreto, para que nadie de fuera lo dispare.
    $esCli = (php_sapi_name() === 'cli');
    if (!$esCli) {
        $k    = (string) ($_GET['k'] ?? '');
        $real = FlowApi::cronToken($conn);
        if ($real === '' || !hash_equals($real, $k)) {
            http_response_code(403);
            echo 'no autorizado';
            exit;
        }
    }

    // Un solo cron a la vez: si otra vuelta sigue corriendo, esta sale enseguida
    // (evita que dos ejecuciones del minuto se pisen).
    if (!(int) $conn->query("SELECT GET_LOCK('snave_flow_cron', 0)")->fetchColumn()) {
        echo 'ya corriendo';
        exit;
    }

    // Responde "ok" AL INSTANTE (para que "Ver resultado" del cron lo muestre sin
    // esperar los ~50 s) y sigue procesando por detrás.
    @set_time_limit(75);
    ignore_user_abort(true);
    echo 'ok ' . date('c') . " — procesando la cola…\n";
    @ob_flush(); @flush();

    // Repasa la cola VARIAS veces durante ~50 s (cada ~3 s), no una sola. Así en
    // producción arranca y detecta el "listo" casi tan rápido como con la página
    // abierta en local, aunque nadie tenga la página abierta.
    $fin = time() + 50;
    $vueltas = 0;
    while (true) {
        FlowCola::vencerAtrasados($conn);
        FlowApi::avanzar($conn);  // tiene su propio freno interno de 3 s
        $vueltas++;
        if (time() >= $fin) break;
        sleep(3);
    }

    $conn->query("SELECT RELEASE_LOCK('snave_flow_cron')");
    echo "listo ({$vueltas} vueltas)\n";
} catch (Throwable $e) {
    error_log('flow-cron: ' . $e->getMessage());
    http_response_code(500);
    echo 'error';
}
