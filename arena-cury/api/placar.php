<?php
// placar.php — placar geral (equipes PRESENTES no salão, ordenadas por pontos aprovados)
// "Presente" = equipe online (gerente logado no tablet) OU com presença registrada hoje.
// Retorna também visitas e documentações aprovadas separadas (para a TV)
require __DIR__.'/db.php';
$rows = db()->query("
  SELECT e.id, e.gerencia, e.superintendencia, e.diretoria, e.online,
         COALESCE(SUM(CASE WHEN p.status='aprovado' THEN p.valor END),0) AS pontos,
         COALESCE(SUM(CASE WHEN p.status='aprovado' AND p.tipo='visita' THEN 1 END),0) AS visitas,
         COALESCE(SUM(CASE WHEN p.status='aprovado' AND p.tipo='documentacao' THEN 1 END),0) AS docs
  FROM equipes e
  LEFT JOIN pontos p ON p.equipe_id=e.id
  WHERE e.online=1
     OR EXISTS (SELECT 1 FROM presencas pr WHERE pr.equipe_id=e.id AND pr.dia=CURDATE())
  GROUP BY e.id ORDER BY pontos DESC, e.gerencia")->fetchAll();
ok(['placar' => $rows]);
