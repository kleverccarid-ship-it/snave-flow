<?php
/**
 * Cola de videos e imágenes de Google Flow.
 *
 * Cada usuario (admin, cliente o partner) encola prompts en flow-cola.php. Su
 * propia extensión Flow Helper, conectada con SU token, los genera en SU cuenta
 * de Google Flow (con sus créditos) y sube aquí los archivos. Una extensión
 * solo recibe los pedidos de su dueño: los pedidos de un cliente nunca se
 * generan en la cuenta de otro, igual que en La Casa Dark.
 *
 * Estados de un trabajo: pendiente -> procesando -> hecho | error.
 * Las tablas se crean solas, igual que el resto del panel; el equivalente en
 * SQL está en database_migrations/060, 061 y 062.
 */
class FlowCola
{
    // Carpeta pública (relativa a dashboard/) donde quedan los videos
    const CARPETA = 'uploads/flow';
    const MAX_BYTES = 200 * 1024 * 1024;
    const TIPOS_ARCHIVO = [
        'video/mp4'  => 'mp4',
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    // Roles del panel: 1 y 5 administran; 2 (cliente) y 3 (partner) usan la página si el admin lo permite
    const ROLES_ADMIN = [1, 5];
    const ROLES_CLIENTE = [2, 3];
    // Accesos de la página pública /flow (correo + contraseña que crea el admin).
    // No son usuarios del panel: en la cola usan un número NEGATIVO (-id del
    // acceso) para que nunca choquen con los ids de tbl_users.
    const ROL_ACCESO = 9;

    public static function asegurarTablas(PDO $conn)
    {
        static $listo = false;
        if ($listo) return;
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_trabajos (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                prompt        TEXT NOT NULL,
                tipo          VARCHAR(10) NOT NULL DEFAULT 'video',
                modelo        VARCHAR(60) NOT NULL DEFAULT '',
                formato       VARCHAR(10) NOT NULL DEFAULT '',
                segundos      TINYINT NOT NULL DEFAULT 8,
                cantidad      TINYINT NOT NULL DEFAULT 1,
                estado        VARCHAR(12) NOT NULL DEFAULT 'pendiente',
                intentos      TINYINT NOT NULL DEFAULT 0,
                mensaje       VARCHAR(255) NOT NULL DEFAULT '',
                archivos      TEXT NULL,
                creado_por    INT NULL,
                creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                tomado_en     DATETIME NULL,
                terminado_en  DATETIME NULL,
                KEY idx_estado (estado, id),
                KEY idx_dueno (creado_por, estado, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_ajustes (
                clave  VARCHAR(40) PRIMARY KEY,
                valor  VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Un token por usuario, con el latido y la pausa de SU extensión (migración 062)
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_tokens (
                usuario_id  INT PRIMARY KEY,
                rol         TINYINT NOT NULL DEFAULT 0,
                token_hash  CHAR(64) NOT NULL,
                procesar    TINYINT NOT NULL DEFAULT 1,
                latido      VARCHAR(255) NULL,
                creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_token (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Instalaciones anteriores (migraciones 061 y 062)
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'intentos'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN intentos TINYINT NOT NULL DEFAULT 0 AFTER estado");
        }
        if (!$conn->query("SHOW INDEX FROM tbl_flow_trabajos WHERE Key_name = 'idx_dueno'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD KEY idx_dueno (creado_por, estado, id)");
        }
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_tokens LIKE 'rol'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_tokens ADD COLUMN rol TINYINT NOT NULL DEFAULT 0 AFTER usuario_id");
        }
        // VARIOS tokens por cuenta (uno por perfil/dispositivo): antes usuario_id era
        // PRIMARY (un token por cuenta). Si aún no existe la columna 'id', se migra a
        // un id autoincrement y usuario_id pasa a ser un simple índice (ya no único).
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_tokens LIKE 'id'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_tokens
                DROP PRIMARY KEY,
                ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST,
                ADD INDEX idx_usuario (usuario_id)");
        }
        // Proyectos de Flow (migración 063): cada pedido va a un proyecto; la
        // extensión cuenta cuáles detecta o crea
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'proyecto'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos
                ADD COLUMN proyecto VARCHAR(64) NOT NULL DEFAULT '' AFTER cantidad,
                ADD COLUMN proyecto_nombre VARCHAR(120) NOT NULL DEFAULT '' AFTER proyecto");
        }
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_proyectos (
                usuario_id   INT NOT NULL,
                flow_id      VARCHAR(64) NOT NULL,
                nombre       VARCHAR(120) NOT NULL DEFAULT '',
                actualizado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (usuario_id, flow_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Accesos de la página pública /flow (migración 064)
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_accesos (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                correo         VARCHAR(190) NOT NULL,
                nombre         VARCHAR(120) NOT NULL DEFAULT '',
                pass_hash      VARCHAR(255) NOT NULL,
                activo         TINYINT NOT NULL DEFAULT 1,
                creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultimo_acceso  DATETIME NULL,
                UNIQUE KEY uq_correo (correo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Flow como servicio por cliente: qué usuarios del panel (tbl_usuarios) lo
        // tienen habilitado. Entran a /flow con SU cuenta del panel.
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_clientes (
                usuario_id  INT PRIMARY KEY,
                activo      TINYINT NOT NULL DEFAULT 1,
                creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Dispositivos que usó cada cuenta de /flow (para el límite de equipos por cliente)
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS tbl_flow_dispositivos (
                owner         INT NOT NULL,
                device        CHAR(32) NOT NULL,
                creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultimo_acceso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (owner, device),
                KEY idx_owner (owner, ultimo_acceso)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Nombre puesto a mano en el panel: el que da Flow ya no lo pisa
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_proyectos LIKE 'nombre_propio'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_proyectos ADD COLUMN nombre_propio TINYINT NOT NULL DEFAULT 0 AFTER nombre");
        }
        // Calidad del video que se entrega ('720p' o '1080p'; vacío = pedidos de antes, 720p)
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'calidad'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN calidad VARCHAR(10) NOT NULL DEFAULT '' AFTER segundos");
        }
        // dispositivo: aísla el contenido por navegador cuando varias personas
        // entran con la MISMA cuenta de acceso (vacío = sin aislamiento, como antes)
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'dispositivo'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN dispositivo VARCHAR(32) NOT NULL DEFAULT '' AFTER creado_por, ADD KEY idx_disp (creado_por, dispositivo, estado, id)");
        }
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_proyectos LIKE 'dispositivo'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_proyectos ADD COLUMN dispositivo VARCHAR(32) NOT NULL DEFAULT ''");
        }
        $listo = true;
    }

    /* ---------- Accesos de la página pública /flow ---------- */

    public static function uidDeAcceso($accesoId) { return -abs((int) $accesoId); }
    public static function esUidDeAcceso($uid) { return (int) $uid < 0; }

    // Lista para el admin, con cuántos pedidos tiene cada acceso
    public static function accesos(PDO $conn)
    {
        return $conn->query(
            "SELECT a.id, a.correo, a.nombre, a.activo, a.creado_en, a.ultimo_acceso,
                    (SELECT COUNT(*) FROM tbl_flow_trabajos t WHERE t.creado_por = -a.id) AS pedidos
             FROM tbl_flow_accesos a ORDER BY a.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function validarClave($clave)
    {
        return mb_strlen((string) $clave, 'UTF-8') >= 8 ? null : 'La contraseña debe tener al menos 8 caracteres.';
    }

    // Devuelve null o el motivo por el que no se pudo crear
    public static function crearAcceso(PDO $conn, $correo, $nombre, $clave)
    {
        $correo = mb_strtolower(trim((string) $correo), 'UTF-8');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return 'Escribe un correo válido.';
        if ($e = self::validarClave($clave)) return $e;
        $st = $conn->prepare("SELECT 1 FROM tbl_flow_accesos WHERE correo = ?");
        $st->execute([$correo]);
        if ($st->fetchColumn()) return 'Ya hay un acceso con ese correo.';
        $conn->prepare("INSERT INTO tbl_flow_accesos (correo, nombre, pass_hash) VALUES (?, ?, ?)")
             ->execute([$correo, mb_substr(trim((string) $nombre), 0, 120, 'UTF-8'), password_hash((string) $clave, PASSWORD_DEFAULT)]);
        return null;
    }

    public static function claveAcceso(PDO $conn, $id, $clave)
    {
        if ($e = self::validarClave($clave)) return $e;
        $conn->prepare("UPDATE tbl_flow_accesos SET pass_hash = ? WHERE id = ?")->execute([password_hash((string) $clave, PASSWORD_DEFAULT), (int) $id]);
        return null;
    }

    // Desactivar corta también su extensión (el token deja de valer mientras esté apagado)
    public static function estadoAcceso(PDO $conn, $id, $activo)
    {
        $conn->prepare("UPDATE tbl_flow_accesos SET activo = ? WHERE id = ?")->execute([$activo ? 1 : 0, (int) $id]);
    }

    // Borra el acceso y su token. Sus pedidos y archivos se quedan (el admin los ve).
    public static function borrarAcceso(PDO $conn, $id)
    {
        $conn->prepare("DELETE FROM tbl_flow_tokens WHERE usuario_id = ?")->execute([self::uidDeAcceso($id)]);
        $conn->prepare("DELETE FROM tbl_flow_accesos WHERE id = ?")->execute([(int) $id]);
    }

    public static function accesoActivo(PDO $conn, $id)
    {
        $st = $conn->prepare("SELECT activo FROM tbl_flow_accesos WHERE id = ?");
        $st->execute([abs((int) $id)]);
        return (int) $st->fetchColumn() === 1;
    }

    // Inicio de sesión en /flow. Devuelve el acceso o null. Siempre tarda lo
    // mismo (password_verify contra un hash falso si el correo no existe), para
    // no delatar qué correos están dados de alta.
    public static function entrarAcceso(PDO $conn, $correo, $clave)
    {
        $st = $conn->prepare("SELECT * FROM tbl_flow_accesos WHERE correo = ?");
        $st->execute([mb_strtolower(trim((string) $correo), 'UTF-8')]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        $hash = $a ? $a['pass_hash'] : '$2y$10$unl32BkgDr0pCpfjd6hCOe7UwDCpF4mN9pz3pHEVt8edaagjIK47i';
        $ok = password_verify((string) $clave, $hash);
        if (!$a || !$ok || (int) $a['activo'] !== 1) return null;
        $conn->prepare("UPDATE tbl_flow_accesos SET ultimo_acceso = NOW() WHERE id = ?")->execute([(int) $a['id']]);
        return $a;
    }

    /* ---------- Flow como servicio por cliente (cuentas del panel) ---------- */

    // Qué servicio del catálogo (tbl_servicios.id) representa "Flow". 0 = ninguno.
    public static function flowServicioId(PDO $conn)
    {
        return (int) self::ajuste($conn, 'flow_servicio_id');
    }

    public static function guardarFlowServicioId(PDO $conn, $sid)
    {
        self::guardarAjuste($conn, 'flow_servicio_id', (string) (int) $sid);
    }

    // ¿Este usuario del panel tiene Flow habilitado? Solo por el servicio del
    // catálogo seleccionado: tiene una compra/asignación vigente de ese servicio.
    public static function clienteFlowActivo(PDO $conn, $uid)
    {
        if ((int) $uid <= 0) return false;
        $sid = self::flowServicioId($conn);
        if ($sid <= 0) return false; // sin servicio seleccionado, nadie tiene Flow
        try {
            $st = $conn->prepare(
                "SELECT 1 FROM tbl_compra
                 WHERE cliente_id = ? AND servicio_id = ? AND deleted_at IS NULL
                   AND estado IN ('aprobado', 'autorizado', 'revision', 'preaprobado')
                   AND (end_date IS NULL OR end_date > NOW())
                 LIMIT 1"
            );
            $st->execute([(int) $uid, $sid]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    // Habilitar/pausar Flow a un cliente del panel
    public static function activarClienteFlow(PDO $conn, $uid, $activo)
    {
        $conn->prepare("INSERT INTO tbl_flow_clientes (usuario_id, activo) VALUES (?, ?) ON DUPLICATE KEY UPDATE activo = VALUES(activo)")
             ->execute([(int) $uid, $activo ? 1 : 0]);
    }

    // Quitar el servicio Flow a un cliente (sus pedidos se quedan)
    public static function quitarClienteFlow(PDO $conn, $uid)
    {
        $conn->prepare("DELETE FROM tbl_flow_clientes WHERE usuario_id = ?")->execute([(int) $uid]);
    }

    // Lista para el admin: clientes con Flow, con sus datos de tbl_usuarios
    public static function clientesFlow(PDO $conn)
    {
        return $conn->query(
            "SELECT c.usuario_id, c.activo, c.creado_en, u.nombre, u.apellidos, u.correo, u.rol,
                    (SELECT COUNT(*) FROM tbl_flow_trabajos t WHERE t.creado_por = c.usuario_id) AS pedidos
             FROM tbl_flow_clientes c JOIN tbl_usuarios u ON u.id = c.usuario_id
             ORDER BY c.creado_en DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // Buscar un cliente del panel (rol 2 o 3) por su correo, para habilitarlo
    public static function buscarClientePanel(PDO $conn, $correo)
    {
        $st = $conn->prepare("SELECT id, nombre, apellidos, correo, rol FROM tbl_usuarios WHERE correo = ? AND rol IN (2, 3)");
        $st->execute([mb_strtolower(trim((string) $correo), 'UTF-8')]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Login en /flow con la cuenta del panel: devuelve el usuario (rol 2/3) si el
    // correo y la contraseña son correctos, o null. NO comprueba si tiene Flow
    // habilitado (eso lo decide quien llama). Tarda parecido exista o no el correo.
    public static function entrarCliente(PDO $conn, $correo, $clave)
    {
        $st = $conn->prepare("SELECT id, nombre, apellidos, correo, pass, rol FROM tbl_usuarios WHERE correo = ? AND rol IN (2, 3)");
        $st->execute([mb_strtolower(trim((string) $correo), 'UTF-8')]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $hash = $u ? (string) $u['pass'] : '$2y$10$unl32BkgDr0pCpfjd6hCOe7UwDCpF4mN9pz3pHEVt8edaagjIK47i';
        // password_verify para claves con hash; comparación directa para claves antiguas en texto plano
        $ok = password_verify((string) $clave, $hash) || ($hash !== '' && $hash[0] !== '$' && hash_equals($hash, (string) $clave));
        return ($u && $ok) ? $u : null;
    }

    // Login de ADMINISTRADOR (rol 1) o admin secundario (rol 5) para entrar a /flow
    // con su cuenta. Ve todo el contenido, pero sin el peso del panel.
    public static function entrarAdmin(PDO $conn, $correo, $clave)
    {
        $st = $conn->prepare("SELECT id, nombre, apellidos, correo, pass, rol FROM tbl_usuarios WHERE correo = ? AND rol IN (1, 5)");
        $st->execute([mb_strtolower(trim((string) $correo), 'UTF-8')]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $hash = $u ? (string) $u['pass'] : '$2y$10$unl32BkgDr0pCpfjd6hCOe7UwDCpF4mN9pz3pHEVt8edaagjIK47i';
        $ok = password_verify((string) $clave, $hash) || ($hash !== '' && $hash[0] !== '$' && hash_equals($hash, (string) $clave));
        return ($u && $ok) ? $u : null;
    }

    /* ---------- Reglas (las pone el admin; valen para todas las extensiones) ---------- */

    // Como en La Casa Dark: el panel pone las reglas y la extensión las cumple
    const CONFIG_DEFECTO = [
        'pausa_seg'           => 3,     // respiro entre un video y el siguiente
        'espera_video_min'    => 10,    // cuánto esperar a que Flow termine un video
        'espera_imagen_min'   => 3,
        'max_intentos'        => 5,     // reintentos automáticos si Flow falla (alta demanda)
        'max_fallos_seguidos' => 2,     // fallos seguidos antes de parar (¿sin créditos?)
        'simultaneos'         => 3,     // pedidos generándose a la vez en Flow
        'clientes_activo'     => false, // si los clientes y partners pueden usar la página
        'limite_diario'       => 20,    // pedidos por cliente y día (los admin no tienen límite)
        'dispositivos_max'    => 2,     // equipos por cliente en /flow (0 = ilimitado); el admin no tiene límite
    ];

    private static function ajuste(PDO $conn, $clave)
    {
        $st = $conn->prepare("SELECT valor FROM tbl_flow_ajustes WHERE clave = ?");
        $st->execute([$clave]);
        return $st->fetchColumn();
    }

    private static function guardarAjuste(PDO $conn, $clave, $valor)
    {
        $conn->prepare(
            "INSERT INTO tbl_flow_ajustes (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        )->execute([$clave, $valor]);
    }

    public static function config(PDO $conn)
    {
        $c = json_decode((string) self::ajuste($conn, 'config'), true);
        $c = array_merge(self::CONFIG_DEFECTO, is_array($c) ? $c : []);
        unset($c['procesar']); // antes era global; ahora cada usuario pausa su extensión
        return $c;
    }

    // Solo valores dentro de rangos razonables; lo demás se queda como estaba
    public static function guardarConfig(PDO $conn, array $nueva)
    {
        $c = self::config($conn);
        $rangos = [
            'pausa_seg' => [0, 120], 'espera_video_min' => [2, 30], 'espera_imagen_min' => [1, 15],
            'max_intentos' => [1, 10], 'max_fallos_seguidos' => [1, 10], 'limite_diario' => [0, 500], 'simultaneos' => [1, 5],
            'dispositivos_max' => [0, 20],
        ];
        foreach ($rangos as $k => [$min, $max]) {
            if (isset($nueva[$k]) && is_numeric($nueva[$k])) $c[$k] = max($min, min($max, (int) $nueva[$k]));
        }
        if (array_key_exists('clientes_activo', $nueva)) $c['clientes_activo'] = (bool) $nueva['clientes_activo'];
        self::guardarAjuste($conn, 'config', json_encode($c));
        return $c;
    }

    // Máximo de dispositivos por cliente en /flow (0 = ilimitado). El admin no tiene límite.
    public static function dispositivosMax(PDO $conn)
    {
        return (int) self::config($conn)['dispositivos_max'];
    }

    // Registra/actualiza el dispositivo de una cuenta de /flow
    public static function registrarDispositivo(PDO $conn, $owner, $device)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $device)) return;
        $conn->prepare("INSERT INTO tbl_flow_dispositivos (owner, device) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultimo_acceso = NOW()")
             ->execute([(int) $owner, $device]);
    }

    // Cuántos dispositivos distintos usó una cuenta en los últimos $dias
    public static function contarDispositivos(PDO $conn, $owner, $dias = 30)
    {
        $st = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_dispositivos WHERE owner = ? AND ultimo_acceso >= (NOW() - INTERVAL ? DAY)");
        $st->execute([(int) $owner, (int) $dias]);
        return (int) $st->fetchColumn();
    }

    // Lo que recibe la extensión de un usuario: las reglas + si SU cola está en pausa
    public static function configPara(PDO $conn, $uid)
    {
        $c = self::config($conn);
        $st = $conn->prepare("SELECT procesar FROM tbl_flow_tokens WHERE usuario_id = ?");
        $st->execute([(int) $uid]);
        $p = $st->fetchColumn();
        $c['procesar'] = $p === false ? true : (bool) $p;
        unset($c['clientes_activo'], $c['limite_diario']);
        return $c;
    }

    public static function pausar(PDO $conn, $uid, $procesar)
    {
        $conn->prepare("UPDATE tbl_flow_tokens SET procesar = ? WHERE usuario_id = ?")->execute([$procesar ? 1 : 0, (int) $uid]);
    }

    /* ---------- Token de la extensión: uno por usuario ---------- */

    // Se guarda solo el hash: el token se enseña una vez, al crearlo. El rol va
    // al lado para que la API sepa si es de un cliente sin mirar la tabla de usuarios.
    public static function nuevoToken(PDO $conn, $uid, $rol)
    {
        $token = bin2hex(random_bytes(24));
        $conn->prepare(
            "INSERT INTO tbl_flow_tokens (usuario_id, rol, token_hash) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rol = VALUES(rol), token_hash = VALUES(token_hash), latido = NULL"
        )->execute([(int) $uid, (int) $rol, hash('sha256', $token)]);
        return $token;
    }

    public static function hayToken(PDO $conn, $uid)
    {
        $st = $conn->prepare("SELECT 1 FROM tbl_flow_tokens WHERE usuario_id = ?");
        $st->execute([(int) $uid]);
        return (bool) $st->fetchColumn();
    }

    // Fija un token PEGADO (el que genera la extension en el navegador). Igual que
    // nuevoToken pero con el valor que trae el usuario. Devuelve '' si ok, o un
    // mensaje de error. Asi el cliente copia su token de la extension y lo pega
    // aqui para activarla, en vez de generarlo en el panel.
    public static function fijarToken(PDO $conn, $uid, $rol, $token)
    {
        $token = strtolower(trim((string) $token));
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return 'El token debe tener 48 caracteres (a-f y 0-9). Cópialo tal cual desde la extensión.';
        }
        $hash = hash('sha256', $token);
        $st = $conn->prepare("SELECT usuario_id FROM tbl_flow_tokens WHERE token_hash = ?");
        $st->execute([$hash]);
        $due = $st->fetchColumn();
        if ($due !== false && (int) $due !== (int) $uid) {
            return 'Ese token ya está en uso por otra cuenta.';
        }
        // AGREGA el token (no reemplaza): una cuenta puede tener VARIOS tokens, uno
        // por perfil/dispositivo. Si ese mismo token ya estaba, solo actualiza el rol.
        $conn->prepare(
            "INSERT INTO tbl_flow_tokens (usuario_id, rol, token_hash) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rol = VALUES(rol)"
        )->execute([(int) $uid, (int) $rol, $hash]);
        return '';
    }

    // El dueño de un token ['id' => ..., 'rol' => ...], o null. Se busca por el hash (índice único).
    public static function usuarioDeToken(PDO $conn, $token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $st = $conn->prepare("SELECT usuario_id, rol FROM tbl_flow_tokens WHERE token_hash = ?");
        $st->execute([hash('sha256', $token)]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        return $f ? ['id' => (int) $f['usuario_id'], 'rol' => (int) $f['rol']] : null;
    }

    /* ---------- Latido: la extensión de cada usuario cuenta qué está haciendo ---------- */

    public static function guardarLatido(PDO $conn, $uid, array $e, $token = '')
    {
        $latido = [
            'activa'  => !empty($e['activa']),
            'mensaje' => mb_substr((string) ($e['mensaje'] ?? ''), 0, 90, 'UTF-8'),
            'version' => mb_substr((string) ($e['version'] ?? ''), 0, 12, 'UTF-8'),
            'trabajo' => (int) ($e['trabajo'] ?? 0),
            'cuenta'  => mb_substr((string) ($e['cuenta_flow'] ?? ''), 0, 80, 'UTF-8'),  // correo de la cuenta de Flow
            't'       => time(),
        ];
        // La columna es VARCHAR(255): si no cabe, se recorta primero el mensaje
        $json = json_encode($latido, JSON_UNESCAPED_UNICODE);
        while (strlen($json) > 250 && $latido['mensaje'] !== '') {
            $latido['mensaje'] = mb_substr($latido['mensaje'], 0, -10, 'UTF-8');
            $json = json_encode($latido, JSON_UNESCAPED_UNICODE);
        }
        // Con varios tokens por cuenta, el latido es POR TOKEN (cada perfil el suyo).
        $tok = strtolower(trim((string) $token));
        if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
            $conn->prepare("UPDATE tbl_flow_tokens SET latido = ? WHERE token_hash = ?")->execute([$json, hash('sha256', $tok)]);
        } else {
            $conn->prepare("UPDATE tbl_flow_tokens SET latido = ? WHERE usuario_id = ?")->execute([$json, (int) $uid]);
        }
    }

    // null si su extensión nunca se conectó. "conectada" = latido de hace menos de 45 s
    public static function estadoExtension(PDO $conn, $uid)
    {
        // Con varios tokens por cuenta: se junta el estado de TODOS. Conectada si
        // CUALQUIER perfil tiene latido reciente; se devuelve el latido más nuevo.
        $st = $conn->prepare("SELECT latido, procesar FROM tbl_flow_tokens WHERE usuario_id = ?");
        $st->execute([(int) $uid]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) return null;
        $procesar = false;
        $mejor = null;
        foreach ($filas as $fila) {
            if ((int) $fila['procesar']) $procesar = true;
            $l = json_decode((string) $fila['latido'], true);
            if (is_array($l) && ($mejor === null || (int) $l['t'] > (int) $mejor['t'])) $mejor = $l;
        }
        $estado = ['procesar' => $procesar, 'nunca' => $mejor === null];
        if ($mejor === null) return $estado + ['conectada' => false, 'activa' => false, 'mensaje' => '', 'version' => '', 'trabajo' => 0, 'hace' => null];
        $mejor['hace'] = time() - (int) $mejor['t'];
        $mejor['conectada'] = $mejor['hace'] < 45;
        return $estado + $mejor;
    }

    // Para el admin: cuántas extensiones tienen latido reciente
    public static function extensionesEnLinea(PDO $conn)
    {
        $n = 0;
        foreach ($conn->query("SELECT latido FROM tbl_flow_tokens WHERE latido IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $j) {
            $l = json_decode((string) $j, true);
            if (is_array($l) && time() - (int) $l['t'] < 45) $n++;
        }
        return $n;
    }

    // Lista TODAS las extensiones que tienen token: a qué cuenta pertenece, si
    // está conectada (latido < 45 s), su versión y cuándo fue la última señal.
    // Para que el admin vea de un vistazo qué tokens/extensiones están activos.
    public static function tokensConectados(PDO $conn)
    {
        $filas = $conn->query("SELECT usuario_id, rol, latido, creado_en FROM tbl_flow_tokens ORDER BY usuario_id")->fetchAll(PDO::FETCH_ASSOC);
        $accesos = [];
        foreach ($conn->query("SELECT id, correo, nombre FROM tbl_flow_accesos")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $accesos[-abs((int) $a['id'])] = $a['correo'] . ($a['nombre'] ? ' · ' . $a['nombre'] : '');
        }
        $out = [];
        foreach ($filas as $f) {
            $uid = (int) $f['usuario_id'];
            if ($uid < 0) {
                $cuenta = $accesos[$uid] ?? ('acceso #' . abs($uid));
                $tipo = 'Acceso';
            } else {
                $u = $conn->prepare("SELECT correo, nombre FROM tbl_usuarios WHERE id = ?");
                $u->execute([$uid]);
                $r = $u->fetch(PDO::FETCH_ASSOC);
                $cuenta = $r ? ($r['correo'] ?: $r['nombre']) : ('usuario #' . $uid);
                $tipo = in_array((int) $f['rol'], [1, 5], true) ? 'Admin' : ((int) $f['rol'] === 3 ? 'Partner' : ((int) $f['rol'] === 2 ? 'Cliente' : 'Usuario'));
            }
            $l = json_decode((string) $f['latido'], true);
            $hace = is_array($l) ? time() - (int) $l['t'] : null;
            $out[] = [
                'cuenta'     => $cuenta,
                'tipo'       => $tipo,
                'cuentaFlow' => is_array($l) ? ($l['cuenta'] ?? '') : '',   // correo de Flow detectado
                'conectada'  => is_array($l) && $hace !== null && $hace < 45,
                'nunca'      => !is_array($l),
                'version'    => is_array($l) ? ($l['version'] ?? '') : '',
                'activa'     => is_array($l) ? !empty($l['activa']) : false,
                'hace'       => $hace,
                'creado'     => $f['creado_en'],
            ];
        }
        return $out;
    }

    /* ---------- Trabajos ---------- */

    // $varios: un trabajo por línea de $texto. Si no, todo el texto es un solo
    // prompt (los saltos de línea se vuelven espacios). Todos con los mismos ajustes.
    public static function crear(PDO $conn, $texto, array $a, $userId, $varios = true, $maximo = null, $dispositivo = '')
    {
        $prompts = $varios
            ? array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $texto))))
            : array_filter([trim(preg_replace('/\s+/u', ' ', (string) $texto))]);
        if ($maximo !== null) $prompts = array_slice($prompts, 0, max(0, (int) $maximo));
        $st = $conn->prepare(
            "INSERT INTO tbl_flow_trabajos (prompt, tipo, modelo, formato, segundos, calidad, cantidad, proyecto, proyecto_nombre, creado_por, dispositivo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $calidad = ($a['tipo'] ?? '') === 'video' && isset(self::CALIDADES[$a['calidad'] ?? '']) ? $a['calidad'] : '';
        $disp = mb_substr((string) $dispositivo, 0, 32, 'UTF-8');
        foreach ($prompts as $p) {
            $st->execute([mb_substr($p, 0, 4000, 'UTF-8'), $a['tipo'], $a['modelo'], $a['formato'], $a['segundos'], $calidad, $a['cantidad'],
                          $a['proyecto'] ?? '', mb_substr($a['proyecto_nombre'] ?? '', 0, 120, 'UTF-8'), $userId, $disp]);
        }
        return count($prompts);
    }

    /* ---------- Proyectos de Flow ---------- */

    // '' = automático (el último usado), 'nuevo' = que la extensión cree uno, o el id de Flow
    public static function proyectoValido($p)
    {
        return $p === '' || $p === 'nuevo' || (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) $p);
    }

    // Proyecto creado con el "+" de la página, antes de existir en Flow: se
    // guarda con un id provisional "nuevo-…" y su nombre. La extensión crea el
    // proyecto en Flow con el primer pedido y lo cuenta (registrarProyecto con
    // alias): desde ahí el proyecto y todos sus pedidos pasan al id de Flow.
    public static function esProyectoPanel($p)
    {
        return (bool) preg_match('/^nuevo-[a-f0-9]{16}$/', (string) $p);
    }

    public static function crearProyecto(PDO $conn, $uid, $nombre, $dispositivo = '')
    {
        $nombre = mb_substr(trim((string) $nombre), 0, 120, 'UTF-8');
        if ($nombre === '') return null;
        $id = 'nuevo-' . bin2hex(random_bytes(8));
        $conn->prepare("INSERT INTO tbl_flow_proyectos (usuario_id, flow_id, nombre, nombre_propio, dispositivo, actualizado) VALUES (?, ?, ?, 1, ?, NOW())")
             ->execute([(int) $uid, $id, $nombre, mb_substr((string) $dispositivo, 0, 32, 'UTF-8')]);
        return $id;
    }

    // La extensión cuenta un proyecto que usó o creó. Si viene con un pedido,
    // el pedido queda apuntando a ese proyecto (y los demás pedidos "nuevo"
    // con el mismo nombre también, para que vayan todos juntos).
    // $alias: el id provisional "nuevo-…" del panel que acaba de crearse en Flow.
    public static function registrarProyecto(PDO $conn, $uid, $flowId, $nombre, $trabajoId = 0, $alias = '')
    {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) $flowId) || self::esProyectoPanel($flowId)) return false;
        $nombre = mb_substr(trim((string) $nombre), 0, 120, 'UTF-8');
        $esPropio = 0;
        // Proyecto del "+" del panel: se queda con su nombre y se lleva sus pedidos
        if (self::esProyectoPanel($alias)) {
            $st = $conn->prepare("SELECT nombre FROM tbl_flow_proyectos WHERE usuario_id = ? AND flow_id = ?");
            $st->execute([(int) $uid, $alias]);
            $delPanel = $st->fetchColumn();
            if ($delPanel !== false) {
                $conn->prepare("DELETE FROM tbl_flow_proyectos WHERE usuario_id = ? AND flow_id = ?")->execute([(int) $uid, $alias]);
                $conn->prepare("UPDATE tbl_flow_trabajos SET proyecto = ?, proyecto_nombre = ? WHERE creado_por = ? AND proyecto = ?")
                     ->execute([$flowId, $delPanel, (int) $uid, $alias]);
                $nombre = $delPanel;
                $esPropio = 1;
            }
        }
        // Si la persona le puso nombre en el panel, ese manda sobre el de Flow
        if (!$esPropio) {
            $st = $conn->prepare("SELECT nombre FROM tbl_flow_proyectos WHERE usuario_id = ? AND flow_id = ? AND nombre_propio = 1");
            $st->execute([(int) $uid, $flowId]);
            $propio = $st->fetchColumn();
            if ($propio !== false) $nombre = $propio;
        }
        if ($trabajoId) {
            $t = self::buscar($conn, $trabajoId);
            if ($t && (int) $t['creado_por'] === (int) $uid) {
                // El nombre que puso la persona manda, y ya no lo pisa el que da Flow
                if (!$esPropio && $t['proyecto'] === 'nuevo' && $t['proyecto_nombre'] !== '') { $nombre = $t['proyecto_nombre']; $esPropio = 1; }
                $conn->prepare("UPDATE tbl_flow_trabajos SET proyecto = ?, proyecto_nombre = ? WHERE id = ?")->execute([$flowId, $nombre, (int) $trabajoId]);
                if ($t['proyecto'] === 'nuevo' && $t['proyecto_nombre'] !== '') {
                    $conn->prepare("UPDATE tbl_flow_trabajos SET proyecto = ? WHERE creado_por = ? AND proyecto = 'nuevo' AND proyecto_nombre = ? AND estado IN ('pendiente', 'procesando')")
                         ->execute([$flowId, (int) $uid, $t['proyecto_nombre']]);
                }
            }
        }
        $conn->prepare(
            "INSERT INTO tbl_flow_proyectos (usuario_id, flow_id, nombre, nombre_propio, actualizado) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                nombre = IF(VALUES(nombre_propio) = 1, VALUES(nombre), IF(nombre_propio = 1 OR VALUES(nombre) = '', nombre, VALUES(nombre))),
                nombre_propio = GREATEST(nombre_propio, VALUES(nombre_propio)), actualizado = NOW()"
        )->execute([(int) $uid, $flowId, $nombre, $esPropio]);
        return true;
    }

    // Nombre puesto a mano en el panel (solo en SnaveCloud; en Flow no cambia).
    // Con $dispositivo, solo deja renombrar un proyecto de ese mismo navegador.
    public static function renombrarProyecto(PDO $conn, $uid, $flowId, $nombre, $dispositivo = null)
    {
        $nombre = mb_substr(trim((string) $nombre), 0, 120, 'UTF-8');
        if ($nombre === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) $flowId)) return false;
        $porDisp = $dispositivo !== null && $dispositivo !== '';
        if ($porDisp) {
            // No crear uno nuevo ni tocar el de otro equipo: solo si ya es de este navegador
            $st = $conn->prepare("SELECT 1 FROM tbl_flow_proyectos WHERE usuario_id = ? AND flow_id = ? AND dispositivo = ?");
            $st->execute([(int) $uid, $flowId, $dispositivo]);
            if (!$st->fetchColumn()) return false;
            $conn->prepare("UPDATE tbl_flow_proyectos SET nombre = ?, nombre_propio = 1 WHERE usuario_id = ? AND flow_id = ? AND dispositivo = ?")
                 ->execute([$nombre, (int) $uid, $flowId, $dispositivo]);
            $conn->prepare("UPDATE tbl_flow_trabajos SET proyecto_nombre = ? WHERE creado_por = ? AND proyecto = ? AND dispositivo = ?")
                 ->execute([$nombre, (int) $uid, $flowId, $dispositivo]);
            return true;
        }
        $conn->prepare(
            "INSERT INTO tbl_flow_proyectos (usuario_id, flow_id, nombre, nombre_propio, actualizado) VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), nombre_propio = 1"
        )->execute([(int) $uid, $flowId, $nombre]);
        $conn->prepare("UPDATE tbl_flow_trabajos SET proyecto_nombre = ? WHERE creado_por = ? AND proyecto = ?")->execute([$nombre, (int) $uid, $flowId]);
        return true;
    }

    // Proyectos del usuario, el más usado recientemente primero, con cuántos pedidos
    // tiene. $dispositivo = solo los de ese navegador (cuenta compartida).
    public static function proyectos(PDO $conn, $uid, $dispositivo = null)
    {
        $porDisp = $dispositivo !== null && $dispositivo !== '';
        $st = $conn->prepare(
            "SELECT p.flow_id, p.nombre, p.actualizado,
                    (SELECT COUNT(*) FROM tbl_flow_trabajos t WHERE t.creado_por = p.usuario_id AND t.proyecto = p.flow_id" . ($porDisp ? " AND t.dispositivo = ?" : "") . ") AS pedidos
             FROM tbl_flow_proyectos p WHERE p.usuario_id = ?" . ($porDisp ? " AND p.dispositivo = ?" : "") . " ORDER BY p.actualizado DESC LIMIT 50"
        );
        $params = [];
        if ($porDisp) $params[] = $dispositivo; // subconsulta de pedidos
        $params[] = (int) $uid;
        if ($porDisp) $params[] = $dispositivo; // filtro del proyecto
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function pedidosHoy(PDO $conn, $uid, $dispositivo = null)
    {
        $sql = "SELECT COUNT(*) FROM tbl_flow_trabajos WHERE creado_por = ? AND creado_en >= CURDATE()";
        $params = [(int) $uid];
        if ($dispositivo !== null && $dispositivo !== '') { $sql .= " AND dispositivo = ?"; $params[] = $dispositivo; }
        $st = $conn->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    // El más antiguo pendiente DEL USUARIO pasa a "procesando". null si no hay ninguno.
    public static function tomarSiguiente(PDO $conn, $uid)
    {
        $conn->beginTransaction();
        try {
            $st = $conn->prepare(
                "SELECT * FROM tbl_flow_trabajos WHERE creado_por = ? AND estado = 'pendiente' ORDER BY id LIMIT 1 FOR UPDATE"
            );
            $st->execute([(int) $uid]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if ($t) {
                $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'procesando', tomado_en = NOW(), mensaje = '' WHERE id = ?")
                     ->execute([$t['id']]);
            }
            $conn->commit();
            return $t ?: null;
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // Lo que necesita la extensión, con los nombres de sus ajustes
    public static function paraExtension(array $t)
    {
        return [
            'id'     => (int) $t['id'],
            'prompt' => $t['prompt'],
            'mode'   => $t['tipo'],
            'model'  => $t['modelo'],
            'aspect' => $t['formato'],
            'dur'    => (string) $t['segundos'],
            'count'  => (string) $t['cantidad'],
            // '1080p': la extensión lo baja de Flow mejorado (sin créditos); si no, el original
            'calidad' => (string) ($t['calidad'] ?? ''),
            // Ya se intentó antes (reintento automático o "Reintentar"): la
            // extensión mira primero si Flow ya lo había creado, para no repetirlo
            'reintento'       => (int) $t['intentos'] > 0 || (string) $t['mensaje'] !== '',
            'proyecto'        => (string) ($t['proyecto'] ?? ''),
            'proyecto_nombre' => (string) ($t['proyecto_nombre'] ?? ''),
            // Imágenes de entrada (inicio, fin, referencias) para que la extensión
            // las suba a Flow antes de generar. Cada una: { rol, ruta }.
            'entradas'        => self::entradasParaExtension($t),
        ];
    }

    // Lista plana de las imágenes de entrada para la extensión: [{ rol, ruta }].
    // La ruta es relativa (uploads/flow/...) y la extensión la resuelve contra la
    // URL del panel; o absoluta (http...) si es de R2. inicio/fin van tal cual;
    // referencias e ingredientes se mandan como rol 'referencia'.
    public static function entradasParaExtension(array $t)
    {
        $e = json_decode((string) ($t['entradas'] ?? ''), true);
        if (!is_array($e)) return [];
        $out = [];
        if (!empty($e['inicio'])) $out[] = ['rol' => 'inicio', 'ruta' => (string) $e['inicio']];
        if (!empty($e['fin']))    $out[] = ['rol' => 'fin',    'ruta' => (string) $e['fin']];
        foreach (['referencias', 'ingredientes'] as $k) {
            foreach ((array) ($e[$k] ?? []) as $r) {
                if ($r) $out[] = ['rol' => 'referencia', 'ruta' => (string) $r];
            }
        }
        return $out;
    }

    public static function buscar(PDO $conn, $id)
    {
        $st = $conn->prepare("SELECT * FROM tbl_flow_trabajos WHERE id = ?");
        $st->execute([(int) $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ¿Es de este usuario? (la API y la página no dejan tocar pedidos ajenos).
    // Con $dispositivo, además tiene que ser del mismo navegador (cuenta compartida).
    public static function esDe(PDO $conn, $id, $uid, $dispositivo = null)
    {
        $t = self::buscar($conn, $id);
        if (!$t || (int) $t['creado_por'] !== (int) $uid) return false;
        if ($dispositivo !== null && $dispositivo !== '' && (string) ($t['dispositivo'] ?? '') !== (string) $dispositivo) return false;
        return true;
    }

    public static function archivosDe(array $t)
    {
        $lista = json_decode((string) $t['archivos'], true);
        return is_array($lista) ? $lista : [];
    }

    private static function agregarArchivo(PDO $conn, $id, array $archivo)
    {
        // Leer y escribir dentro de la misma transacción: pueden llegar varios a la vez
        $conn->beginTransaction();
        try {
            $st = $conn->prepare("SELECT archivos FROM tbl_flow_trabajos WHERE id = ? FOR UPDATE");
            $st->execute([(int) $id]);
            $lista = json_decode((string) $st->fetchColumn(), true);
            $lista = is_array($lista) ? $lista : [];
            $lista[] = $archivo;
            $conn->prepare("UPDATE tbl_flow_trabajos SET archivos = ? WHERE id = ?")
                 ->execute([json_encode($lista, JSON_UNESCAPED_SLASHES), (int) $id]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    // Se puede entregar si está generándose, o si volvió a "en espera" (por un
    // Reintentar o un reinicio) pero la extensión ya lo había generado: mejor
    // aceptarlo que generarlo dos veces
    private static function entregable(array $t)
    {
        return in_array($t['estado'], ['procesando', 'pendiente'], true);
    }

    // Guarda un archivo subido por la extensión. Devuelve null o el motivo del fallo.
    public static function guardarSubida(PDO $conn, $id, $subida)
    {
        $t = self::buscar($conn, $id);
        if (!$t || !self::entregable($t)) return 'Ese trabajo no está en proceso.';
        if (!is_array($subida) || ($subida['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'No llegó el archivo (código ' . ($subida['error'] ?? '?') . '). Revisa upload_max_filesize y post_max_size.';
        }
        if ($subida['size'] > self::MAX_BYTES) return 'Archivo demasiado grande.';

        // El tipo se mira en el contenido, no en lo que diga el navegador
        $mime = function_exists('finfo_open')
            ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $subida['tmp_name'])
            : mime_content_type($subida['tmp_name']);
        if (!isset(self::TIPOS_ARCHIVO[$mime])) return "Tipo de archivo no permitido ($mime).";

        $dir = __DIR__ . '/../' . self::CARPETA;
        self::asegurarCarpeta($dir);
        $nombre = sprintf('%d_%s.%s', $t['id'], bin2hex(random_bytes(6)), self::TIPOS_ARCHIVO[$mime]);
        if (!move_uploaded_file($subida['tmp_name'], "$dir/$nombre")) return 'No se pudo guardar el archivo en el servidor.';

        self::agregarArchivo($conn, $t['id'], ['ruta' => self::CARPETA . '/' . $nombre, 'tipo' => strpos($mime, 'video/') === 0 ? 'video' : 'imagen']);
        return null;
    }

    // Plan B si la subida falla: el enlace de Flow
    public static function guardarEnlace(PDO $conn, $id, $url, $tipo)
    {
        // Dominios de Flow/Google donde vive el video: flow.google.com/asb/ (el
        // <video>), flow-content.google, *.googleusercontent.com, *.googlevideo.com
        // (el MP4) y *.googleapis.com.
        if (!preg_match('#^https://([a-z0-9-]+\.)*(google\.com|flow-content\.google|googleusercontent\.com|googlevideo\.com|googleapis\.com)/#i', (string) $url)) return 'Enlace no válido.';
        $t = self::buscar($conn, $id);
        if (!$t || !self::entregable($t)) return 'Ese trabajo no está en proceso.';
        self::agregarArchivo($conn, $t['id'], ['url' => $url, 'tipo' => $tipo === 'imagen' ? 'imagen' : 'video', 'caduca' => true]);
        return null;
    }

    // $transitorio: fallo de Flow que suele arreglarse solo (alta demanda). Si
    // quedan intentos, el trabajo vuelve a la cola en vez de quedarse en error.
    // $rechazo: Flow no quiso generarlo (política de contenido, o falló hasta
    // agotar los intentos). Queda como "rechazado" con el texto que dio Flow.
    public static function terminar(PDO $conn, $id, $ok, $mensaje, $transitorio = false, $rechazo = false)
    {
        if (!$ok && $transitorio) {
            $t = self::buscar($conn, $id);
            $max = (int) self::config($conn)['max_intentos'];
            if ($t && $t['estado'] === 'procesando' && (int) $t['intentos'] + 1 < $max) {
                $n = (int) $t['intentos'] + 1;
                $st = $conn->prepare(
                    "UPDATE tbl_flow_trabajos SET estado = 'pendiente', intentos = ?, mensaje = ?, tomado_en = NULL
                     WHERE id = ? AND estado = 'procesando'"
                );
                $st->execute([$n, mb_substr("Reintento $n de " . ($max - 1) . ": $mensaje", 0, 255, 'UTF-8'), (int) $id]);
                return $st->rowCount() > 0;
            }
        }
        // Una entrega buena vale aunque el pedido hubiera vuelto a "en espera"
        // (así no se genera dos veces); un fallo solo cierra lo que está en proceso
        $st = $conn->prepare(
            "UPDATE tbl_flow_trabajos SET estado = ?, mensaje = ?, terminado_en = NOW(), tomado_en = COALESCE(tomado_en, NOW())
             WHERE id = ? AND estado " . ($ok ? "IN ('procesando', 'pendiente')" : "= 'procesando'")
        );
        $st->execute([$ok ? 'hecho' : ($rechazo ? 'rechazado' : 'error'), mb_substr((string) $mensaje, 0, 255, 'UTF-8'), (int) $id]);
        return $st->rowCount() > 0;
    }

    // Calidades de video que se pueden pedir: las dos que Flow da sin créditos
    // (el 4K cuesta 50 créditos y no se ofrece), con lo que tardan de más
    const CALIDADES = [
        '720p'  => ['nombre' => '720p · Original',  'extra_min' => 0, 'aprox' => 'Llega en cuanto Flow termina: unos 2 a 4 min.'],
        '1080p' => ['nombre' => '1080p · Mejorado', 'extra_min' => 3, 'aprox' => 'Flow lo mejora antes de entregarlo: 1 a 3 min más (unos 3 a 7 min en total).'],
    ];

    // Plazo máximo de entrega (la espera máxima de las reglas + 3 min para
    // subirlo, + lo que Flow tarda en preparar el 1080p)
    public static function minutosEntrega(array $config, $tipo, $calidad = '')
    {
        return (int) ($tipo === 'imagen' ? $config['espera_imagen_min'] : $config['espera_video_min']) + 3
            + ($tipo === 'imagen' ? 0 : (self::CALIDADES[$calidad]['extra_min'] ?? 0));
    }

    // Los que pasaron el plazo sin respuesta no se quedan "generándose" para
    // siempre. PERO: un video que todavía tiene jobid en Núcleo SNAVE
    // (operacion <> '') SIGUE generándose en Google; NO se corta en el plazo
    // normal, solo tras un tope duro (45 min). Así, aunque Hostinger vaya lento o
    // nadie tenga la pestaña abierta, avanzar() lo sigue consultando y lo entrega
    // cuando Google termina, sin abandonarlo ni duplicarlo.
    // Los que de verdad se atascan (imágenes, o videos que ni arrancaron) se
    // REINTENTAN SOLOS (vuelven a la cola) hasta agotar max_intentos.
    public static function vencerAtrasados(PDO $conn)
    {
        $c = self::config($conn);
        $max     = max(1, (int) $c['max_intentos']);
        $orphan  = 2;   // envío/lanzamiento colgado (el POST tiene 60s de tope): a los 2 min es huérfano
        $hardCap = 45;  // video que SÍ tiene jobid en Google: se le da hasta 45 min

        // "Atrasado":
        //  · operacion = '' → nunca llegó a tener jobid (el envío se cortó a medias, o
        //    una imagen se colgó). Queda ocupando cupo sin que avanzar() lo retome, así
        //    que se RESCATA pronto (2 min) y vuelve a la cola para relanzarlo.
        //  · video CON jobid → sigue generándose en Google; solo se corta tras el tope duro.
        $cond = "estado = 'procesando' AND (
                   (operacion = ''  AND tomado_en < NOW() - INTERVAL $orphan MINUTE)
                OR (tipo <> 'imagen' AND operacion <> '' AND tomado_en < NOW() - INTERVAL $hardCap MINUTE))";

        // 1) AUTO-REINTENTO: si aún quedan intentos, vuelve a la cola (limpiando el
        //    jobid muerto, para arrancar de cero).
        $conn->prepare(
            "UPDATE tbl_flow_trabajos
                SET estado = 'pendiente', intentos = intentos + 1, operacion = '',
                    tomado_en = NULL, terminado_en = NULL,
                    mensaje = CONCAT('Reintentando automáticamente (', intentos + 1, ')…')
              WHERE $cond AND intentos + 1 < $max"
        )->execute();

        // 2) AGOTADOS: ya no quedan intentos -> error (con botón Reintentar manual)
        $conn->prepare(
            "UPDATE tbl_flow_trabajos
                SET estado = 'error', terminado_en = NOW(),
                    mensaje = 'No se pudo generar tras varios intentos. Pulsa Reintentar.'
              WHERE $cond AND intentos + 1 >= $max"
        )->execute();
    }

    public static function reintentar(PDO $conn, $id)
    {
        $conn->prepare(
            "UPDATE tbl_flow_trabajos SET estado = 'pendiente', intentos = 0, mensaje = 'Reintento pedido desde el panel.', tomado_en = NULL, terminado_en = NULL
             WHERE id = ? AND estado IN ('error', 'procesando', 'rechazado')"
        )->execute([(int) $id]);
    }

    public static function borrar(PDO $conn, $id)
    {
        $t = self::buscar($conn, $id);
        if (!$t) return;
        foreach (self::archivosDe($t) as $a) {
            if (!empty($a['ruta']) && strpos($a['ruta'], self::CARPETA . '/') === 0) {
                @unlink(__DIR__ . '/../' . $a['ruta']);
            }
        }
        $conn->prepare("DELETE FROM tbl_flow_trabajos WHERE id = ?")->execute([(int) $id]);
    }

    // VIDEOS "activos" de un usuario/dispositivo: en espera o generándose. Se usa
    // para el límite de N a la vez. Las imágenes (Nano Banana) son ilimitadas y no cuentan.
    public static function activos(PDO $conn, $uid, $dispositivo = null)
    {
        $sql = "SELECT COUNT(*) FROM tbl_flow_trabajos WHERE creado_por = ? AND estado IN ('pendiente', 'procesando') AND tipo <> 'imagen'";
        $params = [(int) $uid];
        if ($dispositivo !== null && $dispositivo !== '') { $sql .= " AND dispositivo = ?"; $params[] = $dispositivo; }
        $st = $conn->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    // $uid null = de todos (vista del admin). $dispositivo = aísla por navegador.
    public static function contar(PDO $conn, $estado, $uid = null, $dispositivo = null)
    {
        $sql = "SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado = ?";
        $params = [$estado];
        if ($uid !== null) { $sql .= " AND creado_por = ?"; $params[] = (int) $uid; }
        if ($dispositivo !== null && $dispositivo !== '') { $sql .= " AND dispositivo = ?"; $params[] = $dispositivo; }
        $st = $conn->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    // Totales para el TABLERO del panel (admin): números globales por estado y por
    // tipo, en UNA sola consulta. $uid null = de todos (vista admin).
    public static function totales(PDO $conn, $uid = null, $dispositivo = null)
    {
        $where = []; $params = [];
        if ($uid !== null) { $where[] = "creado_por = ?"; $params[] = (int) $uid; }
        if ($dispositivo !== null && $dispositivo !== '') { $where[] = "dispositivo = ?"; $params[] = $dispositivo; }
        $w = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $st = $conn->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(estado = 'pendiente')  AS pendientes,
                SUM(estado = 'procesando') AS procesando,
                SUM(estado = 'hecho')      AS hechos,
                SUM(estado = 'error')      AS errores,
                SUM(estado = 'rechazado')  AS rechazados,
                SUM(estado = 'hecho' AND tipo = 'video')  AS videos_ok,
                SUM(estado = 'hecho' AND tipo = 'imagen') AS imagenes_ok,
                SUM(estado = 'hecho' AND terminado_en >= CURDATE()) AS hechos_hoy
             FROM tbl_flow_trabajos" . $w
        );
        $st->execute($params);
        return array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    // Historial del PROCESO de todos los videos/imágenes: para el monitor
    // "Progreso y resultado". Cada fila lista prompt, estado, cuenta, tiempo real
    // (desde que se pidió hasta que se entregó) y "hace cuánto".
    public static function historialProceso(PDO $conn, $limite = 60, $uid = null, $dispositivo = null)
    {
        $where = []; $params = [];
        if ($uid !== null) { $where[] = "creado_por = ?"; $params[] = (int) $uid; }
        if ($dispositivo !== null && $dispositivo !== '') { $where[] = "dispositivo = ?"; $params[] = $dispositivo; }
        $w = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $limite = max(1, min(200, (int) $limite));
        $st = $conn->prepare("SELECT id, tipo, prompt, estado, cuenta, intentos, proyecto, creado_en, tomado_en, terminado_en
                              FROM tbl_flow_trabajos" . $w . " ORDER BY id DESC LIMIT " . $limite);
        $st->execute($params);
        $corta = function ($s, $n = 64) { $s = trim(preg_replace('/\s+/u', ' ', (string) $s)); return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') . '…' : $s; };
        $hace = function ($ref) {
            if (!$ref) return '';
            $d = max(0, time() - strtotime($ref)); $m = intdiv($d, 60);
            if ($d < 60) return 'hace ' . $d . 's';
            if ($m < 60) return 'hace ' . $m . 'm';
            if ($m < 1440) return 'hace ' . intdiv($m, 60) . 'h';
            return 'hace ' . intdiv($m, 1440) . 'd';
        };
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seg = ($r['estado'] === 'hecho' && $r['terminado_en']) ? max(0, strtotime($r['terminado_en']) - strtotime($r['creado_en'])) : 0;
            $ref = $r['terminado_en'] ?: ($r['tomado_en'] ?: $r['creado_en']);
            $proy = trim((string) ($r['proyecto'] ?? ''));
            $enFlow = $proy !== '' && $proy !== 'nuevo' && !self::esProyectoPanel($proy);
            $out[] = [
                'id'          => (int) $r['id'],
                'tipo'        => $r['tipo'],
                'prompt'      => $corta($r['prompt']),
                'estado'      => $r['estado'],
                'cuenta'      => $r['cuenta'] !== '' ? explode('@', $r['cuenta'])[0] : '',
                'intentos'    => (int) $r['intentos'],
                'seg'         => $seg,
                'hace'        => $hace($ref),
                'proyectoUrl' => $enFlow ? ('https://flow.google.com/project/' . rawurlencode($proy)) : '',
            ];
        }
        return $out;
    }

    // Arma los PASOS (línea de tiempo) de un trabajo ya leído de la BD.
    private static function construirPasos(array $r)
    {
        $fmt = function ($dt) { return $dt ? date('d/m/Y H:i:s', strtotime($dt)) : ''; };
        $cuenta = ($r['cuenta'] ?? '') !== '' ? explode('@', $r['cuenta'])[0] : '';
        $estado = $r['estado'];
        $pasos = [];

        $pasos[] = ['ic' => 'edit_note', 'c' => 'ok', 't' => 'Pedido creado', 'd' => 'Entró a la cola de generación', 'cuando' => $fmt($r['creado_en'])];

        if (!empty($r['tomado_en'])) {
            $cola = max(0, strtotime($r['tomado_en']) - strtotime($r['creado_en']));
            $pasos[] = ['ic' => 'rocket_launch', 'c' => 'ok', 't' => 'Enviado a Flow',
                        'd' => ($cuenta ? 'Cuenta ' . $cuenta . ' · ' : '') . 'esperó ' . $cola . ' s en la cola', 'cuando' => $fmt($r['tomado_en'])];
        } elseif ($estado === 'pendiente') {
            $pasos[] = ['ic' => 'schedule', 'c' => 'cola', 't' => 'En cola', 'd' => 'Esperando turno para enviarse a Flow', 'cuando' => ''];
        }

        if (trim((string) ($r['operacion'] ?? '')) !== '') {
            $pasos[] = ['ic' => 'check_circle', 'c' => 'ok', 't' => 'Job creado en Flow', 'd' => (string) $r['operacion'], 'cuando' => ''];
        }

        if ($estado === 'procesando') {
            $est = trim((string) ($r['mensaje'] ?? ''));
            $seg = !empty($r['tomado_en']) ? max(0, time() - strtotime($r['tomado_en'])) : 0;
            $pasos[] = ['ic' => 'bolt', 'c' => 'gen', 't' => 'Generándose…',
                        'd' => (stripos($est, 'Estado:') === 0 ? $est : 'Google está generando') . ' (' . $seg . ' s)', 'cuando' => ''];
        }

        if ($estado === 'hecho' && !empty($r['terminado_en'])) {
            $gen = !empty($r['tomado_en']) ? (strtotime($r['terminado_en']) - strtotime($r['tomado_en'])) : 0;
            $total = strtotime($r['terminado_en']) - strtotime($r['creado_en']);
            $pasos[] = ['ic' => 'movie', 'c' => 'ok', 't' => 'Entregado ✓',
                        'd' => ($gen > 0 ? 'Se generó en ' . $gen . ' s · ' : '') . 'Total ' . $total . ' s desde que se pidió', 'cuando' => $fmt($r['terminado_en'])];
        }

        if ($estado === 'error' || $estado === 'rechazado') {
            $msg = trim((string) ($r['mensaje'] ?? ''));
            $pasos[] = ['ic' => 'error', 'c' => 'err', 't' => ($estado === 'rechazado' ? 'Rechazado' : 'Error'),
                        'd' => ($msg !== '' ? $msg : 'No se pudo generar') . ' · intentos: ' . (int) ($r['intentos'] ?? 0), 'cuando' => $fmt($r['terminado_en'])];
        }

        $segTotal = ($estado === 'hecho' && !empty($r['terminado_en'])) ? (strtotime($r['terminado_en']) - strtotime($r['creado_en'])) : 0;
        // Link al proyecto en Google Flow (solo si es un proyecto REAL de Flow, no
        // uno temporal del panel ni "nuevo").
        $proy = trim((string) ($r['proyecto'] ?? ''));
        $enFlow = $proy !== '' && $proy !== 'nuevo' && !self::esProyectoPanel($proy);
        return [
            'id'          => (int) $r['id'],
            'tipo'        => $r['tipo'],
            'prompt'      => trim(preg_replace('/\s+/u', ' ', (string) $r['prompt'])),
            'estado'      => $estado,
            'cuenta'      => $cuenta,
            'jobid'       => trim((string) ($r['operacion'] ?? '')),
            'proyecto'    => $enFlow ? $proy : '',
            'proyectoUrl' => $enFlow ? ('https://flow.google.com/project/' . rawurlencode($proy)) : '',
            'segTotal'    => $segTotal,
            'pasos'       => $pasos,
        ];
    }

    // DETALLE del proceso de UN video/imagen: toda su historia paso a paso.
    public static function detalleProceso(PDO $conn, $id, $uid = null, $dispositivo = null)
    {
        $where = ["id = ?"]; $params = [(int) $id];
        if ($uid !== null) { $where[] = "creado_por = ?"; $params[] = (int) $uid; }
        if ($dispositivo !== null && $dispositivo !== '') { $where[] = "dispositivo = ?"; $params[] = $dispositivo; }
        $st = $conn->prepare("SELECT * FROM tbl_flow_trabajos WHERE " . implode(' AND ', $where) . " LIMIT 1");
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::construirPasos($r) : null;
    }

    // Proceso COMPLETO de TODOS los videos: un bloque por video con su línea de
    // tiempo. Para el visor "Examinar proceso" (todo de principio a fin).
    public static function logProcesoTodos(PDO $conn, $limite = 100, $uid = null, $dispositivo = null)
    {
        $where = []; $params = [];
        if ($uid !== null) { $where[] = "creado_por = ?"; $params[] = (int) $uid; }
        if ($dispositivo !== null && $dispositivo !== '') { $where[] = "dispositivo = ?"; $params[] = $dispositivo; }
        $w = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $limite = max(1, min(300, (int) $limite));
        $st = $conn->prepare("SELECT * FROM tbl_flow_trabajos" . $w . " ORDER BY id DESC LIMIT " . $limite);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = self::construirPasos($r);
        return $out;
    }

    // Contadores para la extensión de un usuario: lo que tiene en cola y lo de hoy
    public static function resumen(PDO $conn, $uid)
    {
        $st = $conn->prepare(
            "SELECT SUM(estado = 'pendiente')  AS pendientes,
                    SUM(estado = 'procesando') AS procesando,
                    SUM(estado = 'hecho' AND terminado_en >= CURDATE()) AS hechos_hoy,
                    SUM(estado IN ('error', 'rechazado') AND terminado_en >= CURDATE()) AS errores_hoy
             FROM tbl_flow_trabajos WHERE creado_por = ?"
        );
        $st->execute([(int) $uid]);
        return array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    // $uid null = de todos (vista del admin). $dispositivo = aísla por navegador
    // (cuenta compartida: cada equipo solo ve lo que generó él).
    public static function ultimos(PDO $conn, $limite = 100, $uid = null, $dispositivo = null)
    {
        $where = [];
        $params = [];
        if ($uid !== null) { $where[] = "creado_por = ?"; $params[] = (int) $uid; }
        if ($dispositivo !== null && $dispositivo !== '') { $where[] = "dispositivo = ?"; $params[] = $dispositivo; }
        $sql = "SELECT * FROM tbl_flow_trabajos" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT " . (int) $limite;
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Que nada de lo que caiga en la carpeta se pueda ejecutar como PHP
    private static function asegurarCarpeta($dir)
    {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!file_exists("$dir/.htaccess")) {
            file_put_contents("$dir/.htaccess",
                "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n");
        }
    }
}
