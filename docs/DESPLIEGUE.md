# Poner el monitor en la nube

El servidor tiene que estar **en internet, no en la granja**: así puede avisar si la granja se queda sin luz o sin internet. Esta guía lo deja funcionando en **Render** (recomendado, todo desde el navegador). Al final están las alternativas Fly.io, Railway y un servidor propio.

> **Importante: no uses planes gratuitos.** Los planes gratis se «duermen» cuando no reciben visitas y borran los datos al reiniciar. Un monitor dormido no puede avisar que la granja dejó de enviar datos. Con Render Starter el costo es de unos **7,25 USD al mes** (servicio + 1 GB de disco).

## 1. Crear el servicio en Render

1. Crea una cuenta en [render.com](https://render.com) entrando con tu cuenta de GitHub.
2. **New → Blueprint** y elige el repositorio `monitor`. Render lee el archivo `render.yaml` y prepara todo: el servicio, el disco para el historial y las variables.
3. Render te pide los valores que son secretos (los que no vienen en el archivo). Complétalos con la tabla del punto 2. Si todavía no tienes los DevEUI de los sensores o las apikey de CallMeBot, déjalos vacíos: con `MODO_PRUEBA=true` el servidor arranca igual.
4. Pulsa **Apply**. En unos minutos el servicio queda en una dirección como `https://monitor-avicola-xxxx.onrender.com`.
5. Abre esa dirección: en la página de entrada pon el usuario (`granja`) y la clave que pusiste en `PANEL_CLAVE`.

Para ver el token que Render generó para el gateway: servicio → **Environment** → `TOKEN_GATEWAY` → ojo para mostrarlo. Lo necesitas en la guía del gateway.

Cada vez que se fusiona un cambio en GitHub, Render vuelve a desplegar solo.

## 2. Variables de entorno

Se cambian en Render → servicio → **Environment**. Al guardar, el servicio se reinicia con los valores nuevos.

### Básicas

| Variable | Ejemplo | Para qué |
|---|---|---|
| `MODO_PRUEBA` | `true` | `true`: los avisos solo se escriben en el registro (Logs). **Pásalo a `false` cuando todo esté probado.** |
| `TOKEN_GATEWAY` | (lo genera Render) | Clave que usa el gateway para enviar datos. Mínimo 16 caracteres. |
| `PANEL_USUARIO` | `granja` | Usuario del panel web |
| `PANEL_CLAVE` | | Clave del panel web |
| `GALPONES` | `Galpón 1,Galpón 2,Galpón 3,Galpón 4` | Nombres que salen en el panel y en los mensajes |
| `SENSORES` | ver abajo | Qué sensor está en qué galpón |
| `ZONA_HORARIA` | `America/Santiago` | Para la hora de los mensajes |

**`SENSORES`**: un sensor por línea o separados por `;`, con el formato `DevEUI:galpón:nombre`. El sensor de corte de luz lleva `:luz` al final y galpón `0` si es general. El DevEUI viene en la etiqueta y en la caja del sensor (16 caracteres).

```
24E124136B000001:1:Inicio; 24E124136B000002:1:Fondo;
24E124136B000003:2:Inicio; 24E124136B000004:2:Fondo;
24E124136B000005:3:Inicio; 24E124136B000006:3:Fondo;
24E124136B000007:4:Inicio; 24E124136B000008:4:Fondo;
24E124136B000009:0:Tablero general:luz
```

### Umbrales de alarma

| Variable | Por defecto | Significado |
|---|---|---|
| `CALOR_AVISO` | `28` | °C desde los que se envía WhatsApp |
| `CALOR_CRITICO` | `31` | °C desde los que además se llama |
| `FRIO` | `14` | °C hacia abajo, aviso de frío |
| `HUMEDAD_ALTA` | `80` | % de humedad alta… |
| `HUMEDAD_MINUTOS` | `30` | …mantenida estos minutos |
| `SIN_DATOS_MINUTOS` | `20` | Minutos sin datos de un sensor antes de avisar |
| `HISTERESIS` | `0.5` | °C que tiene que bajar para darla por normalizada (evita avisos repetidos) |
| `REPETIR_AVISO_MIN` | `60` | Cada cuántos minutos se recuerda un aviso activo |
| `REPETIR_CRITICO_MIN` | `15` | Cada cuántos minutos se recuerda una alerta crítica |
| `VALOR_CON_LUZ` | `1` | Lo que marca el sensor de luz cuando hay luz (`0` si el relé está al revés) |

Los decimales se pueden escribir con coma o con punto (`27,5` o `27.5`). Si `CALOR_AVISO` no es menor que `CALOR_CRITICO`, el servidor no arranca y lo dice en los Logs.

### Contactos (CallMeBot)

Hasta 10 personas, numeradas `CONTACTO_1_…`, `CONTACTO_2_…`, etc.

| Variable | Ejemplo |
|---|---|
| `CONTACTO_1_NOMBRE` | `Santiago` |
| `CONTACTO_1_WHATSAPP` | `+56912345678` |
| `CONTACTO_1_APIKEY` | la apikey que te da CallMeBot por WhatsApp |
| `CONTACTO_1_TELEGRAM` | `@tuusuario` (para la llamada automática) |
| `CONTACTO_1_TELEFONO` | solo si usas Twilio y es distinto del WhatsApp |

Cómo conseguir la apikey y activar la llamada: ver el **Paso 3** del [README](../README.md).

| Variable | Por defecto | |
|---|---|---|
| `LLAMADAS_PROVEEDOR` | `callmebot` | `callmebot` (llamada por Telegram), `twilio` (llamada telefónica) o `ninguno` |
| `MINUTOS_ENTRE_LLAMADAS` | `5` | Varias alertas críticas seguidas generan una sola llamada |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_DESDE` | | Solo con Twilio |

### Entrada por MQTT (opcional)

Solo si el gateway envía por MQTT en vez de HTTP (ver la guía del gateway). Si `MQTT_URL` está vacío, no se usa.

| Variable | Ejemplo |
|---|---|
| `MQTT_URL` | `mqtts://xxxxxxxx.s1.eu.hivemq.cloud:8883` |
| `MQTT_USUARIO`, `MQTT_CLAVE` | las credenciales del broker |
| `MQTT_TEMA` | `milesight/uplink/#` (por defecto) |

### Seguridad al pasar a modo real

Con `MODO_PRUEBA=false` el servidor **se niega a arrancar** si el token o la clave del panel siguen con el valor de ejemplo, si no hay sensores o si ningún contacto tiene WhatsApp con apikey. El motivo aparece en **Logs**.

## 3. Comprobar que funciona

1. En el navegador: `https://TU-SERVICIO.onrender.com/salud` responde `{"ok":true,...}`. `ultimoUplink` indica cuándo llegó el último dato del gateway.
2. Simular un sensor sin tener los equipos (desde cualquier computador con internet; en Windows usa PowerShell y cambia `curl` por `curl.exe`):

   ```bash
   curl -X POST "https://TU-SERVICIO.onrender.com/uplink?token=TU_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"devEUI":"24E124136B000001","fPort":85,"data":"AXVcA2c+AQRohg=="}'
   ```

   Debe responder `{"ok":true}` y en el panel ese sensor aparece con 31,8 °C (alerta crítica). En **Logs** se ve `[PRUEBA] WhatsApp a ...`. Si el DevEUI no está en `SENSORES` responde `{"ok":true,"ignorado":true}` y el Log lo muestra como «sensor no registrado».
3. Con las apikey cargadas y `MODO_PRUEBA=false`, usa los botones **«Enviar WhatsApp de prueba»** y **«Probar WhatsApp + llamada»** del panel.

## 4. Vigilar al vigilante (gratis, 5 minutos)

Si el propio servidor se cae, nadie avisaría. Crea una cuenta gratuita en [UptimeRobot](https://uptimerobot.com):

- **New monitor → HTTP(s)**, URL `https://TU-SERVICIO.onrender.com/salud`, cada 5 minutos.
- Alertas por correo y por la app de UptimeRobot en el celular de al menos una persona.

## 5. El historial

Las lecturas y alertas de los últimos 7 días se guardan en el disco `/data` del servicio (1 GB sobra). Sobrevive a reinicios y despliegues nuevos. Render hace una copia diaria del disco.

---

## Alternativas

### Fly.io (servidor en Santiago)

Fly.io tiene región en Santiago (`scl`), cuesta unos 2–3 USD al mes, pero se maneja desde la terminal. Con la [CLI de Fly](https://fly.io/docs/flyctl/install/) instalada, en la carpeta del repositorio:

```bash
fly launch --no-deploy --copy-config --name monitor-avicola-TUNOMBRE
fly volumes create datos --region scl --size 1
fly secrets set TOKEN_GATEWAY="$(openssl rand -hex 20)" PANEL_CLAVE="..." \
  SENSORES="24E124136B000001:1:Inicio;..." \
  CONTACTO_1_NOMBRE="..." CONTACTO_1_WHATSAPP="+569..." CONTACTO_1_APIKEY="..." CONTACTO_1_TELEGRAM="@..."
fly deploy
```

Los umbrales están en `fly.toml` (sección `[env]`). La máquina queda siempre encendida (`auto_stop_machines = "off"`); no lo cambies.

### Railway

New Project → Deploy from GitHub repo. Railway detecta el `Dockerfile`. Después: **Settings → Volumes** → montar un volumen en `/data`; **Variables** → cargar las mismas variables de la tabla; **Settings → Networking → Generate Domain**. Usa el plan Hobby (5 USD/mes), no el de prueba.

### Servidor propio (VPS) con Docker

```bash
docker build -t monitor-avicola .
docker run -d --name monitor-avicola --restart always -p 3000:3000 \
  --env-file .env -v monitor-datos:/data monitor-avicola
```

`.env` se arma copiando `.env.example`. Delante hace falta HTTPS (por ejemplo con Caddy, ver el README). Sin Docker, `config.json` sigue funcionando igual que antes.
