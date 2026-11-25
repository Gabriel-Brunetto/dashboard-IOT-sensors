// estacao esp32-1: esp32 com dht22, posta a leitura no ponto de acesso do gateway, 192.168.4.1
#include "DHT.h"
#include "secrets.h"
#include <WiFi.h>
#include <HTTPClient.h>

#define ESTACAO_ID "esp32-1"
#define DHTTYPE DHT22

// descomente para gerar leituras falsas, serve para testar a cadeia inteira sem o dht ligado
// #define SIMULAR

// o fio de dados do dht fica no pino rotulado D5, que nesta placa e o gpio5 (confirmado por teste)
// nao use o gpio2: o led da placa o mantem em nivel baixo e o dht nunca responde
#define DHTPIN 5

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
