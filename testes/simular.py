# simula um gateway com varias estacoes falsas, para desenvolver a pagina sem o hardware
# uso, na raiz do projeto, com o cluster de pe:  python3 testes/simular.py [opcoes]
#   --estacoes N    quantas estacoes simular, com ids sim-1, sim-2 e assim por diante (padrao 3)
#   --intervalo S   segundos entre as leituras (padrao 5, como o gateway real)
#   --historico M   minutos de historico enviados ao iniciar, para os graficos ja terem dados (padrao 10, 0 desliga)
#   --instavel      cada estacao some por um tempo de vez em quando, para testar o estado inativa
#   --ciclos N      para depois de N ciclos (padrao: roda ate o Ctrl+C)
#   --host H        maquina onde estao os nos (padrao 127.0.0.1)
#   --ip IP         ip deste computador, que o lider usa para dar ping aqui (padrao: HOST_IP do .env)
#   --limpar        remove as estacoes simuladas dos nos e reinicia os nos (precisa de docker ou podman)
# o simulador se comporta como o d1: acha o lider, envia lotes ao /ingest.php e responde ao /ping do lider
# evite rodar junto com o gateway d1 real ligado, porque os dois disputam o estado do gateway que o lider guarda
# so usa a biblioteca padrao do python
import argparse
import math
import os
import random
import socket
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.dont_write_bytecode = True # sem isso o import abaixo cria testes/__pycache__, que o .gitignore nao cobre
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import verificar # reaproveita o .env, a deteccao de docker ou podman e o cliente http

GW_ID = "gw-simulador"
PORTAS_DOS_NOS = (8081, 8082, 8083)
pings = 0


# o lider pergunta GET /ping para saber se o gateway esta vivo, e espera receber pong
class Ping(BaseHTTPRequestHandler):
	def do_GET(self):
		global pings
		ok = self.path == "/ping"
		corpo = b"pong" if ok else b"nao"
		if ok: pings += 1
		self.send_response(200 if ok else 404)
		self.send_header("Content-Length", str(len(corpo)))
		self.end_headers()
		self.wfile.write(corpo)

	def log_message(self, *args):
		pass


# cada estacao tem uma temperatura base propria e oscila devagar, a umidade acompanha ao contrario
class Estacao:
	def __init__(self, i):
		self.id = f"sim-{i}"
		self.base = 18 + (i * 3) % 14
		self.fase = random.uniform(0, 6.28)
		self.fora_ate = 0 # com --instavel, enquanto o relogio nao passar disso a estacao nao envia nada

	# a medida depende so do instante t, assim o historico e as leituras novas formam uma curva continua
	def medir(self, t):
		temp = self.base + 2.5 * math.sin(t / 900 + self.fase) + 0.8 * math.sin(t / 137 + 2 * self.fase) + random.gauss(0, 0.08)
		umid = 55 - 2.0 * (temp - self.base) + 6 * math.sin(t / 1300 + self.fase) + random.gauss(0, 0.3)
		return round(temp, 1), round(max(20, min(95, umid)), 1)


# o ip desta maquina na rede, sem enviar nada: so pergunta ao sistema por qual interface sairia um pacote
def ip_local():
	s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
	try:
		s.connect(("8.8.8.8", 80))
		return s.getsockname()[0]
	except OSError:
		return "127.0.0.1"
	finally:
		s.close()


# pergunta a qualquer no quem e o lider, como o d1 faz, e devolve o endereco publico dele
def descobrir_lider(nos):
	for no in nos:
		codigo, resp, _ = verificar.http(no + "/leader.php")
		if codigo == 200 and resp.get("url"): return "http://" + resp["url"]
	return None


def enviar(lider, ip, boot, leituras, esps):
	corpo = {"gw": GW_ID, "boot": boot, "ip": ip, "esps": esps, "readings": leituras}
	return verificar.http(lider + "/ingest.php", corpo, timeout=8)


# leituras do passado, em lotes, com a idade de cada uma, que e como o gateway real informa o horario
def enviar_historico(estacoes, args, lider, ip, boot, proximo_seq):
	por_estacao = int(args.historico * 60 / args.intervalo)
	agora = time.time()
	leituras = []
	for k in range(por_estacao):
		idade = (por_estacao - k) * args.intervalo
		for e in estacoes:
			temp, umid = e.medir(agora - idade)
			leituras.append({"esp": e.id, "seq": proximo_seq(), "temp": temp, "umid": umid, "age": int(idade * 1000)})
	for i in range(0, len(leituras), 100):
		codigo, resp, _ = enviar(lider, ip, boot, leituras[i:i + 100], {})
		if codigo != 200:
			print(f"historico: o lider respondeu {codigo}, seguindo sem o resto")
			return
	print(f"historico: {len(leituras)} leituras enviadas ({por_estacao} por estacao, {args.historico:g} min)")


# tira as estacoes simuladas dos 3 nos e reinicia, que e o que zera o estado em memoria deles
def limpar():
	verificar.runtime = verificar.descobrir_runtime()
	for n in (1, 2, 3):
		verificar.cmd("exec", verificar.container(n), "sh", "-c", "rm -f /data/leituras/sim-*.jsonl")
	verificar.cmd("restart", "-t", "2", *[verificar.container(n) for n in (1, 2, 3)])
	verificar.esperar(lambda: verificar.todos_com_lider(3), 60)
	print("estacoes simuladas removidas dos 3 nos")


def main():
	p = argparse.ArgumentParser(description="simula um gateway com estacoes falsas")
	p.add_argument("--estacoes", type=int, default=3)
	p.add_argument("--intervalo", type=float, default=5)
	p.add_argument("--historico", type=float, default=10)
	p.add_argument("--instavel", action="store_true")
	p.add_argument("--ciclos", type=int, default=0)
	p.add_argument("--host", default="127.0.0.1")
	p.add_argument("--ip")
	p.add_argument("--porta-ping", type=int, default=8099)
	p.add_argument("--limpar", action="store_true")
	args = p.parse_args()
	if args.limpar: return limpar()

	ip = args.ip or verificar.host_ip() or ip_local()
	if ip.startswith("127."): print("aviso: com o ip 127.x o lider, dentro do container, nao alcanca este computador (use --ip)")
	nos = [f"http://{args.host}:{porta}" for porta in PORTAS_DOS_NOS]
	estacoes = [Estacao(i) for i in range(1, args.estacoes + 1)]
	boot = f"{random.getrandbits(32):08x}" # muda a cada execucao, assim a chave de deduplicacao nao colide com a de antes
	seq = [0]

	def proximo_seq():
		seq[0] += 1
		return seq[0]

	# o servidor do ping roda em segundo plano, e o ip informado ao lider leva a porta junto
	servidor = HTTPServer(("0.0.0.0", args.porta_ping), Ping)
	threading.Thread(target=servidor.serve_forever, daemon=True).start()
	ip_gateway = f"{ip}:{args.porta_ping}"
	print(f"gateway simulado {GW_ID} em {ip_gateway}, estacoes: {', '.join(e.id for e in estacoes)}")

	lider = None
	ciclo = 0
	try:
		while True:
			inicio = time.time()
			if lider is None: lider = descobrir_lider(nos)
			if lider is None:
				print("nenhum no respondeu, tentando de novo")
			else:
				if ciclo == 0 and args.historico > 0: enviar_historico(estacoes, args, lider, ip_gateway, boot, proximo_seq)
				leituras, esps = [], {}
				for e in estacoes:
					if args.instavel and inicio < e.fora_ate: continue
					if args.instavel and random.random() < 0.05:
						e.fora_ate = inicio + random.uniform(25, 60)
						print(f"{e.id} saiu do ar por um tempo")
						continue
					temp, umid = e.medir(inicio)
					idade = random.randint(50, 400)
					leituras.append({"esp": e.id, "seq": proximo_seq(), "temp": temp, "umid": umid, "age": idade})
					esps[e.id] = idade
				codigo, resp, _ = enviar(lider, ip_gateway, boot, leituras, esps)
				if codigo == 200:
					print(f"ciclo {ciclo + 1}: {resp['accepted']} leituras aceitas pelo lider (replicas {resp['replicas']}), pings recebidos {pings}")
				elif codigo == 421:
					lider = ("http://" + resp["url"]) if resp and resp.get("url") else None
					print("421: o no nao e mais o lider, " + (f"novo lider {lider}" if lider else "eleicao em andamento"))
				else:
					lider = None
					print(f"falha ao enviar ({codigo}), procurando o lider de novo")
			ciclo += 1
			if args.ciclos and ciclo >= args.ciclos: break
			time.sleep(max(0, args.intervalo - (time.time() - inicio)))
	except KeyboardInterrupt:
		print()
	servidor.shutdown()
	print(f"fim, o lider pingou o simulador {pings} vezes. para remover os dados simulados dos nos: python3 testes/simular.py --limpar")


if __name__ == "__main__":
	main()
