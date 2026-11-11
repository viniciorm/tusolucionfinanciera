<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadManagement;
use App\Models\User;
use App\Services\AdvisorAssignmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LeadReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_resolves_pending_leads_when_advisor_is_enabled_in_mapping(): void
    {
        // 1. Lead importado previamente con asesor 'Pao', pendiente de conciliación y sin asignación
        $lead = Lead::create([
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'lead-pao-1',
            'name' => 'Cliente Pao',
            'email' => 'clientepao@test.cl',
            'source_assigned_to' => 'Pao',
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-pao-1'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead->id,
            'status' => 'Nuevo',
            'assigned_user_id' => null,
        ]);

        // 2. Se habilita formalmente a Pao como asesora activa
        $paoUser = User::create([
            'email' => 'pao@tusolucionfinanciera.cl',
            'name' => 'Paola Asesora',
            'role' => 'advisor',
            'active' => true,
        ]);

        // 3. Se activa el mapeo explícito
        config(['services.advisors.mappings' => [
            'pao' => 'pao@tusolucionfinanciera.cl',
        ]]);

        // 4. Ejecutar la reconciliación explícita
        $result = AdvisorAssignmentResolver::reconcilePendingLeads();

        $this->assertEquals(1, $result['reconciled']);
        $this->assertEquals(0, $result['skipped_manual']);
        $this->assertEquals(0, $result['unresolved']);

        $lead->refresh();
        $this->assertFalse($lead->reconciliation_pending);
        $this->assertEquals($paoUser->id, $lead->management->assigned_user_id);

        // Se registra nota de auditoría y actividad
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => Activity::TYPE_NOTE,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'leads.reconciled',
            'lead_id' => $lead->id,
        ]);
    }

    public function test_reconciliation_strictly_preserves_existing_manual_assignments(): void
    {
        $manualAdvisor = User::create([
            'email' => 'asesor_manual@tusolucionfinanciera.cl',
            'name' => 'Asesor Manual',
            'role' => 'advisor',
            'active' => true,
        ]);

        // Lead con origen 'Pao', pero asignado manualmente a otro asesor en el CRM
        $lead = Lead::create([
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'lead-pao-manual',
            'name' => 'Cliente Con Asignación Manual',
            'email' => 'manual@test.cl',
            'source_assigned_to' => 'Pao',
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-pao-manual'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead->id,
            'status' => 'En seguimiento',
            'assigned_user_id' => $manualAdvisor->id, // ASIGNADO MANUALMENTE
        ]);

        // Se habilita Pao
        $paoUser = User::create([
            'email' => 'pao@tusolucionfinanciera.cl',
            'name' => 'Paola Asesora',
            'role' => 'advisor',
            'active' => true,
        ]);
        config(['services.advisors.mappings' => [
            'pao' => 'pao@tusolucionfinanciera.cl',
        ]]);

        // Ejecutar reconciliación
        $result = AdvisorAssignmentResolver::reconcilePendingLeads();

        $this->assertEquals(0, $result['reconciled']);
        $this->assertEquals(1, $result['skipped_manual']);
        $this->assertEquals(0, $result['unresolved']);

        $lead->refresh();
        // La asignación manual previa JAMÁS debe ser sobrescrita
        $this->assertEquals($manualAdvisor->id, $lead->management->assigned_user_id);
        $this->assertNotEquals($paoUser->id, $lead->management->assigned_user_id);
        $this->assertFalse($lead->reconciliation_pending);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'leads.reconciliation_preserved_manual',
            'lead_id' => $lead->id,
        ]);
    }

    public function test_reconciliation_does_not_invent_equivalences_for_unmapped_or_ambiguous_advisors(): void
    {
        $lead1 = Lead::create([
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'lead-desconocido',
            'name' => 'Cliente Sin Mapeo',
            'email' => 'nomapeo@test.cl',
            'source_assigned_to' => 'NombreInventado123',
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-desconocido'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead1->id,
            'status' => 'Nuevo',
            'assigned_user_id' => null,
        ]);

        $lead2 = Lead::create([
            'source_sheet' => 'Hoja 2',
            'meta_id' => 'lead-vacio',
            'name' => 'Cliente Vacio',
            'email' => 'vacio@test.cl',
            'source_assigned_to' => null,
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-vacio'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead2->id,
            'status' => 'Nuevo',
            'assigned_user_id' => null,
        ]);

        $result = AdvisorAssignmentResolver::reconcilePendingLeads();

        $this->assertEquals(0, $result['reconciled']);
        $this->assertEquals(0, $result['skipped_manual']);
        $this->assertEquals(2, $result['unresolved']);

        $lead1->refresh();
        $this->assertTrue($lead1->reconciliation_pending);
        $this->assertNull($lead1->management->assigned_user_id);

        $lead2->refresh();
        $this->assertTrue($lead2->reconciliation_pending);
        $this->assertNull($lead2->management->assigned_user_id);
    }

    public function test_reconciliation_ignores_advisors_that_are_inactive_or_not_advisors(): void
    {
        // Cris existe pero está inactiva
        User::create([
            'email' => 'cris@tusolucionfinanciera.cl',
            'name' => 'Cristian Inactivo',
            'role' => 'advisor',
            'active' => false, // INACTIVO
        ]);

        config(['services.advisors.mappings' => [
            'cris' => 'cris@tusolucionfinanciera.cl',
        ]]);

        $lead = Lead::create([
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'lead-cris-inactivo',
            'name' => 'Cliente Cris Inactivo',
            'email' => 'crisinactivo@test.cl',
            'source_assigned_to' => 'Cris',
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-cris-inactivo'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead->id,
            'status' => 'Nuevo',
            'assigned_user_id' => null,
        ]);

        $result = AdvisorAssignmentResolver::reconcilePendingLeads();

        $this->assertEquals(0, $result['reconciled']);
        $this->assertEquals(1, $result['unresolved']);

        $lead->refresh();
        $this->assertTrue($lead->reconciliation_pending);
        $this->assertNull($lead->management->assigned_user_id);
    }

    public function test_artisan_command_reconciles_pending_leads(): void
    {
        $paoUser = User::create([
            'email' => 'pao@tusolucionfinanciera.cl',
            'name' => 'Paola Asesora',
            'role' => 'advisor',
            'active' => true,
        ]);
        config(['services.advisors.mappings' => [
            'pao' => 'pao@tusolucionfinanciera.cl',
        ]]);

        $lead = Lead::create([
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'lead-cmd-1',
            'name' => 'Cliente Cmd',
            'email' => 'cmd@test.cl',
            'source_assigned_to' => 'Pao',
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-cmd-1'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead->id,
            'status' => 'Nuevo',
            'assigned_user_id' => null,
        ]);

        $exitCode = Artisan::call('crm:reconcile-leads');
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Proceso de reconciliación finalizado', $output);

        $lead->refresh();
        $this->assertFalse($lead->reconciliation_pending);
        $this->assertEquals($paoUser->id, $lead->management->assigned_user_id);
    }

    public function test_admin_controller_reconcile_action_triggers_reconciliation(): void
    {
        $admin = User::create([
            'email' => 'admin@tusolucionfinanciera.cl',
            'name' => 'Admin TSF',
            'role' => 'admin',
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->post('/admin/reconcile-leads');

        $response->assertRedirect('/admin');
        $response->assertSessionHas('status');
        $this->assertStringContainsString('Reconciliación de prospectos completada', session('status'));
    }

    public function test_concurrent_manual_assignment_is_not_overwritten_by_reconciliation(): void
    {
        $manualAdvisor = User::create([
            'email' => 'asesor_concurrente@tusolucionfinanciera.cl',
            'name' => 'Asesor Manual Concurrente',
            'role' => 'advisor',
            'active' => true,
        ]);

        $paoUser = User::create([
            'email' => 'pao@tusolucionfinanciera.cl',
            'name' => 'Paola Asesora',
            'role' => 'advisor',
            'active' => true,
        ]);

        config(['services.advisors.mappings' => [
            'pao' => 'pao@tusolucionfinanciera.cl',
        ]]);

        $lead = Lead::create([
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'lead-race-condition',
            'name' => 'Cliente Carrera Concurrente',
            'email' => 'race@test.cl',
            'source_assigned_to' => 'Pao',
            'reconciliation_pending' => true,
            'original' => ['id' => 'lead-race-condition'],
        ]);
        LeadManagement::create([
            'lead_id' => $lead->id,
            'status' => 'Nuevo',
            'assigned_user_id' => null, // Inicialmente sin asignar
        ]);

        // Simular que justo después de la lectura preliminar, un admin asigna manualmente el lead en la BD
        $firstRead = true;
        Lead::retrieved(function ($model) use (&$firstRead, $manualAdvisor, $lead) {
            if ($firstRead && $model->id === $lead->id) {
                $firstRead = false;
                \Illuminate\Support\Facades\DB::table('lead_management')
                    ->where('lead_id', $lead->id)
                    ->update(['assigned_user_id' => $manualAdvisor->id]);
            }
        });

        $result = AdvisorAssignmentResolver::reconcilePendingLeads();

        $this->assertEquals(0, $result['reconciled'], 'No debió asignarse a Pao');
        $this->assertEquals(1, $result['skipped_manual'], 'Debió preservarse la asignación manual adquirida concurrentemente');

        $lead->refresh();
        $this->assertEquals($manualAdvisor->id, $lead->management->assigned_user_id);
        $this->assertNotEquals($paoUser->id, $lead->management->assigned_user_id);
        $this->assertFalse($lead->reconciliation_pending);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'leads.reconciliation_preserved_manual',
            'lead_id' => $lead->id,
        ]);
    }
}
