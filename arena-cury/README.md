# Arena Cury — Tela da TV

Sistema de gamificação de prospecção da Cury (versão leve, arquivos separados).

## Estrutura
```
arena-cury/
  index.html        ← a tela (abre no navegador / TV)
  img/              ← imagens (logo, astronauta, encarada)
  sons/             ← efeitos sonoros (sino, explosão, documentação)
```

## Como subir no GitHub e rodar na TV

1. No GitHub, crie um repositório novo (ex: `arena-cury`).
2. Na página do repositório, clique em **Add file → Upload files**.
3. Arraste a pasta inteira (o `index.html` e as pastas `img` e `sons`) para a área de upload.
   - Importante: mantenha as pastas `img` e `sons` junto do `index.html`.
4. Escreva uma mensagem de commit e clique em **Commit changes**.

### Ativar o site (GitHub Pages)
1. No repositório, vá em **Settings → Pages**.
2. Em "Source", escolha a branch `main` e a pasta `/ (root)`.
3. Salve. Em ~1 minuto o GitHub gera um link tipo:
   `https://SEU-USUARIO.github.io/arena-cury/`
4. Abra esse link na TV. Pronto.

## Observações
- O áudio só toca depois do primeiro toque/clique na tela (regra dos navegadores).
- As imagens e sons carregam por arquivo (cache), por isso a tela fica leve e fluida.
- Esta é a tela da TV. As telas de Recepção, Tablet e Aprovação entram na próxima etapa,
  junto com o banco de dados (Postgres) para sincronizar tudo em tempo real.

## Trocar imagens ou sons
Basta substituir o arquivo dentro de `img/` ou `sons/` por outro com o **mesmo nome**.
Ex: trocar `sons/sino.mp3` por outro sino, mantendo o nome `sino.mp3`.
