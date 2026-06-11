<?php
// ============================================================
// acesso.php — código do dia do tablet (evita que gente de fora
// fique "brincando" de marcar ponto / desafiar sem estar no local).
//
//   ?acao=status                 → { ok, ativo:bool }  (há código definido?)
//   ?acao=verificar  {codigo}     → { ok, valido:bool } (o tablet confere no login)
//   ?acao=definir    {codigo,atual}→ define/troca o código (recepção)
//
// Para TROCAR um código já existente é preciso informar o atual (`atual`).
// Para DEFINIR pela 1ª vez (nenhum código) não precisa do atual.
// Código vazio em `definir` => DESLIGA o bloqueio (volta a ficar aberto).
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'status';

// cria a coluna sob demanda (mesma técnica do config.php), tolerando "já existe"
try { db()->exec("ALTER TABLE config ADD COLUMN codigo_acesso VARCHAR(40) NOT NULL DEFAULT ''"); } catch (Exception $e) {}

$atual = codigoConfigurado();

if ($acao === 'status') {
  ok(['ativo' => $atual !== '']);
}

if ($acao === 'verificar') {
  $d = body();
  $cod = trim((string)($d['codigo'] ?? ''));
  // sem código definido => qualquer um passa (bloqueio desligado)
  $valido = ($atual === '') || ($cod !== '' && hash_equals($atual, $cod));
  ok(['valido' => $valido, 'ativo' => $atual !== '']);
}

if ($acao === 'definir') {
  $d = body();
  $novo = trim((string)($d['codigo'] ?? ''));
  $conf = trim((string)($d['atual'] ?? ''));
  // se já existe um código, exige o atual para trocar/desligar
  if ($atual !== '' && !hash_equals($atual, $conf)) {
    fail('Para trocar o código é preciso informar o código atual.', 403);
  }
  db()->prepare("UPDATE config SET codigo_acesso=? WHERE id=1")->execute([$novo]);
  ok(['ativo' => $novo !== '', 'codigo' => $novo]);
}

fail('Ação desconhecida: '.$acao, 404);
