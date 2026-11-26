<?php
require_once __DIR__ . "/../../config/email.php";
require_once __DIR__ . "/../Services/AuthService.php";
require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/ValidacaoCadastroService.php";
require_once __DIR__ . "/../Services/SessaoService.php";

function login()
{
    global $conexao;

    header("Content-Type: application/json; charset=UTF-8");

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        echo json_encode([
            "success" => false,
            "message" => "Método não permitido. Use POST."
        ]);
        exit;
    }

    $dados = json_decode(file_get_contents("php://input"), true);

    if (!$dados) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhum dado foi enviado."
        ]);
        exit;
    }

    $email = $dados["email_usuario"] ?? "";
    $senha = $dados["senha_usuario"] ?? "";

    if (empty($email) || empty($senha)) {
        echo json_encode([
            "success" => false,
            "message" => "E-mail e senha são obrigatórios."
        ]);
        exit;
    }

    $usuario = loginUsuarioService($conexao, $email);

    if (!$usuario) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou senha incorretos"
        ]);
        exit;
    }


    if (!password_verify($senha, $usuario["senha_usuario"])) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou senha incorretos"
        ]);
        exit;
    }

    // Só entra quem confirmou o e-mail (o app abre a tela do código quando recebe este aviso).
    if (!perfilVerdadeiro($usuario["email_verificado_usuario"] ?? false)) {
        echo json_encode([
            "success" => false,
            "email_nao_verificado" => true,
            "email_usuario" => $usuario["email_usuario"],
            "message" => "Confirme seu e-mail para entrar."
        ]);
        exit;
    }

    // Sessão de 30 dias ligada ao aparelho (o app envia o nome dele em "dispositivo").
    $refreshToken = criarRefreshToken();
    $idSessao = sessaoRegistrar(
        $conexao,
        (int) $usuario["id_usuario"],
        criarHashRefreshToken($refreshToken),
        "Android",
        sessaoNomeDispositivo($dados["dispositivo"] ?? null, "Android")
    );
    $accessToken = criarToken($usuario["id_usuario"], $idSessao);

    echo json_encode([
        "success" => true,
        "message" => "Login realizado com sucesso",
        "token" => $accessToken,
        "refreshToken" => $refreshToken,
        "user" => [
            "id_usuario" => $usuario["id_usuario"],
            "nome_usuario" => $usuario["nome_usuario"],
            "email_usuario" => $usuario["email_usuario"]
        ]
    ]);
}

// POST /auth/registrarNovoUsuario
// Obrigatórios: nome, e-mail e senha. Telefone e CPF são opcionais e só são validados se enviados.
function registrarUsuario()
{
    global $conexao;

    $dados = json_decode(file_get_contents("php://input"), true);
    if (!is_array($dados)) {
        $dados = [];
    }

    $nome = preg_replace("/\s+/u", " ", trim((string) ($dados['nome_usuario'] ?? '')));
    $email = mb_strtolower(trim((string) ($dados['email_usuario'] ?? '')), "UTF-8");
    $senha = (string) ($dados['senha_usuario'] ?? '');
    $telefone = telefoneSomenteDigitos((string) ($dados['telefone_usuario'] ?? ''));
    $cpf = preg_replace("/\D+/", "", (string) ($dados['cpf_usuario'] ?? ''));

    $erro = null;

    if ($nome === '' || $email === '' || $senha === '') {
        $erro = "Nome, e-mail e senha são obrigatórios.";
    } elseif (mb_strlen($nome, "UTF-8") < 2 || mb_strlen($nome, "UTF-8") > 100) {
        $erro = "Informe um nome com 2 a 100 caracteres.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
        $erro = "E-mail inválido.";
    } elseif (strlen($senha) < PERFIL_SENHA_MIN) {
        $erro = "A senha precisa ter pelo menos " . PERFIL_SENHA_MIN . " caracteres.";
    } elseif ($telefone === null) {
        $erro = "Telefone inválido. Use o DDD, por exemplo (11) 91234-5678, ou deixe em branco.";
    } elseif ($cpf !== '' && !cpfValido($cpf)) {
        $erro = "CPF inválido. Confira os números ou deixe o campo em branco.";
    }

    if ($erro !== null) {
        echo json_encode(["status" => "erro", "mensagem" => $erro]);
        exit;
    }

    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    try {
        $usuario = registrarNovoUsuarioService(
            $conexao,
            $nome,
            $email,
            $senhaHash,
            $cpf === '' ? null : $cpf,
            $telefone === '' ? null : $telefone
        );
    } catch (PDOException $erro) {
        // 23505 = já existe uma conta com este e-mail, telefone ou CPF
        if ($erro->getCode() === '23505') {
            http_response_code(409);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "Já existe uma conta com este e-mail, telefone ou CPF."
            ]);
            exit;
        }

        error_log("Erro ao registrar usuário: " . $erro->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => "erro",
            "mensagem" => "Não foi possível concluir o cadastro agora."
        ]);
        exit;
    }

    $idUsuario = $usuario['id_usuario'];

    $codigo = str_pad(
        random_int(0, 999999),
        6,
        "0",
        STR_PAD_LEFT
    );

    $codigoHash = password_hash(
        $codigo,
        PASSWORD_DEFAULT
    );

    $expiraEm = date(
        "Y-m-d H:i:s",
        time() + (10 * 60)
    );

    gerarTokenUsuario(
        $conexao,
        $idUsuario,
        $codigoHash,
        $expiraEm
    );

    $enviado = enviarCodigoBrevo($email, $codigo);

    if (!$enviado) {
        echo json_encode([
            "status" => "erro",
            "mensagem" => "Não foi possível enviar o código de verificação.",
            "detalhe" => $GLOBALS["erro_email"] ?? "sem detalhe"
        ]);
        exit;
    }

    echo json_encode([
        "status" => "sucesso",
        "mensagem" => "Cadastro realizado. Código de verificação enviado.",
        "id_usuario" => $idUsuario
    ]);
}
function refresh()
{
    global $conexao;

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {

        http_response_code(405);

        echo json_encode([
            "success" => false,
            "message" => "Método não permitido. Use POST."
        ]);

        exit;
    }

    $dados = json_decode(file_get_contents("php://input"), true);

    $refreshToken = $dados["refresh_token"] ?? "";

    if (empty($refreshToken)) {
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Refresh Token não enviado."
        ]);

        exit;
    }

    $refreshTokenHash = criarHashRefreshToken($refreshToken);

    $sessao = getSessaoUsuarioService($conexao, $refreshTokenHash);

    if (!$sessao) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Refresh Token inválido."
        ]);

        exit;
    }

    if ($sessao["revogado_em"] !== null) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Sessão revogada."
        ]);

        exit;
    }

    if (strtotime($sessao["expira_em"]) <= time()) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Refresh Token expirado."
        ]);

        exit;
    }

    // O token novo continua ligado à mesma sessão (aparelho) e o último uso é anotado.
    sessaoAtiva($conexao, (int) $sessao["id_sessao"], (int) $sessao["id_usuario"]);
    $novoAccessToken = criarToken($sessao["id_usuario"], (int) $sessao["id_sessao"]);

    echo json_encode([
        "success" => true,
        "message" => "Token renovado com sucesso",
        "token" => $novoAccessToken
    ]);
}

function loginSite()
{
    // CORREÇÃO: usar a conexão global. O "$conexao = require_once ..." devolvia
    // "true" porque o conexao.php já foi incluído no topo deste arquivo.
    global $conexao;

    header("Content-Type: application/json; charset=UTF-8");

    require_once __DIR__ . "/../Middleware/Authentication.php";

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        echo json_encode([
            "success" => false,
            "message" => "Método não permitido. Use POST."
        ]);
        exit;
    }

    $dados = json_decode(file_get_contents("php://input"), true);

    if (!$dados) {
        echo json_encode([
            "success" => false,
            "message" => "Nenhum dado foi enviado."
        ]);
        exit;
    }

    $email = $dados["email_usuario"] ?? "";
    $senha = $dados["senha_usuario"] ?? "";

    if (empty($email) || empty($senha)) {
        echo json_encode([
            "success" => false,
            "message" => "E-mail e senha são obrigatórios."
        ]);
        exit;
    }

    $usuario = loginUsuarioSiteService($conexao, $email);

    if (!$usuario) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou senha incorretos"
        ]);
        exit;
    }

    if (!password_verify($senha, $usuario["senha_usuario"])) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou senha incorretos"
        ]);
        exit;
    }

    if (!$usuario["email_verificado_usuario"]) {
        echo json_encode([
            "success" => false,
            "message" => "E-mail ainda não foi verificado."
        ]);
        exit;
    }


    // Refresh token aleatório (só o hash vai para o banco) numa sessão de 30 dias ligada ao
    // navegador; o access token de 15 minutos carrega o id dessa sessão.
    $refreshToken = criarRefreshToken();
    $idSessao = sessaoRegistrar(
        $conexao,
        (int) $usuario["id_usuario"],
        criarHashRefreshToken($refreshToken),
        "Site",
        sessaoNomeDispositivo($dados["dispositivo"] ?? null, "Site", (string) ($_SERVER["HTTP_USER_AGENT"] ?? ""))
    );
    $accessToken = criarToken($usuario["id_usuario"], $idSessao);

    // Retorna os dois tokens para o site.
    echo json_encode([
        "success" => true,
        "message" => "Login realizado com sucesso",
        "token" => $accessToken,
        "refreshToken" => $refreshToken,
        "user" => [
            "id_usuario" => $usuario["id_usuario"],
            "nome_usuario" => $usuario["nome_usuario"],
            "email_usuario" => $usuario["email_usuario"]
        ]
    ]);
}

function resendVerification()
{
    global $conexao;

    try {
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "Método não permitido."
            ]);
            exit;
        }

        $dados = json_decode(file_get_contents("php://input"), true);
        $email = trim($dados["email_usuario"] ?? "");

        if ($email === "") {
            http_response_code(400);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "E-mail é obrigatório."
            ]);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "E-mail inválido."
            ]);
            exit;
        }

        $usuario = findByEmailService($conexao, $email);

        if (!$usuario) {
            http_response_code(404);

            echo json_encode([
                "status" => "erro",
                "mensagem" => "Usuário não encontrado."
            ]);

            exit;
        }

        if ($usuario["email_verificado_usuario"] === true || $usuario["email_verificado_usuario"] === "t") {
            echo json_encode([
                "status" => "erro",
                "mensagem" => "Este e-mail já foi verificado."
            ]);

            exit;
        }

        $idUsuario = $usuario["id_usuario"];

        $codigo = str_pad(
            random_int(0, 999999),
            6,
            "0",
            STR_PAD_LEFT
        );

        $codigoHash = password_hash($codigo, PASSWORD_DEFAULT);

        $expiraEm = date(
            "Y-m-d H:i:s",
            time() + (10 * 60)
        );

        $conexao->beginTransaction();

        invalidarTokenService($conexao, $idUsuario);

        gerarTokenUsuarioService($conexao, $idUsuario, $codigoHash, $expiraEm);

        $conexao->commit();

        $enviado = enviarCodigoBrevo(
            $email,
            $codigo
        );

        if (!$enviado) {
            http_response_code(500);

            echo json_encode([
                "status" => "erro",
                "mensagem" => "Não foi possível enviar o código de verificação."
            ]);

            exit;
        }

        echo json_encode([
            "status" => "sucesso",
            "mensagem" => "Novo código de verificação enviado."
        ]);
    } catch (PDOException $erro) {

        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        http_response_code(500);

        echo json_encode([
            "status" => "erro",
            "mensagem" => $erro->getMessage()
        ]);
    } catch (Exception $erro) {

        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        http_response_code(500);

        echo json_encode([
            "status" => "erro",
            "mensagem" => $erro->getMessage()
        ]);
    }
}

function verifyEmail()
{
    global $conexao;
    try {

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "Método não permitido."
            ]);
            exit;
        }

        $dados = json_decode(file_get_contents("php://input"), true);

        $email = trim($dados["email_usuario"] ?? "");
        $codigo = trim($dados["codigo"] ?? "");

        if ($email === "" || $codigo === "") {
            http_response_code(400);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "E-mail e código são obrigatórios."
            ]);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "E-mail inválido."
            ]);
            exit;
        }

        if (!preg_match("/^[0-9]{6}$/", $codigo)) {
            http_response_code(400);
            echo json_encode([
                "status" => "erro",
                "mensagem" => "O código deve possuir 6 números."
            ]);
            exit;
        }

        $usuario = findByEmailService($conexao, $email);

        if (!$usuario) {
            http_response_code(404);

            echo json_encode([
                "status" => "erro",
                "mensagem" => "Usuário não encontrado."
            ]);

            exit;
        }

        $idUsuario = $usuario["id_usuario"];

        if ($usuario["email_verificado_usuario"] === true || $usuario["email_verificado_usuario"] === "t") {
            echo json_encode([
                "status" => "sucesso",
                "mensagem" => "E-mail já verificado."
            ]);
            exit;
        }

        $token = getUsuarioTokenService($conexao, $idUsuario);

        if (!$token) {
            http_response_code(404);

            echo json_encode([
                "status" => "erro",
                "mensagem" => "Nenhum código de verificação válido foi encontrado."
            ]);

            exit;
        }

        if (strtotime($token["expira_em"]) < time()) {
            echo json_encode([
                "status" => "erro",
                "mensagem" => "O código de verificação expirou."
            ]);

            exit;
        }

        if (!password_verify($codigo, $token["token_hash"])) {
            echo json_encode([
                "status" => "erro",
                "mensagem" => "Código de verificação inválido."
            ]);

            exit;
        }

        $conexao->beginTransaction();

        $stmtUsarToken = usarTokenService($conexao, $token);

        verificarEmailService($conexao, $idUsuario);

        $conexao->commit();

        echo json_encode([
            "status" => "sucesso",
            "mensagem" => "E-mail verificado com sucesso!"
        ]);
    } catch (PDOException $erro) {

        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        http_response_code(500);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "Erro ao processar a verificação."
        ]);
    } catch (Exception $erro) {

        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }

        http_response_code(500);

        echo json_encode([
            "status" => "erro",
            "mensagem" => "Erro interno da API."
        ]);
    }
}
function storageEnviar($caminho, $conteudo, $mime)
{
    $base   = rtrim($_ENV["SUPABASE_URL"] ?? getenv("SUPABASE_URL"), "/");
    $chave  = $_ENV["SUPABASE_SERVICE_KEY"] ?? getenv("SUPABASE_SERVICE_KEY");
    $bucket = $_ENV["SUPABASE_BUCKET"] ?? getenv("SUPABASE_BUCKET") ?: "avatars";

    if (!$base || !$chave) {
        error_log("STORAGE: SUPABASE_URL ou SUPABASE_SERVICE_KEY ausente");
        return null;
    }

    $ch = curl_init("$base/storage/v1/object/$bucket/$caminho");

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $conteudo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $chave",
            "apikey: $chave",
            "Content-Type: $mime",
        ],
    ]);

    $resposta = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("ERRO STORAGE ($status): " . $resposta);
        return null;
    }

    return "$base/storage/v1/object/public/$bucket/$caminho";
}


const PERFIL_FOTO_MAX_BYTES    = 2 * 1024 * 1024;
const PERFIL_SENHA_MIN         = 8;
const PERFIL_MAX_TENTATIVAS    = 5;
const PERFIL_INTERVALO_REENVIO = 60; // segundos entre pedidos de código

function perfilResponder(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo);
    exit;
}

function perfilEntrada(): array
{
    return json_decode(file_get_contents("php://input"), true) ?: [];
}

function perfilNovoCodigo(): string
{
    return str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
}

function perfilVerdadeiro($v): bool
{
    return $v === true || $v === "t" || $v === 1 || $v === "1";
}

function perfilEmailCodigo(string $titulo, string $codigo): string
{
    return "<h2>$titulo</h2><p>Seu código de confirmação é:</p><h1>$codigo</h1>"
        . "<p>Ele vale por 10 minutos. Se não foi você, ignore este e-mail.</p>";
}

// Confere o código; devolve o token se estiver tudo certo ou responde com erro
function perfilConferirCodigo(PDO $c, $id, string $tipo, string $codigo): array
{
    $token = perfilBuscarToken($c, $id, $tipo);

    if (!$token || !perfilVerdadeiro($token["valido"])) {
        perfilResponder(400, ["success" => false, "message" => "Código expirado. Peça um novo."]);
    }

    if ((int) $token["tentativas"] >= PERFIL_MAX_TENTATIVAS) {
        perfilUsarToken($c, $token["id_token"]);
        perfilResponder(429, ["success" => false, "message" => "Muitas tentativas. Peça um novo código."]);
    }

    if ($codigo === "" || !password_verify($codigo, $token["token_hash"])) {
        perfilAnotarTentativa($c, $token["id_token"]);
        perfilResponder(400, ["success" => false, "message" => "Código incorreto."]);
    }

    return $token;
}

function perfilChecarReenvio(PDO $c, $id, string $tipo): void
{
    $atual = perfilBuscarToken($c, $id, $tipo);

    if ($atual && (float) $atual["segundos"] < PERFIL_INTERVALO_REENVIO) {
        perfilResponder(429, ["success" => false, "message" => "Aguarde um minuto para pedir outro código."]);
    }
}

// GET /perfil/obter
function perfilObter()
{
    global $conexao;
    $id = autenticar();

    $usuario = perfilBuscarUsuario($conexao, $id);

    if (!$usuario) {
        perfilResponder(404, ["success" => false, "message" => "Usuário não encontrado."]);
    }

    perfilResponder(200, ["success" => true, "user" => perfilParaResposta($conexao, $usuario, $id)]);
}

/** Dados do perfil como o site e o app exibem: telefone formatado e CPF mascarado. */
function perfilParaResposta(PDO $conexao, array $usuario, $id): array
{
    $usuario["email_verificado_usuario"] = perfilVerdadeiro($usuario["email_verificado_usuario"] ?? false);
    $usuario["google_vinculado"] = perfilGoogleVinculado($conexao, $id);
    $usuario["telefone_formatado"] = formatarTelefone($usuario["telefone_usuario"] ?? null);
    $usuario["cpf_mascarado"] = mascararCpf($usuario["cpf_usuario"] ?? null);
    unset($usuario["cpf_usuario"]);

    return $usuario;
}

// POST /auth/googleConfig -> ID do cliente Web do Google (valor público) para o app iniciar o login
function googleConfig()
{
    $clientId = $_ENV["GOOGLE_CLIENT_ID_WEB"] ?? getenv("GOOGLE_CLIENT_ID_WEB") ?: "";

    if ($clientId === "") {
        perfilResponder(503, ["success" => false, "message" => "Login com Google indisponível no momento."]);
    }

    perfilResponder(200, ["success" => true, "client_id" => $clientId]);
}

// POST /auth/perfilAtualizar   {nome_usuario?, telefone_usuario?}  (só os campos enviados mudam)
function perfilAtualizar()
{
    global $conexao;
    $id = autenticar();

    $dados = perfilEntrada();

    $nome = null;
    if (array_key_exists("nome_usuario", $dados)) {
        $nome = preg_replace("/\s+/u", " ", trim((string) $dados["nome_usuario"]));
        $tamanho = mb_strlen($nome, "UTF-8");
        if ($tamanho < 2 || $tamanho > 100) {
            perfilResponder(400, ["success" => false, "message" => "Informe um nome com 2 a 100 caracteres."]);
        }
    }

    $mudarTelefone = array_key_exists("telefone_usuario", $dados);
    $telefone = null;
    if ($mudarTelefone) {
        $telefone = telefoneSomenteDigitos((string) $dados["telefone_usuario"]);
        if ($telefone === null) {
            perfilResponder(400, ["success" => false, "message" => "Informe o telefone com DDD, por exemplo (11) 91234-5678."]);
        }
    }

    if ($nome === null && !$mudarTelefone) {
        perfilResponder(400, ["success" => false, "message" => "Nenhum dado para atualizar."]);
    }

    try {
        perfilAtualizarDados($conexao, $id, $nome, $telefone, $mudarTelefone);
    } catch (PDOException $erro) {
        if ($erro->getCode() === "23505") {
            perfilResponder(409, ["success" => false, "message" => "Este telefone já está em uso por outra conta."]);
        }
        error_log("Erro ao atualizar perfil: " . $erro->getMessage());
        perfilResponder(500, ["success" => false, "message" => "Não foi possível salvar agora."]);
    }

    $usuario = perfilBuscarUsuario($conexao, $id);

    perfilResponder(200, ["success" => true, "message" => "Dados atualizados.", "user" => perfilParaResposta($conexao, $usuario, $id)]);
}

// POST /perfil/foto   (multipart/form-data, campo "foto")
function perfilFoto()
{
    global $conexao;
    $id = autenticar();

    $arquivo = $_FILES["foto"] ?? null;

    if (!$arquivo || $arquivo["error"] !== UPLOAD_ERR_OK) {
        perfilResponder(400, ["success" => false, "message" => "Nenhuma foto enviada (máximo 2 MB)."]);
    }

    if ($arquivo["size"] > PERFIL_FOTO_MAX_BYTES) {
        perfilResponder(400, ["success" => false, "message" => "A foto deve ter no máximo 2 MB."]);
    }

    // o tipo é lido do conteúdo do arquivo, não do nome que o usuário mandou
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $arquivo["tmp_name"]);
    finfo_close($finfo);

    $extensoes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

    if (!isset($extensoes[$mime])) {
        perfilResponder(400, ["success" => false, "message" => "Use uma imagem JPG, PNG ou WEBP."]);
    }

    $caminho = "usuarios/$id-" . bin2hex(random_bytes(6)) . "." . $extensoes[$mime];
    $url     = storageEnviar($caminho, file_get_contents($arquivo["tmp_name"]), $mime);

    if (!$url) {
        perfilResponder(502, ["success" => false, "message" => "Não foi possível salvar a foto agora."]);
    }

    perfilSalvarFoto($conexao, $id, $url);

    perfilResponder(200, ["success" => true, "foto_usuario" => $url]);
}

// POST /perfil/solicitarTrocaSenha   -> envia o código para o e-mail atual
function perfilSolicitarTrocaSenha()
{
    global $conexao;
    $id = autenticar();

    $usuario = perfilBuscarUsuario($conexao, $id);

    if (!$usuario) {
        perfilResponder(404, ["success" => false, "message" => "Usuário não encontrado."]);
    }

    perfilChecarReenvio($conexao, $id, "troca_senha");

    $codigo = perfilNovoCodigo();
    perfilCriarToken($conexao, $id, "troca_senha", password_hash($codigo, PASSWORD_DEFAULT));

    if (!enviarEmailBrevo($usuario["email_usuario"], "Troca de senha - Accuracy", perfilEmailCodigo("Troca de senha", $codigo))) {
        perfilResponder(502, ["success" => false, "message" => "Não foi possível enviar o e-mail agora."]);
    }

    perfilResponder(200, ["success" => true, "message" => "Enviamos um código para o seu e-mail."]);
}

// POST /perfil/confirmarTrocaSenha   {codigo, nova_senha}
function perfilConfirmarTrocaSenha()
{
    global $conexao;
    $id = autenticar();

    $dados     = perfilEntrada();
    $codigo    = trim($dados["codigo"] ?? "");
    $novaSenha = $dados["nova_senha"] ?? "";

    if (strlen($novaSenha) < PERFIL_SENHA_MIN) {
        perfilResponder(400, ["success" => false, "message" => "A nova senha precisa ter pelo menos " . PERFIL_SENHA_MIN . " caracteres."]);
    }

    $token = perfilConferirCodigo($conexao, $id, "troca_senha", $codigo);

    perfilAtualizarSenha($conexao, $id, password_hash($novaSenha, PASSWORD_DEFAULT));
    perfilUsarToken($conexao, $token["id_token"]);
    perfilRevogarSessoes($conexao, $id);

    perfilResponder(200, ["success" => true, "message" => "Senha alterada com sucesso."]);
}


function perfilSolicitarTrocaEmail()
{
    global $conexao;

    $id = autenticar();
    $dados = perfilEntrada();

    $novoEmail = strtolower(trim($dados["novo_email"] ?? ""));

    if (!filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
        perfilResponder(400, [
            "success" => false,
            "message" => "E-mail inválido."
        ]);
    }

    $usuario = perfilBuscarUsuario($conexao, $id);

    if (!$usuario) {
        perfilResponder(404, [
            "success" => false,
            "message" => "Usuário não encontrado."
        ]);
    }

    if (strtolower($usuario["email_usuario"]) === $novoEmail) {
        perfilResponder(400, [
            "success" => false,
            "message" => "Esse já é o seu e-mail atual."
        ]);
    }

    if (perfilEmailEmUso($conexao, $novoEmail, $id)) {
        perfilResponder(409, [
            "success" => false,
            "message" => "Este e-mail já está em uso."
        ]);
    }

    perfilChecarReenvio(
        $conexao,
        $id,
        "troca_email_autorizacao"
    );

    $codigo = perfilNovoCodigo();

    perfilCriarToken(
        $conexao,
        $id,
        "troca_email_autorizacao",
        password_hash($codigo, PASSWORD_DEFAULT),
        $novoEmail
    );

    if (!enviarEmailBrevo(
        $usuario["email_usuario"],
        "Autorização de troca de e-mail - Accuracy",
        perfilEmailCodigo("Autorize a troca de e-mail", $codigo)
    )) {
        perfilResponder(502, [
            "success" => false,
            "message" => "Não foi possível enviar o código para seu e-mail atual."
        ]);
    }

    perfilResponder(200, [
        "success" => true,
        "message" => "Código de autorização enviado para seu e-mail atual."
    ]);
}


// POST /perfil/confirmarTrocaEmail   {codigo}
function perfilConfirmarTrocaEmail()
{
    global $conexao;
    $id = autenticar();

    $dados  = perfilEntrada();
    $codigo = trim($dados["codigo"] ?? "");

    $token     = perfilConferirCodigo($conexao, $id, "troca_email", $codigo);
    $novoEmail = $token["novo_email"];

    // outra conta pode ter pegado esse e-mail enquanto o código estava aberto
    if (perfilEmailEmUso($conexao, $novoEmail, $id)) {
        perfilResponder(409, ["success" => false, "message" => "Este e-mail já está em uso."]);
    }

    $antigo = perfilBuscarUsuario($conexao, $id)["email_usuario"] ?? null;

    perfilAtualizarEmail($conexao, $id, $novoEmail);
    perfilUsarToken($conexao, $token["id_token"]);

    // aviso para o e-mail antigo (se falhar, a troca continua valendo)
    if ($antigo) {
        enviarEmailBrevo(
            $antigo,
            "Seu e-mail foi alterado - Accuracy",
            "<h2>E-mail alterado</h2><p>O e-mail da sua conta Accuracy foi trocado para <b>$novoEmail</b>.</p>"
                . "<p>Se não foi você, entre em contato com o suporte imediatamente.</p>"
        );
    }

    perfilResponder(200, ["success" => true, "message" => "E-mail alterado com sucesso.", "email_usuario" => $novoEmail]);
}

function perfilAutorizarTrocaEmail()
{
    global $conexao;

    $id = autenticar();
    $dados = perfilEntrada();

    $codigo = trim($dados["codigo"] ?? "");

    $token = perfilConferirCodigo(
        $conexao,
        $id,
        "troca_email_autorizacao",
        $codigo
    );

    $novoEmail = strtolower(trim($token["novo_email"] ?? ""));

    if (!filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
        perfilResponder(400, [
            "success" => false,
            "message" => "E-mail de destino inválido. Solicite uma nova alteração."
        ]);
    }

    if (perfilEmailEmUso($conexao, $novoEmail, $id)) {
        perfilResponder(409, [
            "success" => false,
            "message" => "Este e-mail já está em uso."
        ]);
    }

    // Consome o código enviado ao e-mail atual.
    perfilUsarToken($conexao, $token["id_token"]);

    // Gera o segundo código para o novo endereço.
    $novoCodigo = perfilNovoCodigo();

    perfilCriarToken(
        $conexao,
        $id,
        "troca_email",
        password_hash($novoCodigo, PASSWORD_DEFAULT),
        $novoEmail
    );

    if (!enviarEmailBrevo(
        $novoEmail,
        "Confirme seu novo e-mail - Accuracy",
        perfilEmailCodigo("Confirme seu novo e-mail", $novoCodigo)
    )) {
        perfilResponder(502, [
            "success" => false,
            "message" => "Autorização realizada, mas não foi possível enviar o código ao novo e-mail. Solicite novamente."
        ]);
    }

    perfilResponder(200, [
        "success" => true,
        "message" => "Mudança autorizada! Código enviado ao novo e-mail."
    ]);
}

// ---------------------------------------------------------------- aparelhos conectados

// POST /auth/sessoes  -> aparelhos com login aberto ("atual" marca este aparelho)
function sessoesListar()
{
    global $conexao;
    $id = (int) autenticar();
    $atual = $GLOBALS["sessao_atual"] ?? null;

    $sessoes = array_map(static function (array $s) use ($atual): array {
        $s["atual"] = $atual !== null && $s["id_sessao"] === (int) $atual;
        return $s;
    }, sessaoListarAtivas($conexao, $id));
    // Este aparelho primeiro; os outros seguem do uso mais recente para o mais antigo.
    usort($sessoes, static fn(array $a, array $b): int => (int) $b["atual"] <=> (int) $a["atual"]);

    perfilResponder(200, ["success" => true, "sessoes" => $sessoes]);
}

// POST /auth/sessaoEncerrar  {id_sessao}  -> desconecta um aparelho
function sessaoEncerrarController()
{
    global $conexao;
    $id = (int) autenticar();
    $idSessao = filter_var(perfilEntrada()["id_sessao"] ?? null, FILTER_VALIDATE_INT);

    if (!$idSessao || $idSessao <= 0) {
        perfilResponder(400, ["success" => false, "message" => "Informe o aparelho que será desconectado."]);
    }
    if (!sessaoEncerrar($conexao, $id, $idSessao)) {
        perfilResponder(404, ["success" => false, "message" => "Esse aparelho já estava desconectado."]);
    }

    $atual = $GLOBALS["sessao_atual"] ?? null;
    perfilResponder(200, [
        "success" => true,
        "message" => "Aparelho desconectado.",
        "encerrou_atual" => $atual !== null && (int) $atual === $idSessao
    ]);
}

// POST /auth/sessoesEncerrar  {manter_atual: true|false}  -> desconecta todos (ou todos os outros)
function sessoesEncerrarController()
{
    global $conexao;
    $id = (int) autenticar();
    $atual = $GLOBALS["sessao_atual"] ?? null;
    $manterAtual = (perfilEntrada()["manter_atual"] ?? true) !== false;

    $encerradas = sessaoEncerrarTodas($conexao, $id, $manterAtual && $atual !== null ? (int) $atual : null);

    perfilResponder(200, [
        "success" => true,
        "message" => $manterAtual ? "Os outros aparelhos foram desconectados." : "Todos os aparelhos foram desconectados.",
        "encerradas" => $encerradas,
        "encerrou_atual" => !$manterAtual || $atual === null
    ]);
}

// POST /auth/sair  {refresh_token}  -> logout: encerra a sessão deste aparelho no servidor.
// Usa o refresh token (não o de acesso) para funcionar mesmo depois que o acesso venceu;
// a resposta é sempre a mesma, então não revela se o token existia.
function sairDaConta()
{
    global $conexao;
    $refreshToken = trim((string) (perfilEntrada()["refresh_token"] ?? ""));

    if ($refreshToken !== "") {
        sessaoEncerrarPorRefreshToken($conexao, criarHashRefreshToken($refreshToken));
    }

    perfilResponder(200, ["success" => true, "message" => "Você saiu da conta neste aparelho."]);
}

// ---------------------------------------------------------------- esqueci a senha

const RECUPERAR_SENHA_TIPO = "recuperar_senha";

// POST /auth/recuperarSenha  {email_usuario}  -> envia um código de 6 números
// A resposta é a mesma exista ou não a conta, para não revelar quais e-mails estão cadastrados.
function recuperarSenha()
{
    global $conexao;
    $email = mb_strtolower(trim((string) (perfilEntrada()["email_usuario"] ?? "")), "UTF-8");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        perfilResponder(400, ["success" => false, "message" => "Informe um e-mail válido."]);
    }

    $resposta = ["success" => true, "message" => "Se houver uma conta com esse e-mail, enviamos um código de 6 números para ele."];
    $usuario = findByEmailService($conexao, $email);
    if (!$usuario) {
        perfilResponder(200, $resposta);
    }

    $id = (int) $usuario["id_usuario"];
    $anterior = perfilBuscarToken($conexao, $id, RECUPERAR_SENHA_TIPO);
    if ($anterior && (float) $anterior["segundos"] < PERFIL_INTERVALO_REENVIO) {
        perfilResponder(200, $resposta);
    }

    $codigo = perfilNovoCodigo();
    perfilCriarToken($conexao, $id, RECUPERAR_SENHA_TIPO, password_hash($codigo, PASSWORD_DEFAULT));

    if (!enviarEmailBrevo($email, "Recuperação de senha - Accuracy", perfilEmailCodigo("Recuperação de senha", $codigo))) {
        // Sem e-mail, o código não serve: é cancelado para o próximo pedido tentar enviar de novo
        // (senão o intervalo de reenvio responderia "enviamos" sem mandar nada).
        $criado = perfilBuscarToken($conexao, $id, RECUPERAR_SENHA_TIPO);
        if ($criado) {
            perfilUsarToken($conexao, $criado["id_token"]);
        }
        perfilResponder(502, ["success" => false, "message" => "Não foi possível enviar o e-mail agora. Tente de novo em alguns minutos."]);
    }

    perfilResponder(200, $resposta);
}

// POST /auth/redefinirSenha  {email_usuario, codigo, nova_senha}
// Troca a senha, confirma o e-mail (o código chegou nele) e desconecta todos os aparelhos.
function redefinirSenha()
{
    global $conexao;
    $dados = perfilEntrada();
    $email = mb_strtolower(trim((string) ($dados["email_usuario"] ?? "")), "UTF-8");
    $codigo = trim((string) ($dados["codigo"] ?? ""));
    $novaSenha = (string) ($dados["nova_senha"] ?? "");

    if (strlen($novaSenha) < PERFIL_SENHA_MIN) {
        perfilResponder(400, ["success" => false, "message" => "A nova senha precisa ter pelo menos " . PERFIL_SENHA_MIN . " caracteres."]);
    }
    if (!preg_match("/^[0-9]{6}$/", $codigo)) {
        perfilResponder(400, ["success" => false, "message" => "O código tem 6 números."]);
    }

    $usuario = filter_var($email, FILTER_VALIDATE_EMAIL) ? findByEmailService($conexao, $email) : null;
    if (!$usuario) {
        perfilResponder(400, ["success" => false, "message" => "Código expirado. Peça um novo."]);
    }

    $id = (int) $usuario["id_usuario"];
    $token = perfilConferirCodigo($conexao, $id, RECUPERAR_SENHA_TIPO, $codigo);

    perfilAtualizarSenha($conexao, $id, password_hash($novaSenha, PASSWORD_DEFAULT));
    perfilUsarToken($conexao, $token["id_token"]);
    $conexao->prepare("UPDATE usuario SET email_verificado_usuario = TRUE WHERE id_usuario = :id")
        ->execute([":id" => $id]);
    perfilRevogarSessoes($conexao, $id);

    perfilResponder(200, ["success" => true, "message" => "Senha redefinida. Entre com a nova senha."]);
}
