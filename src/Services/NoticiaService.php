<?php

// Notícias do mercado para o dashboard.
//
// Fontes: notícias publicadas pela equipe (tabela noticia) + feeds RSS públicos de
// portais de economia. Os feeds são lidos pelo servidor e guardados em cache, então
// nenhuma chave ou serviço externo fica exposto no site ou no aplicativo.

const NOTICIAS_CACHE_TTL = 1200;          // 20 minutos
const NOTICIAS_CACHE_ANTIGO_MAX = 86400;  // usado quando os portais não respondem
const NOTICIAS_POR_FONTE = 6;
const NOTICIAS_TIMEOUT_FEED = 6;

const NOTICIAS_FEEDS_PADRAO = [
    ["fonte" => "InfoMoney", "url" => "https://www.infomoney.com.br/feed/"],
    ["fonte" => "Money Times", "url" => "https://www.moneytimes.com.br/mercados/feed/"],
    ["fonte" => "g1 Economia", "url" => "https://g1.globo.com/rss/g1/economia/"],
];

// Conteúdo pago/publicitário marcado pelos próprios portais fica de fora.
const NOTICIAS_CATEGORIAS_IGNORADAS = ["branded", "patrocinado", "publieditorial", "conteúdo empiricus"];

// Os feeds gerais misturam esporte e política: só entra o que fala de dinheiro e mercado.
const NOTICIAS_ASSUNTOS_FINANCEIROS = '/\b(mercados?|econom\w*|invest\w*|ibovespa|bolsas?|a[cç][oõ]es|d[oó]lar|c[aâ]mbio|juros|selic|infla[cç][aã]o|ipca|renda fixa|tesouro|fiis?|fundos?|cripto\w*|bitcoin|dividend\w*|bancos?|cr[eé]dito|fiscal|pib|wall street|nasdaq|b3|finan[cç]\w*|poupan[cç]a|cdbs?|tarifa\w*|impostos?|lucros?|balan[cç]o|copom|commodit\w*|minicontratos?|trader)\b/iu';

/** Feeds configurados: NOTICIAS_FEEDS="Fonte|https://...;Outra|https://..." substitui os padrões. */
function noticiasFeedsConfigurados(): array
{
    $configuracao = trim((string) (getenv("NOTICIAS_FEEDS") ?: ($_ENV["NOTICIAS_FEEDS"] ?? "")));

    if ($configuracao === "") {
        return NOTICIAS_FEEDS_PADRAO;
    }

    $feeds = [];
    foreach (explode(";", $configuracao) as $item) {
        [$fonte, $url] = array_pad(explode("|", $item, 2), 2, "");
        if (trim($fonte) !== "" && preg_match("#^https://#i", trim($url))) {
            $feeds[] = ["fonte" => trim($fonte), "url" => trim($url)];
        }
    }

    return $feeds ?: NOTICIAS_FEEDS_PADRAO;
}

function noticiaUrlSegura($url): ?string
{
    $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, "UTF-8");

    return preg_match('#^https?://[^\s"<>]+$#i', $url) === 1 ? $url : null;
}

/** Texto puro de um trecho HTML do feed, limitado a $limite caracteres. */
function noticiaTextoLimpo(string $html, int $limite): string
{
    $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, "UTF-8");
    $texto = trim(preg_replace("/\s+/u", " ", $texto));
    $texto = preg_replace("/\s*(\[(…|&#8230;|\.\.\.)\]|The post .*)$/u", "", $texto);

    if (mb_strlen($texto, "UTF-8") <= $limite) {
        return $texto;
    }

    $cortado = mb_substr($texto, 0, $limite, "UTF-8");
    $ultimoEspaco = mb_strrpos($cortado, " ", 0, "UTF-8");

    return rtrim($ultimoEspaco ? mb_substr($cortado, 0, $ultimoEspaco, "UTF-8") : $cortado, " ,.;:") . "…";
}

/** Primeira imagem do item: media:content, media:thumbnail, enclosure ou a primeira <img>. */
function noticiaImagemDoItem(SimpleXMLElement $item, string $descricao, string $conteudo): ?string
{
    $media = $item->children("http://search.yahoo.com/mrss/");

    foreach (["content", "thumbnail"] as $tag) {
        if (isset($media->{$tag})) {
            $url = noticiaUrlSegura((string) $media->{$tag}->attributes()["url"]);
            if ($url !== null) {
                return $url;
            }
        }
    }

    if (isset($item->enclosure)) {
        $atributos = $item->enclosure->attributes();
        if (str_starts_with((string) $atributos["type"], "image/")) {
            $url = noticiaUrlSegura((string) $atributos["url"]);
            if ($url !== null) {
                return $url;
            }
        }
    }

    foreach ([$descricao, $conteudo] as $html) {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $url = noticiaUrlSegura($m[1]);
            if ($url !== null) {
                return $url;
            }
        }
    }

    return null;
}

/** Converte um feed RSS 2.0 na lista de notícias usada pela API. */
function interpretarFeedRss(string $xml, string $fonte, int $limite = NOTICIAS_POR_FONTE): array
{
    $anterior = libxml_use_internal_errors(true);
    $documento = simplexml_load_string($xml, "SimpleXMLElement", LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);

    if ($documento === false || !isset($documento->channel->item)) {
        return [];
    }

    $noticias = [];

    foreach ($documento->channel->item as $item) {
        $titulo = noticiaTextoLimpo((string) $item->title, 180);
        $link = noticiaUrlSegura((string) $item->link);

        if ($titulo === "" || $link === null) {
            continue;
        }

        $categorias = [];
        foreach ($item->category as $categoria) {
            $categorias[] = mb_strtolower(trim((string) $categoria), "UTF-8");
        }

        if (array_intersect($categorias, NOTICIAS_CATEGORIAS_IGNORADAS)) {
            continue;
        }

        if (!preg_match(NOTICIAS_ASSUNTOS_FINANCEIROS, $titulo . " " . implode(" ", $categorias))) {
            continue;
        }

        $descricao = (string) $item->description;
        $conteudo = (string) $item->children("http://purl.org/rss/1.0/modules/content/")->encoded;
        $data = strtotime((string) $item->pubDate);

        $noticias[] = [
            "id" => "rss-" . substr(sha1($link), 0, 12),
            "titulo" => $titulo,
            "resumo" => noticiaTextoLimpo($descricao !== "" ? $descricao : $conteudo, 220),
            "fonte" => $fonte,
            "url" => $link,
            "imagem" => noticiaImagemDoItem($item, $descricao, $conteudo),
            "categoria" => "Mercado",
            "origem" => "externa",
            "publicada_em" => $data ? gmdate("Y-m-d\TH:i:s\Z", $data) : null,
        ];

        if (count($noticias) >= $limite) {
            break;
        }
    }

    return $noticias;
}

function noticiasBaixarFeed(string $url): ?string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => NOTICIAS_TIMEOUT_FEED,
        CURLOPT_USERAGENT => "AccuracyNoticias/1.0 (+https://accuracyappapi.onrender.com)",
        CURLOPT_HTTPHEADER => ["Accept: application/rss+xml, application/xml;q=0.9"],
    ]);

    $corpo = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return is_string($corpo) && $status === 200 && $corpo !== "" ? $corpo : null;
}

function noticiasArquivoCache(): string
{
    return rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . "accurancy_noticias_rss.json";
}

/** Notícias dos portais, renovadas a cada 20 minutos; se os portais falharem, usa a última cópia. */
function noticiasExternas(): array
{
    $arquivo = noticiasArquivoCache();
    $idade = is_file($arquivo) ? time() - (int) @filemtime($arquivo) : PHP_INT_MAX;
    $guardadas = $idade <= NOTICIAS_CACHE_ANTIGO_MAX
        ? json_decode((string) @file_get_contents($arquivo), true)
        : null;

    if (is_array($guardadas) && $idade <= NOTICIAS_CACHE_TTL) {
        return $guardadas;
    }

    $noticias = [];
    foreach (noticiasFeedsConfigurados() as $feed) {
        $xml = noticiasBaixarFeed($feed["url"]);
        if ($xml !== null) {
            $noticias = array_merge($noticias, interpretarFeedRss($xml, $feed["fonte"]));
        }
    }

    if (!$noticias) {
        error_log("Notícias: nenhum feed respondeu; usando a última cópia disponível.");
        return is_array($guardadas) ? $guardadas : [];
    }

    @file_put_contents($arquivo, json_encode($noticias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $noticias;
}

/** Joins news lists: most recent first, without repeated titles. */
function combinarNoticias(array $internas, array $externas, int $limite): array
{
    $todas = array_merge($internas, $externas);

    usort($todas, static fn($a, $b) => strcmp((string) ($b["publicada_em"] ?? ""), (string) ($a["publicada_em"] ?? "")));

    $vistas = [];
    $resultado = [];

    foreach ($todas as $noticia) {
        $chave = mb_strtolower(preg_replace("/\W+/u", "", $noticia["titulo"]), "UTF-8");
        if (isset($vistas[$chave])) {
            continue;
        }
        $vistas[$chave] = true;
        $resultado[] = $noticia;

        if (count($resultado) >= $limite) {
            break;
        }
    }

    return $resultado;
}
