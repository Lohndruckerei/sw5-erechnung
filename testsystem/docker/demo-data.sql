-- Testdaten für ./testsystem.sh neu (frische Shopware-Demo-Installation ohne Kunden/Bestellungen).
-- Kunde 1: Firma (B2B) -> ZUGFeRD EN 16931
-- Kunde 2: Behörde mit Leitweg-ID (wird nach der Plugin-Installation gesetzt) -> XRechnung
SET NAMES utf8mb4;
SET sql_mode = 'NO_ENGINE_SUBSTITUTION';

INSERT INTO s_user (id, password, encoder, email, active, accountmode, paymentID, firstlogin, lastlogin, sessionID, newsletter, validation, affiliate, customergroup, paymentpreset, language, subshopID, referer, internalcomment, failedlogins, default_billing_address_id, default_shipping_address_id, salutation, firstname, lastname, customernumber)
VALUES
 (1, '', 'bcrypt', 'einkauf@kundenfirma.example', 1, 0, 5, NOW(), NOW(), '', 0, '', 0, 'EK', 0, '1', 1, '', '', 0, 1, 1, 'ms', 'Erika', 'Muster', '20001'),
 (2, '', 'bcrypt', 'rechnung@stadt-musterstadt.example', 1, 0, 4, NOW(), NOW(), '', 0, '', 0, 'EK', 0, '1', 1, '', '', 0, 2, 2, 'mr', 'Bernd', 'Beamter', '20002');

INSERT INTO s_user_addresses (id, user_id, company, department, salutation, firstname, lastname, street, zipcode, city, country_id, ustid, phone)
VALUES
 (1, 1, 'Kundenfirma GmbH', '', 'ms', 'Erika', 'Muster', 'Kundenweg 5', '54321', 'Kundenstadt', 2, 'DE987654321', ''),
 (2, 2, 'Stadtverwaltung Musterstadt', 'Hauptamt', 'mr', 'Bernd', 'Beamter', 'Rathausplatz 1', '12345', 'Musterstadt', 2, '', '');

INSERT INTO s_order (id, ordernumber, userID, invoice_amount, invoice_amount_net, invoice_shipping, invoice_shipping_net, invoice_shipping_tax_rate, ordertime, status, cleared, paymentID, transactionID, comment, customercomment, internalcomment, net, taxfree, partnerID, temporaryID, referer, cleareddate, trackingcode, language, dispatchID, currency, currencyFactor, subshopID, remote_addr, deviceType, is_proportional_calculation)
VALUES
 (1, '20001', 1, 113.77, 95.60, 3.90, 3.28, 19, NOW(), 0, 17, 5, '', '', '', '', 0, 0, '', '', '', NULL, '', '1', 9, 'EUR', 1, 1, '127.0.0.1', 'desktop', 0),
 (2, '20002', 2, 105.75, 88.87, 5.95, 5.00, 19, NOW(), 0, 17, 4, '', '', '', '', 0, 0, '', '', '', NULL, '', '1', 9, 'EUR', 1, 1, '127.0.0.1', 'desktop', 0);

INSERT INTO s_order_details (orderID, ordernumber, articleID, articleordernumber, price, quantity, name, status, shipped, shippedgroup, modus, esdarticle, taxID, tax_rate, config, articleDetailID)
VALUES
 (1, '20001', 1, 'SW10001', 19.99, 3, 'Visitenkarten 500 Stück', 0, 0, 0, 0, 0, 1, 19, '', 1),
 (1, '20001', 2, 'SW10002', 49.90, 1, 'Briefpapier A4', 0, 0, 0, 0, 0, 1, 19, '', 2),
 (2, '20002', 1, 'SW10001', 4.99, 20, 'Plakat A1 „Bürgerfest“', 0, 0, 0, 0, 0, 1, 19, '', 1);

INSERT INTO s_order_billingaddress (userID, orderID, company, department, salutation, customernumber, firstname, lastname, street, zipcode, city, phone, countryID, ustid)
VALUES
 (1, 1, 'Kundenfirma GmbH', '', 'ms', '20001', 'Erika', 'Muster', 'Kundenweg 5', '54321', 'Kundenstadt', '', 2, 'DE987654321'),
 (2, 2, 'Stadtverwaltung Musterstadt', 'Hauptamt', 'mr', '20002', 'Bernd', 'Beamter', 'Rathausplatz 1', '12345', 'Musterstadt', '', 2, '');

INSERT INTO s_order_shippingaddress (userID, orderID, company, department, salutation, firstname, lastname, street, zipcode, city, phone, countryID)
VALUES
 (1, 1, 'Kundenfirma GmbH', 'Lager', 'ms', 'Erika', 'Muster', 'Lagerweg 1', '54321', 'Kundenstadt', '', 2),
 (2, 2, 'Stadtverwaltung Musterstadt', 'Hauptamt', 'mr', 'Bernd', 'Beamter', 'Rathausplatz 1', '12345', 'Musterstadt', '', 2);

INSERT INTO s_order_attributes (orderID) VALUES (1), (2);

-- Damit der Import-Schritt "Live-Einstellungen entfernen" etwas zu tun hat: Shop wie live mit Domain/HTTPS/SMTP
UPDATE s_core_shops SET host = 'www.beispiel-shop.example', secure = 1 WHERE main_id IS NULL;
