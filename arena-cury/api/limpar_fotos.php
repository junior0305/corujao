<?php
// ============================================================
// limpar_fotos.php — manutenção do banco (chamado pela TV a cada 30 min,
// ou manualmente). Remove fotos pesadas e poda a fila de eventos antiga.
// O ponto e o placar permanecem; apenas a imagem é apagada (foto=NULL).
//
//   ?acao=antigas  (padrão) → apaga fotos de pontos com MAIS de 24h
//   ?acao=todas              → apaga TODAS as fotos já gravadas (limpeza inicial)
//
// Sempre poda eventos com +2 dias (a TV só lê eventos novos).
// Use ?acao=todas UMA vez para limpar o acúmulo atual de fotos.
// ============================================================
require __DIR__.'/db.php';
$acao = $_GET['acao'] ?? 'antigas';

if ($acao === 'todas') {
  $n = db()->exec("UPDATE pontos SET foto=NULL WHERE foto IS NOT NULL");
} else {
  $n = db()->exec("UPDATE pontos SET foto=NULL
                   WHERE foto IS NOT NULL AND criado_em < (NOW() - INTERVAL 24 HOUR)");
}

// poda a fila de eventos antiga — mantém a tabela enxuta
$ev = db()->exec("DELETE FROM eventos WHERE criado_em < (NOW() - INTERVAL 2 DAY)");

ok(['removidas' => (int)$n, 'eventos_podados' => (int)$ev, 'modo' => $acao]);
