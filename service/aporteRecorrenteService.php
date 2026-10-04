<?php
require_once __DIR__ . "/automacaoCronService.php";   // dataHojeCron() e proximaDataRecorrenteCron()

const FREQUENCIAS_RECORRENTE = ["Diário", "Semanal", "Mensal", "Anual"];

function carteiraPertenceAoUsuario(PDO $conexao, $idCarteira, $idUsuario)
{
    $sql = "SELECT id_carteira FROM carteira WHERE id_carteira = ? AND id_usuario = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idCarteira, $idUsuario]);

    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

// Dia de referência guardado na regra: dia do mês (Mensal) ou dia da semana 1=seg..7=dom (Semanal)
function diaReferenciaRecorrente($frequencia, $dataBase)
{
    $data = new DateTimeImmutable($dataBase);

    if ($frequencia === "Mensal") {
        return (int) $data->format("j");
    }

    if ($frequencia === "Semanal") {
        return (int) $data->format("N");
    }

    return null;
}

// Cria um novo aporte recorrente pra uma carteira/ativo
function criarAporteRecorrente(PDO $conexao, $idCarteira, $idAtivo, $valorRecorrente, $frequenciaRecorrente, $diaReferenciaRecorrente, $proximaExecucaoRecorrente)
{
    $sql = "INSERT INTO aporte_recorrente
                (id_carteira, id_ativo, valor_recorrente, frequencia_recorrente, dia_referencia_recorrente, ativo_flag_recorrente, proxima_execucao_recorrente)
            VALUES
                (:carteira, :ativo, :valor, :frequencia, :dia, 1, :proxima)
            RETURNING id_aporte_recorrente";

    $stmt = $conexao->prepare($sql);
    $stmt->bindParam(':carteira', $idCarteira);
    $stmt->bindParam(':ativo', $idAtivo);
    $stmt->bindParam(':valor', $valorRecorrente);
    $stmt->bindParam(':frequencia', $frequenciaRecorrente);
    $stmt->bindParam(':dia', $diaReferenciaRecorrente);
    $stmt->bindParam(':proxima', $proximaExecucaoRecorrente);
    $stmt->execute();

    $novo = $stmt->fetch(PDO::FETCH_ASSOC);

    return $novo["id_aporte_recorrente"];
}

function buscarRecorrenteAtivoIgual(PDO $conexao, $idCarteira, $idAtivo, $frequencia)
{
    $stmt = $conexao->prepare(
        "SELECT id_aporte_recorrente, proxima_execucao_recorrente
         FROM aporte_recorrente
         WHERE id_carteira = ? AND id_ativo = ? AND frequencia_recorrente = ?
           AND ativo_flag_recorrente::int = 1
         ORDER BY id_aporte_recorrente LIMIT 1"
    );
    $stmt->execute([$idCarteira, $idAtivo, $frequencia]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Lista as regras do usuário (de uma carteira, ou de todas as carteiras dele)
function listarAporteRecorrente(PDO $conexao, $idUsuario, $idCarteira = null)
{
    $sql = "SELECT r.id_aporte_recorrente, r.id_carteira, r.id_ativo, a.simbolo_ativo, a.nome_ativo,
                   r.valor_recorrente, r.frequencia_recorrente, r.dia_referencia_recorrente,
                   r.ativo_flag_recorrente::int AS ativo_flag_recorrente,
                   r.proxima_execucao_recorrente, r.criado_em
            FROM aporte_recorrente r
            JOIN carteira c ON c.id_carteira = r.id_carteira
            JOIN ativo a ON a.id_ativo = r.id_ativo
            WHERE c.id_usuario = :usuario";

    $params = [":usuario" => $idUsuario];

    if ($idCarteira !== null && $idCarteira !== "") {
        $sql .= " AND r.id_carteira = :carteira";
        $params[":carteira"] = $idCarteira;
    }

    $sql .= " ORDER BY r.ativo_flag_recorrente::int DESC, r.proxima_execucao_recorrente, r.id_aporte_recorrente";

    $stmt = $conexao->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Busca UMA regra, só se for do usuário logado (null = não existe ou é de outra pessoa)
function buscarAporteRecorrenteDoUsuario(PDO $conexao, $idAporteRecorrente, $idUsuario)
{
    $stmt = $conexao->prepare(
        "SELECT r.id_aporte_recorrente, r.id_carteira, r.valor_recorrente, r.frequencia_recorrente,
                r.dia_referencia_recorrente, r.ativo_flag_recorrente::int AS ativo_flag_recorrente,
                r.proxima_execucao_recorrente
         FROM aporte_recorrente r
         JOIN carteira c ON c.id_carteira = r.id_carteira
         WHERE r.id_aporte_recorrente = ? AND c.id_usuario = ?"
    );
    $stmt->execute([$idAporteRecorrente, $idUsuario]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function alternarAporteRecorrente(PDO $conexao, $regra, $ligar)
{
    $proxima = $regra["proxima_execucao_recorrente"];

    if ($ligar) {
        $hoje = dataHojeCron();

        if ($proxima === null || $proxima < $hoje) {
            $proxima = proximaDataRecorrenteCron($hoje, $regra["frequencia_recorrente"], $regra["dia_referencia_recorrente"]);
        }
    }

    $flag = $ligar ? 1 : 0;

    $stmt = $conexao->prepare(
        "UPDATE aporte_recorrente
         SET ativo_flag_recorrente = :flag, proxima_execucao_recorrente = :proxima
         WHERE id_aporte_recorrente = :id"
    );
    $stmt->bindValue(":flag", $flag, PDO::PARAM_INT);
    $stmt->bindValue(":proxima", $proxima);
    $stmt->bindValue(":id", $regra["id_aporte_recorrente"]);
    $stmt->execute();

    return $proxima;
}

// Edita valor e/ou frequência. Mudou a frequência? A próxima execução é recalculada a partir de hoje.
function atualizarAporteRecorrente(PDO $conexao, $regra, $novoValor, $novaFrequencia)
{
    $valor = $novoValor ?? $regra["valor_recorrente"];
    $frequencia = $novaFrequencia ?? $regra["frequencia_recorrente"];
    $dia = $regra["dia_referencia_recorrente"];
    $proxima = $regra["proxima_execucao_recorrente"];

    if ($frequencia !== $regra["frequencia_recorrente"]) {
        $hoje = dataHojeCron();
        $dia = diaReferenciaRecorrente($frequencia, $hoje);
        $proxima = proximaDataRecorrenteCron($hoje, $frequencia, $dia);
    }

    $stmt = $conexao->prepare(
        "UPDATE aporte_recorrente
         SET valor_recorrente = :valor, frequencia_recorrente = :frequencia,
             dia_referencia_recorrente = :dia, proxima_execucao_recorrente = :proxima
         WHERE id_aporte_recorrente = :id"
    );
    $stmt->bindValue(":valor", $valor);
    $stmt->bindValue(":frequencia", $frequencia);
    $stmt->bindValue(":dia", $dia);
    $stmt->bindValue(":proxima", $proxima);
    $stmt->bindValue(":id", $regra["id_aporte_recorrente"]);
    $stmt->execute();

    return $proxima;
}
?>