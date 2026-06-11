<?php
// duelos.php — criar desafio, aceitar/recusar, entrar, encerrar
// Ações: criar | responder | entrar | ativos | encerrar
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';
$CORES = ['azul','branco','azulclaro','ciano','cinza'];
define('JANELA_ENTRADA', 0.20); // 3º ao 5º só entram até 20% da batalha

// progresso (0..1) de uma batalha já carregada (linha da tabela duelos)
function progressoDuelo($duelo) {
  if (($duelo['status'] ?? '') !== 'ativo') return 1.0;
  if ($duelo['regra'] === 'tempo') {
    $dur = max(1, (int)$duelo['regra_valor']*60);
    $ini = $duelo['iniciado_em'] ? strtotime($duelo['iniciado_em']) : time();
    return (time() - $ini) / $dur;
  }
  $mp = db()->prepare('SELECT COALESCE(MAX(pontos),0) m FROM duelo_participantes WHERE duelo_id=?');
  $mp->execute([$duelo['id']]);
  $top = (int)$mp->fetch()['m']; $meta = max(1,(int)$duelo['regra_valor']);
  return $top / $meta;
}

// Encerra AUTOMATICAMENTE um duelo 'ativo' que já terminou (meta atingida ou tempo esgotado).
// Faz do servidor a fonte da verdade: sem isso, a TV "revive" o duelo encerrado e o tablet
// fica preso em batalha (o fim era só animação local). Retorna true se encerrou agora.
function autoEncerrarSeTerminou($duelo) {
  if (($duelo['status'] ?? '') !== 'ativo') return false;
  if (progressoDuelo($duelo) < 1.0) return false; // ainda em andamento
  // vencedor: maior placar; empate no topo (ou 0x0) => sem vencedor
  $ps = db()->prepare("SELECT equipe_id, pontos FROM duelo_participantes WHERE duelo_id=? ORDER BY pontos DESC");
  $ps->execute([$duelo['id']]); $rows = $ps->fetchAll();
  $venc = null;
  if ($rows) {
    $topo = (int)$rows[0]['pontos'];
    $lideres = array_filter($rows, fn($r)=>(int)$r['pontos']===$topo);
    if (count($lideres) === 1 && $topo > 0) $venc = (int)$rows[0]['equipe_id'];
  }
  // o WHERE ... status='ativo' + rowCount garante que só UM request encerra e emite o nocaute
  $st = db()->prepare("UPDATE duelos SET status='encerrado', vencedor_equipe_id=?, encerrado_em=NOW()
                       WHERE id=? AND status='ativo'");
  $st->execute([$venc, $duelo['id']]);
  if ($st->rowCount() === 0) return false; // outro request já encerrou
  emitir('nocaute', ['duelo_id'=>(int)$duelo['id'], 'vencedor_equipe_id'=>$venc, 'auto'=>true]);
  return true;
}

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
    // se a batalha já terminou (meta/tempo), encerra agora e avisa o tablet para sair do duelo
    if (autoEncerrarSeTerminou($d)) $d['status'] = 'encerrado';
    $pp = db()->prepare("SELECT p.equipe_id,p.cor,p.pontos,p.ordem,e.gerencia
                         FROM duelo_participantes p JOIN equipes e ON e.id=p.equipe_id
                         WHERE p.duelo_id=? ORDER BY p.ordem");
    $pp->execute([$d['id']]); $d['participantes'] = $pp->fetchAll();
  }
  ok(['duelo' => $d ?: null]);
}

if ($acao === 'desistir') {
  exigirCodigo();
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
  exigirCodigo();
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
  $g = db()->prepare('SELECT id,gerencia,superintendencia FROM equipes WHERE id IN (?,?)'); $g->execute([$desafiante,$desafiado]);
  $nomes=[]; $sups=[]; foreach($g->fetchAll() as $r){$nomes[$r['id']]=$r['gerencia'];$sups[$r['id']]=$r['superintendencia'];}
  emitir('desafio', ['duelo_id'=>$did,
    'desafiante'=>$nomes[$desafiante]??'','desafiado'=>$nomes[$desafiado]??'',
    'desafiante_id'=>$desafiante,'desafiado_id'=>$desafiado,
    'desafiante_sup'=>$sups[$desafiante]??'','desafiado_sup'=>$sups[$desafiado]??'',
    'regra'=>$regra,'regra_valor'=>$rv]);
  ok(['duelo_id'=>$did]);
}

if ($acao === 'responder') {
  exigirCodigo();
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
  exigirCodigo();
  // 3º ao 5º entra direto (sem aceite), DESDE QUE dentro da janela de 20% da batalha
  $d = body(); $did=(int)($d['duelo_id']??0); $eid=(int)($d['equipe_id']??0);
  if (!$did||!$eid) fail('Dados inválidos');
  // carrega o duelo para checar a janela de entrada
  $dl=db()->prepare('SELECT * FROM duelos WHERE id=?'); $dl->execute([$did]); $duelo=$dl->fetch();
  if (!$duelo) fail('Duelo não encontrado', 404);
  if ($duelo['status']!=='ativo') fail('A batalha não está ativa para entrada');
  if (progressoDuelo($duelo) > JANELA_ENTRADA) fail('Janela de entrada encerrada: a batalha já passou de 20%');
  $st=db()->prepare('SELECT COUNT(*) n FROM duelo_participantes WHERE duelo_id=?'); $st->execute([$did]);
  $n=(int)$st->fetch()['n']; if ($n>=5) fail('Duelo cheio (máx 5)');
  // não pode entrar duas vezes
  $ja=db()->prepare('SELECT 1 FROM duelo_participantes WHERE duelo_id=? AND equipe_id=?'); $ja->execute([$did,$eid]);
  if ($ja->fetch()) fail('Equipe já está na batalha');
  $usadas=db()->prepare('SELECT cor FROM duelo_participantes WHERE duelo_id=?'); $usadas->execute([$did]);
  $u=array_column($usadas->fetchAll(),'cor'); $livres=array_values(array_diff($CORES,$u));
  $cor=$livres? $livres[0] : $CORES[array_rand($CORES)];
  db()->prepare('INSERT INTO duelo_participantes (duelo_id,equipe_id,cor,ordem,pontos) VALUES (?,?,?,?,0)')->execute([$did,$eid,$cor,$n+1]);
  $g=db()->prepare('SELECT gerencia,superintendencia FROM equipes WHERE id=?'); $g->execute([$eid]); $eq=$g->fetch();
  emitir('entra_duelo', ['duelo_id'=>$did,'equipe_id'=>$eid,'gerencia'=>$eq['gerencia']??'','superintendencia'=>$eq['superintendencia']??'','cor'=>$cor]);
  ok();
}

if ($acao === 'ativos') {
  // duelos em andamento, com participantes e placar (para a TV montar a tela e o tablet listar entradas)
  $duelos = db()->query("SELECT * FROM duelos WHERE status IN ('aguardando','ativo') ORDER BY id")->fetchAll();
  $saida = [];
  foreach ($duelos as $d) {
    // batalha que já terminou (meta/tempo) é encerrada aqui e NÃO entra na lista de ativos
    // — é isso que impede a TV de "reviver" o duelo no próximo sincronizarEstado.
    if (autoEncerrarSeTerminou($d)) continue;
    $st = db()->prepare("SELECT p.equipe_id,p.cor,p.pontos,p.ordem,e.gerencia,e.superintendencia
                         FROM duelo_participantes p JOIN equipes e ON e.id=p.equipe_id
                         WHERE p.duelo_id=? ORDER BY p.ordem");
    $st->execute([$d['id']]); $d['participantes'] = $st->fetchAll();
    $prog = progressoDuelo($d);
    $d['progresso'] = round($prog, 3);
    $d['vagas'] = max(0, 5 - count($d['participantes']));
    // 3º ao 5º só entram em batalha ATIVA, dentro da janela e com vaga
    $d['pode_entrar'] = ($d['status']==='ativo' && $prog <= JANELA_ENTRADA && $d['vagas'] > 0);
    $saida[] = $d;
  }
  ok(['duelos' => $saida]);
}

if ($acao === 'encerrar') {
  $d = body(); $did=(int)($d['duelo_id']??0); $venc=(int)($d['vencedor_equipe_id']??0);
  if (!$did) fail('Informe o duelo');
  // se não veio vencedor explícito, vence quem está na frente (maior placar); empate → sem vencedor
  if (!$venc) {
    $ps = db()->prepare("SELECT equipe_id, pontos FROM duelo_participantes WHERE duelo_id=? ORDER BY pontos DESC");
    $ps->execute([$did]); $rows = $ps->fetchAll();
    if (count($rows) >= 1) {
      $topo = (int)$rows[0]['pontos'];
      $lideres = array_filter($rows, fn($r)=>(int)$r['pontos']===$topo);
      if (count($lideres) === 1 && $topo > 0) $venc = (int)$rows[0]['equipe_id'];
    }
  }
  db()->prepare("UPDATE duelos SET status='encerrado', vencedor_equipe_id=?, encerrado_em=NOW() WHERE id=?")->execute([$venc?:null,$did]);
  emitir('nocaute', ['duelo_id'=>$did,'vencedor_equipe_id'=>$venc,'encerrado_pela_recepcao'=>true]);
  ok(['vencedor_equipe_id'=>$venc]);
}

fail('Ação desconhecida: '.$acao, 404);
