<?php
// o lider entrega aos seguidores leituras que ja vem com o carimbo de lamport
require __DIR__ . '/../lib.php';
require __DIR__ . '/../storage.php';
$in = input();
$n = 0;
foreach (($in['readings'] ?? []) as $r)
	if (store_save($r)) $n++;
state_update(function ($st) use ($in) {
	l_merge($st, $in['lamport'] ?? 0);
	if (isset($in['gw'])) $st['gw'] = $in['gw'];
	return $st;
});
json_out(['ok' => true, 'stored' => $n]);
