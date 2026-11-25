# Servidor (nó do cluster)

O código que roda em cada um dos 3 nós: recebe as leituras do gateway, elege o líder, replica os dados e serve a API e o painel. É PHP 8.3 puro, sem framework e sem banco de dados. Como subir os nós: [`DOCKER.md`](../DOCKER.md). Rotas: [`API.md`](../API.md). Formato das mensagens: [`PROTOCOLO.md`](../PROTOCOLO.md).

## Os dois processos de um nó

Cada nó são **dois processos PHP** que compartilham o mesmo estado em disco:

| Processo | Comando | O que faz |
|---|---|---|
| servidor web | `php -S 0.0.0.0:80 -t public` | atende as requisições: API (`public/*.php`) e painel (`public/index.html`) |
| daemon | `php node.php` | laço de 1 s: heartbeat, eleição, ping no gateway e ressincronização |

O estado do nó fica em `DATA_DIR/state.json` e é sempre lido e gravado por `state_update()`, com **lock exclusivo de arquivo** (`flock`), porque os workers web e o daemon escrevem ao mesmo tempo.

Para derrubar um nó **sem Docker**, é preciso matar os dois processos. Se só o servidor web morrer, o daemon continua mandando heartbeat e os outros acham que o nó está vivo.

## Arquivos

```
servidor/
  lib.php         configuração, estado compartilhado, relógio de Lamport, cliente HTTP
  storage.php     armazenamento das leituras
  node.php        o daemon
  public/
    index.html    painel
    status.php  history.php  registry.php  leader.php     leitura (painel e gateway)
    ingest.php                                            entrada de lotes do gateway (só o líder)
    hb.php  election.php  coordinator.php                 coordenação entre nós
    replicate.php  sync.php                               replicação entre nós
```

### `lib.php`
- **Configuração**, lida do ambiente: `NODE_ID`, `DATA_DIR`, `PEERS` (endereços internos dos nós) e `PUBLIC` (endereços que o gateway enxerga).
- **Constantes de tempo:** `PEER_TTL = 3` (s sem heartbeat até um nó ser dado como caído), `ESP_TTL = 15` (s sem leitura até uma estação ficar inativa) e `GW_PING_INTERVAL = 5` (s entre os pings no gateway).
- **Estado:** `state_default()`, `state_update($fn)`, `state_read()` e `st_event()`, que guarda os 15 últimos eventos e também os escreve no log.
- **Lamport:** `l_tick()` soma 1 a cada envio ou gravação; `l_merge($recebido)` faz `max(local, recebido) + 1` ao receber.
- **Rede:** `peer_vivo($id)`, `http()` e `http_multi()`, que dispara várias requisições ao mesmo tempo com `curl_multi`, e `json_out()`, que responde em JSON com CORS liberado.

### `storage.php`
Uma leitura é `{k, esp, t, temp, umid, l}` (veja o [`PROTOCOLO.md`](../PROTOCOLO.md)). Elas ficam em `DATA_DIR/leituras/<estação>.jsonl`, uma por linha.

| Função | O que faz |
|---|---|
| `store_save($r)` | grava a leitura; devolve `false` se a chave `k` já existe entre as 400 últimas (é isso que impede duplicatas) |
| `store_latest($esp, $n)` | as `n` mais recentes, da mais nova para a mais antiga |
| `store_history($esp, $from, $to, $limit)` | intervalo de tempo, em ordem crescente, para os gráficos |
| `store_since($l)` | as leituras com Lamport maior que `$l` (até 2000), em ordem de Lamport, para a ressincronização |
| `store_max_l()` | o maior Lamport gravado, que é o ponto de partida da ressincronização |
| `store_esps()` | as estações que têm arquivo |

Para trocar por um banco de dados, basta reimplementar as funções `store_*` com as mesmas assinaturas.

### O estado (`state.json`)
| Campo | Significado |
|---|---|
| `lamport` | relógio de Lamport do nó |
| `leader` | id do líder que este nó conhece (`null` logo após iniciar) |
| `peers` | quando cada vizinho mandou o último heartbeat |
| `gw` | o que se sabe do gateway: id, ip, se responde ao ping, último lote e último sinal de cada estação |
| `needs_sync` | `true` quando pode faltar leituras aqui (por exemplo, depois de uma troca de líder) |
| `electing_until` | até quando esperar o anúncio do novo líder antes de refazer a eleição |
| `elect_req` | um nó menor pediu eleição; o daemon atende no próximo ciclo |
| `events` | últimos eventos, mostrados no painel |

## Como as coisas acontecem

### Partida de um nó (`node.php`)
1. Apaga o estado antigo e **mantém só o relógio de Lamport**: um nó que reinicia não deve confiar no que sabia, mas o relógio não pode voltar.
2. Faz uma rodada de heartbeat para descobrir quem está vivo.
3. **Ressincroniza** com o primeiro vizinho vivo (`GET /sync.php?since=<maior Lamport local>`).
4. Só então entra no laço e disputa a liderança.

### O laço de 1 s
1. **Heartbeat:** `POST /hb.php` a todos os vizinhos ao mesmo tempo, com timeout de 0,6 s. O líder manda junto o estado do gateway, para os seguidores já o terem se precisarem assumir.
2. **Eleição, se preciso:** quando não há líder, o líder não manda heartbeat há mais de 3 s, o líder tem id menor ou um nó menor pediu eleição.
3. **Ping no gateway** a cada 5 s, se este nó é o líder.
4. **Ressincronização** com o líder, se este nó é seguidor: logo após uma troca de líder e depois a cada 10 s.

### Eleição (Bully)
1. O nó envia `POST /election.php` aos nós de **id maior**.
2. **Ninguém responde:** ele se declara líder e anuncia com `POST /coordinator.php` a todos.
3. **Alguém responde:** um nó maior está vivo e vai assumir. Ele espera o anúncio por até 4 s e, se o anúncio não vier, refaz a eleição.
4. Quem recebe `election.php` de um nó menor marca `elect_req` e conduz a própria eleição no ciclo seguinte.
5. `coordinator.php` só é aceito de um nó de id **maior ou igual**. Se um nó menor se anunciar, quem é maior faz a própria eleição.

Resultado: o líder é sempre o **nó vivo de maior id**. Quando o nó 3 cai, o 2 assume; quando o 3 volta, ele desafia o 2 e retoma a liderança. Um líder que descobre pelo heartbeat outro líder de id maior também cede o posto.

### Chegada de um lote (`public/ingest.php`)
1. Se este nó não é o líder: responde **421** com o endereço do líder.
2. Valida o lote (`gw`, `readings`) e cada leitura (id, `temp`, `umid`, `seq`).
3. Para cada leitura: soma 1 no Lamport, monta a chave `gw|boot|seq`, calcula `t = agora - age` e grava. Se a chave já existe, conta como duplicada.
4. Atualiza o estado do gateway (ip, último lote, último sinal de cada estação).
5. **Replica** as leituras novas a cada seguidor vivo (`POST /replicate.php`) e responde ao gateway quantas réplicas gravaram.

Um seguidor que estava fora não recebe essa réplica, mas recupera tudo depois pelo `sync.php`. Como `store_save` ignora chaves repetidas, repetir uma réplica ou uma sincronização é seguro.

### Gateway e estações vivos ou não
- O **gateway** está vivo se respondeu `pong` ao último ping do líder (ou acabou de mandar um lote).
- Uma **estação** está ativa se o gateway a ouviu nos últimos 15 s **e** o gateway está vivo. Sem gateway, ninguém sabe o estado dos sensores.

## Variáveis de ambiente

| Variável | Exemplo | Para que serve |
|---|---|---|
| `NODE_ID` | `1` | id do nó; o maior id vivo é o líder |
| `PEERS` | `1=no1:80,2=no2:80,3=no3:80` | endereços dos nós entre si (incluindo o próprio) |
| `PUBLIC` | `1=192.168.1.10:8081,...` | endereços que o gateway usa; é o que `/leader.php` devolve |
| `DATA_DIR` | `/data` | onde ficam `state.json` e `leituras/` |
| `PHP_CLI_SERVER_WORKERS` | `4` | processos do servidor web. Com só 1, uma requisição lenta trava as outras |

No Docker, todas já vêm do `docker-compose.yml` e do `Dockerfile`.

## Rodar sem Docker

Precisa de PHP 8.3 ou mais novo com a extensão `curl`. Os comandos para Linux, Mac e Windows estão na seção "Antes de começar" do [`API-E-FRONT.md`](../API-E-FRONT.md). Para cada nó, são os dois processos com as mesmas variáveis:

```bash
export NODE_ID=1 DATA_DIR=/tmp/estacoes/1 PHP_CLI_SERVER_WORKERS=4
export PEERS="1=127.0.0.1:8081,2=127.0.0.1:8082,3=127.0.0.1:8083"
php -S 127.0.0.1:8081 -t servidor/public &
php servidor/node.php 1 &
```

## Ajustar o comportamento

| O quê | Onde |
|---|---|
| tempo até um nó ser dado como caído | `PEER_TTL` em `lib.php` |
| tempo até uma estação ficar inativa | `ESP_TTL` em `lib.php` |
| intervalo do ping no gateway | `GW_PING_INTERVAL` em `lib.php` |
| espera pelo anúncio do novo líder (4 s) | `run_election()` em `node.php` |
| ressincronização periódica (10 s) | laço principal de `node.php` |
| duração do ciclo (1 s) | fim do laço de `node.php` |
| timeouts das requisições | chamadas de `http()` e `http_multi()` |

Depois de mudar algo, no Docker: `docker compose up --build -d`.
