<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadManagement;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\GoogleSheetsReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncAndPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_dataset_is_idempotent_and_prevents_duplicates(): void
    {
        $service = new GoogleSheetsReaderService();

        $dataset = [
            'Hoja 2' => [
                ['id', 'full_name', 'email', 'phone', 'campaign_name', 'created_time'],
                ['meta-101', 'Juan Perez', 'juan@test.cl', '+56911111111', 'APV 2026', '2026-03-01 10:00:00'],
                ['meta-102', 'Ana Rojas', 'ana@test.cl', '+56922222222', 'Rentas', '2026-03-01 11:00:00'],
            ],
            'Hoja 3' => [
                ['id', 'nombre_completo', 'correo_electrónico', 'número_de_teléfono'],
                ['meta-301', 'Carlos Silva', 'carlos@test.cl', '+56933333333'],
            ],
        ];

        // Primera sincronización
        $run1 = $service->syncDataset($dataset);
        $this->assertEquals(SyncRun::STATUS_COMPLETED, $run1->status);
        $this->assertEquals(3, $run1->rows_saved);
        $this->assertEquals(3, Lead::count());
        $this->assertEquals(3, LeadManagement::count());

        // Segunda sincronización con los mismos datos
        $run2 = $service->syncDataset($dataset);
        $this->assertEquals(SyncRun::STATUS_COMPLETED, $run2->status);

        // No debe crear leads duplicados
        $this->assertEquals(3, Lead::count());
        $this->assertEquals(3, LeadManagement::count());
    }

    public function test_sync_resolves_only_explicitly_mapped_advisors_and_leaves_unmapped_as_reconciliation_pending(): void
    {
        $service = new GoogleSheetsReaderService();

        $dataset = [
            'Hoja 2' => [
                ['id', 'full_name', 'email', 'phone'],
                ['meta-h2-1', 'Lead Sin Asignar', 'sin@test.cl', '+56911111111'],
            ],
            'Hoja 5' => [
                ['id', 'nombre', 'correo', 'telefono', 'assigned_to'],
                ['meta-h5-1', 'Lead de Pao', 'pao.lead@test.cl', '+56955555555', 'Pao'],
            ],
            'Hoja 3' => [
                ['id', 'nombre_completo', 'correo_electrónico', 'assigned_to'],
                ['meta-h3-1', 'Lead de Cris', 'cris.lead@test.cl', 'Cris'],
            ],
        ];

        $service->syncDataset($dataset);

        // 1. Hoja 2: sin assigned_to de origen
        $leadH2 = Lead::where('meta_id', 'meta-h2-1')->firstOrFail();
        $this->assertNull($leadH2->source_assigned_to);
        $this->assertTrue($leadH2->reconciliation_pending);
        $this->assertNull($leadH2->management->assigned_user_id);

        // 2. Hoja 5: assigned_to = 'Pao' (no habilitada todavía)
        $leadH5 = Lead::where('meta_id', 'meta-h5-1')->firstOrFail();
        $this->assertEquals('Pao', $leadH5->source_assigned_to); // Conservado como dato original
        $this->assertTrue($leadH5->reconciliation_pending);     // Pendiente de conciliación
        $this->assertNull($leadH5->management->assigned_user_id); // No inventa asignación

        // 3. Hoja 3: assigned_to = 'Cris' (no habilitado todavía)
        $leadH3 = Lead::where('meta_id', 'meta-h3-1')->firstOrFail();
        $this->assertEquals('Cris', $leadH3->source_assigned_to);
        $this->assertTrue($leadH3->reconciliation_pending);
        $this->assertNull($leadH3->management->assigned_user_id);
    }

    public function test_subsequent_sync_updates_changed_source_attributes_without_overwriting_crm_status(): void
    {
        $service = new GoogleSheetsReaderService();

        // 1. Sincronización inicial
        $datasetInitial = [
            'Hoja 2' => [
                ['id', 'full_name', 'email', 'phone', 'campaign_name', 'lead_status'],
                ['meta-update-1', 'Nombre Antiguo', 'antiguo@test.cl', '+56911111111', 'Camp A', 'pendiente'],
            ],
        ];
        $service->syncDataset($datasetInitial);

        $lead = Lead::where('meta_id', 'meta-update-1')->firstOrFail();

        // 2. Asesor avanza gestión en el CRM
        $advisor = User::create([
            'email' => 'asesor_interno@test.cl',
            'name' => 'Asesor Interno',
            'role' => 'advisor',
            'active' => true,
        ]);

        $lead->management->status = LeadManagement::STATUS_EN_SEGUIMIENTO;
        $lead->management->assigned_user_id = $advisor->id;
        $lead->management->save();

        Activity::create([
            'lead_id' => $lead->id,
            'actor_user_id' => $advisor->id,
            'type' => Activity::TYPE_NOTE,
            'note' => 'Nota crítica de seguimiento comercial que no debe perderse.',
        ]);

        // 3. La planilla de Google Sheets cambia datos de contacto y estado de notificación
        $datasetUpdated = [
            'Hoja 2' => [
                ['id', 'full_name', 'email', 'phone', 'campaign_name', 'lead_status'],
                ['meta-update-1', 'Nombre Corregido', 'nuevo@test.cl', '+56999999999', 'Camp APV 2026', 'notificado'],
            ],
        ];
        $service->syncDataset($datasetUpdated);

        // 4. Verificación de actualización de datos de origen
        $lead->refresh();
        $this->assertEquals('Nombre Corregido', $lead->name);
        $this->assertEquals('nuevo@test.cl', $lead->email);
        $this->assertEquals('+56999999999', $lead->phone);
        $this->assertEquals('Camp APV 2026', $lead->campaign);
        $this->assertEquals('notificado', $lead->notification_status);

        // 5. Verificación de PRESERVACIÓN ESTRICTA de la gestión comercial
        $this->assertEquals(LeadManagement::STATUS_EN_SEGUIMIENTO, $lead->management->status);
        $this->assertEquals($advisor->id, $lead->management->assigned_user_id);
        $this->assertCount(1, $lead->activities);
        $this->assertEquals('Nota crítica de seguimiento comercial que no debe perderse.', $lead->activities->first()->note);
    }

    public function test_persists_notes_status_changes_and_follow_ups(): void
    {
        $user = User::create([
            'email' => 'asesor@test.cl',
            'name' => 'Asesor',
            'role' => 'advisor',
            'active' => true,
        ]);

        $lead = Lead::create([
            'source_key' => 'key-test-p',
            'source_sheet' => 'Hoja 2',
            'meta_id' => 'meta-p-1',
            'name' => 'Prospecto Test',
            'original' => ['id' => 'meta-p-1'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead->id,
            'assigned_user_id' => $user->id,
            'status' => 'Nuevo',
        ]);

        // 1. Cambiar estado comercial
        $this->actingAs($user)->post("/leads/{$lead->id}/status", [
            'status' => 'En seguimiento',
        ])->assertRedirect();
        $this->assertDatabaseHas('lead_management', [
            'lead_id' => $lead->id,
            'status' => 'En seguimiento',
        ]);

        // 2. Registrar nota de gestión
        $this->actingAs($user)->post("/leads/{$lead->id}/activities", [
            'type' => 'call',
            'note' => 'Primera llamada realizada sin respuesta.',
        ])->assertRedirect();
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'call',
            'note' => 'Primera llamada realizada sin respuesta.',
        ]);

        // 3. Agendar seguimiento
        $dueDate = now()->addDay()->format('Y-m-d H:i');
        $this->actingAs($user)->post("/leads/{$lead->id}/follow-ups", [
            'due_at' => $dueDate,
            'note' => 'Volver a llamar mañana por la tarde',
        ])->assertRedirect();
        $this->assertDatabaseHas('follow_ups', [
            'lead_id' => $lead->id,
            'note' => 'Volver a llamar mañana por la tarde',
        ]);
    }

    public function test_google_sheets_client_only_makes_read_calls_and_uses_readonly_scope(): void
    {
        $service = new GoogleSheetsReaderService();

        $this->assertEquals(
            'https://www.googleapis.com/auth/spreadsheets.readonly',
            GoogleSheetsReaderService::READONLY_SCOPE
        );

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'values' => [
                    ['id', 'full_name'],
                    ['meta-test', 'Nombre Test'],
                ],
            ], 200),
        ]);

        $rows = $service->fetchSheetValues('dummy-token', 'dummy-spreadsheet-id', 'Hoja 2');
        $this->assertCount(2, $rows);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'https://sheets.googleapis.com');
        });

        Http::assertNotSent(function ($request) {
            return in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)
                && str_contains($request->url(), 'https://sheets.googleapis.com');
        });
    }
}
