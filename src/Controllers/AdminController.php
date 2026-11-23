<?php

/**
 * Área do administrador (arquivo novo, nada existente foi alterado).
 *
 * - loginUnico(): o mesmo formulário entra como administrador OU como usuário.
 * - admin*():     ações do painel. Todas exigem token de administrador.
 *
 * O token do adm usa o campo "adm" (e iss diferente), então NÃO serve nas rotas
 * de usuário e o token de usuário NÃO serve aqui. O nível de acesso (Editor/Master)
 * é conferido no banco a cada chamada.
 */

require_once __DIR__ . "/AuthController.php";   // carrega conexão, JWT e loginSite()

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

const ADMIN_ISS = "accuracy-admin-api";

/* ===================== utilitários ===================== */

function adminResponder(int $status, array $corpo): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($corpo);
    exit;
}

function adminEntrada(): array
{
    return json_decode(file_get_contents("php://input"), true) ?: [];
}

function adminBool($v): bool
{
    return $v === true || $v === "t" || $v === "true" || $v === 1 || $v === "1";
}

function adminCriarToken($idAdmin): string
{
    return JWT::encode([
        "iss" => ADMIN_ISS,
        "iat" => time(),
        "exp" => time() + (60 * 60 * 2),   // 2 horas
        "adm" => (int) $idAdmin
    ], $_ENV["SECRET_KEY"], "HS256");
}

// Confere o token e devolve o administrador (consultado no banco).
// $soMaster = true -> só nível Master passa.
function adminAutenticar(bool $soMaster = false): array
{
    global $conexao;

    $cabecalho = $_SERVER["HTTP_AUTHORIZATION"] ?? "";

    if (!preg_match('/Bearer\s+(\S+)/', $cabecalho, $m)) {
        adminResponder(401, ["success" => false, "message" => "Token não enviado."]);
    }

    try {
        $dados = JWT::decode($m[1], new Key($_ENV["SECRET_KEY"], "HS256"));
    } catch (Exception $e) {
        adminResponder(401, ["success" => false, "message" => "Sessão expirada. Entre novamente."]);
    }

    if (($dados->iss ?? "") !== ADMIN_ISS || empty($dados->adm)) {
        adminResponder(403, ["success" => false, "message" => "Acesso negado."]);
    }

    $st = $conexao->prepare(
        "SELECT id_administrador, nome_administrador, email_administrador,
                nivel_acesso_administrador
           FROM administrador WHERE id_administrador = :id"
    );
    $st->bindValue(":id", (int) $dados->adm, PDO::PARAM_INT);
    $st->execute();
    $admin = $st->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        adminResponder(403, ["success" => false, "message" => "Acesso negado."]);
    }

    if ($soMaster && $admin["nivel_acesso_administrador"] !== "Master") {
        adminResponder(403, ["success" => false, "message" => "Apenas administradores Master podem fazer isso."]);
    }

    return $admin;
}

/* ===================== login único ===================== */

// POST /auth/loginUnico
// Administrador -> devolve token de adm. Qualquer outro caso -> loginSite() normal.
function loginUnico()
{
    global $conexao;

    $dados = adminEntrada();
    $email = trim($dados["email_usuario"] ?? "");
    $senha = $dados["senha_usuario"] ?? "";

    if ($email === "" || $senha === "") {
        loginSite();            // ele mesmo responde "E-mail e senha são obrigatórios."
        return;
    }

    // 1) é administrador?
    $st = $conexao->prepare(
        "SELECT id_administrador, nome_administrador, email_administrador,
                senha_administrador, nivel_acesso_administrador
           FROM administrador
          WHERE lower(email_administrador) = lower(:email) LIMIT 1"
    );
    $st->bindValue(":email", $email);
    $st->execute();
    $admin = $st->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($senha, $admin["senha_administrador"])) {
        echo json_encode([
            "success" => true,
            "admin"   => true,
            "message" => "Login de administrador realizado com sucesso",
            "token"   => adminCriarToken($admin["id_administrador"]),
            "user"    => [
                "id_administrador" => $admin["id_administrador"],
                "nome_usuario"     => $admin["nome_administrador"],
                "email_usuario"    => $admin["email_administrador"],
                "nivel"            => $admin["nivel_acesso_administrador"]
            ]
        ]);
        return;
    }

    // 2) é usuário com conta desativada? (só avisa quem acertou a senha)
    $st = $conexao->prepare(
        "SELECT senha_usuario, ativo_usuario FROM usuario
          WHERE email_usuario = :email LIMIT 1"
    );
    $st->bindValue(":email", $email);
    $st->execute();
    $usuario = $st->fetch(PDO::FETCH_ASSOC);

    if (
        $usuario
        && password_verify($senha, $usuario["senha_usuario"])
        && !adminBool($usuario["ativo_usuario"])
    ) {
        echo json_encode([
            "success" => false,
            "message" => "Esta conta está desativada. Fale com o suporte."
        ]);
        return;
    }

    // 3) fluxo normal de usuário (inalterado)
    loginSite();
}

/* ===================== usuários ===================== */

// POST /auth/adminListarUsuarios
function adminListarUsuarios()
{
    global $conexao;
    adminAutenticar();

    $linhas = $conexao->query(
        "SELECT id_usuario, nome_usuario, email_usuario, telefone_usuario,
                ativo_usuario, email_verificado_usuario, criado_em
           FROM usuario ORDER BY id_usuario DESC LIMIT 500"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($linhas as &$l) {
        $l["ativo_usuario"] = adminBool($l["ativo_usuario"]);
        $l["email_verificado_usuario"] = adminBool($l["email_verificado_usuario"]);
    }
    unset($l);

    adminResponder(200, [
        "success"  => true,
        "total"    => count($linhas),
        "ativos"   => count(array_filter($linhas, fn($u) => $u["ativo_usuario"])),
        "usuarios" => $linhas
    ]);
}

// POST /auth/adminAlterarUsuario
// corpo: id_usuario + (nome_usuario, email_usuario, telefone_usuario, ativo_usuario) opcionais
function adminAlterarUsuario()
{
    global $conexao;
    adminAutenticar();

    $d  = adminEntrada();
    $id = (int) ($d["id_usuario"] ?? 0);

    if ($id <= 0) {
        adminResponder(400, ["success" => false, "message" => "Usuário inválido."]);
    }

    $nome     = isset($d["nome_usuario"]) ? trim($d["nome_usuario"]) : null;
    $email    = isset($d["email_usuario"]) ? trim($d["email_usuario"]) : null;
    $telefone = isset($d["telefone_usuario"]) ? trim($d["telefone_usuario"]) : null;
    $ativo    = array_key_exists("ativo_usuario", $d)
        ? (adminBool($d["ativo_usuario"]) ? "true" : "false") : null;

    if ($nome === "" || $email === "") {
        adminResponder(400, ["success" => false, "message" => "Nome e e-mail não podem ficar vazios."]);
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        adminResponder(400, ["success" => false, "message" => "E-mail inválido."]);
    }

    try {
        $st = $conexao->prepare(
            "UPDATE usuario SET
                nome_usuario     = COALESCE(:nome, nome_usuario),
                email_usuario    = COALESCE(:email, email_usuario),
                telefone_usuario = COALESCE(:tel, telefone_usuario),
                ativo_usuario    = COALESCE(CAST(:ativo AS boolean), ativo_usuario),
                atualizado_em    = NOW()
              WHERE id_usuario = :id"
        );
        $st->bindValue(":nome", $nome);
        $st->bindValue(":email", $email);
        $st->bindValue(":tel", $telefone);
        $st->bindValue(":ativo", $ativo);
        $st->bindValue(":id", $id, PDO::PARAM_INT);
        $st->execute();
    } catch (PDOException $e) {
        if ($e->getCode() === "23505") {
            adminResponder(409, ["success" => false, "message" => "Este e-mail já está em uso."]);
        }
        adminResponder(500, ["success" => false, "message" => "Erro ao salvar o usuário."]);
    }

    if ($st->rowCount() === 0) {
        adminResponder(404, ["success" => false, "message" => "Usuário não encontrado."]);
    }

    // conta desativada -> derruba as sessões abertas do app
    if ($ativo === "false") {
        $conexao->prepare(
            "UPDATE sessao_usuario SET revogado_em = NOW()
              WHERE id_usuario = :id AND revogado_em IS NULL"
        )->execute([":id" => $id]);
    }

    adminResponder(200, ["success" => true, "message" => "Usuário atualizado."]);
}

// POST /auth/adminDeletarUsuario   corpo: id_usuario
function adminDeletarUsuario()
{
    global $conexao;
    adminAutenticar();

    $id = (int) (adminEntrada()["id_usuario"] ?? 0);

    if ($id <= 0) {
        adminResponder(400, ["success" => false, "message" => "Usuário inválido."]);
    }

    try {
        $conexao->beginTransaction();

        // transacao_saldo não tem ON DELETE CASCADE: apaga antes
        $conexao->prepare("DELETE FROM transacao_saldo WHERE id_usuario = :id")
            ->execute([":id" => $id]);

        // o resto (carteira, aportes, sessões, progresso...) cai em cascata
        $st = $conexao->prepare("DELETE FROM usuario WHERE id_usuario = :id");
        $st->execute([":id" => $id]);

        if ($st->rowCount() === 0) {
            $conexao->rollBack();
            adminResponder(404, ["success" => false, "message" => "Usuário não encontrado."]);
        }

        $conexao->commit();
    } catch (PDOException $e) {
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        adminResponder(500, ["success" => false, "message" => "Não foi possível excluir o usuário."]);
    }

    adminResponder(200, ["success" => true, "message" => "Usuário excluído."]);
}

/* ===================== notícias ===================== */

// POST /auth/adminCategoriasNoticia
function adminCategoriasNoticia()
{
    global $conexao;
    adminAutenticar();

    $lista = $conexao->query(
        "SELECT unnest(enum_range(NULL::categoria_noticia_enum))::text AS categoria"
    )->fetchAll(PDO::FETCH_COLUMN);

    adminResponder(200, ["success" => true, "categorias" => $lista]);
}

// POST /auth/adminListarNoticias
function adminListarNoticias()
{
    global $conexao;
    adminAutenticar();

    $linhas = $conexao->query(
        "SELECT n.id_noticia, n.titulo_noticia, n.resumo_noticia,
                n.categoria_noticia::text AS categoria_noticia,
                n.publicada_em, a.nome_administrador
           FROM noticia n
           JOIN administrador a ON a.id_administrador = n.id_administrador
          ORDER BY n.criado_em DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);

    adminResponder(200, ["success" => true, "noticias" => $linhas]);
}

// POST /auth/adminCriarNoticia
// corpo: titulo_noticia, conteudo_noticia, resumo_noticia?, categoria_noticia?
function adminCriarNoticia()
{
    global $conexao;
    $admin = adminAutenticar();

    $d        = adminEntrada();
    $titulo   = trim($d["titulo_noticia"] ?? "");
    $conteudo = trim($d["conteudo_noticia"] ?? "");
    $resumo   = trim($d["resumo_noticia"] ?? "");
    $categ    = trim($d["categoria_noticia"] ?? "");

    if ($titulo === "" || $conteudo === "") {
        adminResponder(400, ["success" => false, "message" => "Título e conteúdo são obrigatórios."]);
    }

    try {
        $st = $conexao->prepare(
            "INSERT INTO noticia (id_administrador, titulo_noticia, resumo_noticia,
                                  conteudo_noticia, categoria_noticia, publicada_em)
             VALUES (:adm, :titulo, :resumo, :conteudo,
                     COALESCE(CAST(:categ AS categoria_noticia_enum), 'Mercado'), NOW())"
        );
        $st->bindValue(":adm", (int) $admin["id_administrador"], PDO::PARAM_INT);
        $st->bindValue(":titulo", $titulo);
        $st->bindValue(":resumo", $resumo === "" ? null : $resumo);
        $st->bindValue(":conteudo", $conteudo);
        $st->bindValue(":categ", $categ === "" ? null : $categ);
        $st->execute();
    } catch (PDOException $e) {
        adminResponder(400, ["success" => false, "message" => "Não foi possível publicar (confira a categoria)."]);
    }

    adminResponder(201, ["success" => true, "message" => "Notícia publicada."]);
}

// POST /auth/adminExcluirNoticia   corpo: id_noticia
function adminExcluirNoticia()
{
    global $conexao;
    adminAutenticar();

    $id = (int) (adminEntrada()["id_noticia"] ?? 0);

    $st = $conexao->prepare("DELETE FROM noticia WHERE id_noticia = :id");
    $st->execute([":id" => $id]);

    if ($st->rowCount() === 0) {
        adminResponder(404, ["success" => false, "message" => "Notícia não encontrada."]);
    }

    adminResponder(200, ["success" => true, "message" => "Notícia excluída."]);
}

/* ===================== administradores ===================== */

// POST /auth/adminListarAdmins
function adminListarAdmins()
{
    global $conexao;
    $eu = adminAutenticar();

    $linhas = $conexao->query(
        "SELECT id_administrador, nome_administrador, email_administrador,
                nivel_acesso_administrador::text AS nivel, criado_em
           FROM administrador ORDER BY id_administrador"
    )->fetchAll(PDO::FETCH_ASSOC);

    adminResponder(200, [
        "success" => true,
        "meu_nivel" => $eu["nivel_acesso_administrador"],
        "administradores" => $linhas
    ]);
}

// POST /auth/adminCriarAdmin   (só Master)
// corpo: nome_administrador, email_administrador, senha_administrador, nivel
function adminCriarAdmin()
{
    global $conexao;
    adminAutenticar(true);

    $d     = adminEntrada();
    $nome  = trim($d["nome_administrador"] ?? "");
    $email = trim($d["email_administrador"] ?? "");
    $senha = $d["senha_administrador"] ?? "";
    $nivel = in_array($d["nivel"] ?? "", ["Editor", "Master"], true) ? $d["nivel"] : "Editor";

    if ($nome === "" || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 8) {
        adminResponder(400, [
            "success" => false,
            "message" => "Informe nome, e-mail válido e senha com pelo menos 8 caracteres."
        ]);
    }

    try {
        $st = $conexao->prepare(
            "INSERT INTO administrador (nome_administrador, email_administrador,
                                        senha_administrador, nivel_acesso_administrador)
             VALUES (:nome, :email, :senha, CAST(:nivel AS nivel_acesso_administrador_enum))"
        );
        $st->bindValue(":nome", $nome);
        $st->bindValue(":email", $email);
        $st->bindValue(":senha", password_hash($senha, PASSWORD_DEFAULT));
        $st->bindValue(":nivel", $nivel);
        $st->execute();
    } catch (PDOException $e) {
        if ($e->getCode() === "23505") {
            adminResponder(409, ["success" => false, "message" => "Já existe um administrador com este e-mail."]);
        }
        adminResponder(500, ["success" => false, "message" => "Erro ao criar o administrador."]);
    }

    adminResponder(201, ["success" => true, "message" => "Administrador criado."]);
}

/* ===================== utilidades de enum / URL ===================== */

// Nome do tipo enum de uma coluna (ex.: curso.categoria_curso)
function adminTipoEnum(string $tabela, string $coluna): string
{
    global $conexao;
    $st = $conexao->prepare(
        "SELECT udt_name FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = :t AND column_name = :c"
    );
    $st->execute([":t" => $tabela, ":c" => $coluna]);
    $nome = (string) $st->fetchColumn();

    if (!preg_match('/^[A-Za-z0-9_]+$/', $nome)) {
        adminResponder(500, ["success" => false, "message" => "Tipo da coluna $coluna não encontrado."]);
    }
    return $nome;
}

// Valores possíveis do enum de uma coluna
function adminOpcoesEnum(string $tabela, string $coluna): array
{
    global $conexao;
    $st = $conexao->prepare(
        "SELECT e.enumlabel
           FROM pg_enum e
           JOIN pg_attribute a ON a.atttypid = e.enumtypid
           JOIN pg_class c ON c.oid = a.attrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'public' AND c.relname = :t AND a.attname = :c AND a.attnum > 0
          ORDER BY e.enumsortorder"
    );
    $st->execute([":t" => $tabela, ":c" => $coluna]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// Só aceita http(s) (evita javascript: e afins)
function adminUrlOk($u): bool
{
    return is_string($u) && strlen($u) <= 500
        && preg_match('#^https?://#i', $u)
        && filter_var($u, FILTER_VALIDATE_URL) !== false;
}

/* ===================== cursos / vídeos ===================== */

// POST /auth/adminOpcoesCurso
function adminOpcoesCurso()
{
    adminAutenticar();
    adminResponder(200, [
        "success"    => true,
        "categorias" => adminOpcoesEnum("curso", "categoria_curso"),
        "niveis"     => adminOpcoesEnum("curso", "nivel_curso")
    ]);
}

// POST /auth/adminListarCursos
function adminListarCursos()
{
    global $conexao;
    adminAutenticar();

    $linhas = $conexao->query(
        "SELECT id_curso, titulo_curso, descricao_curso,
                categoria_curso::text AS categoria_curso, nivel_curso::text AS nivel_curso,
                duracao_minutos_curso, url_video_curso, url_thumb_curso, criado_em
           FROM curso ORDER BY id_curso DESC LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC);

    adminResponder(200, ["success" => true, "cursos" => $linhas]);
}

// Valida os campos do curso. $completo = true exige todos (criar); false aceita parcial (editar).
function adminCursoValidar(array $d, bool $completo): array
{
    $v = [
        "titulo"    => isset($d["titulo_curso"]) ? trim($d["titulo_curso"]) : null,
        "descricao" => isset($d["descricao_curso"]) ? trim($d["descricao_curso"]) : null,
        "categoria" => isset($d["categoria_curso"]) ? trim($d["categoria_curso"]) : null,
        "nivel"     => isset($d["nivel_curso"]) ? trim($d["nivel_curso"]) : null,
        "duracao"   => isset($d["duracao_minutos_curso"]) && $d["duracao_minutos_curso"] !== ""
            ? (int) $d["duracao_minutos_curso"] : null,
        "video"     => isset($d["url_video_curso"]) ? trim($d["url_video_curso"]) : null,
        "thumb"     => isset($d["url_thumb_curso"]) ? trim($d["url_thumb_curso"]) : null,
    ];

    foreach (["descricao", "thumb"] as $k) {
        if ($v[$k] === "") $v[$k] = null;       // opcionais vazios -> não mexe
    }

    $erro = null;

    if ($completo) {
        foreach (["titulo", "categoria", "nivel", "duracao", "video"] as $k) {
            if ($v[$k] === null || $v[$k] === "") {
                $erro = "Preencha título, categoria, nível, duração e URL do vídeo.";
            }
        }
    }
    if (!$erro && $v["titulo"] !== null && ($v["titulo"] === "" || strlen($v["titulo"]) > 200)) {
        $erro = "Título inválido (até 200 caracteres).";
    }
    if (!$erro && $v["duracao"] !== null && ($v["duracao"] < 1 || $v["duracao"] > 32767)) {
        $erro = "Duração inválida (em minutos).";
    }
    if (!$erro && $v["video"] !== null && !adminUrlOk($v["video"])) {
        $erro = "A URL do vídeo precisa começar com http:// ou https://.";
    }
    if (!$erro && $v["thumb"] !== null && !adminUrlOk($v["thumb"])) {
        $erro = "A URL da miniatura precisa começar com http:// ou https://.";
    }
    if (!$erro && $v["categoria"] !== null && !in_array($v["categoria"], adminOpcoesEnum("curso", "categoria_curso"), true)) {
        $erro = "Categoria inválida.";
    }
    if (!$erro && $v["nivel"] !== null && !in_array($v["nivel"], adminOpcoesEnum("curso", "nivel_curso"), true)) {
        $erro = "Nível inválido.";
    }

    if ($erro) {
        adminResponder(400, ["success" => false, "message" => $erro]);
    }
    return $v;
}

// POST /auth/adminCriarCurso
function adminCriarCurso()
{
    global $conexao;
    $admin = adminAutenticar();

    $v = adminCursoValidar(adminEntrada(), true);
    $tCat = adminTipoEnum("curso", "categoria_curso");
    $tNiv = adminTipoEnum("curso", "nivel_curso");

    try {
        $st = $conexao->prepare(
            "INSERT INTO curso (titulo_curso, descricao_curso, categoria_curso, nivel_curso,
                                duracao_minutos_curso, url_video_curso, url_thumb_curso, id_administrador)
             VALUES (:titulo, :descricao, CAST(:cat AS \"$tCat\"), CAST(:niv AS \"$tNiv\"),
                     :duracao, :video, :thumb, :adm)"
        );
        $st->bindValue(":titulo", $v["titulo"]);
        $st->bindValue(":descricao", $v["descricao"]);
        $st->bindValue(":cat", $v["categoria"]);
        $st->bindValue(":niv", $v["nivel"]);
        $st->bindValue(":duracao", $v["duracao"], PDO::PARAM_INT);
        $st->bindValue(":video", $v["video"]);
        $st->bindValue(":thumb", $v["thumb"]);
        $st->bindValue(":adm", (int) $admin["id_administrador"], PDO::PARAM_INT);
        $st->execute();
    } catch (PDOException $e) {
        adminResponder(400, ["success" => false, "message" => "Não foi possível salvar o curso (confira os campos)."]);
    }

    adminResponder(201, ["success" => true, "message" => "Curso cadastrado."]);
}

// POST /auth/adminAlterarCurso   corpo: id_curso + campos opcionais
function adminAlterarCurso()
{
    global $conexao;
    adminAutenticar();

    $d  = adminEntrada();
    $id = (int) ($d["id_curso"] ?? 0);
    if ($id <= 0) {
        adminResponder(400, ["success" => false, "message" => "Curso inválido."]);
    }

    $v = adminCursoValidar($d, false);
    $tCat = adminTipoEnum("curso", "categoria_curso");
    $tNiv = adminTipoEnum("curso", "nivel_curso");

    try {
        $st = $conexao->prepare(
            "UPDATE curso SET
                titulo_curso          = COALESCE(:titulo, titulo_curso),
                descricao_curso       = COALESCE(:descricao, descricao_curso),
                categoria_curso       = COALESCE(CAST(:cat AS \"$tCat\"), categoria_curso),
                nivel_curso           = COALESCE(CAST(:niv AS \"$tNiv\"), nivel_curso),
                duracao_minutos_curso = COALESCE(:duracao, duracao_minutos_curso),
                url_video_curso       = COALESCE(:video, url_video_curso),
                url_thumb_curso       = COALESCE(:thumb, url_thumb_curso)
              WHERE id_curso = :id"
        );
        $st->bindValue(":titulo", $v["titulo"]);
        $st->bindValue(":descricao", $v["descricao"]);
        $st->bindValue(":cat", $v["categoria"]);
        $st->bindValue(":niv", $v["nivel"]);
        $st->bindValue(":duracao", $v["duracao"], $v["duracao"] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(":video", $v["video"]);
        $st->bindValue(":thumb", $v["thumb"]);
        $st->bindValue(":id", $id, PDO::PARAM_INT);
        $st->execute();
    } catch (PDOException $e) {
        adminResponder(400, ["success" => false, "message" => "Não foi possível atualizar o curso."]);
    }

    if ($st->rowCount() === 0) {
        adminResponder(404, ["success" => false, "message" => "Curso não encontrado."]);
    }
    adminResponder(200, ["success" => true, "message" => "Curso atualizado."]);
}

// POST /auth/adminExcluirCurso   corpo: id_curso
function adminExcluirCurso()
{
    global $conexao;
    adminAutenticar();

    $id = (int) (adminEntrada()["id_curso"] ?? 0);

    try {
        $st = $conexao->prepare("DELETE FROM curso WHERE id_curso = :id");
        $st->execute([":id" => $id]);
    } catch (PDOException $e) {
        if ($e->getCode() === "23503") {
            adminResponder(409, ["success" => false, "message" => "Este curso faz parte de uma trilha. Remova-o da trilha antes de excluir."]);
        }
        adminResponder(500, ["success" => false, "message" => "Não foi possível excluir o curso."]);
    }

    if ($st->rowCount() === 0) {
        adminResponder(404, ["success" => false, "message" => "Curso não encontrado."]);
    }
    adminResponder(200, ["success" => true, "message" => "Curso excluído."]);
}

/* ===================== perguntas dos usuários ===================== */

// ---- lado do administrador ----

// POST /auth/adminListarPerguntas   corpo: filtro = pendentes | respondidas | todas
function adminListarPerguntas()
{
    global $conexao;
    adminAutenticar();

    $filtro = adminEntrada()["filtro"] ?? "todas";
    $where  = $filtro === "pendentes" ? "WHERE p.resposta_pergunta IS NULL"
        : ($filtro === "respondidas" ? "WHERE p.resposta_pergunta IS NOT NULL" : "");

    $linhas = $conexao->query(
        "SELECT p.id_pergunta, p.assunto, p.mensagem_pergunta, p.resposta_pergunta,
                p.respondida_em, p.criado_em, u.nome_usuario, u.email_usuario,
                a.nome_administrador AS respondida_por
           FROM pergunta_usuario p
           JOIN usuario u ON u.id_usuario = p.id_usuario
           LEFT JOIN administrador a ON a.id_administrador = p.id_administrador
           $where
          ORDER BY (p.resposta_pergunta IS NOT NULL), p.criado_em ASC
          LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC);

    $pendentes = (int) $conexao->query(
        "SELECT COUNT(*) FROM pergunta_usuario WHERE resposta_pergunta IS NULL"
    )->fetchColumn();

    adminResponder(200, ["success" => true, "pendentes" => $pendentes, "perguntas" => $linhas]);
}

// POST /auth/adminResponderPergunta   corpo: id_pergunta, resposta_pergunta
function adminResponderPergunta()
{
    global $conexao;
    $admin = adminAutenticar();

    $d        = adminEntrada();
    $id       = (int) ($d["id_pergunta"] ?? 0);
    $resposta = trim($d["resposta_pergunta"] ?? "");

    if ($id <= 0 || $resposta === "" || strlen($resposta) > 5000) {
        adminResponder(400, ["success" => false, "message" => "Escreva a resposta (até 5000 caracteres)."]);
    }

    $st = $conexao->prepare(
        "UPDATE pergunta_usuario
            SET resposta_pergunta = :r, id_administrador = :adm, respondida_em = NOW()
          WHERE id_pergunta = :id
      RETURNING id_usuario, assunto"
    );
    $st->bindValue(":r", $resposta);
    $st->bindValue(":adm", (int) $admin["id_administrador"], PDO::PARAM_INT);
    $st->bindValue(":id", $id, PDO::PARAM_INT);
    $st->execute();
    $p = $st->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        adminResponder(404, ["success" => false, "message" => "Pergunta não encontrada."]);
    }

    // avisa o usuário (se a tabela de notificações recusar, a resposta já foi salva)
    try {
        $conexao->prepare(
            "INSERT INTO notificacao (id_usuario, titulo_notificacao, mensagem_notificacao)
             VALUES (:u, :t, :m)"
        )->execute([
            ":u" => $p["id_usuario"],
            ":t" => "Sua pergunta foi respondida",
            ":m" => mb_substr("Respondemos: " . $p["assunto"], 0, 200)
        ]);
    } catch (PDOException $e) {
        // sem notificação, segue
    }

    adminResponder(200, ["success" => true, "message" => "Resposta enviada."]);
}

// POST /auth/adminExcluirPergunta   corpo: id_pergunta
function adminExcluirPergunta()
{
    global $conexao;
    adminAutenticar();

    $id = (int) (adminEntrada()["id_pergunta"] ?? 0);
    $st = $conexao->prepare("DELETE FROM pergunta_usuario WHERE id_pergunta = :id");
    $st->execute([":id" => $id]);

    if ($st->rowCount() === 0) {
        adminResponder(404, ["success" => false, "message" => "Pergunta não encontrada."]);
    }
    adminResponder(200, ["success" => true, "message" => "Pergunta excluída."]);
}

// ---- lado do usuário (token normal de usuário) ----

function perguntaUsuarioId(): int
{
    $id = (int) autenticar();   // sai com 401 se o token for inválido
    if ($id <= 0) {
        adminResponder(401, ["success" => false, "message" => "Token inválido."]);
    }
    return $id;
}

// POST /auth/perguntaEnviar   corpo: assunto, mensagem_pergunta
function perguntaEnviar()
{
    global $conexao;
    $idUsuario = perguntaUsuarioId();

    $d        = adminEntrada();
    $assunto  = trim($d["assunto"] ?? "");
    $mensagem = trim($d["mensagem_pergunta"] ?? "");

    if ($assunto === "" || $mensagem === "" || mb_strlen($assunto) > 150 || mb_strlen($mensagem) > 2000) {
        adminResponder(400, ["success" => false, "message" => "Preencha o assunto (até 150) e a pergunta (até 2000 caracteres)."]);
    }

    $st = $conexao->prepare("SELECT ativo_usuario FROM usuario WHERE id_usuario = :id");
    $st->execute([":id" => $idUsuario]);
    $ativo = $st->fetchColumn();
    if ($ativo === false || !adminBool($ativo)) {
        adminResponder(403, ["success" => false, "message" => "Conta desativada."]);
    }

    // anti-spam: no máximo 5 perguntas sem resposta por usuário
    $st = $conexao->prepare(
        "SELECT COUNT(*) FROM pergunta_usuario WHERE id_usuario = :id AND resposta_pergunta IS NULL"
    );
    $st->execute([":id" => $idUsuario]);
    if ((int) $st->fetchColumn() >= 5) {
        adminResponder(429, ["success" => false, "message" => "Você já tem 5 perguntas aguardando resposta. Aguarde o retorno."]);
    }

    $conexao->prepare(
        "INSERT INTO pergunta_usuario (id_usuario, assunto, mensagem_pergunta)
         VALUES (:u, :a, :m)"
    )->execute([":u" => $idUsuario, ":a" => $assunto, ":m" => $mensagem]);

    adminResponder(201, ["success" => true, "message" => "Pergunta enviada. Você será avisado quando houver resposta."]);
}

// POST /auth/perguntaListar
function perguntaListar()
{
    global $conexao;
    $idUsuario = perguntaUsuarioId();

    $st = $conexao->prepare(
        "SELECT id_pergunta, assunto, mensagem_pergunta, resposta_pergunta, respondida_em, criado_em
           FROM pergunta_usuario WHERE id_usuario = :id
          ORDER BY criado_em DESC LIMIT 100"
    );
    $st->execute([":id" => $idUsuario]);

    adminResponder(200, ["success" => true, "perguntas" => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
