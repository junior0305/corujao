<?php
// instalar.php — abra UMA VEZ no navegador para criar as tabelas no banco.
// https://corujao.comandra.com.br/api/instalar.php
// Depois de rodar com sucesso, apague este arquivo do repositório por segurança.
require __DIR__.'/db.php';

$schema = @file_get_contents(__DIR__.'/../schema.sql');
if ($schema === false) {
  // tenta na mesma pasta, caso a estrutura seja plana
  $schema = @file_get_contents(__DIR__.'/schema.sql');
}
if ($schema === false || trim($schema) === '') {
  fail('Não encontrei o schema.sql. Coloque-o na raiz do projeto.', 500);
}

try {
  // executa todos os comandos do schema
  db()->exec($schema);
  $tabelas = array_map(fn($r)=>array_values($r)[0], db()->query('SHOW TABLES')->fetchAll());
  ok(['mensagem' => 'Tabelas criadas com sucesso!', 'tabelas' => $tabelas,
      'aviso' => 'Apague este arquivo (instalar.php) do repositório por segurança.']);
} catch (Exception $e) {
  fail('Erro ao criar tabelas: '.$e->getMessage(), 500);
}
