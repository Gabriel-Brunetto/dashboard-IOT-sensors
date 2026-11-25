<?php
// bully: um no menor pergunta se ha algum maior vivo, quem recebe responde ok e o daemon passa a conduzir a eleicao
require __DIR__ . '/../lib.php';
$in = input();
$from = (int)($in['from'] ?? 0);
state_update(function ($st) use ($in, $from) {
	l_merge($st, $in['lamport'] ?? 0);
	$st['peers'][$from]['seen'] = time();
	if ($from < NODE_ID) $st['elect_req'] = true;
	return $st;
});
json_out(['ok' => true, 'id' => NODE_ID]);
