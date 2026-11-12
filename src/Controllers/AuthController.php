<?php
require_once __DIR__ . "/../../config/email.php";
require_once __DIR__ . "/../Services/AuthService.php";
require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Middleware/Authentication.php";

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

    $accessToken = criarToken($usuario["id_usuario"]);

    $refreshToken = criarRefreshToken();

    $refreshTokenHash = criarHashRefreshToken($refreshToken);

    $expiraEm = date(
        "Y-m-d H:i:s",
        time() + (60 * 60 * 24 * 30)
    );


    criarSessaoUsuarioService($conexao, $usuario, $refreshTokenHash, $expiraEm);

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

function registrarUsuario()
{
    global $conexao;

    $dados = json_decode(file_get_contents("php://input"), true);

    $nome = $dados['nome_usuario'];
    $email = $dados['email_usuario'];
    $senha = $dados['senha_usuario'];
    $cpf = $dados['cpf_usuario'];
    $telefone = $dados['telefone_usuario'];

    if (empty($nome) || empty($email) || empty($senha) || empty($cpf) || empty($telefone)) {
        echo json_encode([
            "status" => "erro",
            "mensagem" => "Todos os campos são obrigatórios."
        ]);
        exit;
    }

    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    $usuario = registrarNovoUsuarioService(
        $conexao,
        $nome,
        $email,
        $senhaHash,
        $cpf,
        $telefone
    );

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

    $novoAccessToken = criarToken($sessao["id_usuario"]);

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

    $token = criarToken($usuario["id_usuario"]);

    echo json_encode([
        "success" => true,
        "message" => "Login realizado com sucesso",
        "token" => $token,
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
 
    perfilResponder(200, ["success" => true, "user" => $usuario]);
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
 
// POST /perfil/solicitarTrocaEmail   {novo_email}   -> envia o código para o NOVO e-mail
function perfilSolicitarTrocaEmail()
{
    global $conexao;
    $id = autenticar();
 
    $dados     = perfilEntrada();
    $novoEmail = strtolower(trim($dados["novo_email"] ?? ""));
 
    if (!filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
        perfilResponder(400, ["success" => false, "message" => "E-mail inválido."]);
    }
 
    $usuario = perfilBuscarUsuario($conexao, $id);
 
    if (!$usuario) {
        perfilResponder(404, ["success" => false, "message" => "Usuário não encontrado."]);
    }
 
    if (strtolower($usuario["email_usuario"]) === $novoEmail) {
        perfilResponder(400, ["success" => false, "message" => "Esse já é o seu e-mail atual."]);
    }
 
    if (perfilEmailEmUso($conexao, $novoEmail, $id)) {
        perfilResponder(409, ["success" => false, "message" => "Este e-mail já está em uso."]);
    }
 
    perfilChecarReenvio($conexao, $id, "troca_email");
 
    $codigo = perfilNovoCodigo();
    perfilCriarToken($conexao, $id, "troca_email", password_hash($codigo, PASSWORD_DEFAULT), $novoEmail);
 
    if (!enviarEmailBrevo($novoEmail, "Confirme seu novo e-mail - Accuracy", perfilEmailCodigo("Confirme seu novo e-mail", $codigo))) {
        perfilResponder(502, ["success" => false, "message" => "Não foi possível enviar o e-mail agora."]);
    }
 
    perfilResponder(200, ["success" => true, "message" => "Enviamos um código para o novo e-mail."]);
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
