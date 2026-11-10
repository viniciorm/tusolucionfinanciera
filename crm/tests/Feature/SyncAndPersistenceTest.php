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

    public function test_subsequent_syncs_strictly_preserve_commercial_statuses_and_notes(): void
    {
        $service = new GoogleSheetsReaderService();

        $dataset = [
            'Hoja 2' => [
                ['id', 'full_name', 'email', 'phone'],
                ['meta-201', 'Roberto Diaz', 'roberto@test.cl', '+56944444444'],
            ],
        ];

        // 1. Sincronizar lead inicial
        $service->syncDataset($dataset);
        $lead = Lead::where('meta_id', 'meta-201')->firstOrFail();

        // 2. Asesor cambia estado comercial y agrega notas de gestión
        $advisor = User::create([
            'email' => 'asesor@test.cl',
            'name' => 'Asesor Activo',
            'role' => 'advisor',
            'active' => true,
        ]);

        $lead->management->status = LeadManagement::STATUS_REUNION_AGENDADA;
        $lead->management->assigned_user_id = $advisor->id;
        $lead->management->save();

        Activity::create([
            'lead_id' => $lead->id,
            'actor_user_id' => $advisor->id,
            'type' => Activity::TYPE_NOTE,
            'note' => 'Cliente muy interesado en APV régimen B.',
        ]);

        FollowUp::create([
            'lead_id' => $lead->id,
            'assigned_user_id' => $advisor->id,
            'due_at' => now()->addDays(2),
            'note' => 'Reunión Zoom agendada.',
        ]);

        // 3. Ejecutar sincronización posterior desde Sheets
        $service->syncDataset($dataset);

        // 4. Verificar que el estado, notas y seguimientos comerciales NO fueron sobrescritos
        $lead->refresh();
        $this->assertEquals(LeadManagement::STATUS_REUNION_AGENDADA, $lead->management->status);
        $this->assertEquals($advisor->id, $lead->management->assigned_user_id);
        $this->assertCount(1, $lead->activities);
        $this->assertEquals('Cliente muy interesado en APV régimen B.', $lead->activities->first()->note);
        $this->assertCount(1, $lead->followUps);
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

        // Verificar la constante de alcance estricto
        $this->assertEquals(
            'https://www.googleapis.com/auth/spreadsheets.readonly',
            GoogleSheetsReaderService::READONLY_SCOPE
        );

        // Falsificar llamadas HTTP a Google Sheets API
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

        // Verificar que solo se ejecutó petición GET y ninguna llamada destructiva (POST, PUT, DELETE, PATCH)
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
