<div align="center">
  <h1>🐾 API REST PetCare</h1>
  <p><strong>Gestão de tutores e pets com autenticação por token em PHP puro.</strong></p>
</div>

<p>
  <div align="center">
    <img src="https://img.shields.io/badge/Versão-1.0-blue.svg" alt="Versão">
    <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4" alt="PHP">
    <img src="https://img.shields.io/badge/MySQL-8.0-4479A1" alt="MySQL">
    <img src="https://img.shields.io/badge/Status-Conclu%C3%ADdo-brightgreen.svg" alt="Status">
    <img src="https://img.shields.io/badge/Licen%C3%A7a-Acad%C3%AAmica-green.svg" alt="Licença">
  </div>
</p>

<hr>

##  Integrantes

| Nome completo | RA |
| --- | --- |
| Erick Correia Coelho | 22504188 |
| (preencher) | (preencher) |
| (preencher) | (preencher) |

**Disciplina:** Desenvolvimento Web · **Professor:** Prof. Jr · **Entrega:** 08/12/2026

<hr>

##  Sobre o Projeto

A **API REST PetCare** foi desenvolvida para a clínica veterinária PetCare, com o objetivo de centralizar o cadastro dos tutores (donos) e dos pets atendidos, servindo a recepção e o site da clínica.

Qualquer pessoa consulta tutores e pets. Só funcionários autenticados cadastram, alteram e excluem registros. A base da API é `http://localhost/petcare/`, toda resposta é JSON e os erros seguem o formato `{"detail": ...}`.

O banco foi projetado sem nada específico de PHP, para ser reaproveitado no **Trabalho 2** com Python e FastAPI.

<hr>

## ✅ Pré-requisitos

- XAMPP com **Apache** e **MySQL** iniciados
- PHP 7.4 ou superior, com as extensões `PDO`, `pdo_mysql` e `mbstring`
- VS Code com a extensão **REST Client** para executar o `requisicoes.http`

<hr>

##  Como Rodar

**1.** Suba o **Apache** e o **MySQL** no painel do XAMPP e confirme que `http://localhost/phpmyadmin` abre sem pedir senha.

**2.** Copie a pasta para o `htdocs`. A pasta precisa se chamar exatamente `petcare`:

    C:\xampp\htdocs\petcare\index.php
    C:\xampp\htdocs\petcare\.htaccess
    C:\xampp\htdocs\petcare\banco.sql
    C:\xampp\htdocs\petcare\requisicoes.http
    C:\xampp\htdocs\petcare\README.md

O `.htaccess` é o único arquivo que exige cuidado no Windows: salve com o nome entre aspas (`".htaccess"`), senão o Windows cria `.htaccess.txt` e o Apache ignora o arquivo.

**3.** Gere o hash da senha de teste. O `banco.sql` traz o marcador `__HASH_DA_SENHA__`, porque só o PHP consegue gerar o hash:

    C:\xampp\php\php.exe -r "echo password_hash('petcare123', PASSWORD_DEFAULT), PHP_EOL;"

Copie a linha de 60 caracteres que começa com `$2y$10$` e cole no lugar do marcador, mantendo as aspas simples:

    INSERT INTO usuarios (nome, email, senha_hash) VALUES
      ('Recepcao PetCare', 'admin@petcare.com', '$2y$10$valorGeradoAqui');

> ⚠️ Use aspas simples. Com aspas duplas o MySQL pode interpretar o cifrão como variável e gravar um hash inválido.

**4.** Importe o banco. Abra `http://localhost/phpmyadmin`, clique em **Importar**, selecione o `banco.sql` e execute. O script apaga e recria tudo, então pode ser rodado quantas vezes for preciso.

**5.** Confirme que a API responde. Acesse `http://localhost/petcare/tutores`. Deve aparecer um JSON com `total: 5` e os tutores de exemplo.

**6.** Teste uma rota autenticada. Abra o `requisicoes.http`, rode `1.1 POST /login`, copie o token da resposta e cole na variável `@token` no topo do arquivo. Depois rode as demais requisições na ordem.

<hr>

## 🔑 Usuário de Teste

| Campo | Valor |
| --- | --- |
| E-mail | `admin@petcare.com` |
| Senha | `petcare123` |

A senha existe no banco apenas como hash, gerado com `password_hash`. Nunca é gravada em texto puro e nunca aparece em nenhuma resposta da API.

<hr>

## 🗄️ Banco de Dados

Banco `petcare`, com quatro tabelas:

- **tutores:** `id`, `nome`, `telefone`, `email`. `email` é UNIQUE e `nome` e `telefone` são NOT NULL.
- **pets:** `id`, `nome`, `especie`, `raca`, `data_nascimento`, `peso_kg`, `tutor_id`. `especie` é ENUM com cinco valores, `raca` aceita nulo e `tutor_id` é chave estrangeira.
- **usuarios:** `id`, `nome`, `email`, `senha_hash`. `email` é UNIQUE.
- **tokens:** `id`, `usuario_id`, `token`, `expira_em`. `token` é UNIQUE e `usuario_id` é chave estrangeira.

Dados de exemplo: **5 tutores, 11 pets e 1 usuário**. A tutora Ana Paula tem 3 pets e Bruno tem 2, atendendo ao requisito de tutor com mais de um animal.

<hr>

## 🛣️ Rotas da API

| Método | Rota | Token | Sucesso | Erros |
| --- | --- | --- | --- | --- |
| POST | `/login` | Não | 200 com o token | 401, 422, 400 |
| POST | `/logout` | Sim | 204 | 401 |
| GET | `/tutores` | Não | 200 com total e lista | 400 |
| GET | `/tutores/{id}` | Não | 200 | 404 |
| GET | `/tutores/{id}/pets` | Não | 200 com os pets do tutor | 404 |
| POST | `/tutores` | Sim | 201 com o id criado | 401, 409, 422, 400 |
| PUT | `/tutores/{id}` | Sim | 200 com o tutor alterado | 401, 404, 409, 422, 400 |
| DELETE | `/tutores/{id}` | Sim | 204 | 401, 404 |
| GET | `/pets` | Não | 200, filtro `?especie=` | 400 |
| GET | `/pets/{id}` | Não | 200, com o nome do tutor | 404 |
| POST | `/pets` | Sim | 201 com o id criado | 401, 422, 400 |
| PUT | `/pets/{id}` | Sim | 200 com o pet alterado | 401, 404, 422, 400 |
| DELETE | `/pets/{id}` | Sim | 204 | 401, 404 |
| Qualquer outra | Qualquer | Não | Não | 404 Rota não encontrada |

O `400` aparece em toda rota que lê corpo, para o caso de o JSON estar malformado ou vazio.

<hr>

## 🔐 Autenticação

O login confere a senha com `password_verify`, gera o token com `bin2hex(random_bytes(32))` e grava em `tokens` com validade de **2 horas**. As rotas protegidas recebem `Authorization: Bearer <token>`, verificado pela função `exigirToken()` antes de qualquer outra checagem. O logout apaga o token da tabela.

> 🛡️ E-mail inexistente e senha errada devolvem a mesma mensagem, para não revelar quais e-mails existem.

<hr>

## ⚖️ Regra de Exclusão de Tutores

Adotamos **ON DELETE CASCADE**: ao excluir um tutor, todos os pets dele são apagados na mesma operação.

    CONSTRAINT fk_pets_tutor
      FOREIGN KEY (tutor_id) REFERENCES tutores (id)
      ON DELETE CASCADE

Um pet só existe porque existe um tutor responsável. Não há animal sem dono no sistema, e um registro órfão quebraria o JOIN de `GET /pets` e `GET /pets/{id}`, que dependem do tutor para montar a resposta. Como o banco será reaproveitado no Trabalho 2, permitir pet sem tutor obrigaria toda consulta a tratar `tutor_nome` nulo, o que espalha complexidade sem ganho de negócio.

A alternativa RESTRICT, que devolveria 409 quando o tutor tiver pets, foi descartada porque empurra para o cliente a tarefa de apagar pet por pet. Com CASCADE, a integridade fica garantida pelo banco, não pelo código da API.

**Consequência:** o `DELETE /tutores/{id}` trata o erro MySQL 1451 devolvendo 409, mas com CASCADE esse erro não ocorre na prática. O tratamento permanece como defesa caso a chave estrangeira seja trocada para RESTRICT, o que torna o 409 dessa rota inalcançável com a regra adotada.

<hr>

## 🛠️ Problemas Comuns

**Todas as rotas devolvem 404 do Apache, em HTML.** O `.htaccess` não está sendo lido. Confira se o arquivo não virou `.htaccess.txt`, se `mod_rewrite` está ativo no `httpd.conf` e se o `AllowOverride` do `htdocs` está como `All`. Depois reinicie o Apache.

**Login correto devolve 401.** O cabeçalho `Authorization` não chegou ao PHP. Confirme a linha `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` no `.htaccess`, logo depois de `RewriteEngine On`.

**Login do usuário de teste devolve 401.** O hash ainda é o marcador `__HASH_DA_SENHA__`, ou o banco foi importado antes de você colar o hash. Gere o hash, cole no `banco.sql` e reimporte.

**Erro 500 mencionando conexão.** O MySQL não está rodando, o `banco.sql` não foi executado, ou a senha do root não é vazia. Nesse caso, ajuste o terceiro argumento de `conectar()` no `index.php`.

**Erro "headers already sent".** O `index.php` foi salvo com BOM UTF-8. Salve como UTF-8 sem BOM.

**curl com JSON retorna "URL rejected".** O PowerShell não usa `\"` como escape. Use `Invoke-RestMethod` com hashtable, ou `curl.exe` com o JSON entre aspas simples.

> 📌 Antes de commitar ou apresentar, reimporte o `banco.sql`: os testes alteram os dados de exemplo.

<hr>

<p align="center">
  Desenvolvido por <a href="https://github.com/ErickCorreia">ErickCorreia</a>
</p>
