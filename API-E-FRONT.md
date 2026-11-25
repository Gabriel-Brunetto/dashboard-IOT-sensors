# API e guia para a página (front-end)

Tudo é HTTP + JSON, somente leitura (`GET`). **Qualquer um dos 3 nós responde a tudo**, com os mesmos dados
(são réplicas). Os nós ficam em `http://<IP-do-PC>:8081`, `:8082` e `:8083`. CORS liberado (`Access-Control-Allow-Origin: *`).

## Antes de começar

1. **Subir o backend.** Com Docker (a forma oficial, veja `DOCKER.md` para a primeira vez):
   ```bash
   cp .env.example .env          # troque IPdoHOST pelo IP do seu computador
   docker compose up --build -d
   curl -s localhost:8081/status.php   # se responder um JSON, está no ar
   ```
   **Sem Docker (só para desenvolver o front; a entrega e a demonstração usam Docker).** Precisa de PHP 8.3 ou mais novo com a extensão `curl` (Windows: tire o `;` de `extension=curl` no `php.ini`) e de Python 3 para o simulador. Cada nó são 2 processos com as mesmas variáveis, na raiz do repositório:
   ```bash
   # Mac / Linux
   for n in 1 2 3; do
   	mkdir -p /tmp/estacoes/$n
   	export NODE_ID=$n DATA_DIR=/tmp/estacoes/$n
   	export PEERS="1=127.0.0.1:8081,2=127.0.0.1:8082,3=127.0.0.1:8083"
   	php -S 127.0.0.1:808$n -t servidor/public > /dev/null 2>&1 &
   	php servidor/node.php $n > /dev/null 2>&1 &   # o $n só serve para achar o processo depois
   done
   ```
   ```powershell
   # Windows (PowerShell), uma vez por nó (aqui o nó 1). Repita com 2/8082 e 3/8083
   $env:NODE_ID = "1"; $env:DATA_DIR = "$env:TEMP\estacoes1"
   $env:PEERS = "1=127.0.0.1:8081,2=127.0.0.1:8082,3=127.0.0.1:8083"
   New-Item -ItemType Directory -Force $env:DATA_DIR | Out-Null
   Start-Process php -ArgumentList "-S","127.0.0.1:8081","-t","servidor/public"
   Start-Process php -ArgumentList "servidor/node.php","1"
   ```
   Em uns 5 s, `curl -s localhost:8081/status.php` deve listar os 3 nós vivos, com o nó 3 líder. Para parar, `pkill -f "php -S 127.0.0.1:808"; pkill -f node.php` (ou feche as janelas). Para zerar os dados, apague as pastas `DATA_DIR`.
2. **Onde a página fica.** Duas opções:
   - **Arquivos estáticos servidos de `servidor/public/`**. Cada nó serve essa pasta, então `fetch('status.php')` (caminho relativo) já fala com o próprio nó. Neste projeto, o HTML-fonte do painel fica em `index.html` na raiz, e o Dockerfile o copia para a pasta pública da imagem. Se usar um framework (React, Vue...), o `build` precisa gerar os arquivos estáticos em `servidor/public/`. No Docker, depois de mudar o HTML reconstrua a imagem (`docker compose up --build -d`); sem Docker, coloque o HTML em `servidor/public/` para servi-lo junto à API. Teste a página no Docker antes de entregar.
   - **Servidor de desenvolvimento separado** (ex.: Vite em `localhost:5173`) chamando os nós por URL completa (`http://localhost:8081/status.php`). Funciona porque o CORS está liberado, e não precisa reconstruir nada a cada mudança.
3. **Sem o hardware (D1 e sensores)** a lista de estações vem vazia. Use o simulador da seção [Sem hardware](#sem-hardware-o-simulador).

## O que a página deve mostrar

Isto é o que a demonstração e o relatório precisam (o bônus de observabilidade do trabalho pede "painel com estado dos nós, líder atual e relógios lógicos").

**Obrigatório**
1. **Os 3 nós:** quais estão vivos ou fora, e qual é o líder.
2. **O gateway:** vivo ou sem resposta, o IP, e há quanto tempo mandou o último lote.
3. **Cada estação:** ativa ou inativa, a última leitura (temperatura, umidade e horário) e há quanto tempo foi vista.
4. **Gráficos** de temperatura e umidade por estação, com `history.php`.
5. **Eventos** do nó (eleições, falhas, ressincronização).
6. **Falha do líder sem quebrar:** durante a queda e a eleição (cerca de 6 s) a página mostra um aviso de "eleição em andamento" e depois o novo líder, sem erro nem tela em branco (veja "Eleição em andamento" abaixo).
7. **Gateway fora:** aviso claro, e todas as estações como inativas.

**Desejável**
- **Relógios lógicos:** cada nó tem o seu `lamport`. Para mostrar o de todos, chame o `status.php` dos três nós (o campo `lamport` é do nó que respondeu).
- Escolher o período do gráfico (últimos 5 min, 1 h...), e destacar visualmente a troca de líder.
- Funcionar quando o nó que serve a página cai, tentando os outros (exemplo no fim).

**Roteiro da demonstração que a página precisa aguentar:** com o sistema rodando, `docker compose stop no3` (derruba o líder) → a página mostra o nó 3 fora, o aviso de eleição, e o nó 2 como novo líder em cerca de 6 s, com os sensores continuando a aparecer → `docker compose start no3` → o nó 3 volta e retoma a liderança.

## Conceitos que a página precisa conhecer
- **Nó / líder:** só um nó é o líder (o de maior id vivo).
- **Eleição em andamento:** quando o líder cai, durante alguns segundos o `leader` **continua apontando para o nó caído**, e esse nó aparece em `nodes[]` com `alive: false` e `leader: true`. Só depois o `leader` muda para o novo líder. A regra para a página: **há eleição em andamento quando `leader` é `null` ou quando o nó `leader` está com `alive: false`.** O `null` aparece só por um ou dois segundos logo depois que um nó inicia.
- **Gateway:** o D1 mini, que recebe os sensores e repassa ao líder. Se `gateway.alive` for `false`, **todos** os sensores ficam inativos (ninguém sabe o estado deles).
- **Estação:** um sensor (ex.: `esp32-1`, `c3-1`). **Não deixe os ids fixos no código:** novas estações aparecem sozinhas na lista.
- **`alive` de uma estação:** `true` se o gateway a ouviu nos últimos 15 s.
- **Tempos:** `t` é epoch em **segundos** (decimal). Em JavaScript: `new Date(t * 1000)`. Campos `*_age` são segundos desde o evento.
- **Unidades:** `temp` em °C, `umid` em %.
- **`l`:** relógio de Lamport da leitura (inteiro crescente). Útil para a demo de "relógios lógicos"; pode ignorar.

---

## `GET /status.php` — visão geral (chame a cada 2–3 s)
```json
{
  "node": 1,
  "leader": 3,
  "lamport": 145413,
  "nodes": [ { "id": 1, "url": "192.168.1.10:8081", "alive": true, "leader": false },
             { "id": 3, "url": "192.168.1.10:8083", "alive": true, "leader": true } ],
  "gateway": { "id": "d1-gw", "ip": "192.168.1.20", "alive": true, "last_ping_age": 1, "last_batch_age": 4 },
  "esps": [
    { "id": "c3-1", "alive": true, "last_seen_age": 8,
      "latest": [ { "t": 1795575496.151, "temp": 28.8, "umid": 58.2, "l": 145378, "esp": "c3-1", "k": "..." } ] }
  ],
  "events": [ { "t": 1795574262, "msg": "lider agora e o no 3 (coordinator)" } ]
}
```
| Campo | Significado |
|---|---|
| `node` | id do nó que respondeu |
| `leader` | id do líder. É `null` só logo após o nó iniciar, e **aponta para um nó caído** nos primeiros segundos depois que o líder falha (veja "Eleição em andamento") |
| `lamport` | valor atual do relógio lógico **deste nó** |
| `nodes[]` | todos os nós: `alive` (vivo agora), `leader` (é o líder que este nó conhece), `url` (`ip:porta`, **sem** `http://`) |
| `gateway` | `id` e `ip` do gateway (com o simulador, o `ip` traz a porta: `ip:porta`), `alive`, `last_ping_age` (s desde o último ping do líder; `null` = nunca), `last_batch_age` (s desde o último lote recebido; `null` = nunca) |
| `esps[]` | uma por estação já vista. `last_seen_age` é `null` se desconhecido |
| `esps[].latest` | até 5 leituras, **da mais nova para a mais antiga**. Ignore `k` e `esp` (internos) |
| `events[]` | últimos eventos **deste nó** (eleições, falhas), mais novo primeiro, no máximo 15 |

## `GET /history.php?esp=<id>` — histórico para gráficos
Parâmetros: `esp` (obrigatório) · `from` e `to` (epoch em segundos, opcionais) · `limit` (padrão 200, máximo 2000).
Exemplo: `/history.php?esp=c3-1&from=1795575000&limit=500`
```json
{ "esp": "c3-1", "from": 0, "to": 1795575504.67, "count": 2,
  "readings": [ { "t": 1795575490.985, "temp": 28.8, "umid": 58.1, "l": 145332 },
                { "t": 1795575496.151, "temp": 28.8, "umid": 58.2, "l": 145378 } ] }
```
- `readings` vem **em ordem crescente de tempo** (o contrário do `latest` do status): pronto para plotar.
- Se houver mais leituras que `limit`, vêm as **mais recentes** do intervalo.
- Erros: `400 {"error": "..."}` (esp ausente/inválido, parâmetro não numérico) · `404 {"error": "estacao desconhecida"}`.

## `GET /registry.php` — lista de nós
`{ "node": 1, "leader": 3, "nodes": [ { "id", "url", "alive", "leader" } ] }`. Mesmos dados de `status.php`; útil para saber a quais nós a página pode recorrer.

## `GET /leader.php` — quem é o líder
`{ "node": 1, "leader": 3, "url": "192.168.1.10:8083", "lamport": 145412 }`. Como no `status.php`, `leader` e `url` são `null` só logo após o nó iniciar, e apontam para o nó caído nos primeiros segundos de uma falha.

---

## Sem hardware: o simulador
`testes/simular.py` faz o papel do D1: acha o líder, envia lotes ao cluster e responde ao ping do líder. Cria estações `sim-1`, `sim-2`... com temperatura e umidade que oscilam devagar, e já envia alguns minutos de histórico, então os gráficos têm dados desde o primeiro segundo. Só usa a biblioteca padrão do Python.

```bash
python3 testes/simular.py                  # (sem Docker, acrescente --ip 127.0.0.1) 3 estações, leituras a cada 5 s, 10 min de histórico
python3 testes/simular.py --estacoes 6 --historico 60
python3 testes/simular.py --instavel       # estações somem por um tempo de vez em quando
python3 testes/simular.py --limpar         # remove os dados simulados dos nós (reinicia os nós; só com Docker)
```
| Opção | Efeito |
|---|---|
| `--estacoes N` | quantas estações simular (padrão 3) |
| `--intervalo S` | segundos entre as leituras (padrão 5, como o D1 real) |
| `--historico M` | minutos de histórico enviados ao iniciar (padrão 10, `0` desliga) |
| `--instavel` | cada estação some por 25 a 60 s de vez em quando, para testar o estado inativa |
| `--ciclos N` | para depois de N ciclos (padrão: roda até o Ctrl+C) |
| `--host H` | máquina onde estão os nós (padrão `127.0.0.1`) |
| `--ip IP` | IP deste computador, que o líder usa para dar ping (padrão: `HOST_IP` do `.env`). **Sem Docker use `--ip 127.0.0.1`**; o aviso sobre "dentro do container" pode ser ignorado |

Como testar cada estado da página:
- **Estação inativa:** use `--instavel`, ou pare o simulador e espere 15 s.
- **Gateway fora:** com o D1 real desligado, pare o simulador. O líder passa a marcar o gateway como sem resposta em alguns segundos, e todas as estações ficam inativas.
- **Troca de líder:** com Docker, `docker compose stop no3` e depois `docker compose start no3`. Sem Docker, mate os **2 processos** do nó 3 (`pkill -f "127.0.0.1:8083"; pkill -f "node.php 3"`, ou feche as 2 janelas): se morrer só o servidor web, o `node.php` segue mandando heartbeat e o nó continua "vivo". Para voltar, rode de novo os 2 comandos dele.

Cuidados:
- **Os dados simulados ficam gravados nos nós** depois que o simulador para. Use `--limpar` (com Docker; sem Docker, apague as pastas `DATA_DIR`) para removê-los antes de entregar ou demonstrar com o hardware real.
- **Não rode junto com o D1 real ligado:** os dois disputam o `gateway` que o líder guarda, e o `id` e o `ip` do gateway ficam alternando.
- O `gateway.ip` do simulador aparece com a porta (`192.168.1.10:8099`), diferente do D1 real.
- O simulador roda na mesma máquina dos containers (ou use `--host` e `--ip`), e a porta 8099 precisa estar livre.

## Limites dos dados
- **Só temperatura e umidade.** A estação `c3-1` (ESP32-C3 com BME280) também mede pressão, mas ela ainda **não é enviada**, então a página não pode mostrá-la.
- **Os ids são só texto** (`esp32-1`, `c3-1`). Não há nome amigável nem tipo de sensor: se quiser rótulos ("ESP32 com DHT22"), faça o mapeamento na página, e mostre o próprio id para estações que não conhecer.
- **`history.php` devolve no máximo as 2000 leituras mais recentes**, sem paginação nem agregação. A 5 s por leitura, 2000 leituras cobrem cerca de 2 h 45 min. Para períodos maiores, reduza os pontos na página.
- **Só leitura, sem login e sem WebSocket:** a página atualiza por polling.
- **Durante falhas os nós podem diferir por alguns segundos** (um deles ainda sem as últimas leituras). Não trate isso como erro: o nó se ressincroniza sozinho.

## Dicas para a página
1. **Faça polling** (a cada 2–3 s).
2. **Se o nó que serve a página cair, a aba para de atualizar.** Para a demo de falha: abra a página em um nó que não será derrubado, ou faça a página tentar os outros nós quando um `fetch` falhar (exemplo abaixo).
3. **Trate a eleição em andamento** e **`gateway.alive: false`** com mensagens claras, não como erro.
4. Não assuma que toda estação tem leitura: `latest` pode vir vazio.
5. Estações que pararam de mandar continuam na lista com `alive: false`: mostre como "inativa", não esconda.

Exemplo mínimo, tentando os 3 nós em ordem e detectando a eleição:
```js
// os nos ficam no mesmo computador que serve a pagina, nas portas 8081 a 8083
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

// ha eleicao em andamento quando nao ha lider ou quando o lider marcado esta fora do ar
function emEleicao(status) {
	const lider = status.nodes.find(n => n.id === status.leader);
	return status.leader === null || !lider || !lider.alive;
}
```

## Não são para a página (uso interno entre nós/gateway)
`ingest.php`, `replicate.php`, `sync.php`, `hb.php`, `election.php`, `coordinator.php`. Descritos em `PROTOCOLO.md`.

## Testar rápido
```bash
curl -s localhost:8081/status.php | python3 -m json.tool
curl -s "localhost:8081/history.php?esp=c3-1&limit=5"
```
