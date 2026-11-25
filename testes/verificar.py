# verifica o cluster de 3 nos que esta rodando, da eleicao a falha do lider
# uso, na raiz do projeto, com o cluster de pe:  python3 testes/verificar.py [--hardware] [--manter]
#   --hardware  inclui as verificacoes que precisam do gateway d1 e de um sensor reais ligados
#   --manter    nao remove a estacao de teste zz-teste no final
# so usa a biblioteca padrao do python, e precisa de docker ou podman com os containers no1, no2 e no3 rodando
# o teste para, religa e reinicia nos, entao nao deve ser rodado durante uma demonstracao
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ESTACAO_TESTE = "zz-teste"
runtime = None # docker ou podman, escolhido em descobrir_runtime
resultados = []


def porta(n):
	return f"http://127.0.0.1:808{n}"


# o ip do computador que o gateway usa para falar com os nos, lido do .env como o docker compose faz
def host_ip():
	try:
		for linha in open(os.path.join(RAIZ, ".env")):
			if linha.startswith("HOST_IP="): return linha.strip().split("=", 1)[1]
	except OSError:
		pass
	return os.environ.get("HOST_IP")


def nomes_containers(rt):
	r = subprocess.run([rt, "ps", "-a", "--format", "{{.Names}}"], capture_output=True, text=True)
	return r.stdout.split() if r.returncode == 0 else []


# docker ou podman, o primeiro que enxerga os containers dos nos (RUNTIME no ambiente forca um dos dois)
def descobrir_runtime():
	if os.environ.get("RUNTIME"): return os.environ["RUNTIME"]
	for rt in ("docker", "podman"):
		if shutil.which(rt) and any(re.search(r"(^|[-_])no1([-_]\d+)?$", n) for n in nomes_containers(rt)): return rt
	sys.exit("nao encontrei os containers no1, no2 e no3 (docker compose up --build), ou defina RUNTIME=docker ou RUNTIME=podman")


# o docker compose chama os containers de projeto-no1-1, entao o nome e procurado pelo final
def container(n):
	for nome in nomes_containers(runtime):
		if re.search(rf"(^|[-_])no{n}([-_]\d+)?$", nome): return nome
	sys.exit(f"container do no {n} nao encontrado")


def cmd(*args):
	return subprocess.run([runtime, *args], capture_output=True, text=True)


def linhas(n):
	r = cmd("exec", container(n), "sh", "-c", "cat /data/leituras/*.jsonl 2>/dev/null | wc -l")
	return int(r.stdout.strip() or 0)


# devolve (codigo, json, cabecalhos), com codigo 0 quando nao ha conexao
def http(url, corpo=None, timeout=4):
	try:
		req = urllib.request.Request(url, data=json.dumps(corpo).encode() if corpo is not None else None)
		with urllib.request.urlopen(req, timeout=timeout) as r:
			return r.status, json.loads(r.read() or b"null"), dict(r.headers)
	except urllib.error.HTTPError as e:
		try:
			dados = json.loads(e.read())
		except ValueError:
			dados = None
		return e.code, dados, dict(e.headers)
	except (urllib.error.URLError, OSError, ValueError):
		return 0, None, {}


def lider(n):
	return (http(porta(n) + "/leader.php")[1] or {}).get("leader")


def todos_com_lider(esperado):
	return lider(1) == lider(2) == lider(3) == esperado


def status(n=1):
	return http(porta(n) + "/status.php")[1] or {}


def historico(n, query):
	return http(porta(n) + "/history.php?" + query)


def ingest(n, corpo):
	return http(porta(n) + "/ingest.php", corpo)


# repete a condicao ate ela ser verdadeira, e devolve os segundos que levou, ou None se o tempo acabou
def esperar(condicao, limite, passo=1):
	t0 = time.time()
	while time.time() - t0 < limite:
		try:
			if condicao(): return round(time.time() - t0, 1)
		except (KeyError, TypeError, ValueError):
			pass
		time.sleep(passo)
	return None


def tempo(t, falha):
	return f"{t}s" if t is not None else falha


def checar(nome, ok, detalhe=""):
	resultados.append(ok)
	print(("OK     " if ok else "FALHOU ") + nome + (f"  [{detalhe}]" if detalhe else ""), flush=True)


def iguais():
	return linhas(1) == linhas(2) == linhas(3)


# um lote do gateway com leituras da estacao de teste, no formato que o ingest.php documenta no PROTOCOLO.md
def lote(gw, ip, boot, leituras):
	return {"gw": gw, "boot": boot, "ip": ip, "esps": {ESTACAO_TESTE: 100},
			"readings": [{"esp": ESTACAO_TESTE, "seq": s, "temp": t, "umid": u, "age": a} for s, t, u, a in leituras]}


def teste_eleicao():
	print("== eleicao")
	t = esperar(lambda: todos_com_lider(3), 45)
	checar("os 3 nos concordam que o lider e o no 3", t is not None, tempo(t, "sem consenso em 45 s"))
	reg = http(porta(1) + "/registry.php")[1] or {}
	checar("registry.php lista 3 nos vivos", len(reg.get("nodes", [])) == 3 and all(n["alive"] for n in reg["nodes"]))
	url = (http(porta(2) + "/leader.php")[1] or {}).get("url") or ""
	esperada = f"{host_ip()}:8083" if host_ip() else ":8083"
	checar("leader.php devolve o endereco publico do lider", url.endswith(esperada) and url.endswith(":8083"), url)


def teste_hardware():
	print("== gateway e sensor reais")
	reais = lambda: [e for e in status(3).get("esps", []) if e["alive"] and e["id"] != ESTACAO_TESTE]
	t = esperar(lambda: status(3)["gateway"]["alive"] and reais(), 90, 2)
	checar("o gateway esta vivo e ha ao menos um sensor real ativo", t is not None, tempo(t, "nada apareceu em 90 s"))


def teste_ingest(gw, ip):
	print("== ingest e replicacao")
	leituras = [(1, 21.5, 45, 3000), (2, 22.0, 46, 2000), (3, 22.5, 47, 1000)]
	corpo = lote(gw, ip, "verificar1", leituras)
	codigo, resp, _ = ingest(1, corpo)
	checar("um seguidor recusa o ingest com 421 e aponta o lider", codigo == 421 and resp.get("leader") == 3, str(resp))
	codigo, resp, _ = ingest(3, {"gw": gw, "readings": "x"})
	checar("ingest com formato invalido recebe 400", codigo == 400)
	codigo, resp, _ = ingest(3, corpo)
	checar("o lider aceita 3 leituras e replica nos 3 nos", codigo == 200 and resp.get("accepted") == 3 and resp.get("replicas") == 3, str(resp))
	codigo, resp, _ = ingest(3, corpo)
	checar("reenviar o mesmo lote nao duplica nada", codigo == 200 and resp.get("accepted") == 0 and resp.get("dup") == 3, str(resp))
	# um id com espaco e uma temperatura que nao e numero
	ruins = [{"esp": "ID RUIM", "seq": 5, "temp": 1, "umid": 1}, {"esp": ESTACAO_TESTE, "seq": 6, "temp": "abc", "umid": 1}]
	codigo, resp, _ = ingest(3, {**corpo, "readings": ruins})
	checar("leituras invalidas sao contadas e descartadas", codigo == 200 and resp.get("invalid") == 2 and resp.get("accepted") == 0, str(resp))

	lamports = [[r["l"] for r in (historico(n, f"esp={ESTACAO_TESTE}")[1] or {}).get("readings", [])] for n in (1, 2, 3)]
	checar("os 3 nos guardam as mesmas leituras com o mesmo lamport", len(lamports[0]) == 3 and lamports[0] == lamports[1] == lamports[2], str(lamports))
	t = esperar(iguais, 20)
	checar("os 3 nos tem o mesmo numero de leituras", t is not None, f"{linhas(1)}/{linhas(2)}/{linhas(3)}")


def teste_api():
	print("== api")
	st = status(2)
	campos = ["node", "leader", "lamport", "nodes", "gateway", "esps", "events"]
	checar("status.php tem todos os campos do API.md", all(k in st for k in campos) and all(k in st["gateway"] for k in ["id", "ip", "alive", "last_ping_age", "last_batch_age"]))
	teste = next((e for e in st.get("esps", []) if e["id"] == ESTACAO_TESTE), {})
	ts = [x["t"] for x in teste.get("latest", [])]
	checar("status.php lista a estacao com as ultimas leituras, da mais nova para a mais antiga", len(ts) == 3 and ts == sorted(ts, reverse=True), str(len(ts)))

	codigo, h, cabecalhos = historico(2, f"esp={ESTACAO_TESTE}")
	ts = [r["t"] for r in (h or {}).get("readings", [])]
	checar("history.php devolve as leituras em ordem crescente, com cors", codigo == 200 and len(ts) == 3 and ts == sorted(ts) and cabecalhos.get("Access-Control-Allow-Origin") == "*")
	ultimas = (historico(2, f"esp={ESTACAO_TESTE}&limit=2")[1] or {}).get("readings", [])
	checar("history.php com limit devolve as mais recentes", [r["t"] for r in ultimas] == ts[1:], str(len(ultimas)))
	desde = (historico(2, f"esp={ESTACAO_TESTE}&from={ts[1]}")[1] or {}).get("count") if len(ts) == 3 else None
	checar("history.php com from filtra o intervalo", desde == 2, str(desde))
	checar("history.php limita o limit a 2000", (historico(2, f"esp={ESTACAO_TESTE}&limit=99999")[1] or {}).get("count", 99999) <= 2000)
	checar("history.php sem esp recebe 400", historico(2, "")[0] == 400)
	checar("history.php com esp invalido recebe 400", historico(2, "esp=Id_Ruim")[0] == 400)
	checar("history.php com estacao desconhecida recebe 404", historico(2, "esp=nao-existe")[0] == 404)
	checar("history.php com from nao numerico recebe 400", historico(2, f"esp={ESTACAO_TESTE}&from=abc")[0] == 400)


def teste_falha_do_lider(gw, ip, hardware):
	print("== falha do lider")
	t0 = time.time()
	cmd("stop", container(3))
	segundos = round(time.time() - t0, 1)
	checar("parar o container leva menos de 5 s (o container obedece ao SIGTERM)", segundos < 5, f"{segundos}s")
	t = esperar(lambda: lider(1) == lider(2) == 2, 30)
	checar("o no 2 assume a lideranca e os sobreviventes concordam", t is not None, tempo(t, "sem novo lider em 30 s"))
	codigo, resp, _ = ingest(2, lote(gw, ip, "verificar2", [(10, 23.0, 48, 50)]))
	checar("o novo lider aceita leituras e replica no no vivo", codigo == 200 and resp.get("accepted") == 1 and resp.get("replicas") == 2, str(resp))
	if hardware:
		antes = linhas(2)
		t = esperar(lambda: linhas(2) > antes, 40, 2)
		checar("o sensor real continua entregando ao novo lider (o gateway redescobriu)", t is not None, tempo(t, "sem novas leituras em 40 s"))


def teste_retorno():
	print("== retorno do no 3")
	cmd("start", container(3))
	t = esperar(lambda: todos_com_lider(3), 45)
	checar("o no 3 volta e retoma a lideranca (bully)", t is not None, tempo(t, "sem consenso em 45 s"))
	t = esperar(iguais, 30)
	checar("o no 3 ressincroniza o que perdeu e as replicas ficam iguais", t is not None, f"{linhas(1)}/{linhas(2)}/{linhas(3)}")
	quantas = (historico(3, f"esp={ESTACAO_TESTE}")[1] or {}).get("count")
	checar("o no 3 recuperou a leitura feita enquanto estava fora", quantas == 4, str(quantas))


# o evento "tem id menor que o meu" so e verdadeiro se o lider citado tem id menor que o do no que o registrou
def evento_incoerente():
	for n in (1, 2, 3):
		for e in status(n).get("events", []):
			m = re.search(r"lider (\d+) tem id menor que o meu", e["msg"])
			if m and int(m.group(1)) >= n: return f"no {n}: {e['msg']}"
	return None


def teste_reinicio():
	print("== reinicio de todos os nos")
	antes = linhas(1)
	cmd("restart", "-t", "2", container(1), container(2), container(3))
	t = esperar(lambda: todos_com_lider(3), 60)
	checar("depois de reiniciar tudo o no 3 e o lider de novo", t is not None, tempo(t, "sem consenso em 60 s"))
	erro = evento_incoerente()
	checar("os eventos de eleicao dos nos citam o motivo certo", erro is None, erro or "")
	checar("os dados sobreviveram ao reinicio (ficam no volume)", min(linhas(1), linhas(2), linhas(3)) >= antes, f"antes={antes} depois={linhas(1)}/{linhas(2)}/{linhas(3)}")


def teste_logs():
	print("== logs")
	erros = []
	for n in (1, 2, 3):
		r = cmd("logs", container(n))
		for linha in (r.stdout + r.stderr).splitlines():
			if any(p in linha for p in ["Warning", "Fatal", "Notice", "Deprecated", "Parse error", "Stack trace", "Uncaught"]): erros.append(f"no {n}: {linha[:120]}")
	checar("nenhum aviso ou erro de php nos logs dos 3 nos", not erros, erros[0] if erros else "")


# tira a estacao de teste dos 3 nos e reinicia, que e o que zera o estado em memoria deles
def limpar():
	print("== limpeza")
	for n in (1, 2, 3):
		cmd("exec", container(n), "rm", "-f", f"/data/leituras/{ESTACAO_TESTE}.jsonl")
	cmd("restart", "-t", "2", container(1), container(2), container(3))
	esperar(lambda: todos_com_lider(3), 60)
	print(f"estacao {ESTACAO_TESTE} removida dos 3 nos")


def main():
	global runtime
	p = argparse.ArgumentParser(description="verifica o cluster de 3 nos")
	p.add_argument("--hardware", action="store_true", help="inclui as verificacoes que precisam do gateway e de um sensor reais")
	p.add_argument("--manter", action="store_true", help="nao remove a estacao de teste no final")
	args = p.parse_args()

	runtime = descobrir_runtime()
	print(f"usando {runtime}, nos: {container(1)}, {container(2)}, {container(3)}")
	if esperar(lambda: all(lider(n) is not None for n in (1, 2, 3)), 30) is None:
		sys.exit("os nos nao respondem em localhost:8081 a 8083")

	# com um gateway real o lote de teste usa o ip e o id dele, para nao atrapalhar o estado que o lider guarda
	gw = status(1).get("gateway", {})
	gw_id, gw_ip = gw.get("id") or "gw-teste", gw.get("ip") or "10.255.255.1"
	try:
		teste_eleicao()
		if args.hardware: teste_hardware()
		teste_ingest(gw_id, gw_ip)
		teste_api()
		teste_falha_do_lider(gw_id, gw_ip, args.hardware)
		teste_retorno()
		teste_reinicio()
		teste_logs()
	finally:
		if not args.manter: limpar()
	print(f"\nresultado: {sum(resultados)} de {len(resultados)} verificacoes passaram")
	sys.exit(0 if all(resultados) else 1)


if __name__ == "__main__":
	main()
