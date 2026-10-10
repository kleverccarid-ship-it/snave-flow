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
                token_cifrado TEXT NULL,
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
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_trabajos LIKE 'generacion_confirmada'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_trabajos ADD COLUMN generacion_confirmada TINYINT NOT NULL DEFAULT 0");
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
        if (!$conn->query("SHOW COLUMNS FROM tbl_flow_tokens LIKE 'token_cifrado'")->fetch()) {
            $conn->exec("ALTER TABLE tbl_flow_tokens ADD COLUMN token_cifrado TEXT NULL");
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
        'ext_rotar'           => true,  // reparto y reintento entre extensiones conectadas
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
        if (array_key_exists('ext_rotar', $nueva)) $c['ext_rotar'] = (bool) $nueva['ext_rotar'];
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
    public static function configPara(PDO $conn, $uid, $hash = '')
    {
        $c = self::config($conn);
        $c['simultaneos'] = 3; // Tres por perfil Ultra, independiente de reglas antiguas.
        $st = $conn->prepare("SELECT procesar FROM tbl_flow_tokens WHERE usuario_id = ?" . ($hash !== '' ? ' AND token_hash = ?' : ''));
        $st->execute($hash !== '' ? [(int) $uid, $hash] : [(int) $uid]);
        $p = $st->fetchColumn();
        $c['procesar'] = $p === false ? true : (bool) $p;
        $reposo=$hash!==''?self::reposoPerfil($conn,$hash):null;
        $c['reposo_hasta']=$reposo?(int)$reposo['hasta']:0;
        unset($c['clientes_activo'], $c['limite_diario']);
        return $c;
    }

    public static function pausar(PDO $conn, $uid, $procesar)
    {
        $q=$conn->query("SELECT GET_LOCK('snave_flow_reparto',5)");
        if (!(int)$q->fetchColumn()) throw new RuntimeException('La cola está ocupada.');
        try {
            $q=$conn->prepare('SELECT token_hash FROM tbl_flow_tokens WHERE usuario_id = ?');$q->execute([(int)$uid]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $hash) self::guardarAjuste($conn,'ext_reposo_'.substr($hash,0,40),'');
        $conn->prepare("UPDATE tbl_flow_tokens SET procesar = ? WHERE usuario_id = ?")->execute([$procesar ? 1 : 0, (int) $uid]);
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    /* ---------- Token de la extensión: uno por usuario ---------- */

    // Se autentica por hash y se guarda una copia cifrada para el admin. El rol va
    // al lado para que la API sepa si es de un cliente sin mirar la tabla de usuarios.
    private static function cifrarToken($token)
    {
        require_once __DIR__ . '/CryptoBox.php';
        $cifrado = CryptoBox::encrypt($token);
        if (!CryptoBox::isEncrypted($cifrado)) throw new RuntimeException('No se pudo cifrar el token.');
        return $cifrado;
    }

    private static function leerTokenCifrado($cifrado)
    {
        require_once __DIR__ . '/CryptoBox.php';
        if (!CryptoBox::isEncrypted($cifrado)) return '';
        $token = CryptoBox::decrypt($cifrado);
        return preg_match('/^[a-f0-9]{48}$/', (string) $token) ? $token : '';
    }

    public static function nuevoToken(PDO $conn, $uid, $rol)
    {
        $token = bin2hex(random_bytes(24));
        $conn->prepare(
            "INSERT INTO tbl_flow_tokens (usuario_id, rol, token_hash, token_cifrado) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rol = VALUES(rol), token_hash = VALUES(token_hash), token_cifrado = VALUES(token_cifrado), latido = NULL"
        )->execute([(int) $uid, (int) $rol, hash('sha256', $token), self::cifrarToken($token)]);
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
            "INSERT INTO tbl_flow_tokens (usuario_id, rol, token_hash, token_cifrado) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rol = VALUES(rol), token_cifrado = VALUES(token_cifrado)"
        )->execute([(int) $uid, (int) $rol, $hash, self::cifrarToken($token)]);
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

    // Un correo tardio solo corrige pedidos del mismo perfil y proyecto registrado.
    public static function identificarCuentaProyecto(PDO $conn, $hash, $correo, $flow)
    {
        $correo=strtolower(trim((string)$correo));
        if(!preg_match('/^[a-f0-9]{64}$/',(string)$hash) || !filter_var($correo,FILTER_VALIDATE_EMAIL) || strlen($correo)>80 || !preg_match('/^[A-Za-z0-9-]{8,64}$/',(string)$flow))return;
        $q=$conn->prepare("SELECT t.id,t.cuenta FROM tbl_flow_trabajos t JOIN tbl_flow_ajustes p ON p.clave=CONCAT('ext_perfil_',t.id) JOIN tbl_flow_ajustes f ON f.clave=CONCAT('ext_proy_pedido_',t.id) WHERE p.valor=? AND f.valor=? AND (t.cuenta=? OR t.cuenta='' OR t.cuenta IS NULL)");
        $q->execute(['perfil:'.$hash,$flow,'ext:'.$hash]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $t){
            $conn->prepare("UPDATE tbl_flow_trabajos SET cuenta=? WHERE id=? AND (cuenta=? OR cuenta='' OR cuenta IS NULL)")->execute([$correo,(int)$t['id'],'ext:'.$hash]);
        }
    }

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
            $conn->prepare("UPDATE tbl_flow_tokens SET latido = ?, token_cifrado = COALESCE(token_cifrado, ?) WHERE token_hash = ?")->execute([$json, self::cifrarToken($tok), hash('sha256', $tok)]);
            self::identificarCuentaProyecto($conn,hash('sha256',$tok),$latido['cuenta'],$e['flow_id']??'');
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
        $filas = $conn->query("SELECT usuario_id, rol, latido, creado_en, token_cifrado FROM tbl_flow_tokens ORDER BY usuario_id")->fetchAll(PDO::FETCH_ASSOC);
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
                'token'      => self::leerTokenCifrado($f['token_cifrado'] ?? null),
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
            ? array_values(array_filter(preg_split('/\R/u', (string)$texto), function($p){return trim($p)!=='';}))
            : (trim((string)$texto)!=='' ? [(string)$texto] : []);
        if ($maximo !== null) $prompts = array_slice($prompts, 0, max(0, (int) $maximo));
        $st = $conn->prepare(
            "INSERT INTO tbl_flow_trabajos (prompt, tipo, modelo, formato, segundos, calidad, cantidad, proyecto, proyecto_nombre, creado_por, dispositivo, entradas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $calidad = ($a['tipo'] ?? '') === 'video' && isset(self::CALIDADES[$a['calidad'] ?? '']) ? $a['calidad'] : '';
        $disp = mb_substr((string) $dispositivo, 0, 32, 'UTF-8');
        $limitar = $disp !== '';
        $lock = 'flow_disp_' . substr(hash('sha256', (int)$userId . ':' . $disp), 0, 40);
        if ($limitar) {
            $q = $conn->prepare('SELECT GET_LOCK(?, 5)'); $q->execute([$lock]);
            if (!(int)$q->fetchColumn()) return -1;
        }
        try {
            if ($limitar) {
                $q = $conn->prepare("SELECT COALESCE(SUM(cantidad),0) FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND estado IN ('pendiente','procesando')");
                $q->execute([(int)$userId, $disp]);
                if ((int)$q->fetchColumn() + count($prompts) * max(1,(int)$a['cantidad']) > 3) return -1;
            }
            foreach ($prompts as $p) {
                $st->execute([mb_substr($p, 0, 4000, 'UTF-8'), $a['tipo'], $a['modelo'], $a['formato'], $a['segundos'], $calidad, $a['cantidad'],
                              $a['proyecto'] ?? '', mb_substr($a['proyecto_nombre'] ?? '', 0, 120, 'UTF-8'), $userId, $disp, json_encode($a['entradas'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
            return count($prompts);
        } finally {
            if ($limitar) { $q = $conn->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
        }
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
    // El token del administrador opera la cola común; el dueño del pedido no cambia.
    public static function esOperadorAdmin(PDO $conn, $uid, $rol)
    {
        if (!in_array((int) $rol, self::ROLES_ADMIN, true)) return false;
        $st = $conn->prepare("SELECT 1 FROM tbl_usuarios WHERE id = ? AND rol IN (1, 5)");
        $st->execute([(int) $uid]);
        return (bool) $st->fetchColumn();
    }

    public static function estadoExtensionAdmin(PDO $conn)
    {
        $ids = $conn->query("SELECT DISTINCT t.usuario_id FROM tbl_flow_tokens t JOIN tbl_usuarios u ON u.id = t.usuario_id WHERE t.rol IN (1, 5) AND u.rol IN (1, 5)")->fetchAll(PDO::FETCH_COLUMN);
        $mejor = null;
        foreach ($ids as $uid) {
            $e = self::estadoExtension($conn, $uid);
            if ($e && ($mejor === null || (!empty($e['conectada']) && empty($mejor['conectada'])) || ($e['conectada'] === $mejor['conectada'] && (int) ($e['hace'] ?? PHP_INT_MAX) < (int) ($mejor['hace'] ?? PHP_INT_MAX)))) $mejor = $e;
        }
        return $mejor;
    }

    private static function propietarioHabilitado(PDO $conn, $uid)
    {
        if ($uid < 0) return self::accesoActivo($conn, $uid);
        $st = $conn->prepare("SELECT rol FROM tbl_usuarios WHERE id = ?");
        $st->execute([(int) $uid]); $rol = $st->fetchColumn();
        if ($rol === false) return false;
        if (in_array((int) $rol, self::ROLES_ADMIN, true)) return true;
        return in_array((int) $rol, self::ROLES_CLIENTE, true) && (self::config($conn)['clientes_activo'] || self::clienteFlowActivo($conn, $uid));
    }

    public static function admitirEnTanda(PDO $conn, array $t)
    {
        $disp = (string) ($t['dispositivo'] ?? '');
        if ($disp === '') return true;
        $clave = 'tanda_' . substr(hash('sha256', $t['creado_por'] . '|' . $disp), 0, 40);
        $ids = json_decode((string) self::ajuste($conn, $clave), true);
        if (!is_array($ids)) {
            $st = $conn->prepare("SELECT id FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND estado = 'procesando' ORDER BY id");
            $st->execute([(int) $t['creado_por'], $disp]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids) {
            $st = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND id IN (" . implode(',', $ids) . ") AND estado IN ('pendiente','procesando')");
            $st->execute([(int) $t['creado_por'], $disp]);
            if (!(int) $st->fetchColumn()) $ids = [];
        }
        if (in_array((int) $t['id'], $ids, true)) return true;
        $ocupados = 0;
        if ($ids) {
            $st = $conn->prepare("SELECT COALESCE(SUM(cantidad),0) FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND id IN (" . implode(',', $ids) . ")");
            $st->execute([(int)$t['creado_por'], $disp]); $ocupados = (int)$st->fetchColumn();
        }
        if ($ocupados + max(1,(int)($t['cantidad'] ?? 1)) > 3) return false;
        $ids[] = (int) $t['id'];
        self::guardarAjuste($conn, $clave, json_encode($ids));
        return true;
    }

    public static function reservarPedido(PDO $conn, $id, $cuenta = '')
    {
        if (!(int) $conn->query("SELECT GET_LOCK('snave_flow_reparto', 1)")->fetchColumn()) return null;
        try {
            $conn->beginTransaction();
            $st = $conn->prepare("SELECT * FROM tbl_flow_trabajos WHERE id = ? AND estado = 'pendiente' FOR UPDATE");
            $st->execute([(int) $id]); $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t || !self::admitirEnTanda($conn, $t)) { $conn->commit(); return null; }
            $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'procesando', tomado_en = NOW(), operacion = '', cuenta = ?, mensaje = '' WHERE id = ? AND estado = 'pendiente'")
                 ->execute([(string) $cuenta, (int) $id]);
            $conn->commit(); $t['estado'] = 'procesando'; $t['cuenta'] = (string) $cuenta;
            return $t;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    // Tres pedidos por cuenta, mezclando videos e imágenes. Las plazas terminadas
    // se conservan hasta que termine toda la tanda. Se llama bajo el bloqueo de reparto.
    private static function tandaCuenta(PDO $conn, $cuenta, $id = null, $registrar = true)
    {
        $clave = 'ultra_' . substr(hash('sha256', $cuenta), 0, 40);
        $ids = json_decode((string) self::ajuste($conn, $clave), true);
        if (!is_array($ids)) {
            $st = $conn->prepare("SELECT id FROM tbl_flow_trabajos WHERE cuenta = ? AND estado = 'procesando' ORDER BY id");
            $st->execute([$cuenta]); $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids) {
            $st = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE id IN (" . implode(',', $ids) . ") AND estado IN ('pendiente','procesando')");
            $st->execute();
            $activos = (int) $st->fetchColumn();
            if ($activos) {
                $identidad = $cuenta;
                if (strpos($cuenta, 'perfil:') === 0) {
                    $perfil = substr($cuenta, 7);
                    $lat = $conn->prepare("SELECT latido FROM tbl_flow_tokens WHERE token_hash = ?");
                    $lat->execute([$perfil]); $lat = json_decode((string) $lat->fetchColumn(), true);
                    $email = strtolower(trim((string) ($lat['cuenta'] ?? '')));
                    $identidad = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'ext:' . $perfil;
                }
                $activos = 0;
                $st = $conn->query("SELECT id, estado, cuenta FROM tbl_flow_trabajos WHERE id IN (" . implode(',', $ids) . ") AND estado IN ('pendiente','procesando')");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $job) {
                    // Un fallo completó la plaza en esta cuenta, aunque el pedido siga en otra.
                    $enEstaCuenta = $job['cuenta'] === $cuenta || self::ajuste($conn, 'ext_perfil_' . (int) $job['id']) === $cuenta;
                    $fallidas = self::cuentasFallidas($conn, (int) $job['id']);
                    if (($job['estado'] === 'procesando' && $enEstaCuenta) || (!in_array($cuenta, $fallidas, true) && !in_array($identidad, $fallidas, true))) $activos++;
                }
            }
            if (!$activos) $ids = [];
        }
        if ($id !== null && in_array((int) $id, $ids, true)) return true;
        if (count($ids) >= 3) {
            if ($id !== null) return false;
            // Un rechazo transitorio vuelve a pendiente y conserva su plaza.
            $st = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE id IN (" . implode(',', $ids) . ") AND estado = 'pendiente'");
            $st->execute();
            return (int) $st->fetchColumn() > 0;
        }
        if ($id !== null && !$registrar) return true;
        if ($id !== null) $ids[] = (int) $id;
        self::guardarAjuste($conn, $clave, json_encode($ids));
        return true;
    }

    private static function turnoExtensionVideo(PDO $conn, $hash, array $excluir = [], $pedidoId = null)
    {
        $pool = []; $actual = '';
        foreach ($conn->query("SELECT id, token_hash, latido FROM tbl_flow_tokens WHERE rol IN (1,5) AND procesar = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $l = json_decode((string) $fila['latido'], true);
            if (!$l || empty($l['activa']) || time() - (int) ($l['t'] ?? 0) >= 45) continue;
            if (preg_match('/sigo en\s+\d+\s*min|actividad inusual|sin cr[eé]ditos|captcha/iu', (string) ($l['mensaje'] ?? ''))) continue;
            $email = strtolower(trim((string) ($l['cuenta'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'ext:' . $fila['token_hash'];
            if (!isset($pool[$email])) $pool[$email] = $fila['token_hash'];
            if (hash_equals($fila['token_hash'], (string) $hash)) $actual = $email;
        }
        if (!$pool) return ['permitido'=>false, 'cuenta'=>''];
        $ocupacion = [];
        foreach ($conn->query("SELECT cuenta, COUNT(*) n FROM tbl_flow_trabajos WHERE estado = 'procesando' GROUP BY cuenta")->fetchAll(PDO::FETCH_ASSOC) as $f) $ocupacion[$f['cuenta']] = (int) $f['n'];
        $max = 3;
        $rotar = !empty(self::config($conn)['ext_rotar']);
        $emails = $rotar ? array_keys($pool) : ($actual !== '' && isset($pool[$actual]) ? [$actual] : []);
        if (!$emails) return ['permitido'=>false, 'cuenta'=>''];
        $ultimo = (string) self::ajuste($conn, 'ext_rot_ultima');
        $pos = array_search($ultimo, $emails, true); $inicio = $pos === false ? 0 : ($pos + 1) % count($emails);
        $alternativa = '';
        for ($i=0; $i<count($emails); $i++) {
            $email = $emails[($inicio+$i)%count($emails)];
            if ($rotar && in_array($email, $excluir, true)) continue;
            if (($ocupacion[$email] ?? 0) >= $max || !self::tandaCuenta($conn, $email, $pedidoId, false) || !self::tandaCuenta($conn, 'perfil:' . $pool[$email], $pedidoId, false)) continue;
            if ($rotar && $pedidoId && $actual === $email) {
                $preferida = '';
                foreach ($emails as $otra) {
                    if (in_array($otra,$excluir,true) || ($ocupacion[$otra]??0) >= ($ocupacion[$email]??0)) continue;
                    if (self::tandaCuenta($conn,$otra,$pedidoId,false) && self::tandaCuenta($conn,'perfil:'.$pool[$otra],$pedidoId,false)) { $preferida=$otra; break; }
                }
                if ($preferida !== '') {
                    $clave='ext_turno_'.(int)$pedidoId;
                    $turno=json_decode((string)self::ajuste($conn,$clave),true) ?: [];
                    if (!$turno) { $turno=['desde'=>time()]; self::guardarAjuste($conn,$clave,json_encode($turno)); }
                    if (time()-(int)$turno['desde']<5) return ['permitido'=>false,'cuenta'=>$preferida];
                }
            }
            // Si otra cuenta no toma su turno, esta puede continuar.
            if ($actual === $email && hash_equals($pool[$email], (string) $hash)) return ['permitido'=>true, 'cuenta'=>$email];
            if ($alternativa === '') $alternativa = $email;
        }
        return ['permitido'=>false, 'cuenta'=>$alternativa];
    }

    // Diagnóstico de solo lectura: no reserva ni cambia pedidos o tokens.
    public static function diagnosticoAsignacion(PDO $conn, $hash)
    {
        $resumen = self::resumen($conn, null);
        $pendientes = (int) $resumen['pendientes'];
        $st = $conn->prepare("SELECT procesar, latido FROM tbl_flow_tokens WHERE token_hash = ?");
        $st->execute([$hash]); $token = $st->fetch(PDO::FETCH_ASSOC);
        $latido = $token ? json_decode((string) $token['latido'], true) : [];
        $cuenta = strtolower(trim((string) ($latido['cuenta'] ?? '')));
        if (!filter_var($cuenta, FILTER_VALIDATE_EMAIL)) $cuenta = 'ext:' . $hash;
        $estado = ['revision'=>422, 'pendientes'=>$pendientes, 'en_proceso_total'=>(int)$resumen['procesando'], 'capacidad_por_cuenta'=>3];
        $fin = function ($codigo, $mensaje) use ($estado) { return $estado + ['codigo'=>$codigo, 'mensaje'=>$mensaje]; };
        if (!$pendientes) return $fin('sin_pendientes', 'No hay pedidos pendientes en la cola.');
        if (!$token || empty($token['procesar'])) return $fin('perfil_pausado', "$pendientes pendientes. Este perfil está pausado en el panel; reanuda su procesamiento.");
        if (!$latido || empty($latido['activa']) || time() - (int) ($latido['t'] ?? 0) >= 45) return $fin('sin_latido_activo', "$pendientes pendientes. Este perfil no tiene un latido activo reciente; comprueba conexión y Procesar automáticamente.");
        if (preg_match('/sigo en\s+\d+\s*min|actividad inusual|sin cr[eé]ditos|captcha/iu', (string)($latido['mensaje'] ?? ''))) return $fin('cuenta_en_pausa', "$pendientes pendientes. Esta cuenta está en pausa por el aviso anterior de Flow; revisa el aviso en la extensión.");
        foreach ($conn->query("SELECT token_hash, latido FROM tbl_flow_tokens WHERE rol IN (1,5) AND procesar = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $l = json_decode((string)$f['latido'], true);
            if (!$l || empty($l['activa']) || time() - (int)($l['t'] ?? 0) >= 45) continue;
            if (preg_match('/sigo en\s+\d+\s*min|actividad inusual|sin cr[eé]ditos|captcha/iu', (string)($l['mensaje'] ?? ''))) continue;
            $correo = strtolower(trim((string)($l['cuenta'] ?? '')));
            if ($correo !== $cuenta) continue;
            if (!hash_equals((string)$f['token_hash'], (string)$hash)) return $fin('cuenta_duplicada', "$pendientes pendientes. Otro perfil activo está registrado con el mismo correo de Flow; revisa la cuenta de este perfil.");
            break;
        }
        $st = $conn->prepare("SELECT COUNT(*) FROM tbl_flow_trabajos WHERE estado='procesando' AND cuenta=?");
        $st->execute([$cuenta]); $enCurso=(int)$st->fetchColumn();
        if ($enCurso >= 3) return $fin('cuenta_completa', "$pendientes pendientes. Esta cuenta ya tiene $enCurso pedidos asignados; espera a que termine su tanda.");
        $filas = $conn->query("SELECT * FROM tbl_flow_trabajos WHERE estado='pendiente' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $habilitados = 0; $agotados = 0;
        foreach ($filas as $t) {
            if (!self::propietarioHabilitado($conn, (int)$t['creado_por'])) continue;
            $habilitados++;
            $nuevo = json_decode((string)self::ajuste($conn, 'ext_nuevo_' . (int)$t['id']), true);
            if (in_array($cuenta, self::cuentasFallidas($conn, (int)$t['id']), true) && is_array($nuevo) && in_array($cuenta, $nuevo, true)) $agotados++;
        }
        if (!$habilitados) return $fin('solicitantes_desactivados', "$pendientes pendientes. Sus solicitantes tienen el acceso a Flow desactivado o ya no existe. Revisa sus accesos en el admin.");
        if ($agotados === $habilitados) return $fin('reintentos_agotados_en_cuenta', "$pendientes pendientes. Esta cuenta ya falló en todos y agotó el intento en proyecto nuevo; esperan otra cuenta disponible.");
        foreach ([$cuenta, 'perfil:' . $hash] as $identidad) {
            $ids = json_decode((string)self::ajuste($conn, 'ultra_' . substr(hash('sha256', $identidad), 0, 40)), true);
            if (!is_array($ids) || count($ids) < 3) continue;
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $jobs = $conn->query("SELECT id,estado,cuenta FROM tbl_flow_trabajos WHERE id IN (" . implode(',', $ids) . ") AND estado IN ('pendiente','procesando')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($jobs as $job) {
                $fallidas = self::cuentasFallidas($conn, (int)$job['id']);
                $enEsta = $job['cuenta'] === $cuenta || self::ajuste($conn, 'ext_perfil_' . (int)$job['id']) === 'perfil:' . $hash;
                if (($job['estado'] === 'procesando' && $enEsta) || (!in_array($cuenta,$fallidas,true) && !in_array('perfil:' . $hash,$fallidas,true))) {
                    return $fin('tanda_en_curso', "$pendientes pendientes. Esta cuenta conserva una tanda sin terminar; pedido #" . (int)$job['id'] . ' en estado ' . $job['estado'] . '.');
                }
            }
        }
        return $fin('revisando_asignacion', "$pendientes pendientes ($habilitados con acceso habilitado). Este perfil tiene $enCurso en curso. Sigo consultando asignación; copia el diagnóstico si continúa igual.");
    }

    public static function tomarSiguiente(PDO $conn, $uid, $compartida = false, $hash = '')
    {
        if (!(int) $conn->query("SELECT GET_LOCK('snave_flow_reparto', 1)")->fetchColumn()) return null;
        try {
            $conn->beginTransaction();
            $turno = ['permitido'=>true,'cuenta'=>''];
            $sql = $compartida
                ? "SELECT * FROM tbl_flow_trabajos WHERE estado = 'pendiente' ORDER BY id FOR UPDATE"
                : "SELECT * FROM tbl_flow_trabajos WHERE creado_por = ? AND estado = 'pendiente' ORDER BY id FOR UPDATE";
            $st = $conn->prepare($sql); $st->execute($compartida ? [] : [(int) $uid]); $t = null;
            while ($fila = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($compartida && !self::propietarioHabilitado($conn, (int) $fila['creado_por'])) continue;
                $fallidas = !empty(self::config($conn)['ext_rotar']) ? self::cuentasFallidas($conn, (int) $fila['id']) : [];
                $turno = $compartida && $hash !== '' ? self::turnoExtensionVideo($conn, $hash, $fallidas, (int) $fila['id']) : $turno;
                $proyectoNuevo = false;
                if ($fallidas && $turno['cuenta'] === '' && $compartida && $hash !== '') {
                    $usados = json_decode((string) self::ajuste($conn, 'ext_nuevo_' . (int) $fila['id']), true);
                    $usados = is_array($usados) ? $usados : [];
                    $turno = self::turnoExtensionVideo($conn, $hash, $usados, (int) $fila['id']);
                    $proyectoNuevo = $turno['cuenta'] !== '' && in_array($turno['cuenta'], $fallidas, true);
                }
                if (!$turno['permitido']) continue;
                if ($turno['cuenta'] !== '' && (!self::tandaCuenta($conn, $turno['cuenta'], (int) $fila['id'], false) || !self::tandaCuenta($conn, 'perfil:' . $hash, (int) $fila['id'], false))) continue;
                // Cola de extensiones compartida: el limite es por Ultra, no por dispositivo del solicitante.
                if (!self::admitirEnTanda($conn, $fila)) continue;
                if ($turno['cuenta'] !== '') { self::tandaCuenta($conn, $turno['cuenta'], (int) $fila['id']); self::tandaCuenta($conn, 'perfil:' . $hash, (int) $fila['id']); }
                if ($proyectoNuevo) {
                    $usados[] = $turno['cuenta'];
                    self::guardarAjuste($conn, 'ext_nuevo_' . (int) $fila['id'], json_encode(array_values(array_unique($usados))));
                }
                self::guardarAjuste($conn, 'ext_perfil_' . (int) $fila['id'], 'perfil:' . $hash);
                $t = $fila; break;
            }
            if ($t) {
                $cuenta = $turno['cuenta'];
                $conn->prepare("UPDATE tbl_flow_trabajos SET estado = 'procesando', tomado_en = NOW(), cuenta = ?, mensaje = '' WHERE id = ?")
                     ->execute([$cuenta, $t['id']]);
                if ($cuenta !== '') self::guardarAjuste($conn, 'ext_rot_ultima', $cuenta);
                $t['cuenta'] = $cuenta;
                if ($proyectoNuevo) $t['_proyecto_extension'] = 'nuevo';
                if (!empty(self::config($conn)['ext_rotar']) && $cuenta !== '' && trim((string) ($t['proyecto'] ?? '')) !== '') {
                    $mapa = self::ajuste($conn, self::claveProyectoExtension($t));
                    $lote = self::proyectoDeTanda($conn, $cuenta);
                    $t['_proyecto_extension'] = $proyectoNuevo ? 'nuevo' : ($lote ?: ($mapa ?: 'nuevo'));
                }
            }
            $conn->commit(); return $t ?: null;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
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
            'proyecto'        => (string) ($t['_proyecto_extension'] ?? $t['proyecto'] ?? ''),
            'proyecto_nombre' => (string) ($t['proyecto_nombre'] ?? ''),
            // Imágenes de entrada (inicio, fin, referencias) para que la extensión
            // las suba a Flow antes de generar. Cada una: { rol, ruta }.
            'entradas'        => self::entradasParaExtension($t),
            'entradasEsperadas' => count(self::entradasParaExtension($t)),
            'revision_entradas' => 450,
            'inputMode'       => (json_decode((string) ($t['entradas'] ?? ''), true)['modo_entrada'] ?? ''),
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
        self::vencerAtrasados($conn);
        // Leer y escribir dentro de la misma transacción: pueden llegar varios a la vez
        $conn->beginTransaction();
        try {
            $st = $conn->prepare("SELECT archivos FROM tbl_flow_trabajos WHERE id = ? FOR UPDATE");
            $st->execute([(int) $id]);
            $lista = json_decode((string) $st->fetchColumn(), true);
            $lista = is_array($lista) ? $lista : [];
            foreach ($lista as $existente) { if (!empty($archivo['url']) && ($existente['url'] ?? null) === $archivo['url']) { $conn->commit(); return; } }
            $lista[] = $archivo;
            $conn->prepare("UPDATE tbl_flow_trabajos SET archivos = ? WHERE id = ? AND estado IN ('pendiente', 'procesando')")
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
        self::vencerAtrasados($conn);
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
        self::vencerAtrasados($conn);
        // Dominios de Flow/Google donde vive el video: flow.google.com/asb/ (el
        // <video>), flow-content.google, *.googleusercontent.com, *.googlevideo.com
        // (el MP4) y *.googleapis.com.
        if (!preg_match('#^https://([a-z0-9-]+\.)*(google\.com|flow-content\.google|googleusercontent\.com|googlevideo\.com|googleapis\.com)/#i', (string) $url)) return 'Enlace no válido.';
        $t = self::buscar($conn, $id);
        if (!$t || !self::entregable($t)) return 'Ese trabajo no está en proceso.';
        self::agregarArchivo($conn, $t['id'], ['url' => $url, 'tipo' => $tipo === 'imagen' ? 'imagen' : 'video', 'caduca' => true, 'temp' => 1, 'entregado_en' => time(), 'conservar_desde' => time() + ($tipo === 'imagen' ? 180 : 3), 'via_extension' => true]);
        return null;
    }

    // Sustituye únicamente la URL temporal correspondiente a este pedido.
    public static function conservarEntrega(PDO $conn, $id, $url, $subida)
    {
        if (!(int) $conn->query("SELECT GET_LOCK('snave_flow_migra', 0)")->fetchColumn()) return 'Conservación ocupada; se reintentará.';
        try {
            $t = self::buscar($conn, $id);
            if (!$t || !in_array($t['estado'], ['hecho', 'procesando', 'pendiente'], true)) return 'Pedido no disponible.';
            $lista = self::archivosDe($t); $indice = null;
            foreach ($lista as $i => $a) {
                if (($a['fuente_flow'] ?? '') === $url) return null;
                if (($a['url'] ?? '') === $url && !empty($a['temp'])) $indice = $i;
            }
            if ($indice === null) return 'La URL no pertenece a esta entrega.';
            $desde=(int)($lista[$indice]['conservar_desde']??0);
            // Tambien reduce la espera de entregas de video anteriores a esta version.
            if(($lista[$indice]['tipo']??'')==='video' && !empty($lista[$indice]['entregado_en']))$desde=(int)$lista[$indice]['entregado_en']+3;
            if(time()<$desde)return 'La copia todavia no esta programada; se reintentara.';
            if (!is_array($subida) || ($subida['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return 'No llegó el archivo.';
            if ($subida['size'] > self::MAX_BYTES) return 'Archivo demasiado grande.';
            $mime = mime_content_type($subida['tmp_name']);
            if (!isset(self::TIPOS_ARCHIVO[$mime])) return 'Tipo de archivo no permitido.';
            $tipo = strpos($mime, 'video/') === 0 ? 'video' : 'imagen';
            if ($tipo !== $lista[$indice]['tipo']) return 'El archivo no coincide con el tipo del pedido.';
            $nuevo = FlowApi::almacenarEntrega($conn, $id, file_get_contents($subida['tmp_name']), $mime);
            if (!$nuevo) return 'No se pudo conservar el archivo; se reintentará.';
            $nuevo['fuente_flow'] = $url;
            $lista[$indice] = $nuevo;
            $conn->prepare("UPDATE tbl_flow_trabajos SET archivos = ? WHERE id = ? AND estado IN ('hecho', 'procesando', 'pendiente')")
                 ->execute([json_encode($lista, JSON_UNESCAPED_SLASHES), (int) $id]);
            return null;
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_migra')"); }
    }

    private static function cuentasFallidas(PDO $conn, $id)
    {
        $lista = json_decode((string) self::ajuste($conn, 'ext_fallo_' . (int) $id), true);
        return is_array($lista) ? array_values(array_unique(array_map('strval', $lista))) : [];
    }

    private static function claveProyectoExtension(array $t)
    {
        return 'ext_proy_' . substr(hash('sha256', $t['creado_por'] . '|' . $t['proyecto'] . '|' . $t['cuenta']), 0, 40);
    }

    private static function proyectoDeTanda(PDO $conn, $cuenta)
    {
        $ids = json_decode((string) self::ajuste($conn, 'ultra_' . substr(hash('sha256', $cuenta), 0, 40)), true);
        $proy = json_decode((string) self::ajuste($conn, 'ext_lote_' . substr(hash('sha256', $cuenta), 0, 40)), true);
        return is_array($ids) && $ids && is_array($proy) && (int) ($proy['inicio'] ?? 0) === (int) $ids[0] ? (string) ($proy['flow_id'] ?? '') : '';
    }

    public static function registrarProyectoExtension(PDO $conn, array $t, $flowId)
    {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', (string) $flowId) || self::esProyectoPanel($flowId)) return false;
        self::guardarAjuste($conn, self::claveProyectoExtension($t), $flowId);
        $ids = json_decode((string) self::ajuste($conn, 'ultra_' . substr(hash('sha256', $t['cuenta']), 0, 40)), true);
        if (is_array($ids) && $ids) self::guardarAjuste($conn, 'ext_lote_' . substr(hash('sha256', $t['cuenta']), 0, 40), json_encode(['inicio'=>(int) $ids[0], 'flow_id'=>$flowId]));
        return true;
    }

    // $transitorio: fallo de Flow que suele arreglarse solo (alta demanda). Si
    // quedan intentos, el trabajo vuelve a la cola en vez de quedarse en error.
    // $rechazo: Flow no quiso generarlo (política de contenido, o falló hasta
    // agotar los intentos). Queda como "rechazado" con el texto que dio Flow.
    public static function terminar(PDO $conn, $id, $ok, $mensaje, $transitorio = false, $rechazo = false)
    {
        self::vencerAtrasados($conn);
        if (!$ok && preg_match('/actividad inusual|unusual activity/iu',(string)$mensaje)) $transitorio = false;
        if (!$ok && $transitorio) {
            $t = self::buscar($conn, $id);
            $max = (int) self::config($conn)['max_intentos'];
            if ($t && $t['estado'] === 'procesando' && (int) $t['intentos'] + 1 < $max) {
                $n = (int) $t['intentos'] + 1;
                $st = $conn->prepare(
                    "UPDATE tbl_flow_trabajos SET estado = 'pendiente', intentos = ?, mensaje = ?, tomado_en = NULL, generacion_confirmada = 0
                     WHERE id = ? AND estado = 'procesando'"
                );
                $st->execute([$n, mb_substr("Reintento $n de " . ($max - 1) . ": $mensaje", 0, 255, 'UTF-8'), (int) $id]);
                $actualizado = $st->rowCount() > 0;
                if ($actualizado && !empty(self::config($conn)['ext_rotar']) && trim((string) $t['cuenta']) !== '') {
                    $fallidas = self::cuentasFallidas($conn, $id);
                    $fallidas[] = (string) $t['cuenta'];
                    $perfil = (string) self::ajuste($conn, 'ext_perfil_' . (int) $id);
                    if ($perfil !== '') $fallidas[] = $perfil;
                    self::guardarAjuste($conn, 'ext_fallo_' . (int) $id, json_encode(array_values(array_unique($fallidas))));
                }
                return $actualizado;
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

    // La espera configurada y el margen de entrega se cuentan desde la solicitud.
    // Los latidos no reinician el plazo; resultados parciales se conservan.
    public static function vencerAtrasados(PDO $conn)
    {
        $config=self::config($conn);
        $ahora=(string)$conn->query('SELECT NOW()')->fetchColumn();
        $total=0;
        foreach(['imagen','video'] as $tipo){
            foreach($tipo==='imagen'?['']:array_keys(self::CALIDADES) as $calidad){
                $minutos=self::minutosEntrega($config,$tipo,$calidad);
                $corte=date('Y-m-d H:i:s',strtotime($ahora)-$minutos*60);
                $mensaje='Cancelado automáticamente: transcurrieron '.$minutos.' minutos desde la solicitud sin recibir resultado.';
                $filtro=$tipo==='imagen'?'':" AND COALESCE(NULLIF(calidad,''),'720p')=?";
                $q=$conn->prepare("UPDATE tbl_flow_trabajos SET estado='cancelado',mensaje=?,terminado_en=?,operacion='' WHERE estado IN ('pendiente','procesando') AND tipo=?".$filtro." AND creado_en<=? AND (archivos IS NULL OR archivos='' OR archivos='[]' OR archivos='null')");
                $parametros=[$mensaje,$ahora,$tipo];
                if($tipo!=='imagen')$parametros[]=$calidad;
                $parametros[]=$corte;$q->execute($parametros);$total+=$q->rowCount();
            }
        }
        return $total;
    }

    public static function sePuedeCancelar(array $t)
    {
        return in_array($t['estado'], ['pendiente', 'procesando'], true)
            && empty($t['generacion_confirmada']) && trim((string) ($t['operacion'] ?? '')) === '';
    }

    public static function registrarProyectoPedido(PDO $conn, $id, $hash, $flowId)
    {
        if(!self::esPerfilExtension($conn,$id,$hash) || !preg_match('/^[A-Za-z0-9-]{8,64}$/',(string)$flowId) || self::esProyectoPanel($flowId))return false;
        self::guardarAjuste($conn,'ext_proy_pedido_'.(int)$id,$flowId);
        return true;
    }

    public static function esPerfilExtension(PDO $conn, $id, $hash)
    {
        return hash_equals((string)self::ajuste($conn,'ext_perfil_'.(int)$id),'perfil:'.$hash);
    }

    public static function confirmarGeneracion(PDO $conn, $id)
    {
        $st = $conn->prepare("UPDATE tbl_flow_trabajos SET mensaje = CASE WHEN generacion_confirmada = 0 THEN 'Flow confirmó que está generando.' ELSE mensaje END, generacion_confirmada = 1, tomado_en = NOW() WHERE id = ? AND estado = 'procesando'");
        $st->execute([(int) $id]);
        $t = self::buscar($conn, $id);
        return $t && $t['estado'] === 'procesando' && !empty($t['generacion_confirmada']);
    }

    // Exclusivo del dueño y navegador; no borra generaciones tomadas por la extensión.
    // La ruta del administrador valida rol y CSRF antes de invocar esta limpieza global.
    public static function cancelarColaCompleta(PDO $conn)
    {
        $q = $conn->query("SELECT GET_LOCK('snave_flow_reparto', 5)");
        if (!(int)$q->fetchColumn()) return false;
        try {
            return $conn->exec("DELETE FROM tbl_flow_trabajos WHERE estado = 'pendiente' AND generacion_confirmada = 0 AND operacion = ''");
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    public static function cancelarPendientes(PDO $conn, $uid, $dispositivo)
    {
        if (!$dispositivo) return 0;
        $q = $conn->query("SELECT GET_LOCK('snave_flow_reparto', 5)");
        if (!(int)$q->fetchColumn()) return 0;
        try {
            $q = $conn->prepare("DELETE FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND estado = 'pendiente' AND generacion_confirmada = 0 AND operacion = ''");
            $q->execute([(int)$uid, (string)$dispositivo]);
            return $q->rowCount();
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    public static function cancelar(PDO $conn, $id)
    {
        $st = $conn->prepare(
            "UPDATE tbl_flow_trabajos SET estado = 'cancelado', mensaje = 'Cancelado por el usuario.', terminado_en = NOW(), operacion = ''
             WHERE id = ? AND estado IN ('pendiente', 'procesando') AND generacion_confirmada = 0 AND operacion = ''"
        );
        $st->execute([(int) $id]);
        return $st->rowCount() > 0;
    }

    public static function sePuedeReintentar(array $t)
    {
        return in_array($t['estado'], ['error', 'rechazado'], true);
    }

    public static function reintentar(PDO $conn, $id)
    {
        $t = self::buscar($conn, $id);
        if (!$t || !self::sePuedeReintentar($t)) return false;
        $limitar = !empty($t['dispositivo']);
        $lock = 'flow_disp_' . substr(hash('sha256', (int)$t['creado_por'] . ':' . $t['dispositivo']), 0, 40);
        if ($limitar) {
            $q = $conn->prepare('SELECT GET_LOCK(?, 5)'); $q->execute([$lock]);
            if (!(int)$q->fetchColumn()) return false;
        }
        try {
            if ($limitar) {
                $q = $conn->prepare("SELECT COALESCE(SUM(cantidad),0) FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND estado IN ('pendiente','procesando')");
                $q->execute([(int)$t['creado_por'], $t['dispositivo']]);
                if ((int)$q->fetchColumn() + (int)$t['cantidad'] > 3) return false;
            }
        $st = $conn->prepare(
            "UPDATE tbl_flow_trabajos SET estado = 'pendiente', intentos = 0, mensaje = 'Reintento pedido desde el panel.', tomado_en = NULL, terminado_en = NULL, generacion_confirmada = 0, operacion = ''
             WHERE id = ? AND estado IN ('error', 'rechazado')"
        );
        $st->execute([(int) $id]);
        $ok = $st->rowCount() > 0;
        if ($ok) { self::guardarAjuste($conn, 'ext_pct_' . (int)$id, ''); self::guardarAjuste($conn, 'ext_politica_' . (int)$id, ''); self::guardarAjuste($conn, 'ext_fallo_' . (int) $id, '[]'); self::guardarAjuste($conn, 'ext_nuevo_' . (int) $id, '[]'); }
        return $ok;
        } finally { if ($limitar) { $q = $conn->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); } }
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
    public static function reposoPerfil(PDO $conn, $hash)
    {
        return json_decode((string)self::ajuste($conn,'ext_reposo_'.substr($hash,0,40)),true) ?: null;
    }

    public static function iniciarReposo(PDO $conn, $hash)
    {
        $q=$conn->query("SELECT GET_LOCK('snave_flow_reparto',5)");
        if (!(int)$q->fetchColumn()) throw new RuntimeException('La cola está ocupada.');
        try {
            $q=$conn->prepare('UPDATE tbl_flow_tokens SET procesar = 0 WHERE token_hash = ?');$q->execute([$hash]);
            self::guardarAjuste($conn,'ext_reposo_'.substr($hash,0,40),json_encode(['hasta'=>time()+120,'guardia'=>bin2hex(random_bytes(16))]));
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    public static function reanudarReposo(PDO $conn, $hash, $guardia)
    {
        $q=$conn->query("SELECT GET_LOCK('snave_flow_reparto',5)");
        if (!(int)$q->fetchColumn()) return false;
        try {
            $r=self::reposoPerfil($conn,$hash);
            if(!$r || time()<(int)$r['hasta'] || !hash_equals((string)$r['guardia'],$guardia)) return false;
            $q=$conn->prepare('SELECT latido FROM tbl_flow_tokens WHERE token_hash = ?');$q->execute([$hash]);
            $raw=$q->fetchColumn();if($raw===false)return false;
            $l=json_decode((string)$raw,true) ?: [];
            if(empty($l['activa']))return false;
            $l['mensaje']='Reposo terminado; Flow revisado sin aviso.';
            $q=$conn->prepare('UPDATE tbl_flow_tokens SET procesar = 1, latido = ? WHERE token_hash = ?');
            $q->execute([json_encode($l,JSON_UNESCAPED_UNICODE),$hash]);
            self::guardarAjuste($conn,'ext_reposo_'.substr($hash,0,40),'');
            return true;
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    public static function prepararLimpiezaSesion(PDO $conn, $hash)
    {
        $q=$conn->query("SELECT GET_LOCK('snave_flow_reparto',5)");
        if (!(int)$q->fetchColumn()) return 'La cola está ocupada; vuelve a intentarlo.';
        try {
            $q=$conn->prepare('SELECT latido FROM tbl_flow_tokens WHERE token_hash = ?');$q->execute([$hash]);
            $json=$q->fetchColumn();if($json===false)return 'Perfil no registrado.';
            $l=json_decode((string)$json,true) ?: [];
            $cuenta=strtolower(trim((string)($l['cuenta']??''))) ?: 'ext:'.$hash;
            foreach($conn->query("SELECT id,cuenta FROM tbl_flow_trabajos WHERE estado = 'procesando'")->fetchAll(PDO::FETCH_ASSOC) as $t) {
                if($t['cuenta']===$cuenta || self::ajuste($conn,'ext_perfil_'.(int)$t['id'])==='perfil:'.$hash) return 'Este perfil tiene pedidos en proceso. Espera su entrega antes de limpiar.';
            }
            $q=$conn->prepare('UPDATE tbl_flow_tokens SET procesar = 0 WHERE token_hash = ?');$q->execute([$hash]);
            self::guardarAjuste($conn,'ext_reposo_'.substr($hash,0,40),'');
            return '';
        } finally { $conn->query("SELECT RELEASE_LOCK('snave_flow_reparto')"); }
    }

    public static function registrarPolitica(PDO $conn,$id,$hash,$mensaje)
    {
        $t=self::buscar($conn,$id);
        if(!$t || $t['estado']!=='procesando' || !self::esPerfilExtension($conn,$id,$hash) || trim((string)$mensaje)==='')return false;
        self::guardarAjuste($conn,'ext_politica_'.(int)$id,mb_substr(trim((string)$mensaje),0,500,'UTF-8'));
        return true;
    }

    public static function registrarPorcentaje(PDO $conn,$id,$hash,$valores)
    {
        self::vencerAtrasados($conn);
        $t=self::buscar($conn,$id);
        if(!$t || $t['estado']!=='procesando' || empty($t['generacion_confirmada']) || !self::esPerfilExtension($conn,$id,$hash))return false;
        if(!is_array($valores) || !$valores || count($valores)>max(1,(int)$t['cantidad']))return false;
        foreach($valores as $v)if((!is_int($v)&&!is_float($v)) || !is_finite((float)$v) || $v<0 || $v>100)return false;
        self::guardarAjuste($conn,'ext_pct_'.(int)$id,json_encode(['t'=>time(),'valores'=>array_values($valores)]));
        return true;
    }

    public static function registrarPasoExtension(PDO $conn, $id, $hash, $etapa, $detalle = '')
    {
        $titulos = ['proyecto'=>'Proyecto abierto', 'prompt'=>'Prompt colocado', 'imagenes'=>'Imágenes colocadas', 'enviando'=>'Mandando a generar', 'generando'=>'Flow aceptó la generación', 'listo'=>'Listo', 'error'=>'No fue creado'];
        if (!isset($titulos[$etapa])) return false;
        $t = self::buscar($conn, $id);
        if (!$t || !hash_equals((string) self::ajuste($conn, 'ext_perfil_' . (int)$id), 'perfil:' . $hash)) return false;
        if ($etapa === 'listo' && $t['estado'] !== 'hecho') return false;
        $clave = 'ext_pasos_' . (int)$id;
        $pasos = json_decode((string)self::ajuste($conn, $clave), true) ?: [];
        $evento = ['ic'=>$etapa==='error'?'error':'check_circle', 'c'=>$etapa==='error'?'err':'ok', 't'=>$titulos[$etapa], 'd'=>mb_substr((string)$detalle,0,500,'UTF-8'), 'cuando'=>date('d/m/Y H:i:s'), 'cuenta'=>(string)$t['cuenta']];
        $ultimo = end($pasos);
        if (!$ultimo || $ultimo['t'] !== $evento['t'] || $ultimo['d'] !== $evento['d'] || $ultimo['cuenta'] !== $evento['cuenta']) {
            $pasos[] = $evento; self::guardarAjuste($conn, $clave, json_encode(array_slice($pasos,-60), JSON_UNESCAPED_UNICODE));
        }
        if ($t['estado'] === 'procesando') $conn->prepare("UPDATE tbl_flow_trabajos SET mensaje = ? WHERE id = ? AND estado = 'procesando'")->execute([$titulos[$etapa].': '.$evento['d'],(int)$id]);
        return true;
    }

    private static function procesoConEventos(PDO $conn, array $r)
    {
        $proceso = self::construirPasos($r);
        $eventos = json_decode((string)self::ajuste($conn,'ext_pasos_'.(int)$r['id']),true) ?: [];
        if ($eventos) {
            $base = [$proceso['pasos'][0]];
            foreach ($eventos as $evento) { $evento['d'] = ($evento['cuenta'] ?? '') . ' · ' . $evento['d']; $base[] = $evento; }
            if (in_array($r['estado'],['hecho','error','rechazado'],true) && !in_array(end($base)['t'],['Listo','No fue creado'],true)) $base[] = end($proceso['pasos']);
            $proceso['pasos'] = $base;
        }
        return $proceso;
    }

    public static function monitorExtensiones(PDO $conn)
    {
        $out=[];
        foreach ($conn->query("SELECT id, token_hash, procesar, latido FROM tbl_flow_tokens WHERE rol IN (1,5) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $token) {
            $l=json_decode((string)$token['latido'],true) ?: [];
            if (time()-(int)($l['t']??0)>180) continue;
            $cuenta=strtolower(trim((string)($l['cuenta']??''))) ?: 'ext:'.$token['token_hash'];
            $st=$conn->prepare("SELECT id,prompt,tipo,estado,mensaje,entradas FROM tbl_flow_trabajos WHERE cuenta = ? ORDER BY id DESC LIMIT 12");$st->execute([$cuenta]);
            $jobs=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['pasos']=json_decode((string)self::ajuste($conn,'ext_pasos_'.$r['id']),true) ?: [];
                foreach($r['pasos'] as &$p) unset($p['cuenta']);unset($p);
                $r['entradas']=count(self::entradasParaExtension($r));$jobs[]=$r;
            }
            $diag=self::diagnosticoAsignacion($conn,$token['token_hash']);
            $out[]=['perfil'=>(int)$token['id'], 'cuenta'=>str_starts_with($cuenta,'ext:')?'Correo no identificado':$cuenta, 'enLinea'=>time()-(int)($l['t']??0)<45, 'activa'=>!empty($l['activa'])&&!empty($token['procesar']), 'mensaje'=>(string)($l['mensaje']??''), 'espera'=>$diag['mensaje'], 'pedidos'=>$jobs];
        }
        return $out;
    }

    private static function construirPasos(array $r)
    {
        $fmt = function ($dt) { return $dt ? date('d/m/Y H:i:s', strtotime($dt)) : ''; };
        $cuenta = ($r['cuenta'] ?? '') !== '' ? explode('@', $r['cuenta'])[0] : '';
        $estado = $r['estado'];
        $pasos = [];

        $pasos[] = ['ic' => 'edit_note', 'c' => 'ok', 't' => 'Pedido creado', 'd' => 'Entró a la cola de generación', 'cuando' => $fmt($r['creado_en'])];

        if (!empty($r['tomado_en'])) {
            $cola = max(0, strtotime($r['tomado_en']) - strtotime($r['creado_en']));
            $pasos[] = ['ic' => 'rocket_launch', 'c' => 'ok', 't' => 'Asignado a una cuenta',
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
            $pasos[] = ['ic' => 'movie', 'c' => 'ok', 't' => 'Listo ✓',
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
        return $r ? self::procesoConEventos($conn, $r) : null;
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
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = self::procesoConEventos($conn, $r);
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
             FROM tbl_flow_trabajos" . ($uid === null ? '' : ' WHERE creado_por = ?')
        );
        $st->execute($uid === null ? [] : [(int) $uid]);
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
