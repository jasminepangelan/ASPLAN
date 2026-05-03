<?php
/**
 * Repeatable benchmark for study plan generation.
 *
 * Examples:
 *   php dev/test/study_plan_benchmark.php
 *   php dev/test/study_plan_benchmark.php --limit=10 --repeat=3
 *   php dev/test/study_plan_benchmark.php --student=220100031 --repeat=5 --target-ms=1500
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

$sessionDir = __DIR__ . '/../tmp/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0777, true);
}
if (is_dir($sessionDir)) {
    @ini_set('session.save_path', $sessionDir);
}

require_once __DIR__ . '/../../config/database.php';

function benchmarkLine(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function parseBenchmarkArgs(array $argv): array
{
    $options = [
        'student' => null,
        'limit' => 5,
        'repeat' => 1,
        'target_ms' => 2500.0,
        'report' => null,
    ];

    foreach ($argv as $arg) {
        if (strpos($arg, '--student=') === 0) {
            $options['student'] = trim(substr($arg, strlen('--student=')));
            continue;
        }
        if (strpos($arg, '--limit=') === 0) {
            $limit = (int) trim(substr($arg, strlen('--limit=')));
            if ($limit > 0) {
                $options['limit'] = $limit;
            }
            continue;
        }
        if (strpos($arg, '--repeat=') === 0) {
            $repeat = (int) trim(substr($arg, strlen('--repeat=')));
            if ($repeat > 0) {
                $options['repeat'] = $repeat;
            }
            continue;
        }
        if (strpos($arg, '--target-ms=') === 0) {
            $target = (float) trim(substr($arg, strlen('--target-ms=')));
            if ($target > 0) {
                $options['target_ms'] = $target;
            }
            continue;
        }
        if (strpos($arg, '--report=') === 0) {
            $options['report'] = trim(substr($arg, strlen('--report=')));
        }
    }

    return $options;
}

function benchmarkPreflightDb(): array
{
    try {
        if (class_exists('mysqli')) {
            $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            if (!$mysqli->connect_error) {
                $mysqli->close();
                return ['ok' => true, 'message' => 'MySQLi connection successful.'];
            }
        }

        if (function_exists('createPdoFallbackConnection')) {
            $conn = createPdoFallbackConnection();
            if ($conn && method_exists($conn, 'close')) {
                $conn->close();
            }
            return ['ok' => true, 'message' => 'PDO fallback connection successful.'];
        }

        return ['ok' => false, 'message' => 'No usable database connection method is available.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function benchmarkFetchStudents($conn, array $options): array
{
    $students = [];

    if (!empty($options['student'])) {
        $stmt = $conn->prepare("SELECT student_number, program FROM student_info WHERE student_number = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare student lookup statement.');
        }
        $studentNumber = (string) $options['student'];
        $stmt->bind_param('s', $studentNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        $stmt->close();
        return $students;
    }

    $sql = "SELECT student_number, program FROM student_info ORDER BY student_number ASC LIMIT " . (int) $options['limit'];
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    return $students;
}

function benchmarkResetQueryCache(): void
{
    unset($_SESSION['_qopt_cache'], $_SESSION['_qopt_cache_times']);
}

function benchmarkCloseGeneratorConnection($generator): void
{
    static $reflection = null;

    if (!is_object($generator)) {
        return;
    }

    if ($reflection === null) {
        $reflection = new ReflectionClass($generator);
    }

    if (!$reflection->hasProperty('conn')) {
        return;
    }

    $property = $reflection->getProperty('conn');
    $property->setAccessible(true);
    $conn = $property->getValue($generator);
    if ($conn) {
        closeDBConnection($conn);
    }
}

function benchmarkFormatMs(float $value): string
{
    return number_format($value, 2) . ' ms';
}

function benchmarkFormatMb(float $value): string
{
    return number_format($value, 2) . ' MB';
}

function benchmarkStats(array $samples): array
{
    sort($samples, SORT_NUMERIC);
    $count = count($samples);
    $sum = array_sum($samples);
    $avg = $count > 0 ? ($sum / $count) : 0.0;
    $median = $count > 0
        ? ($count % 2 === 1 ? $samples[(int) floor($count / 2)] : (($samples[$count / 2] + $samples[($count / 2) - 1]) / 2))
        : 0.0;

    return [
        'count' => $count,
        'min' => $count > 0 ? min($samples) : 0.0,
        'max' => $count > 0 ? max($samples) : 0.0,
        'avg' => $avg,
        'median' => $median,
    ];
}

$options = parseBenchmarkArgs(array_slice($argv, 1));
$timestamp = date('Ymd_His');
$defaultReport = __DIR__ . '/reports/study_plan_benchmark_' . $timestamp . '.txt';
$reportPath = $options['report'] !== null && $options['report'] !== ''
    ? $options['report']
    : $defaultReport;

benchmarkLine('Study Plan Benchmark');
benchmarkLine('Generated: ' . date('Y-m-d H:i:s'));
benchmarkLine('Options: student=' . ($options['student'] ?? '-') . ', limit=' . $options['limit'] . ', repeat=' . $options['repeat'] . ', target_ms=' . $options['target_ms']);
benchmarkLine('');

$preflight = benchmarkPreflightDb();
if (empty($preflight['ok'])) {
    benchmarkLine('Database preflight: FAILED');
    benchmarkLine('Reason: ' . ($preflight['message'] ?? 'Unknown database error.'));
    benchmarkLine('');
    benchmarkLine('This does not prove the study plan is broken. It means this CLI environment cannot reach the configured database.');
    exit(2);
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../student/generate_study_plan.php';

$conn = getDBConnection();
$students = benchmarkFetchStudents($conn, $options);
closeDBConnection($conn);

if (empty($students)) {
    benchmarkLine('No students matched the benchmark selection.');
    exit(1);
}

$reportLines = [];
$reportLines[] = 'Study Plan Benchmark';
$reportLines[] = 'Generated: ' . date('Y-m-d H:i:s');
$reportLines[] = 'Student count: ' . count($students);
$reportLines[] = 'Repeat count: ' . $options['repeat'];
$reportLines[] = 'Target average: ' . benchmarkFormatMs((float) $options['target_ms']);
$reportLines[] = '';

$allElapsed = [];
$allMemory = [];
$errorCount = 0;

foreach ($students as $student) {
    $studentId = trim((string) ($student['student_number'] ?? ''));
    $program = (string) ($student['program'] ?? '');
    $elapsedSamples = [];
    $memorySamples = [];
    $remainingCourses = null;
    $planTerms = null;

    for ($run = 1; $run <= (int) $options['repeat']; $run++) {
        benchmarkResetQueryCache();
        $startedAt = hrtime(true);
        $memoryBefore = memory_get_usage(true);

        try {
            $generator = new StudyPlanGenerator($studentId, $program);
            $plan = $generator->generateOptimizedPlan();
            $stats = $generator->getCompletionStats();
            $generator->getPolicyGateStatus();
            $generator->getCompletedTerms();
            $generator->getAllCoursesGroupedByTerm();

            $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
            $memoryDeltaMb = (memory_get_usage(true) - $memoryBefore) / 1048576;

            $elapsedSamples[] = $elapsedMs;
            $memorySamples[] = $memoryDeltaMb;
            $allElapsed[] = $elapsedMs;
            $allMemory[] = $memoryDeltaMb;

            $remainingCourses = (int) ($stats['remaining_courses'] ?? 0);
            $planTerms = count($plan);

            benchmarkCloseGeneratorConnection($generator);
            unset($generator, $plan, $stats);
        } catch (Throwable $e) {
            $errorCount++;
            $reportLines[] = '[ERROR] ' . $studentId . ' run ' . $run . ': ' . $e->getMessage();
            break;
        }
    }

    if (empty($elapsedSamples)) {
        continue;
    }

    $elapsedSummary = benchmarkStats($elapsedSamples);
    $memorySummary = benchmarkStats($memorySamples);
    $status = $elapsedSummary['avg'] <= (float) $options['target_ms'] ? 'WITHIN TARGET' : 'ABOVE TARGET';

    $line = sprintf(
        '%s | terms=%d | remaining=%d | avg=%s | median=%s | max=%s | mem(avg)=%s | %s',
        $studentId,
        (int) $planTerms,
        (int) $remainingCourses,
        benchmarkFormatMs($elapsedSummary['avg']),
        benchmarkFormatMs($elapsedSummary['median']),
        benchmarkFormatMs($elapsedSummary['max']),
        benchmarkFormatMb($memorySummary['avg']),
        $status
    );

    benchmarkLine($line);
    $reportLines[] = $line;
}

$overallElapsed = benchmarkStats($allElapsed);
$overallMemory = benchmarkStats($allMemory);

benchmarkLine('');
benchmarkLine('Summary');
benchmarkLine('Students processed: ' . count($students));
benchmarkLine('Errors: ' . $errorCount);
benchmarkLine('Average generation time: ' . benchmarkFormatMs($overallElapsed['avg']));
benchmarkLine('Median generation time: ' . benchmarkFormatMs($overallElapsed['median']));
benchmarkLine('Max generation time: ' . benchmarkFormatMs($overallElapsed['max']));
benchmarkLine('Average memory delta: ' . benchmarkFormatMb($overallMemory['avg']));
benchmarkLine('Target threshold: ' . benchmarkFormatMs((float) $options['target_ms']));

$reportLines[] = '';
$reportLines[] = 'Summary';
$reportLines[] = 'Students processed: ' . count($students);
$reportLines[] = 'Errors: ' . $errorCount;
$reportLines[] = 'Average generation time: ' . benchmarkFormatMs($overallElapsed['avg']);
$reportLines[] = 'Median generation time: ' . benchmarkFormatMs($overallElapsed['median']);
$reportLines[] = 'Max generation time: ' . benchmarkFormatMs($overallElapsed['max']);
$reportLines[] = 'Average memory delta: ' . benchmarkFormatMb($overallMemory['avg']);
$reportLines[] = 'Target threshold: ' . benchmarkFormatMs((float) $options['target_ms']);

$reportDir = dirname($reportPath);
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0777, true);
}
@file_put_contents($reportPath, implode(PHP_EOL, $reportLines) . PHP_EOL);

benchmarkLine('Report: ' . $reportPath);

if ($errorCount > 0) {
    exit(1);
}

exit(0);
