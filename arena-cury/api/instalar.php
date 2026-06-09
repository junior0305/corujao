<?php
// instalar.php — cria/atualiza as tabelas. Pode rodar várias vezes com segurança.
// https://corujao.comandra.com.br/api/instalar.php
require __DIR__.'/db.php';

$schema = @file_get_contents(__DIR__.'/../schema.sql');
if ($schema === false) $schema = @file_get_contents(__DIR__.'/schema.sql');
if ($schema === false || trim($schema) === '') fail('Não encontrei o schema.sql.', 500);

// executa comando a comando, tolerando erros de "já existe" (ex: ALTER de coluna repetida)
$comandos = array_filter(array_map('trim', explode(';', $schema)));
$ok = 0; $ignorados = [];
foreach ($comandos as $cmd) {
  if ($cmd === '' || strpos($cmd, '--') === 0) continue;
  try { db()->exec($cmd); $ok++; }
  catch (Exception $e) {
    $msg = $e->getMessage();
    // ignora erros esperados ao reexecutar (coluna/tabela já existe, duplicada)
    if (stripos($msg,'Duplicate column')!==false || stripos($msg,'already exists')!==false
        || stripos($msg,'Duplicate key')!==false || stripos($msg,'exists')!==false) {
      $ignorados[] = substr($cmd,0,40).'...';
    } else { /* erro real: continua mas registra */ $ignorados[] = 'ERRO: '.$msg; }
  }
}
$tabelas = array_map(fn($r)=>array_values($r)[0], db()->query('SHOW TABLES')->fetchAll());
ok(['mensagem'=>'Schema aplicado.', 'comandos_ok'=>$ok, 'tabelas'=>$tabelas,
    'observacoes'=>$ignorados, 'aviso'=>'Pode apagar este arquivo depois, por segurança.']);
