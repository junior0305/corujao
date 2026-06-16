<?php
// ============================================================
// importar_csv.php — SINCRONIZA a base de pessoas a partir de um CSV.
//
// A planilha é tratada como FOTO COMPLETA do dia (snapshot): ela descreve
// TODAS as pessoas ativas agora. O importador compara com o banco e aplica
// a diferença, por NOME (a chave única e estável de cada pessoa):
//
//   • nome novo (não existe)         -> CRIA
//   • nome existe, equipe diferente  -> MOVE (preserva todo o histórico)
//   • nome existia inativo, voltou   -> REATIVA
//   • nome sumiu da planilha         -> INATIVA (ativo=0, não apaga nada)
//   • equipe sem ninguém ativo       -> INATIVA a equipe (some das telas)
//
// Com isso, "corretor transferido", "gerente virou corretor" e "equipe
// remanejada pra outro gerente" são todos o MESMO mecanismo.
//
// Formatos aceitos (cabeçalho em qualquer ordem; separador , ou ;):
//   A) uma pessoa por linha:
//        nome,diretoria,superintendencia,gerencia
//   B) legado (lista de corretores numa coluna, separados por ;):
//        diretoria,superintendencia,gerencia,corretores
//   Sem cabeçalho reconhecível, assume a ordem nome,diretoria,superintendencia,gerencia.
//
// Parâmetro "simular" (?simular=1 ou {"simular":true}): calcula o resumo
// mas NÃO grava (faz rollback) — para a recepção conferir antes de aplicar.
// ============================================================
require __DIR__.'/db.php';
exigirStaff(['admin','recepcao']);

$pdo = db();

// garante as colunas novas mesmo se o instalar.php ainda não foi reexecutado
try { $pdo->exec("ALTER TABLE corretores ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE equipes    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}

// --- entrada (arquivo ou texto cru) ---
$d = [];
if (!empty($_FILES['arquivo']['tmp_name'])) {
  $conteudo = file_get_contents($_FILES['arquivo']['tmp_name']);
} else {
  $d = body();
  $conteudo = $d['csv'] ?? '';
}
$simular = !empty($_GET['simular']) || !empty($d['simular']);
if (trim($conteudo) === '') fail('Envie um arquivo CSV ou o texto do CSV');

// remove BOM e normaliza quebras de linha
$conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
$linhas = preg_split('/\r\n|\r|\n/', trim($conteudo));
if (count($linhas) < 1) fail('CSV vazio');

// separador pela 1ª linha
$sep = (substr_count($linhas[0], ';') > substr_count($linhas[0], ',')) ? ';' : ',';

// normaliza nome de coluna (minúsculo, sem acento) para casar o cabeçalho
function chaveCol($s){
  $s = strtolower(trim($s));
  $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i',
                  'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
  return $s;
}

// mapeia índices das colunas pelo cabeçalho (ordem livre)
$cab = array_map('chaveCol', str_getcsv($linhas[0], $sep));
$idx = ['nome'=>-1,'diretoria'=>-1,'superintendencia'=>-1,'gerencia'=>-1,'corretores'=>-1];
foreach ($cab as $i=>$h) { if (array_key_exists($h, $idx)) $idx[$h] = $i; }

$temCabecalho = (bool) array_filter($idx, fn($v)=>$v>=0);
if ($temCabecalho) {
  $inicio = 1; // pula a linha de cabeçalho
} else {
  // sem cabeçalho reconhecível: assume nome,diretoria,superintendencia,gerencia
  $idx = ['nome'=>0,'diretoria'=>1,'superintendencia'=>2,'gerencia'=>3,'corretores'=>-1];
  $inicio = 0;
}
if ($idx['diretoria']<0 || $idx['superintendencia']<0 || $idx['gerencia']<0) {
  fail('O CSV precisa ter as colunas diretoria, superintendencia e gerencia (e nome OU corretores).');
}

// --- monta a lista de pessoas do snapshot ---
$pessoas = []; $erros = []; $nomesVistosCsv = [];
for ($i=$inicio; $i<count($linhas); $i++) {
  $linha = trim($linhas[$i]); if ($linha === '') continue;
  $c = str_getcsv($linha, $sep);
  $dir = trim($c[$idx['diretoria']] ?? '');
  $sup = trim($c[$idx['superintendencia']] ?? '');
  $ger = trim($c[$idx['gerencia']] ?? '');
  if ($dir==='' || $sup==='' || $ger==='') { $erros[]="linha ".($i+1).": diretoria/superintendência/gerência em branco"; continue; }

  $nomes = [];
  if ($idx['nome']>=0 && trim($c[$idx['nome']] ?? '') !== '') $nomes[] = trim($c[$idx['nome']]);
  if ($idx['corretores']>=0) {
    foreach (preg_split('/[;,]/', $c[$idx['corretores']] ?? '') as $n) { $n=trim($n); if ($n!=='') $nomes[]=$n; }
  }
  if (!$nomes) { $erros[]="linha ".($i+1).": sem nome"; continue; }

  foreach ($nomes as $nome) {
    $kl = strtolower($nome);
    if (isset($nomesVistosCsv[$kl])) { $erros[]="nome repetido na planilha (ignorado): ".$nome; continue; }
    $nomesVistosCsv[$kl] = true;
    $pessoas[] = ['nome'=>$nome,'dir'=>$dir,'sup'=>$sup,'ger'=>$ger];
  }
}
if (!$pessoas) fail('Nenhuma pessoa válida no CSV. Confira o cabeçalho e as colunas. Avisos: '.implode('; ',$erros));

// --- sincroniza ---
$pdo->beginTransaction();
try {
  // estado anterior
  $idsAtivosAntes = [];
  foreach ($pdo->query("SELECT id, nome FROM corretores WHERE ativo=1")->fetchAll() as $r) {
    $idsAtivosAntes[(int)$r['id']] = $r['nome'];
  }
  $equipesAtivasAntes = $pdo->query("SELECT id FROM equipes WHERE ativo=1")->fetchAll(PDO::FETCH_COLUMN);

  // resolve (e reativa) a equipe pelo trio diretoria/superintendência/gerência
  $eqCache = [];
  $insEquipe = $pdo->prepare("INSERT INTO equipes (diretoria,superintendencia,gerencia,ativo) VALUES (?,?,?,1)
                              ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), ativo=1");

  $selNome  = $pdo->prepare("SELECT id, equipe_id, ativo FROM corretores WHERE nome=? LIMIT 1");
  $insCor   = $pdo->prepare("INSERT INTO corretores (equipe_id,nome,ativo) VALUES (?,?,1)");
  $updMove  = $pdo->prepare("UPDATE corretores SET equipe_id=?, ativo=1 WHERE id=?");
  $updAtivo = $pdo->prepare("UPDATE corretores SET ativo=1 WHERE id=?");

  $criados=0; $movidos=0; $reativados=0; $vistos=[];

  foreach ($pessoas as $p) {
    $k = $p['dir']."\x1f".$p['sup']."\x1f".$p['ger'];
    if (!isset($eqCache[$k])) {
      $insEquipe->execute([$p['dir'],$p['sup'],$p['ger']]);
      $eqCache[$k] = (int)$pdo->lastInsertId();
    }
    $eid = $eqCache[$k];

    $selNome->execute([$p['nome']]);
    $ex = $selNome->fetch();
    if (!$ex) {
      $insCor->execute([$eid, $p['nome']]);
      $vistos[(int)$pdo->lastInsertId()] = true;
      $criados++;
    } else {
      $cid = (int)$ex['id'];
      if ((int)$ex['equipe_id'] !== $eid) {
        $updMove->execute([$eid, $cid]); $movidos++;
        if ((int)$ex['ativo'] !== 1) $reativados++;
      } elseif ((int)$ex['ativo'] !== 1) {
        $updAtivo->execute([$cid]); $reativados++;
      }
      $vistos[$cid] = true;
    }
  }

  // quem estava ativo e sumiu do snapshot -> inativa (preserva histórico)
  $sumiram = array_diff_key($idsAtivosAntes, $vistos);
  $inativados = count($sumiram);
  if ($sumiram) {
    $ids = array_keys($sumiram);
    foreach (array_chunk($ids, 500) as $lote) {
      $ph = implode(',', array_fill(0, count($lote), '?'));
      $pdo->prepare("UPDATE corretores SET ativo=0 WHERE id IN ($ph)")->execute($lote);
    }
  }

  // equipe fica ativa só se tiver ao menos 1 corretor ativo
  $pdo->exec("UPDATE equipes e SET ativo = EXISTS(SELECT 1 FROM corretores c WHERE c.equipe_id=e.id AND c.ativo=1)");

  $equipesAtivasDepois = $pdo->query("SELECT id FROM equipes WHERE ativo=1")->fetchAll(PDO::FETCH_COLUMN);
  $equipesEsvaziadas = count(array_diff($equipesAtivasAntes, $equipesAtivasDepois));
  $equipesNovas      = count(array_diff($equipesAtivasDepois, $equipesAtivasAntes));

  if ($simular) $pdo->rollBack(); else $pdo->commit();
} catch (Exception $e) {
  $pdo->rollBack();
  fail('Erro ao sincronizar: '.$e->getMessage(), 500);
}

ok([
  'simulado'           => $simular,
  'criados'            => $criados,
  'movidos'            => $movidos,
  'reativados'         => $reativados,
  'inativados'         => $inativados,
  'equipes_novas'      => $equipesNovas,
  'equipes_esvaziadas' => $equipesEsvaziadas,
  'total_pessoas_csv'  => count($pessoas),
  'total_equipes_csv'  => count($eqCache),
  'avisos'             => $erros,
  // --- compatibilidade com o toast antigo da recepção ---
  'equipes_importadas' => count($eqCache),
  'corretores'         => count($pessoas),
]);
