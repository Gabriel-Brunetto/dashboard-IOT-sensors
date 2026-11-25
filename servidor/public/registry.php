<?php
// registro de servico: os nos conhecidos, se estao vivos e qual e o lider
require __DIR__ . '/../lib.php';
$st = state_read();
$nos = [];
foreach (peers_publicos() as $id => $url)
	$nos[] = ['id' => $id, 'url' => $url, 'alive' => peer_vivo($st, $id), 'leader' => $st['leader'] === $id];
json_out(['node' => NODE_ID, 'leader' => $st['leader'], 'nodes' => $nos]);
