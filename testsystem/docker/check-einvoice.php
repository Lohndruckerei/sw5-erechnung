<?php
/*
 * Prüft die zuletzt erzeugte Rechnung einer Bestellung:
 * PDF/A-3, eingebettete factur-x.xml, Profil, Beträge gegen die Bestellung, ggf. gespeicherte XRechnung.
 *
 *   php /opt/testsystem/check-einvoice.php <Bestellnummer> [erwartet: zugferd|xrechnung]
 *
 * Exit-Code 0 = alles in Ordnung.
 */
$orderNumber = $argv[1] ?? '';
$expected = $argv[2] ?? '';

$config = require '/var/www/html/config.php';
$db = $config['db'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['dbname']), $db['username'], $db['password']);

$stmt = $pdo->prepare('SELECT o.invoice_amount, d.docID, d.hash FROM s_order o JOIN s_order_documents d ON d.orderID = o.id AND d.type = 1 WHERE o.ordernumber = ? ORDER BY d.id DESC LIMIT 1');
$stmt->execute([$orderNumber]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$errors = [];
$ok = function ($text) { echo "  [OK]     $text\n"; };
$fail = function ($text) use (&$errors) { $errors[] = $text; echo "  [FEHLER] $text\n"; };

echo "Bestellung $orderNumber:\n";
if (!$row) {
    $fail('keine Rechnung gefunden');
    exit(1);
}

$pdfPath = "/var/www/html/files/documents/{$row['hash']}.pdf";
$pdf = @file_get_contents($pdfPath);
if ($pdf === false) {
    $fail("PDF fehlt: $pdfPath");
    exit(1);
}
$ok("Rechnung {$row['docID']}: files/documents/{$row['hash']}.pdf");

preg_match('/pdfaid:part>(\d)</', $pdf, $m) && $m[1] === '3' ? $ok('PDF/A-3') : $fail('keine PDF/A-3-Kennung');
strpos($pdf, '/F (factur-x.xml)') !== false ? $ok('factur-x.xml eingebettet') : $fail('keine factur-x.xml im PDF');
strpos($pdf, '/AFRelationship /Alternative') !== false ? $ok('AFRelationship /Alternative') : $fail('AFRelationship fehlt');
preg_match('/fx:ConformanceLevel>([^<]+)</', $pdf, $m);
$level = $m[1] ?? '';

// Eingebettete Datei (FlateDecode) auslesen
$xml = null;
if (preg_match('/\/Type \/EmbeddedFile.*?stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
    $xml = @gzuncompress($m[1]);
}
if (!$xml) {
    $fail('eingebettete XML nicht lesbar');
    exit(1);
}

$dom = new DOMDocument();
if (!@$dom->loadXML($xml)) {
    $fail('eingebettete XML ist kein gültiges XML');
    exit(1);
}
$xp = new DOMXPath($dom);
$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
$v = function ($q) use ($xp) { $n = $xp->query($q); return $n->length ? trim($n->item(0)->textContent) : ''; };

$guideline = $v('//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');
$isXr = strpos($guideline, 'xrechnung') !== false;
$ok(sprintf('Profil: %s (ConformanceLevel im PDF: %s)', $isXr ? 'XRechnung 3.0' : 'ZUGFeRD EN 16931', $level));
if ($expected === 'xrechnung' && !$isXr) {
    $fail('erwartet war XRechnung');
}
if ($expected === 'zugferd' && $isXr) {
    $fail('erwartet war ZUGFeRD');
}

$grand = (float) $v('//ram:GrandTotalAmount');
$basis = (float) $v('//ram:TaxBasisTotalAmount');
$tax = (float) $v('//ram:TaxTotalAmount');
abs($grand - (float) $row['invoice_amount']) < 0.005
    ? $ok(sprintf('Gesamtbetrag %.2f EUR = Bestellung', $grand))
    : $fail(sprintf('Gesamtbetrag XML %.2f <> Bestellung %.2f', $grand, $row['invoice_amount']));
abs($basis + $tax - $grand) < 0.005
    ? $ok(sprintf('Netto %.2f + MwSt %.2f = Gesamt', $basis, $tax))
    : $fail('Netto + MwSt <> Gesamt');

$buyerRef = $v('//ram:BuyerReference');
$ok("Käuferreferenz (BT-10): $buyerRef");

if ($isXr) {
    $xmlPath = "/var/www/html/files/documents/{$row['hash']}.xml";
    is_file($xmlPath) && file_get_contents($xmlPath) === $xml
        ? $ok('XRechnung zusätzlich gespeichert: files/documents/' . basename($xmlPath))
        : $fail('separate XRechnung-XML fehlt oder weicht ab');
    foreach (['//ram:SellerTradeParty/ram:DefinedTradeContact/ram:PersonName' => 'Ansprechpartner', '//ram:SellerTradeParty/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber' => 'Telefon', '//ram:SellerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID' => 'E-Mail'] as $q => $label) {
        $v($q) !== '' ? $ok("Verkäufer-$label vorhanden") : $fail("Verkäufer-$label fehlt (XRechnung-Pflicht)");
    }
}

exit($errors ? 1 : 0);
