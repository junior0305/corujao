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
  $equipesComReserva = []; // equipe_id que já tem reserva nesse dia (não realocar)
  foreach ($mesas as &$m) {
    $st = db()->prepare("SELECT r.id, r.equipe_id, r.lugares, r.horario, e.gerencia
                         FROM reservas r LEFT JOIN equipes e ON e.id=r.equipe_id
                         WHERE r.mesa_id=? AND r.dia=? ORDER BY r.horario");
    $st->execute([$m['id'],$dia]);
    $eqs = $st->fetchAll();
    $ocup = 0;
    foreach ($eqs as &$q) {
      $ocup += (int)$q['lugares'];
      if ($q['equipe_id']) {
        $equipesComReserva[(int)$q['equipe_id']] = true;
        $p = db()->prepare("SELECT COUNT(*) n FROM presencas WHERE equipe_id=? AND dia=?");
        $p->execute([$q['equipe_id'],$dia]);
        $q['presentes'] = (int)$p->fetch()['n'];
      } else $q['presentes'] = 0;
      $q['ociosos'] = max(0, (int)$q['lugares'] - $q['presentes']);
      $q['tipo'] = 'reserva';
    }
    unset($q);
    $m['equipes'] = $eqs;
    $m['ocupados'] = $ocup;
    $m['livres'] = max(0, (int)$m['lugares'] - $ocup);
  }
  unset($m);

  // ---- equipes PRESENTES no salão hoje SEM reserva (entraram direto pelo tablet) ----
  // presente = online=1 OU com presença registrada no dia
  $pres = db()->prepare("
    SELECT e.id, e.gerencia, e.superintendencia,
           (SELECT COUNT(*) FROM presencas pr WHERE pr.equipe_id=e.id AND pr.dia=?) AS presentes
    FROM equipes e
    WHERE e.online=1
       OR EXISTS (SELECT 1 FROM presencas pr WHERE pr.equipe_id=e.id AND pr.dia=?)
    ORDER BY e.gerencia");
  $pres->execute([$dia,$dia]);
  $semReserva = [];
  foreach ($pres->fetchAll() as $e) {
    if (isset($equipesComReserva[(int)$e['id']])) continue; // já está numa mesa via reserva
    $semReserva[] = $e;
  }
  // aloca cada equipe presente-sem-reserva na 1ª mesa com lugar livre
  foreach ($semReserva as $e) {
    $lug = max(1, (int)$e['presentes']); // ocupa pelo nº de presentes (mín. 1)
    $alocou = false;
    foreach ($mesas as &$m) {
      if ($m['livres'] >= $lug) {
        $m['equipes'][] = [
          'id' => null, 'equipe_id' => (int)$e['id'], 'gerencia' => $e['gerencia'],
          'lugares' => $lug, 'horario' => '', 'presentes' => (int)$e['presentes'],
          'ociosos' => 0, 'tipo' => 'presenca' // sem reserva, presente no salão
        ];
        $m['ocupados'] += $lug;
        $m['livres'] = max(0, (int)$m['lugares'] - $m['ocupados']);
        $alocou = true; break;
      }
    }
    unset($m);
    // se não coube em nenhuma mesa, ainda assim devolve numa lista à parte
    if (!$alocou) {
      $mesas[0]['equipes'][] = [
        'id'=>null,'equipe_id'=>(int)$e['id'],'gerencia'=>$e['gerencia'],
        'lugares'=>$lug,'horario'=>'','presentes'=>(int)$e['presentes'],
        'ociosos'=>0,'tipo'=>'presenca','sem_mesa'=>true
      ];
    }
  }
  ok(['mesas'=>$mesas, 'dia'=>$dia]);
}

fail('Ação desconhecida: '.$acao, 404);
