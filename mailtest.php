<?php

$to      = 'ronald@compareweb.org';
$subject = 'Database aanpassingen controleren';
$message = "De aanpassingen: \n";
/*
Abonnementen toe te voegen: \n".print_r ($abonnementenToevoegen)."\n
Abonnementen te verwijderen: \n".print_r ($abonnementenVerwijderen)."\n
Abonnementen te vervangen: \n".print_r ($abonnementenVervangenVan)."\n Door:
".print_r ($abonnementenVervangenNaar)." \n Uitvoeren? KNOP";
*/
$headers = 'From: ronald@compareweb.org' . "\r\n" .
  'Reply-To: ronald@compareweb.org' . "\r\n" .
  'X-Mailer: PHP/' . phpversion();

mail($to, $subject, $message, $headers);
echo 'mail sent';
?>
