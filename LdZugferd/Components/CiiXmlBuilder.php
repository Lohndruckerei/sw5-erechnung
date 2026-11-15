<?php

namespace LdZugferd\Components;

use DOMDocument;
use DOMElement;

/**
 * Erzeugt UN/CEFACT CII D16B XML im Profil EN 16931 (ZUGFeRD 2.x "COMFORT" / Factur-X EN16931)
 * oder – mit $invoice['profile'] = 'XRECHNUNG' – als XRechnung 3.0 (CII-Syntax).
 *
 * Die Eingabe ist ein reines Array (siehe InvoiceDataFactory), damit der Builder
 * unabhängig von Shopware testbar ist.
 */
class CiiXmlBuilder
{
    const GUIDELINE_EN16931 = 'urn:cen.eu:en16931:2017';
    const GUIDELINE_XRECHNUNG = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

    const PROFILE_EN16931 = 'EN16931';
    const PROFILE_XRECHNUNG = 'XRECHNUNG';

    const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    /** @var DOMDocument */
    private $dom;

    public function build(array $invoice): string
    {
        $this->validate($invoice);

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::NS_QDT);
        $this->dom->appendChild($root);

        // BG-2 Prozesssteuerung
        $context = $this->rsm($root, 'ExchangedDocumentContext');
        $guideline = $this->ram($context, 'GuidelineSpecifiedDocumentContextParameter');
        $this->ram($guideline, 'ID', $this->isXRechnung($invoice) ? self::GUIDELINE_XRECHNUNG : self::GUIDELINE_EN16931);

        // Kopf
        $doc = $this->rsm($root, 'ExchangedDocument');
        $this->ram($doc, 'ID', $invoice['number']);
        $this->ram($doc, 'TypeCode', $invoice['typeCode']);
        $this->dateTime($this->ram($doc, 'IssueDateTime'), $invoice['issueDate']);
        foreach ($invoice['notes'] as $note) {
            $n = $this->ram($doc, 'IncludedNote');
            $this->ram($n, 'Content', $note['content']);
            if (!empty($note['subjectCode'])) {
                $this->ram($n, 'SubjectCode', $note['subjectCode']);
            }
        }

        $transaction = $this->rsm($root, 'SupplyChainTradeTransaction');

        // BG-25 Positionen
        $lineNo = 0;
        foreach ($invoice['lines'] as $line) {
            $this->addLine($transaction, $line, ++$lineNo);
        }

        // Vereinbarungen (Verkäufer, Käufer)
        $agreement = $this->ram($transaction, 'ApplicableHeaderTradeAgreement');
        if (!empty($invoice['buyerReference'])) {
            $this->ram($agreement, 'BuyerReference', $invoice['buyerReference']);
        }
        $this->addParty($agreement, 'SellerTradeParty', $invoice['seller'], true);
        $this->addParty($agreement, 'BuyerTradeParty', $invoice['buyer'], false);
        if (!empty($invoice['orderNumber'])) {
            $ref = $this->ram($agreement, 'BuyerOrderReferencedDocument');
            $this->ram($ref, 'IssuerAssignedID', $invoice['orderNumber']);
        }

        // Lieferung
        $delivery = $this->ram($transaction, 'ApplicableHeaderTradeDelivery');
        if (!empty($invoice['shipTo'])) {
            $shipTo = $this->ram($delivery, 'ShipToTradeParty');
            $this->ram($shipTo, 'Name', $invoice['shipTo']['name']);
            $this->addAddress($shipTo, $invoice['shipTo']);
        }
        if (!empty($invoice['deliveryDate'])) {
            $event = $this->ram($delivery, 'ActualDeliverySupplyChainEvent');
            $this->dateTime($this->ram($event, 'OccurrenceDateTime'), $invoice['deliveryDate']);
        }

        // Abrechnung
        $settlement = $this->ram($transaction, 'ApplicableHeaderTradeSettlement');
        $this->ram($settlement, 'PaymentReference', $invoice['number']);
        $this->ram($settlement, 'InvoiceCurrencyCode', $invoice['currency']);

        $payment = $invoice['payment'];
        if (!empty($payment['code'])) {
            $means = $this->ram($settlement, 'SpecifiedTradeSettlementPaymentMeans');
            $this->ram($means, 'TypeCode', $payment['code']);
            if (!empty($payment['info'])) {
                $this->ram($means, 'Information', $payment['info']);
            }
            if (in_array($payment['code'], ['30', '58'], true) && !empty($payment['iban'])) {
                $account = $this->ram($means, 'PayeePartyCreditorFinancialAccount');
                $this->ram($account, 'IBANID', $payment['iban']);
                if (!empty($payment['accountName'])) {
                    $this->ram($account, 'AccountName', $payment['accountName']);
                }
                if (!empty($payment['bic'])) {
                    $bank = $this->ram($means, 'PayeeSpecifiedCreditorFinancialInstitution');
                    $this->ram($bank, 'BICID', $payment['bic']);
                }
            }
        }

        foreach ($invoice['taxes'] as $tax) {
            $t = $this->ram($settlement, 'ApplicableTradeTax');
            $this->ram($t, 'CalculatedAmount', $this->amount($tax['tax']));
            $this->ram($t, 'TypeCode', 'VAT');
            if (!empty($tax['exemptionReason'])) {
                $this->ram($t, 'ExemptionReason', $tax['exemptionReason']);
            }
            $this->ram($t, 'BasisAmount', $this->amount($tax['basis']));
            $this->ram($t, 'CategoryCode', $tax['category']);
            if (!empty($tax['exemptionReasonCode'])) {
                $this->ram($t, 'ExemptionReasonCode', $tax['exemptionReasonCode']);
            }
            $this->ram($t, 'RateApplicablePercent', $this->decimal($tax['rate'], 2));
        }

        $totals = $invoice['totals'];
        if (!empty($payment['terms']) || ($totals['duePayable'] > 0 && !empty($invoice['dueDate']))) {
            $terms = $this->ram($settlement, 'SpecifiedTradePaymentTerms');
            if (!empty($payment['terms'])) {
                $this->ram($terms, 'Description', $payment['terms']);
            }
            if ($totals['duePayable'] > 0 && !empty($invoice['dueDate'])) {
                $this->dateTime($this->ram($terms, 'DueDateDateTime'), $invoice['dueDate']);
            }
        }

        $sum = $this->ram($settlement, 'SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->ram($sum, 'LineTotalAmount', $this->amount($totals['lineTotal']));
        $this->ram($sum, 'TaxBasisTotalAmount', $this->amount($totals['taxBasis']));
        $taxTotal = $this->ram($sum, 'TaxTotalAmount', $this->amount($totals['taxTotal']));
        $taxTotal->setAttribute('currencyID', $invoice['currency']);
        $this->ram($sum, 'GrandTotalAmount', $this->amount($totals['grandTotal']));
        $this->ram($sum, 'TotalPrepaidAmount', $this->amount($totals['prepaid']));
        $this->ram($sum, 'DuePayableAmount', $this->amount($totals['duePayable']));

        if (!empty($invoice['precedingInvoice']['number'])) {
            $ref = $this->ram($settlement, 'InvoiceReferencedDocument');
            $this->ram($ref, 'IssuerAssignedID', $invoice['precedingInvoice']['number']);
            if (!empty($invoice['precedingInvoice']['date'])) {
                $issue = $this->ram($ref, 'FormattedIssueDateTime');
                $dt = $this->dom->createElementNS(self::NS_QDT, 'qdt:DateTimeString', $invoice['precedingInvoice']['date']->format('Ymd'));
                $dt->setAttribute('format', '102');
                $issue->appendChild($dt);
            }
        }

        return $this->dom->saveXML();
    }

    /**
     * Prüft die Pflichtfelder, die nicht aus der Bestellung kommen (Konfiguration).
     *
     * @throws \InvalidArgumentException
     */
    private function validate(array $invoice)
    {
        $missing = [];
        foreach (['name', 'street', 'zip', 'city', 'country'] as $field) {
            if (empty($invoice['seller'][$field])) {
                $missing[] = 'seller.' . $field;
            }
        }
        if (empty($invoice['seller']['vatId']) && empty($invoice['seller']['taxNumber'])) {
            $missing[] = 'seller.vatId|taxNumber';
        }
        foreach (['name', 'country'] as $field) {
            if (empty($invoice['buyer'][$field])) {
                $missing[] = 'buyer.' . $field;
            }
        }
        if (empty($invoice['number'])) {
            $missing[] = 'number';
        }
        if (empty($invoice['lines'])) {
            $missing[] = 'lines';
        }
        if ($missing) {
            throw new \InvalidArgumentException('E-Rechnung: Pflichtangaben fehlen: ' . implode(', ', $missing));
        }

        if ($this->isXRechnung($invoice)) {
            $this->validateXRechnung($invoice);
        }
    }

    /**
     * Zusätzliche Pflichtangaben der XRechnung (CIUS, Regeln BR-DE-*).
     *
     * @throws \InvalidArgumentException
     */
    private function validateXRechnung(array $invoice)
    {
        $missing = [];
        if (empty($invoice['buyerReference'])) {
            $missing[] = 'buyerReference/Leitweg-ID (BR-DE-15)';
        }
        // BR-DE-2, -5, -6, -7: Kontakt des Verkäufers
        foreach (['contactName' => 'BR-DE-5', 'phone' => 'BR-DE-6', 'email' => 'BR-DE-7'] as $field => $rule) {
            if (empty($invoice['seller'][$field])) {
                $missing[] = 'seller.' . $field . ' (' . $rule . ')';
            }
        }
        // BR-DE-8, -9
        foreach (['zip', 'city'] as $field) {
            if (empty($invoice['buyer'][$field])) {
                $missing[] = 'buyer.' . $field;
            }
        }
        if (!empty($invoice['shipTo']) && (empty($invoice['shipTo']['zip']) || empty($invoice['shipTo']['city']))) {
            $missing[] = 'shipTo.zip/city (BR-DE-10/11)';
        }
        // BR-DE-1 und BR-DE-23/24/25: Zahlungsanweisungen
        $code = isset($invoice['payment']['code']) ? (string) $invoice['payment']['code'] : '';
        if ($code === '') {
            $missing[] = 'payment.code (BR-DE-1)';
        } elseif (in_array($code, ['30', '58'], true) && empty($invoice['payment']['iban'])) {
            $missing[] = 'payment.iban (BR-DE-23)';
        } elseif (in_array($code, ['48', '54', '55', '59'], true)) {
            $missing[] = 'payment.code ' . $code . ' benötigt Karten-/Mandatsdaten (BR-DE-24/25), bitte anderen Code zuordnen';
        }
        if (!in_array((string) $invoice['typeCode'], ['326', '380', '384', '389', '381', '875', '876', '877'], true)) {
            $missing[] = 'typeCode (BR-DE-17)';
        }
        if ($missing) {
            throw new \InvalidArgumentException('XRechnung: Pflichtangaben fehlen: ' . implode(', ', $missing));
        }
    }

    private function isXRechnung(array $invoice): bool
    {
        return isset($invoice['profile']) && $invoice['profile'] === self::PROFILE_XRECHNUNG;
    }

    private function addLine(DOMElement $parent, array $line, int $lineNo)
    {
        $item = $this->ram($parent, 'IncludedSupplyChainTradeLineItem');
        $doc = $this->ram($item, 'AssociatedDocumentLineDocument');
        $this->ram($doc, 'LineID', (string) $lineNo);

        $product = $this->ram($item, 'SpecifiedTradeProduct');
        if (!empty($line['sku'])) {
            $this->ram($product, 'SellerAssignedID', $line['sku']);
        }
        $this->ram($product, 'Name', $line['name'] !== '' ? $line['name'] : '-');

        $agreement = $this->ram($item, 'SpecifiedLineTradeAgreement');
        $price = $this->ram($agreement, 'NetPriceProductTradePrice');
        $this->ram($price, 'ChargeAmount', $this->decimal(abs($line['netPrice']), 4));

        $delivery = $this->ram($item, 'SpecifiedLineTradeDelivery');
        $qty = $this->ram($delivery, 'BilledQuantity', $this->decimal($line['quantity'], 4));
        $qty->setAttribute('unitCode', $line['unitCode']);

        $settlement = $this->ram($item, 'SpecifiedLineTradeSettlement');
        $tax = $this->ram($settlement, 'ApplicableTradeTax');
        $this->ram($tax, 'TypeCode', 'VAT');
        $this->ram($tax, 'CategoryCode', $line['category']);
        $this->ram($tax, 'RateApplicablePercent', $this->decimal($line['rate'], 2));

        $sum = $this->ram($settlement, 'SpecifiedTradeSettlementLineMonetarySummation');
        $this->ram($sum, 'LineTotalAmount', $this->amount($line['netAmount']));
    }

    private function addParty(DOMElement $parent, string $name, array $party, bool $isSeller)
    {
        $p = $this->ram($parent, $name);
        if (!empty($party['id'])) {
            $this->ram($p, 'ID', $party['id']);
        }
        $this->ram($p, 'Name', $party['name']);
        if (!empty($party['legalId'])) {
            $legal = $this->ram($p, 'SpecifiedLegalOrganization');
            $this->ram($legal, 'ID', $party['legalId']);
        }
        if (!empty($party['contactName']) || !empty($party['phone']) || !empty($party['email'])) {
            $contact = $this->ram($p, 'DefinedTradeContact');
            if (!empty($party['contactName'])) {
                $this->ram($contact, 'PersonName', $party['contactName']);
            }
            if (!empty($party['phone'])) {
                $phone = $this->ram($contact, 'TelephoneUniversalCommunication');
                $this->ram($phone, 'CompleteNumber', $party['phone']);
            }
            if (!empty($party['email'])) {
                $mail = $this->ram($contact, 'EmailURIUniversalCommunication');
                $this->ram($mail, 'URIID', $party['email']);
            }
        }
        $this->addAddress($p, $party);
        // BT-34 / BT-49 elektronische Adresse: explizit gesetzt (z. B. Leitweg-ID mit Schema 0204), sonst E-Mail
        if (!empty($party['electronicAddress'])) {
            $uri = $this->ram($p, 'URIUniversalCommunication');
            $id = $this->ram($uri, 'URIID', $party['electronicAddress']);
            $id->setAttribute('schemeID', $party['electronicAddressScheme']);
        } elseif (!empty($party['email'])) {
            $uri = $this->ram($p, 'URIUniversalCommunication');
            $id = $this->ram($uri, 'URIID', $party['email']);
            $id->setAttribute('schemeID', 'EM');
        }
        if (!empty($party['vatId'])) {
            $reg = $this->ram($p, 'SpecifiedTaxRegistration');
            $id = $this->ram($reg, 'ID', $party['vatId']);
            $id->setAttribute('schemeID', 'VA');
        }
        if ($isSeller && !empty($party['taxNumber'])) {
            $reg = $this->ram($p, 'SpecifiedTaxRegistration');
            $id = $this->ram($reg, 'ID', $party['taxNumber']);
            $id->setAttribute('schemeID', 'FC');
        }
    }

    private function addAddress(DOMElement $parent, array $a)
    {
        $addr = $this->ram($parent, 'PostalTradeAddress');
        if (!empty($a['zip'])) {
            $this->ram($addr, 'PostcodeCode', $a['zip']);
        }
        if (!empty($a['street'])) {
            $this->ram($addr, 'LineOne', $a['street']);
        }
        if (!empty($a['street2'])) {
            $this->ram($addr, 'LineTwo', $a['street2']);
        }
        if (!empty($a['city'])) {
            $this->ram($addr, 'CityName', $a['city']);
        }
        $this->ram($addr, 'CountryID', strtoupper($a['country']));
    }

    private function dateTime(DOMElement $parent, \DateTimeInterface $date)
    {
        $el = $this->dom->createElementNS(self::NS_UDT, 'udt:DateTimeString', $date->format('Ymd'));
        $el->setAttribute('format', '102');
        $parent->appendChild($el);
    }

    private function rsm(DOMElement $parent, string $name): DOMElement
    {
        $el = $this->dom->createElementNS(self::NS_RSM, 'rsm:' . $name);
        $parent->appendChild($el);

        return $el;
    }

    /**
     * @param string|null $value
     */
    private function ram(DOMElement $parent, string $name, $value = null): DOMElement
    {
        $el = $this->dom->createElementNS(self::NS_RAM, 'ram:' . $name);
        if ($value !== null) {
            $el->appendChild($this->dom->createTextNode($this->clean((string) $value)));
        }
        $parent->appendChild($el);

        return $el;
    }

    private function clean(string $value): string
    {
        // HTML-Entities aus Shopware (z. B. &euro; in Positionsnamen) und Steuerzeichen entfernen.
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return trim($value);
    }

    private function amount($value): string
    {
        return $this->decimal($value, 2);
    }

    private function decimal($value, int $precision): string
    {
        $formatted = number_format((float) $value, $precision, '.', '');
        if ((float) $formatted == 0.0) {
            $formatted = number_format(0, $precision, '.', '');
        }

        return $formatted;
    }
}
