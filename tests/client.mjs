// Runs tests/client_check.php against a recording mock of the API and checks what it sent.
// `node tests/client.mjs` — needs php on PATH.
import { createServer } from "node:http";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import assert from "node:assert/strict";

const seen = [];
let createCalls = 0;
const server = createServer(async (req, res) => {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const body = Buffer.concat(chunks);
  seen.push({ method: req.method, url: req.url, headers: req.headers, body });
  const send = (status, obj, type = "application/json") => {
    res.writeHead(status, { "Content-Type": type });
    res.end(type === "application/json" ? JSON.stringify(obj) : obj);
  };
  if (req.url === "/v1/documents") return send(200, { id: "doc_up" });
  if (req.url === "/v1/signature_requests") {
    createCalls++;
    // The second create gets a 503 first, to prove the retry keeps its idempotency key.
    if (createCalls === 2) return send(503, { error: "maintenance" });
    return send(201, { id: `req_${createCalls}` });
  }
  if (req.url === "/v1/signature_requests/missing") return send(404, { error: "not_found" });
  if (req.url.startsWith("/v1/signature_requests/req_1/signed_documents/0")) return send(200, "%PDF-signed", "application/pdf");
  if (req.url.startsWith("/v1/events")) return send(200, { data: [], next_cursor: null });
  send(500, { error: "unexpected" });
});
await new Promise((r) => server.listen(0, r));
const port = server.address().port;
// Async on purpose: a synchronous child would block this process, and with it the mock server the
// child is waiting on.
const { stdout } = await promisify(execFile)("php", [new URL("./client_check.php", import.meta.url).pathname, `http://127.0.0.1:${port}/v1`]);
const out = JSON.parse(stdout);
server.close();

// Upload: multipart, both files under the repeated field `files`, as the API reads it.
const up = seen.find((s) => s.url === "/v1/documents");
const form = await new Request("http://x", { method: "POST", headers: { "content-type": up.headers["content-type"] }, body: up.body }).formData();
assert.deepEqual(form.getAll("files").map((f) => f.name), ["esigner-a.pdf", "esigner-b.docx"]);
assert.equal(form.get("ownerEmail"), "post@klubb.se");
assert.equal(out.upload, "doc_up");

// Create: bearer, JSON body, an idempotency key; the retried one sent the SAME key twice.
const creates = seen.filter((s) => s.url === "/v1/signature_requests");
assert.equal(creates[0].headers.authorization, "Bearer sk_test_abc");
assert.deepEqual(JSON.parse(creates[0].body).signingEids, ["se_bankid"]);
assert.equal(creates.length, 3);
assert.equal(creates[1].headers["idempotency-key"], creates[2].headers["idempotency-key"]);
assert.notEqual(creates[0].headers["idempotency-key"], creates[1].headers["idempotency-key"]);
assert.equal(out.create, "req_1");
assert.equal(out.retried, "req_3");

assert.deepEqual(out.error, [404, "not_found"]);
assert.equal(out.pdf, "%PDF-signed");
assert.equal(out.events, null);
assert.ok(seen.find((s) => s.url.startsWith("/v1/events")).url.includes("type=signer.signed"));
console.log("ok   client: upload multipart, auth, idempotent retry, errors, download, query");
