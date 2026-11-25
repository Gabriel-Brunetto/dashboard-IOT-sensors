<?php
// o gateway entrega um lote de leituras ao lider
// corpo {gw, boot, ip, esps: {id: idade_ms_do_ultimo_sinal}, readings: [{esp, seq, temp, umid, age em ms}]}
// so o lider aceita, os demais respondem 421 apontando quem e o lider
require __DIR__ . '/../lib.php';
require __DIR__ . '/../storage.php';

$st = state_read();
if ($st['leader'] !== NODE_ID) {
	$l = $st['leader'];
	json_out(['error' => 'not_leader', 'leader' => $l, 'url' => $l ? (peers_publicos()[$l] ?? null) : null], 421);
}

$in = input();
if (!id_valido($in['gw'] ?? null) || !is_array($in['readings'] ?? null)) json_out(['error' => 'formato'], 400);
$boot = preg_replace('/[^a-z0-9]/i', '', (string)($in['boot'] ?? '0'));
$agora = microtime(true);

$novos = [];
$dup = 0;
$invalidos = 0;
foreach ($in['readings'] as $r) {
	if (!id_valido($r['esp'] ?? null) || !is_numeric($r['temp'] ?? null) || !is_numeric($r['umid'] ?? null) || !isset($r['seq'])) {
		$invalidos++;
		continue;
	}
	$l = 0;
	state_update(function ($s) use (&$l) {
		$l = l_tick($s);
		return $s;
	});

	// o gateway nao tem relogio, entao manda a idade da leitura e o horario real e calculado aqui
	$rec = [
		'k' => "{$in['gw']}|$boot|" . (int)$r['seq'], // gateway, boot e seq: um reenvio da mesma leitura gera a mesma chave
		'esp' => $r['esp'],
		't' => round($agora - ((float)($r['age'] ?? 0)) / 1000, 3),
		'temp' => (float)$r['temp'],
		'umid' => (float)$r['umid'],
		'l' => $l
	];
	if (store_save($rec)) $novos[] = $rec; else $dup++;
}

// um lote, mesmo vazio, prova que o gateway esta vivo e atualiza o ultimo sinal de cada sensor
$gw = null;
$st = state_update(function ($s) use ($in, $agora, &$gw) {
	$s['gw']['id'] = $in['gw'];
	$s['gw']['ip'] = $in['ip'] ?? $s['gw']['ip'];
	$s['gw']['ok'] = true;
	$s['gw']['last_batch'] = time();
	foreach (($in['esps'] ?? []) as $id => $idade)
		if (id_valido($id)) $s['gw']['esps'][$id] = (int)round($agora - $idade / 1000);
	$gw = $s['gw'];
	return $s;
});

// replica na hora nos nos vivos, e quem estava fora recupera depois por sync.php
$replicados = 1;
if ($novos) {
	foreach (outros_nos() as $id => $addr) {
		if (!peer_vivo($st, $id)) continue;
		[$c] = http('POST', "http://$addr/replicate.php", ['from' => NODE_ID, 'lamport' => $st['lamport'], 'readings' => $novos, 'gw' => $gw], 1.0);
		if ($c === 200) $replicados++;
	}
}
json_out([
	'ok' => true,
	'accepted' => count($novos),
	'dup' => $dup,
	'invalid' => $invalidos,
	'replicas' => $replicados,
	'leader' => NODE_ID,
	'lamport' => $st['lamport']
]);
