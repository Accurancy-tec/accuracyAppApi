<?php

function login()
{
    header("Content-Type: application/json; charset=UTF-8");

    require_once __DIR__ . "/../Middleware/Authentication.php";
    require_once __DIR__ . "/../../database/conexao.php";

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

    $usuario = loginService($conexao, $email);

    if (!$usuario) {
        echo json_encode([
            "success" => false,
            "message" => "Email ou senha incorretos"
        ]);
        exit;
    }

    if ($senha !== $usuario["senha_usuario"]) {
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

function registrarNovoUsuario()
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

    $usuario = registrarNovoUsuarioService($conexao, $nome, $email, $senhaHash, $cpf, $telefone);

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

    gerarTokenUsuario($conexao, $idUsuario, $codigoHash, $expiraEm);

    $enviado = enviarCodigo($email, $codigo);

    if (!$enviado) {
        echo json_encode([
            "status" => "erro",
            "mensagem" => "Não foi possível enviar o código de verificação."
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
    header("Content-Type: application/json; charset=UTF-8");

    require_once __DIR__ . "/../src/Middleware/Authentication.php";
    require_once __DIR__ . "/../database/conexao.php";

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

    $usuario = loginSiteService($conexao, $email);

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

        $enviado = enviarCodigo(
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

        $dados = json_decode(file_get_contents("php://input"),true);

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
