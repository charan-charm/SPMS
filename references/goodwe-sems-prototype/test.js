const { login } = require("./sems-login");

async function main() {
  const session = await login({
    account: process.env.SEMS_ACCOUNT,
    password: process.env.SEMS_PASSWORD,
    language: process.env.SEMS_LANGUAGE || "en",
    uuid: process.env.SEMS_UUID,
  });

  console.log("Login successful.");
  console.log(session);
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});

