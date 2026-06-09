<?php
// duelos.php — criar desafio, aceitar/recusar, entrar, encerrar
// Ações: criar | responder | entrar | ativos | encerrar
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';
$CORES = ['azul','branco','azulclaro','ciano','cinza'];

if ($acao === 'para_mim') {
  // desafios AGUARDANDO resposta, onde esta equipe é o desafiado (ordem=2)
  $eid = (int)($_GET['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $st = db()->prepare("SELECT d.id, d.nivel, d.regra, d.regra_valor,
                              des.gerencia AS desafiante
                       FROM duelos d
                       JOIN duelo_participantes p ON p.duelo_id=d.id AND p.equipe_id=? AND p.ordem=2
                       JOIN duelo_participantes pd ON pd.duelo_id=d.id AND pd.ordem=1
                       JOIN equipes des ON des.id=pd.equipe_id
                       WHERE d.status='aguardando'
                       ORDER BY d.id DESC LIMIT 1");
  $st->execute([$eid]);
  $row = $st->fetch();
  ok(['desafio' => $row ?: null]);
}

if ($acao === 'meu_duelo') {
  // situação do duelo ativo/aguardando em que a equipe participa (para o desafiante saber se foi aceito)
  $eid = (int)($_GET['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $st = db()->prepare("SELECT d.* FROM duelos d
                       JOIN duelo_participantes p ON p.duelo_id=d.id AND p.equipe_id=?
                       WHERE d.status IN ('aguardando','ativo')
                       ORDER BY d.id DESC LIMIT 1");
  $st->execute([$eid]);
  $d = $st->fetch();
  if ($d) {
    $pp = db()->prepare("SELECT p.equipe_id,p.cor,p.pontos,p.ordem,e.gerencia
                         FROM duelo_participantes p JOIN equipes e ON e.id=p.equipe_id
                         WHERE p.duelo_id=? ORDER BY p.ordem");
    $pp->execute([$d['id']]); $d['participantes'] = $pp->fetchAll();
  }
  ok(['duelo' => $d ?: null]);
}

if ($acao === 'desistir') {
  // equipe abandona o duelo voluntariamente (continua online no sistema)
  $d = body(); $did=(int)($d['duelo_id']??0); $eid=(int)($d['equipe_id']??0);
  if (!$did || !$eid) fail('Dados inválidos');
  $g = db()->prepare('SELECT gerencia FROM equipes WHERE id=?'); $g->execute([$eid]);
  $ger = $g->fetch()['gerencia'] ?? '';
  // remove do duelo
  db()->prepare('DELETE FROM duelo_participantes WHERE duelo_id=? AND equipe_id=?')->execute([$did,$eid]);
  emitir('desistencia', ['duelo_id'=>$did, 'equipe_id'=>$eid, 'gerencia'=>$ger]);
  // se sobrou só 1, encerra com vitória dele
  $rest = db()->prepare('SELECT equipe_id FROM duelo_participantes WHERE duelo_id=?');
  $rest->execute([$did]); $sobra = $rest->fetchAll();
  if (count($sobra) <= 1) {
    $venc = $sobra ? $sobra[0]['equipe_id'] : null;
    db()->prepare("UPDATE duelos SET status='encerrado', vencedor_equipe_id=?, encerrado_em=NOW() WHERE id=?")
        ->execute([$venc, $did]);
    emitir('nocaute', ['duelo_id'=>$did, 'vencedor_equipe_id'=>$venc, 'por_desistencia'=>true]);
    ok(['encerrado'=>true]);
  }
  ok(['encerrado'=>false]);
}

if ($acao === 'criar') {
  // desafiante cria; fica 'aguardando' o aceite do desafiado
  $d = body();
  $nivel = $d['nivel'] ?? 'gerencia'; $regra = $d['regra'] ?? 'meta'; $rv = (int)($d['regra_valor'] ?? 10);
  $desafiante = (int)($d['desafiante_equipe_id'] ?? 0);
  $desafiado  = (int)($d['desafiado_equipe_id'] ?? 0);
  if (!$desafiante || !$desafiado) fail('Informe as equipes');
  $st = db()->prepare('INSERT INTO duelos (nivel,regra,regra_valor,status) VALUES (?,?,?,"aguardando")');
  $st->execute([$nivel,$regra,$rv]);
  $did = db()->lastInsertId();
  $cores = $CORES; shuffle($cores);
  db()->prepare('INSERT INTO duelo_participantes (duelo_id,equipe_id,cor,ordem) VALUES (?,?,?,1)')->execute([$did,$desafiante,$cores[0]]);
  db()->prepare('INSERT INTO duelo_participantes (duelo_id,equipe_id,cor,ordem) VALUES (?,?,?,2)')->execute([$did,$desafiado,$cores[1]]);
  $g = db()->prepare('SELECT id,gerencia FROM equipes WHERE id IN (?,?)'); $g->execute([$desafiante,$desafiado]);
  $nomes=[]; foreach($g->fetchAll() as $r)$nomes[$r['id']]=$r['gerencia'];
  emitir('desafio', ['duelo_id'=>$did,'desafiante'=>$nomes[$desafiante]??'','desafiado'=>$nomes[$desafiado]??'','regra'=>$regra,'regra_valor'=>$rv]);
  ok(['duelo_id'=>$did]);
}

if ($acao === 'responder') {
  // desafiado aceita ou recusa
  $d = body(); $did=(int)($d['duelo_id']??0); $aceita = !empty($d['aceita']);
  if (!$did) fail('Informe o duelo');
  if ($aceita) {
    db()->prepare("UPDATE duelos SET status='ativo', iniciado_em=NOW() WHERE id=?")->execute([$did]);
    db()->prepare('UPDATE duelo_participantes SET pontos=0 WHERE duelo_id=?')->execute([$did]); // zera placar do duelo
    emitir('aceite', ['duelo_id'=>$did]);
  } else {
    db()->prepare("UPDATE duelos SET status='recusado', encerrado_em=NOW() WHERE id=?")->execute([$did]);
    emitir('recusa', ['duelo_id'=>$did]);
  }
  ok();
}

if ($acao === 'entrar') {
  // 3º ao 5º entra direto (sem aceite)
  $d = body(); $did=(int)($d['duelo_id']??0); $eid=(int)($d['equipe_id']??0);
  if (!$did||!$eid) fail('Dados inválidos');
  $st=db()->prepare('SELECT COUNT(*) n FROM duelo_participantes WHERE duelo_id=?'); $st->execute([$did]);
  $n=(int)$st->fetch()['n']; if ($n>=5) fail('Duelo cheio (máx 5)');
  $usadas=db()->prepare('SELECT cor FROM duelo_participantes WHERE duelo_id=?'); $usadas->execute([$did]);
  $u=array_column($usadas->fetchAll(),'cor'); $livres=array_values(array_diff($CORES,$u));
  $cor=$livres? $livres[0] : $CORES[array_rand($CORES)];
  db()->prepare('INSERT INTO duelo_participantes (duelo_id,equipe_id,cor,ordem,pontos) VALUES (?,?,?,?,0)')->execute([$did,$eid,$cor,$n+1]);
  $g=db()->prepare('SELECT gerencia FROM equipes WHERE id=?'); $g->execute([$eid]);
  emitir('entra_duelo', ['duelo_id'=>$did,'gerencia'=>$g->fetch()['gerencia']??'','cor'=>$cor]);
  ok();
}

if ($acao === 'ativos') {
  // duelos em andamento, com participantes e placar (para a TV montar a tela)
  $duelos = db()->query("SELECT * FROM duelos WHERE status IN ('aguardando','ativo') ORDER BY id")->fetchAll();
  foreach ($duelos as &$d) {
    $st = db()->prepare("SELECT p.equipe_id,p.cor,p.pontos,p.ordem,e.gerencia,e.superintendencia
                         FROM duelo_participantes p JOIN equipes e ON e.id=p.equipe_id
                         WHERE p.duelo_id=? ORDER BY p.ordem");
    $st->execute([$d['id']]); $d['participantes'] = $st->fetchAll();
  }
  ok(['duelos' => $duelos]);
}

if ($acao === 'encerrar') {
  $d = body(); $did=(int)($d['duelo_id']??0); $venc=(int)($d['vencedor_equipe_id']??0);
  db()->prepare("UPDATE duelos SET status='encerrado', vencedor_equipe_id=?, encerrado_em=NOW() WHERE id=?")->execute([$venc?:null,$did]);
  emitir('nocaute', ['duelo_id'=>$did,'vencedor_equipe_id'=>$venc]);
  ok();
}

fail('Ação desconhecida: '.$acao, 404);
