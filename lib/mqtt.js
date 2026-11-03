// Entrada opcional por MQTT: el gateway publica en un broker (por ejemplo HiveMQ Cloud)
// y este servidor se suscribe. Solo se activa si hay MQTT_URL.

function conectarMqtt(opciones, alRecibir, log = console) {
  if (!opciones?.url) return null;
  const mqtt = require("mqtt");
  const tema = opciones.tema || "milesight/uplink/#";
  const cliente = mqtt.connect(opciones.url, {
    username: opciones.usuario,
    password: opciones.clave,
    clientId: `monitor-avicola-${Math.random().toString(16).slice(2, 10)}`,
    reconnectPeriod: 5000,
    connectTimeout: 20000,
  });
  const sinClave = opciones.url.replace(/\/\/[^@/]*@/, "//***@");
  cliente.on("connect", () => {
    log.log(`[mqtt] conectado a ${sinClave}, suscrito a ${tema}`);
    cliente.subscribe(tema, { qos: 1 }, (e) => e && log.error("[mqtt] no se pudo suscribir:", e.message));
  });
  cliente.on("reconnect", () => log.log("[mqtt] reconectando..."));
  cliente.on("error", (e) => log.error("[mqtt]", e.message));
  cliente.on("message", (topic, carga) => {
    let cuerpo;
    try {
      cuerpo = JSON.parse(carga.toString("utf8"));
    } catch {
      return log.error(`[mqtt] mensaje no es JSON en ${topic}`);
    }
    try {
      alRecibir(cuerpo, topic);
    } catch (e) {
      log.error("[mqtt] error procesando mensaje:", e.message);
    }
  });
  return cliente;
}

module.exports = { conectarMqtt };
