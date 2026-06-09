<?php
// mesas.php — mesas do salão com ocupação (reservado vs presente)
// Ações: listar | renomear | mapa
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'listar';
$hoje = date('Y-m-d');

if ($acao === 'listar') {
  ok(['mesas' => db()->query('SELECT * FROM mesas ORDER BY ordem, id')->fetchAll()]);
}

if ($acao === 'renomear') {
  $d = body(); $id=(int)($d['mesa_id']??0); $nome=trim($d['nome']??'');
  if (!$id || $nome==='') fail('Informe mesa e nome');
  db()->prepare('UPDATE mesas SET nome=? WHERE id=?')->execute([$nome,$id]);
  ok();
}

// mapa do dia: mesas + equipes alocadas + reservado vs presente
if ($acao === 'mapa') {
  $dia = $_GET['dia'] ?? $hoje;
  $mesas = db()->query('SELECT * FROM mesas ORDER BY ordem, id')->fetchAll();
  foreach ($mesas as &$m) {
    $st = db()->prepare("SELECT r.id, r.equipe_id, r.lugares, r.horario, e.gerencia
                         FROM reservas r LEFT JOIN equipes e ON e.id=r.equipe_id
                         WHERE r.mesa_id=? AND r.dia=? ORDER BY r.horario");
    $st->execute([$m['id'],$dia]);
    $eqs = $st->fetchAll();
    $ocup = 0;
    foreach ($eqs as &$q) {
      $ocup += (int)$q['lugares'];
      // presentes da equipe hoje
      if ($q['equipe_id']) {
        $p = db()->prepare("SELECT COUNT(*) n FROM presencas WHERE equipe_id=? AND dia=?");
        $p->execute([$q['equipe_id'],$dia]);
        $q['presentes'] = (int)$p->fetch()['n'];
      } else $q['presentes'] = 0;
      $q['ociosos'] = max(0, (int)$q['lugares'] - $q['presentes']);
    }
    $m['equipes'] = $eqs;
    $m['ocupados'] = $ocup;
    $m['livres'] = max(0, (int)$m['lugares'] - $ocup);
  }
  ok(['mesas'=>$mesas, 'dia'=>$dia]);
}

fail('Ação desconhecida: '.$acao, 404);
