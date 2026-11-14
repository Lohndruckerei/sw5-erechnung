<?php
/**
 * Entwicklungs-Hilfe: erzeugt ohne Shopware eine Beispiel-ZUGFeRD-Rechnung,
 * um XML und PDF/A-3 mit einem Validator (z. B. Mustang, veraPDF, KoSIT) zu prüfen.
 *
 *   composer require mpdf/mpdf:8.2.5   (im Ordner tools/)
 *   php tools/generate-sample.php [gross|net|ic|credit] out.pdf
 */
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../LdZugferd/Components/TotalsCalculator.php';
require __DIR__ . '/../LdZugferd/Components/CiiXmlBuilder.php';
require __DIR__ . '/../LdZugferd/Components/ZugferdPdfEmbedder.php';

use LdZugferd\Components\CiiXmlBuilder;
use LdZugferd\Components\TotalsCalculator;
use LdZugferd\Components\ZugferdPdfEmbedder;

$scenario = $argv[1] ?? 'gross';
$out = $argv[2] ?? __DIR__ . '/sample.pdf';

$category = 'S';
$lines = [
    ['sku' => 'SW10001', 'name' => 'Visitenkarten 500 Stk.', 'quantity' => 3, 'gross' => 3 * 19.99, 'net' => round(3 * 19.99 / 1.19, 2), 'rate' => 19],
    ['sku' => 'SW10002', 'name' => 'Broschüre A5 "Premium" & mehr', 'quantity' => 1, 'gross' => 7.77, 'net' => round(7.77 / 1.07, 2), 'rate' => 7],
    ['sku' => 'GUTSCHEIN', 'name' => 'Gutschein 5 &euro;', 'quantity' => 1, 'gross' => -5.00, 'net' => round(-5 / 1.19, 2), 'rate' => 19],
    ['sku' => '', 'name' => 'Versandkosten', 'quantity' => 1, 'gross' => 4.90, 'net' => round(4.90 / 1.19, 2), 'rate' => 19],
];
$net = false;
$buyerCountry = 'DE';
$buyerVat = null;
if ($scenario === 'net') {
    $net = true;
    foreach ($lines as &$l) { $l['net'] = round($l['gross'], 2); $l['gross'] = 0; }
    unset($l);
}
if ($scenario === 'ic') {
    $buyerCountry = 'AT';
    $buyerVat = 'ATU12345678';
    foreach ($lines as &$l) { $l['net'] = round($l['net'], 2); $l['gross'] = $l['net']; $l['rate'] = 0; }
    unset($l);
}
foreach ($lines as &$l) {
    $l['unitCode'] = 'H87';
    $l['category'] = $scenario === 'ic' ? 'K' : ($l['rate'] > 0 ? 'S' : 'Z');
    if ($scenario === 'ic') {
        $l['exemptionReason'] = 'Steuerfreie innergemeinschaftliche Lieferung';
        $l['exemptionReasonCode'] = 'VATEX-EU-IC';
    }
}
unset($l);

$calc = (new TotalsCalculator())->calculate($lines, $net);
$totals = $calc['totals'] + ['prepaid' => 0.0];
$totals['duePayable'] = $totals['grandTotal'];

$invoice = [
    'number' => '20261001',
    'typeCode' => $scenario === 'credit' ? '381' : '380',
    'issueDate' => new DateTimeImmutable('2026-11-14'),
    'deliveryDate' => new DateTimeImmutable('2026-11-12'),
    'dueDate' => new DateTimeImmutable('2026-11-28'),
    'currency' => 'EUR',
    'orderNumber' => '30001',
    'buyerReference' => '30001',
    'notes' => [['content' => 'Muster GmbH, HRB 12345, AG Musterstadt', 'subjectCode' => 'REG']],
    'seller' => [
        'name' => 'Muster Druckerei GmbH', 'street' => 'Musterstraße 1', 'zip' => '12345', 'city' => 'Musterstadt',
        'country' => 'DE', 'vatId' => 'DE123456789', 'taxNumber' => '123/456/78901', 'email' => 'rechnung@example.com',
        'contactName' => 'Max Muster', 'phone' => '+49 123 456789', 'legalId' => null,
    ],
    'buyer' => [
        'id' => '20005', 'name' => 'Kunde AG', 'contactName' => 'Erika Kunde', 'street' => 'Kundenweg 5',
        'zip' => '54321', 'city' => 'Kundenstadt', 'country' => $buyerCountry, 'vatId' => $buyerVat, 'email' => 'einkauf@example.org',
    ],
    'shipTo' => ['name' => 'Kunde AG', 'street' => 'Lagerweg 1', 'zip' => '54321', 'city' => 'Kundenstadt', 'country' => $buyerCountry],
    'lines' => $calc['lines'],
    'taxes' => $calc['taxes'],
    'totals' => $totals,
    'payment' => ['code' => '58', 'iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'accountName' => 'Muster Druckerei GmbH', 'info' => null, 'terms' => 'Zahlbar innerhalb von 14 Tagen ohne Abzug.'],
    'precedingInvoice' => $scenario === 'credit' ? ['number' => '20260999', 'date' => new DateTimeImmutable('2026-11-01')] : null,
];

$xml = (new CiiXmlBuilder())->build($invoice);
file_put_contents(preg_replace('/\.pdf$/', '.xml', $out), $xml);

$html = '<h1>Rechnung ' . $invoice['number'] . '</h1><table border="1" cellpadding="4">';
foreach ($calc['lines'] as $l) {
    $html .= '<tr><td>' . htmlspecialchars($l['name']) . '</td><td>' . $l['quantity'] . '</td><td>' . number_format($l['netAmount'], 2, ',', '.') . ' €</td></tr>';
}
$html .= '</table><p>Summe: ' . number_format($totals['grandTotal'], 2, ',', '.') . ' €</p>';

$mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
(new ZugferdPdfEmbedder())->apply($mpdf, $xml);
$mpdf->WriteHTML($html);
$mpdf->Output($out, 'F');
echo "OK $out\n";
print_r($totals);
