<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminAccountsCheckController extends Controller
{
    public function check(Request $request)
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response("Unauthorized\n", 403)->header('Content-Type', 'text/plain; charset=UTF-8');
            }

            $output = [];
            $output[] = "Checking admin accounts:";
            $output[] = "========================";

            $hasTable = DB::select("SHOW TABLES LIKE 'admins'");
            if (!empty($hasTable)) {
                $output[] = "✓ admins table exists";

                $admins = DB::table('admins')->select(['username', 'full_name'])->get();
                if ($admins->isNotEmpty()) {
                    $output[] = "";
                    $output[] = "Admin accounts found:";
                    foreach ($admins as $admin) {
                        $output[] = "Username: " . (string) ($admin->username ?? '') . " | Full Name: " . (string) ($admin->full_name ?? '');
                    }
                } else {
                    $output[] = "";
                    $output[] = "✗ No admin accounts found. Creating default admin...";
                    $this->ensureAdminsTable();
                    $this->ensureDefaultAdmin();
                    $output[] = "✓ Default admin created:";
                    $output[] = "  Username: admin";
                    $output[] = "  Password: admin123";
                    $output[] = "  (Please change this password after first login)";
                }
            } else {
                $output[] = "✗ admins table does not exist";
                $output[] = "Creating admins table...";
                $this->createAdminsTable();
                $this->ensureDefaultAdmin();
                $output[] = "✓ admins table created";
                $output[] = "✓ Default admin created:";
                $output[] = "  Username: admin";
                $output[] = "  Password: admin123";
                $output[] = "  (Please change this password after first login)";
            }

            return response(implode("\n", $output) . "\n", 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        } catch (Throwable $e) {
            return response("Failed to check admin accounts: " . $e->getMessage() . "\n", 500)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }
    }

    private function ensureAdminsTable(): void
    {
        $this->createAdminsTable();
    }

    private function createAdminsTable(): void
    {
        DB::statement(
            "
            CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
            "
        );
    }

    private function ensureDefaultAdmin(): void
    {
        $exists = DB::table('admins')->where('username', 'admin')->exists();
        if ($exists) {
            return;
        }

        DB::table('admins')->insert([
            'username' => 'admin',
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
            'full_name' => 'System Administrator',
        ]);
    }
}
