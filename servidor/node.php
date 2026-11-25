<?php
// processo de fundo de um no: heartbeat entre os nos, eleicao bully, ping no gateway (quando e o lider) e ressincronizacao
// roda ao lado do servidor web com php node.php
require __DIR__ . '/lib.php';
require __DIR__ . '/storage.php';

// o estado e zerado ao iniciar, so o lamport e mantido: um no que reinicia nao deve confiar no que sabia
$lamport = file_exists(STATE_FILE) ? (json_decode(file_get_contents(STATE_FILE), true)['lamport'] ?? 0) : 0;
@unlink(STATE_FILE);
state_update(function ($st) use ($lamport) {
	$st = state_default();
	$st['lamport'] = $lamport;
	st_event($st, 'no iniciado');
	return $st;
});

// um heartbeat para cada vizinho, todos ao mesmo tempo, assim um no caido nao atrasa os demais
function heartbeat_round() {
	$ids = [];
	$reqs = [];
	state_update(function ($st) use (&$ids, &$reqs) {
		foreach (outros_nos() as $id => $addr) {
			$p = ['from' => NODE_ID, 'lamport' => l_tick($st), 'leader' => $st['leader']];
			if ($st['leader'] === NODE_ID) $p['gw'] = $st['gw']; // o lider repassa o estado do gateway aos seguidores
			$ids[] = $id;
			$reqs[] = ['POST', "http://$addr/hb.php", $p, 0.6];
		}
		return $st;
	});

	foreach (http_multi($reqs) as $i => [$c, $r]) {
		if ($c !== 200) continue;
		$id = $ids[$i];
		state_update(function ($st) use ($id, $r) {
			$st['peers'][$id]['seen'] = time();
			l_merge($st, $r['lamport'] ?? 0);

			// eu me achava lider e esse vizinho conhece um lider maior, entao cedo
			if ($st['leader'] === NODE_ID && ($r['leader'] ?? 0) > NODE_ID) {
				st_event($st, "cedendo lideranca ao no {$r['leader']}");
				$st['leader'] = $r['leader'];
				$st['needs_sync'] = true;
			}
			return $st;
		});
	}
}

// manda a mesma mensagem a varios nos de uma vez, com um unico tick do lamport
function msg_para(array $addrs, $rota) {
	$l = 0;
	state_update(function ($st) use (&$l) {
		$l = l_tick($st);
		return $st;
	});
	return http_multi(array_map(fn($a) => ['POST', "http://$a/$rota", ['from' => NODE_ID, 'lamport' => $l], 0.8], array_values($addrs)));
}

function broadcast($rota) { msg_para(outros_nos(), $rota); }

// bully: pergunta aos nos de id maior se algum esta vivo, se nenhum responde este no vira o lider
function run_election() {
	$maiores = array_filter(outros_nos(), fn($id) => $id > NODE_ID, ARRAY_FILTER_USE_KEY);
	$ok = false;
	foreach (msg_para($maiores, 'election.php') as [$c])
		if ($c === 200) $ok = true;

	if (!$ok) {
		state_update(function ($s) {
			if ($s['leader'] !== NODE_ID) st_event($s, 'nenhum no maior vivo: me declaro LIDER');
			$s['leader'] = NODE_ID;
			$s['needs_sync'] = false;
			$s['elect_req'] = false;
			return $s;
		});
		broadcast('coordinator.php');
	} else {
		// um no maior esta vivo e vai assumir, se o coordinator nao chegar em 4 s a eleicao e refeita
		state_update(function ($s) {
			st_event($s, 'existe no maior vivo: aguardando coordinator');
			$s['electing_until'] = time() + 4;
			return $s;
		});
	}
}

// puxa de um vizinho as leituras que faltam aqui, e e seguro repetir porque store_save ignora duplicatas
function sync_from($addr) {
	$since = store_max_l();
	[$c, $r] = http('GET', "http://$addr/sync.php?since=$since", null, 3.0);
	if ($c !== 200) return false;

	$n = 0;
	foreach (($r['readings'] ?? []) as $rec)
		if (store_save($rec)) $n++;
	if ($n) state_update(function ($st) use ($n, $addr) {
		st_event($st, "sincronizadas $n leituras de $addr");
		return $st;
	});
	return true;
}

// o lider pergunta ao gateway se esta vivo, o ip vem no ultimo lote que o gateway enviou
function ping_gateway() {
	$st = state_read();
	$ip = $st['gw']['ip'];
	$ok = false;
	if ($ip) {
		[$c, , $raw] = http('GET', "http://$ip/ping", null, 1.5);
		$ok = $c === 200 && trim((string)$raw) === 'pong';
	}
	state_update(function ($s) use ($ok) {
		if ($s['gw']['ok'] !== $ok) st_event($s, 'gateway ' . ($ok ? 'RESPONDENDO' : 'SEM RESPOSTA'));
		$s['gw']['ok'] = $ok;
		$s['gw']['last_ping'] = time();
		return $s;
	});
}

// na partida o no descobre quem esta vivo e recupera dados de um vizinho antes de disputar a lideranca
heartbeat_round();
foreach (outros_nos() as $id => $addr)
	if (peer_vivo(state_read(), $id) && sync_from($addr)) break;

$ultimo_ping = 0;
$ultimo_sync = 0;
while (true) {
	$t0 = microtime(true);
	heartbeat_round();
	$st = state_read();
	$lider = $st['leader'];

	if ($st['elect_req'] && $lider === NODE_ID) {
		// um no menor duvidou de mim, entao reafirmo que sou o lider
		state_update(function ($s) {
			$s['elect_req'] = false;
			return $s;
		});
		broadcast('coordinator.php');
	} elseif ($st['elect_req'] || $lider === null || !peer_vivo($st, $lider) || $lider < NODE_ID) {
		// sem lider, lider caido, lider de id menor que o meu (que o bully manda desafiar) ou um no menor pediu eleicao
		if (time() >= $st['electing_until']) {
			if ($lider !== null && $lider !== NODE_ID) {
				if (!peer_vivo($st, $lider)) $motivo = "nao responde";
				elseif ($lider < NODE_ID) $motivo = "tem id menor que o meu";
				else $motivo = "esta vivo, mas um no menor pediu eleicao";
				state_update(function ($s) use ($lider, $motivo) {
					st_event($s, "lider $lider $motivo: iniciando eleicao");
					return $s;
				});
			}
			state_update(function ($s) {
				$s['elect_req'] = false;
				return $s;
			});
			run_election();
		}
	}

	$st = state_read();
	if ($st['leader'] === NODE_ID && time() - $ultimo_ping >= GW_PING_INTERVAL) {
		ping_gateway();
		$ultimo_ping = time();
	}

	// seguidor: pede leituras ao lider quando o estado manda ou a cada 10 s, para pegar o que a replicacao perdeu
	if ($st['leader'] !== null && $st['leader'] !== NODE_ID && ($st['needs_sync'] || time() - $ultimo_sync >= 10)) {
		if (sync_from(peers_internos()[$st['leader']])) {
			state_update(function ($s) {
				$s['needs_sync'] = false;
				return $s;
			});
			$ultimo_sync = time();
		}
	}

	// cada ciclo dura 1 s, descontado o tempo que o proprio ciclo gastou
	$resto = 1.0 - (microtime(true) - $t0);
	if ($resto > 0) usleep((int)($resto * 1e6));
}
