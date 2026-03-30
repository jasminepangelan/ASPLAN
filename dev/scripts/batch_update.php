<!-- batch_update -->
<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug: Log all POST data
error_log("=== BATCH UPDATE DEBUG ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("Full POST data: " . print_r($_POST, true));
error_log("POST keys: " . implode(', ', array_keys($_POST)));

if (isset($_POST['batches'])) {
    error_log("Batches is SET");
    error_log("Batches type: " . gettype($_POST['batches']));
    if (is_array($_POST['batches'])) {
        error_log("Batches count: " . count($_POST['batches']));
        error_log("Batches values: " . implode(', ', $_POST['batches']));
    } else {
        error_log("Batches value: " . $_POST['batches']);
    }
} else {
    error_log("Batches is NOT SET");
}
error_log("=== END DEBUG ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $host = 'localhost';
    $db = 'e_checklist';
    $user = 'root';
    $pass = '';

    try {
        $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get adviser ID from username
        $stmt = $conn->prepare("SELECT id FROM adviser WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        $adviser = $stmt->fetch(PDO::FETCH_ASSOC);

        error_log("Adviser found: " . print_r($adviser, true));

        if ($adviser) {
            error_log("Adviser found - ID: " . $adviser['id']);
            
            if (isset($_POST['unassign']) && $_POST['unassign'] == '1') {
                // Unassign all batches for this adviser
                $del = $conn->prepare("DELETE FROM adviser_batch WHERE id = ?");
                $del->execute([$adviser['id']]);
                header("Location: admin/adviser_management.php?message=" . urlencode("All batches unassigned!"));
                exit();
            } elseif (isset($_POST['batches']) && is_array($_POST['batches']) && !empty($_POST['batches'])) {
                // Assign multiple batches
                // Debug: Log what we received
                error_log("Batches received: " . print_r($_POST['batches'], true));
                
                $batches = $_POST['batches'];
                
                // Debug: Check current state
                error_log("About to process " . count($batches) . " batches for adviser ID: " . $adviser['id']);
                
                // Check if adviser exists in adviser table
                $checkAdviser = $conn->prepare("SELECT id, username, full_name FROM adviser WHERE id = ?");
                $checkAdviser->execute([$adviser['id']]);
                $adviserCheck = $checkAdviser->fetch(PDO::FETCH_ASSOC);
                error_log("Adviser verification: " . print_r($adviserCheck, true));

                // Remove all previous assignments
                $del = $conn->prepare("DELETE FROM adviser_batch WHERE id = ?");
                $delResult = $del->execute([$adviser['id']]);
                error_log("Deleted previous assignments. Result: " . ($delResult ? 'success' : 'failed'));

                // Assign new batches
                $successCount = 0;
                $errorCount = 0;
                $errors = [];
                
                foreach ($batches as $batch) {
                    try {
                        // Convert batch to integer since the database expects int(11)
                        $batchInt = (int)$batch;
                        error_log("Processing batch: '$batch' -> converted to int: $batchInt");
                        
                        // Validate that we have a valid integer
                        if ($batchInt <= 0) {
                            throw new Exception("Invalid batch value: $batch");
                        }
                        
                        // First check if the batch exists in the batches table
                        $checkBatch = $conn->prepare("SELECT batch FROM batches WHERE batch = ?");
                        $checkBatch->execute([$batchInt]);
                        $batchExists = $checkBatch->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$batchExists) {
                            error_log("Batch '$batchInt' does not exist in batches table. Creating it...");
                            // Create the batch if it doesn't exist
                            $createBatch = $conn->prepare("INSERT INTO batches (batch) VALUES (?)");
                            $createResult = $createBatch->execute([$batchInt]);
                            error_log("Created batch result: " . ($createResult ? 'success' : 'failed'));
                        } else {
                            error_log("Batch '$batchInt' exists in batches table");
                        }
                        
                        // Check if this combination already exists
                        $checkExisting = $conn->prepare("SELECT * FROM adviser_batch WHERE batch = ? AND id = ?");
                        $checkExisting->execute([$batchInt, $adviser['id']]);
                        if ($checkExisting->rowCount() > 0) {
                            error_log("Combination already exists: batch $batchInt, adviser " . $adviser['id']);
                            $successCount++; // Count as success since it's already there
                            continue;
                        }
                        
                        // Now insert the adviser_batch relationship
                        $ins = $conn->prepare("INSERT INTO adviser_batch (batch, id) VALUES (?, ?)");
                        $result = $ins->execute([$batchInt, $adviser['id']]);
                        
                        if ($result) {
                            $successCount++;
                            error_log("Successfully inserted batch: $batchInt for adviser ID: " . $adviser['id']);
                        } else {
                            $errorCount++;
                            $errors[] = "Failed to insert batch $batch (unknown error)";
                            error_log("Failed to insert batch $batchInt - execute returned false");
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        $errors[] = "Batch $batch: " . $e->getMessage();
                        error_log("Exception for batch $batch: " . $e->getMessage());
                    } catch (PDOException $e) {
                        $errorCount++;
                        $errors[] = "Batch $batch: " . $e->getMessage();
                        error_log("Error inserting batch $batch for adviser " . $adviser['id'] . ": " . $e->getMessage());
                        error_log("Error code: " . $e->getCode());
                        
                        // If it's not a duplicate entry error, continue with other batches
                        if ($e->getCode() != '23000') {
                            // Log the full error for debugging
                            error_log("Full error details: " . print_r($e, true));
                        }
                    }
                }
                
                if ($successCount > 0) {
                    $message = "Successfully assigned $successCount batch(es) to adviser.";
                    if ($errorCount > 0) {
                        $message .= " ($errorCount failed)";
                        error_log("Some batches failed: " . implode('; ', $errors));
                    }
                } else {
                    if ($errorCount > 0) {
                        $message = "Failed to assign batches. Errors: " . implode('; ', array_slice($errors, 0, 3));
                        if (count($errors) > 3) {
                            $message .= " and " . (count($errors) - 3) . " more errors.";
                        }
                    } else {
                        $message = "No batches were processed. Please check your selection.";
                    }
                    error_log("All batch assignments failed. Errors: " . implode('; ', $errors));
                }
                
                header("Location: admin/adviser_management.php?message=" . urlencode($message));
                exit();
            } else {
                // No batches selected or batches is not an array, remove all assignments
                error_log("No valid batches received. POST batches: " . (isset($_POST['batches']) ? print_r($_POST['batches'], true) : 'not set'));
                $del = $conn->prepare("DELETE FROM adviser_batch WHERE id = ?");
                $del->execute([$adviser['id']]);
                header("Location: admin/adviser_management.php?message=" . urlencode("All batches removed for adviser (no batches selected)."));
                exit();
            }
        } else {
            header("Location: admin/adviser_management.php?error=" . urlencode("Adviser not found."));
            exit();
        }
    } catch (PDOException $e) {
        // Add detailed error logging for debugging
        error_log("Error occurred: " . $e->getMessage());
        header("Location: admin/adviser_management.php?error=" . urlencode("An error occurred while updating the batch."));
        exit();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_batch'])) {
    $host = 'localhost';
    $db = 'e_checklist';
    $user = 'root';
    $pass = '';

    try {
        $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Add new batch
        $newBatch = $_POST['new_batch'];
        $stmt = $conn->prepare("INSERT INTO batches (batch) VALUES (?)");
        $stmt->execute([$newBatch]);

        header("Location: admin/adviser_management.php?message=" . urlencode("New batch added successfully!"));
        exit();
    } catch (PDOException $e) {
        // Add detailed error logging for debugging
        error_log("Error occurred: " . $e->getMessage());
        header("Location: admin/adviser_management.php?error=" . urlencode("An error occurred while adding the new batch."));
        exit();
    }
} else {
    header("Location: admin/adviser_management.php?error=" . urlencode("Invalid request."));
    exit();
}
?>