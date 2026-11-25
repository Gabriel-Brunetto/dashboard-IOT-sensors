# Protocolo de mensagens

Formato de todas as mensagens trocadas no sistema: estação → gateway, gateway ↔ líder e nó ↔ nó.
A lista de todas as rotas está no [`API.md`](API.md), e as de leitura usadas pela página (`status.php`, `history.php`, `registry.php`) estão detalhadas em [`API-E-FRONT.md`](API-E-FRONT.md).

```
estação ──POST /reading──▶ gateway (D1) ──POST /ingest.php──▶ líder ──POST /replicate.php──▶ seguidores
                              ▲   │                              │
                              │   └──GET /leader.php──▶ qualquer nó (descoberta)
                              └──────GET /ping──────────────────┘
nó ◀──POST /hb.php · /election.php · /coordinator.php · GET /sync.php──▶ nó
```

## Convenções

- Transporte: HTTP/1.1. Entre os nós e entre gateway e cluster o corpo é JSON (`Content-Type: application/json`). As exceções são `POST /reading` (formulário) e `GET /ping` (texto puro).
- Endereços: dentro do Docker os nós se falam por `no1:80`, `no2:80` e `no3:80` (variável `PEERS`). O gateway fala com os nós pelo IP do computador e pelas portas publicadas 8081, 8082 e 8083 (variável `PUBLIC`, montada a partir do `HOST_IP` do `.env`). Nos JSON, `url` é sempre `ip:porta`, **sem** `http://`.
- Ids de estação e de gateway: `^[a-z0-9-]{1,32}$` (viram nome de arquivo no servidor). Leituras com id fora disso são descartadas.
- Tempo: `t` é epoch em segundos com 3 casas decimais. O gateway não tem relógio, então manda a **idade** (`age`, em ms) e o líder calcula `t = agora - age / 1000`.
- Relógio de Lamport (`lamport` nas mensagens, `l` nas leituras): o nó soma 1 a cada envio (um heartbeat por vizinho; uma eleição ou um anúncio contam uma vez para todos os destinos) e a cada leitura que grava, e ao receber `hb.php`, a resposta de um heartbeat, `election.php`, `coordinator.php` ou `replicate.php` faz `relógio = max(local, recebido) + 1`.
- Respostas de erro: `{"error": "..."}` com o código HTTP correspondente.

## Formato de uma leitura gravada

É assim que cada leitura fica no armazenamento do nó (uma linha JSON por leitura em `/data/leituras/<esp>.jsonl`) e é assim que ela viaja em `replicate.php` e `sync.php`:

```json
{ "k": "d1-gw|5a3f9c1|42", "esp": "esp32-1", "t": 1795575496.151, "temp": 24.6, "umid": 38.4, "l": 145378 }
```

| Campo | Significado |
|---|---|
| `k` | chave única `gateway\|boot\|seq`. Uma leitura reenviada gera a mesma chave e não é gravada de novo |
| `esp` | id da estação |
| `t` | horário da leitura (epoch em s), calculado pelo líder |
| `temp`, `umid` | temperatura em °C e umidade relativa em % |
| `l` | valor do relógio de Lamport do líder no momento da gravação. Define a ordem das escritas |

---

## 1. Estação → gateway

### `POST http://192.168.4.1/reading`
Enviada a cada 5 s por cada estação, na rede Wi-Fi criada pelo gateway.

```
Content-Type: application/x-www-form-urlencoded

id=esp32-1&temp=24.6&umid=38.4
```

| Resposta | Quando |
|---|---|
| `200` `ok` (texto) | leitura guardada no buffer do gateway |
| `400` `campos` (texto) | falta `id`, `temp` ou `umid`, ou o `id` tem mais de 23 caracteres |

Ids em uso: `esp32-1` (ESP32 + DHT22), `esp32u-2` (ESP32U + DHT22), `esp12e-3` (ESP-12E + DHT11), `c3-1` (ESP32-C3 + BME280). A pressão do BME280 é lida, mas **não faz parte do protocolo** ainda.

O gateway guarda até 200 leituras em um buffer circular (quando enche, descarta a mais antiga) e numera cada uma com um `seq` crescente.

## 2. Gateway → cluster

### `GET /leader.php` (descoberta do líder)
O gateway pergunta, em ordem, a cada nó-semente (`SEEDS` no `secrets.h` do gateway) e fica com a primeira resposta que traga `url`. Timeout de 2 s por nó.

```json
{ "node": 1, "leader": 3, "url": "192.168.1.10:8083", "lamport": 145412 }
```
`leader` e `url` são `null` logo após o nó iniciar. Nos primeiros segundos de uma falha, apontam para o líder que caiu.

### `POST /ingest.php` (entrega de um lote)
A cada 5 s o gateway envia ao líder até 20 leituras do buffer. Sem leituras, envia o lote vazio, que serve de sinal de vida. Timeout de 3 s.

```json
{
  "gw": "d1-gw",
  "boot": "5a3f9c1",
  "ip": "192.168.1.20",
  "esps": { "esp32-1": 1200, "c3-1": 800 },
  "readings": [
    { "esp": "esp32-1", "seq": 42, "temp": 24.6, "umid": 38.4, "age": 3150 }
  ]
}
```

| Campo | Significado |
|---|---|
| `gw` | id do gateway |
| `boot` | identificador sorteado a cada vez que o gateway liga, para o `seq` não colidir com o de antes de um reinício |
| `ip` | IP do gateway na rede do roteador. O líder usa esse endereço para o `GET /ping` |
| `esps` | para cada estação já ouvida, há quantos ms ela mandou a última leitura |
| `readings[].seq` | número da leitura no gateway, crescente desde o boot |
| `readings[].age` | há quantos ms a leitura chegou ao gateway |

Respostas:

| Código | Corpo | O que o gateway faz |
|---|---|---|
| `200` | `{"ok": true, "accepted": 1, "dup": 0, "invalid": 0, "replicas": 3, "leader": 3, "lamport": 500}` | tira o lote do buffer e segue para o próximo |
| `421` | `{"error": "not_leader", "leader": 3, "url": "192.168.1.10:8083"}` | passa a usar `url`. Se `url` vier `null` (eleição em andamento), redescobre o líder no próximo ciclo |
| `400` | `{"error": "formato"}` | `gw` inválido ou `readings` não é uma lista |
| sem resposta | | esquece o líder e redescobre no próximo ciclo |

Em qualquer falha o lote **continua no buffer** e é reenviado. `accepted` conta as leituras novas, `dup` as que já existiam (reenvio) e `invalid` as descartadas por id ou campos inválidos. `replicas` é em quantos nós o lote ficou gravado, o líder incluído.

## 3. Líder → gateway

### `GET http://<ip do gateway>/ping`
O líder pergunta a cada 5 s, com timeout de 1,5 s. O gateway responde `200` com o texto `pong`. Sem resposta, o líder marca o gateway como fora do ar, e todas as estações passam a aparecer como inativas.

## 4. Entre os nós

### `POST /hb.php` (heartbeat)
A cada 1 s, cada nó envia a todos os outros, em paralelo, com timeout de 0,6 s. Um nó que passa **3 s** sem heartbeat é considerado caído.

```json
{ "from": 1, "lamport": 120, "leader": 3 }
```
Se quem envia é o líder, vai junto o campo `gw` com o estado do gateway, para que os seguidores já o tenham se precisarem assumir:
```json
{ "from": 3, "lamport": 121, "leader": 3,
  "gw": { "id": "d1-gw", "ip": "192.168.1.20", "ok": true, "last_ping": 1795575490, "last_batch": 1795575494,
          "esps": { "esp32-1": 1795575493 } } }
```
Resposta: `{"id": 2, "leader": 3, "lamport": 122}`, ou `400 {"error": "from"}` sem o campo `from`.

Regras de quem recebe: aceita o remetente como líder se ele se diz líder (`leader == from`) e o id dele é maior ou igual ao do líder atual, ou se o líder atual está caído. Regra de quem envia: se ele se achava líder e a resposta traz um `leader` de id maior, cede a liderança.

### `POST /election.php` (Bully)
Enviada aos nós de **id maior** quando não há líder, o líder caiu, o líder tem id menor ou um nó menor pediu eleição. Timeout de 0,8 s.

```json
{ "from": 1, "lamport": 130 }
```
Resposta: `{"ok": true, "id": 3}`. Quem recebe de um id menor marca que deve conduzir a própria eleição no próximo ciclo.
Se **nenhum** nó maior responde, o remetente se declara líder e envia `coordinator.php`. Se algum responde, ele espera até **4 s** por um `coordinator.php` e, se não chegar, refaz a eleição.

### `POST /coordinator.php` (Bully)
Enviada pelo novo líder a todos os outros nós.

```json
{ "from": 3, "lamport": 131 }
```
Resposta: `{"ok": true, "leader": 3}`. Quem recebe aceita se `from` for maior ou igual ao próprio id. Se for menor, responde `ok: false` e conduz uma eleição no próximo ciclo, porque deve ser ele o líder.

### `POST /replicate.php` (replicação)
O líder envia na hora, a cada lote aceito, as leituras novas aos seguidores vivos. Timeout de 1 s.

```json
{ "from": 3, "lamport": 500,
  "readings": [ { "k": "d1-gw|5a3f9c1|42", "esp": "esp32-1", "t": 1795575496.151, "temp": 24.6, "umid": 38.4, "l": 500 } ],
  "gw": { "id": "d1-gw", "ip": "192.168.1.20", "ok": true, "last_ping": 1795575490, "last_batch": 1795575496, "esps": { "esp32-1": 1795575496 } } }
```
Resposta: `{"ok": true, "stored": 1}` (`stored` não conta duplicatas).

### `GET /sync.php?since=L` (ressincronização)
Devolve as leituras com `l > L`, em ordem crescente de `l`, no máximo 2000 por chamada. Qualquer nó responde. Timeout de 3 s.

```json
{ "readings": [ { "k": "...", "esp": "c3-1", "t": 1795575501.2, "temp": 28.8, "umid": 58.2, "l": 501 } ] }
```
Quem chama envia como `since` o maior `l` que já tem gravado. É usada:
- ao iniciar, com o primeiro vizinho vivo, antes de disputar a liderança;
- por um seguidor, com o líder, logo após uma troca de líder e depois a cada 10 s.

Como a gravação ignora chaves repetidas, repetir uma sincronização é seguro.

---

## Tempos

| Parâmetro | Valor | Onde |
|---|---|---|
| leitura de cada estação | 5 s | firmware das estações |
| envio de lote pelo gateway | 5 s, até 20 leituras | `gateway-d1.ino` |
| buffer do gateway | 200 leituras | `gateway-d1.ino` |
| ciclo do nó (heartbeat) | 1 s | `node.php` |
| nó considerado caído | 3 s sem heartbeat (`PEER_TTL`) | `lib.php` |
| espera pelo `coordinator.php` | 4 s | `node.php` |
| ping do líder no gateway | 5 s (`GW_PING_INTERVAL`) | `lib.php` |
| estação considerada inativa | 15 s sem ser ouvida (`ESP_TTL`) | `lib.php` |
| ressincronização periódica | 10 s | `node.php` |
