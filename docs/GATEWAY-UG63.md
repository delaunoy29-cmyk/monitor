# Configurar el gateway Milesight UG63 (4G) paso a paso

Esta guía deja el gateway recibiendo los sensores EM300 por LoRaWAN y enviando cada lectura al monitor en la nube. Necesitas:

- El gateway **UG63 en versión 915 MHz** con módem celular, su antena y su fuente.
- Una **SIM con plan de datos** (tamaño micro, 3FF). Basta un plan pequeño: cada lectura pesa menos de 1 KB.
- El **APN** de tu compañía. Te lo dan al comprar el chip o en su web.
- La dirección del monitor y el `TOKEN_GATEWAY` (ver [DESPLIEGUE.md](DESPLIEGUE.md)).
- Los sensores con su **DevEUI** y su **AppKey** (en la etiqueta, la caja o la app Milesight ToolBox).

Los nombres de los menús son los de la guía oficial del UG63 ([milesight.com/docs](https://www.milesight.com/docs/en/mini-gateway/ug63/about-this-guide.html)). Si tu firmware muestra algo un poco distinto, busca el equivalente.

## 1. Entrar al gateway

1. Inserta la SIM **con el gateway apagado**, conecta la antena y enciéndelo.
2. Con el celular o el notebook, conéctate a la red Wi-Fi **`Gateway_XXXXXX`** (el nombre exacto está en la etiqueta del equipo).
3. Abre **https://192.168.1.1** (acepta la advertencia de certificado del navegador).
4. Usuario **`admin`**, clave **`password`**. Te pedirá cambiar la clave: usa una con letras y números y **anótala**.

> Algunos equipos de 2022 traen otra dirección (`192.168.23.150`, entrando por el puerto de red). Si la de arriba no responde, prueba esa.

## 2. Conectar el 4G

1. **Network → Cellular**: escribe el **APN** de tu compañía (usuario y clave solo si te los dieron). Guarda con **Save & Apply**.
2. **Network → Link Backup**: deja el enlace celular como principal (o como respaldo si en la granja hay internet por cable).
3. **Status → Cellular**: espera a que aparezca conectado y con dirección IP. Si no conecta, revisa el APN y que la SIM tenga saldo o plan activo.

## 3. Frecuencia LoRaWAN (Chile = AU915)

1. **Packet Forward → General → Destination**: en **Type** elige **Embedded NS** (el servidor LoRaWAN integrado del gateway). Save & Apply.
2. **Packet Forward → Radios → Basic Settings**: en **Supported Freq** elige **AU915**.
3. **Network Server → General → Channel**: deja los canales de la **sub-banda 2 (canales 8–15)**, que es la que usan por defecto los sensores Milesight en AU915. Lo importante es que sensores y gateway usen **la misma sub-banda**; se revisa en los sensores con la app ToolBox.

## 4. Dar de alta los sensores

**Network Server → Device → Add**, uno por sensor (o **Batch Import** con la plantilla para cargar los 9 de una vez):

| Campo | Valor |
|---|---|
| Device Name | Por ejemplo `G1-Inicio`, `G1-Fondo`, …, `Luz-Tablero` |
| Device EUI | El DevEUI del sensor (16 caracteres) |
| Class | **Class A** |
| Join Type | **OTAA** |
| App Key | La AppKey del sensor (32 caracteres). Si no viene en la caja, léela con ToolBox |
| FPort | `85` (el de Milesight; viene así) |

Al guardar, enciende el sensor (o acércale el imán/NFC según el modelo). En pocos minutos su estado debe pasar a **activado**. Si no, mira **Network Server → Packets** para ver si llegan sus mensajes.

**Usa exactamente los mismos DevEUI en la variable `SENSORES` del monitor.** Mayúsculas o minúsculas da igual.

## 5. Enviar los datos al monitor (HTTP, recomendado)

1. **Network Server → Application → Add**:
   - Application name: `monitor`
   - **Protocol: HTTPS**
   - Marca los sensores dados de alta en el paso 4 y guarda.
2. Pulsa el lápiz (editar) de la aplicación, pestaña **HTTPS**:
   - **Enable**: activado.
   - **HTTP Headers** → **+**: nombre `X-Token`, valor: tu `TOKEN_GATEWAY`.
   - **Data Topic**, tipo **Uplink Data**: `https://TU-SERVICIO.onrender.com/uplink`
   - Deja sin marcar Join Notification, ACK Notification y Gateway Info (el monitor no los necesita).
3. Save & Apply.

> Si tu firmware no permite cabeceras, pon el token en la dirección: `https://TU-SERVICIO.onrender.com/uplink?token=TU_TOKEN`. Las dos formas funcionan.

El monitor entiende el formato que envía el gateway tal cual (los datos en base64 en el campo `data`), así que no hace falta configurar ningún decodificador en el gateway. Si se configura uno, también lo entiende.

### Comprobarlo

- En el monitor, `https://TU-SERVICIO.onrender.com/salud` muestra `ultimoUplink` con un número (la hora del último dato). Si sigue en `null`, no está llegando nada.
- En Render → **Logs** no debe aparecer `token inválido`. Si aparece «sensor no registrado: 24E1…», ese DevEUI falta o está mal escrito en `SENSORES`.
- En el panel, cada sensor muestra su última lectura con la hora.

## 6. Alternativa: enviar por MQTT

Úsalo solo si prefieres pasar por un broker MQTT (por ejemplo, para que otra aplicación también reciba los datos). Necesitas un broker en internet; [HiveMQ Cloud](https://www.hivemq.com/products/mqtt-cloud-broker/) tiene un plan gratuito suficiente.

1. Crea en el broker un usuario y clave para el gateway y otro para el monitor.
2. En el gateway, crea la aplicación con **Protocol: MQTT** y en su pestaña MQTT:
   - **Broker Address**: el host del broker (por ejemplo `xxxxxxxx.s1.eu.hivemq.cloud`), **Broker Port** `8883`.
   - **Client ID**: `ug63-granja`.
   - **User Credentials**: el usuario y clave del gateway.
   - **TLS**: activado, modo **CA Signed Server Certificate**.
   - Data Topic, **Uplink Data**: `milesight/uplink/$devEUI`, QoS 1.
3. En el monitor, carga `MQTT_URL=mqtts://xxxxxxxx.s1.eu.hivemq.cloud:8883`, `MQTT_USUARIO` y `MQTT_CLAVE` (los del monitor). `MQTT_TEMA` puede quedar vacío (por defecto `milesight/uplink/#`).
4. En los Logs del monitor debe aparecer `[mqtt] conectado a ... suscrito a milesight/uplink/#`.

Puedes dejar HTTP y MQTT activos a la vez, pero entonces cada lectura llega dos veces. Mejor elegir uno.

## 7. Sensores (con la app Milesight ToolBox)

Con un celular con NFC, acercándolo a cada sensor:

- Frecuencia **AU915**, misma sub-banda que el gateway.
- **Intervalo de envío: 5 minutos.** Con menos, la pila dura bastante menos.
- En el EM300-DI del corte de luz, activa el envío **al cambiar la entrada** si su versión lo permite, para que el aviso sea inmediato.

## 8. Antes de dejarlo solo

- [ ] Los 9 sensores aparecen en el panel con lecturas de hace menos de 10 minutos.
- [ ] Calentar un sensor con la mano o un secador por encima de 28 °C genera el aviso.
- [ ] Bajar el automático del relé de luz genera la alerta de corte de luz y la llamada.
- [ ] Desenchufar el gateway 25 minutos genera «No llegan datos de NINGÚN sensor».
- [ ] `MODO_PRUEBA=false` en el monitor y los 3 contactos recibieron WhatsApp y llamada de prueba.
- [ ] UptimeRobot vigila `/salud`.
- [ ] El gateway está enchufado a un **UPS o al circuito del generador**: si se corta la luz y el gateway se apaga, solo llega el aviso de «no llegan datos», no el de corte de luz.
