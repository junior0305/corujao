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

// cria as colunas sob demanda (mesma técnica do config.php), tolerando "já existe"
try { db()->exec("ALTER TABLE config ADD COLUMN codigo_acesso VARCHAR(40) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE config ADD COLUMN rede_liberada VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE config ADD COLUMN exigir_pin TINYINT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE config ADD COLUMN exigir_login TINYINT NOT NULL DEFAULT 0"); } catch (Exception $e) {}

$atual = codigoConfigurado();

if ($acao === 'status') {
  $rede = redeLiberada();
  ok(['ativo' => $atual !== '', 'rede_ativa' => $rede !== '', 'rede' => $rede,
      'exigir_pin' => exigirPinLigado(), 'exigir_login' => exigirLoginLigado()]);
}

if ($acao === 'exigir_pin') {
  exigirStaff(['admin','recepcao']);
  // liga/desliga a exigência do PIN por equipe
  $d = body();
  $on = !empty($d['ligado']) ? 1 : 0;
  db()->prepare("UPDATE config SET exigir_pin=? WHERE id=1")->execute([$on]);
  ok(['exigir_pin' => $on === 1]);
}

if ($acao === 'encerrar_dia') {
  // zera o estado vivo agora (online, duelos, presenças) e abre nova sessão. Histórico preservado.
  exigirStaff(['admin','recepcao']);
  encerrarDia();
  ok(['encerrado' => true]);
}

if ($acao === 'exigir_login') {
  // liga/desliga a exigência de login na recepção
  $d = body();
  $on = !empty($d['ligado']) ? 1 : 0;
  if ($on) {
    // precisa existir um ADMIN, senão tranca todo mundo do lado de fora
    $temAdmin = false;
    try { $temAdmin = (bool)db()->query("SELECT 1 FROM usuarios WHERE papel='admin' LIMIT 1")->fetch(); } catch (Exception $e) {}
    if (!$temAdmin) fail('Crie um usuário ADMIN antes de exigir login.', 400);
  }
  if (exigirLoginLigado()) exigirStaff(['admin']); // já ligado: só admin muda
  db()->prepare("UPDATE config SET exigir_login=? WHERE id=1")->execute([$on]);
  ok(['exigir_login' => $on === 1]);
}

if ($acao === 'definir_rede') {
  exigirStaff(['admin','recepcao']);
  // define os IPs liberados ('' = desliga). Se há código, exige o atual para mudar.
  $d = body();
  $ips  = trim((string)($d['ips'] ?? ''));
  $conf = trim((string)($d['atual'] ?? ''));
  if ($atual !== '' && !hash_equals($atual, $conf)) {
    fail('Para mudar a rede liberada é preciso informar o código atual.', 403);
  }
  db()->prepare("UPDATE config SET rede_liberada=? WHERE id=1")->execute([$ips]);
  ok(['rede' => $ips, 'rede_ativa' => $ips !== '']);
}

// diagnóstico de rede: qual IP o servidor enxerga deste aparelho (p/ futura blindagem por rede)
if ($acao === 'meu_ip') {
  ok([
    'ip'     => ipCliente(),
    'xff'    => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
    'remote' => $_SERVER['REMOTE_ADDR'] ?? null,
  ]);
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
