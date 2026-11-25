# Monitoramento Distribuído de Sensores IoT

Trabalho final de Sistemas Distribuídos (URI Erechim, 2026): Eduardo Ongaratto, Gabriel Brunetto, João Guilherme e Vinícius Alexandre Lucas.

Estações com ESP32, ESP-12E e ESP32-C3 medem temperatura e umidade e enviam as leituras a um gateway Wemos D1 mini, que as entrega em lotes a um cluster de **3 nós em contêineres Docker**. Os nós se monitoram por **heartbeat**, elegem um líder pelo **algoritmo Bully**, carimbam cada leitura com um **relógio de Lamport** e **replicam** os dados entre si. Se o líder cair, outro assume em poucos segundos, e o nó que volta recupera o que perdeu. Um painel web mostra os nós, o líder, o gateway, as estações e os gráficos.

```
estações ──▶ gateway D1 ──▶ nó líder ──▶ nós seguidores
 (sensores)    (buffer)      (Bully)       (réplicas)
                                ▲
                  painel web ───┘ (qualquer nó responde)
```

## Documentação

| Documento | Conteúdo |
|---|---|
| [`DOCKER.md`](DOCKER.md) | como o cluster é montado, como subir, derrubar um nó e resolver problemas |
| [`firmware/README.md`](firmware/README.md) | gateway e estações: como funcionam, como gravar e ligar |
| [`servidor/README.md`](servidor/README.md) | o que roda em cada nó: processos, eleição, replicação, ressincronização |
| [`API.md`](API.md) | todas as rotas HTTP, com exemplos |
| [`API-E-FRONT.md`](API-E-FRONT.md) | guia para a página: campos de `status.php` e `history.php`, eleição em andamento |
| [`PROTOCOLO.md`](PROTOCOLO.md) | formato exato de cada mensagem entre estações, gateway e nós |
| [`testes/README.md`](testes/README.md) | simulador de gateway e testes automatizados |

## Conceitos e onde estão no código

| Conceito | Como foi feito | Onde |
|---|---|---|
| Nós em contêineres | 3 contêineres idênticos (`no1`, `no2` e `no3`), cada um com volume próprio | `docker-compose.yml`, `Dockerfile` |
| Comunicação | HTTP com JSON entre gateway e nós e entre os nós | `servidor/lib.php`, [`PROTOCOLO.md`](PROTOCOLO.md) |
| Descoberta de serviços | nós se acham pela variável `PEERS`; o gateway pergunta `/leader.php` a qualquer nó-semente | `docker-compose.yml`, `servidor/public/leader.php`, `firmware/gateway-d1` |
| Detecção de falhas | heartbeat a cada 1 s; nó sem heartbeat por 3 s é dado como caído | `servidor/node.php`, `servidor/public/hb.php` |
| Eleição de líder | Bully: vence o nó vivo de maior id | `servidor/node.php`, `election.php`, `coordinator.php` |
| Ordenação | relógio de Lamport carimbando cada leitura | `servidor/lib.php` (`l_tick`, `l_merge`) |
| Replicação | primário-backup: o líder grava e repassa aos seguidores | `servidor/public/ingest.php`, `replicate.php` |
| Recuperação | o nó que volta pede o que perdeu, a partir do maior Lamport que tem | `servidor/public/sync.php`, `servidor/node.php` |
| Sem perda no caminho | o gateway guarda até 200 leituras e reenvia; chave única evita duplicatas | `firmware/gateway-d1`, `servidor/storage.php` |

## Início rápido

Precisa de Docker (e de Python 3 para o simulador). Na raiz do repositório:

```bash
cp .env.example .env              # Windows (PowerShell): copy .env.example .env
# edite o .env: troque IPdoHOST pelo IP deste computador na rede do gateway
docker compose up --build -d      # sobe os 3 nós
python3 testes/simular.py         # sem hardware: gateway e estações simulados
```

Abra o painel em <http://localhost:8081>. Para ver a eleição acontecer:

```bash
docker compose stop no3           # derruba o líder: o nó 2 assume
docker compose start no3          # o nó 3 volta, recupera os dados e retoma a liderança
```

Com o hardware real, em vez do simulador, grave os firmwares seguindo o [`firmware/README.md`](firmware/README.md). Não use os dois ao mesmo tempo. Detalhes e problemas comuns estão no [`DOCKER.md`](DOCKER.md).

## Estrutura

```
docker-compose.yml        3 nós nas portas 8081, 8082 e 8083
Dockerfile                imagem php:8.3-cli-alpine com o servidor
.env.example              modelo do .env (IP do computador)
servidor/                 código dos nós e o painel          → servidor/README.md
  lib.php  storage.php  node.php
  public/                 rotas da API e index.html          → API.md
firmware/                 gateway e estações (Arduino)       → firmware/README.md
  gateway-d1/  sensor/  sensor-c3/
testes/                   simulador e testes automatizados   → testes/README.md
```

## Uso de IA

O assistente Claude (Anthropic) foi usado como apoio na análise do código, nos testes e na redação da documentação (READMEs, `PROTOCOLO.md` e relatório).
