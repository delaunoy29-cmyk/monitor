// Carga la configuración. En un servidor propio basta con config.json; en la nube
// (Render, Railway, Fly.io) todo se puede dar con variables de entorno, que
// tienen prioridad sobre el archivo. Ver docs/DESPLIEGUE.md.

const fs = require("fs");
const path = require("path");

const raiz = path.join(__dirname, "..");

function leerBase(env) {
  if (env.CONFIG_JSON) return { config: JSON.parse(env.CONFIG_JSON), origen: "CONFIG_JSON" };
  const arch = env.CONFIG || path.join(raiz, "config.json");
  if (fs.existsSync(arch)) return { config: JSON.parse(fs.readFileSync(arch, "utf8")), origen: arch };
  if (env.CONFIG) throw new Error(`No existe ${arch}`);
  // Sin archivo: valores por defecto del ejemplo, pero sin sensores ni contactos de mentira.
  const ejemplo = JSON.parse(fs.readFileSync(path.join(raiz, "config.example.json"), "utf8"));
  return { config: { ...ejemplo, sensores: [], contactos: [] }, origen: "variables de entorno" };
}

const texto = (v) => (v === undefined || v === "" ? undefined : String(v).trim());
function numero(nombre, v) {
  if (texto(v) === undefined) return undefined;
  const n = Number(String(v).replace(",", "."));
  if (Number.isNaN(n)) throw new Error(`${nombre} debe ser un número (vino "${v}")`);
  return n;
}
function booleano(v) {
  if (texto(v) === undefined) return undefined;
  return /^(1|true|si|sí|yes|on)$/i.test(String(v).trim());
}
function poner(obj, clave, valor) {
  if (valor !== undefined) obj[clave] = valor;
}

// SENSORES admite JSON o un formato corto, un sensor por línea o separado por «;»:
//   24E124136B000001:1:Inicio; 24E124136B000002:1:Fondo; 24E124136B000009:0:Tablero general:luz
function leerSensores(v) {
  const t = texto(v);
  if (t === undefined) return undefined;
  if (t.startsWith("[")) return JSON.parse(t);
  return t.split(/[;\n]+/).map((s) => s.trim()).filter(Boolean).map((s) => {
    const [devEUI, galpon, nombre, tipo] = s.split(":").map((x) => x.trim());
    if (!/^[0-9A-Fa-f]{16}$/.test(devEUI || "")) throw new Error(`SENSORES: DevEUI inválido "${devEUI}" (son 16 caracteres hexadecimales)`);
    const g = Number(galpon || 0);
    if (!Number.isInteger(g) || g < 0) throw new Error(`SENSORES: galpón inválido para ${devEUI}`);
    return { devEUI: devEUI.toUpperCase(), galpon: g, nombre: nombre || devEUI, tipo: tipo || "th" };
  });
}

// CONTACTO_1_NOMBRE, CONTACTO_1_WHATSAPP, CONTACTO_1_APIKEY, CONTACTO_1_TELEGRAM, CONTACTO_1_TELEFONO, ...
function leerContactos(env) {
  const contactos = [];
  for (let n = 1; n <= 10; n++) {
    const c = {};
    poner(c, "nombre", texto(env[`CONTACTO_${n}_NOMBRE`]));
    poner(c, "whatsapp", texto(env[`CONTACTO_${n}_WHATSAPP`]));
    poner(c, "apikey", texto(env[`CONTACTO_${n}_APIKEY`]));
    poner(c, "telegram", texto(env[`CONTACTO_${n}_TELEGRAM`]));
    poner(c, "telefono", texto(env[`CONTACTO_${n}_TELEFONO`]));
    if (Object.keys(c).length) contactos.push({ nombre: `Persona ${n}`, ...c });
  }
  return contactos.length ? contactos : undefined;
}

function cargarConfig(env = process.env) {
  const { config, origen } = leerBase(env);
  config.panel = { ...(config.panel || {}) };
  config.limites = { ...(config.limites || {}) };
  config.repetir = { ...(config.repetir || {}) };
  config.luz = { ...(config.luz || {}) };
  config.llamadas = { ...(config.llamadas || {}) };
  config.llamadas.twilio = { ...(config.llamadas.twilio || {}) };
  config.mqtt = { ...(config.mqtt || {}) };

  poner(config, "modoPrueba", booleano(env.MODO_PRUEBA));
  poner(config, "zonaHoraria", texto(env.ZONA_HORARIA));
  poner(config, "tokenGateway", texto(env.TOKEN_GATEWAY));
  poner(config.panel, "usuario", texto(env.PANEL_USUARIO));
  poner(config.panel, "clave", texto(env.PANEL_CLAVE));
  const galpones = texto(env.GALPONES);
  if (galpones) config.galpones = galpones.split(/[,;]/).map((g) => g.trim()).filter(Boolean);
  poner(config, "sensores", leerSensores(env.SENSORES));

  const L = config.limites;
  poner(L, "calorAviso", numero("CALOR_AVISO", env.CALOR_AVISO));
  poner(L, "calorCritico", numero("CALOR_CRITICO", env.CALOR_CRITICO));
  poner(L, "frio", numero("FRIO", env.FRIO));
  poner(L, "humedadAlta", numero("HUMEDAD_ALTA", env.HUMEDAD_ALTA));
  poner(L, "humedadMinutos", numero("HUMEDAD_MINUTOS", env.HUMEDAD_MINUTOS));
  poner(L, "sinDatosMinutos", numero("SIN_DATOS_MINUTOS", env.SIN_DATOS_MINUTOS));
  poner(L, "histeresis", numero("HISTERESIS", env.HISTERESIS));
  poner(config.repetir, "avisoMin", numero("REPETIR_AVISO_MIN", env.REPETIR_AVISO_MIN));
  poner(config.repetir, "criticoMin", numero("REPETIR_CRITICO_MIN", env.REPETIR_CRITICO_MIN));
  poner(config.luz, "valorConLuz", numero("VALOR_CON_LUZ", env.VALOR_CON_LUZ));

  poner(config, "contactos", leerContactos(env));
  const ll = config.llamadas;
  poner(ll, "proveedor", texto(env.LLAMADAS_PROVEEDOR));
  poner(ll, "idioma", texto(env.LLAMADAS_IDIOMA));
  poner(ll, "repetir", numero("LLAMADAS_REPETIR", env.LLAMADAS_REPETIR));
  poner(ll, "minutosEntreLlamadas", numero("MINUTOS_ENTRE_LLAMADAS", env.MINUTOS_ENTRE_LLAMADAS));
  poner(ll.twilio, "accountSid", texto(env.TWILIO_ACCOUNT_SID));
  poner(ll.twilio, "authToken", texto(env.TWILIO_AUTH_TOKEN));
  poner(ll.twilio, "desde", texto(env.TWILIO_DESDE));

  poner(config.mqtt, "url", texto(env.MQTT_URL));
  poner(config.mqtt, "usuario", texto(env.MQTT_USUARIO));
  poner(config.mqtt, "clave", texto(env.MQTT_CLAVE));
  poner(config.mqtt, "tema", texto(env.MQTT_TEMA));

  for (const k of ["calorAviso", "calorCritico", "frio", "humedadAlta", "humedadMinutos", "sinDatosMinutos"]) {
    if (typeof L[k] !== "number") throw new Error(`Falta el límite ${k}`);
  }
  if (L.calorAviso >= L.calorCritico) throw new Error("CALOR_AVISO tiene que ser menor que CALOR_CRITICO");

  return { config, origen, problemas: revisarConfig(config) };
}

// Errores que impiden arrancar en modo real. En modo prueba solo se avisan.
function revisarConfig(c) {
  const p = [];
  if (!c.tokenGateway || /^CAMBIA/i.test(c.tokenGateway)) p.push("TOKEN_GATEWAY no está definido o sigue con el valor de ejemplo");
  else if (c.tokenGateway.length < 16) p.push("TOKEN_GATEWAY es muy corto (mínimo 16 caracteres)");
  if (!c.panel?.clave || /^CAMBIA/i.test(c.panel.clave)) p.push("PANEL_CLAVE no está definida o sigue con el valor de ejemplo");
  if (!c.sensores?.length) p.push("No hay sensores (SENSORES)");
  if (!(c.contactos || []).some((x) => x.whatsapp && x.apikey)) p.push("Ningún contacto tiene WhatsApp y apikey de CallMeBot");
  return p;
}

module.exports = { cargarConfig, leerSensores };
