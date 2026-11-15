<?php

namespace LdZugferd\Components;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Shopware_Components_Document;

/**
 * Liest die bereits von Shopware geladenen Beleg-/Bestelldaten aus der
 * Dokumentenkomponente und baut daraus das Array für den CiiXmlBuilder.
 */
class InvoiceDataFactory
{
    const PAYMENT_STATUS_COMPLETELY_PAID = 12;

    /** Freitextfeld (Kunde, Adresse, Bestell-Rechnungsadresse), das das Plugin anlegt */
    const LEITWEG_ID_FIELD = 'ld_leitweg_id';

    const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /** @var TotalsCalculator */
    private $calculator;

    /** @var Connection */
    private $connection;

    public function __construct(TotalsCalculator $calculator, Connection $connection)
    {
        $this->calculator = $calculator;
        $this->connection = $connection;
    }

    /**
     * @param string $profile CiiXmlBuilder::PROFILE_EN16931 oder CiiXmlBuilder::PROFILE_XRECHNUNG
     */
    public function create(Shopware_Components_Document $document, array $config, string $typeCode, string $profile = CiiXmlBuilder::PROFILE_EN16931): array
    {
        $docOrder = $document->_order;
        $order = $docOrder->order;
        $billing = $docOrder->billing;
        $shipping = $docOrder->shipping ?: $billing;
        $user = $docOrder->user;

        $sellerCountry = strtoupper(trim($config['sellerCountry'] ?: 'DE'));
        $buyerCountry = $this->countryIso($billing);
        $shipCountry = $this->countryIso($shipping) ?: $buyerCountry;
        $taxFree = !empty($order['taxfree']);

        $issueDate = $this->parseDate(isset($document->_config['date']) ? $document->_config['date'] : null) ?: new DateTimeImmutable('today');
        $deliveryDate = $this->parseDate(isset($document->_config['delivery_date']) ? $document->_config['delivery_date'] : null) ?: $issueDate;

        // Positionen
        $lines = [];
        $maxRate = 0.0;
        foreach ($docOrder->positions->getArrayCopy() as $position) {
            if (empty($position['quantity'])) {
                continue;
            }
            $rate = $taxFree ? 0.0 : (float) $position['tax'];
            $maxRate = max($maxRate, $rate);
            $lines[] = [
                'sku' => (string) $position['articleordernumber'],
                'name' => (string) $position['name'],
                'quantity' => (float) $position['quantity'],
                'unitCode' => $config['unitCode'] ?: 'H87',
                'gross' => (float) $position['amount'],
                'net' => (float) $position['amount_netto'],
                'rate' => $rate,
            ];
        }

        // Versandkosten, falls Shopware sie nicht als Position führt
        if (!$docOrder->shippingCostsAsPosition && (float) $order['invoice_shipping'] != 0.0) {
            $shippingRate = $taxFree ? 0.0 : (float) $order['invoice_shipping_tax_rate'];
            if (!$taxFree && $shippingRate == 0.0 && (float) $order['invoice_shipping_net'] != 0.0) {
                $shippingRate = round(((float) $order['invoice_shipping'] / (float) $order['invoice_shipping_net'] - 1) * 100, 2);
            }
            $lines[] = [
                'sku' => '',
                'name' => 'Versandkosten',
                'quantity' => 1.0,
                'unitCode' => $config['unitCode'] ?: 'H87',
                'gross' => (float) $order['invoice_shipping'],
                'net' => (float) $order['invoice_shipping_net'],
                'rate' => $shippingRate,
            ];
        }

        foreach ($lines as &$line) {
            $this->assignTaxCategory($line, $taxFree, $sellerCountry, $shipCountry, $maxRate, $config);
        }
        unset($line);

        $calc = $this->calculator->calculate($lines);
        $totals = $calc['totals'];

        // Zahlung
        $payment = $docOrder->payment; // __get() – nicht direkt mit isset() prüfen
        $paymentName = isset($payment['name']) ? (string) $payment['name'] : '';
        $paymentLabel = isset($payment['description']) ? (string) $payment['description'] : $paymentName;
        $isPaid = !empty($config['paidStatusAsPrepaid']) && (int) $order['cleared'] === self::PAYMENT_STATUS_COMPLETELY_PAID;
        $totals['prepaid'] = $isPaid && $typeCode === '380' ? $totals['grandTotal'] : 0.0;
        $totals['duePayable'] = round($totals['grandTotal'] - $totals['prepaid'], 2);

        $dueDate = $issueDate->modify('+' . (int) $config['paymentTermDays'] . ' days');
        $paymentCode = $this->paymentMeansCode($paymentName, $config);
        if (in_array($paymentCode, ['30', '58'], true) && empty($config['sellerIban'])) {
            // Überweisung ohne IBAN wäre ungültig (BR-61)
            $paymentCode = '1';
        }

        if ($typeCode !== '380') {
            $terms = null;
        } elseif ($totals['prepaid'] > 0) {
            $terms = 'Bereits bezahlt' . ($paymentLabel !== '' ? ' (' . $paymentLabel . ')' : '') . '.';
        } else {
            $terms = 'Zahlbar bis ' . $dueDate->format('d.m.Y') . ' ohne Abzug.';
        }

        $notes = [];
        if (!empty($config['sellerRegister'])) {
            $notes[] = ['content' => $config['sellerName'] . ', ' . $config['sellerRegister'], 'subjectCode' => 'REG'];
        }

        $leitwegId = $this->findLeitwegId($document, $config);
        $buyerReference = $leitwegId !== '' ? $leitwegId : (string) $order['ordernumber'];

        $company = trim((string) $billing['company']);
        $personName = trim($billing['firstname'] . ' ' . $billing['lastname']);
        $shipCompany = trim((string) $shipping['company']);
        $shipPerson = trim($shipping['firstname'] . ' ' . $shipping['lastname']);

        return [
            'number' => (string) $document->_documentID,
            'typeCode' => $typeCode,
            'issueDate' => $issueDate,
            'deliveryDate' => $deliveryDate,
            'dueDate' => $dueDate,
            'currency' => strtoupper($order['currency'] ?: 'EUR'),
            'orderNumber' => (string) $order['ordernumber'],
            'profile' => $profile,
            'leitwegId' => $leitwegId,
            'buyerReference' => $buyerReference,
            'notes' => $notes,
            'seller' => [
                'name' => $config['sellerName'],
                'street' => $config['sellerStreet'],
                'zip' => $config['sellerZip'],
                'city' => $config['sellerCity'],
                'country' => $sellerCountry,
                'vatId' => $this->normalizeVatId($config['sellerVatId']),
                'taxNumber' => $config['sellerTaxNumber'],
                'email' => $config['sellerEmail'],
                'contactName' => $config['sellerContactName'],
                'phone' => $config['sellerPhone'],
                'legalId' => null,
            ],
            'buyer' => [
                'id' => isset($billing['customernumber']) ? (string) $billing['customernumber'] : '',
                'name' => $company !== '' ? $company : $personName,
                'contactName' => $company !== '' ? $personName : '',
                'street' => (string) $billing['street'],
                'street2' => trim($billing['additional_address_line1'] . ' ' . $billing['department']),
                'zip' => (string) $billing['zipcode'],
                'city' => (string) $billing['city'],
                'country' => $buyerCountry,
                'vatId' => $this->normalizeVatId($billing['ustid']),
                'email' => isset($user['email']) ? (string) $user['email'] : '',
                'phone' => isset($billing['phone']) ? (string) $billing['phone'] : '',
                // BT-49: Bei Behörden ist die Leitweg-ID die elektronische Adresse (Schema 0204)
                'electronicAddress' => $leitwegId,
                'electronicAddressScheme' => '0204',
            ],
            'shipTo' => [
                'name' => $shipCompany !== '' ? $shipCompany : $shipPerson,
                'street' => (string) $shipping['street'],
                'street2' => trim($shipping['additional_address_line1'] . ' ' . $shipping['department']),
                'zip' => (string) $shipping['zipcode'],
                'city' => (string) $shipping['city'],
                'country' => $shipCountry,
            ],
            'lines' => $calc['lines'],
            'taxes' => $calc['taxes'],
            'totals' => $totals,
            'payment' => [
                'code' => $paymentCode,
                'info' => $paymentLabel,
                'iban' => str_replace(' ', '', (string) $config['sellerIban']),
                'bic' => (string) $config['sellerBic'],
                'accountName' => $config['sellerAccountName'] ?: $config['sellerName'],
                'terms' => $terms,
            ],
            'precedingInvoice' => $typeCode === '381' ? $this->findPrecedingInvoice((int) $order['id'], $config) : null,
            // nur zum Abgleich/Logging, nicht Teil der XML
            'shopInvoiceAmount' => (float) $order['invoice_amount'],
        ];
    }

    /**
     * Leitweg-ID suchen: Rechnungsadresse der Bestellung -> konfiguriertes Freitextfeld -> Kundenkonto.
     */
    public function findLeitwegId(Shopware_Components_Document $document, array $config): string
    {
        $billing = $document->_order->billing;
        $billingAttributes = $billing && isset($billing['attributes']) && is_array($billing['attributes']) ? $billing['attributes'] : [];

        $candidates = [];
        $candidates[] = isset($billingAttributes[self::LEITWEG_ID_FIELD]) ? $billingAttributes[self::LEITWEG_ID_FIELD] : null;
        if (!empty($config['buyerReferenceField'])) {
            $field = $config['buyerReferenceField'];
            $candidates[] = isset($billingAttributes[$field]) ? $billingAttributes[$field] : null;
        }
        $userId = (int) $document->_order->userID;
        if ($userId > 0) {
            $userAttributes = $document->getUserAttributes($userId);
            $candidates[] = is_array($userAttributes) && isset($userAttributes[self::LEITWEG_ID_FIELD]) ? $userAttributes[self::LEITWEG_ID_FIELD] : null;
        }

        foreach ($candidates as $value) {
            $value = strtoupper(preg_replace('/\s+/', '', (string) $value));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Prüft Aufbau und Prüfziffer einer Leitweg-ID (Grobadressierung-Feinadressierung-Prüfziffer,
     * Prüfziffer nach ISO 7064 Modulo 97-10 wie bei der IBAN).
     */
    public static function isValidLeitwegId(string $id): bool
    {
        if (!preg_match('/^(\d{2,12})(?:-([A-Z0-9]{1,30}))?-(\d{2})$/', strtoupper($id), $m)) {
            return false;
        }
        $raw = $m[1] . $m[2] . $m[3];
        $numeric = '';
        foreach (str_split($raw) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    private function assignTaxCategory(array &$line, bool $taxFree, string $sellerCountry, string $shipCountry, float $maxRate, array $config)
    {
        $line['exemptionReason'] = null;
        $line['exemptionReasonCode'] = null;

        if ($taxFree) {
            if ($shipCountry !== $sellerCountry && in_array($shipCountry, self::EU_COUNTRIES, true)) {
                $line['category'] = 'K';
                $line['exemptionReason'] = 'Steuerfreie innergemeinschaftliche Lieferung';
                $line['exemptionReasonCode'] = 'VATEX-EU-IC';
            } elseif (!in_array($shipCountry, self::EU_COUNTRIES, true)) {
                $line['category'] = 'G';
                $line['exemptionReason'] = 'Steuerfreie Ausfuhrlieferung';
                $line['exemptionReasonCode'] = 'VATEX-EU-G';
            } else {
                $line['category'] = 'E';
                $line['exemptionReason'] = $config['exemptionReason'] ?: 'Steuerbefreit';
            }

            return;
        }

        if ($line['rate'] > 0) {
            $line['category'] = 'S';

            return;
        }

        if (round($line['gross'], 2) == 0.0 && $maxRate > 0) {
            // Gratisartikel o. Ä. ohne Betrag dem Hauptsteuersatz zuordnen, statt eine eigene 0%-Kategorie zu bilden.
            $line['rate'] = $maxRate;
            $line['category'] = 'S';

            return;
        }

        $line['category'] = 'Z';
    }

    private function paymentMeansCode(string $paymentName, array $config): string
    {
        $name = strtolower($paymentName);
        $lines = preg_split('/\R/', (string) $config['paymentMeansMapping']);
        foreach ($lines as $mapping) {
            if (strpos($mapping, '=') === false) {
                continue;
            }
            list($key, $code) = array_map('trim', explode('=', $mapping, 2));
            if ($key !== '' && $code !== '' && strpos($name, strtolower($key)) !== false) {
                return $code;
            }
        }

        return trim((string) $config['defaultPaymentMeansCode']) ?: '1';
    }

    private function findPrecedingInvoice(int $orderId, array $config)
    {
        $invoiceTypes = array_filter(array_map('intval', explode(',', (string) $config['invoiceDocumentIds'])));
        if (!$invoiceTypes) {
            return null;
        }
        $row = $this->connection->fetchAssoc(
            'SELECT docID, `date` FROM s_order_documents WHERE orderID = ? AND `type` IN (' . implode(',', $invoiceTypes) . ') ORDER BY id DESC LIMIT 1',
            [$orderId]
        );
        if (!$row) {
            return null;
        }

        return ['number' => (string) $row['docID'], 'date' => new DateTimeImmutable($row['date'])];
    }

    private function countryIso($address): string
    {
        if (!$address || empty($address['country'])) {
            return '';
        }
        $country = $address['country'];
        $iso = isset($country['countryiso']) ? $country['countryiso'] : (isset($country['iso']) ? $country['iso'] : '');

        return strtoupper(trim((string) $iso));
    }

    private function normalizeVatId($vatId): string
    {
        return strtoupper(preg_replace('/[\s.\-]/', '', (string) $vatId));
    }

    /**
     * @param mixed $value
     *
     * @return DateTimeImmutable|null
     */
    private function parseDate($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return new DateTimeImmutable($value->format('Y-m-d'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!d.m.Y', trim($value));
        if ($date) {
            return $date;
        }
        try {
            return new DateTimeImmutable(trim($value));
        } catch (\Exception $e) {
            return null;
        }
    }
}
