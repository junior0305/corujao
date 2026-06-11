<?php
// contestacoes.php — mecânica de contestação dentro do duelo
// Ações: pontos_duelo | contestar | recebidas | anular | remover
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';

// pontos dos ADVERSÁRIOS num duelo (que a equipe pode contestar)
if ($acao === 'pontos_duelo') {
  $did = (int)($_GET['duelo_id'] ?? 0);
  $minha = (int)($_GET['equipe_id'] ?? 0);
  if (!$did) fail('Informe o duelo');
  // pontos aprovados/valendo de equipes participantes do duelo, exceto a minha
  $st = db()->prepare("SELECT p.id, p.tipo, p.foto, p.criado_em, e.gerencia AS equipe,
                              EXISTS(SELECT 1 FROM contestacoes c WHERE c.ponto_id=p.id AND c.status='aberta') AS contestado
                       FROM pontos p
                       JOIN equipes e ON e.id=p.equipe_id
                       WHERE p.duelo_id=? AND p.equipe_id <> ? AND p.status='aprovado'
                       ORDER BY p.criado_em DESC");
  $st->execute([$did, $minha]);
  ok(['pontos' => $st->fetchAll()]);
}

// equipe contesta um ponto
if ($acao === 'contestar') {
  exigirCodigo();
  $d = body(); $pid = (int)($d['ponto_id'] ?? 0); $por = (int)($d['equipe_id'] ?? 0);
  if (!$pid || !$por) fail('Dados inválidos');
  db()->prepare('INSERT INTO contestacoes (ponto_id, contestante_equipe_id, status) VALUES (?,?,"aberta")')->execute([$pid,$por]);
  // avisa quem marcou (evento opcional para notificação)
  $p = db()->prepare('SELECT equipe_id FROM pontos WHERE id=?'); $p->execute([$pid]); $dono = $p->fetch();
  emitir('contestacao', ['ponto_id'=>$pid, 'dono_equipe_id'=>$dono['equipe_id']??null]);
  ok();
}

// contestações recebidas (contra a minha equipe) num duelo
if ($acao === 'recebidas') {
  $did = (int)($_GET['duelo_id'] ?? 0);
  $minha = (int)($_GET['equipe_id'] ?? 0);
  if (!$minha) fail('Informe a equipe');
  $st = db()->prepare("SELECT c.id, c.ponto_id, p.tipo, p.foto, c.criado_em,
                              e.gerencia AS contestante
                       FROM contestacoes c
                       JOIN pontos p ON p.id=c.ponto_id
                       JOIN equipes e ON e.id=c.contestante_equipe_id
                       WHERE p.equipe_id=? AND c.status='aberta'".($did?" AND p.duelo_id=".$did:"")."
                       ORDER BY c.criado_em");
  $st->execute([$minha]);
  ok(['recebidas' => $st->fetchAll()]);
}

// dono anula a contestação (ponto era legítimo) — ponto continua valendo
if ($acao === 'anular') {
  $d = body(); $cid = (int)($d['contestacao_id'] ?? 0);
  if (!$cid) fail('Informe a contestação');
  db()->prepare("UPDATE contestacoes SET status='anulada', decidido_em=NOW() WHERE id=?")->execute([$cid]);
  ok();
}

// dono reconhece a falta -> remove o ponto, recalcula, registra falta
if ($acao === 'remover') {
  $d = body(); $cid = (int)($d['contestacao_id'] ?? 0);
  if (!$cid) fail('Informe a contestação');
  $c = db()->prepare("SELECT c.*, p.equipe_id, p.duelo_id, p.valor FROM contestacoes c JOIN pontos p ON p.id=c.ponto_id WHERE c.id=?");
  $c->execute([$cid]); $row = $c->fetch();
  if (!$row) fail('Contestação não encontrada');

  // remove o ponto (marca como rejeitado por falta)
  db()->prepare("UPDATE pontos SET status='rejeitado', motivo_rejeicao='Falta confirmada (contestação)' WHERE id=?")->execute([$row['ponto_id']]);
  db()->prepare("UPDATE contestacoes SET status='procedente', decidido_em=NOW() WHERE id=?")->execute([$cid]);

  // recalcula placar do duelo
  if ($row['duelo_id']) {
    db()->prepare('UPDATE duelo_participantes SET pontos=GREATEST(0, pontos-?) WHERE duelo_id=? AND equipe_id=?')
        ->execute([$row['valor'], $row['duelo_id'], $row['equipe_id']]);
  }

  // conta falta NESTE duelo
  $eid = $row['equipe_id']; $did = $row['duelo_id'];
  $f = db()->prepare("SELECT COUNT(*) n FROM faltas WHERE duelo_id=? AND equipe_id=?");
  $f->execute([$did, $eid]);
  $nFaltas = (int)$f->fetch()['n'] + 1;
  db()->prepare('INSERT INTO faltas (duelo_id, equipe_id) VALUES (?,?)')->execute([$did, $eid]);

  $g = db()->prepare('SELECT gerencia FROM equipes WHERE id=?'); $g->execute([$eid]);
  $ger = $g->fetch()['gerencia'] ?? '';

  if ($nFaltas >= 2) {
    // desclassifica: remove do duelo
    db()->prepare('DELETE FROM duelo_participantes WHERE duelo_id=? AND equipe_id=?')->execute([$did, $eid]);
    emitir('desclassificado', ['duelo_id'=>$did, 'equipe_id'=>$eid, 'gerencia'=>$ger]);
    // se sobrou só 1, encerra o duelo dando vitória a ele
    $rest = db()->prepare('SELECT equipe_id FROM duelo_participantes WHERE duelo_id=?');
    $rest->execute([$did]); $sobra = $rest->fetchAll();
    if (count($sobra) === 1) {
      db()->prepare("UPDATE duelos SET status='encerrado', vencedor_equipe_id=?, encerrado_em=NOW() WHERE id=?")
          ->execute([$sobra[0]['equipe_id'], $did]);
      emitir('nocaute', ['duelo_id'=>$did, 'vencedor_equipe_id'=>$sobra[0]['equipe_id']]);
    }
    ok(['desclassificado'=>true, 'faltas'=>$nFaltas]);
  } else {
    emitir('falta', ['duelo_id'=>$did, 'equipe_id'=>$eid, 'gerencia'=>$ger, 'valor'=>$row['valor'], 'faltas'=>$nFaltas]);
    ok(['desclassificado'=>false, 'faltas'=>$nFaltas]);
  }
}

fail('Ação desconhecida: '.$acao, 404);
