# SnaveCloud Flow

Implementación de **Flow** de SnaveCloud: una extensión de Chrome que automatiza
la generación de videos/imágenes en Google Flow a partir de una cola de prompts,
y el panel (PHP) donde se gestionan los pedidos y se reciben los resultados.

> Este repositorio contiene **solo la parte de Flow**. El panel forma parte de un
> sistema mayor (`sistema_infinity`) y por sí solo no se ejecuta completo: faltan
> adrede la configuración y las credenciales (ver abajo).

## Contenido

```
extension/   Extensión de Chrome (MV3)
  manifest.json, background.js, cola.js, fill.js, panel.js,
  popup.{html,css,js}, ia.js, tanda.js, util.js
  hacer-zip.js   -> empaqueta la extensión: `node hacer-zip.js`

panel/       Archivos PHP de Flow (parte de sistema_infinity)
  flow.php                         Página pública /flow
  dashboard/flow-cola.php          Panel de la cola de Flow
  dashboard/flow-cola-api.php      API que usa la extensión
  dashboard/flow-cron.php          Empuje de la cola por cron
  dashboard/class/flowcola.php     Lógica de la cola/pedidos
  dashboard/class/flow_api.php     Generación por servidor (Núcleo SNAVE)
```

## La extensión

1. Carga `extension/` en `chrome://extensions` (modo desarrollador → "Cargar
   descomprimida"), o empaquétala con `node extension/hacer-zip.js`.
2. Copia el token que muestra la extensión y pégalo en el panel ("Crear con Flow").
3. La extensión toma los pedidos del panel, los genera en Google Flow y sube el
   resultado al panel.

## Qué NO está en el repo (a propósito)

Por seguridad, **no se incluyen** credenciales ni datos:

- `config/conexion.php` y `config/credentials.php` (base de datos)
- Claves de pagos (PayPal, Stripe, Binance, etc.) y de R2
- `uploads/` (archivos de clientes) y los `.zip` empaquetados

Todo eso se configura en el servidor; el código los lee de la base de datos o de
sus archivos de configuración locales.
