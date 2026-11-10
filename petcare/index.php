<?php
/**
 * =====================================================================
 * Trabalho 1 - API REST PetCare
 * Arquivo: index.php (roteador principal + todas as regras da API)
 *
 * Toda requisição cai aqui. O .htaccess manda qualquer caminho para
 * este arquivo e o roteador decide qual bloco executa.
 *
 * ---------------------------------------------------------------------
 * ROTAS IMPLEMENTADAS
 * ---------------------------------------------------------------------
 * POST   /login              (público)  200 token  | 401 | 422
 * POST   /logout             (token)    204        | 401
 * GET    /tutores            (público)  200
 * GET    /tutores/{id}       (público)  200        | 404
 * GET    /tutores/{id}/pets  (público)  200        | 404
 * POST   /tutores            (token)    201        | 401 | 409 | 422
 * PUT    /tutores/{id}       (token)    200        | 401 | 404 | 409 | 422
 * DELETE /tutores/{id}       (token)    204        | 401 | 404 | 409
 * GET    /pets               (público)  200  (filtro opcional ?especie=)
 * GET    /pets/{id}          (público)  200        | 404
 * POST   /pets               (token)    201        | 401 | 422
 * PUT    /pets/{id}          (token)    200        | 401 | 404 | 422
 * DELETE /pets/{id}          (token)    204        | 401 | 404
 * qualquer outra rota                            404
 *
 * REQUISITO DE AMBIENTE
 * ---------------------------------------------------------------------
 * PHP 7.4 ou superior, extensões PDO, pdo_mysql e mbstring.
 * Coloque a pasta petcare dentro do htdocs e acesse
 * http://localhost/petcare/
 * =====================================================================
 */

// ---------------------------------------------------------------------
// 1. CONFIGURAÇÃO
// ---------------------------------------------------------------------

/** Horas de validade do token gerado no login. */
const TOKEN_VALIDADE_HORAS = 2;

/** Espécies aceitas pela regra de validação e pelo ENUM do banco. */
const ESPECIES = ['cachorro', 'gato', 'ave', 'roedor', 'outro'];


// ---------------------------------------------------------------------
// 2. RESPOSTAS PADRÃO
// ---------------------------------------------------------------------

/**
 * Encerra a requisição com um status HTTP e, quando houver, um corpo JSON.
 * Toda rota termina chamando esta função, do mesmo jeito que na Aula 1.
 */
function responder(int $status, $dados = null): void
{
    http_response_code($status);

    // 204 e respostas sem corpo não levam Content-Type nem body.
    if ($dados !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

/**
 * Erro no formato definido nas aulas: {"detail": "mensagem"}.
 * Em validação, $detalhe é uma lista de mensagens e o JSON vira
 * {"detail": ["campo: mensagem", ...]}.
 */
function erro(int $status, $detalhe): void
{
    responder($status, ['detail' => $detalhe]);
}


// ---------------------------------------------------------------------
// 3. ENTRADA E BANCO
// ---------------------------------------------------------------------

/**
 * Lê o corpo da requisição e devolve o JSON já decodificado.
 * Corpo vazio ou que não seja JSON responde 400.
 */
function corpoJson(): array
{
    $bruto = file_get_contents('php://input');

    if ($bruto === false || trim($bruto) === '') {
        erro(400, 'Corpo da requisição vazio ou ausente: envie um JSON.');
    }

    $dados = json_decode($bruto, true);

    if (!is_array($dados)) {
        erro(400, 'Corpo da requisição não é um JSON válido.');
    }

    return $dados;
}

/**
 * Abre a conexão PDO com o banco petcare e reaproveita a mesma conexão
 * durante a requisição. Segue o padrão da função conectar() da Aula 2.
 */
function conectar(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=localhost;port=3306;dbname=petcare;charset=utf8mb4',
                'root',
                '', // no XAMPP o root vem sem senha
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            erro(500, 'Não foi possível conectar ao banco. Confira se o banco.sql '
                    . 'foi executado e se as credenciais do README batem com o seu MySQL.');
        }
    }

    return $pdo;
}

/**
 * Extrai o código de erro do MySQL de uma PDOException.
 * 1062 = valor único repetido, 1451 = registro referenciado por chave estrangeira.
 */
function codigoErroMysql(PDOException $e): int
{
    return isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
}

/**
 * Conta caracteres respeitando acentuação (fração de mbstring).
 */
function tamanho(string $texto): int
{
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen($texto);
}


// ---------------------------------------------------------------------
// 4. SEGURANÇA: TOKEN
// ---------------------------------------------------------------------

/**
 * Verificação única de token, chamada no início de cada rota protegida.
 * Devolve o registro da tabela tokens e encerra com 401 quando falha.
 *
 * Ordem de checagem:
 *   1. cabeçalho Authorization ausente ou fora do formato Bearer  -> 401
 *   2. token que não existe na tabela                             -> 401
 *   3. token cuja expira_em já passou                             -> 401
 */
function exigirToken(): array
{
    $cabecalho = '';

    // Com a linha do .htaccess, o Apache repassa o header para $_SERVER.
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $cabecalho = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        // Alternativa quando o servidor não popula $_SERVER.
        foreach (apache_request_headers() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) {
                $cabecalho = $valor;
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($cabecalho), $m)) {
        erro(401, 'Token não informado');
    }

    $stmt = conectar()->prepare(
        'SELECT id, usuario_id, token, expira_em FROM tokens WHERE token = ?'
    );
    $stmt->execute([$m[1]]);
    $registro = $stmt->fetch();

    if ($registro === false) {
        erro(401, 'Token inválido ou expirado');
    }

    if (strtotime($registro['expira_em']) <= time()) {
        erro(401, 'Token inválido ou expirado');
    }

    return $registro;
}


// ---------------------------------------------------------------------
// 5. VALIDAÇÃO
// ---------------------------------------------------------------------

/**
 * Valida os dados de um tutor.
 * Devolve ['erros' => lista de mensagens, 'valores' => campos já normalizados].
 */
function lerTutor(array $dados): array
{
    $erros   = [];
    $valores = [];

    // nome: obrigatório, de 2 a 100 caracteres
    $nome = (isset($dados['nome']) && is_string($dados['nome'])) ? trim($dados['nome']) : '';
    if ($nome === '') {
        $erros[] = 'nome: campo obrigatório';
    } elseif (tamanho($nome) < 2 || tamanho($nome) > 100) {
        $erros[] = 'nome: deve ter entre 2 e 100 caracteres';
    }
    $valores['nome'] = $nome;

    // telefone: obrigatório, cabe em VARCHAR(20)
    $telefone = (isset($dados['telefone']) && is_string($dados['telefone'])) ? trim($dados['telefone']) : '';
    if ($telefone === '') {
        $erros[] = 'telefone: campo obrigatório';
    } elseif (tamanho($telefone) > 20) {
        $erros[] = 'telefone: deve ter no máximo 20 caracteres';
    }
    $valores['telefone'] = $telefone;

    // email: obrigatório e com formato válido
    $email = (isset($dados['email']) && is_string($dados['email'])) ? trim($dados['email']) : '';
    if ($email === '') {
        $erros[] = 'email: campo obrigatório';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'email: formato inválido';
    } elseif (tamanho($email) > 120) {
        $erros[] = 'email: deve ter no máximo 120 caracteres';
    }
    $valores['email'] = $email;

    return ['erros' => $erros, 'valores' => $valores];
}

/**
 * Valida os dados de um pet.
 * A existência do tutor é conferida depois, com tutorExiste(), para não
 * consultar o banco quando os demais campos já estão errados.
 */
function lerPet(array $dados): array
{
    $erros   = [];
    $valores = [];

    // nome: obrigatório, de 2 a 60 caracteres
    $nome = (isset($dados['nome']) && is_string($dados['nome'])) ? trim($dados['nome']) : '';
    if ($nome === '') {
        $erros[] = 'nome: campo obrigatório';
    } elseif (tamanho($nome) < 2 || tamanho($nome) > 60) {
        $erros[] = 'nome: deve ter entre 2 e 60 caracteres';
    }
    $valores['nome'] = $nome;

    // especie: precisa estar na lista fechada
    $especie = (isset($dados['especie']) && is_string($dados['especie']))
        ? strtolower(trim($dados['especie']))
        : '';
    if ($especie === '') {
        $erros[] = 'especie: campo obrigatório';
    } elseif (!in_array($especie, ESPECIES, true)) {
        $erros[] = 'especie: deve ser uma de ' . implode(', ', ESPECIES);
    }
    $valores['especie'] = $especie;

    // raca: opcional, pode ser nula
    $raca = null;
    if (isset($dados['raca']) && is_string($dados['raca'])) {
        $raca = trim($dados['raca']);
        if ($raca === '') {
            $raca = null;
        } elseif (tamanho($raca) > 60) {
            $erros[] = 'raca: deve ter no máximo 60 caracteres';
        }
    }
    $valores['raca'] = $raca;

    // data_nascimento: formato AAAA-MM-DD e não pode estar no futuro
    $data = (isset($dados['data_nascimento']) && is_string($dados['data_nascimento']))
        ? trim($dados['data_nascimento'])
        : '';
    if ($data === '') {
        $erros[] = 'data_nascimento: campo obrigatório';
    } else {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        // O format() de volta rejeita datas impossíveis, como 2026-02-31.
        if ($dt === false || $dt->format('Y-m-d') !== $data) {
            $erros[] = 'data_nascimento: use o formato AAAA-MM-DD com uma data real';
        } elseif ($dt > new DateTimeImmutable('today')) {
            $erros[] = 'data_nascimento: não pode ser uma data futura';
        }
    }
    $valores['data_nascimento'] = $data;

    // peso_kg: número maior que 0 e no máximo 200
    $pesoBruto = $dados['peso_kg'] ?? null;
    $peso      = 0.0;
    if ($pesoBruto === null || $pesoBruto === '') {
        $erros[] = 'peso_kg: campo obrigatório';
    } elseif (!is_numeric($pesoBruto)) {
        $erros[] = 'peso_kg: deve ser um número';
    } else {
        $peso = (float) $pesoBruto;
        if ($peso <= 0 || $peso > 200) {
            $erros[] = 'peso_kg: deve ser maior que 0 e no máximo 200';
        }
    }
    $valores['peso_kg'] = round($peso, 2);

    // tutor_id: inteiro positivo de um tutor que exista
    $tutorBruto = $dados['tutor_id'] ?? null;
    $tutorId    = 0;
    if ($tutorBruto === null || $tutorBruto === '') {
        $erros[] = 'tutor_id: campo obrigatório';
    } else {
        $convertido = filter_var($tutorBruto, FILTER_VALIDATE_INT);
        if ($convertido === false || $convertido < 1) {
            $erros[] = 'tutor_id: deve ser um número inteiro positivo';
        } else {
            $tutorId = (int) $convertido;
        }
    }
    $valores['tutor_id'] = $tutorId;

    return ['erros' => $erros, 'valores' => $valores];
}


// ---------------------------------------------------------------------
// 6. CONSULTAS AUXILIARES
// ---------------------------------------------------------------------

function buscarTutor(int $id)
{
    $stmt = conectar()->prepare('SELECT id, nome, telefone, email FROM tutores WHERE id = ?');
    $stmt->execute([$id]);
    $tutor = $stmt->fetch();

    return $tutor === false ? null : $tutor;
}

function tutorExiste(int $id): bool
{
    $stmt = conectar()->prepare('SELECT 1 FROM tutores WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetchColumn() !== false;
}

/** Busca o pet já com o nome do tutor, usando JOIN. */
function buscarPet(int $id)
{
    $stmt = conectar()->prepare(
        'SELECT p.id, p.nome, p.especie, p.raca, p.data_nascimento, p.peso_kg,
                p.tutor_id, t.nome AS tutor_nome
           FROM pets p
     INNER JOIN tutores t ON t.id = p.tutor_id
          WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    $pet = $stmt->fetch();

    return $pet === false ? null : $pet;
}


// ---------------------------------------------------------------------
// 7. FORMATAÇÃO DE SAÍDA
// ---------------------------------------------------------------------

function tutorParaJson(array $linha): array
{
    return [
        'id'       => (int) $linha['id'],
        'nome'     => $linha['nome'],
        'telefone' => $linha['telefone'],
        'email'    => $linha['email'],
    ];
}

function petParaJson(array $linha): array
{
    $pet = [
        'id'              => (int) $linha['id'],
        'nome'            => $linha['nome'],
        'especie'         => $linha['especie'],
        'raca'            => $linha['raca'], // null quando não informada
        'data_nascimento' => $linha['data_nascimento'],
        'peso_kg'         => (float) $linha['peso_kg'],
        'tutor_id'        => (int) $linha['tutor_id'],
    ];

    if (array_key_exists('tutor_nome', $linha)) {
        $pet['tutor_nome'] = $linha['tutor_nome'];
    }

    return $pet;
}


// ---------------------------------------------------------------------
// 8. LEITURA DA REQUISIÇÃO
// ---------------------------------------------------------------------

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Precisamos descartar o prefixo da pasta onde a API está publicada.
 * Em http://localhost/petcare/tutores o REQUEST_URI traz /petcare/tutores,
 * e o roteador precisa comparar apenas /tutores.
 *
 * O valor é detectado a partir da localização do index.php, então funciona
 * mesmo se a pasta for renomeada. Se quiser fixar, troque a linha abaixo
 * por: $base = '/petcare';
 */
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/petcare/index.php')), '/');
if ($base === '.' || $base === '/') {
    $base = '';
}

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($caminho === false || $caminho === null) {
    $caminho = '/';
}

if ($base !== '' && ($caminho === $base || strpos($caminho, $base . '/') === 0)) {
    $caminho = substr($caminho, strlen($base));
}

// Normaliza para sempre começar com uma barra e nunca terminar com ela.
$caminho = '/' . trim($caminho, '/');


// ---------------------------------------------------------------------
// 9. ROTAS DE AUTENTICAÇÃO
// ---------------------------------------------------------------------

// POST /login (público)
if ($metodo === 'POST' && $caminho === '/login') {
    $dados = corpoJson();

    $email = (isset($dados['email']) && is_string($dados['email'])) ? trim($dados['email']) : '';
    $senha = (isset($dados['senha']) && is_string($dados['senha'])) ? $dados['senha'] : '';

    $erros = [];
    if ($email === '') {
        $erros[] = 'email: campo obrigatório';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'email: formato inválido';
    }
    if ($senha === '') {
        $erros[] = 'senha: campo obrigatório';
    }
    if ($erros) {
        erro(422, $erros);
    }

    $pdo  = conectar();
    $stmt = $pdo->prepare('SELECT id, nome, email, senha_hash FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    // A mesma mensagem para e-mail inexistente e senha errada, de modo que
    // a resposta não revele quais e-mails estão cadastrados.
    if ($usuario === false || !password_verify($senha, $usuario['senha_hash'])) {
        erro(401, 'E-mail ou senha inválidos');
    }

    // Limpa tokens vencidos: mantém a tabela pequena e barata de consultar.
    $pdo->exec('DELETE FROM tokens WHERE expira_em < NOW()');

    $token  = bin2hex(random_bytes(32)); // 64 caracteres hexadecimais
    $expira = (new DateTimeImmutable())->modify('+' . TOKEN_VALIDADE_HORAS . ' hours');

    $stmt = $pdo->prepare('INSERT INTO tokens (usuario_id, token, expira_em) VALUES (?, ?, ?)');
    $stmt->execute([$usuario['id'], $token, $expira->format('Y-m-d H:i:s')]);

    responder(200, [
        'token'     => $token,
        'tipo'      => 'Bearer',
        'expira_em' => $expira->format('Y-m-d H:i:s'),
    ]);
}

// POST /logout (token)
if ($metodo === 'POST' && $caminho === '/logout') {
    $sessao = exigirToken();

    $stmt = conectar()->prepare('DELETE FROM tokens WHERE id = ?');
    $stmt->execute([$sessao['id']]);

    responder(204);
}


// ---------------------------------------------------------------------
// 10. ROTAS DE TUTORES
// ---------------------------------------------------------------------

// GET /tutores (público)
if ($metodo === 'GET' && $caminho === '/tutores') {
    $stmt = conectar()->query('SELECT id, nome, telefone, email FROM tutores ORDER BY nome');
    $tutores = array_map('tutorParaJson', $stmt->fetchAll());

    responder(200, [
        'total'   => count($tutores),
        'tutores' => $tutores,
    ]);
}

// GET /tutores/{id} (público)
if ($metodo === 'GET' && preg_match('#^/tutores/(\d+)$#', $caminho, $m)) {
    $tutor = buscarTutor((int) $m[1]);
    if ($tutor === null) {
        erro(404, 'Tutor não encontrado');
    }

    responder(200, tutorParaJson($tutor));
}

// GET /tutores/{id}/pets (público)
if ($metodo === 'GET' && preg_match('#^/tutores/(\d+)/pets$#', $caminho, $m)) {
    $id    = (int) $m[1];
    $tutor = buscarTutor($id);
    if ($tutor === null) {
        erro(404, 'Tutor não encontrado');
    }

    $stmt = conectar()->prepare(
        'SELECT id, nome, especie, raca, data_nascimento, peso_kg, tutor_id
           FROM pets
          WHERE tutor_id = ?
       ORDER BY nome'
    );
    $stmt->execute([$id]);
    $pets = array_map('petParaJson', $stmt->fetchAll());

    responder(200, [
        'tutor' => ['id' => (int) $tutor['id'], 'nome' => $tutor['nome']],
        'total' => count($pets),
        'pets'  => $pets,
    ]);
}

// POST /tutores (token)
if ($metodo === 'POST' && $caminho === '/tutores') {
    exigirToken();

    $leitura = lerTutor(corpoJson());
    if ($leitura['erros']) {
        erro(422, $leitura['erros']);
    }

    $pdo = conectar();
    try {
        $stmt = $pdo->prepare('INSERT INTO tutores (nome, telefone, email) VALUES (?, ?, ?)');
        $stmt->execute([
            $leitura['valores']['nome'],
            $leitura['valores']['telefone'],
            $leitura['valores']['email'],
        ]);
    } catch (PDOException $e) {
        // 1062: a UNIQUE de email barrou um e-mail já cadastrado.
        if (codigoErroMysql($e) === 1062) {
            erro(409, 'Já existe um tutor com este e-mail');
        }
        erro(500, 'Erro ao gravar o tutor no banco de dados');
    }

    $id = (int) $pdo->lastInsertId();

    responder(201, tutorParaJson(buscarTutor($id)));
}

// PUT /tutores/{id} (token)
// PUT substitui o recurso: nome, telefone e email precisam vir no corpo.
if ($metodo === 'PUT' && preg_match('#^/tutores/(\d+)$#', $caminho, $m)) {
    exigirToken();

    $id = (int) $m[1];
    if (buscarTutor($id) === null) {
        erro(404, 'Tutor não encontrado');
    }

    $leitura = lerTutor(corpoJson());
    if ($leitura['erros']) {
        erro(422, $leitura['erros']);
    }

    try {
        $stmt = conectar()->prepare(
            'UPDATE tutores SET nome = ?, telefone = ?, email = ? WHERE id = ?'
        );
        $stmt->execute([
            $leitura['valores']['nome'],
            $leitura['valores']['telefone'],
            $leitura['valores']['email'],
            $id,
        ]);
    } catch (PDOException $e) {
        if (codigoErroMysql($e) === 1062) {
            erro(409, 'Já existe outro tutor com este e-mail');
        }
        erro(500, 'Erro ao gravar o tutor no banco de dados');
    }

    responder(200, tutorParaJson(buscarTutor($id)));
}

// DELETE /tutores/{id} (token)
// Regra adotada: ON DELETE CASCADE no banco. Apagar o tutor apaga os pets.
if ($metodo === 'DELETE' && preg_match('#^/tutores/(\d+)$#', $caminho, $m)) {
    exigirToken();

    $id = (int) $m[1];
    if (buscarTutor($id) === null) {
        erro(404, 'Tutor não encontrado');
    }

    try {
        $stmt = conectar()->prepare('DELETE FROM tutores WHERE id = ?');
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        // 1451 só acontece se a FK for trocada por RESTRICT no banco.sql.
        if (codigoErroMysql($e) === 1451) {
            erro(409, 'Não é possível excluir: existem pets vinculados a este tutor');
        }
        erro(500, 'Erro ao excluir o tutor');
    }

    responder(204);
}


// ---------------------------------------------------------------------
// 11. ROTAS DE PETS
// ---------------------------------------------------------------------

// GET /pets (público) com filtro opcional ?especie=
if ($metodo === 'GET' && $caminho === '/pets') {
    $sql = 'SELECT p.id, p.nome, p.especie, p.raca, p.data_nascimento, p.peso_kg,
                   p.tutor_id, t.nome AS tutor_nome
              FROM pets p
        INNER JOIN tutores t ON t.id = p.tutor_id';

    $parametros = [];
    $especie = (isset($_GET['especie']) && is_string($_GET['especie']))
        ? strtolower(trim($_GET['especie']))
        : '';

    // Filtro inexistente ou fora da lista devolve lista vazia, nunca erro:
    // a rota de listagem não tem erro previsto na tabela do trabalho.
    if ($especie !== '') {
        $sql .= ' WHERE p.especie = ?';
        $parametros[] = $especie;
    }

    $sql .= ' ORDER BY p.nome';

    $stmt = conectar()->prepare($sql);
    $stmt->execute($parametros);
    $pets = array_map('petParaJson', $stmt->fetchAll());

    responder(200, [
        'total' => count($pets),
        'pets'  => $pets,
    ]);
}

// GET /pets/{id} (público), com JOIN para trazer o nome do tutor
if ($metodo === 'GET' && preg_match('#^/pets/(\d+)$#', $caminho, $m)) {
    $pet = buscarPet((int) $m[1]);
    if ($pet === null) {
        erro(404, 'Pet não encontrado');
    }

    responder(200, petParaJson($pet));
}

// POST /pets (token)
if ($metodo === 'POST' && $caminho === '/pets') {
    exigirToken();

    $leitura = lerPet(corpoJson());

    // Tutor inexistente também é erro de dados, portanto 422.
    if (!$leitura['erros'] && !tutorExiste($leitura['valores']['tutor_id'])) {
        $leitura['erros'][] = 'tutor_id: tutor não encontrado';
    }
    if ($leitura['erros']) {
        erro(422, $leitura['erros']);
    }

    $pdo = conectar();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO pets (nome, especie, raca, data_nascimento, peso_kg, tutor_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leitura['valores']['nome'],
            $leitura['valores']['especie'],
            $leitura['valores']['raca'],
            $leitura['valores']['data_nascimento'],
            $leitura['valores']['peso_kg'],
            $leitura['valores']['tutor_id'],
        ]);
    } catch (PDOException $e) {
        // 1452: FK apontando para tutor que não existe (corrida entre a
        // validação e o INSERT, rara mas possível).
        if (codigoErroMysql($e) === 1452) {
            erro(422, ['tutor_id: tutor não encontrado']);
        }
        erro(500, 'Erro ao gravar o pet no banco de dados');
    }

    $id = (int) $pdo->lastInsertId();

    responder(201, petParaJson(buscarPet($id)));
}

// PUT /pets/{id} (token)
if ($metodo === 'PUT' && preg_match('#^/pets/(\d+)$#', $caminho, $m)) {
    exigirToken();

    $id = (int) $m[1];
    if (buscarPet($id) === null) {
        erro(404, 'Pet não encontrado');
    }

    $leitura = lerPet(corpoJson());
    if (!$leitura['erros'] && !tutorExiste($leitura['valores']['tutor_id'])) {
        $leitura['erros'][] = 'tutor_id: tutor não encontrado';
    }
    if ($leitura['erros']) {
        erro(422, $leitura['erros']);
    }

    try {
        $stmt = conectar()->prepare(
            'UPDATE pets
                SET nome = ?, especie = ?, raca = ?, data_nascimento = ?, peso_kg = ?, tutor_id = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $leitura['valores']['nome'],
            $leitura['valores']['especie'],
            $leitura['valores']['raca'],
            $leitura['valores']['data_nascimento'],
            $leitura['valores']['peso_kg'],
            $leitura['valores']['tutor_id'],
            $id,
        ]);
    } catch (PDOException $e) {
        if (codigoErroMysql($e) === 1452) {
            erro(422, ['tutor_id: tutor não encontrado']);
        }
        erro(500, 'Erro ao gravar o pet no banco de dados');
    }

    responder(200, petParaJson(buscarPet($id)));
}

// DELETE /pets/{id} (token)
if ($metodo === 'DELETE' && preg_match('#^/pets/(\d+)$#', $caminho, $m)) {
    exigirToken();

    $id = (int) $m[1];
    if (buscarPet($id) === null) {
        erro(404, 'Pet não encontrado');
    }

    try {
        $stmt = conectar()->prepare('DELETE FROM pets WHERE id = ?');
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        erro(500, 'Erro ao excluir o pet');
    }

    responder(204);
}


// ---------------------------------------------------------------------
// 12. ROTA NÃO ENCONTRADA
// ---------------------------------------------------------------------
erro(404, 'Rota não encontrada');