const axios = require("axios");
const crypto = require("crypto");

const LOGIN_URL =
  "https://semsplus.goodwe.com/web/sems/sems-user/api/v1/auth/cross-login";

function base64(value) {
  return Buffer.from(String(value), "utf8").toString("base64");
}

function sha256Hex(value) {
  return crypto.createHash("sha256").update(String(value)).digest("hex");
}

function md5Hex(value) {
  return crypto.createHash("md5").update(String(value)).digest("hex");
}

function encodePassword(password) {
  return base64(md5Hex(password));
}

function encodeSignature(tokenHeaderValue, timestamp = Date.now()) {
  let tokenObject = {};

  try {
    tokenObject = JSON.parse(tokenHeaderValue);
  } catch {
    tokenObject = {};
  }

  const uid = tokenObject.uid || "";
  const token = tokenObject.token || "";
  const signatureHash = sha256Hex(`${timestamp}@${uid}@${token}`);

  return base64(`${signatureHash}@${timestamp}`);
}

function randomHex(byteLength) {
  return crypto.randomBytes(byteLength).toString("hex");
}

function buildTraceparent() {
  return `00-${randomHex(16)}-${randomHex(8)}-01`;
}

function createTokenHeader(session = {}, language = "en") {
  return JSON.stringify({
    uid: session.uid || "",
    timestamp: session.timestamp || 0,
    token: session.token || "",
    client: session.client || "semsPlusWeb",
    version: session.version || "",
    api: session.api,
    region: session.region,
    uuid: session.uuid,
    language,
  });
}

function buildSemsHeaders({ session = {}, language = "en", uuid } = {}) {
  const tokenHeader = createTokenHeader(session, language);

  return {
    Accept: "application/json, text/plain, */*",
    "Content-Type": "application/json",
    "Access-Control-Allow-Origin": "*",
    Origin: "https://semsplus.goodwe.com",
    Referer: "https://semsplus.goodwe.com/",
    token: tokenHeader,
    "X-Signature": encodeSignature(tokenHeader),
    neutral: "0",
    currentLang: language,
    traceparent: buildTraceparent(),
    uuid: uuid || crypto.randomUUID(),
    os: "Windows 10.0 desktop",
    brand: "Node.js SEMS automation",
  };
}

async function login({
  account,
  password,
  language = "en",
  uuid = crypto.randomUUID(),
} = {}) {
  if (!account) {
    throw new Error("Missing SEMS account. Set SEMS_ACCOUNT.");
  }

  if (!password) {
    throw new Error("Missing SEMS password. Set SEMS_PASSWORD.");
  }

  const payload = {
    account,
    agreement: 1,
    isChinese: false,
    isLocal: false,
    pwd: encodePassword(password),
  };

  const response = await axios.post(LOGIN_URL, payload, {
    headers: buildSemsHeaders({ language, uuid }),
    timeout: 30000,
    validateStatus: () => true,
  });

  const body = response.data;

  if (response.status !== 200 || !body || body.code !== "00000") {
    const message = body?.description || body?.msg || response.statusText;
    throw new Error(`Login failed (${response.status}): ${message}`);
  }

  return {
    ...body.data,
    uuid: body.data?.uuid || uuid,
  };
}

function createSemsClient(session, { language = "en" } = {}) {
  if (!session?.api) {
    throw new Error("Session is missing api base URL.");
  }

  const client = axios.create({
    baseURL: session.api,
    timeout: 30000,
  });

  client.interceptors.request.use((config) => {
    config.headers = {
      ...buildSemsHeaders({
        session,
        language: session.language || language,
        uuid: session.uuid,
      }),
      ...(config.headers || {}),
    };

    return config;
  });

  return client;
}

async function main() {
  const account = process.env.SEMS_ACCOUNT;
  const password = process.env.SEMS_PASSWORD;
  const language = process.env.SEMS_LANGUAGE || "en";
  const uuid = process.env.SEMS_UUID || crypto.randomUUID();

  const session = await login({ account, password, language, uuid });
  const tokenHeader = createTokenHeader(session, session.language || language);



  console.log("Login successful.");

//   let encodedPWD = encodePassword(password);
// console.log(encodedPWD);

  console.log(
    JSON.stringify(
      {
        uid: session.uid,
        timestamp: session.timestamp,
        token: session.token,
        client: session.client,
        api: session.api,
        region: session.region,
        uuid: session.uuid,
        tokenHeader,
      },
      null,
      2,
    ),
  );
}

if (require.main === module) {
  main().catch((error) => {
    if (error.response?.data) {
      console.error(error.response.data);
    } else {
      console.error(error.message);
    }

    process.exit(1);
  });
}

module.exports = {
  buildSemsHeaders,
  createSemsClient,
  createTokenHeader,
  encodePassword,
  encodeSignature,
  login,
};
