<?php
// ============================================================
// equipes.php — gerencia equipes e corretores
// Ações (via ?acao=): listar | criar | remover | reset | online
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'listar';

// garante a coluna "ativo" (soft delete) mesmo em bancos antigos
try { db()->exec("ALTER TABLE corretores ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
try { db()->exec("ALTER TABLE equipes    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}

if ($acao === 'listar') {
  // por padrão só equipes/corretores ATIVOS; ?incluir_inativos=1 traz todos
  $incInativos = !empty($_GET['incluir_inativos']);
  $filtroEq  = $incInativos ? '' : 'WHERE ativo=1';
  $filtroCor = $incInativos ? '' : 'AND ativo=1';
  $equipes = db()->query("SELECT * FROM equipes $filtroEq ORDER BY diretoria, superintendencia, gerencia")->fetchAll();
  foreach ($equipes as &$e) {
    $st = db()->prepare("SELECT id, nome FROM corretores WHERE equipe_id = ? $filtroCor ORDER BY nome");
    $st->execute([$e['id']]);
    $e['corretores'] = $st->fetchAll();
    // pontuação total aprovada da equipe
    $st = db()->prepare("SELECT COALESCE(SUM(valor),0) tot FROM pontos WHERE equipe_id=? AND status='aprovado'");
    $st->execute([$e['id']]);
    $e['pontos'] = (int)$st->fetch()['tot'];
  }
  ok(['equipes' => $equipes]);
}

if ($acao === 'criar') {
  exigirStaff(['admin','recepcao']);
  $d = body();
  $dir = trim($d['diretoria'] ?? ''); $sup = trim($d['superintendencia'] ?? ''); $ger = trim($d['gerencia'] ?? '');
  if ($dir==='' || $sup==='' || $ger==='') fail('Preencha diretoria, superintendência e gerência');
  $st = db()->prepare('INSERT INTO equipes (diretoria,superintendencia,gerencia) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
  $st->execute([$dir,$sup,$ger]);
  $eid = db()->lastInsertId();
  foreach (($d['corretores'] ?? []) as $nome) {
    $nome = trim($nome); if ($nome==='') continue;
    $c = db()->prepare('INSERT INTO corretores (equipe_id,nome) VALUES (?,?)');
    $c->execute([$eid,$nome]);
  }
  ok(['id' => $eid]);
}

if ($acao === 'add_corretor') {
  exigirCodigo();
  $d = body();
  $eid = (int)($d['equipe_id'] ?? 0); $nome = trim($d['nome'] ?? '');
  if (!$eid || $nome==='') fail('Informe equipe e nome');
  // cadastro rápido: PERSISTE na base para a próxima vez
  $c = db()->prepare('INSERT INTO corretores (equipe_id,nome) VALUES (?,?)');
  $c->execute([$eid,$nome]);
  ok(['id' => db()->lastInsertId()]);
}

if ($acao === 'remover') {
  exigirStaff(['admin','recepcao']);
  $d = body(); $eid = (int)($d['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $st = db()->prepare('DELETE FROM equipes WHERE id=?'); // cascata apaga corretores e pontos
  $st->execute([$eid]);
  ok();
}

if ($acao === 'reset') {
  exigirStaff(['admin','recepcao']);
  // reset total: zera pontos, tira online, encerra duelos da equipe
  $d = body(); $eid = (int)($d['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  db()->prepare('DELETE FROM pontos WHERE equipe_id=?')->execute([$eid]);
  db()->prepare('UPDATE equipes SET online=0 WHERE id=?')->execute([$eid]);
  db()->prepare("UPDATE duelos d JOIN duelo_participantes p ON p.duelo_id=d.id
                 SET d.status='encerrado', d.encerrado_em=NOW()
                 WHERE p.equipe_id=? AND d.status IN ('aguardando','ativo')")->execute([$eid]);
  ok();
}

if ($acao === 'online') {
  exigirCodigo();
  // ativa/desativa equipe na arena (logon do tablet)
  $d = body(); $eid = (int)($d['equipe_id'] ?? 0); $on = !empty($d['online']) ? 1 : 0;
  if (!$eid) fail('Informe a equipe');
  db()->prepare('UPDATE equipes SET online=? WHERE id=?')->execute([$on,$eid]);
  ok();
}

if ($acao === 'tirar_da_sala') {
  exigirStaff(['admin','recepcao']);
  // remove a equipe do salão HOJE: tira online, apaga presenças do dia e a alocação manual
  $d = body(); $eid = (int)($d['equipe_id'] ?? 0);
  if (!$eid) fail('Informe a equipe');
  $hoje = date('Y-m-d');
  db()->prepare('UPDATE equipes SET online=0 WHERE id=?')->execute([$eid]);
  db()->prepare('DELETE FROM presencas WHERE equipe_id=? AND dia=?')->execute([$eid,$hoje]);
  try { db()->prepare('DELETE FROM alocacoes_presenca WHERE equipe_id=? AND dia=?')->execute([$eid,$hoje]); } catch (Exception $e) {}
  ok();
}

fail('Ação desconhecida: '.$acao, 404);
