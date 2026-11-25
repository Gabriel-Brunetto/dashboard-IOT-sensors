<?php
// api da pagina: lider, nos, gateway, estacoes e as ultimas leituras de cada uma
require __DIR__ . '/../lib.php';
require __DIR__ . '/../storage.php';
$st = state_read();
$gw = $st['gw'];
$agora = time();

$nos = [];
foreach (peers_publicos() as $id => $url)
	$nos[] = ['id' => $id, 'url' => $url, 'alive' => peer_vivo($st, $id), 'leader' => $st['leader'] === $id];

// uma estacao aparece se o gateway ja a viu ou se ja ha leituras dela guardadas
$ids = array_unique(array_merge(array_keys($gw['esps']), store_esps()));
sort($ids);
$esps = [];
foreach ($ids as $id) {
	$visto = $gw['esps'][$id] ?? null;
	$idade = $visto ? $agora - $visto : null;
	$esps[] = [
		'id' => $id,
		'alive' => $gw['ok'] && $idade !== null && $idade <= ESP_TTL, // sem gateway vivo ninguem sabe o estado dos sensores
		'last_seen_age' => $idade,
		'latest' => store_latest($id, 5)
	];
}
json_out([
	'node' => NODE_ID,
	'leader' => $st['leader'],
	'lamport' => $st['lamport'],
	'nodes' => $nos,
	'gateway' => [
		'id' => $gw['id'],
		'ip' => $gw['ip'],
		'alive' => $gw['ok'],
		'last_ping_age' => $gw['last_ping'] ? $agora - $gw['last_ping'] : null,
		'last_batch_age' => $gw['last_batch'] ? $agora - $gw['last_batch'] : null
	],
	'esps' => $esps,
	'events' => array_reverse($st['events'])
]);
