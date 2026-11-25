<?php
// armazenamento das leituras em arquivos json lines, um por estacao
// para trocar por um banco de dados e preciso reimplementar apenas as funcoes store_* mantendo as assinaturas
//
// uma leitura e ['k' => chave unica, 'esp' => 'esp32-1', 't' => epoch com decimais, 'temp' => 24.6, 'umid' => 38.4, 'l' => lamport]

function store_file($esp) { return DATA_DIR . '/leituras/' . $esp . '.jsonl'; }

// ids de estacao viram nome de arquivo, entao so letras minusculas, numeros e hifen
function id_valido($id) { return is_string($id) && preg_match('/^[a-z0-9-]{1,32}$/', $id); }

function store_read_all($esp) {
	$f = store_file($esp);
	if (!file_exists($f)) return [];
	$out = [];
	foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
		$r = json_decode($linha, true);
		if ($r) $out[] = $r;
	}
	return $out;
}

// grava a leitura, ou devolve false se a chave ja existia (assim reenviar um lote nao duplica nada)
function store_save(array $r) {
	if (!id_valido($r['esp'] ?? null)) return false;
	@mkdir(DATA_DIR . '/leituras', 0777, true);
	$fp = fopen(store_file($r['esp']), 'c+');
	flock($fp, LOCK_EX);

	// so as ultimas 400 linhas sao conferidas, um reenvio antigo demais passaria
	$existentes = array_slice(explode("\n", stream_get_contents($fp)), -400);
	foreach ($existentes as $linha) {
		if ($linha !== '' && (json_decode($linha, true)['k'] ?? null) === $r['k']) {
			flock($fp, LOCK_UN);
			fclose($fp);
			return false;
		}
	}
	fseek($fp, 0, SEEK_END);
	fwrite($fp, json_encode($r) . "\n");
	fflush($fp);
	flock($fp, LOCK_UN);
	fclose($fp);
	return true;
}

// as $n leituras mais recentes, da mais nova para a mais antiga
function store_latest($esp, $n = 5) { return array_reverse(array_slice(store_read_all($esp), -$n)); }

function store_esps() {
	return array_map(fn($f) => basename($f, '.jsonl'), glob(DATA_DIR . '/leituras/*.jsonl') ?: []);
}

// maior lamport guardado, e a partir dele que um no que voltou pede o que perdeu
function store_max_l() {
	$max = 0;
	foreach (store_esps() as $esp)
		foreach (store_read_all($esp) as $r) $max = max($max, $r['l'] ?? 0);
	return $max;
}

// leituras com lamport maior que $since, em ordem de lamport, usado na ressincronizacao de um no que voltou
function store_since($since, $limit = 2000) {
	$out = [];
	foreach (store_esps() as $esp)
		foreach (store_read_all($esp) as $r)
			if (($r['l'] ?? 0) > $since) $out[] = $r;
	usort($out, fn($a, $b) => $a['l'] <=> $b['l']);
	return array_slice($out, 0, $limit);
}

// leituras de uma estacao entre os instantes $from e $to (epoch), em ordem crescente de tempo
// se houver mais que $limit no intervalo, ficam as $limit mais recentes
function store_history($esp, $from, $to, $limit) {
	$r = array_values(array_filter(store_read_all($esp), fn($x) => $x['t'] >= $from && $x['t'] <= $to));
	usort($r, fn($a, $b) => $a['t'] <=> $b['t']);
	return array_slice($r, -$limit);
}
