const axios = require("axios");
const crypto = require("crypto");
const dns = require("dns");
const fs = require("fs/promises");
const https = require("https");
const path = require("path");

const { buildSemsHeaders, login } = require("./sems-login");

const SERVICE = {
  userV1: "/web/sems/sems-user/api/v1",
  plant: "/web/sems/sems-plant/api",
  report: "/web/sems/sems-report/api",
  reportDirect: "/sems/sems-report/api",
  alarm: "/web/sems/sems-alarm/api",
  remote: "/web/sems/sems-remote/api",
  remoteV1: "/web/sems/sems-remote/api/v1",
  portal: "/web/sems/semsportal/api",
};

const STATION_STATS_REPORT_HEADERS = [
  {
    field: "base",
    sortable: false,
    includeFields: [
      {
        field: "stationName",
        extraConfig: "",
        translateKey: "station_name",
        sortable: false,
        position: 10,
      },
      {
        field: "stationAddress",
        extraConfig: "",
        translateKey: "station_address",
        sortable: false,
        position: 20,
      },
      {
        field: "installedPower",
        extraConfig: "",
        unit: "kW",
        translateKey: "rated_power",
        sortable: false,
        position: 40,
      },
    ],
  },
  {
    field: "stats",
    sortable: false,
    includeFields: [
      {
        field: "proSystemTotalStats",
        extraConfig: "",
        unit: "kWh",
        translateKey: "power_generation",
        sortable: false,
        position: 110,
      },
      {
        field: "proCharStats",
        extraConfig: "",
        unit: "kWh",
        translateKey: "charge_quantity",
        sortable: false,
        position: 130,
      },
      {
        field: "proDischarStats",
        extraConfig: "",
        unit: "kWh",
        translateKey: "energy_discharged",
        sortable: false,
        position: 140,
      },
      {
        field: "proGridStats",
        extraConfig: "",
        unit: "kWh",
        translateKey: "grid_energy_1",
        sortable: false,
        position: 190,
      },
      {
        field: "proPurchaseStats",
        extraConfig: "",
        unit: "kWh",
        translateKey: "purchased_electricity",
        sortable: false,
        position: 200,
      },
    ],
  },
];

const TRANSIENT_ERROR_CODES = new Set([
  "EAI_AGAIN",
  "ENOTFOUND",
  "ECONNRESET",
  "ECONNREFUSED",
  "ETIMEDOUT",
  "ECONNABORTED",
]);

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function createGoodweFallbackAgent() {
  const fallbackIp = process.env.SEMSPLUS_IP || "47.90.91.45";

  return new https.Agent({
    lookup(hostname, options, callback) {
      if (hostname === "semsplus.goodwe.com") {
        callback(null, fallbackIp, 4);
        return;
      }

      dns.lookup(hostname, options, callback);
    },
  });
}

function shouldRetryRequest(error) {
  if (TRANSIENT_ERROR_CODES.has(error.code)) return true;
  if (error.response?.status >= 500) return true;

  return /^HTTP 5\d\d:/.test(error.message || "");
}

function shouldUseGoodweFallback(endpoint, error) {
  return (
    TRANSIENT_ERROR_CODES.has(error.code) &&
    String(endpoint).includes("https://semsplus.goodwe.com/")
  );
}

function parseArgs(argv) {
  const args = { _: [] };

  for (let index = 0; index < argv.length; index += 1) {
    const item = argv[index];

    if (!item.startsWith("--")) {
      args._.push(item);
      continue;
    }

    const rawKey = item.slice(2);
    const next = argv[index + 1];

    if (!next || next.startsWith("--")) {
      args[rawKey] = true;
      continue;
    }

    args[rawKey] = next;
    index += 1;
  }

  return args;
}

function camelArg(args, dashed, camel) {
  return args[dashed] ?? args[camel];
}

function parseJson(value, label) {
  if (!value) return undefined;

  try {
    return JSON.parse(value);
  } catch (error) {
    throw new Error(`Invalid JSON for ${label}: ${error.message}`);
  }
}

function compactObject(object) {
  return Object.fromEntries(
    Object.entries(object).filter(([, value]) => value !== undefined),
  );
}

function pad2(value) {
  return String(value).padStart(2, "0");
}

function formatLocalDateTime(date) {
  return [
    date.getFullYear(),
    pad2(date.getMonth() + 1),
    pad2(date.getDate()),
  ].join("-") + ` ${pad2(date.getHours())}:${pad2(date.getMinutes())}:${pad2(
    date.getSeconds(),
  )}`;
}

function createContext(args) {
  const stationId =
    camelArg(args, "station-id", "stationId") || process.env.SEMS_STATION_ID;
  const sn = args.sn || args.SN || process.env.SEMS_SN;
  const date =
    args.date || process.env.SEMS_DATE || new Date().toISOString().slice(0, 10);
  const startDate = camelArg(args, "start-date", "startDate");
  const endDate = camelArg(args, "end-date", "endDate");
  const longitude = args.longitude || process.env.SEMS_LONGITUDE;
  const latitude = args.latitude || process.env.SEMS_LATITUDE;
  const dataOverride = parseJson(args.data, "--data");

  return {
    stationId,
    sn,
    date,
    startDate,
    endDate,
    longitude,
    latitude,
    pageNo: Number(camelArg(args, "page-no", "pageNo") || 1),
    pageSize: Number(camelArg(args, "page-size", "pageSize") || 50),
    dataOverride,
  };
}

function stationPayload(context, extra = {}) {
  if (context.dataOverride) return context.dataOverride;

  return compactObject({
    stationId: context.stationId,
    date: context.date,
    startDate: context.startDate,
    endDate: context.endDate,
    ...extra,
  });
}

function devicePayload(context, extra = {}) {
  if (context.dataOverride) return context.dataOverride;

  return compactObject({
    stationId: context.stationId,
    sn: context.sn,
    date: context.date,
    startDate: context.startDate,
    endDate: context.endDate,
    ...extra,
  });
}

function deviceQuery(context, extra = {}) {
  if (context.dataOverride) return context.dataOverride;

  return compactObject({
    sn: context.sn,
    deviceType: "INVERTER",
    pwId: context.stationId,
    ...extra,
  });
}

function dayRange(context) {
  const date = context.date || new Date().toISOString().slice(0, 10);

  return {
    startDateTime: `${date} 00:00:00`,
    endDateTime: `${date} 23:59:59`,
  };
}

function productionPayload(context) {
  if (context.dataOverride) return context.dataOverride;

  const { startDateTime, endDateTime } = dayRange(context);

  return {
    stationId: context.stationId,
    items: ["profitProStats", "proSystemTotalStats"],
    dimension: "day",
    isReport: false,
    startTime: startDateTime,
    endTime: endDateTime,
  };
}

function weatherPayload(context) {
  if (context.dataOverride) return context.dataOverride;

  return compactObject({
    longitude: context.longitude,
    latitude: context.latitude,
    stationId: context.stationId,
  });
}

function stationStatisticPayload(context) {
  if (context.dataOverride) return context.dataOverride;

  const now = new Date();
  const start = context.startDate
    ? `${context.startDate} 00:00:00`
    : formatLocalDateTime(new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0));
  const end = context.endDate
    ? `${context.endDate} 23:59:59`
    : formatLocalDateTime(now);

  return {
    reportCategorize: "STATION",
    reportType: "STATISTIC",
    reportingPeriod: "month",
    stationIds: [context.stationId],
    startTime: start,
    endTime: end,
    dimension: "day",
    polymerize: true,
    reportIndex: {
      userTableHeaders: STATION_STATS_REPORT_HEADERS,
    },
  };
}

function pagedStationPayload(context, extra = {}) {
  if (context.dataOverride) return context.dataOverride;

  return stationPayload(context, {
    pageNo: context.pageNo,
    pageSize: context.pageSize,
    ...extra,
  });
}

function encodePathValue(value) {
  return encodeURIComponent(String(value));
}

const ENDPOINTS = {
  basicInfo: {
    method: "post",
    requires: ["stationId"],
    path: (context) =>
      `${SERVICE.plant}/portal/stations/basic/info?stationId=${encodePathValue(
        context.stationId,
      )}`,
    data: () => ({}),
  },
  production: {
    method: "post",
    requires: ["stationId"],
    path: `${SERVICE.plant}/stations/production`,
    data: productionPayload,
  },
  powerStatisticsAndPrediction: {
    method: "post",
    requires: ["stationId"],
    path: `${SERVICE.plant}/v1/hems/power/statisticsAndPreV2`,
    data: stationPayload,
  },
  flow: {
    method: "get",
    requires: ["stationId"],
    path: `${SERVICE.plant}/stations/flow`,
    data: stationPayload,
  },
  weatherInfo: {
    method: "get",
    requires: ["stationId"],
    path: `${SERVICE.plant}/stations/weather/info`,
    data: weatherPayload,
  },
  inverterList: {
    method: "post",
    requires: ["stationId"],
    path: `${SERVICE.report}/station/inverter/list`,
    data: (context) =>
      context.dataOverride || { stationIds: [context.stationId] },
  },
  telemetry: {
    method: "get",
    requires: ["stationId", "sn"],
    path: (context) =>
      `${SERVICE.plant}/equipments/${encodePathValue(context.sn)}/telemetry`,
    data: (context) => deviceQuery(context, { sn: undefined }),
  },
  telecounting: {
    method: "get",
    requires: ["stationId", "sn"],
    path: (context) =>
      `${SERVICE.plant}/equipments/${encodePathValue(context.sn)}/telecounting`,
    data: (context) => deviceQuery(context, { sn: undefined }),
  },
  relatedDevices: {
    method: "get",
    requires: ["stationId", "sn"],
    path: (context) =>
      `${SERVICE.plant}/equipments/${encodePathValue(context.sn)}/relatedDevices`,
    data: deviceQuery,
  },
  metricConfig: {
    method: "get",
    requires: ["stationId", "sn"],
    path: (context) =>
      `${SERVICE.plant}/equipments/${encodePathValue(context.sn)}/getMetricConfig`,
    data: (context) => deviceQuery(context, { module: "chart" }),
  },
  timeSeriesData: {
    method: "post",
    requires: ["stationId", "sn"],
    path: (context) =>
      `${SERVICE.plant}/portal/equipments/${encodePathValue(
        context.sn,
      )}/timeSeriesData`,
    data: (context) =>
      context.dataOverride ||
      devicePayload(context, {
        deviceType: "INVERTER",
        group: "operation_area",
        module: "chart",
        ...dayRange(context),
        timeGranularity: "1",
      }),
  },
  stationStatistic: {
    method: "post",
    requires: ["stationId"],
    path: `${SERVICE.reportDirect}/report/station/statistic`,
    data: stationStatisticPayload,
  },
  alarmPage: {
    method: "post",
    requires: ["stationId"],
    path: `${SERVICE.alarm}/v2/alarm/page`,
    data: pagedStationPayload,
  },
  alarmStatistics: {
    method: "post",
    requires: [],
    path: `${SERVICE.alarm}/alarm/statistics`,
    data: (context) =>
      context.dataOverride ||
      (context.sn ? { status: 0, sn: context.sn } : stationPayload(context)),
  },
  alarmLevels: {
    method: "post",
    path: `${SERVICE.alarm}/filter/template/alarm/level`,
    data: (context) => context.dataOverride || {},
  },
  deviceTypes: {
    method: "post",
    requires: ["stationId"],
    path: `${SERVICE.alarm}/filter/template/device/type`,
    data: (context) => context.dataOverride || { stationId: context.stationId },
  },
  userTableHeaders: {
    method: "get",
    path: `${SERVICE.plant}/user/settings/userTableHeaders`,
    data: (context) =>
      context.dataOverride || {
        resourceKey:
          process.env.SEMS_RESOURCE_KEY ||
          "resource_centralized_device_inverter",
      },
  },
  deviceFunctionTabMenus: {
    method: "post",
    requires: ["sn"],
    path: `${SERVICE.remote}/v2/address/remote/getDeviceFunctionTabMenus`,
    data: (context) =>
      context.dataOverride ||
      compactObject({
        sn: context.sn,
        module: "GENERAL_FUNCTIONS",
        menuId: process.env.SEMS_MENU_ID,
        batIndex: "",
        menuCode: 0,
      }),
  },
  cachedDeviceFunctionParameters: {
    method: "post",
    requires: ["sn"],
    path: `${SERVICE.remoteV1}/address/remote/get-cache-device-function-parameters`,
    data: (context) =>
      context.dataOverride || {
        sn: context.sn,
        addresses: ["40337", "40330", "40331", "40332"],
        addrFuncMap: {
          40330: "1991318013509906434",
          40331: "1991318013509906435",
          40332: "1991318013514100737",
          40337: "1990982146534699014",
        },
      },
  },
  version: {
    method: "get",
    path: "https://semsplus.goodwe.com/version.json",
    data: () => ({}),
  },
};

function getApiOrigin(apiBase) {
  return new URL(apiBase).origin;
}

function normalizeEndpoint(endpoint) {
  if (/^https?:\/\//i.test(endpoint)) {
    return endpoint;
  }

  if (!endpoint.startsWith("/")) {
    return `/${endpoint}`;
  }

  if (endpoint.startsWith("/web/sems/")) {
    return endpoint;
  }

  if (endpoint.startsWith("/sems-")) {
    return `/web/sems${endpoint}`;
  }

  return endpoint;
}

function buildClient(session, apiBase) {
  const baseURL = getApiOrigin(apiBase || session.api);

  const client = axios.create({
    baseURL,
    timeout: 30000,
    validateStatus: () => true,
  });

  client.interceptors.request.use((config) => {
    const language = session.language || process.env.SEMS_LANGUAGE || "en";

    config.headers = {
      ...buildSemsHeaders({
        session,
        language,
        uuid: session.uuid,
      }),
      ...(config.headers || {}),
    };

    return config;
  });

  return client;
}

function unwrapResponse(response) {
  const body = response.data;

  if (response.status < 200 || response.status >= 300) {
    throw new Error(
      `HTTP ${response.status}: ${JSON.stringify(body).slice(0, 500)}`,
    );
  }

  if (body && typeof body === "object" && "code" in body) {
    if (!["00000", "0", 0].includes(body.code)) {
      const message = body.description || body.msg || body.message || body.code;
      throw new Error(`SEMS ${body.code}: ${message}`);
    }

    return body.data ?? body;
  }

  return body;
}

async function semsRequest(client, method, endpoint, data) {
  const request = {
    method,
    url: normalizeEndpoint(endpoint),
  };

  if (["get", "delete"].includes(method.toLowerCase())) {
    request.params = data;
  } else {
    request.data = data;
  }

  const maxAttempts = Number(process.env.SEMS_RETRIES || 4);

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      const response = await client.request(request);
      return unwrapResponse(response);
    } catch (error) {
      if (
        shouldUseGoodweFallback(request.url, error) &&
        !request.httpsAgent
      ) {
        request.httpsAgent = createGoodweFallbackAgent();
      }

      if (attempt >= maxAttempts || !shouldRetryRequest(error)) {
        throw error;
      }

      await sleep(500 * attempt);
    }
  }

  throw new Error("Request failed after retries.");
}

function resolveEndpointDefinition(name, context) {
  const definition = ENDPOINTS[name];

  if (!definition) {
    throw new Error(`Unknown endpoint name: ${name}`);
  }

  const missing = (definition.requires || []).filter((field) => !context[field]);
  if (missing.length) {
    throw new Error(`${name} needs: ${missing.join(", ")}`);
  }

  const endpoint =
    typeof definition.path === "function"
      ? definition.path(context)
      : definition.path;
  const data = definition.data ? definition.data(context) : context.dataOverride;

  return {
    method: definition.method,
    endpoint,
    data,
  };
}

async function callNamedEndpoint(client, name, context) {
  if (
    name === "weatherInfo" &&
    context.stationId &&
    (!context.longitude || !context.latitude)
  ) {
    const basicInfo = await callNamedEndpoint(client, "basicInfo", context);
    context.longitude = context.longitude || basicInfo?.longitude;
    context.latitude = context.latitude || basicInfo?.latitude;
  }

  const request = resolveEndpointDefinition(name, context);
  return semsRequest(client, request.method, request.endpoint, request.data);
}

async function callRawEndpoint(client, args, context) {
  const method = String(args.method || "post").toLowerCase();
  const endpoint = args.endpoint || args.url || args._[0];

  if (!endpoint) {
    throw new Error("Missing --endpoint.");
  }

  return semsRequest(client, method, endpoint, context.dataOverride || {});
}

async function saveJson(outputDir, name, data) {
  await fs.mkdir(outputDir, { recursive: true });
  const safeName = name.replace(/[^a-z0-9_.-]+/gi, "_");
  const filePath = path.join(outputDir, `${safeName}.json`);
  await fs.writeFile(filePath, `${JSON.stringify(data, null, 2)}\n`);
  return filePath;
}

function printUsage() {
  console.log(`Usage:
  npm run data -- --all --station-id <stationId> --sn <inverterSN>
  npm run data -- --endpoint flow --station-id <stationId>
  npm run data -- --endpoint telemetry --sn <inverterSN>
  npm run data -- --endpoint /web/sems/sems-plant/api/stations/flow --method get --data "{\\"stationId\\":\\"...\\"}"

Env:
  SEMS_ACCOUNT      login email
  SEMS_PASSWORD     login password
  SEMS_STATION_ID   station/plant id used by station endpoints
  SEMS_SN           inverter serial number used by device endpoints
  SEMS_API_BASE     optional override, default comes from login response
`);
}

async function main() {
  const args = parseArgs(process.argv.slice(2));

  if (args.help || args.h) {
    printUsage();
    return;
  }

  const account = process.env.SEMS_ACCOUNT;
  const password = process.env.SEMS_PASSWORD;
  const language = process.env.SEMS_LANGUAGE || "en";
  const uuid = process.env.SEMS_UUID || crypto.randomUUID();
  const outputDir = args.out || process.env.SEMS_OUT || "sems-output";
  const context = createContext(args);
  const endpointName = args.endpoint || args.url || args._[0];

  if (!args.all && !endpointName) {
    printUsage();
    return;
  }

  if (!args.all && endpointName === "version") {
    const publicSession = {
      api: "https://hk-gateway.semsportal.com/web/sems",
      client: "semsPlusWeb",
      language,
      timestamp: 0,
      token: "",
      uid: "",
      uuid,
    };
    const client = buildClient(publicSession, publicSession.api);
    const data = await callNamedEndpoint(client, endpointName, context);
    const filePath = await saveJson(outputDir, endpointName, data);
    console.log(`saved ${endpointName}: ${filePath}`);
    return;
  }

  const session = await login({ account, password, language, uuid });
  const apiBase =
    process.env.SEMS_API_BASE ||
    session.api ||
    "https://hk-gateway.semsportal.com/web/sems";
  const client = buildClient(session, apiBase);

  console.log(`Logged in. API base: ${apiBase}`);

  if (args.all) {
    const names = Object.keys(ENDPOINTS);

    for (const name of names) {
      try {
        const data = await callNamedEndpoint(client, name, context);
        const filePath = await saveJson(outputDir, name, data);
        console.log(`saved ${name}: ${filePath}`);
      } catch (error) {
        console.log(`skipped ${name}: ${error.message}`);
      }
    }

    return;
  }

  const data = ENDPOINTS[endpointName]
    ? await callNamedEndpoint(client, endpointName, context)
    : await callRawEndpoint(client, args, context);

  const filePath = await saveJson(outputDir, endpointName, data);
  console.log(`saved ${endpointName}: ${filePath}`);
}

if (require.main === module) {
  main().catch((error) => {
    console.error(error.message);
    process.exit(1);
  });
}

module.exports = {
  ENDPOINTS,
  buildClient,
  callNamedEndpoint,
  semsRequest,
};
