<?php
// mesas.php — mesas do salão com ocupação (reservado vs presente)
// Ações: listar | renomear | mapa | alocar_presenca
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'listar';
$hoje = date('Y-m-d');

// alocação manual de equipes PRESENTES (sem reserva) a uma mesa, por dia
db()->exec("CREATE TABLE IF NOT EXISTS alocacoes_presenca (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipe_id INT NOT NULL,
  mesa_id INT NOT NULL,
  dia DATE NOT NULL,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_aloc (equipe_id, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// mover (ou fixar) uma equipe presente-sem-reserva para uma mesa específica
if ($acao === 'alocar_presenca') {
  $d = body(); $eid=(int)($d['equipe_id']??0); $mesa=(int)($d['mesa_id']??0);
  $dia = $d['dia'] ?? $hoje;
  if (!$eid || !$mesa) fail('Dados inválidos');
  db()->prepare("INSERT INTO alocacoes_presenca (equipe_id,mesa_id,dia) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE mesa_id=VALUES(mesa_id)")->execute([$eid,$mesa,$dia]);
  ok();
}

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
  // aloca cada equipe presente-sem-reserva: respeita alocação MANUAL; senão, automática
  // carrega alocações manuais do dia
  $alq = db()->prepare("SELECT equipe_id, mesa_id FROM alocacoes_presenca WHERE dia=?");
  $alq->execute([$dia]);
  $manual = [];
  foreach ($alq->fetchAll() as $a) $manual[(int)$a['equipe_id']] = (int)$a['mesa_id'];

  foreach ($semReserva as $e) {
    $lug = max(1, (int)$e['presentes']); // ocupa pelo nº de presentes (mín. 1)
    $eid = (int)$e['id'];
    $card = [
      'id' => null, 'equipe_id' => $eid, 'gerencia' => $e['gerencia'],
      'lugares' => $lug, 'horario' => '', 'presentes' => (int)$e['presentes'],
      'ociosos' => 0, 'tipo' => 'presenca'
    ];
    $alvo = null;
    // 1) alocação manual escolhida pela recepção
    if (isset($manual[$eid])) {
      foreach ($mesas as $idx=>$m) if ((int)$m['id']===$manual[$eid]) { $alvo=$idx; break; }
    }
    // 2) automática: 1ª mesa com lugar livre
    if ($alvo===null) {
      foreach ($mesas as $idx=>$m) if ($m['livres'] >= $lug) { $alvo=$idx; break; }
    }
    if ($alvo!==null) {
      $mesas[$alvo]['equipes'][] = $card;
      $mesas[$alvo]['ocupados'] += $lug;
      $mesas[$alvo]['livres'] = max(0, (int)$mesas[$alvo]['lugares'] - $mesas[$alvo]['ocupados']);
    } else {
      // não coube em nenhuma → mostra na 1ª mesa marcada como sem_mesa
      $card['sem_mesa'] = true;
      $mesas[0]['equipes'][] = $card;
    }
  }
  ok(['mesas'=>$mesas, 'dia'=>$dia]);
}

fail('Ação desconhecida: '.$acao, 404);
