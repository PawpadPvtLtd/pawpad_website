import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const START_PORT = parseInt(process.env.PORT || "3000", 10);

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".mjs": "application/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".webp": "image/webp",
  ".ico": "image/x-icon",
  ".svg": "image/svg+xml",
  ".woff2": "font/woff2",
  ".woff": "font/woff",
  ".ttf": "font/ttf",
  ".webmanifest": "application/manifest+json",
  ".xml": "application/xml; charset=utf-8",
  ".txt": "text/plain; charset=utf-8",
  ".pdf": "application/pdf"
};

const SECURITY_HEADERS = {
  "X-Content-Type-Options": "nosniff",
  "X-Frame-Options": "SAMEORIGIN",
  "Referrer-Policy": "strict-origin-when-cross-origin"
};

const SENSITIVE_PATTERNS = [
  /(^|[/\\])\.[^/\\]/i,           // Hidden files (.git, .env, .DS_Store, etc.)
  /(^|[/\\])node_modules([/\\]|$)/i, // node_modules
  /(^|[/\\])package(-lock)?\.json$/i, // package.json, package-lock.json
  /(^|[/\\])scripts([/\\]|$)/i,   // Backend / build scripts
  /(^|[/\\])tests?([/\\]|$)/i,    // Test suites
  /(^|[/\\])playwright\.config/i, // Test runner configs
  /(^|[/\\])TODO\.md$/i,
  /(^|[/\\])api[/\\]config\.php$/i // Real API passwords (only ever on the server)
];

async function handleRequest(req, res) {
  try {
    const parsedUrl = new URL(req.url, `http://${req.headers.host || "localhost"}`);
    const rawPathname = decodeURIComponent(parsedUrl.pathname);

    // Handle Static Files with Path Traversal Protection
    if (req.method !== "GET" && req.method !== "HEAD") {
      res.writeHead(405, { "Content-Type": "text/plain; charset=utf-8", ...SECURITY_HEADERS });
      res.end("405 Method Not Allowed");
      return;
    }

    // Normalize path and eliminate any traversal sequences
    const safePath = path.normalize(rawPathname).replace(/^(\.\.[/\\])+/, "");
    let resolvedPath = path.resolve(ROOT, "." + safePath);

    // Strict boundary enforcement: resolved path must strictly start with ROOT
    if (!resolvedPath.startsWith(ROOT)) {
      res.writeHead(403, { "Content-Type": "text/plain; charset=utf-8", ...SECURITY_HEADERS });
      res.end("403 Forbidden");
      return;
    }

    // Block sensitive files and directories
    const relFromRoot = path.relative(ROOT, resolvedPath);
    const isSensitive = SENSITIVE_PATTERNS.some((pattern) => pattern.test(safePath) || pattern.test(relFromRoot));
    if (isSensitive) {
      res.writeHead(403, { "Content-Type": "text/plain; charset=utf-8", ...SECURITY_HEADERS });
      res.end("403 Forbidden");
      return;
    }

    if (fs.existsSync(resolvedPath) && fs.statSync(resolvedPath).isDirectory()) {
      resolvedPath = path.join(resolvedPath, "index.html");
    } else if (!fs.existsSync(resolvedPath) && fs.existsSync(`${resolvedPath}.html`)) {
      resolvedPath = `${resolvedPath}.html`;
    }

    if (!fs.existsSync(resolvedPath) || !fs.statSync(resolvedPath).isFile()) {
      res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8", ...SECURITY_HEADERS });
      res.end("404 Not Found");
      return;
    }

    const ext = path.extname(resolvedPath).toLowerCase();
    const contentType = MIME[ext] || "application/octet-stream";
    const cacheControl = "no-cache, must-revalidate";

    const stat = fs.statSync(resolvedPath);
    res.writeHead(200, {
      "Content-Type": contentType,
      "Content-Length": stat.size,
      "Cache-Control": cacheControl,
      "Access-Control-Allow-Origin": "*",
      ...SECURITY_HEADERS
    });

    if (req.method === "HEAD") {
      res.end();
      return;
    }

    fs.createReadStream(resolvedPath).pipe(res);
  } catch (err) {
    if (!res.headersSent) {
      res.writeHead(500, { "Content-Type": "text/plain; charset=utf-8", ...SECURITY_HEADERS });
      res.end("500 Internal Server Error");
    }
  }
}

function listenOnPort(port) {
  const server = http.createServer(handleRequest);
  server.once("error", (err) => {
    if (err.code === "EADDRINUSE" || err.code === "ENOBUFS") {
      console.log(`Port ${port} in use, trying ${port + 1}...`);
      listenOnPort(port + 1);
    } else {
      console.error(err);
    }
  });
  server.listen(port, () => {
    console.log(`Server running at http://localhost:${port}/`);
  });
}

listenOnPort(START_PORT);
