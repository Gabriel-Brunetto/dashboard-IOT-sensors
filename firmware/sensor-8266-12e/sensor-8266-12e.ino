// estacao esp12e-3: esp8266 (esp-12e) com dht22, posta a leitura no ponto de acesso do gateway, 192.168.4.1
#include "DHT.h"
#include "secrets.h"
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>

#define ESTACAO_ID "esp12e-3"
#define DHTTYPE DHT22

// descomente para gerar leituras falsas, serve para testar a cadeia inteira sem o dht ligado
// #define SIMULAR

// o fio de dados do dht fica no pino rotulado D3, que nesta placa e o gpio0 (confirmado por teste)
// o gpio0 e um pino de boot: o dht nao o segura em nivel baixo, mas desligue o fio de dados para gravar se a gravacao falhar
#define DHTPIN 0

const char* URL_GATEWAY = "http://192.168.4.1/reading"; // ip fixo do ponto de acesso do d1
const unsigned long INTERVALO_LEITURA = 5000;

DHT dht(DHTPIN, DHTTYPE);
unsigned long ultimaLeitura = 0;

void setup() {
	Serial.begin(115200);
	dht.begin();
	WiFi.mode(WIFI_STA);
	WiFi.begin(AP_SSID, AP_PASS);
	WiFi.setAutoReconnect(true);
	Serial.printf("\n%s conectando ao gateway %s", ESTACAO_ID, AP_SSID);
	while (WiFi.status() != WL_CONNECTED) {
		delay(500);
		Serial.print(".");
	}
	Serial.println("\nconectado, IP " + WiFi.localIP().toString());
}

void loop() {
	if (millis() - ultimaLeitura < INTERVALO_LEITURA) return;
	ultimaLeitura = millis();

#ifdef SIMULAR
	float temp = 24.0 + random(-30, 31) / 10.0, umid = 50.0 + random(-100, 101) / 10.0;
	Serial.println("[SIMULADO] leitura falsa");
#else
	float temp = dht.readTemperature(), umid = dht.readHumidity();
#endif
	if (isnan(temp) || isnan(umid)) {
		Serial.println("leitura invalida do DHT");
		return;
	}
	if (WiFi.status() != WL_CONNECTED) {
		Serial.println("sem gateway");
		return;
	}

	WiFiClient client;
	HTTPClient http;
	http.begin(client, URL_GATEWAY);
	http.setTimeout(3000);
	http.addHeader("Content-Type", "application/x-www-form-urlencoded");
	int codigo = http.POST(String("id=") + ESTACAO_ID + "&temp=" + String(temp, 1) + "&umid=" + String(umid, 1));
	http.end();
	Serial.printf("%s -> gateway [%d] %.1fC %.1f%%\n", ESTACAO_ID, codigo, temp, umid);
}
