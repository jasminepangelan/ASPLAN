<?php

namespace Tests\Unit;

use App\Http\Controllers\LegacyCompat\ChecklistController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class ChecklistControllerTest extends TestCase
{
    public function test_bulk_approve_route_receives_program_view()
    {
        $controller = new class extends ChecklistController {
            public bool $receivedProgramView = false;

            public function saveBulk(Request $request): JsonResponse
            {
                $this->receivedProgramView = trim((string) $request->input('program_view', '')) === 'EXPECTED';
                return response()->json(['status' => 'ok', 'received_program_view' => $this->receivedProgramView]);
            }
        };

        $request = Request::create('/api/save-checklist', 'POST', [
            'bulk_approve' => true,
            'save_context' => 'staff',
            'student_id' => '12345',
            'program_view' => 'EXPECTED',
            'courses' => ['CSE 100'],
            'grades' => ['CSE 100' => '1.0'],
            'professors' => ['CSE 100' => 'Prof'],
        ]);

        $response = $controller->save($request);
        $data = $response->getData(true);

        $this->assertSame('ok', $data['status']);
        $this->assertTrue($controller->receivedProgramView, 'Bulk save did not receive the expected program_view value');
    }
}
