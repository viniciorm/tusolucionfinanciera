<?php
declare(strict_types=1);
require __DIR__.'/../src/SheetLeadNormalizer.php';
use TSF\Sync\SheetLeadNormalizer;
$n = new SheetLeadNormalizer();
$cases = [
 ['Hoja 2',['id','created_time','email','full name','phone','lead_status'],['i2','2026-11-01','A@EXAMPLE.TEST','Persona','+56 9','sent']],
 ['Hoja 3',['id','full_name','phone_number','assigned_to'],['i3','Persona','999','Pao']],
 ['Hoja 4',['created_time','correo_electrónico','nombre_completo','número_de_teléfono','id'],['fecha','a@example.test','Persona','999','i4']],
 ['Hoja 5',['f','nombre_completo','número_de_teléfono','correo_electrónico','assigned_to','id'],['','Persona','999','a@example.test','Pao','i5']],
];
foreach ($cases as [$sheet,$headers,$row]) {
 $r=$n->normalize($sheet,$headers,$row);
 if ($r['errors'] || $r['lead']['meta_id'] !== 'i'.substr($sheet,-1) || !$r['lead']['reconciliation_pending']) throw new RuntimeException('Failed '.$sheet);
}
$a=$n->normalize('Hoja 2',['id'],['same'])['lead']; $b=$n->normalize('Hoja 3',['id'],['same'])['lead'];
if ($a['source_key']===$b['source_key']) throw new RuntimeException('source collision');
if ($n->normalize('Hoja 2',['id','ID'],['1','2'])['errors']!==['ambiguous_headers']) throw new RuntimeException('ambiguous headers');
if ($n->normalize('Hoja 4',['id'],[''])['errors']!==['missing_id']) throw new RuntimeException('missing ID');
if ($n->normalize('Hoja 1',['id'],['x'])['errors']!==['unsupported_sheet']) throw new RuntimeException('unsupported sheet');
if ($n->normalize('Hoja 3',['id','email','correo'],['1','a','b'])['errors']!==['conflicting_email']) throw new RuntimeException('conflicting fields');
echo "OK: 4 esquemas, claves, colisiones, IDs y conflictos\n";
