<?php
// ============================================================
// pontos.php — marcar ponto (foto->pendente), aprovar, rejeitar
// Ações: marcar | pendentes | aprovar | rejeitar
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';

function valorDoTipo($tipo) {
  $c = db()->query('SELECT pts_visita, pts_documentacao FROM config WHERE id=1')->fetch();
  return $tipo === 'documentacao' ? (int)$c['pts_documentacao'] : (int)$c['pts_visita'];
}

if ($acao === 'marcar') {
  exigirCodigo(); // exige o código do dia (anti-brincadeira); desligado se não houver código
  // gerente marca visita/doc com foto -> entra como PENDENTE
  $d = body();
  $eid = (int)($d['equipe_id'] ?? 0);
  $cid = !empty($d['corretor_id']) ? (int)$d['corretor_id'] : null;
  $tipo = $d['tipo'] ?? '';
  $foto = $d['foto'] ?? null; // base64
  if (!$eid || !in_array($tipo,['visita','documentacao'])) fail('Dados inválidos');
  if (!$foto) fail('A foto do comprovante é obrigatória');
  $valor = valorDoTipo($tipo);
  // duelo ativo da equipe (se houver) — o ponto contará nele também quando aprovado
  $st = db()->prepare("SELECT d.id FROM duelos d JOIN duelo_participantes p ON p.duelo_id=d.id
                       WHERE p.equipe_id=? AND d.status='ativo' LIMIT 1");
  $st->execute([$eid]);
  $duelo = $st->fetch(); $duelo_id = $duelo ? $duelo['id'] : null;
  // Mecânica de contestação: o ponto VALE NA HORA (entra como aprovado).
  // A fiscalização é feita depois pelos adversários (contestação), não por aprovação prévia.
  $ins = db()->prepare("INSERT INTO pontos (equipe_id,corretor_id,tipo,valor,foto,duelo_id,status,decidido_em)
                        VALUES (?,?,?,?,?,?, 'aprovado', NOW())");
  $ins->execute([$eid,$cid,$tipo,$valor,$foto,$duelo_id]);
  $pid = db()->lastInsertId();
  // se há duelo ativo, soma no placar do duelo na hora
  if ($duelo_id) {
    db()->prepare('UPDATE duelo_participantes SET pontos=pontos+? WHERE duelo_id=? AND equipe_id=?')
        ->execute([$valor,$duelo_id,$eid]);
  }
  // evento para a TV animar (soco/explosão)
  $e = db()->prepare('SELECT gerencia FROM equipes WHERE id=?'); $e->execute([$eid]);
  $ger = $e->fetch()['gerencia'] ?? '';
  emitir($tipo, ['equipe_id'=>$eid, 'gerencia'=>$ger, 'valor'=>$valor, 'duelo_id'=>$duelo_id]);
  ok(['id' => $pid, 'status' => 'aprovado']);
}

if ($acao === 'pendentes') {
  // lista pendências (para o painel de aprovação)
  $rows = db()->query("SELECT p.id, p.tipo, p.valor, p.foto, p.criado_em,
                              e.gerencia, e.superintendencia, c.nome AS corretor
                       FROM pontos p
                       JOIN equipes e ON e.id=p.equipe_id
                       LEFT JOIN corretores c ON c.id=p.corretor_id
                       WHERE p.status='pendente' ORDER BY p.criado_em")->fetchAll();
  ok(['pendentes' => $rows]);
}

if ($acao === 'aprovar') {
  $d = body(); $pid = (int)($d['ponto_id'] ?? 0);
  if (!$pid) fail('Informe o ponto');
  $p = db()->prepare('SELECT * FROM pontos WHERE id=? AND status="pendente"');
  $p->execute([$pid]); $ponto = $p->fetch();
  if (!$ponto) fail('Pendência não encontrada');
  db()->prepare("UPDATE pontos SET status='aprovado', decidido_em=NOW() WHERE id=?")->execute([$pid]);

  // se há duelo ativo, soma no placar do duelo
  if ($ponto['duelo_id']) {
    db()->prepare('UPDATE duelo_participantes SET pontos=pontos+? WHERE duelo_id=? AND equipe_id=?')
        ->execute([$ponto['valor'],$ponto['duelo_id'],$ponto['equipe_id']]);
  }
  // dados para a TV animar (nome da equipe + se é duelo)
  $e = db()->prepare('SELECT gerencia FROM equipes WHERE id=?'); $e->execute([$ponto['equipe_id']]);
  $ger = $e->fetch()['gerencia'] ?? '';
  emitir($ponto['tipo'], [
    'equipe_id' => (int)$ponto['equipe_id'],
    'gerencia'  => $ger,
    'valor'     => (int)$ponto['valor'],
    'duelo_id'  => $ponto['duelo_id'] ? (int)$ponto['duelo_id'] : null
  ]);
  ok();
}

if ($acao === 'rejeitar') {
  $d = body(); $pid = (int)($d['ponto_id'] ?? 0); $motivo = trim($d['motivo'] ?? '');
  if (!$pid) fail('Informe o ponto');
  if ($motivo === '') fail('Informe o motivo da rejeição');
  db()->prepare("UPDATE pontos SET status='rejeitado', motivo_rejeicao=?, decidido_em=NOW() WHERE id=? AND status='pendente'")
      ->execute([$motivo,$pid]);
  ok();
}

fail('Ação desconhecida: '.$acao, 404);
