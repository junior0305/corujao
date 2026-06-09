<?php
// eventos.php — a TV puxa eventos novos para animar (polling)
// Ação: novos (retorna e marca como consumidos) | limpar
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'novos';

if ($acao === 'novos') {
  $desde = (int)($_GET['desde'] ?? 0); // último id que a TV já viu
  $st = db()->prepare('SELECT id, tipo, payload, criado_em FROM eventos WHERE id > ? ORDER BY id ASC LIMIT 50');
  $st->execute([$desde]);
  $rows = $st->fetchAll();
  foreach ($rows as &$r) { $r['payload'] = $r['payload'] ? json_decode($r['payload'], true) : null; }
  ok(['eventos' => $rows]);
}
if ($acao === 'ultimo') {
  // maior id de evento atual — a TV usa para começar a ouvir só o que vier DEPOIS
  $r = db()->query('SELECT COALESCE(MAX(id),0) AS ultimo FROM eventos')->fetch();
  ok(['ultimo' => (int)$r['ultimo']]);
}
if ($acao === 'limpar') {
  db()->query('DELETE FROM eventos'); // zera a fila (uso administrativo)
  ok();
}
fail('Ação desconhecida: '.$acao, 404);
