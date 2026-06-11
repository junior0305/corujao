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

fail('Ação desconhecida: '.$acao, 404);
