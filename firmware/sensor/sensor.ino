// sensor com dht (esp32, esp32u ou esp12e) que posta a leitura no ponto de acesso do gateway, 192.168.4.1
// para escolher a estacao, deixe descomentada uma, e so uma, das tres linhas ESTACAO_ abaixo
#include "DHT.h"
#include "secrets.h"

#ifdef ESP8266
	#include <ESP8266WiFi.h>
	#include <ESP8266HTTPClient.h>
#else
	#include <WiFi.h>
	#include <HTTPClient.h>
#endif

#define ESTACAO_ESP32 // esp32 com dht22
// #define ESTACAO_ESP32U // esp32u com dht22
// #define ESTACAO_ESP12E // esp12e com dht11

// descomente para gerar leituras falsas, serve para testar a cadeia inteira sem o dht ligado
// #define SIMULAR

#if defined(ESTACAO_ESP32)
	#define ESTACAO_ID "esp32-1"
	#define DHTTYPE DHT22
#elif defined(ESTACAO_ESP32U)
	#define ESTACAO_ID "esp32u-2"
	#define DHTTYPE DHT22
#elif defined(ESTACAO_ESP12E)
	#define ESTACAO_ID "esp12e-3"
	#define DHTTYPE DHT11
#endif

// todos os sensores usam o gpio4, que no esp12e nodemcu e o rotulo D2 e no esp32 e o rotulo D4
// o gpio2 nao serve no esp32, o led da placa o mantem em nivel baixo e o dht nunca responde
#define DHTPIN 4

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
