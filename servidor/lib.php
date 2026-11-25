<?php
// funcoes comuns a todo no do cluster: configuracao lida do ambiente, estado local compartilhado, relogio de lamport e cliente http
date_default_timezone_set('America/Sao_Paulo');

define('NODE_ID', (int)(getenv('NODE_ID') ?: 1));
define('DATA_DIR', rtrim(getenv('DATA_DIR') ?: __DIR__ . '/dados', '/'));
define('PEER_TTL', 3); // segundos sem heartbeat ate o no ser considerado caido
define('ESP_TTL', 15); // segundos sem leitura ate a estacao ser considerada inativa
define('GW_PING_INTERVAL', 5); // segundos entre os pings do lider no gateway
define('STATE_FILE', DATA_DIR . '/state.json');

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0777, true);

// transforma "1=host:porta,2=host:porta" em [1 => "host:porta", 2 => "host:porta"]
function parse_map($s) {
	$out = [];
	foreach (array_filter(explode(',', (string)$s)) as $par) {
		[$k, $v] = array_pad(explode('=', trim($par), 2), 2, '');
		if ($v !== '') $out[(int)$k] = $v;
	}
	ksort($out);
	return $out;
}

// enderecos dos nos dentro da rede dos containers, o proprio no incluido
function peers_internos() {
	$m = parse_map(getenv('PEERS'));
	return $m ?: [NODE_ID => '127.0.0.1:80'];
}

// enderecos que o gateway enxerga (ip do computador e porta publicada), os internos servem de reserva
function peers_publicos() {
	return parse_map(getenv('PUBLIC')) + peers_internos();
}

// os demais nos, sem o proprio
function outros_nos() {
	$p = peers_internos();
	unset($p[NODE_ID]);
	return $p;
}

function state_default() {
	return [
		'lamport' => 0,
		'leader' => null, // id do lider que este no conhece, null durante uma eleicao
		'peers' => [], // id do no => ['seen' => instante do ultimo heartbeat recebido]
		'gw' => ['id' => null, 'ip' => null, 'ok' => false, 'last_ping' => 0, 'last_batch' => 0, 'esps' => []],
		'needs_sync' => true, // true quando faltam leituras que um vizinho pode ter
		'electing_until' => 0, // enquanto o relogio nao passar disso, aguarda o coordinator em vez de eleger de novo
		'elect_req' => false, // um no menor pediu eleicao, o daemon atende no proximo ciclo
		'events' => [],
	];
}

// le, altera e grava o estado com lock exclusivo, porque os workers web e o daemon escrevem ao mesmo tempo
// $fn recebe o estado e devolve o novo, ou nada para manter o mesmo
function state_update(callable $fn) {
	$fp = fopen(STATE_FILE, 'c+');
	flock($fp, LOCK_EX);
	$st = json_decode(stream_get_contents($fp), true) ?: state_default();
	$st = $fn($st) ?? $st;
	ftruncate($fp, 0);
	rewind($fp);
	fwrite($fp, json_encode($st));
	fflush($fp);
	flock($fp, LOCK_UN);
	fclose($fp);
	return $st;
}

function state_read() { return state_update(fn($s) => $s); }

// guarda um evento para a pagina (so os 15 ultimos) e escreve no log do container
function st_event(array &$st, $msg) {
	$st['events'][] = ['t' => time(), 'msg' => $msg];
	$st['events'] = array_slice($st['events'], -15);
	error_log("[no " . NODE_ID . "] $msg");
}

// relogio de lamport: cada evento soma 1, e ao receber uma mensagem o relogio vai para o maior valor visto mais 1
function l_tick(array &$st) { return ++$st['lamport']; }
function l_merge(array &$st, $recv) { $st['lamport'] = max($st['lamport'], (int)$recv) + 1; }

// um no esta vivo se e o proprio ou se mandou heartbeat ha pouco
function peer_vivo(array $st, $id) {
	return $id === NODE_ID || (time() - ($st['peers'][$id]['seen'] ?? 0)) <= PEER_TTL;
}

// o timeout do curl inclui a resolucao de dns, que sozinha demora segundos para o nome de um container parado
function http_handle($method, $url, $data, $timeout) {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_NOSIGNAL => true,
		CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
		CURLOPT_CONNECTTIMEOUT_MS => (int)($timeout * 1000),
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'], // sem o Expect vazio o curl espera 1 s por um 100-continue
	]);
	if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
	return $ch;
}

function http_result($ch) {
	$raw = curl_multi_getcontent($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	return $code ? [$code, json_decode((string)$raw, true), $raw] : [0, null, null];
}

// devolve [codigo, json_decodificado, corpo_cru], com codigo 0 quando nao houve conexao ou o tempo acabou
function http($method, $url, $data = null, $timeout = 0.6) {
	return http_multi([[$method, $url, $data, $timeout]])[0];
}

// varias requisicoes ao mesmo tempo, [[method, url, data, timeout], ...], e os resultados voltam na mesma ordem
function http_multi(array $reqs) {
	$mh = curl_multi_init();
	$hs = [];
	foreach ($reqs as $i => [$m, $u, $d, $t]) {
		$hs[$i] = http_handle($m, $u, $d, $t);
		curl_multi_add_handle($mh, $hs[$i]);
	}
	do {
		curl_multi_exec($mh, $ativos);
		if ($ativos) curl_multi_select($mh, 0.1);
	} while ($ativos);
	$out = [];
	foreach ($hs as $i => $ch) {
		$out[$i] = http_result($ch);
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
	}
	curl_multi_close($mh);
	return $out;
}

// corpo json da requisicao como array, vazio se nao veio nada
function input() { return json_decode(file_get_contents('php://input'), true) ?: []; }

// responde em json (com cors liberado para a pagina) e encerra
function json_out($data, $code = 200) {
	http_response_code($code);
	header('Content-Type: application/json');
	header('Access-Control-Allow-Origin: *');
	echo json_encode($data);
	exit;
}
