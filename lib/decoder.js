// Decodifica los paquetes de los sensores Milesight EM300-TH y EM300-DI.
// Formato Milesight: secuencia de [canal, tipo, datos...].

function decodificarMilesight(bytes) {
  const out = {};
  let i = 0;
  while (i + 1 < bytes.length) {
    const canal = bytes[i];
    const tipo = bytes[i + 1];
    i += 2;
    if (canal === 0x01 && tipo === 0x75) {          // batería %
      out.battery = bytes[i]; i += 1;
    } else if (canal === 0x03 && tipo === 0x67) {   // temperatura int16 LE /10
      let v = bytes[i] | (bytes[i + 1] << 8);
      if (v > 0x7fff) v -= 0x10000;
      out.temperature = v / 10; i += 2;
    } else if (canal === 0x04 && tipo === 0x68) {   // humedad uint8 /2
      out.humidity = bytes[i] / 2; i += 1;
    } else if (canal === 0x05 && tipo === 0x00) {   // entrada digital (EM300-DI)
      out.gpio = bytes[i]; i += 1;
    } else if (canal === 0x05 && tipo === 0xc8) {   // contador de pulsos (EM300-DI)
      out.pulse = (bytes[i] | (bytes[i + 1] << 8) | (bytes[i + 2] << 16) | (bytes[i + 3] << 24)) >>> 0;
      i += 4;
    } else {
      break; // canal desconocido (información del dispositivo, etc.)
    }
  }
  return out;
}

// Extrae devEUI y valores de un mensaje HTTP del gateway, venga decodificado o no.
function leerUplink(body) {
  if (!body || typeof body !== "object") return null;
  const eui = String(
    body.devEUI || body.devEui || body.deveui || body.deviceInfo?.devEui || ""
  ).toUpperCase();
  if (!eui) return null;

  let d = body.object || body.decoded || body.decodedPayload || null;
  if (!d && (body.temperature !== undefined || body.gpio !== undefined)) d = body;
  if (!d && typeof body.data === "string") {
    try {
      d = decodificarMilesight([...Buffer.from(body.data, "base64")]);
    } catch {
      d = {};
    }
  }
  d = d || {};
  const num = (v) => (v === undefined || v === null || v === "" || isNaN(Number(v)) ? undefined : Number(v));
  const di = d.gpio ?? d.digital_input ?? d.di ?? d.gpio_1;
  return {
    eui,
    temp: num(d.temperature),
    hum: num(d.humidity),
    bat: num(d.battery),
    di: di === undefined ? undefined : (di === "on" || di === "high" ? 1 : di === "off" || di === "low" ? 0 : Number(di)),
  };
}

module.exports = { decodificarMilesight, leerUplink };
