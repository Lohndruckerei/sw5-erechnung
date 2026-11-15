<?php
/**
 * Entwicklungs-Test ohne Shopware: simuliert typische Bestellungen (brutto, netto/B2B, EU steuerfrei,
 * Export, Storno) mit nachgebauten Shopware-Objekten und schreibt die erzeugten XMLs nach tools/out/.
 *   php tools/test-invoice-data.php [ausgabeordner]
 */
namespace Doctrine\DBAL { class Connection { public function fetchAssoc($q, $p) { return ['docID' => '20260999', 'date' => '2026-11-01 10:00:00']; } } }
namespace {
class Shopware_Components_Document { public $_order; public $_config = ['date' => '14.11.2026']; public $_documentID = '20261042'; public $_typID = 1; public $userAttributes = [];
  public function getUserAttributes($id) { return $this->userAttributes; } }
class FakeOrder { public $d; public function __construct($d) { $this->d = $d; } public function __get($n) { return $this->d[$n]; } }
$root = __DIR__ . '/../LdZugferd/Components/';
$outDir = $argv[1] ?? __DIR__ . '/out';
@mkdir($outDir, 0777, true);
foreach (['TotalsCalculator', 'CiiXmlBuilder', 'InvoiceDataFactory'] as $c) require $root . $c . '.php';
$ao = function ($a) { return new ArrayObject($a, ArrayObject::ARRAY_AS_PROPS); };
$cfg = ['sellerName' => 'Muster Druckerei GmbH', 'sellerStreet' => 'Musterstr. 1', 'sellerZip' => '12345', 'sellerCity' => 'Musterstadt', 'sellerCountry' => 'DE',
 'sellerVatId' => 'DE 123 456 789', 'sellerTaxNumber' => '', 'sellerEmail' => 'rechnung@example.com', 'sellerContactName' => 'Max Muster', 'sellerPhone' => '+49 123 456789', 'sellerRegister' => 'HRB 1, AG X',
 'sellerIban' => 'DE02 1203 0000 0000 2020 51', 'sellerBic' => '', 'sellerAccountName' => '', 'paymentTermDays' => 14,
 'paymentMeansMapping' => "prepayment=58\ninvoice=58\ndebit=59\npaypal=68", 'defaultPaymentMeansCode' => '58', 'paidStatusAsPrepaid' => 1,
 'buyerReferenceField' => '', 'unitCode' => 'H87', 'exemptionReason' => 'Steuerbefreit', 'invoiceDocumentIds' => '1'];
function mkpos($name, $sku, $qty, $price, $tax, $net = false, $modus = 0) {
  // nachgebildet wie Shopware_Models_Document_Order::processPositions
  if ($net) { $netto = round($price, 2); $price = round($price * (1 + $tax / 100), 2); } else { $netto = $price / (100 + $tax) * 100; }
  return ['name' => $name, 'articleordernumber' => $sku, 'quantity' => $qty, 'price' => $price, 'netto' => $netto, 'tax' => $tax, 'modus' => $modus,
          'amount_netto' => round($netto * $qty, 2), 'amount' => round($price * $qty, 2)];
}
$scen = [
 'gross' => [false, false, 'DE', '', 17, [mkpos('Flyer A6', 'F1', 7, 13.37, 19), mkpos('Buch', 'B1', 3, 9.99, 7), mkpos('Gutschein', 'V1', 1, -10, 19, false, 2), mkpos('Versandkosten', '', 1, 5.95, 19, false, 1), mkpos('Gratis-Kuli', 'P1', 1, 0, 0, false, 1)]],
 'net-b2b' => [true, false, 'DE', 'DE987654321', 12, [mkpos('Plakat A1', 'P1', 13, 7.33, 19, true), mkpos('Versandkosten', '', 1, 8.40, 19, true, 1)]],
 'eu-taxfree' => [false, true, 'AT', 'ATU12345678', 17, [mkpos('Flyer', 'F1', 5, 12.34, 0), mkpos('Versandkosten', '', 1, 10.00, 0, false, 1)]],
 'behoerde' => [false, false, 'DE', '', 17, [mkpos('Plakate A1', 'P1', 20, 4.99, 19), mkpos('Versandkosten', '', 1, 5.95, 19, false, 1)], '991-33333TEST-33'],
 'export-ch' => [false, true, 'CH', '', 17, [mkpos('Flyer', 'F1', 2, 50, 0)]],
];
$f = new LdZugferd\Components\InvoiceDataFactory(new LdZugferd\Components\TotalsCalculator(), new Doctrine\DBAL\Connection());
$b = new LdZugferd\Components\CiiXmlBuilder();
foreach (array_merge($scen, ['storno' => $scen['gross']]) as $name => $sc) {
  [$net, $taxfree, $country, $ustid, $cleared, $positions] = $sc; $leitweg = $sc[6] ?? '';
  $amount = array_sum(array_column($positions, 'amount'));
  $addr = ['company' => $ustid ? 'Firma ' . $country : '', 'firstname' => 'Erika', 'lastname' => 'Muster', 'street' => 'Weg 1', 'additional_address_line1' => '', 'department' => '',
    'zipcode' => '1010', 'city' => 'Stadt', 'ustid' => $ustid, 'customernumber' => '20001', 'phone' => '', 'country' => ['countryiso' => $country], 'attributes' => []];
  $d = new Shopware_Components_Document();
  $d->userAttributes = ['ld_leitweg_id' => $leitweg];
  $d->_order = new FakeOrder(['id' => 5, 'userID' => 7, 'net' => $net, 'shippingCostsAsPosition' => true,
    'order' => $ao(['id' => 5, 'ordernumber' => '30042', 'taxfree' => $taxfree ? 1 : 0, 'currency' => 'EUR', 'cleared' => $cleared, 'invoice_amount' => $amount, 'invoice_shipping' => 0, 'invoice_shipping_net' => 0, 'invoice_shipping_tax_rate' => 19]),
    'positions' => $ao($positions), 'billing' => $ao($addr), 'shipping' => $ao($addr), 'user' => $ao(['email' => 'kunde@example.org']),
    'payment' => $ao(['name' => $cleared == 12 ? 'SwagPaymentPayPalUnified' : 'prepayment', 'description' => $cleared == 12 ? 'PayPal' : 'Vorkasse'])]);
  $profile = $leitweg !== '' ? 'XRECHNUNG' : 'EN16931';
  $data = $f->create($d, $cfg, $name === 'storno' ? '381' : '380', $profile);
  file_put_contents($outDir . "/$name.xml", $b->build($data));
  printf("%-11s %-9s grand=%8.2f shop=%8.2f prepaid=%.2f due=%.2f pay=%s cats=%s\n", $name, $data['profile'], $data['totals']['grandTotal'], $data['shopInvoiceAmount'], $data['totals']['prepaid'], $data['totals']['duePayable'], $data['payment']['code'], implode(',', array_map(function ($t) { return $t['category'] . $t['rate']; }, $data['taxes'])));
}

// Gegentest: XRechnung ohne Telefonnummer des Verkäufers muss abgelehnt werden
$d->userAttributes = ['ld_leitweg_id' => '991-33333TEST-33'];
$cfg['sellerPhone'] = '';
try { $b->build($f->create($d, $cfg, '380', 'XRECHNUNG')); echo "FEHLER: XRechnung ohne Telefon wurde nicht abgelehnt\n"; exit(1); }
catch (InvalidArgumentException $e) { echo "Gegentest ok: ", $e->getMessage(), "\n"; }
}
