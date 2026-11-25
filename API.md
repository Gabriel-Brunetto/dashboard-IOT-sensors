# API

Todas as rotas HTTP do sistema, para que servem e como chamá-las. Cada arquivo `.php` de [`servidor/public/`](servidor/public/) é uma rota, e os 3 nós servem exatamente as mesmas, em `http://<IP-do-computador>:8081`, `:8082` e `:8083`.

- Guia para quem faz a página: [`API-E-FRONT.md`](API-E-FRONT.md), com todos os campos de `status.php` e `history.php`, eleição em andamento e exemplos.
- Corpo de cada mensagem entre gateway e nós: [`PROTOCOLO.md`](PROTOCOLO.md).
- Como o servidor funciona por dentro: [`servidor/README.md`](servidor/README.md).

## Conceito

- **HTTP + JSON.** As respostas são JSON, com `Access-Control-Allow-Origin: *`, então uma página em qualquer endereço pode chamar a API. Não há login.
- **Qualquer nó responde às leituras.** Os nós são réplicas, então `status.php` e `history.php` dão a mesma resposta em qualquer um. Durante uma falha, um nó pode ficar alguns segundos atrás.
- **Só o líder aceita escritas.** `ingest.php` em um nó que não é o líder responde **421** com o endereço do líder.
- **A raiz** (`/`) serve o painel (`index.html`).

## Rotas

### Para o painel e para clientes (somente leitura)

| Rota | Retorna |
|---|---|
| `GET /status.php` | visão geral: líder, Lamport do nó, os 3 nós (vivos ou não), gateway, estações com as 5 últimas leituras e os últimos eventos |
| `GET /history.php?esp=<id>` | histórico de uma estação, para gráficos. Opcionais: `from` e `to` (epoch em s) e `limit` (padrão 200, máximo 2000) |
| `GET /registry.php` | os nós, se estão vivos e qual é o líder |
| `GET /leader.php` | quem é o líder e o endereço dele |

### Do gateway

| Rota | Função |
|---|---|
| `GET /leader.php` | descoberta: o gateway pergunta a qualquer nó quem é o líder |
| `POST /ingest.php` | entrega de um lote de leituras ao líder |

### Internas (entre os nós)

| Rota | Função |
|---|---|
| `POST /hb.php` | heartbeat, a cada 1 s |
| `POST /election.php` | Bully: "há algum nó maior vivo?" |
| `POST /coordinator.php` | Bully: "sou o novo líder" |
| `POST /replicate.php` | o líder envia as leituras novas aos seguidores |
| `GET /sync.php?since=<L>` | devolve as leituras com Lamport maior que `L` (ressincronização) |

Essas não são para a página, mas `sync.php` pode ser útil para depurar.

## Exemplos

Respostas reais de um cluster com o simulador ligado (sem hardware, o gateway e as estações vêm do simulador).

**Quem é o líder**
```bash
curl -s localhost:8081/leader.php
```
```json
{"node":1,"leader":3,"url":"127.0.0.1:8083","lamport":140}
```
`node` é quem respondeu. `leader` e `url` ficam `null` logo após o nó iniciar.

**Nós do cluster**
```bash
curl -s localhost:8082/registry.php
```
```json
{"node":2,"leader":3,"nodes":[{"id":1,"url":"127.0.0.1:8081","alive":true,"leader":false},
  {"id":2,"url":"127.0.0.1:8082","alive":true,"leader":false},{"id":3,"url":"127.0.0.1:8083","alive":true,"leader":true}]}
```

**Visão geral** (resumida: uma estação, uma leitura e dois eventos)
```bash
curl -s localhost:8081/status.php
```
```json
{
  "node": 1, "leader": 3, "lamport": 140,
  "nodes": [ { "id": 1, "url": "127.0.0.1:8081", "alive": true, "leader": false }, "..." ],
  "gateway": { "id": "gw-simulador", "ip": "127.0.0.1:8099", "alive": true, "last_ping_age": 3, "last_batch_age": 3 },
  "esps": [ { "id": "sim-1", "alive": true, "last_seen_age": 3,
              "latest": [ { "k": "gw-simulador|56516e16|27", "esp": "sim-1", "t": 1795638572.329, "temp": 24.1, "umid": 51.4, "l": 117 } ] } ],
  "events": [ { "t": 1795638562, "msg": "lider agora e o no 3 (coordinator)" }, "..." ]
}
```

**Histórico de uma estação**
```bash
curl -s "localhost:8081/history.php?esp=sim-1&limit=2"
```
```json
{"esp":"sim-1","from":0,"to":1795638575.36,"count":2,"readings":[
  {"t":1795638567.156,"temp":24.1,"umid":51.4,"l":77},{"t":1795638572.329,"temp":24.1,"umid":51.4,"l":117}]}
```
`readings` vem em ordem crescente de tempo, pronto para plotar. Para os últimos 10 minutos, passe `from` com o epoch de agora menos 600.

**Tudo o que um nó tem gravado** (depuração)
```bash
curl -s "localhost:8081/sync.php?since=0"
```

**Enviar um lote de teste**, fazendo o papel do gateway. Isso grava dados de verdade, então use um id de teste e apague depois:
```bash
curl -s -X POST localhost:8083/ingest.php -H "Content-Type: application/json" \
  -d '{"gw":"gw-teste","boot":"abc","ip":"127.0.0.1:9","esps":{"zz-teste":100},"readings":[{"esp":"zz-teste","seq":1,"temp":22.5,"umid":40.1,"age":100}]}'
```
```json
{"ok":true,"accepted":1,"dup":0,"invalid":0,"replicas":3,"leader":3,"lamport":500}
```
Mandando o mesmo lote de novo, vem `"accepted":0,"dup":1`. Mandando a um seguidor (8081), vem `421` com o endereço do líder. Para apagar com Docker, rode `docker compose exec no1 rm /data/leituras/zz-teste.jsonl` (e o mesmo em `no2` e `no3`) e depois `docker compose restart`.

**Do JavaScript**, tentando os 3 nós em ordem (o painel faz isso):
```js
const NOS = [8081, 8082, 8083].map(p => `http://${location.hostname}:${p}`);

async function api(caminho) {
	for (const no of NOS) {
		try {
			const r = await fetch(`${no}/${caminho}`);
			if (r.ok) return await r.json();
		} catch (e) { /* esse no nao respondeu, tenta o proximo */ }
	}
	throw new Error("nenhum no respondeu");
}

const status = await api("status.php");
```

## Códigos de resposta

| Código | Quando | Corpo |
|---|---|---|
| `200` | sucesso | o JSON da rota |
| `400` | parâmetro ausente ou inválido | `{"error":"parametro esp invalido ou ausente"}`, `{"error":"parametro limit deve ser numerico"}`, `{"error":"formato"}` (lote mal formado) ou `{"error":"from"}` (heartbeat sem remetente) |
| `404` | `history.php` de uma estação que não existe | `{"error":"estacao desconhecida","esp":"..."}` |
| `421` | `ingest.php` em um nó que não é o líder | `{"error":"not_leader","leader":3,"url":"192.168.1.10:8083"}`; `leader` e `url` vêm `null` durante uma eleição |

## Unidades e formatos

- `t`: epoch em **segundos**, com casas decimais. Em JavaScript: `new Date(t * 1000)`.
- `*_age`: segundos desde o evento.
- `temp` em °C e `umid` em %.
- `l`: relógio de Lamport da leitura, que define a ordem das escritas.
- `url`: `ip:porta`, **sem** `http://`.
