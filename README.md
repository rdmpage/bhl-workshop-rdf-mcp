# bhl-workshop-rdf-mcp

An MCP server over the Biodiversity Heritage Library RDF graph, for the BHL workshop.

One file, no dependencies, two transports. Drop `mcp.php` on any PHP 7.4+ host with
cURL and point an MCP client at the URL — attendees need nothing installed locally.
Or run it as a local stdio server for testing. Tested on PHP 7.4 and 8.4.

It wraps the QLever SPARQL endpoint at
<https://koetai.semscape.org/u/0000-0001-9773-4008/bhl/sparql> (~771 million triples)
with task-shaped tools, a raw SPARQL escape hatch, and a schema resource that
documents the traps in the data.

## Install

Copy `mcp.php` to your web root, and optionally `.htaccess` alongside it if you want
the directory URL to work on Apache:

```
scp mcp.php .htaccess you@yourserver:/var/www/bhl-mcp/
```

Then the endpoint is `https://yourserver/bhl-mcp/`. The `.htaccess` only sets
`DirectoryIndex mcp.php`; without it the URL is `https://yourserver/bhl-mcp/mcp.php`,
or just rename the file to `index.php`.

Visit that URL in a browser to get a page listing the tools and the client config.

### Running under Apache

Works as-is under mod_php or PHP-FPM — it is an ordinary PHP script handling a
POST. Verified end to end against Apache 2.4 + PHP-FPM via `mod_proxy_fcgi`:
`POST` JSON-RPC, `202` for notifications, `OPTIONS` preflight, `DELETE`, the
`405` on an SSE `GET`, and CORS headers all behave exactly as under `php -S`.

Two things to get right:

**`AllowOverride`.** The `.htaccess` only sets `DirectoryIndex mcp.php`, and
Apache ignores it unless the enclosing `<Directory>` has `AllowOverride All`
(or at least `AllowOverride Indexes`). If you cannot change that, put
`DirectoryIndex mcp.php` in the vhost instead, or rename the file to `index.php`.

**Timeouts.** This is the one that bites. A slow SPARQL query that outlives
PHP's `max_execution_time` is killed outright under FPM — no fatal, no shutdown
handler — and Apache answers with an HTML `503`, which no MCP client can parse.
The server therefore stops waiting on the endpoint *before* the host does: it
reads `max_execution_time` and waits `min(35, limit - 5)` seconds, so on a
default `max_execution_time = 30` host it gives up at 25s and returns a real
JSON-RPC error telling the model to narrow the query. Override with
`BHL_HTTP_TIMEOUT` if your limits differ:

```apache
# only needed if you want to allow longer queries than the default
<Directory /var/www/bhl-mcp>
    AllowOverride All
    SetEnv BHL_HTTP_TIMEOUT 35
</Directory>
Timeout 120
ProxyTimeout 120
```

There is nothing to gain past ~35s: the BHL endpoint gives up on its own at
about 30.

## Connect a client

### Remote, over HTTP

Claude Code:

```
claude mcp add --transport http bhl https://yourserver/bhl-mcp/
```

Claude Desktop, or anything else taking an MCP JSON config:

```json
{
  "mcpServers": {
    "bhl": { "type": "http", "url": "https://yourserver/bhl-mcp/" }
  }
}
```

The transport is MCP Streamable HTTP (protocol 2025-06-18, negotiating down to
2025-03-26 and 2024-11-05). The server is stateless — no session id, so it works
behind a load balancer or on shared hosting.

### Local, over stdio

For the Claude desktop app, or any client that spawns the server as a child
process. Desktop's `claude_desktop_config.json` takes stdio servers only, so this
is the way to test locally there — no HTTPS, no tunnel, no bridge:

```json
{
  "mcpServers": {
    "bhl-rdf": {
      "command": "/opt/homebrew/opt/php@7.4/bin/php",
      "args": ["/path/to/mcp.php", "--stdio"]
    }
  }
}
```

Use an absolute path to the PHP binary: the desktop app is launched by the OS with
a minimal `PATH`, and macOS no longer ships `/usr/bin/php`, so a bare `php` may not
resolve. Restart the app after editing the config.

Both transports share the same dispatcher, so the tools behave identically.

## Tools

| Tool | What it does |
| --- | --- |
| `search_titles` | Search bibliographic titles by words, subject, language, year range |
| `search_creators` | Find authors and corporate bodies by name |
| `get_record` | Everything about one title, item, article, page or creator, plus inbound counts |
| `titles_by_creator` | What a creator is credited on, as titles, articles or both |
| `items_for_title` | The scanned volumes belonging to a title, with year and holding institution |
| `articles_in_item` | Articles segmented out of one scanned volume |
| `pages_for_name` | Pages where BHL's name-finder tagged a scientific name |
| `resolve_identifier` | Wikidata / VIAF / OCLC / LCCN / DOI → the BHL record |
| `get_page` | One page's image (returned to the model) and OCR text, plus image URLs to show |
| `get_text` | OCR text of an article, a run of pages in an item, or one page |
| `sparql_query` | Any read-only SPARQL, with the standard prefixes prepended |

Every SPARQL-backed tool echoes the query it ran, which is the point in a workshop: attendees
see a working query for each question they ask, and can edit it with `sparql_query`.

## Page text and images

`get_page` and `get_text` read BHL's open data on AWS
(<https://bhl-open-data.s3.amazonaws.com/>), not biodiversitylibrary.org, which sits
behind Cloudflare and often turns away automated requests. The graph supplies what
the AWS paths need: each item's scan barcode and each page's sequence number.

Page images come from the bucket's `web/` folder, which the bucket's own README
does not mention. Alongside the JPEG 2000 masters in `images/`, every page is there
as WebP at five sizes:

```
web/{barcode}/{barcode}_{seq:0000}_{size}.webp

thumb   ~235px    small   ~370px    medium  ~730px
large   ~1460px   full    the original scan
```

`get_page` returns the image itself, `large` by default, so the model can see the
page, along with the URL so a client can show it to the user.

## Resources

- `bhl://schema` — classes, properties, how records link, and the gotchas
- `bhl://examples` — worked queries from simple lookups to multi-hop joins

## Testing

Run the file from the command line for a smoke test against the live endpoint:

```
php mcp.php        # one line per check
php mcp.php -v     # also print each result
```

Drive it over stdio the way a client would — one JSON-RPC message per line:

```
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | php mcp.php --stdio
```

Or over HTTP:

```
php -S localhost:8000 mcp.php     # add PHP_CLI_SERVER_WORKERS=4 for concurrency

curl -sS http://localhost:8000 \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Configuration

All optional, via environment:

- `BHL_SPARQL_ENDPOINT` — defaults to the koetai BHL endpoint
- `BHL_HTTP_TIMEOUT` — seconds to wait on the endpoint. Unset, it derives a safe
  value from `max_execution_time`: `min(35, limit - 5)`, or 35 where there is no
  limit (the CLI default). See the Apache notes above for why.
- `BHL_S3_BASE` — the BHL open data bucket, for page text and images

## Notes on the endpoint

- **Read-only.** The endpoint accepts a `query` parameter only; the server also
  rejects SPARQL Update before it goes over the wire.
- **~30 second timeout.** Graph-wide aggregates and unconstrained scans fail.
  The server turns the timeout into an error message explaining what to do instead.
- **No full-text index.** `ql:contains-word` is unavailable here, so text search is
  `FILTER(CONTAINS(LCASE(?x), "..."))`. Fine over titles, subjects and names;
  hopeless over the 64 million pages.
- **Dates are inconsistently typed.** Years are `xsd:gYear` in most places but
  `xsd:string` on articles and on a million pages. This is the single most common
  reason a plausible query returns nothing; `bhl://schema` spells it out.

There is no authentication — anyone with the URL can run queries, which is what a
workshop wants. Put it behind a reverse proxy if you need otherwise.
