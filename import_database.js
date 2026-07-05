const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');

async function importDatabase() {
  const connection = await mysql.createConnection({
    host: 'thomas.proxy.rlwy.net',
    port: 45044,
    user: 'root',
    password: 'qMsZwbiIngMfINmKygVSbMIiqJfdoTst',
    database: 'railway',
    multipleStatements: true
  });

  console.log('Connected to Railway MySQL');

  const sqlFile = path.join(__dirname, 'backups', 'railway-production-2026-07-05.sql');
  
  if (!fs.existsSync(sqlFile)) {
    console.error(`SQL file not found: ${sqlFile}`);
    process.exit(1);
  }

  const sql = fs.readFileSync(sqlFile, 'utf8');
  console.log(`SQL file loaded. Size: ${sql.length} bytes`);
  console.log('Starting import...');

  try {
    await connection.query(sql);
    console.log('Import completed successfully!');
  } catch (error) {
    console.error('Error during import:', error.message);
    process.exit(1);
  }

  await connection.end();
}

importDatabase().catch(error => {
  console.error('Fatal error:', error);
  process.exit(1);
});
