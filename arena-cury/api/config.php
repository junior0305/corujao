<?php
// config.php — regras da sessão e rodada geral
// Ações: ler | salvar | rodada (iniciar/pausar/encerrar)
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'ler';

if ($acao === 'ler') {
  ok(['config' => db()->query('SELECT * FROM config WHERE id=1')->fetch()]);
}
if ($acao === 'salvar') {
  $d = body();
  $v = (int)($d['pts_visita'] ?? 1); $dc = (int)($d['pts_documentacao'] ?? 3); $rm = (int)($d['rodada_min'] ?? 120);
  db()->prepare('UPDATE config SET pts_visita=?, pts_documentacao=?, rodada_min=? WHERE id=1')->execute([$v,$dc,$rm]);
  ok();
}
if ($acao === 'rodada') {
  $d = body(); $st = $d['status'] ?? '';
  if (!in_array($st,['ativa','pausada','encerrada','parada'])) fail('status inválido');
  if ($st === 'ativa') db()->prepare("UPDATE config SET rodada_status='ativa', rodada_inicio=NOW() WHERE id=1")->execute();
  else db()->prepare('UPDATE config SET rodada_status=? WHERE id=1')->execute([$st]);
  emitir('rodada', ['status' => $st]);
  ok();
}
fail('Ação desconhecida: '.$acao, 404);
