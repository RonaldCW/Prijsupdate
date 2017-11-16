<?php
// Datafeed voor Negen providers ophalen en opslaan gelukt.
// TODO: Mail sturen alvorens nieuwe abonnementen te uploaden, Mail sturen werkt pas als het live staat
// Tele2 heeft nog geen feed

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Lets the browser know it's JSON (puur voor uitlezen)
header( "Content-Type: application/json" );

include 'configuration.php';
$i = 0;
$stmt = $conn->prepare("SELECT * FROM providers");
$stmt->execute();
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($providers as $provider) {
	$provider = $providers[$i]['provider'];
	$provider_id = $providers[$i]['provider_id'];
	$feedurl = $providers[$i]['feedurl'];

	//Ophalen feed
	$datafeed_data = file_get_contents($feedurl);
	$newfile = 'datafeed/'.$provider.'/datafeed-'.$provider.'.zip';
	if (!copy($feedurl, $newfile)) {
		echo "failed to copy $file...\n";
	}
	$zip = new ZipArchive;

	// Pak de zip uit naar een csv bestand (deze is wel leesbaar)
	if ($zip->open('datafeed/'.$provider.'/datafeed-'.$provider.'.zip') === TRUE) {
		$zip->extractTo('datafeed/'.$provider.'/');
		$zip->close();
		echo "Datafeed is geupload! \n";
	} else {
		echo 'failed';
	}

	// Openen van CSV bestand
	$datafeedAbonnement = $fields = array(); $d = 0;
	$file = fopen('datafeed/'.$provider.'/datafeed_324849.csv','r');
	if ($file) {
		while (($row = fgetcsv($file, 4096)) !== false) {
			if (empty($fields)) {
				$fields = $row;
				continue;
			}
			foreach ($row as $k=>$value) {
				$datafeedAbonnement[$d][$fields[$k]] = $value;
			}
			$aantalrowsDatafeed = count($row);

			// Conversies per provider toepassen - Alles wat niet converted wordt, word verwijderd. provider_id 0 zijn de algemene conversies
			$stmt = $conn->prepare("SELECT * FROM conversietabel WHERE provider_id = '0' OR provider_id = '$provider_id'");
			$stmt->execute();
			$conversies = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$datafeedAbonnement[$d]['id'] = $d;
			$datafeedAbonnement[$d]['provider_id'] = $provider_id;
			$aantalconversies = count($conversies);
			foreach ($conversies as $conversie) {
				$datafeedAbonnement[$d][$conversie['naar']] = $datafeedAbonnement[$d][$conversie['van']];
				unset($datafeedAbonnement[$d][$conversie['van']]);
			}

			// Verwijderen van ongebruikte rijen in array datafeed
			$teVerwijderenElementen = $aantalrowsDatafeed - $aantalconversies;
			$datafeedAbonnement[$d] = array_slice($datafeedAbonnement[$d], $teVerwijderenElementen);

			//Opschoonacties uitvoeren
			$datafeedAbonnement[$d]['internetsnelheid'] = rtrim($datafeedAbonnement[$d]['internetsnelheid'], " Mbps");
			$datafeedAbonnement[$d]['internetsnelheid'] = rtrim($datafeedAbonnement[$d]['internetsnelheid'], "Mbit/");
			if (empty($datafeedAbonnement[$d]['datalimiet'])) { $datafeedAbonnement[$d]['datalimiet'] = 0; $datafeedAbonnement[$d]['internetsnelheid'] = 0;}
			if ($provider_id == 2) { $datafeedAbonnement[$d]['internetsnelheid'] = 225; }
			if ($provider_id == 4) { $datafeedAbonnement[$d]['internetsnelheid'] = 150; }
			if ($provider_id == 5) { $datafeedAbonnement[$d]['internetsnelheid'] = 75; }
			if ($provider_id == 7) { $datafeedAbonnement[$d]['internetsnelheid'] = 300; }
			$techniek = ($datafeedAbonnement[$d]['internetsnelheid'] >= '15' ? '4g' : '3g');
			$datafeedAbonnement[$d]['techniek'] = $techniek;

			if (strpos($datafeedAbonnement[$d]['datalimiet'], 'Onbeperkt') !== false) { $datafeedAbonnement[$d]['datalimiet'] = '99999'; }
			if ($datafeedAbonnement[$d]['belminuten'] == "Onbeperkt") { $datafeedAbonnement[$d]['belminuten'] = '99999'; }
			if ($datafeedAbonnement[$d]['belminuten'] == "onbeperkt") { $datafeedAbonnement[$d]['belminuten'] = '99999'; }
			$d++;
		}
		if (!feof($file)) {
			echo "Error: unexpected fgets() fail\n";
		}
		fclose($file);

		//Check of iets nieuws in feed (match met abonnementen)
		$stmt = $conn->prepare("SELECT * FROM abonnementen WHERE provider_id = '$provider_id'");
		$stmt->execute();
		$abonnementenDatabase = $stmt->fetchAll(PDO::FETCH_ASSOC);
		if ($abonnementenDatabase == $datafeedAbonnement) {
			echo 'Abonnementen zijn precies gelijk, niets meer doen';
			echo '<p>Datafeed is gecontroleerd.</p><p>Geen nieuwe abonnementen gevonden in de datafeed.</p>';
			exit();
		}
		else {
			$abonnementenToevoegen['$provider_id'] = array();
			$abonnementenVerwijderen['$provider_id'] = array();
			$abonnementenVervangenVan['$provider_id'] = array();
			$abonnementenVervangenNaar['$provider_id'] = array();

			// Check of abonnementen in feed in database zitten.
			foreach ($datafeedAbonnement as $abonnement) {
				if ($provider_id == 6) { if (strpos($abonnement['abonnement_naam'], 'Prepaid') !== false) { echo 'break'; break; }}
				$product_id = $abonnement['product_id'];
				$stmt = $conn->prepare("SELECT * FROM abonnementen WHERE provider_id = '$provider_id' && product_id ='$product_id'");
				$stmt->execute();
				$databaseAbonnement = $stmt->fetchAll(PDO::FETCH_ASSOC);

				// Als het abonnement al in database zit:
				if (!empty($databaseAbonnement)) {
					if($databaseAbonnement[0]['product_id'] !== $product_id) {

					// TOEVOEGEN abonnement als hij nog niet in database zit
					array_push ($abonnementenToevoegen['$provider_id'], $abonnement);
					}
				} else {
					array_push ($abonnementenToevoegen['$provider_id'], $abonnement);
				}
			}

			// Check of abonnementen van database in feed zitten.
			$c = 0;
			foreach ($datafeedAbonnement as $abonnement) {
				array_shift ($datafeedAbonnement[$c]);
				$c++;
			}
			foreach ($abonnementenDatabase as $abonnement) {

				// CHECK DOEN OF abonnement in array van feed voor komt in de database.
				array_shift ($abonnement);
				if (in_array($abonnement, $datafeedAbonnement)) {
					echo "Abonnement uit database komt voor in feed \n";
				}

				// Als tenminste het ID overeenkomt dan vervangen:
				else {
					foreach ($datafeedAbonnement as $datafeedProductid) {
						$idGevonden = false;
						if ($datafeedProductid['product_id'] == $abonnement['product_id']) {
							echo "Abonnement komt NIET voor maar het abonnement bestaat wel! Wil je het abonnement vervangen? \n";
							array_push ($abonnementenVervangenVan['$provider_id'], $abonnement);
							array_push ($abonnementenVervangenNaar['$provider_id'], $datafeedProductid);
							$idGevonden = true;
							if ($idGevonden) {
								break;
							}
						}
					}

					// Anders abonnement verwijderen:
					if ($idGevonden == false) {
						echo 'Abonnement komt NIET voor';
						array_push ($abonnementenVerwijderen['$provider_id'], $abonnement);
					}

				}
			}
			echo "Datafeed voor provider ".$provider_id." is gecontroleerd. \n";

			// HIERONDER WORDEN DE AANPASSINGEN AAN DE DATABASE UITGEVOERD //
			// Abonnementen uit feed die niet in database zaten (TOEVOEGEN)
			if(!empty($abonnementenToevoegen['$provider_id'])) {
				echo '<p>Nieuwe abonnementen gevonden in de datafeed:</p>';
				print_r ($abonnementenToevoegen['$provider_id']);
				foreach ($abonnementenToevoegen['$provider_id'] as $abonnement) {
					$stmt = $conn->prepare('INSERT INTO abonnementen (provider_id, product_id, belminuten, datalimiet, techniek, contractduur, maandprijs, internetsnelheid, deeplink, aansluitkosten, abonnement_naam) VALUES (:provider_id, :product_id, :belminuten, :datalimiet, :techniek, :contractduur, :maandprijs, :internetsnelheid, :deeplink, :aansluitkosten, :abonnement_naam)');
					$stmt->bindValue(':provider_id', (string) $abonnement['provider_id']);
					$stmt->bindValue(':product_id', (string) $abonnement['product_id']);
					$stmt->bindValue(':belminuten', (string) $abonnement['belminuten']);
					$stmt->bindValue(':datalimiet', (string) $abonnement['datalimiet']);
					$stmt->bindValue(':techniek', (string) $abonnement['techniek']);
					$stmt->bindValue(':contractduur', (string) $abonnement['contractduur']);
					$stmt->bindValue(':maandprijs', (string) $abonnement['maandprijs']);
					$stmt->bindValue(':internetsnelheid', (string) $abonnement['internetsnelheid']);
					$stmt->bindValue(':deeplink', (string) $abonnement['deeplink']);
					$stmt->bindValue(':aansluitkosten', (string) $abonnement['aansluitkosten']);
					$stmt->bindValue(':abonnement_naam', (string) $abonnement['abonnement_naam']);
					try {
						$stmt->execute();
						echo 'Abonnement toegevoegd.';
					} catch(PDOException $e) {
						echo 'error:'.$e->getMessage();
					}
				}
			}

			// De abonnementen uit database die niet meer voorkwamen in de feed (VERWIJDEREN):
			if (!empty($abonnementenVerwijderen['$provider_id'])) {
				echo 'Daarnaast de volgende abonnementen uit database komen niet voor in de feed:';
				print_r ($abonnementenVerwijderen['$provider_id']);
				foreach ($abonnementenVerwijderen['$provider_id'] as $abonnement) {
					$product_id = $abonnement['product_id'];
					$stmt = $conn->prepare("DELETE FROM abonnementen WHERE product_id = '$product_id'");
					try {
						$stmt->execute();
						echo 'Abonnement met product_id '.$product_id.' verwijderd.';
					} catch(PDOException $e) {
						echo 'error:'.$e->getMessage();
					}
				}
			}

			// De abonnementen die vervangen moeten worden (wel zelfde ID maar iets is veranderd)
			if (!empty($abonnementenVervangenVan['$provider_id'])) {
				$v = 0;
				foreach ($abonnementenVervangenVan['$provider_id'] as $abonnement) {
					echo 'Wil je dit abonnement vervangen door het nieuwe abonnement uit de feed?';
					echo 'Te vervangen: ';
					print_r ($abonnementenVervangenVan['$provider_id'][$v]);
					foreach ($abonnementenVervangenVan['$provider_id'] as $abonnement) {
						$product_id = $abonnement['product_id'];
						$stmt = $conn->prepare("DELETE FROM abonnementen WHERE product_id = '$product_id'");
						try {
							$stmt->execute();
							echo 'Abonnement met product_id '.$product_id.' verwijderd.';
						} catch(PDOException $e) {
							echo 'error:'.$e->getMessage();
						}
					}
					echo 'Vervangen door:';
					print_r ($abonnementenVervangenNaar['$provider_id'][$v]);
					foreach ($abonnementenVervangenNaar['$provider_id'] as $abonnement) {
						$stmt = $conn->prepare('INSERT INTO abonnementen (provider_id, product_id, belminuten, datalimiet, techniek, contractduur, maandprijs, internetsnelheid, deeplink, aansluitkosten, abonnement_naam) VALUES (:provider_id, :product_id, :belminuten, :datalimiet, :techniek, :contractduur, :maandprijs, :internetsnelheid, :deeplink, :aansluitkosten, :abonnement_naam)');
						$stmt->bindValue(':provider_id', (string) $abonnement['provider_id']);
						$stmt->bindValue(':product_id', (string) $abonnement['product_id']);
						$stmt->bindValue(':belminuten', (string) $abonnement['belminuten']);
						$stmt->bindValue(':datalimiet', (string) $abonnement['datalimiet']);
						$stmt->bindValue(':techniek', (string) $abonnement['techniek']);
						$stmt->bindValue(':contractduur', (string) $abonnement['contractduur']);
						$stmt->bindValue(':maandprijs', (string) $abonnement['maandprijs']);
						$stmt->bindValue(':internetsnelheid', (string) $abonnement['internetsnelheid']);
						$stmt->bindValue(':deeplink', (string) $abonnement['deeplink']);
						$stmt->bindValue(':aansluitkosten', (string) $abonnement['aansluitkosten']);
						$stmt->bindValue(':abonnement_naam', (string) $abonnement['abonnement_naam']);
						try {
							$stmt->execute();
							echo 'Abonnement toegevoegd.';
						} catch(PDOException $e) {
							echo 'error:'.$e->getMessage();
						}
					}
					$v++;
				}
			}
		}
	}
	$i++;
}

// MAILEN
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
?>
