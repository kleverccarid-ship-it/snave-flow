# SnaveCloud Flow — interfaces web

Código de las dos pantallas de Flow, sin la extensión de Chrome. Desde el
05/10/2026 la generación la hace el servidor con la clave de Google del
administrador (`dashboard/class/flow_api.php`), así que estas dos interfaces
funcionan por sí solas.

| Ruta | Archivo | Qué es |
|---|---|---|
| `/flow` | [flow.php](flow.php) | Página pública. Se entra con un correo y una contraseña que crea el admin; no hace falta cuenta en el panel. Por dentro muestra la misma pantalla de abajo en su modo público, sin el menú. |
| `/dashboard/flow-cola` | [dashboard/flow-cola.php](dashboard/flow-cola.php) | Pantalla del panel: cola de pedidos de video e imagen, galería, proyectos, accesos y reglas. |

## Qué hay en el repositorio

```
flow.php                            Página pública /flow (login + render)
dashboard/
  flow-cola.php                     Interfaz principal (HTML, CSS y JS en línea)
  flow-cola-api.php                 API que consume la propia pantalla
  flow-cron.php                     Empuja la cola cada minuto sin navegador abierto
  class/
    flowcola.php                    Lógica: cola, proyectos, accesos, tablas
    flow_api.php                    Generación en el servidor (Google Flow)
    FlowEstado.php                  Estados de un trabajo
    FlowPrivado.php                 Entrega de archivos privados
    CryptoBox.php                   Cifra credenciales en base de datos (AES-256-GCM)
  config/
    conexion.example.php            Plantilla de conexión a MySQL
```

## Puesta en marcha

1. Copia los archivos a tu servidor con PHP 7.4+ y MySQL, y apunta el dominio
   a la carpeta raíz (donde está `flow.php`).
2. Copia `dashboard/config/conexion.example.php` a
   `dashboard/config/conexion.php` y pon los datos de tu base de datos.
3. Abre `/flow` una vez. Las tablas (`tbl_flow_trabajos`, `tbl_flow_accesos`,
   `tbl_flow_clientes`, `tbl_flow_proyectos`, `tbl_flow_tokens`,
   `tbl_flow_dispositivos`, `tbl_flow_ajustes`) se crean solas.
4. En el panel, tarjeta de conexión, pega tu clave de Google Flow y copia la
   URL del cron.
5. Programa el cron cada minuto:
   ```
   curl -s "https://TU-DOMINIO/dashboard/flow-cron.php?k=TOKEN" > /dev/null
   ```

Requisitos de PHP: `pdo_mysql`, `openssl`, `curl`.

## Lo que este repositorio NO incluye

- **La extensión de Chrome.** Era el camino anterior y ya no es necesaria.
- **El resto del panel.** `flow-cola.php` en modo panel incluye
  `dashboard/app/{header,sidebar,topbar,footer}.php` y
  `dashboard/class/global.php`, que son del sistema completo. La página pública
  `/flow` no los usa y funciona sin ellos.
- **Secretos.** `conexion.php` y `crypto_key.php` están en `.gitignore`. La
  clave de cifrado se genera sola la primera vez; no la compartas ni la
  versiones.
- **Archivos de clientes.** `dashboard/uploads/` y `dashboard/privado/`.
