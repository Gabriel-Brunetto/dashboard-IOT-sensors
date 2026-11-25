<?php
// bully: um no anuncia que e o novo lider, e e aceito se o id dele for maior ou igual ao deste no
require __DIR__ . '/../lib.php';
$in = input();
$from = (int)($in['from'] ?? 0);
$st = state_update(function ($st) use ($in, $from) {
	l_merge($st, $in['lamport'] ?? 0);
	$st['peers'][$from]['seen'] = time();
	if ($from >= NODE_ID) {
		if ($st['leader'] !== $from) {
			st_event($st, "lider agora e o no $from (coordinator)");
			$st['needs_sync'] = true;
		}
		$st['leader'] = $from;
		$st['electing_until'] = 0;
	} else {
		$st['elect_req'] = true; // um id menor se dizendo lider, este no e maior e deve assumir
	}
	return $st;
});
json_out(['ok' => $st['leader'] === $from, 'leader' => $st['leader']]);
