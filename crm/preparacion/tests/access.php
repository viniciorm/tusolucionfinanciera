<?php
declare(strict_types=1);
require __DIR__.'/../src/LeadAccess.php';
use TSF\Security\LeadAccess;
$cases = [
 [true,'admin',1,null,true], [false,'admin',1,1,false],
 [true,'advisor',2,2,true], [true,'advisor',2,3,false],
 [true,'advisor',2,null,false], [true,'unknown',1,1,false],
];
foreach ($cases as [$active,$role,$id,$assigned,$expected]) {
 if (LeadAccess::allowed($active,$role,$id,$assigned) !== $expected) throw new RuntimeException('Access rule failed');
}
echo "OK: reglas básicas de aislamiento por asesor\n";
