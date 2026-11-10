<?php
declare(strict_types=1);
require __DIR__.'/../src/SheetLeadNormalizer.php';
require __DIR__.'/../src/SheetBatchValidator.php';
use TSF\Sync\SheetLeadNormalizer;
use TSF\Sync\SheetBatchValidator;
$v = new SheetBatchValidator(new SheetLeadNormalizer());
$rows = [['dup','A'],['safe','B'],['dup','C'],['','']];
$a = $v->validate('Hoja 2',['id','full name'],$rows);
$b = $v->validate('Hoja 2',['id','full name'],$rows);
if (count($a['valid'])!==1 || $a['valid'][0]['meta_id']!=='safe' || count($a['rejected'])!==2 || $a!==$b) throw new RuntimeException('Batch validation failed');
echo "OK: duplicados rechazados en ambas filas, filas vacías y resultado reproducible\n";
