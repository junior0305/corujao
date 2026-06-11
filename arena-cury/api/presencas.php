<?php
// presencas.php — check-in de corretores por dia/evento
// Ações: hoje | marcar | desmarcar | ultimo
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';
$hoje = date('Y-m-d');

// lista ids dos corretores presentes HOJE para uma equipe
if ($acao === 'hoje') {
  $eid = (int)($_GET['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $st = db()->prepare("SELECT corretor_id FROM presencas WHERE equipe_id=? AND dia=?");
  $st->execute([$eid, $hoje]);
  ok(['presentes' => array_map('intval', array_column($st->fetchAll(), 'corretor_id'))]);
}

// marca presença de um corretor hoje
if ($acao === 'marcar') {
  exigirCodigo();
  $d = body(); $cid = (int)($d['corretor_id'] ?? 0); $eid = (int)($d['equipe_id'] ?? 0);
  if (!$cid || !$eid) fail('Dados inválidos');
  db()->prepare("INSERT IGNORE INTO presencas (corretor_id, equipe_id, dia) VALUES (?,?,?)")->execute([$cid,$eid,$hoje]);
  ok();
}

// desmarca presença de um corretor hoje
if ($acao === 'desmarcar') {
  exigirCodigo();
  $d = body(); $cid = (int)($d['corretor_id'] ?? 0);
  if (!$cid) fail('Dados inválidos');
  db()->prepare("DELETE FROM presencas WHERE corretor_id=? AND dia=?")->execute([$cid,$hoje]);
  ok();
}

// presentes do ÚLTIMO evento (dia anterior com presença) — para pré-marcar
if ($acao === 'ultimo') {
  $eid = (int)($_GET['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  // acha o último dia (antes de hoje) que teve presença
  $st = db()->prepare("SELECT MAX(dia) ult FROM presencas WHERE equipe_id=? AND dia < ?");
  $st->execute([$eid, $hoje]);
  $ult = $st->fetch()['ult'] ?? null;
  if (!$ult) { ok(['presentes' => [], 'dia' => null]); }
  $st = db()->prepare("SELECT corretor_id FROM presencas WHERE equipe_id=? AND dia=?");
  $st->execute([$eid, $ult]);
  ok(['presentes' => array_map('intval', array_column($st->fetchAll(), 'corretor_id')), 'dia' => $ult]);
}

fail('Ação desconhecida: '.$acao, 404);
