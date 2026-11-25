# Docker

Como o cluster de 3 nós é montado com Docker e como operá-lo: subir, acompanhar, derrubar um nó e limpar. Visão geral do projeto no [README](README.md); o que roda dentro de cada contêiner, no [`servidor/README.md`](servidor/README.md).

## Como funciona

O [`docker-compose.yml`](docker-compose.yml) cria **3 contêineres idênticos**, `no1`, `no2` e `no3`, todos a partir do mesmo [`Dockerfile`](Dockerfile). O que muda entre eles é só o `NODE_ID`, a porta publicada e o volume:

| Nó | `NODE_ID` | Porta no computador | Volume (dados) |
|---|---|---|---|
| `no1` | 1 | 8081 | `dados1` |
| `no2` | 2 | 8082 | `dados2` |
| `no3` | 3 | 8083 | `dados3` |

**Imagem.** `php:8.3-cli-alpine` com a pasta `servidor/` copiada para `/app`. Cada contêiner roda **dois processos**:
- `php -S 0.0.0.0:80 -t /app/public`: servidor web com a API e o painel, com 4 workers (`PHP_CLI_SERVER_WORKERS=4`) para atender várias requisições ao mesmo tempo;
- `php /app/node.php`: processo de fundo com heartbeat, eleição, ping no gateway e ressincronização.

O `sh` é o processo principal e repassa o sinal de parada aos dois (`trap`). Por isso `docker compose stop` derruba um nó em poucos segundos (a suíte de testes exige menos de 5 s), em vez dos 10 s que o Docker esperaria.

**Endereços sem nada fixo no código.** Os nós se acham por variáveis de ambiente definidas no compose:
- `PEERS="1=no1:80,2=no2:80,3=no3:80"`: como os nós falam **entre si**, pelos nomes de serviço que o DNS interno do Docker resolve;
- `PUBLIC="1=<HOST_IP>:8081,..."`: como o **gateway** enxerga cada nó, pelo IP do computador e pelas portas publicadas. É esse endereço que o `/leader.php` devolve ao gateway.

O `HOST_IP` vem do arquivo `.env`. Se ele faltar, o compose para com o erro `defina HOST_IP no .env`.

**Dados.** Cada nó grava em `/data`, que é um volume próprio, então os dados sobrevivem a `stop`, `restart` e `down`. Ali ficam o `state.json` (estado de coordenação) e `leituras/<estação>.jsonl` (uma leitura por linha).

**Sem reinício automático** (`restart: "no"`): um nó derrubado fica fora até você religá-lo, que é o que a demonstração de falha precisa.

## Instalação

- **Windows:** instale o **Docker Desktop** (usa o WSL 2) e deixe-o aberto enquanto usa os comandos.
- **Linux:** instale o Docker Engine e o plugin `docker compose`.
- **Mac:** Docker Desktop.

Confira com `docker compose version`.

## Primeira vez

Na raiz do repositório:

1. **Crie o `.env`** a partir do modelo:
   ```bash
   cp .env.example .env          # Windows (PowerShell): copy .env.example .env
   ```
2. **Ponha o IP do computador** no lugar de `IPdoHOST`. Precisa ser o IP na rede em que o gateway está (o mesmo roteador):
   - Windows: `ipconfig` (campo "Endereço IPv4" do adaptador Wi-Fi ou Ethernet);
   - Linux: `hostname -I`;
   - Mac: `ipconfig getifaddr en0`.
   ```
   HOST_IP=192.168.1.10
   ```
3. **Construa e suba:**
   ```bash
   docker compose up --build -d
   ```
4. **Confira:** em uns 5 s os 3 nós estão vivos e o nó 3 é o líder.
   ```bash
   docker compose ps
   curl -s localhost:8081/status.php
   ```
   O painel abre em <http://localhost:8081> (ou 8082, 8083).

Se o `HOST_IP` mudar (outra rede, outro dia), edite o `.env` e rode `docker compose up -d` de novo. Atualize também o `SEEDS` do gateway ([`firmware/README.md`](firmware/README.md)).

## Comandos do dia a dia

| Para | Comando |
|---|---|
| subir (ou aplicar mudanças no código) | `docker compose up --build -d` |
| ver se estão de pé | `docker compose ps` |
| acompanhar eventos (eleições, falhas, sincronização) | `docker compose logs -f` (ou só um: `docker compose logs -f no3`) |
| derrubar um nó | `docker compose stop no3` |
| religar um nó | `docker compose start no3` |
| reiniciar tudo | `docker compose restart` |
| abrir um terminal dentro de um nó | `docker compose exec no1 sh` |
| ver o estado de um nó | `docker compose exec no1 cat /data/state.json` |
| contar as leituras de um nó | `docker compose exec no1 sh -c "cat /data/leituras/*.jsonl \| wc -l"` |
| desligar (mantém os dados) | `docker compose down` |
| desligar e **apagar os dados** | `docker compose down -v` |

Nos logs, cada evento aparece como `[no 2] lider 3 nao responde: iniciando eleicao`.

## Demonstração de falha

Com o sistema recebendo leituras (gateway real ou `python3 testes/simular.py`), deixe o painel aberto em <http://localhost:8081> e, em outro terminal, rode `docker compose logs -f`.

1. `docker compose stop no3`: o líder cai. Em cerca de 4 a 6 s, os nós 1 e 2 percebem a falta de heartbeat, o nó 2 vence a eleição e o painel mostra o aviso de eleição e depois o **nó 2 como líder**. O gateway recebe `421` ou erro de conexão, redescobre o líder e reenvia o que estava no buffer.
2. `docker compose start no3`: o nó 3 volta, ressincroniza as leituras que perdeu e **retoma a liderança**, por ser o de maior id.

Para conferir que nada se perdeu, compare a contagem de leituras dos três nós (tabela acima): depois de alguns segundos ela fica igual.

O painel continua funcionando mesmo se o nó que o serve cair, porque ele tenta os outros nós (8081, 8082 e 8083).

## Problemas comuns

| Sintoma | Causa e solução |
|---|---|
| `defina HOST_IP no .env` | falta o `.env` ou a linha `HOST_IP=` (passo 1 e 2) |
| build para com `"/app/public/index.html": not found` | a linha `COPY /app/public/index.html /app/public/index.html` do `Dockerfile` aponta para uma pasta que não existe no repositório. Apague essa linha: o `COPY servidor/ /app/` logo acima já leva o painel para `/app/public/index.html` |
| `port is already allocated` | outro programa usa 8081, 8082 ou 8083; feche-o ou rode `docker compose down` antes |
| `docker` não é reconhecido (Windows) | o Docker Desktop não está aberto |
| o gateway não acha o líder | `HOST_IP` ou `SEEDS` com IP errado, ou o firewall do computador bloqueando as portas 8081 a 8083 (no Windows, libere-as para redes privadas) |
| o painel mostra o gateway "sem resposta" | o líder não alcança o IP do gateway; computador e gateway precisam estar no mesmo roteador |
| sobraram estações `sim-1`, `sim-2`... | dados do simulador: `python3 testes/simular.py --limpar` |
| mudei o `index.html` e nada mudou | o painel vai dentro da imagem: `docker compose up --build -d` |

## Rodar sem Docker

Para desenvolver o painel sem Docker (precisa de PHP 8.3 com a extensão `curl`), veja a seção "Antes de começar" do [`API-E-FRONT.md`](API-E-FRONT.md). A entrega e a demonstração usam Docker.
