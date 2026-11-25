<?php
// descoberta de servico: o gateway pergunta a qualquer no quem e o lider e responde {leader, url}
require __DIR__ . '/../lib.php';
$st = state_read();
$l = $st['leader'];
json_out(['node' => NODE_ID, 'leader' => $l, 'url' => $l ? (peers_publicos()[$l] ?? null) : null, 'lamport' => $st['lamport']]);
