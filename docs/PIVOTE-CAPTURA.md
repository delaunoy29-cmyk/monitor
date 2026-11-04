# Capturar lo que manda la placa del pivote

La placa **iControl Remote** tiene dos partes: un chip principal que maneja los relés y el GPS, y el **XBee Cellular 3G**, que es solo el módem. El chip principal le escribe al XBee por serial, y el XBee lo manda por internet.

La idea es redirigir el XBee a **nuestro servidor de captura** (`pivote/captura.js`). Así vemos byte a byte qué manda la placa y podemos descifrar el protocolo antes de programar nada.

> ⚠ **Seguridad.** Mientras pruebas, deja los relés **desconectados del tablero del pivote** (220/380 V, equipo pesado que se mueve). El capturador solo escucha y no manda nada a la placa, pero la placa podría reaccionar sola al perder su servidor original.

## Por qué no sirve Render para esto

El XBee en modo transparente no habla HTTP: abre una **conexión TCP cruda** y manda los bytes tal cual. Render (incluido el plan pagado) solo deja entrar tráfico web HTTP, así que la conexión del XBee no llegaría. Por eso el capturador va aparte, en **Fly.io**, que sí acepta TCP y tiene región en Santiago.

**Costo aproximado en Fly.io:** unos **US$4 al mes** (IPv4 dedicada US$2, máquina chica ~US$2, disco de 1 GB ~US$0,15). Pide tarjeta. Cuando termines de capturar, lo borras con `fly apps destroy pivote-captura` y deja de cobrar.

## 1. Poner el capturador en Fly.io

Necesitas el programa `fly` en tu computador ([instrucciones](https://fly.io/docs/flyctl/install/)) y una cuenta (`fly auth signup`).

Desde la carpeta `pivote` del repositorio:

```bash
cd pivote
fly launch --no-deploy --copy-config --name pivote-captura   # si el nombre está tomado, usa otro
fly volumes create capturas --region scl --size 1
fly ips allocate-v4                                          # la IPv4 dedicada (US$2/mes)
fly secrets set PANEL_CLAVE="una-clave-tuya"
fly deploy
fly ips list                                                 # anota la IPv4 (tipo "v4", "public")
```

- **Página para ver la captura:** `https://pivote-captura.fly.dev` (usuario `pivote`, la clave que pusiste). Se actualiza sola cada 10 segundos.
- **Descargar todo lo del día:** botón en esa misma página (archivo `.jsonl`, una línea por mensaje).
- **Ver en vivo desde el computador:** `fly logs`

### Alternativa gratis

Cualquier servidor Linux con Node 18+ y el puerto TCP 9000 abierto sirve (por ejemplo una máquina gratuita de Oracle Cloud). Copias `pivote/captura.js` y lo corres con:

```bash
PANEL_CLAVE="una-clave-tuya" node captura.js
```

## 2. Configurar el XBee en XCTU

Necesitas un adaptador USB para XBee (XBee Explorer USB o el kit de Digi) y el programa **XCTU** (gratis, de Digi).

1. Saca el XBee de la placa **con la placa apagada** y ponlo en el adaptador.
2. En XCTU: **Add device** (ícono con el +), elige el puerto USB y pulsa **Finish**. Si no lo encuentra, usa **Discover devices** y marca todas las velocidades.
3. **Antes de cambiar nada, guarda la configuración original:** botón **Profile → Save configuration profile**. Guárdalo en un lugar seguro: con eso lo dejas como venía.
4. **Anota estos valores y mándamelos** (o una foto de la pantalla):
   - `DL` y `DE`: a dónde mandaba los datos el fabricante. Nos dice qué protocolo esperaba el servidor original.
   - `IP`: si usaba UDP (0), TCP (1) o SMS (2).
   - `AP`: **si dice 0 (transparente), sigue. Si dice 1 o 2 (API), no cambies nada y avísame**: en ese modo la placa elige el destino ella misma y hay que hacerlo distinto.
   - `BD`, `NB`, `SB`: velocidad serial. **No los cambies**: tienen que coincidir con el chip principal.
5. Cambia solo esto:

| Parámetro | Valor | Qué es |
|---|---|---|
| `AN` | El APN de tu compañía (pregúntales; suelen ser `bam.entelpcs.cl` en Entel, `wap.tmovil.cl` en Movistar, `bam.clarochile.cl` en Claro) | Para conectarse a internet |
| `IP` | `1` (TCP) | Tipo de conexión |
| `DL` | La IPv4 que te dio `fly ips list` | Dirección del capturador |
| `DE` | `2328` | Puerto **9000 escrito en hexadecimal** (XCTU pide el puerto en hex) |

6. Pulsa **Write** (el lápiz) para grabar.
7. Revisa que se conecte (con el chip puesto y antena conectada):
   - `AI` tiene que llegar a `0` (conectado a la red). Puede tardar un par de minutos.
   - `DB` es la señal, en hexadecimal: `50` es −80 dBm (buena), `5A` es −90 dBm (aceptable), `64` o más es −100 dBm (débil).
   - `MY` muestra la IP que le dio la compañía.
   - Si `AI` se queda en `22` (buscando red), no hay 3G en esa zona o la antena está mal. Si se queda en `23` (conectando a internet), revisa el APN. `25` es que la compañía rechazó el chip.
8. Vuelve a poner el XBee en la placa, con la placa apagada, y enciéndela (con los relés desconectados del tablero).

## 3. Qué hacer con lo capturado

En la página de captura vas a ver líneas como esta:

```
TCP 200.x.x.x:1234   14 bytes
02 69 43 52 2C 53 54 41 54 2C 30 31 0D 0A
·iCR,STAT,01\r\n
```

La primera fila es en hexadecimal y la segunda como texto (los bytes que no son letras aparecen como `·`). Deja la placa encendida un rato y, si puedes, mueve cosas a mano: cambia el sentido, prende el cañón, cambia la velocidad. Anota la hora de cada cosa que hiciste. Con eso se puede saber qué parte del mensaje cambia con cada acción.

Cuando tengas un rato de captura, descarga el archivo del día y mándamelo junto con tus anotaciones.

## Para volver a como estaba

En XCTU: **Profile → Load configuration profile**, eliges el archivo que guardaste en el paso 3 y pulsas **Write**.
