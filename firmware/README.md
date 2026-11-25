# Firmware

Código das placas: o **gateway** (Wemos D1 mini) e as **estações** que medem temperatura e umidade. Visão geral do projeto no [README da raiz](../README.md); formato exato das mensagens no [`PROTOCOLO.md`](../PROTOCOLO.md).

| Pasta | Placa | Papel |
|---|---|---|
| [`gateway-d1/`](gateway-d1/) | Wemos D1 mini (ESP8266) | junta as leituras das estações e entrega ao líder do cluster |
| [`sensor/`](sensor/) | ESP32, ESP32U ou ESP-12E com DHT | estação `esp32-1`, `esp32u-2` ou `esp12e-3` |
| [`sensor-c3/`](sensor-c3/) | ESP32-C3 SuperMini com BME280 | estação `c3-1` |

## Como funciona

```
estação ──POST /reading──▶ gateway ──POST /ingest.php (lotes)──▶ líder do cluster
 (5 s)      rede própria    buffer de 200 leituras
            192.168.4.1     ◀──GET /ping──── líder
```

**Gateway.** O D1 mini trabalha em dois modos ao mesmo tempo (`WIFI_AP_STA`):
- **cria uma rede Wi-Fi própria** (`AP_SSID`), sempre no endereço `192.168.4.1`, onde as estações se conectam;
- **conecta-se ao roteador** (`ROUTER_SSID`), a mesma rede do computador que roda os contêineres.

Cada leitura que chega em `POST /reading` vai para um **buffer circular de 200 posições** na RAM (cheio, descarta a mais antiga) e recebe um número `seq` crescente. A cada 5 s o gateway envia ao líder um lote com até 20 leituras e, sem leituras, um lote vazio que serve de sinal de vida. Se o envio falha, o lote **fica no buffer** e é reenviado depois, então nada se perde durante uma eleição de líder (com 4 estações, o buffer aguenta cerca de 4 minutos sem servidor).

- **Descoberta do líder:** o gateway não sabe qual nó é o líder. Ele pergunta `/leader.php` a cada nó da lista `SEEDS`, até um responder. Se o líder mudar, o nó antigo responde `421` com o endereço do novo, e o gateway passa a usar esse.
- **Sem relógio:** o ESP não sabe a hora. Por isso cada leitura vai com a **idade** em ms (`age`), e o servidor calcula o horário.
- **Sem duplicatas:** a cada vez que liga, o gateway sorteia um `boot`. A chave `gateway|boot|seq` identifica cada leitura, e o servidor descarta reenvios.
- **Sinal de vida:** o líder chama `GET /ping` no gateway a cada 5 s e espera `pong`.

**Estações.** Leem o sensor a cada 5 s e postam `id`, `temp` e `umid` como formulário em `http://192.168.4.1/reading`. Não guardam histórico: se o gateway estiver fora, a leitura daquele ciclo se perde.

## Preparar a Arduino IDE

1. **Pacotes de placas:** em *Arquivo → Preferências → URLs adicionais*, adicione as duas linhas abaixo e depois instale **esp8266** e **esp32** em *Ferramentas → Placa → Gerenciador de placas*:
   ```
   https://arduino.esp8266.com/stable/package_esp8266com_index.json
   https://espressif.github.io/arduino-esp32/package_esp32_index.json
   ```
2. **Bibliotecas** (*Gerenciar bibliotecas*), aceitando instalar as dependências que a IDE oferecer:
   - **DHT sensor library** (Adafruit), para `sensor/`;
   - **Adafruit BME280 Library**, para `sensor-c3/`.

   O gateway só usa bibliotecas que já vêm com o pacote esp8266.

| Pasta | Placa a escolher na IDE | Observação |
|---|---|---|
| `gateway-d1` | LOLIN(WEMOS) D1 R2 & mini | |
| `sensor` (ESP32 / ESP32U) | ESP32 Dev Module | |
| `sensor` (ESP-12E) | NodeMCU 1.0 (ESP-12E Module) | |
| `sensor-c3` | ESP32C3 Dev Module | ative **USB CDC On Boot: Enabled**, senão o monitor serial fica mudo |

Monitor serial sempre em **115200**.

## `secrets.h`

Cada pasta tem um `secrets.h.example`. Copie como **`secrets.h`** na mesma pasta e preencha. O `secrets.h` está no `.gitignore`, então as senhas não vão para o repositório.

**Gateway** (`gateway-d1/secrets.h`):
```cpp
#define ROUTER_SSID "nome-do-roteador"      // rede onde está o computador dos contêineres
#define ROUTER_PASS "senha-do-roteador"
#define AP_SSID "gateway-estacoes"          // rede que o gateway cria para as estações
#define AP_PASS "uma-senha-forte"           // no mínimo 8 caracteres
const char* SEEDS[] = { "192.168.1.10:8081", "192.168.1.10:8082", "192.168.1.10:8083" };
```
Em `SEEDS` vai o **mesmo IP do `HOST_IP`** do `.env` (o IP do computador), com as portas 8081, 8082 e 8083.

**Estações** (`sensor/secrets.h` e `sensor-c3/secrets.h`): os **mesmos** `AP_SSID` e `AP_PASS` do gateway.

## Gravar e ligar

Ordem recomendada: **suba o cluster** ([`DOCKER.md`](../DOCKER.md)), **grave e ligue o gateway** e, por último, **as estações**.

### Gateway
Grave `gateway-d1.ino`. No serial deve aparecer o IP na rede do roteador e, em seguida, o líder:
```
AP gateway-estacoes em 192.168.4.1 | conectando em nome-do-roteador.....
IP na rede: 192.168.1.20
lider descoberto: 192.168.1.10:8083 (via 192.168.1.10:8081)
lote de 3 enviado a 192.168.1.10:8083 (restam 0)
```

### Estações com DHT (`sensor/`)
1. Em `sensor.ino`, deixe **descomentada só uma** destas linhas:
   ```cpp
   #define ESTACAO_ESP32    // esp32-1,  DHT22
   // #define ESTACAO_ESP32U // esp32u-2, DHT22
   // #define ESTACAO_ESP12E // esp12e-3, DHT11
   ```
2. Ligue o pino de dados do DHT no **GPIO4** (rótulo D2 no NodeMCU), o VCC no 3V3 e o GND no GND. O GPIO2 não serve no ESP32, porque o LED da placa o mantém em nível baixo.
3. Para testar a cadeia inteira sem o sensor, descomente `#define SIMULAR`: a placa passa a enviar valores falsos.

Serial esperado: `esp32-1 -> gateway [200] 24.6C 38.4%`.

### Estação ESP32-C3 com BME280 (`sensor-c3/`)
1. Ligue o BME280 no I2C: **SDA no GPIO8**, **SCL no GPIO9**, VCC no 3V3 e GND no GND. No SuperMini o GPIO8 também acende o LED da placa, e isso é normal.
2. Se a porta não aparecer na hora de gravar, segure o botão **BOOT** enquanto conecta o USB.
3. No serial, o sketch diz em qual endereço achou o sensor (0x76 ou 0x77). Se aparecer "BMP280 (NAO e BME280)", o módulo é um BMP280, que não mede umidade.

A **pressão** é lida e aparece no serial, mas **ainda não é enviada**: o protocolo, o gateway e a API só têm temperatura e umidade.

Pelo terminal, com o `arduino-cli`:
```bash
arduino-cli compile --fqbn esp32:esp32:esp32c3:CDCOnBoot=cdc firmware/sensor-c3
arduino-cli upload  --fqbn esp32:esp32:esp32c3:CDCOnBoot=cdc -p /dev/ttyACM0 firmware/sensor-c3   # no Windows, -p COM5 (a porta da placa)
```

## Adicionar uma estação nova

Copie `sensor/` e troque o `ESTACAO_ID` por um id novo, só com **letras minúsculas, números e hífen**, com até 23 caracteres (ex.: `esp32-4`). A estação aparece sozinha no painel, sem mudar nada no servidor. O gateway acompanha o "último sinal" de até 8 estações ao mesmo tempo.

## Problemas comuns

| Sintoma no serial | Causa provável |
|---|---|
| gateway: `nenhum no respondeu / sem lider` | `SEEDS` com IP errado, cluster desligado, computador em outra rede ou firewall bloqueando as portas 8081 a 8083 |
| gateway: `falha ao enviar [-1]` repetido | o computador mudou de IP: atualize `SEEDS` e o `HOST_IP` do `.env` |
| gateway: `sem rede: mantendo cache` | o gateway perdeu o roteador; as leituras ficam no buffer até voltar |
| estação: fica em `conectando ao gateway ....` | `AP_SSID`/`AP_PASS` diferentes dos do gateway, ou gateway desligado |
| estação: `leitura invalida do DHT` | fio de dados fora do GPIO4, DHT sem alimentação ou tipo errado (DHT11 × DHT22) |
| estação: `-> gateway [-1]` | estação conectada, mas o gateway não respondeu; confira se ele está ligado |
| C3: `BME280 nao encontrado` | confira SDA/SCL (8 e 9), 3V3 e GND |
| C3: monitor serial vazio | ative **USB CDC On Boot** e grave de novo |
