# Arena Cury — Sistema completo (telas + banco)

Sistema de prospecção gamificada da Cury. Roda em PHP + MySQL via Docker/Portainer.

## O que tem aqui
```
arena/
  docker-compose.yml      ← sobe o site (PHP) + o banco (MySQL)
  schema.sql              ← cria as tabelas (roda sozinho na 1ª vez)
  exemplo-equipes.csv     ← modelo de CSV para carregar a base
  public/                 ← as telas (o que abre no navegador)
    index.html            ← menu inicial
    tv.html               ← telão
    tablet.html           ← tablet do gerente
    aprovacao.html        ← painel de aprovação
    recepcao.html         ← recepção (reservas, equipes, regras, controle)
    arena-api.js          ← ponte das telas com a API
  api/                    ← o "cérebro" em PHP (fala com o banco)
    db.php, equipes.php, pontos.php, duelos.php, reservas.php,
    config.php, eventos.php, placar.php, importar_csv.php, teste.php
```

## Como subir no Portainer

### Opção A — usar o banco que já vem no compose (mais simples)
1. No Portainer, vá em **Stacks → Add stack**.
2. Dê um nome (ex: `arena`).
3. Em **Web editor**, cole o conteúdo do `docker-compose.yml`.
4. Você precisa que os arquivos (`public/`, `api/`, `schema.sql`) estejam acessíveis
   ao Portainer. O jeito mais fácil: suba este projeto para um repositório no GitHub
   e, no Portainer, use **Repository** em vez de Web editor, apontando para o repo.
5. **Deploy the stack**.
6. Acesse `http://IP-DO-SERVIDOR:8080` — o menu da Arena aparece.
7. Confira a conexão em `http://IP-DO-SERVIDOR:8080/api/teste.php`
   (deve listar as tabelas criadas).

### Opção B — você já tem o MySQL "corujao" rodando
1. No `docker-compose.yml`, **remova** o serviço `arena-mysql` e o volume `arena_db`.
2. Em `arena-web`, troque `DB_HOST` pelo nome do serviço do seu MySQL (ou o IP/rede).
3. Rode o `schema.sql` uma vez no seu banco (pelo Adminer/phpMyAdmin ou linha de comando)
   para criar as tabelas.
4. Deploy.

## Primeiro uso — carregar a base de equipes
1. Abra **Recepção** → aba **Equipes**.
2. Clique em **Importar CSV** e selecione um arquivo no formato do `exemplo-equipes.csv`:
   ```
   diretoria,superintendencia,gerencia,corretores
   Diretoria Alfa,Sup. Centauro,Ger. Órion,Lucas;Marina;Pedro;Bia
   ```
   (os corretores vão separados por ponto-e-vírgula `;`)
3. As equipes ficam salvas no banco — aparecem no tablet e na TV.
   Corretores adicionados depois pelo "cadastro rápido" também ficam salvos.

## Estado atual da integração
- **Recepção (Equipes):** já conectada ao banco — importar CSV e listar equipes salvam/leem de verdade.
- **Demais telas (TV, Tablet, Aprovação) e a API:** prontas. As telas ainda estão sendo
  ligadas à API uma a uma; enquanto isso, funcionam em "modo local" (dados de exemplo)
  para testes visuais. A API já está completa e testável pelos endpoints.

## Câmera nos tablets
Funciona em `localhost` e em **HTTPS**. Para os tablets acessarem pela rede (pelo IP),
use HTTPS — no Portainer dá para pôr um proxy (Caddy/Nginx) com certificado.
Em HTTP puro por IP, alguns navegadores bloqueiam a câmera.

## Segurança
As senhas do banco estão no `docker-compose.yml`. Não publique este arquivo
em repositório público com as senhas reais — use variáveis de ambiente do Portainer
ou um repositório privado.
