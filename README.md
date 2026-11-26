# ADX Marketing Knowledge — SharePoint MCP Connector

A lightweight, **read-only** PHP [Model Context Protocol](https://modelcontextprotocol.io/) server that lets the existing
**Claude Marketing Agent** search and read documents in the SharePoint folder **Agent Knowledge** (including all nested
subfolders) through Microsoft Graph.

> **Status: local development only.** Nothing here has been deployed. See [Preparing for hosting](#preparing-for-hosting)
> and [Production authentication](#production-authentication) before any deployment decision.

---

## Contents

- [What it does](#what-it-does)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Setup on Windows](#setup-on-windows)
- [Testing locally on Windows](#testing-locally-on-windows)
- [Configuration reference](#configuration-reference)
- [Security design](#security-design)
- [Production authentication](#production-authentication)
- [Preparing for hosting](#preparing-for-hosting)
- [Troubleshooting](#troubleshooting)

---

## What it does

The server exposes three MCP tools, all annotated `readOnlyHint: true`:

| Tool | Purpose |
|---|---|
| `search_documents` | Keyword search across file names, folder paths and (where Graph allows) document content. Optional `limit` (1–25) and `file_types` filter (e.g. `["pdf","docx"]`). |
| `list_folder` | Browse the folder tree. Omit `folder_id` for the Agent Knowledge root. |
| `get_document` | Return the text of a document by `document_id`. Long documents are paged: the response ends with the `offset` to continue from. |

Supported formats for text extraction: **.docx, .pptx (incl. speaker notes), .xlsx, .pdf, .txt, .md, .csv, .tsv, .json, .xml,
.html, .yaml**. Legacy `.doc/.xls/.ppt` and scanned (image-only) PDFs return a clear explanatory message instead.

Every result includes the document path and its SharePoint link so Claude can cite sources. Marketing staff keep working
in SharePoint as usual — new or updated files are picked up automatically (folder index refresh ≤ 10 minutes by default,
or immediately when a new ID is requested).

## Architecture

```
Claude (Marketing Agent)
   │  MCP over Streamable HTTP  (Authorization: Bearer …)
   ▼
public/index.php ──► MCP\HttpApplication
                       ├─ CORS + DNS-rebinding protection     (SDK middleware)
                       ├─ RFC 9728 protected-resource metadata (SDK middleware)
                       ├─ Bearer auth: static token | OAuth JWT (SDK AuthorizationMiddleware)
                       └─ Official MCP PHP SDK (mcp/sdk 0.10) StreamableHttpTransport
                              └─ MCP\KnowledgeTools  (search_documents, list_folder, get_document)
                                    ├─ SharePoint\SharePointRepository ── scope enforcement
                                    │     └─ SharePoint\FolderIndex  (recursive tree snapshot = allow-list)
                                    ├─ Documents\DocumentService + TextExtractor
                                    └─ Graph\GraphClient (retry/throttling, paging, size cap)
                                          └─ Auth\MicrosoftGraphAuth (client credentials, cached token)
                                                 ▼
                                   Microsoft Entra ID + Microsoft Graph v1.0
```

```
src/
├── Auth/        MicrosoftGraphAuth, encrypted token cache, StaticTokenValidator
├── Config/      Validated, secret-safe configuration from environment
├── Documents/   Text extraction (DOCX/PPTX/XLSX/PDF/HTML/text) and paging
├── Graph/       Read-only Graph REST client
├── Logging/     Monolog setup + RedactingProcessor
├── MCP/         Tool implementations, server definition, HTTP front controller
├── SharePoint/  Folder index, repository, DriveItem model
└── Kernel.php   Composition root
public/index.php   HTTP entry point
bin/check-graph.php  Graph connectivity check (no MCP involved)
tests/             PHPUnit suite with an in-memory fake Graph
```

**Key decisions**

- **Official SDK.** Uses `mcp/sdk` v0.10.0 (the official PHP SDK, maintained by the PHP Foundation & Symfony). Its APIs
  were verified against the installed source: `Server::builder()->addTool(...)`, `StreamableHttpTransport`,
  `AuthorizationMiddleware`, `ProtectedResourceMetadata`, `JwtTokenValidator::fromIssuer()`.
- **No framework.** Plain PSR-7/PSR-15 (Nyholm PSR-7, Guzzle 7, Monolog 3, phpdotenv).
- **Sessionless Streamable HTTP.** The tools are stateless, so no session store is needed. This works unchanged under
  `php -S`, PHP-FPM, or any shared host where each request is a fresh PHP process. The SDK also serves the newer stateless
  `2026-07-28` protocol revision on the same endpoint.
- **Scope enforcement by allow-list.** The connector walks the Agent Knowledge tree and only serves IDs found in it.
  An ID from anywhere else in the drive (or another drive) is answered with the same "not found" message as a
  non-existent ID, and no Graph request is made with it. This is in addition to the `Files.SelectedOperations.Selected`
  permission enforced by Microsoft.

## Requirements

- Windows 10/11
- **PHP 8.3 or newer** (tested on PHP 8.4.27) with extensions: `curl`, `openssl`, `mbstring`, `zip`, `dom`, `libxml`,
  `fileinfo`, `json`. All are included in the standard Windows PHP build (`winget install PHP.PHP.8.4`).
- **Composer 2.x**
- The existing Entra app values: Tenant ID, Client ID, Client Secret
- The SharePoint **drive ID** and the **Agent Knowledge folder item ID** (the same values used in the PowerShell tests)

Check:

```powershell
php -v
php -m
composer --version
```

## Setup on Windows

```powershell
cd C:\Projects\adx-marketing-mcp
composer install
```

A `.env` file has already been created from `.env.example` with a randomly generated `MCP_AUTH_TOKEN`.
If you need to recreate it:

```powershell
Copy-Item .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # paste the output into MCP_AUTH_TOKEN
```

Open `.env` and fill in:

```ini
MICROSOFT_TENANT_ID=<tenant GUID>
MICROSOFT_CLIENT_ID=<application (client) ID of "Claude Marketing SharePoint Connector">
MICROSOFT_CLIENT_SECRET=<client secret value>
SHAREPOINT_DRIVE_ID=<drive ID, usually starts with b!>
SHAREPOINT_ROOT_FOLDER_ID=<item ID of the Agent Knowledge folder>
```

> `.env` is in `.gitignore`. Never commit it, paste it into chat, or email it.

## Testing locally on Windows

### 1. Run the automated tests (no credentials needed)

```powershell
composer test
```

The suite (48 tests) uses an in-memory fake of Entra ID and Graph, including paging, throttling, the 302 download
redirect, and an out-of-scope "Payroll.xlsx" file that must never be reachable. It also checks end-to-end MCP requests
over HTTP and asserts that no token, secret or pre-authenticated download URL ever appears in a response.

### 2. Verify Microsoft Graph connectivity (real SharePoint)

```powershell
php bin/check-graph.php
php bin/check-graph.php "brand guidelines"
```

This authenticates, reads the Agent Knowledge root, lists the top level, and (with a query) searches and reads the
first matching document. It prints no secrets.

### 3. Start the MCP server

```powershell
composer serve
```

(equivalent to `php -S 127.0.0.1:8080 -t public public/index.php`). Leave this window open; request logs appear here
and in `var\log\app-YYYY-MM-DD.log`.

### 4. Call it from PowerShell

In a second PowerShell window:

```powershell
cd C:\Projects\adx-marketing-mcp
$token = (Select-String -Path .env -Pattern '^MCP_AUTH_TOKEN=(.+)$').Matches[0].Groups[1].Value
$h = @{ Authorization = "Bearer $token"; Accept = 'application/json, text/event-stream'; 'MCP-Protocol-Version' = '2025-06-18' }
$url = 'http://127.0.0.1:8080/mcp'

# Health (no auth)
Invoke-RestMethod http://127.0.0.1:8080/health

# Handshake
Invoke-RestMethod $url -Method Post -Headers $h -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"ps","version":"1"}}}'

# List tools
(Invoke-RestMethod $url -Method Post -Headers $h -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":2,"method":"tools/list"}').result.tools | Select-Object name, description

# Browse the root
(Invoke-RestMethod $url -Method Post -Headers $h -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_folder","arguments":{}}}').result.content[0].text

# Search
(Invoke-RestMethod $url -Method Post -Headers $h -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"search_documents","arguments":{"query":"brand"}}}').result.content[0].text

# Read a document (use a document_id from the search output)
(Invoke-RestMethod $url -Method Post -Headers $h -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"get_document","arguments":{"document_id":"PASTE_ID_HERE"}}}').result.content[0].text

# Negative test: no token -> 401
try { Invoke-RestMethod $url -Method Post -ContentType 'application/json' -Body '{}' } catch { $_.Exception.Response.StatusCode }
```

### 5. Test with the MCP Inspector (optional, requires Node.js)

```powershell
npx @modelcontextprotocol/inspector
```

In the Inspector UI choose **Transport: Streamable HTTP**, URL `http://127.0.0.1:8080/mcp`, and add the header
`Authorization: Bearer <MCP_AUTH_TOKEN>`. Connect, then run the tools from the **Tools** tab.

### 6. Test with Claude Code (optional)

Claude Code runs on your PC, so it can reach `127.0.0.1`:

```powershell
claude mcp add --transport http adx-knowledge http://127.0.0.1:8080/mcp --header "Authorization: Bearer <MCP_AUTH_TOKEN>"
```

Then ask Claude Code, for example: *"Search Agent Knowledge for our brand guidelines and summarise the tone of voice."*

> **Claude.ai Projects (the Claude Marketing Agent) cannot reach `127.0.0.1`.** Custom connectors on claude.ai are called
> from Anthropic's cloud, so the Marketing Agent can only use this connector once it is hosted at a public **HTTPS** URL
> (see below). Use steps 4–6 to validate behaviour locally first.

## Configuration reference

| Variable | Default | Notes |
|---|---|---|
| `APP_ENV` | `local` | `production` refuses to start unless `APP_DEBUG=false`, auth is `oauth` (or `MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION=true`), `MCP_PUBLIC_URL` is a non-local `https://` URL, and `MCP_ALLOWED_HOSTS` includes its host (no `*`). |
| `APP_DEBUG` | `true` | Never shows secrets either way. |
| `MICROSOFT_TENANT_ID` / `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` | — | Existing Entra app. Required. |
| `SHAREPOINT_DRIVE_ID` / `SHAREPOINT_ROOT_FOLDER_ID` | — | Agent Knowledge location. Required. |
| `MCP_SERVER_HOST` / `MCP_SERVER_PORT` | `127.0.0.1` / `8080` | Used for the default `MCP_PUBLIC_URL`. |
| `MCP_AUTH_TOKEN` | — | Static bearer token, ≥ 32 chars. Required when `MCP_AUTH_MODE=static`. |
| `MCP_MAX_DOCUMENT_SIZE_MB` | `20` | Larger files return a SharePoint link instead of text. Also enforced while streaming the download. |
| `MCP_MAX_RESPONSE_CHARS` | `30000` | Hard cap per tool response; long documents are paged. |
| `LOG_LEVEL` | `info` | PSR-3 level. |
| `MCP_AUTH_MODE` | `static` | `static` or `oauth`. |
| `MCP_PUBLIC_URL` | `http://127.0.0.1:8080/mcp` | Public endpoint URL; must be `https://` except on loopback. |
| `MCP_ALLOWED_HOSTS` | `localhost,127.0.0.1,[::1]` | Accepted `Host` headers (DNS-rebinding protection). Add your public hostname when hosted. |
| `MCP_OAUTH_ISSUER` / `MCP_OAUTH_AUDIENCE` | — | OAuth mode: token issuer and expected `aud`. JWKS is discovered from the issuer. |
| `MCP_OAUTH_REQUIRED_SCOPES` | — | OAuth mode: comma-separated scopes advertised in metadata. |
| `MCP_OAUTH_SCOPE_CLAIM` | `scope` | Use `scp` for Microsoft Entra-issued tokens. |
| `SHAREPOINT_INDEX_TTL_SECONDS` | `600` | Folder index cache lifetime. |
| `SHAREPOINT_MAX_INDEX_ITEMS` / `SHAREPOINT_MAX_FOLDER_DEPTH` | `5000` / `15` | Safety caps for the recursive walk. Responses say when the cap was hit. |
| `SHAREPOINT_TEXT_CACHE_TTL_SECONDS` | `3600` | Encrypted on-disk cache of extracted document text, so later pages of a long document are instant. Entries are keyed by last-modified time, so edits are never served stale. `0` disables. |
| `MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION` | `false` | Deliberate opt-in to run the shared-token mode with `APP_ENV=production`. |
| `STORAGE_PATH` | `var` | Writable directory for caches and logs. |

## Security design

- **Read-only.** Only `GET` requests are made to Graph. No write, share, or permission endpoints are called.
- **Existing app, existing permission.** Uses `Files.SelectedOperations.Selected` via client credentials and the
  `https://graph.microsoft.com/.default` scope. No new app registration or broader permission is requested.
- **Double scope enforcement.** Microsoft enforces the selected-folder grant; the connector additionally serves only IDs
  that are inside the Agent Knowledge tree, and validates ID format before use.
- **Secrets never leave the server.**
  - Client secret and MCP token are `#[\SensitiveParameter]`, excluded from `var_dump`/`print_r`, and never put in
    exception messages.
  - Graph tokens are cached on disk **encrypted** (AES-256-GCM, key derived from the client secret).
  - Graph's `@microsoft.graph.downloadUrl` (a pre-authenticated URL) is never stored or returned; downloads use
    `/content`, and Guzzle strips the Graph `Authorization` header on the redirect to the download host.
  - A Monolog `RedactingProcessor` scrubs bearer tokens, JWTs, `client_secret=`, `tempauth=` and configured secret values
    from every log line as a last line of defence.
  - Tool errors return short, generic, user-safe messages; details go to the log.
- **Input hardening.** Constant-time token comparison; request body size limit (SDK, 4 MB); OData-escaped search
  strings; only `graph.microsoft.com/v1.0` paging links are followed (no SSRF).
- **Document parsing hardening.** DTDs rejected (no XXE/billion-laughs), including ones hidden by UTF-16 encoding;
  `LIBXML_NONET`; libxml's own size/entity limits stay on; zip-bomb caps (100 MB expanded in total, 20 MB per XML part,
  counted on the real decompressed bytes rather than the zip header); per-sheet row cap; PDF decode memory cap.
  Make sure PHP's `memory_limit` is at least 256M on the host.
- **Prompt-injection awareness.** Document text is returned to Claude with a notice (and a server instruction) that it is
  untrusted reference material, not instructions. This reduces, but does not remove, the risk: also limit who can edit the
  Agent Knowledge folder in SharePoint.
- **Audit trail.** Every log line carries a `request_id`, returned to clients as the `X-Request-Id` header. Each tool call
  logs what was asked for (tool, folder/document id, offset, and for searches only the query length and a short hash, not
  the text) and whether it succeeded or was rejected.
- **Concurrency safety.** A file lock makes simultaneous requests share one folder-index rebuild instead of each crawling
  Graph (which would cause throttling).
- **HTTP hardening.** CORS and DNS-rebinding middleware from the SDK, `Cache-Control: no-store`,
  `X-Content-Type-Options: nosniff`, unauthenticated `/health` reveals nothing, and unknown paths return 404.

## Production authentication

> **Microsoft Entra OAuth:** the implementation, the exact Entra configuration for CMS, and the live test plan are in
> [docs/oauth-implementation.md](docs/oauth-implementation.md). That document supersedes the OAuth notes below where they differ.

`MCP_AUTH_TOKEN` is a **local test credential**. Before hosting, choose how Claude will authenticate. These are Claude's
documented options for custom remote MCP connectors (claude.com/docs/connectors, checked November 2026):

| Claude option | How it works | Fit for this connector |
|---|---|---|
| **OAuth** (DCR, CIMD, or Anthropic-held client credentials) | Claude runs an authorization-code + **PKCE (S256)** flow against your authorization server. Callback: `https://claude.ai/api/mcp/auth_callback`. Your MCP server must answer `401` with `WWW-Authenticate: Bearer resource_metadata="…"` and publish RFC 9728 metadata whose `resource` exactly matches the connector URL. Claude does **not** use the `client_credentials` grant. | **Recommended for production.** Per-user identity, revocation, and auditing. |
| **Static request headers** (beta) | A fixed header, for example `Authorization: Bearer <token>`, is sent with every request. Available only to some organisations; on Team/Enterprise only an Owner (or an Enterprise custom role) can add it. Cannot be edited after creation (remove and re-add). | Simplest. Works with this server's `static` mode as-is, but it is a shared secret with no per-user identity. Acceptable as an interim step **only** combined with the IP allowlist below. |
| **None** | No authentication. | **Not acceptable**: the folder holds internal business content. |

**What is already implemented**

- `MCP_AUTH_MODE=static`: works with Claude's *Request headers* option and with local tools.
- `MCP_AUTH_MODE=oauth`: validates RS256 JWT access tokens (issuer, audience, expiry, signature via JWKS discovered from
  the issuer), and serves RFC 9728 protected-resource metadata and the `401` + `resource_metadata` challenge Claude
  requires. **This mode has not yet been tested with Claude.**

**What still needs a decision (with ADX Depot IT)**

1. **Which authorization server.** Using Microsoft Entra ID directly is attractive (staff already sign in with it), but
   Claude needs PKCE S256 advertised in the authorization server's metadata, plus DCR, CIMD, or pre-registered client
   credentials. Public reports describe Entra-backed connectors failing to connect on claude.ai. Options:
   - **(a)** Entra ID directly, with a *separate* app registration for user sign-in (not the Graph app; that one stays
     app-only). Pilot this before committing to it.
   - **(b)** An MCP-aware OAuth broker in front of Entra (e.g. WorkOS AuthKit, Auth0, Stytch, or Cloudflare's OAuth
     provider) that federates to Entra and meets Claude's requirements.
2. **Who may use it.** Restrict sign-in to the marketing security group at the authorization server.
3. **Defence in depth.** Whatever the auth method, allow inbound traffic only from Anthropic's published egress range
   (`160.79.104.0/21` as of November 2026; re-check the docs before configuring), and keep the connector read-only.

## Preparing for hosting

Not done yet — checklist for when a host is chosen:

- [ ] HTTPS with a valid certificate (claude.ai requires a public HTTPS URL).
- [ ] Web server document root → `public/`; route all requests to `public/index.php`. Deny access to everything else
      (`.env`, `vendor/`, `var/`, `src/`).
- [ ] `composer install --no-dev --optimize-autoloader --classmap-authoritative`
- [ ] Set configuration as real environment variables (or a `.env` outside the web root). Real environment variables
      take precedence over `.env`.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info`.
- [ ] `MCP_PUBLIC_URL=https://<host>/mcp` and `MCP_ALLOWED_HOSTS=<host>`.
- [ ] Choose and configure production authentication (above).
- [ ] `var/` writable by the PHP user only; ship logs to central logging; rotate logs.
- [ ] Rotate the Entra client secret before it expires; consider moving to a certificate credential.
- [ ] Firewall / WAF allowlist for Anthropic egress IPs.
- [ ] Re-run `composer test` and `php bin/check-graph.php` on the host.

No long-lived connections are required: the server answers each POST with a single JSON response and does not open
SSE streams, so ordinary PHP-FPM hosting is sufficient.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `{"error":"server_misconfigured"}` | A required `.env` value is missing. The PHP console names the missing keys (never values). |
| `AADSTS7000215` | Wrong client secret value (use the secret **value**, not its ID) or the secret has expired. |
| `AADSTS700016` | Wrong `MICROSOFT_CLIENT_ID` or tenant. |
| "Access denied: the connector is not permitted to read this item" | The selected-permission grant does not cover that folder or drive. Check with IT that the grant targets the Agent Knowledge folder in this drive. |
| Search finds file names but never content | Graph search is not permitted for selected-permission apps on this tenant; the connector falls back to name/path search automatically (logged at `notice`). |
| A brand-new file is not found | Index cache TTL (default 10 min). Requesting its ID directly forces a refresh; or lower `SHAREPOINT_INDEX_TTL_SECONDS`. |
| `401` from `/mcp` | Missing or incorrect `Authorization: Bearer` header. |
| `403` / host rejected | The request's `Host` header isn't in `MCP_ALLOWED_HOSTS`. |
| Response says "Content continues…" | Normal paging for long documents; call `get_document` again with the given `offset`. |
