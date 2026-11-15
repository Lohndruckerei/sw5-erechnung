<?php
/*
 * Erzeugt einen Beleg genau so, wie es das Backend oder Plugins wie Kuzo tun:
 * Shopware_Components_Document::initDocument(...)->render()
 *
 *   php /opt/testsystem/create-document.php <Bestellnummer> [Belegtyp-ID, Standard 1 = Rechnung]
 */
use Shopware\Kernel;

chdir('/var/www/html');
require '/var/www/html/autoload.php';

$orderNumber = $argv[1] ?? '';
$typeId = (int) ($argv[2] ?? 1);

$kernel = new Kernel('production', false);
$kernel->boot();
$container = $kernel->getContainer();
// Reihenfolge wie bin/console: zuerst Doctrine/DB, dann Plugins (Hooks/Subscriber)
$container->get(\Shopware\Components\Model\ModelManager::class);
$container->load('plugins');

$db = $container->get('dbal_connection');
$orderId = (int) $db->fetchColumn('SELECT id FROM s_order WHERE ordernumber = ?', [$orderNumber]);
if ($orderId <= 0) {
    fwrite(STDERR, "Bestellung '$orderNumber' nicht gefunden.\n");
    exit(1);
}

$document = Shopware_Components_Document::initDocument($orderId, $typeId, [
    'netto' => false,
    'bid' => '',
    'voucher' => null,
    'date' => date('d.m.Y'),
    'delivery_date' => null,
    'shippingCostsAsPosition' => true,
    '_renderer' => 'pdf',
    '_preview' => false,
    '_previewForcePagebreak' => null,
    '_previewSample' => null,
    'docComment' => '',
    'forceTaxCheck' => false,
]);
$document->render();

$row = $db->fetchAssoc('SELECT docID, hash FROM s_order_documents WHERE orderID = ? AND type = ? ORDER BY id DESC LIMIT 1', [$orderId, $typeId]);
echo "Beleg {$row['docID']} erzeugt: files/documents/{$row['hash']}.pdf\n";
$xml = "files/documents/{$row['hash']}.xml";
if (is_file($xml)) {
    echo "XML (XRechnung): $xml\n";
}
