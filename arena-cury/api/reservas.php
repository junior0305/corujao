<?php
// reservas.php — reservas do salão com as regras (5 mesas x 20 = 100; diretoria bloqueia o dia; seg-sex 9h-20h)
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'listar';
const CAP_TOTAL = 100;

if ($acao === 'listar') {
  ok(['reservas' => db()->query('SELECT * FROM reservas ORDER BY dia, horario')->fetchAll()]);
}
if ($acao === 'criar') {
  $d = body();
  $dia = $d['dia'] ?? ''; $hora = $d['horario'] ?? ''; $nivel = $d['nivel'] ?? '';
  $nome = trim($d['nome'] ?? ''); $part = (int)($d['participantes'] ?? 0);
  $buf = !empty($d['buffet']) ? 1 : 0; $bufh = $d['buffet_hora'] ?? null;
  if (!$dia || !$hora || !in_array($nivel,['dir','sup','ger']) || $nome==='') fail('Preencha os campos da reserva');
  // fim de semana
  $wd = (int)date('w', strtotime($dia)); // 0=dom 6=sab
  if ($wd === 0 || $wd === 6) fail('A arena não funciona aos fins de semana');
  if ($hora < '09:00' || $hora > '20:00') fail('Horário permitido: 9h às 20h');
  // diretoria = salão todo
  if ($nivel === 'dir') $part = CAP_TOTAL;
  // dia já bloqueado por diretoria?
  $st = db()->prepare("SELECT nome FROM reservas WHERE dia=? AND nivel='dir' LIMIT 1");
  $st->execute([$dia]);
  if ($r = $st->fetch()) fail('Dia bloqueado pela '.$r['nome']);
  // nova diretoria mas já há reservas nesse dia?
  if ($nivel === 'dir') {
    $st = db()->prepare('SELECT COUNT(*) n FROM reservas WHERE dia=?'); $st->execute([$dia]);
    if ((int)$st->fetch()['n'] > 0) fail('Já há reservas nesse dia — diretoria não pode bloquear');
  }
  // capacidade do dia
  $st = db()->prepare('SELECT COALESCE(SUM(participantes),0) u FROM reservas WHERE dia=?'); $st->execute([$dia]);
  $usados = (int)$st->fetch()['u'];
  if ($usados + $part > CAP_TOTAL) fail('Sem lugares suficientes nesse dia ('.(CAP_TOTAL-$usados).' livres)');
  db()->prepare('INSERT INTO reservas (dia,horario,nivel,nome,participantes,buffet,buffet_hora) VALUES (?,?,?,?,?,?,?)')
      ->execute([$dia,$hora,$nivel,$nome,$part,$buf,$bufh]);
  ok(['id' => db()->lastInsertId()]);
}
if ($acao === 'remover') {
  $d = body(); $id = (int)($d['id'] ?? 0);
  db()->prepare('DELETE FROM reservas WHERE id=?')->execute([$id]);
  ok();
}
fail('Ação desconhecida: '.$acao, 404);
