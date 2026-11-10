<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $advisor1;
    private User $advisor2;
    private Lead $lead1;
    private Lead $lead2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'email' => 'admin@ejemplo.test',
            'name' => 'Admin Test',
            'role' => 'admin',
            'active' => true,
            'google_subject' => 'sub-admin',
        ]);

        $this->advisor1 = User::create([
            'email' => 'asesor1@ejemplo.test',
            'name' => 'Asesor 1',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => 'sub-adv1',
        ]);

        $this->advisor2 = User::create([
            'email' => 'asesor2@ejemplo.test',
            'name' => 'Asesor 2',
            'role' => 'advisor',
            'active' => true,
            'google_subject' => 'sub-adv2',
        ]);

        $this->lead1 = Lead::create([
            'source_key' => 'key1',
            'source_sheet' => 'Hoja 2',
            'meta_id' => 'meta-101',
            'name' => 'Lead de Asesor 1',
            'email' => 'lead1@ejemplo.test',
            'phone' => '+56911111111',
            'original' => ['id' => 'meta-101'],
        ]);
        LeadManagement::create([
            'lead_id' => $this->lead1->id,
            'assigned_user_id' => $this->advisor1->id,
            'status' => 'Nuevo',
        ]);

        $this->lead2 = Lead::create([
            'source_key' => 'key2',
            'source_sheet' => 'Hoja 3',
            'meta_id' => 'meta-202',
            'name' => 'Lead de Asesor 2',
            'email' => 'lead2@ejemplo.test',
            'phone' => '+56922222222',
            'original' => ['id' => 'meta-202'],
        ]);
        LeadManagement::create([
            'lead_id' => $this->lead2->id,
            'assigned_user_id' => $this->advisor2->id,
            'status' => 'Contactado',
        ]);
    }

    public function test_advisor_only_sees_assigned_leads_in_listing(): void
    {
        $response = $this->actingAs($this->advisor1)->get('/leads');
        $response->assertOk();
        $response->assertSee('Lead de Asesor 1');
        $response->assertDontSee('Lead de Asesor 2');
    }

    public function test_advisor_can_view_their_own_lead_detail(): void
    {
        $response = $this->actingAs($this->advisor1)->get('/leads/' . $this->lead1->id);
        $response->assertOk();
        $response->assertSee('Lead de Asesor 1');
    }

    public function test_advisor_is_forbidden_from_viewing_another_advisors_lead(): void
    {
        $response = $this->actingAs($this->advisor1)->get('/leads/' . $this->lead2->id);
        $response->assertForbidden();
    }

    public function test_advisor_is_forbidden_from_updating_another_advisors_lead_status(): void
    {
        $response = $this->actingAs($this->advisor1)->post('/leads/' . $this->lead2->id . '/status', [
            'status' => 'En seguimiento',
        ]);
        $response->assertForbidden();

        $this->assertDatabaseHas('lead_management', [
            'lead_id' => $this->lead2->id,
            'status' => 'Contactado', // No modificado
        ]);
    }

    public function test_advisor_is_forbidden_from_adding_activity_to_another_advisors_lead(): void
    {
        $response = $this->actingAs($this->advisor1)->post('/leads/' . $this->lead2->id . '/activities', [
            'type' => 'call',
            'note' => 'Intento no autorizado de registrar llamada',
        ]);
        $response->assertForbidden();

        $this->assertDatabaseMissing('activities', [
            'lead_id' => $this->lead2->id,
        ]);
    }

    public function test_advisor_is_forbidden_from_accessing_admin_area(): void
    {
        $response = $this->actingAs($this->advisor1)->get('/admin');
        $response->assertForbidden();
    }

    public function test_admin_can_view_all_leads_and_fichas(): void
    {
        $response = $this->actingAs($this->admin)->get('/leads');
        $response->assertOk();
        $response->assertSee('Lead de Asesor 1');
        $response->assertSee('Lead de Asesor 2');

        $response1 = $this->actingAs($this->admin)->get('/leads/' . $this->lead1->id);
        $response1->assertOk();

        $response2 = $this->actingAs($this->admin)->get('/leads/' . $this->lead2->id);
        $response2->assertOk();
    }

    public function test_admin_can_reassign_leads_between_advisors(): void
    {
        $response = $this->actingAs($this->admin)->post('/leads/' . $this->lead1->id . '/assign', [
            'assigned_user_id' => $this->advisor2->id,
        ]);
        $response->assertRedirect('/leads/' . $this->lead1->id);

        $this->assertDatabaseHas('lead_management', [
            'lead_id' => $this->lead1->id,
            'assigned_user_id' => $this->advisor2->id,
        ]);
    }
}
