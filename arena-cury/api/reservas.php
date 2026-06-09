<?php
// reservas.php — reservas por LUGARES com alocação automática de mesa
// Ações: listar | criar | remover | reposicionar | liberar
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'listar';

function diaValido($dia){
  $wd = (int)date('w', strtotime($dia)); // 0=dom 6=sab
  return !($wd===0 || $wd===6);
}

if ($acao === 'listar') {
  $rows = db()->query("SELECT r.*, e.gerencia, m.nome AS mesa_nome
                       FROM reservas r LEFT JOIN equipes e ON e.id=r.equipe_id
                       LEFT JOIN mesas m ON m.id=r.mesa_id
                       ORDER BY r.dia, r.horario")->fetchAll();
  ok(['reservas' => $rows]);
}

if ($acao === 'criar') {
  $d = body();
  $dia=$d['dia']??''; $hora=$d['horario']??''; $nivel=$d['nivel']??'ger';
  $nome=trim($d['nome']??''); $lugares=(int)($d['lugares']??0);
  $equipe_id = !empty($d['equipe_id']) ? (int)$d['equipe_id'] : null;
  if (!$dia || !$hora || $lugares<1) fail('Preencha dia, horário e nº de lugares');
  if (!diaValido($dia)) fail('A arena não funciona aos fins de semana');
  if ($hora < '09:00' || $hora > '20:00') fail('Horário permitido: 9h às 20h');

  // limite: não pode reservar mais lugares do que corretores cadastrados na equipe
  if ($equipe_id) {
    $cc = db()->prepare("SELECT COUNT(*) n FROM corretores WHERE equipe_id=?");
    $cc->execute([$equipe_id]);
    $nCorr = (int)$cc->fetch()['n'];
    if ($nCorr === 0) fail('Cadastre os corretores da equipe antes de agendar');
    if ($lugares > $nCorr) fail("Você só pode reservar até $nCorr lugares (corretores cadastrados)");
  }

  // a mesma equipe já tem reserva nesse dia/horário?
  if ($equipe_id) {
    $c = db()->prepare("SELECT id FROM reservas WHERE equipe_id=? AND dia=? AND horario=?");
    $c->execute([$equipe_id,$dia,$hora]);
    if ($c->fetch()) fail('Sua equipe já tem uma reserva nesse dia e horário');
  }

  // ALOCAÇÃO AUTOMÁTICA: acha a 1ª mesa com lugares livres suficientes nesse dia
  $mesas = db()->query('SELECT * FROM mesas ORDER BY ordem, id')->fetchAll();
  $mesaEscolhida = null;
  foreach ($mesas as $m) {
    $st = db()->prepare("SELECT COALESCE(SUM(lugares),0) u FROM reservas WHERE mesa_id=? AND dia=?");
    $st->execute([$m['id'],$dia]);
    $usados = (int)$st->fetch()['u'];
    if ($usados + $lugares <= (int)$m['lugares']) { $mesaEscolhida = $m['id']; break; }
  }
  if (!$mesaEscolhida) fail('Não há mesa com '.$lugares.' lugares livres nesse dia');

  db()->prepare("INSERT INTO reservas (dia,horario,nivel,nome,participantes,lugares,mesa_id,equipe_id)
                 VALUES (?,?,?,?,?,?,?,?)")
      ->execute([$dia,$hora,$nivel,$nome,$lugares,$lugares,$mesaEscolhida,$equipe_id]);
  $id = db()->lastInsertId();
  $mn = db()->prepare('SELECT nome FROM mesas WHERE id=?'); $mn->execute([$mesaEscolhida]);
  ok(['id'=>$id, 'mesa_id'=>$mesaEscolhida, 'mesa_nome'=>$mn->fetch()['nome']??'']);
}

if ($acao === 'remover') {
  $d = body(); $id=(int)($d['id']??0);
  db()->prepare('DELETE FROM reservas WHERE id=?')->execute([$id]);
  ok();
}

// recepcionista move uma reserva para outra mesa
if ($acao === 'reposicionar') {
  $d = body(); $id=(int)($d['reserva_id']??0); $mesa=(int)($d['mesa_id']??0);
  if (!$id || !$mesa) fail('Dados inválidos');
  // valida capacidade da mesa destino
  $r = db()->prepare('SELECT * FROM reservas WHERE id=?'); $r->execute([$id]); $res=$r->fetch();
  if (!$res) fail('Reserva não encontrada');
  $st = db()->prepare("SELECT COALESCE(SUM(lugares),0) u FROM reservas WHERE mesa_id=? AND dia=? AND id<>?");
  $st->execute([$mesa,$res['dia'],$id]);
  $usados=(int)$st->fetch()['u'];
  $cap = db()->prepare('SELECT lugares FROM mesas WHERE id=?'); $cap->execute([$mesa]);
  $capm=(int)$cap->fetch()['lugares'];
  if ($usados + (int)$res['lugares'] > $capm) fail('A mesa destino não tem lugares suficientes');
  db()->prepare('UPDATE reservas SET mesa_id=? WHERE id=?')->execute([$mesa,$id]);
  ok();
}

// liberar lugares ociosos: ajusta a reserva para o nº de presentes (libera o resto)
if ($acao === 'liberar') {
  $d = body(); $id=(int)($d['reserva_id']??0);
  if (!$id) fail('Informe a reserva');
  $r = db()->prepare('SELECT * FROM reservas WHERE id=?'); $r->execute([$id]); $res=$r->fetch();
  if (!$res) fail('Reserva não encontrada');
  $p = db()->prepare("SELECT COUNT(*) n FROM presencas WHERE equipe_id=? AND dia=?");
  $p->execute([$res['equipe_id'],$res['dia']]);
  $pres=(int)$p->fetch()['n'];
  $novo = max(1,$pres); // não zera; mínimo 1
  db()->prepare('UPDATE reservas SET lugares=? WHERE id=?')->execute([$novo,$id]);
  ok(['lugares'=>$novo,'liberados'=>(int)$res['lugares']-$novo]);
}

fail('Ação desconhecida: '.$acao, 404);
