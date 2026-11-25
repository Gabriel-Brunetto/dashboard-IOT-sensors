<?php
// historico de uma estacao para graficos, GET /history.php?esp=esp32-1&from=epoch&to=epoch&limit=200
// from, to e limit sao opcionais, e o limit (padrao 200, maximo 2000) fica com as leituras mais recentes do intervalo
// responde {esp, from, to, count, readings: [{t, temp, umid, l}]} em ordem crescente de t
require __DIR__ . '/../lib.php';
require __DIR__ . '/../storage.php';

$esp = $_GET['esp'] ?? null;
if (!id_valido($esp)) json_out(['error' => 'parametro esp invalido ou ausente'], 400);
if (!in_array($esp, store_esps(), true)) json_out(['error' => 'estacao desconhecida', 'esp' => $esp], 404);

foreach (['from', 'to', 'limit'] as $p)
	if (isset($_GET[$p]) && !is_numeric($_GET[$p])) json_out(['error' => "parametro $p deve ser numerico"], 400);

$from = (float)($_GET['from'] ?? 0);
$to = (float)($_GET['to'] ?? microtime(true));
$limit = max(1, min(2000, (int)($_GET['limit'] ?? 200)));

// so os campos que a pagina precisa, a chave de deduplicacao e interna
$leituras = array_map(
	fn($r) => ['t' => $r['t'], 'temp' => $r['temp'], 'umid' => $r['umid'], 'l' => $r['l']],
	store_history($esp, $from, $to, $limit)
);
json_out(['esp' => $esp, 'from' => $from, 'to' => $to, 'count' => count($leituras), 'readings' => $leituras]);
