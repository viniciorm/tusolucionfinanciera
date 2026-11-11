<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use TSF\Sync\SheetBatchValidator;
use TSF\Sync\SheetLeadNormalizer;

class SheetNormalizationAndValidationTest extends TestCase
{
    private SheetLeadNormalizer $normalizer;
    private SheetBatchValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new SheetLeadNormalizer();
        $this->validator = new SheetBatchValidator($this->normalizer);
    }

    public function test_maps_headers_correctly_across_all_four_supported_sheets(): void
    {
        // Hoja 2 (ej: sin assigned_to)
        $h2 = ['id', 'created_time', 'full_name', 'phone_number', 'email', 'campaign_name', 'form_name', 'lead_status'];
        $r2 = ['m-100', '2026-03-01 10:00:00', 'Juan Perez', '+56912345678', 'juan@test.cl', 'Camp APV', 'Form 1', 'enviado'];
        $res2 = $this->normalizer->normalize('Hoja 2', $h2, $r2);
        $this->assertEmpty($res2['errors']);
        $this->assertEquals('m-100', $res2['lead']['meta_id']);
        $this->assertEquals('juan@test.cl', $res2['lead']['email']);
        $this->assertEquals('Juan Perez', $res2['lead']['name']);

        // Hoja 3 (encabezados en español con tildes)
        $h3 = ['nombre_completo', 'correo_electrónico', 'número_de_teléfono', 'campaign_name', 'form_name', 'lead_status', 'assigned_to', 'id'];
        $r3 = ['Maria Soto', 'MARIA@test.cl', '+56987654321', 'Rentas', 'Form 2', 'contactado', 'Asesor Cris', 'm-200'];
        $res3 = $this->normalizer->normalize('Hoja 3', $h3, $r3);
        $this->assertEmpty($res3['errors']);
        $this->assertEquals('m-200', $res3['lead']['meta_id']);
        $this->assertEquals('maria@test.cl', $res3['lead']['email']); // minúsculas

        // Hoja 4
        $h4 = ['id', 'full_name', 'email', 'phone', 'created_time'];
        $r4 = ['m-300', 'Pedro Gomez', 'pedro@test.cl', '912345678', '2026-03-05'];
        $res4 = $this->normalizer->normalize('Hoja 4', $h4, $r4);
        $this->assertEmpty($res4['errors']);
        $this->assertEquals('m-300', $res4['lead']['meta_id']);

        // Hoja 5
        $h5 = ['id', 'nombre', 'correo', 'telefono', 'assigned_to'];
        $r5 = ['m-400', 'Claudia Reyes', 'claudia@test.cl', '987654321', 'Pao'];
        $res5 = $this->normalizer->normalize('Hoja 5', $h5, $r5);
        $this->assertEmpty($res5['errors']);
        $this->assertEquals('m-400', $res5['lead']['meta_id']);
        $this->assertEquals('Pao', $res5['lead']['source_assigned_to']);
    }

    public function test_rejects_unsupported_sheet(): void
    {
        $res = $this->normalizer->normalize('Hoja 1', ['id'], ['m-999']);
        $this->assertContains('unsupported_sheet', $res['errors']);
        $this->assertNull($res['lead']);
    }

    public function test_detects_missing_id_header(): void
    {
        $headers = ['full_name', 'email', 'phone'];
        $row = ['Sin ID', 'test@test.cl', '123456'];
        $res = $this->normalizer->normalize('Hoja 2', $headers, $row);
        $this->assertContains('missing_id_header', $res['errors']);
    }

    public function test_detects_ambiguous_duplicate_headers(): void
    {
        $headers = ['id', 'email', 'correo_electrónico'];
        $row = ['m-500', 'test1@test.cl', 'test2@test.cl'];
        $res = $this->normalizer->normalize('Hoja 2', $headers, $row);
        // Debe detectar conflicto o encabezados ambiguos
        $this->assertTrue(in_array('ambiguous_headers', $res['errors']) || in_array('conflicting_email', $res['errors']));
    }

    public function test_detects_absent_id_value_in_row(): void
    {
        $headers = ['id', 'full_name', 'email'];
        $row = ['', 'Nombre sin ID', 'test@test.cl'];
        $res = $this->normalizer->normalize('Hoja 2', $headers, $row);
        $this->assertContains('missing_id', $res['errors']);
    }

    public function test_batch_validator_rejects_duplicate_meta_ids_on_both_rows(): void
    {
        $headers = ['id', 'full_name', 'email'];
        $rows = [
            ['m-duplicate-1', 'Registro Uno', 'uno@test.cl'],
            ['m-unique-2', 'Registro Dos', 'dos@test.cl'],
            ['m-duplicate-1', 'Registro Tres', 'tres@test.cl'], // ID duplicado
        ];

        $res = $this->validator->validate('Hoja 2', $headers, $rows);

        // Ninguna de las filas con ID duplicado debe ser aceptada silenciosamente
        $this->assertCount(1, $res['valid']);
        $this->assertEquals('m-unique-2', $res['valid'][0]['meta_id']);

        // Ambas filas duplicadas deben estar en rejected
        $this->assertCount(2, $res['rejected']);
        $rejectedRows = array_column($res['rejected'], 'row');
        $this->assertContains(2, $rejectedRows); // fila 2
        $this->assertContains(4, $rejectedRows); // fila 4
    }
}
