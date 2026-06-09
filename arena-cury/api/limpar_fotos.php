<?php
// ============================================================
// limpar_fotos.php — remove fotos (base64) dos pontos p/ aliviar o banco.
// O ponto e o placar permanecem; apenas a imagem é apagada (foto=NULL).
//
//   ?acao=antigas  (padrão) → apaga só as fotos de pontos com MAIS de 24h
//   ?acao=todas              → apaga TODAS as fotos já gravadas (limpeza inicial)
//
// Use ?acao=todas uma vez para limpar o acúmulo atual. Depois disso, a
// limpeza das +24h roda sozinha (ver limparFotosAntigas() em db.php).
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'antigas';

if ($acao === 'todas') {
  $n = db()->exec("UPDATE pontos SET foto=NULL WHERE foto IS NOT NULL");
  ok(['removidas' => (int)$n, 'modo' => 'todas']);
}

// padrão: só as antigas (> 24h)
$n = db()->exec("UPDATE pontos SET foto=NULL
                 WHERE foto IS NOT NULL AND criado_em < (NOW() - INTERVAL 24 HOUR)");
ok(['removidas' => (int)$n, 'modo' => 'antigas']);
