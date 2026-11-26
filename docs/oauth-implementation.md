# Microsoft Entra OAuth — Implementation & CMS Configuration

**Project:** ADX Marketing Knowledge — SharePoint MCP Connector
**Status:** OAuth mode is implemented and passes local automated tests. It has **not** yet been tested against a real
Entra tenant or Claude. That needs the app registration CMS is creating.
**Scope:** staff sign-in to the MCP server only. Microsoft Graph / SharePoint access (app-only, client credentials,
`Files.SelectedOperations.Selected`) is unchanged and completely separate.

All IDs below are placeholders. No real identifiers, secrets or tokens appear in this document or the code.

---

## 1. Summary of findings

### 1.1 Required scopes were advertised but not enforced (fixed)

The SDK's `AuthorizationMiddleware` only checks scopes when it is given a `ScopePolicy`. `HttpApplication` passed
`null`. So before this change `MCP_OAUTH_REQUIRED_SCOPES` only appeared in RFC 9728 metadata and the
`WWW-Authenticate` challenge. **Any authentic token for the right audience was accepted, whatever its scopes**,
including app-only tokens and tokens consented for different permissions.

`JwtTokenValidator` (SDK) checks signature (JWKS), `alg` allow-list (RS256), `iss` (exact match), `aud`, `exp`/`nbf`,
and the presence of `exp`. It only *extracts* scopes from the configured claim. It does not enforce them.

### 1.2 Entra uses two forms of a scope, so one variable could not serve both purposes (fixed)

| Purpose | Entra form | Example |
|---|---|---|
| What a client **requests** (authorize request, PRM `scopes_supported`, `WWW-Authenticate scope=`) | full: `<Application ID URI>/<name>` | `https://<MCP_HOST>/mcp/access_as_user` |
| What the token **contains** (`scp` claim) | bare name | `access_as_user` |

Advertising the bare name would make Claude ask Entra for `access_as_user`, which Entra treats as a Microsoft Graph
scope. Requiring the full name would reject every valid token. There was a second, subtler problem. Even if the SDK's
`ScopePolicy` had been used, its 403 challenge puts the *required* (bare) scopes into `WWW-Authenticate`, so Claude's
step-up retry would ask for the wrong thing. The application therefore enforces scopes itself and advertises the
request form. `MCP_OAUTH_ADVERTISED_SCOPES` was introduced because the architecture genuinely needs both values.

### 1.3 SDK parts that already work with Entra (kept unchanged)

- **OIDC discovery:** for issuer `https://login.microsoftonline.com/<TENANT>/v2.0`, `OidcDiscovery` tries RFC 8414
  path-insertion (404 on Entra), then OIDC path-insertion, then `<issuer>/.well-known/openid-configuration`, which
  Entra serves. It checks the document's `issuer` equals the configured issuer exactly.
- **JWKS:** Entra publishes keys without `alg`. The SDK tags each key with the token's (already allow-listed)
  algorithm. Keys are cached, and refetches on unknown `kid` are rate-limited (key rotation is handled).
- **Tenant pinning:** exact `iss` matching means tokens from other tenants and v1.0 tokens (`https://sts.windows.net/...`)
  are rejected.
- **401 challenge:** `WWW-Authenticate: Bearer resource_metadata="…", scope="…"` as Claude requires. PRM is served at
  `/.well-known/oauth-protected-resource/mcp` with `resource` equal to `MCP_PUBLIC_URL`.

### 1.4 Claude ↔ Entra compatibility (verified against current documentation, Nov 2026)

| Requirement | Claude behaviour | Entra behaviour | Consequence |
|---|---|---|---|
| Client registration | Needs DCR, CIMD, or a pre-registered client (“Use your own OAuth client”) | No DCR; CIMD is public preview only | **Use a pre-registered client.** The Claude org Owner (CMS) enters its client ID (+ secret) in Claude. |
| `resource` (RFC 8707) | Always sent on authorize and token requests, equal to the MCP URL, and cannot be disabled on claude.ai | Evaluated; fails with `AADSTS9010010` unless the MCP URL is an **Application ID URI** of the API app (`api://<id>` alone is not enough) | **The API app must have `https://<MCP_HOST>/mcp` as an Application ID URI.** |
| PKCE | Always S256 | Supported for confidential and public clients | OK |
| AS metadata discovery | RFC 8414 first, then OIDC | v2.0 metadata via OIDC; it does **not** list `code_challenge_methods_supported` or `registration_endpoint` | Pre-registered client avoids DCR. Whether Claude tolerates the missing S256 advertisement is **unverified**. |
| Scopes | Uses `scope=` from the 401, else PRM `scopes_supported`; adds `offline_access` only if AS metadata lists it | Refresh tokens only with `offline_access` | Advertise `offline_access` explicitly. |
| Redirect URI | `https://claude.ai/api/mcp/auth_callback` (Claude Code: `http://localhost:<port>/callback`) | Web platform redirect | Register both. |
| Token audience | n/a | v2.0 access tokens: `aud` is **always the API's client ID GUID** | `MCP_OAUTH_AUDIENCE` = client ID GUID. |

**Verdict.** Claude can be pointed **directly** at Entra with the configuration in §6, and Claude's own documentation
now describes an Entra setup. However, there is an **open, unresolved Claude issue**
([anthropics/claude-ai-mcp#506](https://github.com/anthropics/claude-ai-mcp/issues/506), opened 2026-06-29; a duplicate,
#754, was closed 2026-10-12). In it, Entra sign-in succeeds but claude.ai never calls the token endpoint. Users report
the workaround is an OAuth **intermediary**: a small authorization server that federates to Entra. The server
supports that path without code changes (`MCP_OAUTH_AUTHORIZATION_SERVER`, see §3). **Plan a live pilot of the direct
setup first and treat the intermediary as the fallback.** Token validation working locally does not by itself prove
Claude compatibility.

Sources: claude.com/docs/connectors/building/authentication, …/custom/add-unlisted, …/troubleshooting;
learn.microsoft.com: access-token-claims-reference, v2-oauth2-auth-code-flow, identifier-uri-restrictions,
howto-restrict-your-app-to-a-set-of-users, assign-user-or-group-access-portal, client-id-metadata-documents,
secure-mcp-server-with-entra-id.

### 1.5 Staff authorization design

| Control | Who | Effect |
|---|---|---|
| Enterprise Application **Assignment required = Yes**, staff group assigned | CMS (Entra) | Unassigned users cannot sign in or get a token (`AADSTS50105`). **Primary control.** |
| App role `MCP.Access` on the API app, group assigned to that role | CMS (Entra) | Assigned users get `roles: ["MCP.Access"]` in their token. |
| `MCP_OAUTH_REQUIRED_ROLES=MCP.Access` | PHP app | Server refuses tokens without the role (403 `access_denied`). **Defence in depth**: it still protects if assignment is switched off by mistake. |
| `MCP_OAUTH_ALLOWED_CLIENT_IDS` | PHP app | Only tokens issued to the approved OAuth client (`azp`) are accepted, so a token for this API obtained by some other app is refused. |
| `MCP_OAUTH_REQUIRED_SCOPES` | PHP app | Delegated user tokens only. App-only tokens have no `scp` and are refused. |

The server deliberately does **not** use the `groups` claim: it would need a hard-coded group object ID, suffers
group overage for users in many groups, and gains nothing over app roles. No Microsoft Graph permission was added.

---

## 2. Files changed

| File | Change |
|---|---|
| `src/Auth/AccessPolicyValidator.php` | **New.** Application authorization policy wrapping the SDK `JwtTokenValidator`. |
| `src/Config/Config.php` | New OAuth settings and fail-closed validation of OAuth / Entra configuration. |
| `src/Kernel.php` | Wraps the SDK validator in the policy, advertises request-form scopes, optional authorization-server override, multiple audiences. |
| `.env.example` | Documented OAuth placeholders. (`.env` was not touched.) |
| `tests/Auth/OAuthModeTest.php` | **New.** 48 OAuth test cases (290 assertions), run end to end over HTTP. |
| `tests/Support/FakeEntra.php` | **New.** Mints signed test JWTs; serves Entra-shaped OIDC discovery and JWKS. |
| `tests/Fixtures/oauth/test-signing-key.pem`, `attacker-signing-key.pem` | **New.** Test-only RSA keys, labelled as fixtures and trusted by nothing. |
| `tests/Support/FakeGraph.php` | Optional hook so `FakeEntra` can answer GET requests to `login.microsoftonline.com`. |
| `tests/ConfigAndLoggingTest.php` | Production fixture updated to a valid Entra configuration (the previous one used an `api://` audience and a non-GUID tenant, which are now correctly refused). |
| `docs/oauth-implementation.md` | **New.** This document. |

No files in `vendor/` were modified. No dependencies were added.

## 3. What each change does

**`AccessPolicyValidator`.** It implements the SDK's `AuthorizationTokenValidatorInterface`, so it plugs into the SDK's
`AuthorizationMiddleware` unchanged. It first delegates to `JwtTokenValidator` (authenticity), then applies these checks
in order:
1. `tid` must equal the tenant in the issuer (401 `invalid_token`).
2. If configured, `azp` (or v1 `appid`) must be an allowed client (401 `invalid_token`).
3. Every required scope must be in `scp` (403 `insufficient_scope`, `scope="<advertised scopes>"`).
4. If configured, every required role must be in `roles` (403 `access_denied`). This is deliberately not
   `insufficient_scope`, because requesting more scopes cannot fix a missing assignment.

It refuses to be built with no required scopes. It logs every refusal with a reason and a hashed user reference
(`u_<12 hex>`). It never logs tokens, names or emails.

**`Config`.** New settings: `MCP_OAUTH_ADVERTISED_SCOPES`, `MCP_OAUTH_REQUIRED_ROLES`, `MCP_OAUTH_ALLOWED_CLIENT_IDS`,
`MCP_OAUTH_AUTHORIZATION_SERVER`. `MCP_OAUTH_AUDIENCE` now accepts a comma-separated list. In OAuth mode the
server **refuses to start** when any of the following is true:
- issuer, audience or required scopes are missing;
- the issuer is not https;
- `REPLACE_…` placeholders were left in.

For Entra issuers it also refuses:
- `common`, `organizations`, domain-name or v1 (`sts.windows.net`) issuers;
- an audience that is not a GUID (e.g. `api://…`);
- a scope claim other than `scp`;
- a full `api://…` value in the required scopes;
- a bare name in the advertised scopes;
- missing advertised scopes;
- non-GUID client IDs.

Each message names the setting and explains the correct value, without echoing secrets.

**`Kernel`.** In OAuth mode it builds `AccessPolicyValidator(JwtTokenValidator::fromIssuer(...))`. RFC 9728 metadata now
advertises `MCP_OAUTH_ADVERTISED_SCOPES`, and the authorization server is `MCP_OAUTH_AUTHORIZATION_SERVER` (default:
the issuer). That override lets an Entra-federating intermediary be advertised while tokens are still validated as Entra
tokens. If an intermediary issues its *own* tokens, set issuer and audience to its values instead.

**Static vs OAuth.** The mode selects exactly one validator. In OAuth mode `MCP_AUTH_TOKEN` is never consulted. A static
token is not a JWT and gets 401 `invalid_token` ("malformed"). In static mode a valid Entra JWT is rejected. Production
still refuses static mode unless `MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION=true`.

## 4. Final OAuth configuration (placeholders)

```ini
MCP_AUTH_MODE=oauth
MCP_PUBLIC_URL=https://<MCP_HOST>/mcp
MCP_ALLOWED_HOSTS=<MCP_HOST>

MCP_OAUTH_ISSUER=https://login.microsoftonline.com/<ADX_TENANT_ID>/v2.0
MCP_OAUTH_AUDIENCE=<OAUTH_API_CLIENT_ID>
MCP_OAUTH_SCOPE_CLAIM=scp
MCP_OAUTH_REQUIRED_SCOPES=access_as_user
MCP_OAUTH_ADVERTISED_SCOPES=https://<MCP_HOST>/mcp/access_as_user offline_access
MCP_OAUTH_REQUIRED_ROLES=MCP.Access
MCP_OAUTH_ALLOWED_CLIENT_IDS=<CLAUDE_OAUTH_CLIENT_ID>
# MCP_OAUTH_AUTHORIZATION_SERVER=        # leave blank for direct Entra; set only for an intermediary
```

Differences from the originally proposed values:
- **Added** `MCP_OAUTH_ADVERTISED_SCOPES`.
- **Recommended** using the MCP URL as the scope prefix (required by Claude's `resource` parameter, §1.4). `api://<id>/access_as_user`
  is also accepted by the server, but Entra must then reconcile two different identifiers in one request, which is
  unverified.
- **Added** the optional role and client checks.

With the single-registration design in §6, `<CLAUDE_OAUTH_CLIENT_ID>` equals `<OAUTH_API_CLIENT_ID>`.

The MCP server needs **no client secret** for OAuth mode. The OAuth client secret is entered into Claude only.

## 5. Automated test results (local only)

```
vendor/bin/phpunit
OK (138 tests, 549 assertions)
```

These are **local automated tests**. Tokens are signed with fixture keys, and discovery and JWKS are served by
`FakeEntra` through Guzzle's handler stack. The real SDK middleware, JWT validation, discovery and caching code run
unmodified. SharePoint behaviour is checked against the existing in-memory Graph fake.

| Area | Covered by `tests/Auth/OAuthModeTest.php` |
|---|---|
| Valid token | Accepted; tools work end to end; discovery and JWKS fetched once and cached. |
| Missing token | 401 with `resource_metadata` and advertised `scope`. |
| Invalid signature | Other key; tampered payload; HS256 algorithm confusion; unknown `kid`. |
| Expiry | Expired; not yet valid; no `exp`. |
| Issuer / tenant | Other tenant's issuer; v1.0 issuer; correct issuer with a foreign `tid`. |
| Audience | Another API; `api://` URI as `aud`; Microsoft Graph token. |
| Scopes | No `scp` (app-only token); different scope; scope only in wrong claim (`scope`); lookalike `access_as_user_admin`; 403 challenge carries request-form scope; multiple required scopes. |
| Users | Missing / wrong app role → 403 `access_denied`; client allow-list. |
| Static token in OAuth mode | 401; valid Entra JWT in static mode → 401; static mode still works. |
| Metadata | PRM `resource`, `authorization_servers`, `scopes_supported`; intermediary override. |
| Failure handling | Discovery outage → 503 (fail closed); rejected requests never reach Graph. |
| Configuration | 15 incomplete or incorrect configurations refuse to start. |
| Leakage | Every OAuth test asserts the bearer token, client secret, static token and user email appear in no response or log line. |

The full suite also re-ran every pre-existing Graph, SharePoint, document, caching, audit and static-auth test with no
regressions.

**Not covered (needs live integration testing, §8):** real Entra token issuance, consent, user assignment,
Claude's discovery and token exchange, refresh tokens, and the `resource` / Application ID URI behaviour.

## 6. Answers for CMS

**1. Can we configure and test the server's OAuth mode?**
Yes. OAuth mode is complete and passes the automated tests above. It can be configured as soon as the app registration
exists, using §4. A live test plan is in §8.

**2. Does `MCP_OAUTH_AUDIENCE` expect the client ID or the `api://` URI?**
**The client ID GUID** of the OAuth API app registration. Entra v2.0 access tokens always carry the API's client ID in
`aud`, whatever identifier was requested. The server refuses to start with an `api://` value for an Entra issuer.
This requires the API app to issue v2.0 tokens (`requestedAccessTokenVersion = 2`).

**3. Does `MCP_OAUTH_REQUIRED_SCOPES` expect the full API scope or only the scope name?**
**Only the scope name** (`access_as_user`), because that is what appears in the token's `scp` claim. The full scope goes in
the new `MCP_OAUTH_ADVERTISED_SCOPES` (`https://<MCP_HOST>/mcp/access_as_user offline_access`). The server refuses a full
URI in `MCP_OAUTH_REQUIRED_SCOPES`.

**4. Which security group should be allowed to sign in?**
**`ADX Marketing MCP Users`**, containing the **marketing team only**. ADX Depot has confirmed both the name and the
audience. The server does not reference any group ID. Configure it in Entra:
- Enterprise Application → Assignment required = **Yes**.
- Assign that group to the **`MCP.Access`** app role.
- Set `MCP_OAUTH_REQUIRED_ROLES=MCP.Access`.

Assigning a group needs **Entra ID P1 or P2** (included in Microsoft 365 Business Premium, E3 and E5). Without it,
assign the marketing team members to the app individually instead. Nested groups are not supported, so members must be
direct members of the assigned group.

### Exact Microsoft Entra configuration for CMS

Recommended: **one new app registration** that is both the protected API and the OAuth client Claude uses. This is
simplest: one secret, and `azp = aud`. A separate client registration also works, but needs admin consent and must be
listed in `MCP_OAUTH_ALLOWED_CLIENT_IDS`.

| Setting | Value |
|---|---|
| Name | e.g. `Claude Marketing MCP (Staff Sign-in)`. Separate from `Claude Marketing SharePoint Connector`. |
| Supported account types | **Accounts in this organizational directory only** (single tenant). |
| Access token version | Manifest `api.requestedAccessTokenVersion` = **2**. |
| Application ID URI(s) | **`https://<MCP_HOST>/mcp`**, exactly equal to `MCP_PUBLIC_URL`. Required because Claude sends `resource=<MCP URL>`. The domain should be a verified domain in the tenant (or covered by Entra's identifier-URI exemption for v2 apps). `api://<OAUTH_API_CLIENT_ID>` may be added as well. |
| Expose an API → scope | Name **`access_as_user`**; who can consent: **Admins only**; state Enabled. |
| App roles | **`MCP.Access`** (display "ADX Marketing MCP access"); allowed member types: Users/Groups. |
| API permissions | `access_as_user` (this API), Microsoft Graph delegated `openid` and `offline_access`. **Grant admin consent.** Add nothing else. |
| Authentication → platform | **Web**. Redirect URIs: `https://claude.ai/api/mcp/auth_callback`; for local Claude Code testing also `http://localhost/callback`. Implicit grant: off. Allow public client flows: **No**. |
| Certificates & secrets | One client secret, ≤ 12 months. As CMS is also the Claude org Owner, enter it **directly** into the Claude connector; it does not need to be shared with anyone. **It is not needed by the MCP server.** |
| Token configuration | No optional claims needed. Do **not** add a groups claim. |
| Enterprise Application | **Assignment required = Yes**; assign the confirmed staff group to role `MCP.Access`; Visible to users = No. |
| Conditional Access (recommended) | Require MFA / compliant device for this app per ADX policy. |
| Network | Allow inbound HTTPS to `<MCP_HOST>` from Claude's egress range `160.79.104.0/21` (re-check Claude's docs at configuration time). Allow the MCP host outbound to `login.microsoftonline.com` (OIDC discovery and JWKS). |

**Web server and Cloudflare (needed for OAuth discovery):**
- Route **`/.well-known/oauth-protected-resource/mcp`** to `index.php`. A rule that blocks hidden files and folders
  (`location ~ /\.`) also blocks `/.well-known/`, and Claude cannot discover the sign-in without it.
- Turn off Cloudflare bot protection and JavaScript challenges for `/mcp` and `/.well-known/`.
- During testing, temporarily allow ADX's office IP through the Anthropic-only allowlist (see §8 Stage B).

**Claude side (CMS, as Claude org Owner, together with ADX).** Organization settings → Connectors → Add custom connector →
URL `https://<MCP_HOST>/mcp` → Advanced → **Use your own OAuth client**: client ID `<OAUTH_API_CLIENT_ID>`, client secret.
These settings cannot be edited later; the connector must be removed and re-added, so check the values together first.

## 7. Remaining tasks needing CMS / ADX

**Decisions confirmed:** access is for the marketing team only; the group is `ADX Marketing MCP Users`; CMS is the Claude
org Owner and adds the connector; ADX has nominated one marketing and one non-marketing user for testing.

1. **CMS:** create the app registration exactly as in §6 and send ADX the hostname and client ID (the client ID is not
   a secret; the client secret stays with CMS and goes straight into the Claude connector).
2. **CMS:** create `ADX Marketing MCP Users` with the marketing team as direct members and assign it to `MCP.Access`
   (or assign members individually if the tenant lacks Entra ID P1/P2).
3. **CMS:** confirm `<MCP_HOST>` is a verified domain usable as an Application ID URI.
4. **CMS:** set the §4 environment variables on the host; apply the web server and Cloudflare changes in §6; allow
   outbound access to `login.microsoftonline.com`; temporarily allow ADX's office IP for testing.
5. **CMS with ADX:** add the custom connector in Claude with the client ID and secret.
6. **Joint:** run the live pilot (§8). If it fails with symptom #506, choose and deploy an OAuth intermediary, then set
   `MCP_OAUTH_AUTHORIZATION_SERVER` (or its issuer and audience). That is outside this task's scope.
7. **Before go-live:** rotate the existing Graph app's client secret (its current value was shared by email), and remove
   the temporary office-IP allowance.

## 8. Testing once the app registration exists

These are **live integration tests**, separate from the automated suite.

**Stage A — server token validation with real Entra tokens (local).**
1. In `.env` set the §4 values with the local URL: `MCP_PUBLIC_URL=http://127.0.0.1:8080/mcp`. Leave
   `MCP_OAUTH_ALLOWED_CLIENT_IDS` blank for this stage.
2. Get a real user token without Claude. Either:
   - CMS temporarily adds the Azure CLI client (`04b07795-8ddb-461a-bbee-02f9e1bf7b46`) under **Expose an API →
     Authorized client applications** for `access_as_user`; or
   - use Claude Code, which can reach localhost and uses the `http://localhost` callback.

   With Azure CLI:
   ```powershell
   az login --tenant <ADX_TENANT_ID>
   $t = az account get-access-token --scope "https://<MCP_HOST>/mcp/access_as_user" --query accessToken -o tsv
   ```
3. `composer serve`, then:
   ```powershell
   $h = @{ Authorization = "Bearer $t"; Accept = 'application/json, text/event-stream'; 'MCP-Protocol-Version' = '2025-06-18' }
   Invoke-RestMethod http://127.0.0.1:8080/mcp -Method Post -Headers $h -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
   ```
   Expect the three tools. Check `var/log` for `OAuth token accepted`.
4. Negative checks:
   - Remove `MCP.Access` from a test user and get a new token → 403 `access_denied` (or `AADSTS50105` at sign-in
     when assignment is required).
   - Use a token for Microsoft Graph (`az account get-access-token --resource-type ms-graph`) → 401 audience mismatch.
5. **Remove the Azure CLI authorized-client entry** afterwards.

**Stage B — hosted endpoint (CMS host).** Run from the ADX office, whose IP CMS has temporarily allowed through Cloudflare.
```powershell
Invoke-RestMethod https://<MCP_HOST>/.well-known/oauth-protected-resource/mcp
try { Invoke-WebRequest https://<MCP_HOST>/mcp -Method Post -Body '{}' -ContentType 'application/json' } catch { $_.Exception.Response.Headers['WWW-Authenticate'] }
```
Expect `resource = https://<MCP_HOST>/mcp`, `authorization_servers = [issuer]`, and the advertised scopes, plus a 401
challenge carrying `resource_metadata` and `scope`.

**Stage C — Claude.**
1. CMS, as Claude org Owner, adds the connector (§6).
2. The nominated marketing tester (a group member) connects and completes Entra sign-in, then asks the Marketing Agent a question that needs Agent
   Knowledge. Expect tool calls to succeed and `OAuth token accepted` in the log.
3. The nominated non-marketing tester is blocked at Entra (`AADSTS50105`).
4. After about an hour, confirm the connector still works (refresh token via `offline_access`).
5. If Claude shows "Authorization with the MCP server failed", check the Entra sign-in logs:
   - **Sign-in succeeds but no token is redeemed for the client:** this matches issue #506, so move to the intermediary
     option.
   - **`AADSTS9010010`:** the Application ID URI does not exactly equal the MCP URL.
   - **`AADSTS65001`:** admin consent is missing.
   - **`AADSTS7000218`:** Claude was configured without the client secret.
