<?php
// ============================================================
// importar_csv.php — alimenta a base de equipes a partir de um CSV
// Aceita: upload de arquivo (campo "arquivo") OU texto cru no corpo JSON ("csv")
// Formato esperado (com cabeçalho):
//   diretoria,superintendencia,gerencia,corretores
//   Diretoria Alfa,Sup. Centauro,Ger. Órion,Lucas;Marina;Pedro;Bia
// (corretores separados por ; )  — aceita também separador , ou ;
// ============================================================
require __DIR__.'/db.php';
exigirStaff(['admin','recepcao']);

$conteudo = '';
if (!empty($_FILES['arquivo']['tmp_name'])) {
  $conteudo = file_get_contents($_FILES['arquivo']['tmp_name']);
} else {
  $d = body();
  $conteudo = $d['csv'] ?? '';
}
if (trim($conteudo) === '') fail('Envie um arquivo CSV ou o texto do CSV');

// remove BOM e normaliza quebras de linha
$conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
$linhas = preg_split('/\r\n|\r|\n/', trim($conteudo));
if (count($linhas) < 2) fail('CSV vazio ou só com cabeçalho');

// detecta separador (vírgula ou ponto-e-vírgula) pela 1ª linha
$sep = (substr_count($linhas[0], ';') > substr_count($linhas[0], ',')) ? ';' : ',';

$criadas = 0; $corretoresTot = 0; $erros = [];
$pdo = db();
$pdo->beginTransaction();
try {
  for ($i = 1; $i < count($linhas); $i++) {
    $linha = trim($linhas[$i]);
    if ($linha === '') continue;
    $cols = str_getcsv($linha, $sep);
    $dir = trim($cols[0] ?? ''); $sup = trim($cols[1] ?? ''); $ger = trim($cols[2] ?? '');
    if ($dir === '' || $sup === '' || $ger === '') { $erros[] = "linha ".($i+1)." incompleta"; continue; }

    $st = $pdo->prepare('INSERT INTO equipes (diretoria,superintendencia,gerencia) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
    $st->execute([$dir,$sup,$ger]);
    $eid = $pdo->lastInsertId();
    $criadas++;

    // corretores podem vir separados por ; mesmo quando o CSV usa , como separador de coluna
    $rawCorr = $cols[3] ?? '';
    $nomes = preg_split('/[;,]/', $rawCorr);
    foreach ($nomes as $nome) {
      $nome = trim($nome); if ($nome === '') continue;
      // evita duplicar corretor com mesmo nome na mesma equipe
      $chk = $pdo->prepare('SELECT id FROM corretores WHERE equipe_id=? AND nome=?');
      $chk->execute([$eid,$nome]);
      if ($chk->fetch()) continue;
      $pdo->prepare('INSERT INTO corretores (equipe_id,nome) VALUES (?,?)')->execute([$eid,$nome]);
      $corretoresTot++;
    }
  }
  $pdo->commit();
} catch (Exception $e) {
  $pdo->rollBack();
  fail('Erro ao importar: '.$e->getMessage(), 500);
}

ok(['equipes_importadas' => $criadas, 'corretores' => $corretoresTot, 'avisos' => $erros]);
