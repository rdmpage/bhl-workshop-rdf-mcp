<?php
/**
 * BHL RDF MCP server
 *
 * A single-file Model Context Protocol server wrapping the Biodiversity
 * Heritage Library SPARQL endpoint. No dependencies, two transports:
 *
 *   php mcp.php --stdio     stdio, for clients that spawn the server
 *   (web request)           Streamable HTTP, for remote clients
 *   php mcp.php             smoke test against the live endpoint
 *
 * Built for the BHL workshop. See README.md.
 */

// ---- CONFIG ------------------------------------------------------------

define('BHL_SPARQL_ENDPOINT', getenv('BHL_SPARQL_ENDPOINT')
    ?: 'https://koetai.semscape.org/u/0000-0001-9773-4008/bhl/sparql');

/**
 * Seconds to wait on the SPARQL endpoint.
 *
 * This deliberately stays under the host's own request limit. Under PHP-FPM a
 * request that outlives max_execution_time is killed outright -- no fatal, no
 * shutdown handler -- and the web server answers with an HTML error page that
 * no MCP client can parse. Giving up first means we can return a real
 * JSON-RPC error instead. BHL_HTTP_TIMEOUT overrides if you know your limits.
 */
function httpTimeout()
{
    static $seconds = null;
    if ($seconds !== null) {
        return $seconds;
    }
    $env = (int)getenv('BHL_HTTP_TIMEOUT');
    if ($env > 0) {
        return $seconds = $env;
    }
    $limit = (int)ini_get('max_execution_time');   // 0 means no limit (the CLI default)
    // The endpoint gives up after about 30s, so waiting beyond 35 gains nothing.
    return $seconds = $limit > 0 ? max(10, min(35, $limit - 5)) : 35;
}
define('BHL_DEFAULT_LIMIT', 25);
define('BHL_MAX_LIMIT', 500);
define('BHL_MAX_LITERAL', 400);   // truncate long literals in rendered tables
define('BHL_SERVER_NAME', 'bhl-rdf');
define('BHL_SERVER_VERSION', '1.0.0');

$SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];
$DEFAULT_PROTOCOL = '2025-06-18';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---- NAMESPACES --------------------------------------------------------

$NS = [
    'dcterms' => 'http://purl.org/dc/terms/',
    'bibo'    => 'http://purl.org/ontology/bibo/',
    'dwc'     => 'http://rs.tdwg.org/dwc/terms/',
    'foaf'    => 'http://xmlns.com/foaf/0.1/',
    'owl'     => 'http://www.w3.org/2002/07/owl#',
    'rdf'     => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
    'rdfs'    => 'http://www.w3.org/2000/01/rdf-schema#',
    'xsd'     => 'http://www.w3.org/2001/XMLSchema#',
    'bhlv'       => 'https://www.biodiversitylibrary.org/vocab/',
    // SPARQL prefixed names may not contain '/', so each kind of BHL record
    // gets its own prefix. That keeps rendered IRIs copy-pasteable into a query.
    'bhlbib'     => 'https://www.biodiversitylibrary.org/bibliography/',
    'bhlitem'    => 'https://www.biodiversitylibrary.org/item/',
    'bhlpart'    => 'https://www.biodiversitylibrary.org/part/',
    'bhlpage'    => 'https://www.biodiversitylibrary.org/page/',
    'bhlcreator' => 'https://www.biodiversitylibrary.org/creator/',
];

define('BHL_BASE', 'https://www.biodiversitylibrary.org/');

function prefixBlock()
{
    global $NS;
    $out = '';
    foreach ($NS as $p => $u) {
        $out .= "PREFIX $p: <$u>\n";
    }
    return $out;
}

/** Shorten a full IRI to prefixed form for display. */
function shortenIri($iri)
{
    global $NS;
    foreach ($NS as $p => $u) {
        if (strpos($iri, $u) === 0) {
            $local = substr($iri, strlen($u));
            // A prefixed name cannot contain '/' or '#', so only shorten when
            // the local part is clean -- e.g. page-position IRIs stay in full.
            if ($local !== '' && !preg_match('/[\s\/#]/', $local)) {
                return "$p:$local";
            }
        }
    }
    return $iri;
}

// ---- ERRORS ------------------------------------------------------------

class BhlError extends Exception {}

// ---- SPARQL CLIENT -----------------------------------------------------

/**
 * Run a SELECT/ASK query and return the parsed SPARQL JSON results.
 *
 * @return array ['vars' => [...], 'rows' => [...], 'total' => int|null, 'ms' => int|null]
 * @throws BhlError
 */
function sparqlSelect($query)
{
    $ch = curl_init(BHL_SPARQL_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => httpTimeout(),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/sparql-results+json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . BHL_SERVER_NAME . '/' . BHL_SERVER_VERSION,
        ],
    ]);

    $body  = curl_exec($ch);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            // We gave up before the host could kill us mid-request. Same advice
            // as an endpoint-side timeout, since the cause is the same.
            throw new BhlError('The query was still running after ' . httpTimeout()
                . 's, so this server stopped waiting. This graph has ~771 million '
                . 'triples: unbounded scans and GROUP BY over all triples will never '
                . 'finish. Add a more selective triple pattern, filter on a specific '
                . 'class, or add LIMIT.');
        }
        throw new BhlError("Could not reach the SPARQL endpoint: $err");
    }

    $json = json_decode($body, true);

    if (is_array($json) && isset($json['error'])) {
        throw new BhlError(explainEndpointError($json['error']));
    }
    if ($code >= 400) {
        throw new BhlError("SPARQL endpoint returned HTTP $code: " . substr($body, 0, 500));
    }
    if (!is_array($json) || !isset($json['head'])) {
        throw new BhlError('Unexpected response from the SPARQL endpoint: ' . substr($body, 0, 500));
    }

    // ASK queries return a boolean instead of bindings.
    if (array_key_exists('boolean', $json)) {
        return ['vars' => ['result'], 'rows' => [
            ['result' => ['type' => 'literal', 'value' => $json['boolean'] ? 'true' : 'false']],
        ], 'total' => 1, 'ms' => isset($json['meta']['query-time-ms']) ? $json['meta']['query-time-ms'] : null];
    }

    return [
        'vars'  => isset($json['head']['vars']) ? $json['head']['vars'] : [],
        'rows'  => isset($json['results']['bindings']) ? $json['results']['bindings'] : [],
        'total' => isset($json['meta']['result-size-total']) ? $json['meta']['result-size-total'] : null,
        'ms'    => isset($json['meta']['query-time-ms']) ? $json['meta']['query-time-ms'] : null,
    ];
}

/** Turn QLever's JSON-in-a-string error blob into one useful line of advice. */
function explainEndpointError($raw)
{
    $inner = is_string($raw) ? json_decode($raw, true) : $raw;
    $msg = is_array($inner) && isset($inner['exception']) ? $inner['exception'] : (is_string($raw) ? $raw : json_encode($raw));
    $msg = trim(preg_replace('/\s*In file ".*$/s', '', $msg));

    if (stripos($msg, 'timed out') !== false) {
        return 'The query timed out (this server waits ' . httpTimeout() . 's; the '
             . 'endpoint itself allows about 30). '
             . "This graph has ~771 million triples, so unbounded scans and "
             . "GROUP BY over all triples will always fail. Add a more selective "
             . "triple pattern, filter on a specific class, or add LIMIT. "
             . "Endpoint said: $msg";
    }
    if (stripos($msg, 'was not registered using a PREFIX') !== false) {
        return "Undeclared prefix. Known prefixes are declared for you "
             . "(dcterms, bibo, dwc, foaf, owl, rdf, rdfs, xsd, bhlv,\n             bhlbib, bhlitem, bhlpart, bhlpage, bhlcreator); "
             . "declare anything else yourself. Endpoint said: $msg";
    }
    return $msg;
}

// ---- SPARQL LITERAL ESCAPING ------------------------------------------

function sparqlString($s)
{
    $s = str_replace(
        ['\\', '"', "\n", "\r", "\t"],
        ['\\\\', '\\"', '\\n', '\\r', '\\t'],
        (string)$s
    );
    return '"' . $s . '"';
}

function sparqlIri($iri)
{
    $iri = trim((string)$iri);
    if ($iri === '' || preg_match('/[\s<>"{}|\\\\^`]/', $iri)) {
        throw new BhlError("Not a usable IRI: $iri");
    }
    return '<' . $iri . '>';
}

// ---- BHL IDENTIFIERS ---------------------------------------------------

$BHL_TYPES = [
    'bibliography' => 'Title',
    'title'        => 'Title',         // friendly alias
    'item'         => 'Item (scanned volume)',
    'part'         => 'Article',
    'page'         => 'Page',
    'creator'      => 'Creator',
];

/**
 * Normalise a user-supplied reference to a full BHL IRI.
 * Accepts a full IRI, "bibliography/100", "title/100", or a bare number when
 * $assume names the expected kind of record.
 */
function bhlIri($ref, $assume = null)
{
    global $BHL_TYPES;
    $ref = trim((string)$ref);
    if ($ref === '') {
        throw new BhlError('Empty identifier.');
    }
    if (preg_match('#^https?://#i', $ref)) {
        return $ref;
    }
    $ref = ltrim($ref, '/');
    if (preg_match('#^([a-z]+)/(\d+)$#i', $ref, $m)) {
        $kind = strtolower($m[1]);
        if (!isset($BHL_TYPES[$kind])) {
            throw new BhlError("Unknown BHL record kind '$kind'. Use one of: "
                . implode(', ', array_keys($BHL_TYPES)) . '.');
        }
        if ($kind === 'title') $kind = 'bibliography';
        return BHL_BASE . $kind . '/' . $m[2];
    }
    if (ctype_digit($ref) && $assume !== null) {
        return BHL_BASE . $assume . '/' . $ref;
    }
    throw new BhlError("Could not read '$ref' as a BHL identifier. Give a full URI, "
        . "or a form like 'bibliography/100', 'item/10', 'part/1', 'page/3190642', 'creator/93'.");
}

// ---- RESULT RENDERING --------------------------------------------------

function renderValue($binding)
{
    if ($binding === null) return '';
    $v = $binding['value'];
    if ($binding['type'] === 'uri') {
        return shortenIri($v);
    }
    if (mb_strlen($v) > BHL_MAX_LITERAL) {
        $v = mb_substr($v, 0, BHL_MAX_LITERAL) . '…';
    }
    return str_replace(["\n", "\r", "\t"], ' ', $v);
}

/** Render SPARQL results as a pipe table, capped at $limit rows. */
function renderTable($res, $limit, $query = null)
{
    $vars = $res['vars'];
    $rows = $res['rows'];
    $shown = array_slice($rows, 0, $limit);

    $out = '';
    if (!$shown) {
        $out .= "No results.\n";
    } else {
        $out .= '| ' . implode(' | ', $vars) . " |\n";
        $out .= '|' . str_repeat(' --- |', count($vars)) . "\n";
        foreach ($shown as $row) {
            $cells = [];
            foreach ($vars as $v) {
                $cell = renderValue(isset($row[$v]) ? $row[$v] : null);
                $cells[] = str_replace('|', '\\|', $cell);
            }
            $out .= '| ' . implode(' | ', $cells) . " |\n";
        }
    }

    $n = count($rows);
    $meta = count($shown) . ' row' . (count($shown) === 1 ? '' : 's') . ' shown';
    if ($n > count($shown)) {
        $meta .= " of $n returned";
    }
    if ($res['total'] !== null && $res['total'] > $n) {
        $meta .= ' (endpoint reported ' . $res['total'] . ' total)';
    }
    if ($res['ms'] !== null) {
        $meta .= '; ' . $res['ms'] . ' ms';
    }
    $out .= "\n$meta.\n";

    if ($query !== null) {
        $out .= "\nSPARQL used:\n```sparql\n" . displayQuery($query) . "\n```\n";
    }
    return $out;
}

/**
 * Strip the auto-prepended PREFIX block so the echoed query shows only the
 * interesting part. Fourteen boilerplate lines above every result buries it.
 */
function displayQuery($query)
{
    $boilerplate = array_flip(array_filter(explode("\n", prefixBlock())));
    $kept = [];
    $stripped = false;
    foreach (explode("\n", $query) as $line) {
        if (isset($boilerplate[rtrim($line)])) {
            $stripped = true;
            continue;
        }
        $kept[] = $line;
    }
    $out = trim(implode("\n", $kept));
    if ($stripped) {
        $out = "# standard PREFIX declarations omitted here; see bhl://schema\n" . $out;
    }
    return $out;
}

function clampLimit($args, $default = BHL_DEFAULT_LIMIT)
{
    $n = isset($args['limit']) ? (int)$args['limit'] : $default;
    if ($n < 1) $n = 1;
    if ($n > BHL_MAX_LIMIT) $n = BHL_MAX_LIMIT;
    return $n;
}

function requireArg($args, $name)
{
    if (!isset($args[$name]) || (is_string($args[$name]) && trim($args[$name]) === '')) {
        throw new BhlError("Missing required argument '$name'.");
    }
    return $args[$name];
}

function textResult($text)
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

/** Run a query and return it as an MCP tool result. */
function runAndRender($query, $limit)
{
    $res = sparqlSelect($query);
    return textResult(renderTable($res, $limit, $query));
}

// ---- SCHEMA DOCUMENTATION ---------------------------------------------

function schemaDoc()
{
    return <<<'TEXT'
# BHL RDF — data model

Endpoint: QLever, ~771 million triples, already scoped to the BHL data graph.
Plain triple patterns read that graph, so you never need GRAPH or FROM.

## Prefixes (declared for you by the `sparql_query` tool)

    PREFIX dcterms: <http://purl.org/dc/terms/>
    PREFIX bibo:    <http://purl.org/ontology/bibo/>
    PREFIX dwc:     <http://rs.tdwg.org/dwc/terms/>
    PREFIX foaf:    <http://xmlns.com/foaf/0.1/>
    PREFIX owl:     <http://www.w3.org/2002/07/owl#>
    PREFIX rdf:     <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    PREFIX rdfs:    <http://www.w3.org/2000/01/rdf-schema#>
    PREFIX xsd:     <http://www.w3.org/2001/XMLSchema#>
    PREFIX bhlv:       <https://www.biodiversitylibrary.org/vocab/>
    PREFIX bhlbib:     <https://www.biodiversitylibrary.org/bibliography/>
    PREFIX bhlitem:    <https://www.biodiversitylibrary.org/item/>
    PREFIX bhlpart:    <https://www.biodiversitylibrary.org/part/>
    PREFIX bhlpage:    <https://www.biodiversitylibrary.org/page/>
    PREFIX bhlcreator: <https://www.biodiversitylibrary.org/creator/>

A SPARQL prefixed name cannot contain a slash, so each kind of record has its
own prefix: write `bhlbib:100`, not `bhl:bibliography/100`.

## Predicates, by number of triples

    dwc:scientificName     210,692,114     dcterms:identifier    1,398,536
    dwc:scientificNameID   151,786,434     dcterms:subject       1,131,746
    rdf:type                69,872,842     dcterms:creator       1,033,393
    bhlv:sequenceOrder      68,619,895     owl:sameAs              702,549
    dcterms:isPartOf        64,612,889     dcterms:title           647,578
    bhlv:nameBankID         60,985,349     bhlv:containerTitle     442,640
    bhlv:pageNumber         52,758,609     bhlv:pageRange          391,168
    bhlv:pagePrefix         52,263,365     bibo:volume             384,835
    dcterms:date            17,494,359     bhlv:institution        329,364
    bhlv:page                4,756,621     bhlv:copyrightStatus    329,362
    bhlv:hasPage             4,756,621     foaf:name               276,341
    bhlv:part                4,756,621     dcterms:issued          196,593
                                           dcterms:language        194,952
                                           bibo:issue              159,081
                                           bhlv:creatorType         95,221
                                           bhlv:endYear             12,527

That is the whole vocabulary -- 28 predicates, nothing else is in the graph.

## Classes

    bhlv:Title            204,710   bhlbib:{id}
    bibo:Book             329,364   bhlitem:{id}
    bibo:Article          442,868   bhlpart:{id}
    bibo:Page          63,862,938   bhlpage:{id}
    foaf:Agent            276,341   bhlcreator:{id}
    bhlv:PagePosition   4,756,621   .../part/{id}/page-position/{pageid}

## How they fit together

    foaf:Agent  <--dcterms:creator--  bhlv:Title  (a work: a book, or a journal run)
                                          ^
                                          | dcterms:isPartOf
                                      bibo:Book   (a scanned physical volume)
                                          ^
                                          | dcterms:isPartOf
                        +-----------------+-----------------+
                        |                                   |
                    bibo:Page                          bibo:Article
                        ^                                   |
                        +--------- bhlv:hasPage ------------+

Note the two senses of "part of": pages and articles are both `dcterms:isPartOf`
an **item**, not a title. To go from an article to its work you must hop through
the item.

## bhlv:Title — the bibliographic work (bhlbib:{id})

    dcterms:title      xsd:string    the title (one per work)
    dcterms:creator    IRI           -> foaf:Agent (also used on Articles, so
                                     `?w dcterms:creator ?c` alone matches both)
    dcterms:subject    xsd:string    subject heading, repeatable, e.g. "Botany"
    dcterms:language   xsd:string    3-letter MARC code, UPPERCASE: "ENG", "FRE", "GER"
    dcterms:issued     xsd:gYear     first/only year of publication
    bhlv:endYear       xsd:string    last year, for serials
    dcterms:identifier xsd:string    prefixed external ids: "OCLC:1507055",
                                     "Wikidata:Q54792313", "DLC:05024608", "TL2:8404"
    owl:sameAs         IRI           wikidata.org/entity/..., worldcat.org/oclc/...,
                                     id.loc.gov/authorities/names/..., doi.org/10.5962/bhl.title.N

A Title has no direct link to its items; query the reverse direction:
`?item dcterms:isPartOf bhlbib:100`.

## bibo:Book — a scanned volume, "item" in BHL's own vocabulary (bhlitem:{id})

    dcterms:isPartOf      IRI         -> bhlv:Title
    dcterms:date          xsd:gYear   year this volume was published
    dcterms:identifier    xsd:string  scan barcode / Internet Archive id
    bhlv:institution      xsd:string  holding institution, a plain label not an IRI
    bhlv:copyrightStatus  xsd:string  free text, inconsistent, e.g. "Public domain. ...",
                                      "In copyright. Digitized with the permission of the
                                      rights holder.", "NOT_IN_COPYRIGHT", or most often
                                      "Not provided. Contact Holding Institution to verify
                                      copyright status." Whatever it says, the item is freely
                                      available to read and download in BHL; the status bears
                                      only on republication or commercial reuse.

Items carry no title of their own — read it from the Title they belong to.

## bibo:Article — an article or chapter segmented out of an item (bhlpart:{id})

    dcterms:title         xsd:string
    dcterms:creator       IRI         -> foaf:Agent
    dcterms:isPartOf      IRI         -> bibo:Book (the item), NOT the title
    dcterms:date          xsd:string  NOTE: a string here, and may be a full date
                                      such as "1957-06-18", unlike every other date
    dcterms:identifier    xsd:string  e.g. "BioStor:4443"
    bibo:volume           xsd:string
    bibo:issue            xsd:string
    bhlv:containerTitle   xsd:string  journal name as printed, e.g. "Breviora"
    bhlv:pageRange        xsd:string  e.g. "1--4"
    bhlv:hasPage          IRI         -> bibo:Page, repeatable, unordered

## bibo:Page — a single scanned page (bhlpage:{id})

    dcterms:isPartOf        IRI         -> bibo:Book (the item)
    dcterms:date            mixed       on only 16.7M of the 64M pages, and of those
                                        15.7M are xsd:gYear while 1.0M are xsd:string
                                        holding things like "1907-1915" or "(1827)".
                                        Prefer the item's own dcterms:date, which is
                                        always a clean xsd:gYear
    dwc:scientificName      xsd:string  name found on the page by BHL's automated
                                        name-finder, repeatable, often at several ranks
                                        ("Tetragnatha" and "Tetragnatha fraterna")
    dwc:scientificNameID    IRI         -> wikidata.org/entity/..., repeatable
    bhlv:nameBankID         xsd:string  uBio NameBank id, repeatable
    bhlv:pageNumber         xsd:string  printed page number
    bhlv:pagePrefix         xsd:string  e.g. "Figs. 1-5, Page"
    bhlv:sequenceOrder      xsd:int     position within the item

Names are machine-generated OCR matches: expect misspellings and false positives.
There is no link from a name string to a taxon record beyond dwc:scientificNameID.

## foaf:Agent — an author or corporate body (bhlcreator:{id})

    foaf:name           xsd:string  inverted, with dates: "Hooker, Joseph Dalton, 1817-1911"
    bhlv:creatorType    xsd:string  "Main - Personal Name", "Added - Corporate Name", ...
    dcterms:identifier  xsd:string  "VIAF:17306215", "Wikidata:Q157501", "DLC:n86843993"
    owl:sameAs          IRI         viaf.org/viaf/..., wikidata.org/entity/...,
                                    id.loc.gov/..., snaccooperative.org/ark:/...

## bhlv:PagePosition — reified ordering of a page inside an article

    bhlv:part           IRI       -> bibo:Article
    bhlv:page           IRI       -> bibo:Page
    bhlv:sequenceOrder  xsd:int   position of that page within that article

Use this when you need an article's pages **in order**; `bhlv:hasPage` is unordered.

## Gotchas that silently return zero rows

1. **Years are typed, and not uniformly.** An untyped `FILTER(?y >= "1850")`
   matches nothing against a gYear. Compare against a typed literal:

       FILTER(?y >= "1850"^^xsd:gYear && ?y <= "1900"^^xsd:gYear)

   What each date property actually holds:

       Title   dcterms:issued   196,590 gYear  +         3 string
       Item    dcterms:date     309,132 gYear              (always clean)
       Page    dcterms:date  15,709,600 gYear  + 1,032,784 string
       Article dcterms:date                    +   442,843 string

   So a gYear range filter over **page** dates silently discards the million
   string-typed ones ("1907-1915", "(1827)"), and over **article** dates it
   matches nothing at all. For dates you can rely on, take them from the item:
   every item has one and it is always a bare four-digit gYear. Filter article
   dates with `STRSTARTS(?date, "18")` or `SUBSTR(?date, 1, 4)`.

2. **No full-text index.** `ql:contains-word` is unavailable on this endpoint.
   Search text with `FILTER(CONTAINS(LCASE(?label), "orchid"))`. That is fine
   over titles, subjects and creator names (a few seconds), but never try it
   over `dwc:scientificName` across all 64 million pages.

3. **Language codes are three uppercase letters** — "ENG", not "en" or "eng".

4. **Subjects and institutions are plain strings, not IRIs**, so the same
   concept appears under several spellings.

5. **Aggregate with care.** `SELECT ?p (COUNT(*)) WHERE { ?s ?p ?o } GROUP BY ?p`
   times out. Always constrain by class or by a specific predicate first.

6. **Repeated properties multiply rows.** A Title with four subjects yields four
   rows when you also select `dcterms:title`; add `DISTINCT` or aggregate.
TEXT;
}

function examplesDoc()
{
    return <<<'TEXT'
# Example SPARQL queries for BHL

Prefixes are prepended automatically by the `sparql_query` tool.

## Titles published in a date range whose title mentions a word

    SELECT ?title ?label ?year WHERE {
      ?title dcterms:title ?label ;
             dcterms:issued ?year .
      FILTER(CONTAINS(LCASE(?label), "orchid"))
      FILTER(?year >= "1850"^^xsd:gYear && ?year <= "1900"^^xsd:gYear)
    }
    LIMIT 25

## Works by a named author

    SELECT ?title ?label ?year WHERE {
      ?creator foaf:name ?name .
      FILTER(CONTAINS(LCASE(?name), "darwin, charles"))
      ?title dcterms:creator ?creator ;
             dcterms:title ?label .
      OPTIONAL { ?title dcterms:issued ?year }
    }
    LIMIT 25

## Every page where the name-finder tagged a species

    SELECT ?page ?item ?year WHERE {
      ?page dwc:scientificName "Anableps anableps" ;
            dcterms:isPartOf ?item ;
            dcterms:date ?year .
    }
    LIMIT 100

## Pages for a species, resolved all the way up to the work and its title

    SELECT DISTINCT ?page ?year ?label WHERE {
      ?page dwc:scientificName "Anableps anableps" ;
            dcterms:isPartOf ?item .
      ?item dcterms:isPartOf ?title ;
            dcterms:date ?year .
      ?title dcterms:title ?label .
    }
    LIMIT 50

## Earliest page bearing a name

    SELECT ?page ?year WHERE {
      ?page dwc:scientificName "Anableps anableps" ;
            dcterms:date ?year .
    }
    ORDER BY ASC(?year)
    LIMIT 1

## An article's pages in printed order

    SELECT ?page ?seq ?printed WHERE {
      ?pos bhlv:part bhlpart:1 ;
           bhlv:page ?page ;
           bhlv:sequenceOrder ?seq .
      OPTIONAL { ?page bhlv:pageNumber ?printed }
    }
    ORDER BY ASC(?seq)

## Articles in a journal, by volume

    SELECT ?part ?label ?vol ?date WHERE {
      ?part bhlv:containerTitle "Breviora" ;
            dcterms:title ?label ;
            dcterms:date ?date .
      OPTIONAL { ?part bibo:volume ?vol }
    }
    ORDER BY ?date
    LIMIT 50

## Creators linked to Wikidata

    SELECT ?creator ?name ?wd WHERE {
      ?creator foaf:name ?name ;
               owl:sameAs ?wd .
      FILTER(STRSTARTS(STR(?wd), "http://www.wikidata.org/entity/"))
    }
    LIMIT 25

## Go the other way: which BHL author is this Wikidata item?

    SELECT ?creator ?name WHERE {
      ?creator owl:sameAs <http://www.wikidata.org/entity/Q157501> ;
               foaf:name ?name .
    }

## How many volumes of a serial were scanned, and by whom

    SELECT ?institution (COUNT(?item) AS ?volumes) WHERE {
      ?item dcterms:isPartOf bhlbib:1000 ;
            bhlv:institution ?institution .
    }
    GROUP BY ?institution
    ORDER BY DESC(?volumes)

## Subject headings used alongside a given one

    SELECT ?other (COUNT(*) AS ?n) WHERE {
      ?title dcterms:subject "Orchids" ;
             dcterms:subject ?other .
      FILTER(?other != "Orchids")
    }
    GROUP BY ?other
    ORDER BY DESC(?n)
    LIMIT 25

## Names co-occurring on the same page as a target name

    SELECT ?other (COUNT(DISTINCT ?page) AS ?pages) WHERE {
      ?page dwc:scientificName "Anableps anableps" ;
            dwc:scientificName ?other .
      FILTER(?other != "Anableps anableps")
    }
    GROUP BY ?other
    ORDER BY DESC(?pages)
    LIMIT 25
TEXT;
}

// ---- TOOLS -------------------------------------------------------------

/**
 * Each tool is [description, inputSchema, handler]. Handlers receive the
 * decoded arguments array and return an MCP tool result, or throw BhlError
 * with a message the model can act on.
 */
function toolRegistry()
{
    return [

    'search_titles' => [
        'title' => 'Search BHL titles',
        'description' =>
            "Search bibliographic titles (works: books and journal runs) by words in the title, "
          . "and optionally narrow by subject heading, language or year of publication. "
          . "Matching is case-insensitive substring matching, not stemmed or fuzzy: 'orchid' "
          . "matches 'Orchids' and 'Orchidophile', but 'orchids' will not match 'orchid'. "
          . "Returns the title IRI, its label and its year. Use get_record for full detail on a hit.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'q'         => ['type' => 'string', 'description' => 'Words to look for inside the title.'],
                'subject'   => ['type' => 'string', 'description' => 'Substring of a subject heading, e.g. "botany", "fishes".'],
                'language'  => ['type' => 'string', 'description' => 'Three-letter MARC language code, e.g. ENG, FRE, GER.'],
                'year_from' => ['type' => 'integer', 'description' => 'Earliest year of publication (inclusive).'],
                'year_to'   => ['type' => 'integer', 'description' => 'Latest year of publication (inclusive).'],
                'limit'     => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $hasQ       = isset($args['q']) && trim($args['q']) !== '';
            $hasSubject = isset($args['subject']) && trim($args['subject']) !== '';
            $hasLang    = isset($args['language']) && trim($args['language']) !== '';
            $yFrom      = isset($args['year_from']) ? (int)$args['year_from'] : null;
            $yTo        = isset($args['year_to']) ? (int)$args['year_to'] : null;

            if (!$hasQ && !$hasSubject && !$hasLang && $yFrom === null && $yTo === null) {
                throw new BhlError('Give at least one of q, subject, language, year_from or year_to.');
            }

            $where = ["  ?title a bhlv:Title ;\n         dcterms:title ?label ."];
            if ($hasQ) {
                $where[] = '  FILTER(CONTAINS(LCASE(?label), ' . sparqlString(mb_strtolower(trim($args['q']))) . '))';
            }
            if ($hasSubject) {
                $where[] = '  ?title dcterms:subject ?subject .';
                $where[] = '  FILTER(CONTAINS(LCASE(?subject), ' . sparqlString(mb_strtolower(trim($args['subject']))) . '))';
            }
            if ($hasLang) {
                $where[] = '  ?title dcterms:language ' . sparqlString(strtoupper(trim($args['language']))) . ' .';
            }
            // A year filter only makes sense on titles that actually carry a year,
            // so make ?year required in that case and optional otherwise.
            if ($yFrom !== null || $yTo !== null) {
                $where[] = '  ?title dcterms:issued ?year .';
                if ($yFrom !== null) $where[] = '  FILTER(?year >= "' . $yFrom . '"^^xsd:gYear)';
                if ($yTo !== null)   $where[] = '  FILTER(?year <= "' . $yTo . '"^^xsd:gYear)';
            } else {
                $where[] = '  OPTIONAL { ?title dcterms:issued ?year }';
            }
            // Undated titles sort first by default, which buries the useful rows.
            $order = 'ORDER BY DESC(BOUND(?year)) ASC(?year)';

            $q = prefixBlock()
               . "SELECT DISTINCT ?title ?label ?year WHERE {\n"
               . implode("\n", $where) . "\n"
               . "}\n$order\nLIMIT $limit";
            return runAndRender($q, $limit);
        },
    ],

    'search_creators' => [
        'title' => 'Search BHL creators',
        'description' =>
            "Find authors and corporate bodies by name. Names are stored inverted and usually "
          . "carry life dates, e.g. 'Hooker, Joseph Dalton, 1817-1911', so search on the surname "
          . "first: 'darwin, charles' works, 'charles darwin' does not. Matching is "
          . "case-insensitive substring matching.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'name'  => ['type' => 'string', 'description' => 'Part of the name, surname first.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
            'required' => ['name'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $name = sparqlString(mb_strtolower(trim(requireArg($args, 'name'))));
            $q = prefixBlock()
               . "SELECT ?creator ?name WHERE {\n"
               . "  ?creator a foaf:Agent ;\n"
               . "           foaf:name ?name .\n"
               . "  FILTER(CONTAINS(LCASE(?name), $name))\n"
               . "}\nLIMIT $limit";
            return runAndRender($q, $limit);
        },
    ],

    'get_record' => [
        'title' => 'Get a BHL record',
        'description' =>
            "Fetch everything known about one BHL record: a title, an item (scanned volume), "
          . "an article, a page or a creator. Accepts a full IRI or a short form such as "
          . "'bibliography/100', 'item/10', 'part/1', 'page/3190642' or 'creator/93'. "
          . "Also reports how many records point back at it.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => "Full IRI, or short form like 'bibliography/100' or 'creator/93'."],
            ],
            'required' => ['id'],
        ],
        'handler' => function ($args) {
            $iri = bhlIri(requireArg($args, 'id'));
            $q = prefixBlock()
               . "SELECT ?p ?o WHERE {\n  " . sparqlIri($iri) . " ?p ?o .\n}\nORDER BY ?p";
            $res = sparqlSelect($q);

            if (!$res['rows']) {
                return textResult("No record found for <$iri>.\n\nSPARQL used:\n```sparql\n"
                    . displayQuery($q) . "\n```\n");
            }

            // Group the property/value pairs so repeated properties read cleanly.
            $props = [];
            $types = [];
            foreach ($res['rows'] as $row) {
                $p = $row['p']['value'];
                $props[$p][] = renderValue($row['o']);
                if ($p === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type') {
                    $types[] = $row['o']['value'];
                }
            }

            $out = "# " . shortenIri($iri) . "\n<$iri>\n\n";
            foreach ($props as $p => $values) {
                sort($values, SORT_NATURAL);
                $out .= shortenIri($p) . "\n";
                foreach ($values as $v) {
                    $out .= "    $v\n";
                }
            }

            $back = inboundSummary($iri, $types);
            if ($back !== '') {
                $out .= "\n## Referred to by\n" . $back;
            }
            $out .= "\nSPARQL used:\n```sparql\n" . displayQuery($q) . "\n```\n";
            return textResult($out);
        },
    ],

    'titles_by_creator' => [
        'title' => 'Titles by a creator',
        'description' =>
            "List what a creator is credited on. dcterms:creator is used for both bibliographic "
          . "titles and articles, so by default you get both, labelled by kind; set kind to "
          . "'titles' or 'articles' to narrow. Takes a creator IRI or 'creator/93'; use "
          . "search_creators first if you only have a name.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'creator' => ['type' => 'string', 'description' => "Creator IRI or 'creator/93'."],
                'kind'    => ['type' => 'string', 'enum' => ['all', 'titles', 'articles'],
                              'description' => "Which works to list (default 'all')."],
                'limit'   => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
            'required' => ['creator'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $iri = sparqlIri(bhlIri(requireArg($args, 'creator'), 'creator'));
            $kind = isset($args['kind']) ? strtolower(trim($args['kind'])) : 'all';
            if (!in_array($kind, ['all', 'titles', 'articles'], true)) {
                throw new BhlError("kind must be 'all', 'titles' or 'articles'.");
            }

            $typeLine = '';
            if ($kind === 'titles')   $typeLine = "  ?work a bhlv:Title .\n";
            if ($kind === 'articles') $typeLine = "  ?work a bibo:Article .\n";

            // Titles date with dcterms:issued, articles with dcterms:date.
            $q = prefixBlock()
               . "SELECT DISTINCT ?work ?kind ?label ?year WHERE {\n"
               . "  ?work dcterms:creator $iri ;\n"
               . "        dcterms:title ?label ;\n"
               . "        a ?kind .\n"
               . $typeLine
               . "  OPTIONAL { ?work dcterms:issued ?issued }\n"
               . "  OPTIONAL { ?work dcterms:date ?date }\n"
               . "  BIND(COALESCE(?issued, ?date) AS ?year)\n"
               . "}\nORDER BY DESC(BOUND(?year)) ASC(?year)\nLIMIT $limit";
            return runAndRender($q, $limit);
        },
    ],

    'items_for_title' => [
        'title' => 'Scanned volumes of a title',
        'description' =>
            "List the scanned items (physical volumes) that belong to a bibliographic title, "
          . "with their year and holding institution. This is how you get from a serial to its "
          . "individual volumes. Takes a title IRI or 'bibliography/100'.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => "Title IRI or 'bibliography/100'."],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
            'required' => ['title'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $iri = sparqlIri(bhlIri(requireArg($args, 'title'), 'bibliography'));
            $q = prefixBlock()
               . "SELECT ?item ?year ?institution WHERE {\n"
               . "  ?item dcterms:isPartOf $iri .\n"
               . "  OPTIONAL { ?item dcterms:date ?year }\n"
               . "  OPTIONAL { ?item bhlv:institution ?institution }\n"
               . "}\nORDER BY DESC(BOUND(?year)) ASC(?year)\nLIMIT $limit";
            return runAndRender($q, $limit);
        },
    ],

    'articles_in_item' => [
        'title' => 'Articles in an item',
        'description' =>
            "List the articles and chapters segmented out of one scanned item, with volume, "
          . "date and page range. Takes an item IRI or 'item/22498'. Note that articles hang off "
          . "the item, not off the title.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'item'  => ['type' => 'string', 'description' => "Item IRI or 'item/22498'."],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
            'required' => ['item'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $iri = sparqlIri(bhlIri(requireArg($args, 'item'), 'item'));
            $q = prefixBlock()
               . "SELECT ?part ?label ?date ?volume ?pages WHERE {\n"
               . "  ?part a bibo:Article ;\n"
               . "        dcterms:isPartOf $iri ;\n"
               . "        dcterms:title ?label .\n"
               . "  OPTIONAL { ?part dcterms:date ?date }\n"
               . "  OPTIONAL { ?part bibo:volume ?volume }\n"
               . "  OPTIONAL { ?part bhlv:pageRange ?pages }\n"
               . "}\nORDER BY DESC(BOUND(?date)) ASC(?date)\nLIMIT $limit";
            return runAndRender($q, $limit);
        },
    ],

    'pages_for_name' => [
        'title' => 'Pages mentioning a scientific name',
        'description' =>
            "Find pages where BHL's automated name-finder tagged a scientific name. The name must "
          . "match exactly as written on the page, so try both the binomial ('Anableps anableps') "
          . "and the genus alone ('Anableps'). Results are OCR-derived, so expect some noise. "
          . "Set with_title to also resolve each page up to the work it appears in, which is "
          . "slower but far more useful.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'name'       => ['type' => 'string', 'description' => 'Exact scientific name as printed, e.g. "Anableps anableps".'],
                'with_title' => ['type' => 'boolean', 'description' => 'Also return the containing work and its title (default false).'],
                'limit'      => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
            'required' => ['name'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $name = sparqlString(trim(requireArg($args, 'name')));
            $withTitle = !empty($args['with_title']);

            if ($withTitle) {
                $q = prefixBlock()
                   . "SELECT DISTINCT ?page ?year ?item ?label WHERE {\n"
                   . "  ?page dwc:scientificName $name ;\n"
                   . "        dcterms:isPartOf ?item .\n"
                   . "  OPTIONAL { ?page dcterms:date ?pageYear }\n"
                   . "  OPTIONAL { ?item dcterms:date ?itemYear }\n"
                   . "  OPTIONAL { ?item dcterms:isPartOf ?title . ?title dcterms:title ?label }\n"
                   . "  BIND(COALESCE(?itemYear, ?pageYear) AS ?year)\n"
                   . "}\nORDER BY DESC(BOUND(?year)) ASC(?year)\nLIMIT $limit";
            } else {
                $q = prefixBlock()
                   . "SELECT ?page ?year ?item WHERE {\n"
                   . "  ?page dwc:scientificName $name ;\n"
                   . "        dcterms:isPartOf ?item .\n"
                   . "  OPTIONAL { ?page dcterms:date ?pageYear }\n"
                   . "  OPTIONAL { ?item dcterms:date ?itemYear }\n"
                   . "  BIND(COALESCE(?itemYear, ?pageYear) AS ?year)\n"
                   . "}\nORDER BY DESC(BOUND(?year)) ASC(?year)\nLIMIT $limit";
            }
            return runAndRender($q, $limit);
        },
    ],

    'resolve_identifier' => [
        'title' => 'Resolve an external identifier',
        'description' =>
            "Find the BHL record matching an identifier from elsewhere. Understands Wikidata "
          . "QIDs and IRIs, VIAF, OCLC, LCCN/DLC, DOIs and BioStor ids, given either bare "
          . "('Q157501', '17306215'), prefixed ('VIAF:17306215', 'Wikidata:Q54792313') or as a "
          . "full IRI. To go the other way -- BHL record to its external identifiers -- use get_record.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'identifier' => ['type' => 'string', 'description' => "e.g. 'Q157501', 'VIAF:17306215', '10.5962/bhl.title.100'."],
                'limit'      => ['type' => 'integer', 'description' => 'Max rows (default 25, max 500).'],
            ],
            'required' => ['identifier'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            list($iris, $literals) = identifierCandidates(trim(requireArg($args, 'identifier')));

            $branches = [];
            if ($iris) {
                $branches[] = "  { VALUES ?matched { " . implode(' ', array_map('sparqlIri', $iris))
                            . " }\n    ?record owl:sameAs ?matched }";
            }
            if ($literals) {
                $branches[] = "  { VALUES ?matched { " . implode(' ', array_map('sparqlString', $literals))
                            . " }\n    ?record dcterms:identifier ?matched }";
            }
            if (!$branches) {
                throw new BhlError('Could not turn that into anything searchable.');
            }

            $q = prefixBlock()
               . "SELECT ?record ?type ?label\n"
               . "       (GROUP_CONCAT(DISTINCT STR(?matched); SEPARATOR=\", \") AS ?matchedOn)\n"
               . "WHERE {\n"
               . implode("\n  UNION\n", $branches) . "\n"
               . "  OPTIONAL { ?record a ?type }\n"
               . "  OPTIONAL { ?record dcterms:title ?label }\n"
               . "  OPTIONAL { ?record foaf:name ?label }\n"
               . "}\nGROUP BY ?record ?type ?label\nLIMIT $limit";
            return runAndRender($q, $limit);
        },
    ],

    'sparql_query' => [
        'title' => 'Run a SPARQL query',
        'description' =>
            "Run any read-only SPARQL query against the BHL endpoint. The standard prefixes "
          . "(dcterms, bibo, dwc, foaf, owl, rdf, rdfs, xsd, bhlv, bhlbib, bhlitem, bhlpart, "
          . "bhlpage, bhlcreator) are prepended for you; declaring your own overrides them. "
          . "Read the bhl://schema resource first -- the graph has ~771 million triples and a "
          . "30 second query timeout, so unconstrained patterns and graph-wide aggregates will "
          . "time out, and years are xsd:gYear so untyped comparisons silently match nothing.",
        'schema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'SELECT, ASK or CONSTRUCT query. No PREFIX lines needed for the standard vocabularies.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows to display (default 25, max 500). Does not alter the query.'],
            ],
            'required' => ['query'],
        ],
        'handler' => function ($args) {
            $limit = clampLimit($args);
            $query = trim(requireArg($args, 'query'));
            assertReadOnly($query);
            return runAndRender(prefixBlock() . $query, $limit);
        },
    ],

    ];
}

// ---- TOOL SUPPORT ------------------------------------------------------

/** Count the records pointing at $iri, chosen by what kind of record it is. */
function inboundSummary($iri, $types)
{
    $s = sparqlIri($iri);
    $probes = [];
    foreach ($types as $t) {
        if ($t === 'https://www.biodiversitylibrary.org/vocab/Title') {
            $probes['scanned items'] = "?x dcterms:isPartOf $s";
        } elseif ($t === 'http://purl.org/ontology/bibo/Book') {
            $probes['pages']    = "?x a bibo:Page ; dcterms:isPartOf $s";
            $probes['articles'] = "?x a bibo:Article ; dcterms:isPartOf $s";
        } elseif ($t === 'http://xmlns.com/foaf/0.1/Agent') {
            $probes['titles']   = "?x dcterms:creator $s";
            $probes['articles'] = "?x a bibo:Article ; dcterms:creator $s";
        } elseif ($t === 'http://purl.org/ontology/bibo/Page') {
            $probes['articles containing this page'] = "?x bhlv:hasPage $s";
        }
    }
    if (!$probes) return '';

    $out = '';
    foreach ($probes as $label => $pattern) {
        try {
            $res = sparqlSelect(prefixBlock() . "SELECT (COUNT(DISTINCT ?x) AS ?n) WHERE { $pattern }");
            $n = isset($res['rows'][0]['n']['value']) ? $res['rows'][0]['n']['value'] : '?';
            $out .= "    $n $label\n";
        } catch (BhlError $e) {
            $out .= "    (could not count $label: " . $e->getMessage() . ")\n";
        }
    }
    return $out;
}

/** Expand a user-supplied external identifier into IRI and literal candidates. */
function identifierCandidates($id)
{
    $iris = [];
    $lits = [];

    $schemes = [
        'VIAF'     => 'http://viaf.org/viaf/',
        'WIKIDATA' => 'http://www.wikidata.org/entity/',
        'OCLC'     => 'http://www.worldcat.org/oclc/',
        'DLC'      => 'http://id.loc.gov/authorities/names/',
    ];

    if (preg_match('#^https?://#i', $id)) {
        $iris[] = $id;
        // A known IRI also has a prefixed string form in dcterms:identifier.
        foreach ($schemes as $scheme => $base) {
            if (strpos($id, $base) === 0) {
                $lits[] = ucfirst(strtolower($scheme)) . ':' . substr($id, strlen($base));
                if ($scheme !== 'WIKIDATA') $lits[] = $scheme . ':' . substr($id, strlen($base));
            }
        }
    } elseif (preg_match('/^(10\.\d{4,})\//', $id)) {
        $iris[] = 'https://doi.org/' . $id;
        $lits[] = $id;
        $lits[] = 'DOI:' . $id;
    } elseif (preg_match('/^Q\d+$/', $id)) {
        $iris[] = 'http://www.wikidata.org/entity/' . $id;
        $lits[] = 'Wikidata:' . $id;
    } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 ]*):(.+)$/', $id, $m)) {
        // Already prefixed, e.g. "VIAF:17306215" -- keep it, and derive the IRI.
        $lits[] = $id;
        $scheme = strtoupper(trim($m[1]));
        if (isset($schemes[$scheme])) {
            $iris[] = $schemes[$scheme] . trim($m[2]);
        }
    } elseif (ctype_digit($id)) {
        // A bare number is ambiguous; try the schemes that use bare numbers.
        foreach (['VIAF', 'OCLC'] as $scheme) {
            $lits[] = $scheme . ':' . $id;
            $iris[] = $schemes[$scheme] . $id;
        }
    } else {
        $lits[] = $id;
    }

    return [array_values(array_unique($iris)), array_values(array_unique($lits))];
}

/**
 * Reject SPARQL Update before it reaches the network. The endpoint only accepts
 * a `query` parameter anyway, so this is about giving a clear reason rather than
 * an opaque "No query provided".
 */
function assertReadOnly($query)
{
    // Strip comments and string literals so keywords inside them do not trip this.
    $stripped = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '""', $query);
    $stripped = preg_replace('/#[^\n]*/', '', $stripped);

    if (preg_match('/\b(INSERT|DELETE|LOAD|CLEAR|DROP|CREATE|COPY|MOVE)\b/i', $stripped, $m)) {
        throw new BhlError("This server is read-only, and the endpoint accepts queries only. "
            . "Remove the {$m[1]} clause. Use SELECT, ASK or CONSTRUCT.");
    }
    if (!preg_match('/\b(SELECT|ASK|CONSTRUCT|DESCRIBE)\b/i', $stripped)) {
        throw new BhlError('That does not look like a SPARQL query. Start with SELECT, ASK, CONSTRUCT or DESCRIBE.');
    }
}

// ---- RESOURCES ---------------------------------------------------------

function resourceRegistry()
{
    return [
        'bhl://schema' => [
            'name'        => 'BHL data model',
            'title'       => 'BHL RDF data model',
            'description' => 'Classes, properties, how records link together, and the traps that '
                           . 'make a plausible-looking query return nothing. Read this before writing SPARQL.',
            'mimeType'    => 'text/markdown',
            'body'        => 'schemaDoc',
        ],
        'bhl://examples' => [
            'name'        => 'BHL example queries',
            'title'       => 'Worked SPARQL examples',
            'description' => 'Working SPARQL queries against the BHL endpoint, from simple lookups '
                           . 'to joins across pages, items, titles and creators.',
            'mimeType'    => 'text/markdown',
            'body'        => 'examplesDoc',
        ],
    ];
}

// ---- JSON-RPC ----------------------------------------------------------

function rpcResult($id, $result)
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function rpcError($id, $code, $message, $data = null)
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) $err['data'] = $data;
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
}

function handleRpc($req)
{
    global $SUPPORTED_PROTOCOLS, $DEFAULT_PROTOCOL;

    $id     = isset($req['id']) ? $req['id'] : null;
    $method = isset($req['method']) ? $req['method'] : '';
    $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];

    switch ($method) {

        case 'initialize':
            $wanted = isset($params['protocolVersion']) ? $params['protocolVersion'] : null;
            $version = in_array($wanted, $SUPPORTED_PROTOCOLS, true) ? $wanted : $DEFAULT_PROTOCOL;
            return rpcResult($id, [
                'protocolVersion' => $version,
                'capabilities'    => [
                    'tools'     => ['listChanged' => false],
                    'resources' => ['listChanged' => false, 'subscribe' => false],
                ],
                'serverInfo' => [
                    'name'    => BHL_SERVER_NAME,
                    'title'   => 'Biodiversity Heritage Library (RDF)',
                    'version' => BHL_SERVER_VERSION,
                ],
                'instructions' =>
                    "Queries the Biodiversity Heritage Library's RDF graph: bibliographic titles, "
                  . "scanned volumes, articles, pages and creators, plus the scientific names that "
                  . "BHL's name-finder tagged on each page.\n\n"
                  . "Prefer the specific tools over raw SPARQL. Before writing SPARQL by hand, read "
                  . "the bhl://schema resource -- the graph is large, the endpoint times out after "
                  . "about 30 seconds, and several properties are typed in ways that make naive "
                  . "filters return nothing.\n\n"
                  . "Names on pages come from automated OCR matching, so treat them as evidence of "
                  . "a mention rather than as a verified determination.\n\n"
                  . "Everything in BHL is freely available to read, view and download: it is either in "
                  . "the public domain or was digitised with the rights holder's permission for open "
                  . "access. You may show, quote, summarise and link to titles, pages, page images and "
                  . "OCR text without hesitation. A missing or vague bhlv:copyrightStatus (the most "
                  . "common value is \"Not provided. Contact Holding Institution to verify copyright "
                  . "status.\") only means the rights metadata was not recorded, not that access is "
                  . "restricted. The status and any licence matter only if the user asks about "
                  . "republishing or commercial reuse; in that case report what the item says.",
            ]);

        case 'ping':
            return rpcResult($id, new stdClass());

        case 'tools/list':
            $tools = [];
            foreach (toolRegistry() as $name => $t) {
                $tools[] = [
                    'name'        => $name,
                    'title'       => $t['title'],
                    'description' => $t['description'],
                    'inputSchema' => $t['schema'],
                ];
            }
            return rpcResult($id, ['tools' => $tools]);

        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
            $registry = toolRegistry();
            if (!isset($registry[$name])) {
                return rpcResult($id, [
                    'content' => [['type' => 'text', 'text' =>
                        "Unknown tool '$name'. Available: " . implode(', ', array_keys($registry)) . '.']],
                    'isError' => true,
                ]);
            }
            try {
                return rpcResult($id, call_user_func($registry[$name]['handler'], $args));
            } catch (BhlError $e) {
                return rpcResult($id, [
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'isError' => true,
                ]);
            } catch (Throwable $e) {
                error_log('[' . BHL_SERVER_NAME . "] $name failed: " . $e->getMessage());
                return rpcResult($id, [
                    'content' => [['type' => 'text', 'text' => 'The server hit an unexpected error: ' . $e->getMessage()]],
                    'isError' => true,
                ]);
            }

        case 'resources/list':
            $out = [];
            foreach (resourceRegistry() as $uri => $r) {
                $out[] = [
                    'uri'         => $uri,
                    'name'        => $r['name'],
                    'title'       => $r['title'],
                    'description' => $r['description'],
                    'mimeType'    => $r['mimeType'],
                ];
            }
            return rpcResult($id, ['resources' => $out]);

        case 'resources/templates/list':
            return rpcResult($id, ['resourceTemplates' => []]);

        case 'resources/read':
            $uri = isset($params['uri']) ? $params['uri'] : '';
            $registry = resourceRegistry();
            if (!isset($registry[$uri])) {
                return rpcError($id, -32602, "Unknown resource: $uri");
            }
            $r = $registry[$uri];
            return rpcResult($id, ['contents' => [[
                'uri'      => $uri,
                'name'     => $r['name'],
                'mimeType' => $r['mimeType'],
                'text'     => call_user_func($r['body']),
            ]]]);

        case 'prompts/list':
            return rpcResult($id, ['prompts' => []]);

        default:
            return rpcError($id, -32601, "Method not found: $method");
    }
}

// ---- STREAMABLE HTTP TRANSPORT ----------------------------------------

/**
 * Last-resort handler: convert a fatal (most likely "Maximum execution time
 * exceeded" on a slow query) into a JSON-RPC error, so the client gets
 * something it can read instead of the web server's HTML error page.
 */
function emitFatalAsJsonRpc()
{
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) {
        return;   // a normal response already went out
    }

    $message = $e['message'];
    if (stripos($message, 'Maximum execution time') !== false) {
        $message = 'The query took longer than this server allows. The BHL endpoint '
                 . 'itself gives up after about 30 seconds, so add a more selective '
                 . 'triple pattern, filter by class, or add LIMIT.';
    }

    sendJson(rpcError(
        isset($GLOBALS['bhl_request_id']) ? $GLOBALS['bhl_request_id'] : null,
        -32603,
        $message
    ), 500);
}

function sendCors()
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
    header('Access-Control-Expose-Headers: Mcp-Session-Id, MCP-Protocol-Version');
    header('Access-Control-Max-Age: 86400');
}

function sendJson($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function infoPage()
{
    // Behind a TLS-terminating proxy (Caddy, nginx, a load balancer) the request
    // reaches PHP as plain HTTP, so $_SERVER['HTTPS'] is unset and we would print
    // an http:// URL that no remote MCP client will accept. Trust the forwarded
    // header, which is what the proxy sets.
    $proto = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        $proto = 'https';
    }
    $proto = ($proto === 'https') ? 'https' : 'http';

    $host = 'localhost';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    }

    $url = $proto . '://' . $host
         . (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/');

    $tools = '';
    foreach (toolRegistry() as $name => $t) {
        $tools .= '<dt><code>' . htmlspecialchars($name) . '</code></dt><dd>'
                . htmlspecialchars($t['title']) . "</dd>\n";
    }

    $u  = htmlspecialchars($url);
    $ep = htmlspecialchars(BHL_SPARQL_ENDPOINT);

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>BHL RDF — MCP server</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.6 system-ui, sans-serif; max-width: 46rem; margin: 3rem auto; padding: 0 1rem; }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .9em; }
  pre { background: rgba(127,127,127,.12); padding: .8rem 1rem; border-radius: 6px; overflow-x: auto; }
  dt { margin-top: .5rem; }
  dd { margin: 0 0 0 1.5rem; opacity: .8; }
  h1 { font-size: 1.5rem; }
  h2 { font-size: 1.1rem; margin-top: 2.5rem; }
</style>
<h1>BHL RDF — MCP server</h1>
<p>A Model Context Protocol server over the Biodiversity Heritage Library SPARQL
endpoint at <code>$ep</code>. Point an MCP client at this URL:</p>
<pre>$u</pre>

<h2>Claude Code</h2>
<pre>claude mcp add --transport http bhl $u</pre>

<h2>Claude Desktop / other clients</h2>
<pre>{
  "mcpServers": {
    "bhl": { "type": "http", "url": "$u" }
  }
}</pre>

<h2>Tools</h2>
<dl>
$tools</dl>

<h2>Resources</h2>
<dl>
<dt><code>bhl://schema</code></dt><dd>The data model, and the traps in it</dd>
<dt><code>bhl://examples</code></dt><dd>Worked SPARQL queries</dd>
</dl>

<h2>Or run it locally over stdio</h2>
<p>For clients that spawn the server as a child process, such as the Claude
desktop app:</p>
<pre>{
  "mcpServers": {
    "bhl-rdf": { "command": "php", "args": ["/path/to/mcp.php", "--stdio"] }
  }
}</pre>

<h2>Check it by hand</h2>
<pre>curl -sS $u \\
  -H 'Content-Type: application/json' \\
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
HTML;
}

function serveHttp()
{
    // Under mod_php or PHP-FPM the web SAPI default is max_execution_time=30,
    // which a slow SPARQL call can exceed. Ask for headroom; hosts that lock
    // this with php_admin_value will refuse, which is why the cURL timeout
    // below it is the real bound.
    @set_time_limit(httpTimeout() + 20);

    // If PHP dies anyway, the web server replies with an HTML error page that
    // no MCP client can parse. Turn any fatal into JSON-RPC on the way out.
    register_shutdown_function('emitFatalAsJsonRpc');

    sendCors();
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        return;
    }

    if ($method === 'DELETE') {
        // Session termination. This server is stateless, so there is nothing to drop.
        http_response_code(204);
        return;
    }

    if ($method === 'GET') {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
        if (strpos($accept, 'text/event-stream') !== false) {
            // No server-initiated messages, so decline the stream as the spec allows.
            http_response_code(405);
            header('Allow: POST, DELETE, OPTIONS');
            return;
        }
        infoPage();
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST, DELETE, OPTIONS');
        return;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if ($body === null) {
        sendJson(rpcError(null, -32700, 'Parse error: request body is not valid JSON'), 400);
        return;
    }

    if (is_array($body) && isset($body['id'])) {
        $GLOBALS['bhl_request_id'] = $body['id'];
    }

    $responses = dispatchMessages($body, $isBatch);

    if (!$responses) {
        http_response_code(202);
        return;
    }

    sendJson($isBatch ? $responses : $responses[0]);
}

/**
 * Turn one decoded JSON-RPC payload into the responses that need sending.
 * Shared by both transports. Notifications produce none, so an empty return
 * means "the client is not waiting for anything".
 *
 * @param bool $isBatch set to true when the payload was a JSON-RPC batch
 */
function dispatchMessages($body, &$isBatch)
{
    // A batch is a bare array. Batching was dropped in MCP 2025-06-18 but older
    // clients still send it, so keep handling it.
    $isBatch = is_array($body) && $body !== [] && array_keys($body) === range(0, count($body) - 1);
    $messages = $isBatch ? $body : [$body];

    $responses = [];
    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            $responses[] = rpcError(null, -32600, 'Invalid request');
            continue;
        }
        // Notifications and responses carry no id and get no reply.
        if (!array_key_exists('id', $msg) || $msg['id'] === null) {
            continue;
        }
        $responses[] = handleRpc($msg);
    }
    return $responses;
}

// ---- STDIO TRANSPORT ---------------------------------------------------

/**
 * MCP over stdio, for clients that launch the server as a child process
 * (Claude Desktop and friends): one JSON-RPC message per line on stdin,
 * one per line on stdout. Nothing but JSON-RPC may ever reach stdout, so
 * all diagnostics go to stderr.
 */
function serveStdio()
{
    ini_set('display_errors', '0');
    ini_set('error_log', 'php://stderr');
    fwrite(STDERR, '[' . BHL_SERVER_NAME . "] stdio transport ready\n");

    while (true) {
        $line = fgets(STDIN);

        if ($line === false) {
            // When the client is a Node process, our STDIN is a socket rather
            // than a plain pipe, so a read returns false after
            // default_socket_timeout seconds of silence (60 by default) with no
            // EOF in sight. Treating that as a closed pipe made the server quit
            // after a minute of idle chat. Only feof() means the client is gone.
            if (feof(STDIN)) {
                break;
            }
            $meta = stream_get_meta_data(STDIN);
            if (!empty($meta['timed_out'])) {
                continue;
            }
            fwrite(STDERR, '[' . BHL_SERVER_NAME . "] error reading stdin\n");
            break;
        }

        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $body = json_decode($line, true);
        if ($body === null) {
            fwrite(STDERR, '[' . BHL_SERVER_NAME . "] could not parse line as JSON\n");
            stdioSend(rpcError(null, -32700, 'Parse error: message is not valid JSON'));
            continue;
        }

        $isBatch = false;
        $responses = dispatchMessages($body, $isBatch);
        if (!$responses) {
            continue;
        }
        stdioSend($isBatch ? $responses : $responses[0]);
    }

    fwrite(STDERR, '[' . BHL_SERVER_NAME . "] stdin closed, shutting down\n");
    return 0;
}

function stdioSend($payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // json_encode escapes newlines, so one message is always exactly one line.
    fwrite(STDOUT, $json . "\n");
    fflush(STDOUT);
}

// ---- CLI SMOKE TEST ----------------------------------------------------

function runSmokeTest()
{
    $checks = [
        ['initialize',   ['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]],
        ['tools/list',   ['method' => 'tools/list']],
        ['schema',       ['method' => 'resources/read', 'params' => ['uri' => 'bhl://schema']]],
        ['search_titles',      ['method' => 'tools/call', 'params' => ['name' => 'search_titles',
            'arguments' => ['q' => 'orchid', 'year_from' => 1850, 'year_to' => 1900, 'limit' => 3]]]],
        ['search_creators',    ['method' => 'tools/call', 'params' => ['name' => 'search_creators',
            'arguments' => ['name' => 'darwin, charles', 'limit' => 3]]]],
        ['get_record',         ['method' => 'tools/call', 'params' => ['name' => 'get_record',
            'arguments' => ['id' => 'creator/93']]]],
        ['titles_by_creator',  ['method' => 'tools/call', 'params' => ['name' => 'titles_by_creator',
            'arguments' => ['creator' => 'creator/93', 'limit' => 3]]]],
        ['items_for_title',    ['method' => 'tools/call', 'params' => ['name' => 'items_for_title',
            'arguments' => ['title' => 'bibliography/100', 'limit' => 3]]]],
        ['articles_in_item',   ['method' => 'tools/call', 'params' => ['name' => 'articles_in_item',
            'arguments' => ['item' => 'item/22498', 'limit' => 3]]]],
        ['pages_for_name',     ['method' => 'tools/call', 'params' => ['name' => 'pages_for_name',
            'arguments' => ['name' => 'Anableps anableps', 'with_title' => true, 'limit' => 3]]]],
        ['resolve_identifier', ['method' => 'tools/call', 'params' => ['name' => 'resolve_identifier',
            'arguments' => ['identifier' => 'Q157501']]]],
        ['sparql_query',       ['method' => 'tools/call', 'params' => ['name' => 'sparql_query',
            'arguments' => ['query' => 'SELECT (COUNT(*) AS ?n) WHERE { ?s a bhlv:Title }']]]],
        ['read-only guard',    ['method' => 'tools/call', 'params' => ['name' => 'sparql_query',
            'arguments' => ['query' => 'INSERT DATA { <http://a/b> <http://a/c> <http://a/d> }']]]],
    ];

    $verbose = in_array('-v', $GLOBALS['argv'], true);
    $failed = 0;

    foreach ($checks as $i => $check) {
        list($label, $req) = $check;
        $req['jsonrpc'] = '2.0';
        $req['id'] = $i + 1;
        $t0 = microtime(true);
        $res = handleRpc($req);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $expectError = ($label === 'read-only guard');
        $isError = isset($res['error']) || !empty($res['result']['isError']);
        $ok = $expectError ? $isError : !$isError;
        if (!$ok) $failed++;

        printf("%-4s %-20s %6d ms\n", $ok ? 'ok' : 'FAIL', $label, $ms);
        if ($verbose || !$ok) {
            $text = isset($res['result']['content'][0]['text']) ? $res['result']['content'][0]['text'] : null;
            echo '     ' . str_replace("\n", "\n     ",
                rtrim(mb_substr($text !== null ? $text : json_encode($res), 0, $verbose ? 1200 : 400))) . "\n\n";
        }
    }

    echo "\n" . ($failed ? "$failed check(s) failed.\n" : "All checks passed.\n");
    return $failed ? 1 : 0;
}

// ---- ENTRY POINT -------------------------------------------------------

if (PHP_SAPI === 'cli') {
    exit(in_array('--stdio', $argv, true) ? serveStdio() : runSmokeTest());
}
serveHttp();
