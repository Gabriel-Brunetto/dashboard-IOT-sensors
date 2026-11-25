# Testes do cluster

`verificar.py` testa o cluster de 3 nós **que já está rodando**. Só usa a biblioteca padrão do Python 3 e precisa de `docker` ou `podman`.

```bash
cp .env.example .env                  # edite HOST_IP com o IP deste computador na rede local
docker compose up --build -d          # sobe os 3 nós e serve o painel nas portas 8081, 8082 e 8083
```

Abra <http://localhost:8081> para ver o painel. Em outro terminal, inicie o simulador substituindo `SEU_IP_LOCAL` pelo endereço configurado em `HOST_IP`:

```bash
python3 testes/simular.py
```

O simulador usa o `HOST_IP` do `.env` para que os containers respondam ao ping do gateway simulado. Se necessário, informe o endereço explicitamente com `--ip SEU_IP_LOCAL`. Não rode o simulador junto com o gateway D1 real. Para rodar os testes automatizados, pare o simulador com `Ctrl+C` antes de executar:

```bash
python3 testes/verificar.py           # roda os testes (não precisa de hardware)
python3 testes/verificar.py --hardware   # inclui o que depende do gateway D1 e de um sensor reais ligados
```

Os testes terminam com código 0 se tudo passou e 1 se algo falhou. `--manter` não remove a estação de teste no final; a variável `RUNTIME=docker` ou `RUNTIME=podman` força qual dos dois usar.

Para testar estações que ficam inativas, inicie o simulador com `--instavel`. Para parar, use `Ctrl+C`; para remover as leituras simuladas e reiniciar os nós, rode `python3 testes/simular.py --limpar`. O simulador aceita `--estacoes N`, `--intervalo S`, `--historico M`, `--ciclos N` e `--host H`; `python3 testes/simular.py --help` lista todas as opções.

Para demonstrar uma eleição, deixe o cluster e o simulador rodando e execute `docker compose stop no3`; depois, `docker compose start no3`. O painel mostra o aviso de eleição enquanto o nó 2 assume e o nó 3 retorna.

O HTML-fonte do painel fica em `index.html` na raiz; o Dockerfile o copia para a pasta pública de cada nó. Após alterá-lo, atualize os containers com `docker compose up --build -d`. Para desligar o cluster, use `docker compose down`. Os dados permanecem nos volumes; `docker compose down -v` também os apaga.

## O que é verificado
| Grupo | Verificações |
|---|---|
| Eleição | os 3 nós concordam no líder (o de maior id), `registry.php` e `leader.php` corretos |
| Ingest e replicação | seguidor responde 421 apontando o líder, formato inválido gera 400, reenvio não duplica, leituras inválidas são descartadas, os 3 nós guardam as mesmas leituras com o mesmo relógio de Lamport |
| API | todos os campos de `status.php`, ordem de `latest`, `history.php` (ordem, `limit`, `from`, CORS, erros 400 e 404) |
| Falha do líder | parar o container leva menos de 5 s, o nó 2 assume, o novo líder aceita e replica leituras |
| Retorno | o nó 3 volta, retoma a liderança (Bully) e ressincroniza o que perdeu |
| Reinício | depois de reiniciar todos os nós, o líder é o mesmo e os dados continuam (ficam no volume) |
| Logs | nenhum aviso ou erro de PHP nos 3 containers |

## Cuidados
- O teste **para, religa e reinicia nós** em execução.
- Ele cria a estação `zz-teste` e, no final, remove seus arquivos dos 3 nós e os reinicia (é o que limpa o estado em memória). Os dados reais não são tocados.
- Por usar `docker compose up --build`, o teste "parar o container leva menos de 5 s" só passa com o `Dockerfile` atual (sem o `trap`, o container ignora o SIGTERM e o stop leva 10 s).
