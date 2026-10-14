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
  const u = new URL(req.url, "http://x");
  if (u.pathname === "/v1/documents") return send(200, { id: `doc_${seen.filter((s) => s.url === "/v1/documents").length}` });
  if (u.pathname === "/v1/signature_requests") {
    createCalls++;
    // The second create gets a 503 first, to prove the retry keeps its idempotency key.
    if (createCalls === 2) return send(503, { error: "maintenance" });
    return send(201, { id: `req_${createCalls}` });
  }
  if (u.pathname === "/v1/signature_requests/missing") return send(404, { error: "not_found", hint: "check the id" });
  if (u.pathname === "/v1/signature_requests/req_1/cancel") return send(200, { already_cancelled: false, signature_request: { id: "req_1" } });
  if (u.pathname === "/v1/signature_requests/req_1/signed_documents") return send(200, { data: [{ position: 0 }], download_expires_at: null });
  if (u.pathname === "/v1/signature_requests/req_1/signed_documents/0") return send(200, "%PDF-signed", "application/pdf");
  if (u.pathname === "/v1/signature_requests/req_1") return send(200, { id: "req_1" });
  if (u.pathname === "/v1/balance") return send(200, { balance_ore: 12000 });
  if (u.pathname === "/v1/balance/transactions") {
    return u.searchParams.get("cursor") === "t2"
      ? send(200, { data: [{ id: "t3" }], next_cursor: null })
      : send(200, { data: [{ id: "t1" }, { id: "t2" }], next_cursor: "t2" });
  }
  if (u.pathname === "/v1/events") {
    return u.searchParams.get("cursor") === "e1"
      ? send(200, { data: [{ id: "e2" }], next_cursor: null })
      : send(200, { data: [{ id: "e1" }], next_cursor: "e1" });
  }
  send(500, { error: "unexpected", path: u.pathname });
});
await new Promise((r) => server.listen(0, r));
const port = server.address().port;
// Async on purpose: a synchronous child would block this process, and with it the mock server the
// child is waiting on.
const { stdout } = await promisify(execFile)("php", [new URL("./client_check.php", import.meta.url).pathname, `http://127.0.0.1:${port}/v1`]);
server.close();
const out = JSON.parse(stdout);

// Upload: multipart, a path and raw bytes both under the repeated field `files`, as the API reads it.
const up = seen.find((s) => s.url === "/v1/documents");
const form = await new Request("http://x", { method: "POST", headers: { "content-type": up.headers["content-type"] }, body: up.body }).formData();
assert.deepEqual(form.getAll("files").map((f) => f.name), ["esigner-a.pdf", "b.docx"]);
assert.equal(form.getAll("files")[1].type, "application/vnd.openxmlformats-officedocument.wordprocessingml.document");
assert.equal(form.get("ownerEmail"), "post@klubb.se");

// Create: bearer, JSON body; the retried one sent the caller's own key twice.
const creates = seen.filter((s) => s.url === "/v1/signature_requests");
assert.equal(creates[0].headers.authorization, "Bearer sk_test_abc");
assert.deepEqual(JSON.parse(creates[0].body).signingEids, ["se_bankid"]);
assert.deepEqual([creates[1].headers["idempotency-key"], creates[2].headers["idempotency-key"]], ["my-key-1", "my-key-1"]);
assert.equal(out.create, "req_1");
assert.equal(out.retried, "req_3");

// createWithFiles: upload carried ownerEmail, the create carried the new documentId and not ownerEmail.
const withFilesCreate = JSON.parse(creates[3].body);
assert.equal(withFilesCreate.documentId, "doc_2");
assert.equal(withFilesCreate.ownerEmail, undefined);
assert.equal(withFilesCreate.useSubscription, true);
assert.equal(out.withFiles, "req_4");

assert.deepEqual(out.error, [404, "not_found", "check the id"]);
assert.equal(out.cancel, false);
assert.equal(JSON.parse(seen.find((s) => s.url.endsWith("/cancel")).body).reason, "test");
assert.equal(out.signedList, 1);
assert.equal(out.pdf, "%PDF-signed");
assert.equal(out.balance, 12000);
assert.deepEqual(out.transactions, ["t1", "t2", "t3"]);
assert.deepEqual(out.events, ["e1", "e2"]);
assert.ok(seen.find((s) => s.url.startsWith("/v1/events")).url.includes("type=signer.signed"));
assert.equal(out.legacy, "req_1");
console.log("ok   client: every service, multipart upload, idempotent retry, pagination, errors, legacy Client");
