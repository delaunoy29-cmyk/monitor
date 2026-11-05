/*
  SERVIMAXX · Tarjeta de relés del pivote (PIV-xx)
  ESP32 + módem 4G (LilyGO T-SIM7600SA-H o T-A7670SA) + módulo de 5 relés
  Recibe las órdenes del panel "Control del pivote" de SERVIMAXX Monitoreo
  (https://servimaxx.cl/monitoreo/pivote_api.php) y las aplica a los relés.

  Relés (ver docs en LEEME-TARJETA-PIVOTE.txt para el cableado):
    R1 MARCHA      cerrado = pivote en marcha (en serie con la cadena de seguridad del tablero)
    R2 SENTIDO     cerrado = retroceso, abierto = avance
    R3 MOJADO      cerrado = riego con agua (bomba / válvula), abierto = en seco
    R4 VEL_REMOTO  cerrado = la velocidad la manda esta tarjeta (desconecta el temporizador del tablero)
    R5 VEL_PULSO   reemplaza al temporizador porcentual: cerrado X % de cada ciclo de 60 s

  SEGURIDAD (no cambiar sin pensarlo):
  - Al encender, todos los relés quedan ABIERTOS (pivote detenido).
  - Si pasa SIN_SENAL_MS sin hablar con la plataforma, abre MARCHA y avisa.
    Después NO vuelve a arrancar sola: hay que pedir "En marcha" otra vez desde la app.
  - Tras un corte de luz solo vuelve a arrancar si "Auto-reinicio" está activado.
  - Para cambiar de sentido en marcha, primero detiene PAUSA_SENTIDO_MS.
  - Las paradas de emergencia y protecciones del tablero siguen mandando: esta
    tarjeta nunca debe puentearlas.

  Librería (Arduino IDE): "TinyGSM" (Volodymyr Shymanskyy). Placa: "ESP32 Dev Module".
*/
#define TINY_GSM_MODEM_SIM7600
#define TINY_GSM_RX_BUFFER 1024
#include <TinyGsmClient.h>
#include <Preferences.h>
#include <esp_task_wdt.h>

#define VERSION "1.0"

// ---------- Identificación ----------
const char* URL     = "https://servimaxx.cl/monitoreo/pivote_api.php";
const char* API_KEY = "PEGAR_CLAVE_API_DEL_PIVOTE";       // Administrar > equipo tipo pivote
const char* APN     = "imovil.entelpcs.cl";               // Entel. Movistar: "wap.tmovil.cl" · Claro: "bam.clarochile.cl" (verificar)
const char* APN_USR = "entelpcs";
const char* APN_PWD = "entelpcs";

// ---------- Modelo de placa LilyGO: deja SOLO UNA línea sin "//" ----------
#define PLACA_T_SIM7600        // LilyGO T-SIM7600 (SIM7600SA-H / G-H)
// #define PLACA_T_A7670       // LilyGO T-A7670SA

#if defined(PLACA_T_SIM7600)
  #define MODEM_TX       27
  #define MODEM_RX       26
  #define MODEM_PWRKEY    4
  #define MODEM_POWERON  12
  #define MODEM_FLIGHT   25
#elif defined(PLACA_T_A7670)
  #define MODEM_TX       26
  #define MODEM_RX       27
  #define MODEM_PWRKEY    4
  #define MODEM_POWERON  12
  #define MODEM_FLIGHT   -1
#endif

// ---------- Relés ----------
#define R_MARCHA      32
#define R_SENTIDO     33
#define R_MOJADO      18
#define R_VEL_REMOTO  19
#define R_VEL_PULSO   23
// La mayoría de los módulos de relés con optoacoplador se activan con LOW.
// Si tu módulo se activa con HIGH, cambia a HIGH.
#define RELE_ACTIVO   LOW

// ---------- Tiempos ----------
const unsigned long CONSULTA_MS       = 10000UL;          // pregunta por órdenes cada 10 s
const unsigned long SIN_SENAL_MS      = 5UL * 60UL * 1000UL; // 5 min sin plataforma -> detiene
const unsigned long PAUSA_SENTIDO_MS  = 5000UL;           // detenido antes de invertir el sentido
const unsigned long CICLO_VEL_MS      = 60000UL;          // ciclo del temporizador porcentual
const int           WDT_SEG           = 120;              // si el programa se cuelga, se reinicia (y queda detenido)

HardwareSerial SerialAT(1);
TinyGsm modem(SerialAT);
Preferences pref;

// Lo que pide la plataforma
struct Pedido { bool marcha, retroceso, mojado, velRemoto, autoReinicio; int velPct; long orden; };
Pedido pedido = {false, false, false, false, false, 50, 0};

// Lo que está aplicado en los relés
bool marchaAplicada = false, retrocesoAplicado = false;
unsigned long pausaSentidoHasta = 0;

unsigned long ultimoOk = 0;        // última respuesta correcta de la plataforma
long ack = 0;                      // última orden aplicada (se confirma en la siguiente consulta)
String eventoPendiente = "";       // "sin_senal" o "corte_luz", se avisa en la próxima consulta
String aviso = "";

// ---------- Relés ----------
void rele(int pin, bool cerrado) { digitalWrite(pin, cerrado ? RELE_ACTIVO : !RELE_ACTIVO); }
void prepararRele(int pin) { digitalWrite(pin, !RELE_ACTIVO); pinMode(pin, OUTPUT); digitalWrite(pin, !RELE_ACTIVO); }

void guardarMarcha(bool m) {
  if (pref.getBool("marcha", false) != m) pref.putBool("marcha", m);
}

void detenerLocal(const char* evento, const char* motivo) {
  rele(R_MARCHA, false);
  marchaAplicada = false;
  pedido.marcha = false;
  guardarMarcha(false);
  eventoPendiente = evento;
  aviso = motivo;
  Serial.printf("DETENIDO por la tarjeta: %s\n", motivo);
}

// Aplica lo pedido a los relés. Se llama seguido (también maneja la pausa de sentido y el pulso de velocidad).
void aplicar() {
  unsigned long ahora = millis();

  // Cambio de sentido: si está en marcha, primero detiene y espera.
  if (pedido.retroceso != retrocesoAplicado) {
    if (marchaAplicada) {
      rele(R_MARCHA, false); marchaAplicada = false;
      pausaSentidoHasta = ahora + PAUSA_SENTIDO_MS;
      Serial.println("Cambio de sentido: detenido unos segundos");
    }
    if (ahora >= pausaSentidoHasta) {
      rele(R_SENTIDO, pedido.retroceso); retrocesoAplicado = pedido.retroceso;
      pausaSentidoHasta = ahora + 1000;  // 1 s con el sentido nuevo antes de arrancar
    }
  }

  bool marcha = pedido.marcha && ahora >= pausaSentidoHasta && pedido.retroceso == retrocesoAplicado;
  if (marcha != marchaAplicada) {
    rele(R_MARCHA, marcha); marchaAplicada = marcha;
    Serial.println(marcha ? "MARCHA activada" : "MARCHA apagada");
  }
  if (pedido.marcha != pref.getBool("marcha", false)) guardarMarcha(pedido.marcha);

  rele(R_MOJADO, pedido.mojado);
  rele(R_VEL_REMOTO, pedido.velRemoto);
  bool pulso = pedido.velRemoto && (ahora % CICLO_VEL_MS) < (CICLO_VEL_MS * (unsigned long)constrain(pedido.velPct, 1, 100) / 100UL);
  rele(R_VEL_PULSO, pulso);
}

// ---------- Módem ----------
void encenderModem() {
  pinMode(MODEM_POWERON, OUTPUT); digitalWrite(MODEM_POWERON, HIGH);
  if (MODEM_FLIGHT >= 0) { pinMode(MODEM_FLIGHT, OUTPUT); digitalWrite(MODEM_FLIGHT, HIGH); }
  pinMode(MODEM_PWRKEY, OUTPUT);
  digitalWrite(MODEM_PWRKEY, LOW); delay(100);
  digitalWrite(MODEM_PWRKEY, HIGH); delay(500);
  digitalWrite(MODEM_PWRKEY, LOW);
  delay(3000);
}
bool conectarRed() {
  if (modem.isGprsConnected()) return true;
  esp_task_wdt_reset();
  if (!modem.waitForNetwork(60000L)) return false;
  esp_task_wdt_reset();
  return modem.gprsConnect(APN, APN_USR, APN_PWD);
}

// POST HTTPS con el stack interno del módem. Devuelve el cuerpo de la respuesta ("" si falla).
String post(const String& cuerpo) {
  String r;
  modem.sendAT("+HTTPTERM"); modem.waitResponse(2000);
  modem.sendAT("+HTTPINIT"); if (modem.waitResponse(5000) != 1) return "";
  modem.sendAT("+HTTPPARA=\"URL\",\"", URL, "\""); modem.waitResponse(3000);
  modem.sendAT("+HTTPPARA=\"CONTENT\",\"application/json\""); modem.waitResponse(3000);
  modem.sendAT("+HTTPDATA=", cuerpo.length(), ",10000");
  if (modem.waitResponse(5000, "DOWNLOAD") != 1) return "";
  SerialAT.print(cuerpo); modem.waitResponse(10000);
  modem.sendAT("+HTTPACTION=1");
  if (modem.waitResponse(5000) != 1) return "";
  if (modem.waitResponse(30000L, r, "+HTTPACTION:") != 1) return "";
  modem.waitResponse(1000L, r);
  if (r.indexOf(",200,") < 0) { modem.sendAT("+HTTPTERM"); modem.waitResponse(2000); return ""; }
  r = "";
  modem.sendAT("+HTTPREAD=0,512");
  modem.waitResponse(5000L, r, "+HTTPREAD:0", "+HTTPREAD: 0");   // SIM7600 / A7670
  modem.sendAT("+HTTPTERM"); modem.waitResponse(2000);
  int a = r.indexOf('{'), b = r.lastIndexOf('}');
  return (a >= 0 && b > a) ? r.substring(a, b + 1) : "";
}

// Lee un número de la respuesta plana {"clave":valor,...}. Devuelve 'def' si no está.
float numero(const String& j, const char* clave, float def) {
  String k = "\"" + String(clave) + "\":";
  int i = j.indexOf(k);
  if (i < 0) return def;
  return j.substring(i + k.length()).toFloat();
}

bool consultar() {
  int csq = modem.getSignalQuality();
  int dbm = (csq > 0 && csq < 99) ? -113 + 2 * csq : -120;
  String c = "{\"key\":\"" + String(API_KEY) + "\",\"ack\":" + String(ack) +
             ",\"marcha\":" + String(marchaAplicada ? 1 : 0) + ",\"rssi\":" + String(dbm) +
             ",\"version\":\"" VERSION "\"";
  if (eventoPendiente.length()) c += ",\"evento\":\"" + eventoPendiente + "\"";
  if (aviso.length()) c += ",\"aviso\":\"" + aviso + "\"";
  c += "}";

  String r = post(c);
  if (numero(r, "ok", 0) != 1) { Serial.println("Sin respuesta válida de la plataforma"); return false; }

  // La plataforma ya registró el evento y dejó el pivote como "Detenido".
  eventoPendiente = ""; aviso = "";
  pedido.marcha       = numero(r, "marcha", 0) == 1;
  pedido.retroceso    = numero(r, "retroceso", 0) == 1;
  pedido.mojado       = numero(r, "mojado", 0) == 1;
  pedido.velRemoto    = numero(r, "velremoto", 0) == 1;
  pedido.velPct       = (int)numero(r, "velpct", 50);
  pedido.autoReinicio = numero(r, "autoreinicio", 0) == 1;
  pedido.orden        = (long)numero(r, "orden", 0);
  if (pref.getBool("autorein", false) != pedido.autoReinicio) pref.putBool("autorein", pedido.autoReinicio);
  aplicar();
  ack = pedido.orden;     // aplicada: se confirma en la próxima consulta
  Serial.printf("OK orden=%ld marcha=%d retro=%d mojado=%d vel=%s%d%% senal=%d dBm\n", pedido.orden, pedido.marcha,
                pedido.retroceso, pedido.mojado, pedido.velRemoto ? "app " : "tablero ", pedido.velPct, dbm);
  return true;
}

void setup() {
  // Relés abiertos antes que nada.
  prepararRele(R_MARCHA); prepararRele(R_SENTIDO); prepararRele(R_MOJADO);
  prepararRele(R_VEL_REMOTO); prepararRele(R_VEL_PULSO);

  Serial.begin(115200);
  Serial.println("SERVIMAXX tarjeta de pivote " VERSION);
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt = { .timeout_ms = WDT_SEG * 1000, .idle_core_mask = 0, .trigger_panic = true };
  esp_task_wdt_reconfigure(&wdt);
#else
  esp_task_wdt_init(WDT_SEG, true);
#endif
  esp_task_wdt_add(NULL);

  pref.begin("pivote", false);
  // ¿Se cortó la luz (o se reinició) con el pivote en marcha?
  if (pref.getBool("marcha", false) && !pref.getBool("autorein", false))
    detenerLocal("corte_luz", "se reinicio la tarjeta");
  // Con auto-reinicio, vuelve a arrancar cuando la plataforma confirme que sigue "En marcha".

  SerialAT.begin(115200, SERIAL_8N1, MODEM_RX, MODEM_TX);
  encenderModem();
  modem.restart();
  Serial.println(modem.getModemInfo());
  ultimoOk = millis();
}

void loop() {
  static unsigned long ultimaConsulta = 0;
  esp_task_wdt_reset();
  aplicar();

  if (millis() - ultimoOk > SIN_SENAL_MS && (marchaAplicada || pedido.marcha))
    detenerLocal("sin_senal", "sin conexion con la plataforma");

  if (ultimaConsulta == 0 || millis() - ultimaConsulta >= CONSULTA_MS) {
    ultimaConsulta = millis();
    if (conectarRed() && consultar()) ultimoOk = millis();
    else Serial.println("Sin red 4G o sin plataforma, reintenta");
  }
  delay(100);
}
