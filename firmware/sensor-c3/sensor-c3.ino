// sensor esp32-c3 supermini com bme280 (i2c) que posta a leitura no ponto de acesso do gateway, 192.168.4.1, como o sensor.ino
// o protocolo so leva temperatura e umidade, a pressao e lida e aparece no serial mas ainda nao e enviada
//
// placa "ESP32C3 Dev Module" com "USB CDC On Boot" ativado, ou o fqbn esp32:esp32:esp32c3:CDCOnBoot=cdc no arduino-cli
// a porta e a /dev/ttyACM0, e se ela nao aparecer ao gravar, segure o botao BOOT enquanto liga o usb
#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <Adafruit_BME280.h>
#include "secrets.h"

#define ESTACAO_ID "c3-1"

// pinos i2c, 8 e 9 sao o padrao do core para o c3 (no supermini o gpio8 tambem acende o led da placa, e isso e normal)
#define PIN_SDA 8
#define PIN_SCL 9

const char* URL_GATEWAY = "http://192.168.4.1/reading"; // ip fixo do ponto de acesso do d1
const unsigned long INTERVALO_LEITURA = 5000;

Adafruit_BME280 bme;
bool bmeOk = false;
unsigned long ultimaLeitura = 0;

// tenta iniciar o bme280 nos dois enderecos possiveis e diz no serial o que encontrou
bool iniciarSensor() {
	Wire.begin(PIN_SDA, PIN_SCL);
	const uint8_t enderecos[] = {0x76, 0x77};
	for (uint8_t end : enderecos) {
		if (bme.begin(end, &Wire)) {
			Serial.printf("BME280 encontrado no endereco 0x%02X (chip id 0x%02X)\n", end, bme.sensorID());
			return true;
		}
		uint8_t id = bme.sensorID(); // depois de um begin() que falhou guarda o id lido, e 0xFF ou 0x00 quer dizer que nada respondeu
		if (id == 0x58 || id == 0x56 || id == 0x57)
			Serial.printf("0x%02X: chip id 0x%02X = BMP280 (NAO e BME280: nao tem umidade!)\n", end, id);
	}
	Serial.println("BME280 nao encontrado. Confira SDA/SCL, VCC (3V3) e GND.");
	return false;
}

void setup() {
	Serial.begin(115200);
	delay(1500); // da tempo do monitor serial usb abrir
	bmeOk = iniciarSensor();

	WiFi.mode(WIFI_STA);
	WiFi.begin(AP_SSID, AP_PASS);
	WiFi.setTxPower(WIFI_POWER_8_5dBm); // alguns supermini so conectam com a potencia reduzida, e o gateway esta perto
	WiFi.setAutoReconnect(true);
	Serial.printf("%s conectando ao gateway %s", ESTACAO_ID, AP_SSID);
	while (WiFi.status() != WL_CONNECTED) {
		delay(500);
		Serial.print(".");
	}
	Serial.println("\nconectado, IP " + WiFi.localIP().toString());
}

void loop() {
	if (millis() - ultimaLeitura < INTERVALO_LEITURA) return;
	ultimaLeitura = millis();

	// sem o sensor tenta iniciar de novo a cada ciclo, e assim ele pode ser ligado depois
	if (!bmeOk) {
		bmeOk = iniciarSensor();
		return;
	}

	float temp = bme.readTemperature(), umid = bme.readHumidity(), pres = bme.readPressure() / 100.0; // pressao em hPa
	if (isnan(temp) || isnan(umid)) {
		Serial.println("leitura invalida do BME280");
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
	Serial.printf("%s -> gateway [%d] %.1fC %.1f%% (pressao %.1f hPa, nao enviada)\n", ESTACAO_ID, codigo, temp, umid, pres);
}
