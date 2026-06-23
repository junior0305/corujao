<?php
// ============================================================
// pins.php — PIN diário por equipe (gerado na recepção, usado no tablet).
// Cada equipe tem um PIN que vale só HOJE (pin_dia). Expira no dia seguinte.
//
//   ?acao=gerar     {equipe_id}       → gera/regenera o PIN de hoje, retorna pin
//   ?acao=ver       &equipe_id=       → retorna o PIN de hoje (ou null se expirou/não há)
//   ?acao=verificar {equipe_id, pin}  → { valido: bool }
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? '';

// cria as colunas sob demanda (tolerando "já existe")
try { db()->exec("ALTER TABLE equipes ADD COLUMN pin VARCHAR(10) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE equipes ADD COLUMN pin_dia DATE NULL"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE corretores ADD COLUMN pin VARCHAR(10) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE corretores ADD COLUMN pin_dia DATE NULL"); } catch (Exception $e) {}

// PIN de 4 dígitos, sem colidir com outra equipe HOJE
function gerarPinUnico() {
  for ($i = 0; $i < 40; $i++) {
    $p = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $c = db()->prepare("SELECT 1 FROM equipes WHERE pin=? AND pin_dia=CURDATE() LIMIT 1");
    $c->execute([$p]);
    if (!$c->fetch()) return $p;
  }
  return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

if ($acao === 'gerar') {
  exigirStaff(['admin','recepcao']);
  $d = body(); $eid = (int)($d['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $pin = gerarPinUnico();
  db()->prepare("UPDATE equipes SET pin=?, pin_dia=CURDATE() WHERE id=?")->execute([$pin, $eid]);
  ok(['pin' => $pin]);
}

if ($acao === 'ver') {
  $eid = (int)($_GET['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $st = db()->prepare("SELECT pin FROM equipes WHERE id=? AND pin_dia=CURDATE()");
  $st->execute([$eid]); $row = $st->fetch();
  ok(['pin' => ($row && $row['pin'] !== '') ? $row['pin'] : null]);
}

if ($acao === 'verificar') {
  $d = body(); $eid = (int)($d['equipe_id'] ?? 0); $pin = trim((string)($d['pin'] ?? ''));
  ok(['valido' => pinValido($pin, $eid)]);
}

// resolve um PIN para a equipe correspondente (PIN é único por equipe hoje).
// É assim que o tablet "descobre" a equipe pelo PIN e cai direto na tela do gerente.
if ($acao === 'resolver') {
  $d = body(); $pin = trim((string)($d['pin'] ?? ''));
  if ($pin === '') { ok(['equipe' => null]); }
  $st = db()->prepare("SELECT id, diretoria, superintendencia, gerencia FROM equipes WHERE pin=? AND pin_dia=CURDATE() LIMIT 1");
  $st->execute([$pin]);
  ok(['equipe' => $st->fetch() ?: null]);
}

// ===== PIN do CORRETOR avulso (5 dígitos, único entre corretores hoje) =====
function gerarPinCorretorUnico() {
  for ($i = 0; $i < 60; $i++) {
    $p = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    $c = db()->prepare("SELECT 1 FROM corretores WHERE pin=? AND pin_dia=CURDATE() LIMIT 1");
    $c->execute([$p]);
    if (!$c->fetch()) return $p;
  }
  return str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
}

if ($acao === 'gerar_corretor') {
  exigirStaff(['admin','recepcao']);
  $d = body(); $cid = (int)($d['corretor_id'] ?? 0);
  if (!$cid) fail('Informe o corretor');
  $pin = gerarPinCorretorUnico();
  db()->prepare("UPDATE corretores SET pin=?, pin_dia=CURDATE() WHERE id=?")->execute([$pin, $cid]);
  $r = db()->prepare("SELECT nome FROM corretores WHERE id=?"); $r->execute([$cid]);
  ok(['pin' => $pin, 'nome' => $r->fetch()['nome'] ?? '']);
}

if ($acao === 'ver_corretor') {
  $cid = (int)($_GET['corretor_id'] ?? 0);
  if (!$cid) fail('Informe o corretor');
  $st = db()->prepare("SELECT pin FROM corretores WHERE id=? AND pin_dia=CURDATE()");
  $st->execute([$cid]); $row = $st->fetch();
  ok(['pin' => ($row && $row['pin'] !== '') ? $row['pin'] : null]);
}

if ($acao === 'revogar_corretor') {
  exigirStaff(['admin','recepcao']);
  $d = body(); $cid = (int)($d['corretor_id'] ?? 0);
  if (!$cid) fail('Informe o corretor');
  db()->prepare("UPDATE corretores SET pin='', pin_dia=NULL WHERE id=?")->execute([$cid]);
  ok();
}

// resolve o PIN do corretor -> identifica corretor + equipe, marca presença e põe a equipe online
if ($acao === 'resolver_corretor') {
  $d = body(); $pin = trim((string)($d['pin'] ?? ''));
  if ($pin === '') { ok(['corretor' => null]); }
  $st = db()->prepare("SELECT c.id, c.nome, c.equipe_id, e.diretoria, e.superintendencia, e.gerencia
                       FROM corretores c JOIN equipes e ON e.id=c.equipe_id
                       WHERE c.pin=? AND c.pin_dia=CURDATE() AND c.ativo=1 LIMIT 1");
  $st->execute([$pin]); $corr = $st->fetch();
  if (!$corr) { ok(['corretor' => null]); }
  // login = presença + equipe online (idempotente)
  try { db()->prepare("INSERT IGNORE INTO presencas (corretor_id,equipe_id,dia) VALUES (?,?,CURDATE())")->execute([$corr['id'],$corr['equipe_id']]); } catch (Exception $e) {}
  try { db()->prepare("UPDATE equipes SET online=1 WHERE id=?")->execute([$corr['equipe_id']]); } catch (Exception $e) {}
  ok(['corretor' => $corr]);
}

fail('Ação desconhecida: '.$acao, 404);
