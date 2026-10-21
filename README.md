# Monitor de galpones

Vigila la temperatura y la humedad de los 4 galpones en tiempo real. Si algo se sale de rango, avisa por **WhatsApp** a 3 personas y, si es crítico, también **llama**.

```
Sensores EM300-TH (2 por galpón)  ─┐
Sensor EM300-DI (corte de luz)    ─┤ LoRaWAN  →  Gateway UG63/UG65 (4G)  →  internet  →  Este servidor
                                   ┘                                                       ├─ Panel web
                                                                                           ├─ WhatsApp (CallMeBot)
                                                                                           └─ Llamada (CallMeBot Telegram)
```

## Qué avisa

| Alerta | Cuándo | Aviso | Repite cada |
|---|---|---|---|
| Calor | ≥ 28 °C | WhatsApp | 60 min |
| Calor peligroso | ≥ 31 °C | WhatsApp + llamada | 15 min |
| Frío | ≤ 14 °C | WhatsApp | 60 min |
| Humedad alta | ≥ 80 % durante 30 min | WhatsApp | 60 min |
| Sensor sin datos | 20 min sin reportar | WhatsApp | 60 min |
| No llega nada de la granja | todos los sensores callados | WhatsApp + llamada | 15 min |
| Corte de luz | inmediato | WhatsApp + llamada | 15 min |

Cuando la situación se normaliza llega un mensaje ✅. Todos los valores se cambian en `config.json`.

---

## Paso 1 – Contratar un servidor

El servidor tiene que estar **en internet, no en la granja**. Así puede avisar si la granja se queda sin luz o sin internet.

- Un servidor virtual (VPS) Linux pequeño basta: 1 CPU y 1 GB de RAM, unos 5 USD al mes (DigitalOcean, Hetzner, Vultr, etc.).
- Necesitas un **dominio o subdominio** apuntando a ese servidor, por ejemplo `granja.tudominio.cl`, para tener HTTPS.

## Paso 2 – Instalar

En el servidor:

```bash
# Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Copiar esta carpeta al servidor (por ejemplo a /opt/monitor-avicola) y luego:
cd /opt/monitor-avicola
cp config.example.json config.json
nano config.json      # completar (ver Paso 3)
```

Dejarlo funcionando siempre, también tras reinicios, con systemd:

```bash
sudo tee /etc/systemd/system/monitor-avicola.service > /dev/null <<'EOF'
[Unit]
Description=Monitor avicola
After=network-online.target

[Service]
WorkingDirectory=/opt/monitor-avicola
ExecStart=/usr/bin/node server.js
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
EOF
sudo chown -R www-data /opt/monitor-avicola
sudo systemctl enable --now monitor-avicola
sudo journalctl -u monitor-avicola -f    # ver lo que hace
```

HTTPS con Caddy, que saca el certificado solo:

```bash
sudo apt install -y caddy
echo 'granja.tudominio.cl {
  reverse_proxy localhost:3000
}' | sudo tee /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

El panel queda en `https://granja.tudominio.cl` con el usuario y la clave de `config.json`.

## Paso 3 – Completar `config.json`

- `tokenGateway`: una clave larga inventada. Es la que usará el gateway para enviar datos.
- `panel.usuario` y `panel.clave`: para entrar al panel web.
- `sensores`: el **DevEUI** de cada sensor (viene en la etiqueta y en la caja), el galpón (1–4) y un nombre (Inicio o Fondo). El sensor de luz va con `"tipo": "luz"`. Si usas uno por galpón, pon su número de galpón; si es uno general, pon `"galpon": 0`.
- `contactos`: las 3 personas, con su WhatsApp, su `apikey` de CallMeBot y su usuario de Telegram (ver Paso 4).
- `modoPrueba`: déjalo en `true` mientras pruebas (solo escribe los avisos en el registro) y cámbialo a **`false`** para que envíe de verdad. Después de cada cambio en `config.json`: `sudo systemctl restart monitor-avicola`.

## Paso 4 – Activar CallMeBot (cada una de las 3 personas)

**WhatsApp**
1. Entra en **callmebot.com → WhatsApp API** y guarda en tus contactos el número que indica la web (a veces cambia).
2. Envía por WhatsApp a ese contacto el mensaje que indica la web (*"I allow callmebot to send me messages"*).
3. Te responde con tu **apikey**. Ponla en `config.json` junto a tu número con formato `+569XXXXXXXX`.

**Llamada automática**
La llamada gratuita de CallMeBot entra **por Telegram**: suena como una llamada de Telegram y una voz lee la alerta.
1. Instala Telegram y créate un **nombre de usuario** (Ajustes → Nombre de usuario).
2. En callmebot.com → **Telegram Phone Call** sigue los pasos para autorizar tu usuario.
3. Pon tu usuario (`@tuusuario`) en el campo `telegram`.
4. Para que la llamada suene de noche, desactiva el modo «No molestar» para Telegram en el celular.

> Si prefieres **llamada telefónica normal**, cambia `"proveedor": "twilio"` y completa los datos de una cuenta Twilio (cuesta unos centavos de dólar por llamada). En ese caso se llama al número `whatsapp` de cada contacto, o a `telefono` si lo indicas.

En el panel, **«Enviar WhatsApp de prueba»** y **«Probar WhatsApp + llamada»** comprueban que les llega a todos.

## Paso 5 – Configurar el gateway Milesight (UG63/UG65)

Entra a la configuración web del gateway (dirección y clave en su etiqueta):

1. **Packet Forwarder → General**: región **AU915**, sub-banda **2** (canales 8–15). Tiene que ser la misma en los sensores.
2. **Network Server → General**: actívalo.
3. **Network Server → Applications → Add**:
   - Payload Codec: el de Milesight para EM300 o «None». El servidor entiende los dos.
   - **Data Transmission → HTTP/HTTPS**, *Uplink data*: `https://granja.tudominio.cl/uplink?token=TU_TOKEN`
4. **Network Server → Profiles → Add**: modo OTAA, clase A.
5. **Network Server → Device → Add**: añade cada sensor con su **DevEUI** y su **AppKey** (vienen con el sensor), usando la aplicación y el perfil anteriores.
6. **Network → Cellular**: mete la SIM, pon el APN de tu operador y comprueba que tiene conexión.

## Paso 6 – Configurar los sensores

Con la app **Milesight ToolBox** en un celular con NFC, acercándolo al sensor:

- Frecuencia **AU915**, la misma sub-banda que el gateway.
- **Intervalo de envío: 5 minutos.** Es un buen equilibrio entre rapidez y pila. Con menos de 5 min la pila dura bastante menos.
- En el EM300-DI de corte de luz, activa que informe **al cambiar la entrada**, si su versión lo permite, para que el aviso sea inmediato.

**Cableado del corte de luz (lo hace un electricista):** la bobina de un relé de 220 V se conecta al tablero y su contacto normalmente abierto va a la entrada digital del EM300-DI. Con luz el relé está cerrado (valor 1); si se corta la luz, se abre (valor 0). Si en tu instalación es al revés, cambia `"valorConLuz": 0`.

## Paso 7 – Comprobar que todo funciona

1. En el panel deben aparecer los 8 sensores con datos de hace pocos minutos.
2. Pulsa los dos botones de prueba y confirma que los 3 reciben el WhatsApp y la llamada.
3. Prueba real: calienta un sensor con la mano o con un secador hasta pasar los 28 °C y espera la siguiente lectura.
4. Prueba el corte de luz bajando el automático del relé.

---

## Probar sin equipos

En cualquier computador con Node.js 18 o superior:

```bash
cp config.example.json config.json
node server.js                                 # en una terminal
node simular.js                                # en otra: envía lecturas normales
node simular.js http://localhost:3000 "" calor # galpón 3 a 31,8 °C
node simular.js http://localhost:3000 "" sinluz
```

Abre `http://localhost:3000` (usuario `granja`, clave `CAMBIA-ESTA-CLAVE`). Con `modoPrueba: true` los avisos solo se escriben en la terminal.

## Archivos

| Archivo | Qué hace |
|---|---|
| `server.js` | Recibe los datos, sirve el panel y la API |
| `lib/decoder.js` | Lee los paquetes de los sensores Milesight |
| `lib/alertas.js` | Decide cuándo avisar, repetir y dar por normalizado |
| `lib/notificar.js` | Envía WhatsApp y llamadas |
| `lib/almacen.js` | Guarda el historial (7 días) en `datos/` |
| `public/index.html` | Panel web |
| `simular.js` | Simulador de sensores para pruebas |

Los rangos de alarma son orientativos para gallinas en postura. Ajústalos con tu veterinario o con el manual de tu línea genética.
