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
  $d = body();
  $pinCorr = trim((string)($d['corretor_pin'] ?? ''));
  if ($pinCorr !== '') {
    // AVULSO: autentica pelo PIN do corretor (válido só hoje) + trava de rede
    $cq = db()->prepare("SELECT id, equipe_id FROM corretores WHERE pin=? AND pin_dia=CURDATE() AND ativo=1 LIMIT 1");
    $cq->execute([$pinCorr]); $cc = $cq->fetch();
    if (!$cc) { http_response_code(403); echo json_encode(['ok'=>false,'erro'=>'PIN de corretor inválido ou expirado.','codigo_invalido'=>true]); exit; }
    if (!redeOk()) { http_response_code(403); echo json_encode(['ok'=>false,'erro'=>'Fora da rede liberada. Conecte-se ao wi-fi do salão.','rede_invalida'=>true]); exit; }
    $eid = (int)$cc['equipe_id']; $cid = (int)$cc['id'];
  } else {
    exigirCodigo(); // exige o código do dia (anti-brincadeira); desligado se não houver código
    $eid = (int)($d['equipe_id'] ?? 0);
    $cid = !empty($d['corretor_id']) ? (int)$d['corretor_id'] : null;
  }
  $tipo = $d['tipo'] ?? '';
  $foto = $d['foto'] ?? null; // base64
  if (!$eid || !in_array($tipo,['visita','documentacao'])) fail('Dados inválidos');
  if (!$foto) fail('A foto do comprovante é obrigatória');
  $valor = valorDoTipo($tipo);
  // Qual combate este ponto alimenta? Prioriza o 1×1 DESTE corretor; senão, o duelo da equipe.
  // (O ponto SEMPRE conta no total geral da equipe — a linha em `pontos` tem equipe_id; isto é só o placar DENTRO do combate.)
  $duelo_id = null; $por_corretor = false;
  if ($cid) {
    $q = db()->prepare("SELECT d.id FROM duelos d JOIN duelo_participantes p ON p.duelo_id=d.id
                        WHERE d.status='ativo' AND p.equipe_id=? AND p.corretor_id=? LIMIT 1");
    $q->execute([$eid,$cid]); $r = $q->fetch();
    if ($r) { $duelo_id = (int)$r['id']; $por_corretor = true; }
  }
  if (!$duelo_id) {
    $q = db()->prepare("SELECT d.id FROM duelos d JOIN duelo_participantes p ON p.duelo_id=d.id
                        WHERE d.status='ativo' AND p.equipe_id=? AND p.corretor_id IS NULL LIMIT 1");
    $q->execute([$eid]); $r = $q->fetch();
    if ($r) $duelo_id = (int)$r['id'];
  }
  // Mecânica de contestação: o ponto VALE NA HORA (entra como aprovado).
  // A fiscalização é feita depois pelos adversários (contestação), não por aprovação prévia.
  $ins = db()->prepare("INSERT INTO pontos (equipe_id,corretor_id,tipo,valor,foto,duelo_id,status,decidido_em)
                        VALUES (?,?,?,?,?,?, 'aprovado', NOW())");
  $ins->execute([$eid,$cid,$tipo,$valor,$foto,$duelo_id]);
  $pid = db()->lastInsertId();
  // soma no placar do combate na hora: por corretor (1×1) ou por equipe (duelo de gerência)
  if ($duelo_id && $por_corretor) {
    db()->prepare('UPDATE duelo_participantes SET pontos=pontos+? WHERE duelo_id=? AND corretor_id=?')
        ->execute([$valor,$duelo_id,$cid]);
  } elseif ($duelo_id) {
    db()->prepare('UPDATE duelo_participantes SET pontos=pontos+? WHERE duelo_id=? AND equipe_id=? AND corretor_id IS NULL')
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
  exigirStaff(['admin','recepcao']);
  $d = body(); $pid = (int)($d['ponto_id'] ?? 0);
  if (!$pid) fail('Informe o ponto');
  $p = db()->prepare('SELECT * FROM pontos WHERE id=? AND status="pendente"');
  $p->execute([$pid]); $ponto = $p->fetch();
  if (!$ponto) fail('Pendência não encontrada');
  db()->prepare("UPDATE pontos SET status='aprovado', decidido_em=NOW() WHERE id=?")->execute([$pid]);

  // se há duelo ativo, soma no placar do duelo (por corretor no 1×1; senão por equipe)
  if ($ponto['duelo_id']) {
    $ehCorr = false;
    if (!empty($ponto['corretor_id'])) {
      $chk = db()->prepare('SELECT 1 FROM duelo_participantes WHERE duelo_id=? AND corretor_id=? LIMIT 1');
      $chk->execute([$ponto['duelo_id'],$ponto['corretor_id']]); $ehCorr = (bool)$chk->fetch();
    }
    if ($ehCorr) {
      db()->prepare('UPDATE duelo_participantes SET pontos=pontos+? WHERE duelo_id=? AND corretor_id=?')
          ->execute([$ponto['valor'],$ponto['duelo_id'],$ponto['corretor_id']]);
    } else {
      db()->prepare('UPDATE duelo_participantes SET pontos=pontos+? WHERE duelo_id=? AND equipe_id=? AND corretor_id IS NULL')
          ->execute([$ponto['valor'],$ponto['duelo_id'],$ponto['equipe_id']]);
    }
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
  exigirStaff(['admin','recepcao']);
  $d = body(); $pid = (int)($d['ponto_id'] ?? 0); $motivo = trim($d['motivo'] ?? '');
  if (!$pid) fail('Informe o ponto');
  if ($motivo === '') fail('Informe o motivo da rejeição');
  db()->prepare("UPDATE pontos SET status='rejeitado', motivo_rejeicao=?, decidido_em=NOW() WHERE id=? AND status='pendente'")
      ->execute([$motivo,$pid]);
  ok();
}

if ($acao === 'por_equipe') {
  // contagem de visitas/docs por corretor na sessão atual (p/ o tablet remontar após reload)
  $eid = (int)($_GET['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $ini = db()->query("SELECT sessao_inicio FROM config WHERE id=1")->fetch()['sessao_inicio'] ?? null;
  $sql = "SELECT corretor_id,
                 SUM(tipo='visita') AS visitas,
                 SUM(tipo='documentacao') AS docs
          FROM pontos
          WHERE equipe_id=? AND status='aprovado' AND criado_em >= ?
          GROUP BY corretor_id";
  $st = db()->prepare($sql);
  $st->execute([$eid, $ini ?: date('Y-m-d 00:00:00')]);
  ok(['pontos' => $st->fetchAll()]);
}

fail('Ação desconhecida: '.$acao, 404);
