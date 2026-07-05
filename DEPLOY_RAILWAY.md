Railway deployment steps for ASPLAN

1) Create Railway project and connect GitHub
- Sign into https://railway.app and create a new project.
- Choose "Deploy from GitHub", connect the `ASPLAN` repository and select the branch to deploy.

2) Add MySQL plugin
- In the Railway project, go to "Plugins" → Add Plugin → MySQL. This provisions a DB and exposes connection variables.

3) Map Railway DB vars to the app (if needed)
The repo's `config/database.php` already falls back to Railway's `MYSQL*` variables, so no change is required. If you prefer explicit `DB_*` env vars, set these in Railway's Environment variables (Service Variables):

- `DB_HOST` = value of `MYSQLHOST`
- `DB_PORT` = value of `MYSQLPORT`
- `DB_USER` = value of `MYSQLUSER`
- `DB_PASS` = value of `MYSQLPASSWORD`
- `DB_NAME` = value of `MYSQLDATABASE`

4) Build & Start
- The repository contains a `Dockerfile` which uses the built-in PHP server and respects the `PORT` env var. Railway will build the Dockerfile automatically. No special start command is necessary.

5) Import production data (optional)
- To import the included SQL backup, get the MySQL connection details from the Railway plugin (or use the plugin's web UI) and run locally:

```
mysql -h $MYSQLHOST -P $MYSQLPORT -u $MYSQLUSER -p$MYSQLPASSWORD $MYSQLDATABASE < backups/railway-production-2026-07-05.sql
```

6) Remove leaked credentials and rotate secrets
- The repository currently contains a `.env` file with production credentials. Remove it from git history and rotate the DB password immediately.

Commands to untrack the file (run locally):

```
git rm --cached .env
git commit -m "Remove .env from repo"
git push
```

If the secret was committed previously, consider rotating the DB password and rewriting history with `git filter-repo` or `git filter-branch`, or contact your admins.

7) Verify and monitor
- Open the Railway deployment logs while deploying to watch for build/runtime errors.
- Verify the site loads and that `getDBConnection()` succeeds. If you see DB errors, confirm env var mappings and that the MySQL plugin is ready.

Notes
- `config/database.php` already supports `DATABASE_URL`, `MYSQL*` and `DB_*` env vars. No code edits should be necessary for Railway.
- Keep `.env` out of the repo. Add secrets only via Railway environment variables.

If you want, I can:
- Remove `.env` from git and create a safe commit here.
- Add a short Railway deployment CI file or `railway.toml`.
- Import the SQL into the Railway DB for you (you'll need to rotate the password and provide access).
