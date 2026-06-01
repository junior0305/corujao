<?php
// placar.php — placar geral (equipes ordenadas por pontos aprovados) + online
require __DIR__.'/db.php';
$rows = db()->query("SELECT e.id, e.gerencia, e.superintendencia, e.diretoria, e.online,
                            COALESCE(SUM(CASE WHEN p.status='aprovado' THEN p.valor END),0) AS pontos
                     FROM equipes e LEFT JOIN pontos p ON p.equipe_id=e.id
                     GROUP BY e.id ORDER BY pontos DESC, e.gerencia")->fetchAll();
ok(['placar' => $rows]);
