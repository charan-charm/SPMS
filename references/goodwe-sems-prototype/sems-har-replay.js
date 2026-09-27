const fs = require("fs/promises");
const path = require("path");

const { login } = require("./sems-login");
const { buildClient, semsRequest } = require("./sems-data");

function parseArgs(argv) {
  const args = { _: [] };

  for (let index = 0; index < argv.length; index += 1) {
    const item = argv[index];

    if (!item.startsWith("--")) {
      args._.push(item);
      continue;
    }

    const key = item.slice(2);
    const next = argv[index + 1];

    if (!next || next.startsWith("--")) {
      args[key] = true;
      continue;
    }

    args[key] = next;
    index += 1;
  }

  return args;
}

function parseMaybeJson(text) {
  if (!text) return undefined;

  const trimmed = text.trim();
  if (!trimmed) return undefined;

  if (trimmed.startsWith("{") || trimmed.startsWith("[")) {
    return JSON.parse(trimmed);
  }

  return trimmed;
}

function readPostData(request) {
  const postData = request.postData;
  if (!postData?.text) return undefined;

  const mimeType = postData.mimeType || "";

  if (mimeType.includes("application/x-www-form-urlencoded")) {
    return Object.fromEntries(new URLSearchParams(postData.text));
  }

  return parseMaybeJson(postData.text);
}

function isReplayableUrl(rawUrl) {
  try {
    const url = new URL(rawUrl);
    const isSemsHost =
      url.hostname.endsWith("semsportal.com") ||
      url.hostname.endsWith("goodwe.com");

    if (!isSemsHost) return false;
    if (url.pathname.endsWith("/version.json")) return true;

    return url.pathname.includes("/web/sems/") && url.pathname.includes("/api");
  } catch {
    return false;
  }
}

function isAuthUrl(rawUrl) {
  try {
    const pathname = new URL(rawUrl).pathname;
    return (
      pathname.includes("/auth/cross-login") ||
      pathname.includes("/auth/remove-token") ||
      pathname.includes("/logout")
    );
  } catch {
    return false;
  }
}

function makeSafeName(index, request) {
  const url = new URL(request.url);
  const endpointName = url.pathname
    .replace(/^\/web\/sems\//, "")
    .replace(/^\/sems\//, "")
    .replace(/\/api(\/v\d+)?\//, "_")
    .replace(/[^a-z0-9_.-]+/gi, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 120);

  return `${String(index + 1).padStart(3, "0")}_${request.method.toLowerCase()}_${endpointName}`;
}

function getEntries(har) {
  if (Array.isArray(har)) return har;
  return har?.log?.entries || [];
}

function extractRequests(har, args) {
  const includeAuth = Boolean(args["include-auth"]);
  const contains = args.contains ? String(args.contains).toLowerCase() : "";
  const onlyMethod = args.method ? String(args.method).toLowerCase() : "";
  const seen = new Set();

  return getEntries(har)
    .map((entry) => entry.request)
    .filter(Boolean)
    .filter((request) => request.method !== "OPTIONS")
    .filter((request) => isReplayableUrl(request.url))
    .filter((request) => includeAuth || !isAuthUrl(request.url))
    .filter((request) =>
      contains ? request.url.toLowerCase().includes(contains) : true,
    )
    .filter((request) =>
      onlyMethod ? request.method.toLowerCase() === onlyMethod : true,
    )
    .filter((request) => {
      const key = [
        request.method,
        request.url,
        request.postData?.text || "",
      ].join("\n");

      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
}

async function saveJson(outputDir, name, data) {
  await fs.mkdir(outputDir, { recursive: true });
  const filePath = path.join(outputDir, `${name}.json`);
  await fs.writeFile(filePath, `${JSON.stringify(data, null, 2)}\n`);
  return filePath;
}

function printUsage() {
  console.log(`Usage:
  npm run replay-har -- --har sems.har
  npm run replay-har -- --har sems.har --contains telemetry
  npm run replay-har -- --har sems.har --list

Before running:
  $env:SEMS_ACCOUNT="your-email"
  $env:SEMS_PASSWORD="your-password"

Notes:
  Export the HAR after opening the SEMS page/chart/table in your browser.
  The script uses fresh Node login headers and ignores captured cookies/tokens.
  Auth/logout endpoints are skipped unless you pass --include-auth.
`);
}

async function main() {
  const args = parseArgs(process.argv.slice(2));

  if (args.help || args.h || !args.har) {
    printUsage();
    return;
  }

  const harPath = path.resolve(args.har);
  const outputDir = args.out || "sems-output/har";
  const har = JSON.parse(await fs.readFile(harPath, "utf8"));
  let requests = extractRequests(har, args);

  if (args.max) {
    requests = requests.slice(0, Number(args.max));
  }

  if (args.list) {
    requests.forEach((request, index) => {
      console.log(`${index + 1}. ${request.method} ${request.url}`);
    });
    return;
  }

  if (!requests.length) {
    console.log("No replayable SEMS API requests found in the HAR.");
    return;
  }

  const session = await login({
    account: process.env.SEMS_ACCOUNT,
    password: process.env.SEMS_PASSWORD,
    language: process.env.SEMS_LANGUAGE || "en",
  });
  const client = buildClient(session, process.env.SEMS_API_BASE || session.api);

  console.log(`Logged in. Replaying ${requests.length} request(s).`);

  for (let index = 0; index < requests.length; index += 1) {
    const request = requests[index];
    const method = request.method.toLowerCase();
    const data = readPostData(request);
    const name = makeSafeName(index, request);

    try {
      const responseData = await semsRequest(client, method, request.url, data);
      const filePath = await saveJson(outputDir, name, responseData);
      console.log(`saved ${request.method} ${request.url} -> ${filePath}`);
    } catch (error) {
      const filePath = await saveJson(outputDir, `${name}.error`, {
        method: request.method,
        url: request.url,
        error: error.message,
      });
      console.log(`failed ${request.method} ${request.url} -> ${filePath}`);

      if (args["fail-fast"]) {
        throw error;
      }
    }
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error(error.message);
    process.exit(1);
  });
}
