<?php
declare(strict_types=1);
require __DIR__.'/../src/GoogleAccountBinding.php';
use TSF\Security\GoogleAccountBinding;
$cases = [
 [true,'admin@example.test',null,'ADMIN@example.test',true,'google-id-1','bind'],
 [true,'admin@example.test','google-id-1','admin@example.test',true,'google-id-1','allow'],
 [true,'admin@example.test','google-id-1','admin@example.test',true,'google-id-2','deny'],
 [true,'admin@example.test',null,'other@example.test',true,'google-id-1','deny'],
 [false,'admin@example.test',null,'admin@example.test',true,'google-id-1','deny'],
 [true,'admin@example.test',null,'admin@example.test',false,'google-id-1','deny'],
 [true,'admin@example.test',null,'admin@example.test',true,'','deny'],
 [true,'admin@example.test','','admin@example.test',true,'google-id-1','deny'],
];
foreach ($cases as [$active,$approved,$bound,$email,$verified,$subject,$expected]) {
 if (GoogleAccountBinding::decide($active,$approved,$bound,$email,$verified,$subject) !== $expected) {
    throw new RuntimeException('Google identity binding failed');
 }
}
echo "OK: vinculación por identidad verificada y cuenta previamente autorizada\n";
