# Testes do cluster

`verificar.py` testa o cluster de 3 nós **que já está rodando**. Só usa a biblioteca padrão do Python 3 e precisa de `docker` ou `podman`.

```bash
docker compose up --build -d          # sobe os 3 nós a partir da raiz do projeto (precisa do .env com HOST_IP)
python3 testes/verificar.py           # roda os testes (não precisa de hardware)
python3 testes/verificar.py --hardware   # inclui o que depende do gateway D1 e de um sensor reais ligados
```
Termina com código 0 se tudo passou e 1 se algo falhou. Opções: `--manter` não remove a estação de teste no final; a variável `RUNTIME=docker` ou `RUNTIME=podman` força qual dos dois usar.

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
