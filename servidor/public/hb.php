<?php
// recebe o heartbeat de outro no, com corpo {from, lamport, leader, gw?}, e responde {id, leader, lamport}
require __DIR__ . '/../lib.php';
$in = input();
$from = (int)($in['from'] ?? 0);
if (!$from) json_out(['error' => 'from'], 400);

$st = state_update(function ($st) use ($in, $from) {
	l_merge($st, $in['lamport'] ?? 0);
	$st['peers'][$from]['seen'] = time();

	// quem se diz lider so e aceito se o id for maior ou igual ao do lider atual (bully), ou se o lider atual estiver caido
	if (($in['leader'] ?? null) === $from) {
		$atual = $st['leader'];
		if ($atual === null || $from >= $atual || !peer_vivo($st, $atual)) {
			if ($atual !== $from) {
				st_event($st, "lider agora e o no $from (via heartbeat)");
				$st['needs_sync'] = true;
			}
			$st['leader'] = $from;
		}
	}

	// o lider manda junto o estado do gateway, para os seguidores ja o terem se algum virar lider
	if ($st['leader'] === $from && isset($in['gw'])) $st['gw'] = $in['gw'];
	return $st;
});
json_out(['id' => NODE_ID, 'leader' => $st['leader'], 'lamport' => $st['lamport']]);
