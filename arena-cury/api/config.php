<?php
// config.php — regras da sessão e rodada geral
// Ações: ler | salvar | rodada | start
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'ler';

// garante a coluna rodada_modo (meta|tempo) sem precisar de instalador separado
try { db()->exec("ALTER TABLE config ADD COLUMN rodada_modo VARCHAR(10) NOT NULL DEFAULT 'tempo'"); } catch (Exception $e) {}

if ($acao === 'ler') {
  ok(['config' => db()->query('SELECT * FROM config WHERE id=1')->fetch()]);
}
if ($acao === 'salvar') {
  exigirStaff(['admin','recepcao']);
  $d = body();
  $v = (int)($d['pts_visita'] ?? 1); $dc = (int)($d['pts_documentacao'] ?? 3); $rm = (int)($d['rodada_min'] ?? 120);
  db()->prepare('UPDATE config SET pts_visita=?, pts_documentacao=?, rodada_min=? WHERE id=1')->execute([$v,$dc,$rm]);
  ok();
}
if ($acao === 'rodada') {
  exigirStaff(['admin','recepcao']);
  $d = body(); $st = $d['status'] ?? '';
  if (!in_array($st,['ativa','pausada','encerrada','parada'])) fail('status inválido');
  if ($st === 'ativa') db()->prepare("UPDATE config SET rodada_status='ativa', rodada_inicio=NOW() WHERE id=1")->execute();
  else db()->prepare('UPDATE config SET rodada_status=? WHERE id=1')->execute([$st]);
  emitir('rodada', ['status' => $st]);
  ok();
}
// START da sala: define modo (meta/tempo), valor, e inicia a rodada
if ($acao === 'start') {
  exigirStaff(['admin','recepcao']);
  $d = body();
  $modo = ($d['modo'] ?? 'tempo') === 'meta' ? 'meta' : 'tempo';
  $valor = (int)($d['valor'] ?? 0); // minutos (tempo) ou nº de visitas (meta)
  db()->prepare("UPDATE config SET rodada_modo=?, rodada_min=?, rodada_status='ativa', rodada_inicio=NOW() WHERE id=1")
      ->execute([$modo, $valor]);
  emitir('rodada', ['status'=>'ativa', 'modo'=>$modo, 'valor'=>$valor]);
  ok(['modo'=>$modo, 'valor'=>$valor]);
}
fail('Ação desconhecida: '.$acao, 404);
