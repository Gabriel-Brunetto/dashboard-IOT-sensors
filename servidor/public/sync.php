<?php
// ressincronizacao: devolve as leituras com lamport maior que since, e qualquer no pode responder
require __DIR__ . '/../lib.php';
require __DIR__ . '/../storage.php';
json_out(['readings' => store_since((int)($_GET['since'] ?? 0))]);
