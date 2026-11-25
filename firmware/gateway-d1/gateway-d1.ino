// gateway (wemos d1 mini, esp8266) entre os sensores e o cluster
// cria a rede AP_SSID (192.168.4.1), onde os sensores postam as leituras em /reading, guarda tudo num buffer na ram
// e envia em lotes ao lider do cluster, que ele descobre perguntando /leader.php aos nos-semente
// o lider tambem pergunta /ping de tempos em tempos, e e assim que o servidor sabe que o gateway esta vivo
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <ESP8266WebServer.h>
#include "secrets.h"

const char* GW_ID = "d1-gw";
const int N_SEEDS = sizeof(SEEDS) / sizeof(SEEDS[0]);

const unsigned long INTERVALO_ENVIO = 5000; // periodo do envio ao servidor, e o lote vazio de cada ciclo tambem serve de sinal de vida
const unsigned long TIMEOUT_HTTP = 3000;
const int MAX_BUFFER = 200; // leituras em cache (cerca de 8 KB), quando enche a mais antiga e descartada
const int MAX_LOTE = 20; // leituras por requisicao
const int MAX_ESPS = 8; // sensores que o gateway consegue acompanhar ao mesmo tempo

struct Leitura {
	char esp[24];
	uint32_t seq;
	float temp, umid;
	uint32_t ms; // millis() de quando a leitura chegou, o servidor recebe a idade e calcula o horario
};

// buffer circular, ini e a leitura mais antiga e qtd quantas estao guardadas
Leitura buf[MAX_BUFFER];
int ini = 0, qtd = 0;
uint32_t seqGlobal = 0;
String bootId;

// quando cada sensor foi ouvido pela ultima vez, e com isso que o servidor decide quais estao ativos
struct Visto {
	char esp[24];
	uint32_t ms;
};
Visto vistos[MAX_ESPS];
int nVistos = 0;

String lider = ""; // "ip:porta" do lider atual, vazio quando nao se sabe quem e
unsigned long ultimoEnvio = 0;
ESP8266WebServer web(80);

void marcarVisto(const char* esp) {
	for (int i = 0; i < nVistos; i++)
		if (!strcmp(vistos[i].esp, esp)) {
			vistos[i].ms = millis();
			return;
		}
	if (nVistos < MAX_ESPS) {
		strncpy(vistos[nVistos].esp, esp, 23);
		vistos[nVistos].esp[23] = 0;
		vistos[nVistos++].ms = millis();
	}
}

void guardar(const char* esp, float t, float u) {
	if (qtd == MAX_BUFFER) { // cheio, perde a mais antiga
		ini = (ini + 1) % MAX_BUFFER;
		qtd--;
	}
	Leitura& l = buf[(ini + qtd) % MAX_BUFFER];
	strncpy(l.esp, esp, 23);
	l.esp[23] = 0;
	l.seq = ++seqGlobal;
	l.temp = t;
	l.umid = u;
	l.ms = millis();
	qtd++;
}

// um sensor posta a leitura em POST /reading com id, temp e umid
void aoReceberLeitura() {
	String id = web.arg("id");
	if (id.length() == 0 || id.length() > 23 || !web.hasArg("temp") || !web.hasArg("umid")) {
		web.send(400, "text/plain", "campos");
		return;
	}
	guardar(id.c_str(), web.arg("temp").toFloat(), web.arg("umid").toFloat());
	marcarVisto(id.c_str());
	Serial.printf("sensor %s: %s C %s %% (buffer=%d)\n", id.c_str(), web.arg("temp").c_str(), web.arg("umid").c_str(), qtd);
	web.send(200, "text/plain", "ok");
}

// o valor de um campo de texto "chave":"valor" de um json simples, vazio se nao existe
String campoStr(const String& json, const char* chave) {
	String k = String("\"") + chave + "\":\"";
	int i = json.indexOf(k);
	if (i < 0) return "";
	i += k.length();
	int f = json.indexOf('"', i);
	return f < 0 ? "" : json.substring(i, f);
}

// descoberta de servico, pergunta a cada no-semente quem e o lider e fica com a primeira resposta
bool descobrirLider() {
	for (int i = 0; i < N_SEEDS; i++) {
		WiFiClient c;
		HTTPClient http;
		http.begin(c, String("http://") + SEEDS[i] + "/leader.php");
		http.setTimeout(2000);
		int code = http.GET();
		String corpo = code == 200 ? http.getString() : "";
		http.end();
		String url = campoStr(corpo, "url");
		if (url.length() > 0) {
			lider = url;
			Serial.println("lider descoberto: " + lider + " (via " + SEEDS[i] + ")");
			return true;
		}
	}
	Serial.println("nenhum no respondeu / sem lider");
	return false;
}

// envia as n leituras mais antigas do buffer, e devolve true so se o servidor confirmou com 200
bool enviarLote(int n) {
	unsigned long agora = millis();

	// o gateway nao tem relogio, entao cada leitura e cada sensor vai com a idade em ms e o servidor calcula o horario
	String j = String("{\"gw\":\"") + GW_ID + "\",\"boot\":\"" + bootId + "\",\"ip\":\"" + WiFi.localIP().toString() + "\",\"esps\":{";
	for (int i = 0; i < nVistos; i++) {
		if (i) j += ",";
		j += String("\"") + vistos[i].esp + "\":" + (uint32_t)(agora - vistos[i].ms);
	}
	j += "},\"readings\":[";
	for (int i = 0; i < n; i++) {
		Leitura& l = buf[(ini + i) % MAX_BUFFER];
		if (i) j += ",";
		j += String("{\"esp\":\"") + l.esp + "\",\"seq\":" + l.seq + ",\"temp\":" + String(l.temp, 1) +
			",\"umid\":" + String(l.umid, 1) + ",\"age\":" + (uint32_t)(agora - l.ms) + "}";
	}
	j += "]}";

	WiFiClient c;
	HTTPClient http;
	http.begin(c, String("http://") + lider + "/ingest.php");
	http.setTimeout(TIMEOUT_HTTP);
	http.addHeader("Content-Type", "application/json");
	int code = http.POST(j);
	String corpo = (code > 0) ? http.getString() : "";
	http.end();

	if (code == 200) return true;
	if (code == 421) { // esse no nao e mais o lider
		String url = campoStr(corpo, "url");
		lider = url; // vazio forca a redescoberta
		Serial.println("421: novo lider = " + (url.length() ? url : String("(eleicao em andamento)")));
	} else {
		Serial.printf("falha ao enviar [%d] -> redescobrir lider\n", code);
		lider = "";
	}
	return false;
}

void ciclo() {
	if (WiFi.status() != WL_CONNECTED) {
		Serial.println("sem rede: mantendo cache (" + String(qtd) + ")");
		return;
	}
	if (lider.length() == 0 && !descobrirLider()) return;

	// esvazia o buffer em lotes, e sem leituras ainda envia um lote vazio como sinal de vida
	do {
		int n = qtd < MAX_LOTE ? qtd : MAX_LOTE;
		if (!enviarLote(n)) return; // o buffer fica como esta para a proxima tentativa
		ini = (ini + n) % MAX_BUFFER;
		qtd -= n;
		Serial.printf("lote de %d enviado a %s (restam %d)\n", n, lider.c_str(), qtd);
	} while (qtd > 0);
}

void setup() {
	Serial.begin(115200);
	randomSeed(micros() ^ ESP.getChipId());
	bootId = String((uint32_t)random(0x1000000, 0x7fffffff), HEX); // muda a cada boot, para o seq nao colidir com o de antes de um reinicio

	WiFi.mode(WIFI_AP_STA);
	WiFi.softAP(AP_SSID, AP_PASS);
	WiFi.begin(ROUTER_SSID, ROUTER_PASS);
	WiFi.setAutoReconnect(true);
	Serial.printf("\nAP %s em %s | conectando em %s", AP_SSID, WiFi.softAPIP().toString().c_str(), ROUTER_SSID);
	for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) {
		delay(500);
		Serial.print(".");
	}
	Serial.println("\nIP na rede: " + WiFi.localIP().toString());

	web.on("/ping", []() { web.send(200, "text/plain", "pong"); });
	web.on("/reading", HTTP_POST, aoReceberLeitura);
	web.begin();
}

void loop() {
	web.handleClient();
	if (millis() - ultimoEnvio >= INTERVALO_ENVIO) {
		ultimoEnvio = millis();
		ciclo();
	}
}
